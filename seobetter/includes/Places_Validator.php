<?php
/**
 * Places_Validator — v1.5.26 structural anti-hallucination guarantee for
 * local-intent listicles.
 *
 * Problem: even with the Places waterfall (OSM → Foursquare → HERE → Google)
 * fetching real businesses AND the system prompt telling the model "use ONLY
 * these exact names", LLMs sometimes ignore the closed-menu rule when the
 * structural pressure to produce an N-item listicle is stronger than the
 * instruction. The Lucignano test case in v1.5.24 shipped 6 fabricated gelaterie
 * with Italian-sounding names that don't exist at the addresses given.
 *
 * Solution: mirror the 4-pass defensive pattern used by validate_outbound_links()
 * — walk the generated HTML section-by-section, extract business-name candidates
 * from H2/H3 headings and leading <strong> tags, compare against the verified
 * Places Pool, and DELETE any section whose business name cannot be matched.
 *
 * This is Layer 3 of the 3-layer anti-hallucination architecture:
 *   Layer 1 (prompt rule)  — Async_Generator::get_system_prompt() PLACES RULES
 *   Layer 2 (data grounding) — research.js fetchPlacesWaterfall() + REAL LOCAL PLACES block
 *   Layer 3 (post-gen validator) — THIS CLASS
 *
 * Mirrors:
 *   - validate_outbound_links() in seobetter.php (URL-atom version)
 *   - Citation_Pool::contains_url() lookup (same pattern, different atom)
 */

namespace SEOBetter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Places_Validator {

    /**
     * Minimum Levenshtein distance to accept as a fuzzy match. Allows for
     * minor typos, accent variations, and article differences (e.g.
     * "Gelateria di Nonna Rosa" vs "Gelateria Nonna Rosa").
     */
    const FUZZY_DISTANCE = 3;

    /**
     * If more than this ratio of sections get stripped, the article is
     * effectively a hallucination — the caller should re-run generation
     * as a general informational piece with disclaimer.
     */
    const FAILURE_RATIO = 0.5;

    /**
     * Main entry. Validate the generated article HTML against the Places Pool.
     *
     * @param string $html           The assembled article HTML (markdown already formatted).
     * @param array  $places_pool    Array of place entries from research.js fetchPlacesWaterfall.
     *                                Each entry: [name, type, address, website, phone, lat, lon, source_url, source].
     * @param string $business_type  Human-readable business type label ('Ice Cream Shop' etc).
     *                                Used for logging/warnings, not matching.
     * @return array {
     *     @type string $html             Cleaned HTML with unverified sections removed.
     *     @type array  $removed_sections Names of removed H2/H3 business sections.
     *     @type array  $kept_sections    Names of sections that matched the pool.
     *     @type array  $warnings         Human-readable warning strings for the result panel.
     *     @type bool   $force_informational True if > FAILURE_RATIO of sections were stripped.
     *     @type int    $pool_size
     * }
     */
    public static function validate( string $html, array $places_pool, string $business_type = '', bool $is_local_intent = false ): array {
        $result = [
            'html'                => $html,
            'removed_sections'    => [],
            'kept_sections'       => [],
            'warnings'            => [],
            'force_informational' => false,
            'pool_size'           => count( $places_pool ),
        ];

        // v1.5.26: skip entirely for non-local articles (no local intent AND empty pool).
        // v1.5.27: when is_local_intent=true AND pool is empty, we FALL THROUGH into
        // the main validation loop so we can delete any business-name-shaped section
        // the model may have produced despite the prompt-level forbidding. This is
        // the backstop when the pre-generation prompt override (in Async_Generator::
        // generate_outline) isn't sufficient.
        if ( empty( $places_pool ) && ! $is_local_intent ) {
            return $result;
        }

        // Build a normalized lookup set once. O(1) contains check.
        $normalized_pool = [];
        foreach ( $places_pool as $p ) {
            $name = is_array( $p ) ? ( $p['name'] ?? '' ) : '';
            if ( $name === '' ) {
                continue;
            }
            $normalized_pool[] = self::normalize_business_name( $name );
        }

        // v1.5.27 — empty-pool backstop: when is_local_intent=true and the pool is
        // empty, we still walk the article and delete any section whose heading
        // looks like a specific business name. pool_contains() will return false
        // for every candidate, so every business-shaped section gets stripped.
        // Non-business headings (FAQ, History, What to Look For, Key Takeaways,
        // Conclusion) are filtered out by the generic-name check inside
        // extract_business_name_candidate() and are kept intact.
        if ( empty( $normalized_pool ) && ! $is_local_intent ) {
            return $result;
        }

        // Split the HTML into sections at H2/H3 boundaries. We keep everything
        // before the first heading as "preamble" (intro, key takeaways) and
        // never touch it, because it has no business-name claims.
        $sections = self::split_by_headings( $html );
        if ( count( $sections ) <= 1 ) {
            return $result;
        }

        $kept     = [ $sections[0] ]; // preamble always kept
        $removed  = [];
        $listicle_count = count( $sections ) - 1;

        for ( $i = 1; $i < count( $sections ); $i++ ) {
            $section = $sections[ $i ];
            $candidate = self::extract_business_name_candidate( $section );

            if ( $candidate === '' ) {
                // No business-name-shaped text in this section — could be a
                // "How to get there", "Best time to visit", conclusion, etc.
                // Keep it.
                $kept[] = $section;
                continue;
            }

            if ( self::pool_contains( $candidate, $normalized_pool ) ) {
                $kept[] = $section;
                $result['kept_sections'][] = $candidate;
            } else {
                $removed[] = $candidate;
                $result['removed_sections'][] = $candidate;
            }
        }

        // If too many sections got stripped, bail out — the whole article is
        // structurally hallucinated and the caller should regenerate as
        // general informational content.
        $removed_count = count( $removed );
        if ( $listicle_count > 0 && ( $removed_count / $listicle_count ) > self::FAILURE_RATIO ) {
            $result['force_informational'] = true;
            $result['warnings'][] = sprintf(
                'Places_Validator: %d of %d listicle sections named businesses not in the verified pool. The article will be regenerated as a general informational piece with a disclaimer.',
                $removed_count,
                $listicle_count
            );
            // Return original HTML — caller decides what to do based on force_informational.
            return $result;
        }

        if ( $removed_count > 0 ) {
            $result['warnings'][] = sprintf(
                'Places_Validator: removed %d hallucinated business section(s) that did not match the %d verified %s(s) in the pool. Kept: %d real sections.',
                $removed_count,
                count( $normalized_pool ),
                $business_type ?: 'place',
                count( $result['kept_sections'] )
            );
        }

        // Rebuild HTML from kept sections and renumber any "1. / 2. / 3." style
        // listicle headings that may now have gaps.
        $rebuilt = self::rejoin_sections( $kept );
        $rebuilt = self::renumber_listicle_headings( $rebuilt );

        $result['html'] = $rebuilt;
        return $result;
    }

    /**
     * Normalize a business name for comparison. Strips accents, collapses
     * whitespace, lowercases, and removes leading articles ("il", "la", "gli",
     * "les", "the") which the AI sometimes adds or drops inconsistently.
     */
    public static function normalize_business_name( string $name ): string {
        // Strip HTML tags first (in case the candidate came from inside a <strong>)
        $name = wp_strip_all_tags( $name );

        // Transliterate accents. //TRANSLIT//IGNORE falls back gracefully when
        // the glibc iconv lacks the target charset (common on musl/Alpine).
        $transliterated = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $name );
        if ( $transliterated !== false && $transliterated !== '' ) {
            $name = $transliterated;
        }

        $name = strtolower( $name );
        // Remove punctuation except spaces and alphanumerics
        $name = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $name );
        // Remove leading articles
        $name = preg_replace( '/^(il|la|lo|gli|le|les|el|los|las|der|die|das|the|a|an)\s+/', '', $name );
        // Collapse whitespace
        $name = preg_replace( '/\s+/', ' ', $name );
        return trim( $name );
    }

    /**
     * Check if a candidate name is in the normalized pool. Three strategies:
     *   1. Exact normalized match
     *   2. Substring match (candidate contains pool name OR pool name contains candidate)
     *   3. Levenshtein distance ≤ FUZZY_DISTANCE (tolerates typos/transliteration)
     */
    public static function pool_contains( string $candidate, array $normalized_pool ): bool {
        $candidate_norm = self::normalize_business_name( $candidate );
        if ( $candidate_norm === '' ) {
            return false;
        }

        foreach ( $normalized_pool as $pool_name ) {
            if ( $pool_name === '' ) {
                continue;
            }

            // Strategy 1: exact match
            if ( $candidate_norm === $pool_name ) {
                return true;
            }

            // Strategy 2: substring containment (either direction). Protects
            // against "Gelateria della Piazza Lucignano" vs "Gelateria della Piazza".
            if ( strlen( $candidate_norm ) >= 5 && strlen( $pool_name ) >= 5 ) {
                if ( str_contains( $candidate_norm, $pool_name ) || str_contains( $pool_name, $candidate_norm ) ) {
                    return true;
                }
            }

            // Strategy 3: Levenshtein distance. Only run if lengths are close
            // enough to possibly match — avoids O(n²) explosion on long strings.
            // levenshtein() in PHP caps input at 255 chars and returns -1 above.
            if ( abs( strlen( $candidate_norm ) - strlen( $pool_name ) ) <= self::FUZZY_DISTANCE + 2 ) {
                $a = substr( $candidate_norm, 0, 255 );
                $b = substr( $pool_name, 0, 255 );
                $dist = levenshtein( $a, $b );
                if ( $dist >= 0 && $dist <= self::FUZZY_DISTANCE ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Split HTML at H2 and H3 boundaries. Returns an array where [0] is the
     * pre-heading preamble and [1..N] are sections starting with a heading.
     */
    private static function split_by_headings( string $html ): array {
        // Match H2 or H3 opening tag (with or without attributes) as the split point
        $parts = preg_split(
            '/(?=<h[23](?:\s[^>]*)?>)/i',
            $html,
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        return $parts ?: [ $html ];
    }

    /**
     * Rejoin sections back into a single HTML string.
     */
    private static function rejoin_sections( array $sections ): string {
        return implode( '', $sections );
    }

    /**
     * Extract a business-name candidate from a single section. Tries:
     *   1. The text inside the opening H2/H3 (primary signal)
     *   2. If the heading is a listicle-style "1. Gelateria X" or "#1: Gelateria X",
     *      strip the number prefix before matching
     *   3. Falls back to the first <strong>...</strong> inside the first paragraph
     *
     * Returns '' if no candidate looks like a business name.
     */
    private static function extract_business_name_candidate( string $section ): string {
        // Strategy 1: H2/H3 heading text
        if ( preg_match( '/<h[23](?:\s[^>]*)?>(.*?)<\/h[23]>/is', $section, $m ) ) {
            $heading_text = wp_strip_all_tags( $m[1] );
            $heading_text = trim( html_entity_decode( $heading_text, ENT_QUOTES, 'UTF-8' ) );

            // Strip listicle numbering like "1. ", "#1: ", "1) ", "No. 1 — "
            $heading_text = preg_replace( '/^(?:#?\d+[\.\):—\-]|no\.\s*\d+\s*[—\-])\s*/i', '', $heading_text );

            // Heuristic: a business name is typically 2-60 chars and contains
            // letters. Sections like "Conclusion", "FAQ", "References" are
            // kept as-is (no candidate = no check).
            $lower = strtolower( trim( $heading_text ) );
            $generic = [
                'conclusion', 'faq', 'references', 'sources', 'introduction', 'summary',
                'key takeaways', 'how to get there', 'best time to visit', 'planning your trip',
                'what to know', 'final thoughts', 'pros and cons', 'overview', 'why we love it',
                'frequently asked questions', 'about this guide', 'disclaimer',
            ];
            if ( in_array( $lower, $generic, true ) ) {
                return '';
            }
            // Too-short or too-long headings are probably not a specific business
            if ( strlen( $heading_text ) < 3 || strlen( $heading_text ) > 120 ) {
                return '';
            }
            return $heading_text;
        }

        // Strategy 2: first <strong>...</strong>
        if ( preg_match( '/<strong[^>]*>(.*?)<\/strong>/is', $section, $m ) ) {
            $strong_text = wp_strip_all_tags( $m[1] );
            $strong_text = trim( html_entity_decode( $strong_text, ENT_QUOTES, 'UTF-8' ) );
            if ( strlen( $strong_text ) >= 3 && strlen( $strong_text ) <= 120 ) {
                return $strong_text;
            }
        }

        return '';
    }

    /**
     * After sections get removed, listicle numbering may have gaps like
     * "1. ... 3. ... 5. ...". Walk the kept H2 headings and renumber any
     * leading "1. / #2: / 3)" prefix so the remaining sections read cleanly.
     */
    private static function renumber_listicle_headings( string $html ): string {
        $counter = 0;
        return preg_replace_callback(
            '/(<h2(?:\s[^>]*)?>)((?:#?\d+[\.\):—\-]|no\.\s*\d+\s*[—\-])\s*)(.*?)(<\/h2>)/is',
            function ( $m ) use ( &$counter ) {
                $counter++;
                return $m[1] . $counter . '. ' . $m[3] . $m[4];
            },
            $html
        );
    }
}
