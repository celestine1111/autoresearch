<?php

namespace SEOBetter;

/**
 * v1.5.216.25 — Brand Voice profiles (Phase 1 item 6).
 *
 * Lets the user define one or more "brand voices" that get injected into the
 * AI generation system prompt at write-time. Each voice = a sample of the
 * user's existing writing + tone directives + banned phrases. The AI is
 * instructed to mimic the sample style, follow the directives, and never
 * use the banned phrases.
 *
 * Tier gating (per `pro-features-ideas.md` §2 Tier Matrix):
 *   - Free: no voices (UI shown but Add button locked → upsell)
 *   - Pro:  1 voice  (`brand_voice_1`)
 *   - Pro+: 3 voices (`brand_voice_3`)
 *   - Agency: unlimited (`brand_voice_unlimited`)
 *
 * Why solve this:
 * Without brand voice enforcement, generated articles get the "sounds like AI"
 * complaint — em-dash overuse, "in today's fast-paced world" openers, "let's
 * dive in" CTAs. Brand voice profiles fix this at the prompt layer (cheap,
 * works on any AI model) plus an optional post-process regex scrub (catches
 * what the LLM ignored).
 *
 * Storage: single `seobetter_brand_voices` option (JSON-serialized array
 * keyed by voice_id). Reasonable choice given tier limits cap most users
 * at 1-3 voices; Agency "unlimited" practically means 10-20 max which still
 * fits comfortably within autoload-safe option size.
 */
class Brand_Voice_Manager {

    private const OPTION_KEY = 'seobetter_brand_voices';

    /**
     * Get all voice profiles.
     *
     * @return array<string, array> Map of voice_id → voice data.
     */
    public static function all(): array {
        $voices = get_option( self::OPTION_KEY, [] );
        return is_array( $voices ) ? $voices : [];
    }

    /**
     * Get a single voice by id. Returns null if not found.
     */
    public static function get( string $voice_id ): ?array {
        $all = self::all();
        return $all[ $voice_id ] ?? null;
    }

    /**
     * How many voices are stored right now.
     */
    public static function count(): int {
        return count( self::all() );
    }

    /**
     * What's the user's current voice cap based on their tier?
     * Returns 0 (free), 1 (pro), 3 (pro_plus), or 999 (agency = "unlimited").
     */
    public static function tier_cap(): int {
        if ( License_Manager::can_use( 'brand_voice_unlimited' ) ) return 999;
        if ( License_Manager::can_use( 'brand_voice_3' ) )         return 3;
        if ( License_Manager::can_use( 'brand_voice_1' ) )         return 1;
        return 0;
    }

    /**
     * Can the user create another voice given their current count vs cap?
     */
    public static function can_create_more(): bool {
        return self::count() < self::tier_cap();
    }

    /**
     * Save (create or update) a voice profile.
     *
     * @param string $voice_id  '' to create new (auto-generates id); existing id to update
     * @param array  $data      ['name', 'description', 'sample_text', 'banned_phrases', 'tone_directives']
     * @return array            ['success' => bool, 'voice_id' => string, 'error' => string?]
     */
    public static function save( string $voice_id, array $data ): array {
        $all = self::all();
        $is_new = ( $voice_id === '' || ! isset( $all[ $voice_id ] ) );

        // Tier-cap check on create only (updates always allowed)
        if ( $is_new && ! self::can_create_more() ) {
            $cap = self::tier_cap();
            return [
                'success' => false,
                'error'   => $cap === 0
                    ? __( 'Brand Voice profiles require Pro ($39/mo).', 'seobetter' )
                    : sprintf(
                        /* translators: 1: tier voice cap */
                        __( 'You\'ve reached your tier\'s voice limit (%d). Upgrade to add more.', 'seobetter' ),
                        $cap
                    ),
            ];
        }

        $name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
        if ( $name === '' ) {
            return [ 'success' => false, 'error' => __( 'Voice name is required.', 'seobetter' ) ];
        }

        // Banned phrases: accept array or newline/comma-separated string
        $banned_raw = $data['banned_phrases'] ?? '';
        if ( is_string( $banned_raw ) ) {
            $banned = array_filter( array_map( 'trim', preg_split( '/[\n,]+/', $banned_raw ) ?: [] ) );
        } else {
            $banned = is_array( $banned_raw ) ? array_filter( array_map( 'trim', $banned_raw ) ) : [];
        }
        $banned = array_values( array_unique( $banned ) );

        if ( $is_new ) {
            $voice_id = self::generate_id();
            $created_at = time();
        } else {
            $created_at = (int) ( $all[ $voice_id ]['created_at'] ?? time() );
        }

        $voice = [
            'id'              => $voice_id,
            'name'            => $name,
            'description'     => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
            'sample_text'     => self::sanitize_sample( (string) ( $data['sample_text'] ?? '' ) ),
            'banned_phrases'  => $banned,
            'tone_directives' => sanitize_textarea_field( (string) ( $data['tone_directives'] ?? '' ) ),
            'created_at'      => $created_at,
            'updated_at'      => time(),
        ];

        $all[ $voice_id ] = $voice;
        update_option( self::OPTION_KEY, $all, false );

        return [ 'success' => true, 'voice_id' => $voice_id ];
    }

    /**
     * Delete a voice by id. Returns true on success, false if voice not found.
     */
    public static function delete( string $voice_id ): bool {
        $all = self::all();
        if ( ! isset( $all[ $voice_id ] ) ) return false;
        unset( $all[ $voice_id ] );
        update_option( self::OPTION_KEY, $all, false );
        return true;
    }

    /**
     * Build the system-prompt fragment for a given voice. Called by
     * Async_Generator::get_system_prompt() to inject into the AI pipeline
     * during article generation. Returns empty string if voice_id is empty
     * or not found — caller's prompt is unchanged.
     */
    public static function get_prompt_fragment( string $voice_id ): string {
        $voice = self::get( $voice_id );
        if ( ! $voice ) return '';

        $lines = [];
        $lines[] = "\n\n=== BRAND VOICE: " . $voice['name'] . " ===";
        $lines[] = 'You MUST write the article in the user\'s established brand voice. The user has provided a sample of their existing writing AND specific directives. Match their tone, sentence rhythm, vocabulary, and style. Do NOT default to generic AI prose.';

        if ( ! empty( $voice['sample_text'] ) ) {
            $lines[] = "\nSAMPLE OF USER'S EXISTING WRITING (mimic this style — sentence length, vocabulary, rhythm, formality):";
            // Truncate sample to ~1500 chars to keep prompt size sane while
            // giving the AI enough to mirror
            $sample = mb_substr( $voice['sample_text'], 0, 1500, 'UTF-8' );
            $lines[] = '"""' . $sample . '"""';
        }

        if ( ! empty( $voice['tone_directives'] ) ) {
            $lines[] = "\nTONE DIRECTIVES (rules to follow throughout the article):";
            $lines[] = $voice['tone_directives'];
        }

        if ( ! empty( $voice['banned_phrases'] ) ) {
            $lines[] = "\nBANNED PHRASES (NEVER use these — the user has explicitly forbidden them):";
            foreach ( $voice['banned_phrases'] as $phrase ) {
                $lines[] = '- ' . $phrase;
            }
            $lines[] = '\nIf you find yourself reaching for any banned phrase, rewrite the sentence using different words. The user will reject the article if any banned phrase appears.';
        }

        $lines[] = "=== END BRAND VOICE ===\n";

        return implode( "\n", $lines );
    }

    /**
     * Post-process scrub: remove any banned phrases that slipped through the
     * LLM despite the prompt directive. Belt-and-suspenders defense.
     *
     * Returns the scrubbed content + count of replacements made (for logging).
     *
     * @return array{0:string,1:int}  [scrubbed_content, replacements_made]
     */
    public static function scrub_banned_phrases( string $content, string $voice_id ): array {
        $voice = self::get( $voice_id );
        if ( ! $voice || empty( $voice['banned_phrases'] ) ) return [ $content, 0 ];

        $count = 0;
        foreach ( $voice['banned_phrases'] as $phrase ) {
            if ( $phrase === '' ) continue;
            // Word-boundary regex, case-insensitive, multibyte-safe
            $pattern = '/\b' . preg_quote( $phrase, '/' ) . '\b/iu';
            $content = preg_replace_callback(
                $pattern,
                function ( $m ) use ( &$count ) {
                    $count++;
                    return ''; // strip the banned phrase entirely
                },
                $content
            ) ?? $content;
        }

        // Clean up double-spaces left behind by stripped phrases
        if ( $count > 0 ) {
            $content = preg_replace( '/[ \t]{2,}/', ' ', $content ) ?? $content;
            $content = preg_replace( '/  +(?=[.,;:?!])/', '', $content ) ?? $content;
        }

        return [ $content, $count ];
    }

    /**
     * Generate a stable, URL-safe voice id.
     */
    private static function generate_id(): string {
        return 'v_' . substr( wp_generate_uuid4(), 0, 8 );
    }

    /**
     * Sample text sanitization — preserves linebreaks + paragraphs but strips
     * HTML/scripts. Caps at 8KB to prevent prompt-injection or auto-load size
     * blow-up via massive sample_text values.
     */
    private static function sanitize_sample( string $text ): string {
        $text = wp_strip_all_tags( $text );
        if ( strlen( $text ) > 8192 ) {
            $text = mb_substr( $text, 0, 8192, 'UTF-8' );
        }
        return $text;
    }
}
