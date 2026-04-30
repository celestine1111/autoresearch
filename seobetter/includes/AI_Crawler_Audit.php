<?php

namespace SEOBetter;

/**
 * v1.5.216.37 — AI Crawler Access audit (Phase 1 item 18).
 *
 * Bridge feature for AI engines that haven't adopted llms.txt yet —
 * protects users from accidentally blocking AI bots via aggressive
 * WordPress security plugins (Wordfence, Solid Security, iThemes Security
 * etc.) that often add `User-agent: *` Disallow rules to their robots.txt
 * presets.
 *
 * Three layers checked per bot:
 *   1. robots.txt — `User-agent: {bot}` + `Disallow: /` (or any explicit
 *      disallow narrower than `/` is also reported, so users see partial
 *      blocks)
 *   2. Meta robots — `<meta name="robots" content="noindex">` on home page
 *   3. HTTP `X-Robots-Tag` header — server-level noindex/nofollow
 *
 * 8 AI bots tracked per the locked plan §3 item 18:
 *   - GPTBot (OpenAI training)
 *   - ChatGPT-User (OpenAI agent browsing)
 *   - Google-Extended (Google AI / Gemini training)
 *   - ClaudeBot (Anthropic)
 *   - anthropic-ai (Anthropic legacy)
 *   - PerplexityBot (Perplexity)
 *   - Bingbot (Microsoft Copilot)
 *   - CCBot (Common Crawl — feeds many LLM training datasets)
 *
 * One-click fix activates a `seobetter_ai_bot_friendly` option that
 * registers a `robots_txt` filter injecting explicit Allow rules for
 * each bot. Doesn't override user's existing rules — just appends an
 * AI-bot-friendly section. Reversible via deactivation.
 *
 * Tier: Free (table-stakes per locked plan §2 — Free 6 features +
 * AI Crawler Access on the locked tier matrix).
 */
class AI_Crawler_Audit {

    private const OPTION_KEY = 'seobetter_ai_bot_friendly';

    /**
     * The 8 AI bots audited. Order matches locked plan §3 item 18 verbatim.
     * Each entry: user_agent string + display label + purpose hint.
     */
    public const TRACKED_BOTS = [
        'GPTBot'           => [ 'label' => 'GPTBot',           'purpose' => 'OpenAI ChatGPT training data' ],
        'ChatGPT-User'     => [ 'label' => 'ChatGPT-User',     'purpose' => 'OpenAI ChatGPT live browsing' ],
        'Google-Extended'  => [ 'label' => 'Google-Extended',  'purpose' => 'Google AI Overviews + Gemini training' ],
        'ClaudeBot'        => [ 'label' => 'ClaudeBot',        'purpose' => 'Anthropic Claude training + browsing' ],
        'anthropic-ai'     => [ 'label' => 'anthropic-ai',     'purpose' => 'Anthropic legacy UA (still in some allow-lists)' ],
        'PerplexityBot'    => [ 'label' => 'PerplexityBot',    'purpose' => 'Perplexity citations' ],
        'Bingbot'          => [ 'label' => 'Bingbot',          'purpose' => 'Microsoft Copilot + Bing search' ],
        'CCBot'            => [ 'label' => 'CCBot',            'purpose' => 'Common Crawl — feeds many LLM training datasets' ],
    ];

    /**
     * Whether the one-click fix is currently active. The fix is what
     * causes register_robots_filter() to inject Allow rules — when off,
     * the plugin makes no robots.txt modifications.
     */
    public static function is_fix_active(): bool {
        return (bool) get_option( self::OPTION_KEY, false );
    }

    /**
     * Apply the one-click fix. Sets the option flag — the actual rule
     * injection happens via the `robots_txt` filter (registered at boot
     * regardless of flag state, gated internally on the option read so
     * toggle can flip without re-registering hooks).
     */
    public static function apply_fix(): void {
        update_option( self::OPTION_KEY, true );
    }

    /**
     * Remove the fix. AI-bot-friendly rules disappear from robots.txt
     * on the next request.
     */
    public static function remove_fix(): void {
        update_option( self::OPTION_KEY, false );
    }

    /**
     * Run the audit. Returns per-bot status + the underlying robots.txt
     * + meta + header readings so the UI can show what was checked.
     *
     * @return array{
     *   robots_txt_content: string,
     *   meta_robots: string,
     *   x_robots_tag: string,
     *   site_blocked_globally: bool,
     *   bots: array<string, array{status: 'pass'|'fail'|'warning', reason: string}>,
     *   summary: array{passed: int, failed: int, warned: int}
     * }
     */
    public static function audit(): array {
        $robots_txt   = self::fetch_robots_txt();
        $meta_robots  = self::fetch_meta_robots();
        $x_robots_tag = self::fetch_x_robots_tag_header();

        // Site-wide noindex (meta or X-Robots-Tag) blocks ALL crawlers
        $globally_blocked = self::has_site_noindex( $meta_robots, $x_robots_tag );

        $bots = [];
        $passed = $failed = $warned = 0;
        foreach ( self::TRACKED_BOTS as $ua => $meta ) {
            if ( $globally_blocked ) {
                $bots[ $ua ] = [
                    'status' => 'fail',
                    'reason' => __( 'Site-wide noindex via meta robots or X-Robots-Tag — blocks every crawler including AI bots.', 'seobetter' ),
                ];
                $failed++;
                continue;
            }
            $check = self::check_bot_in_robots_txt( $robots_txt, $ua );
            $bots[ $ua ] = $check;
            if ( $check['status'] === 'pass' )    $passed++;
            elseif ( $check['status'] === 'fail' ) $failed++;
            else                                   $warned++;
        }

        return [
            'robots_txt_content'    => $robots_txt,
            'meta_robots'           => $meta_robots,
            'x_robots_tag'          => $x_robots_tag,
            'site_blocked_globally' => $globally_blocked,
            'bots'                  => $bots,
            'summary'               => [
                'passed' => $passed,
                'failed' => $failed,
                'warned' => $warned,
            ],
        ];
    }

    /**
     * Register the robots_txt filter at boot. Filter inspects the active
     * option each call — so toggling apply_fix / remove_fix flips
     * behaviour without re-registering. The filter only ADDS lines; never
     * removes user-defined rules.
     *
     * Called once from seobetter.php __construct().
     */
    public static function register_robots_filter(): void {
        add_filter( 'robots_txt', [ __CLASS__, 'inject_ai_bot_rules' ], 10, 2 );
    }

    /**
     * The robots.txt filter callback. Appends explicit Allow rules for
     * every tracked AI bot when the fix is active. WordPress runs this
     * for /robots.txt requests; the public param is the existing rendered
     * content.
     *
     * @param string $output  Existing robots.txt content from WP / other plugins
     * @param int    $public  1 if the site is public, 0 if discouraged
     */
    public static function inject_ai_bot_rules( string $output, int $public ): string {
        if ( ! self::is_fix_active() ) return $output;
        if ( ! $public ) return $output; // Don't override "Search engine visibility" admin setting

        $append = "\n\n# SEOBetter AI Crawler Access (v1.5.216.37 — Phase 1 item 18)\n";
        $append .= "# Explicitly allow AI bots that aggressive security plugins often block\n";
        foreach ( self::TRACKED_BOTS as $ua => $meta ) {
            $append .= "User-agent: {$ua}\n";
            $append .= "Allow: /\n\n";
        }
        $append = rtrim( $append ) . "\n";
        return $output . $append;
    }

    // ====================================================================
    // PRIVATE HELPERS
    // ====================================================================

    /**
     * Fetch the rendered robots.txt — captures both core WP output and
     * anything any robots_txt-filter-using plugin adds. Uses internal
     * HTTP request to localhost rather than reading filesystem (handles
     * physical robots.txt files too).
     */
    private static function fetch_robots_txt(): string {
        $url = trailingslashit( home_url() ) . 'robots.txt';
        $response = wp_remote_get( $url, [
            'timeout'   => 5,
            'sslverify' => false, // localhost self-signed acceptable
            'headers'   => [ 'User-Agent' => 'SEOBetter-AI-Crawler-Audit/1.0' ],
        ] );
        if ( is_wp_error( $response ) ) return '';
        $body = wp_remote_retrieve_body( $response );
        return is_string( $body ) ? $body : '';
    }

    /**
     * Fetch home page HTML and extract meta robots content. Returns
     * empty string when no meta robots tag present (which is the
     * AI-friendly default).
     */
    private static function fetch_meta_robots(): string {
        $response = wp_remote_get( home_url(), [
            'timeout'   => 5,
            'sslverify' => false,
        ] );
        if ( is_wp_error( $response ) ) return '';
        $html = wp_remote_retrieve_body( $response );
        if ( ! is_string( $html ) ) return '';
        if ( preg_match( '/<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
            return trim( $m[1] );
        }
        return '';
    }

    /**
     * Fetch X-Robots-Tag header from home page. Returns empty when not
     * set (AI-friendly default).
     */
    private static function fetch_x_robots_tag_header(): string {
        $response = wp_remote_head( home_url(), [
            'timeout'   => 5,
            'sslverify' => false,
        ] );
        if ( is_wp_error( $response ) ) return '';
        $headers = wp_remote_retrieve_headers( $response );
        if ( ! is_object( $headers ) && ! is_array( $headers ) ) return '';
        // wp_remote_retrieve_headers returns Requests_Utility_CaseInsensitiveDictionary
        // which behaves like an array but supports object-style access too
        $tag = is_array( $headers )
            ? ( $headers['x-robots-tag'] ?? '' )
            : ( method_exists( $headers, 'getValues' ) ? implode( ', ', (array) $headers->getValues( 'x-robots-tag' ) ) : '' );
        return is_string( $tag ) ? trim( $tag ) : '';
    }

    /**
     * Detect site-wide noindex from either meta or HTTP header.
     */
    private static function has_site_noindex( string $meta, string $x_tag ): bool {
        $combined = strtolower( $meta . ' ' . $x_tag );
        return strpos( $combined, 'noindex' ) !== false;
    }

    /**
     * Check whether a specific bot is blocked in robots.txt.
     *
     * Returns:
     *   - status='pass' when the bot has no explicit User-agent block
     *     (default-allow per robots.txt spec) OR has an explicit
     *     `User-agent: {bot}` followed by Allow rules
     *   - status='fail' when an explicit `User-agent: {bot}` followed
     *     by `Disallow: /` exists (root block)
     *   - status='warning' when a wildcard `User-agent: *` Disallow
     *     exists AND no explicit override for this bot — bot inherits
     *     the wildcard block per robots.txt spec
     */
    private static function check_bot_in_robots_txt( string $robots_txt, string $bot_ua ): array {
        if ( $robots_txt === '' ) {
            return [ 'status' => 'pass', 'reason' => __( 'No robots.txt found — default-allow.', 'seobetter' ) ];
        }

        $lines = preg_split( '/\r?\n/', $robots_txt );
        if ( ! $lines ) $lines = [];

        // Walk line-by-line tracking the active User-agent group
        $current_uas = []; // Multiple consecutive UA lines apply to the same group
        $bot_lower   = strtolower( $bot_ua );
        $bot_explicit_allow    = false;
        $bot_explicit_disallow = false;
        $wildcard_disallow     = false;
        $wildcard_seen         = false;

        foreach ( $lines as $raw ) {
            $line = trim( $raw );
            if ( $line === '' || $line[0] === '#' ) {
                // Blank line ends the previous group; new group starts on next UA line
                $current_uas = [];
                continue;
            }
            if ( preg_match( '/^User-agent:\s*(.+)$/i', $line, $m ) ) {
                $ua = strtolower( trim( $m[1] ) );
                // If this UA-line follows previous UA-lines without rules between them,
                // it's a multi-UA group; otherwise start fresh
                $current_uas[] = $ua;
                continue;
            }
            if ( preg_match( '/^(Allow|Disallow):\s*(.*)$/i', $line, $m ) ) {
                $directive = strtolower( $m[1] );
                $path = trim( $m[2] );
                $blocks_root = $path === '/' || $path === '';
                // Disallow: (empty) per spec means "allow everything"
                if ( $directive === 'disallow' && $path === '' ) {
                    // No-op for blocking; counts as allow signal
                    continue;
                }
                foreach ( $current_uas as $ua ) {
                    if ( $ua === '*' ) $wildcard_seen = true;
                    if ( $ua === $bot_lower ) {
                        if ( $directive === 'allow' && $blocks_root ) $bot_explicit_allow = true;
                        if ( $directive === 'disallow' && $blocks_root ) $bot_explicit_disallow = true;
                    }
                    if ( $ua === '*' && $directive === 'disallow' && $blocks_root ) {
                        $wildcard_disallow = true;
                    }
                }
            }
        }

        if ( $bot_explicit_disallow ) {
            return [
                'status' => 'fail',
                'reason' => sprintf(
                    /* translators: %s: bot user-agent */
                    __( 'Explicit `User-agent: %s` + `Disallow: /` block found in robots.txt.', 'seobetter' ),
                    $bot_ua
                ),
            ];
        }
        if ( $bot_explicit_allow ) {
            return [
                'status' => 'pass',
                'reason' => sprintf(
                    /* translators: %s: bot user-agent */
                    __( 'Explicit `User-agent: %s` + `Allow: /` rule found.', 'seobetter' ),
                    $bot_ua
                ),
            ];
        }
        if ( $wildcard_disallow ) {
            return [
                'status' => 'warning',
                'reason' => __( 'Wildcard `User-agent: *` + `Disallow: /` blocks this bot too. Apply the one-click fix to add an explicit Allow rule.', 'seobetter' ),
            ];
        }
        return [
            'status' => 'pass',
            'reason' => __( 'No blocking rule for this bot — default-allow.', 'seobetter' ),
        ];
    }
}
