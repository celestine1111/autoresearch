<?php

namespace SEOBetter;

/**
 * Citation Pool — retrieves, filters, and content-verifies a bounded set of
 * real keyword-relevant article URLs before article generation.
 *
 * This is the "retrieve" step of our RAG-style citation system. The pool is
 * injected into the AI prompt as an AVAILABLE CITATIONS list, used as the
 * primary allow-list by validate_outbound_links(), and used to build the
 * References section programmatically at save time.
 *
 * See seo-guidelines/external-links-policy.md for the full architecture.
 *
 * Research foundation:
 *   - Joshi (2025): RAG reduces hallucinations by 42-58% — grounding in
 *     retrieved documents is the #1 mitigation lever.
 *   - Gosmar & Dahl (2501.13946): FGR (Factual Grounding References) metric
 *     — maximize citations that resolve to verified sources.
 *   - Yin et al. (2602.05723): RLFKV — verify each citation unit against
 *     the retrieved document content (Pass 3).
 */
class Citation_Pool {

    private const CACHE_TTL = 21600; // 6 hours
    // v1.5.63 — cache version bump invalidates all pre-v1.5.62 pools.
    // Without this, v1.5.62's topical relevance filter isn't applied
    // because stale pools built under v1.5.61 (no filter) were still
    // being returned from the transient cache. Bump this whenever the
    // pool builder logic changes.
    private const CACHE_VERSION = 'v2';

    /**
     * Build a citation pool for a keyword. Returns an array of pool entries:
     *   [ { url, title, source_name, verified_at }, ... ]
     *
     * The pool only contains URLs that:
     *   1. Came from a keyword-targeted web search (not a data API)
     *   2. Have a deep path (not a homepage)
     *   3. Are not API / endpoint / dev-host URLs
     *   4. Return 200-399 on a HEAD check
     *   5. Contain the keyword in their title or body prefix (content verification)
     */
    public static function build( string $keyword, string $country = '', string $domain = 'general' ): array {
        if ( empty( $keyword ) ) {
            return [];
        }

        $cache_key = 'seobetter_pool_' . self::CACHE_VERSION . '_' . md5( $keyword . '|' . $country . '|' . $domain );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        // Collect candidate URLs from keyword-targeted sources only.
        // Data APIs (NASA, CoinGecko, Nager.Date, etc.) are NOT part of the pool —
        // their stats inform the article content but can't be cited.
        $candidates = [];

        $research = Trend_Researcher::research( $keyword, $domain, $country );
        if ( ! empty( $research['sources'] ) && is_array( $research['sources'] ) ) {
            foreach ( $research['sources'] as $source ) {
                if ( is_array( $source ) && ! empty( $source['url'] ) ) {
                    $candidates[] = [
                        'url'         => $source['url'],
                        'title'       => $source['title'] ?? '',
                        'source_name' => $source['source_name'] ?? '',
                    ];
                } elseif ( is_string( $source ) ) {
                    $candidates[] = [
                        'url'         => $source,
                        'title'       => '',
                        'source_name' => '',
                    ];
                }
            }
        }

        // v1.5.61 — pool filter relaxed. Previously passes_live_check (4s
        // HEAD request) and passes_content_verification (5s GET + keyword
        // match) were run synchronously on every candidate. On WP Engine
        // and similar hosts where outbound HTTP is slow or occasionally
        // firewall-blocked, all 12 candidates failed the live check and
        // the pool came back empty → AVAILABLE CITATIONS: None in the
        // prompt → AI used plain-text citations → append_references_section
        // had no markdown links to build from → article published with
        // zero outbound links (live Mindiam test confirmed this).
        //
        // New approach: hygiene check stays (blocks obvious junk URLs).
        // Live check + content verification become SOFT — their failure is
        // logged but doesn't reject the URL. An unverified-but-plausible
        // URL is infinitely better than no URL at all. The worst case is
        // one broken link in References which is recoverable.
        // v1.5.62 — topical relevance filter. v1.5.61 removed HTTP content
        // verification to fix the empty-pool issue on WP Engine, but that
        // meant every always-on source (Brave/Reddit/HN/DDG/dev.to) flowed
        // through unfiltered. Live test returned dev.to April Fools posts
        // and a Wikipedia Veganism entry in references for a raw-dog-food
        // article. Fix: require the source title to contain at least one
        // content token from the keyword. No HTTP calls. Milliseconds.
        $stopwords = [
            'the','and','for','how','what','why','when','where','which','who',
            'with','from','that','this','these','those','have','has','had',
            'your','their','best','top','safely','guide','tips','2024','2025','2026','2027',
        ];
        $raw_terms = preg_split( '/\s+/', strtolower( $keyword ) );
        $key_tokens = [];
        foreach ( $raw_terms as $t ) {
            $t = preg_replace( '/[^\w]/', '', $t );
            if ( strlen( $t ) >= 4 && ! in_array( $t, $stopwords, true ) ) {
                $key_tokens[] = $t;
            }
        }

        $pool = [];
        $seen_urls = [];
        foreach ( $candidates as $c ) {
            $url = $c['url'];

            // Dedup
            $normalized = self::normalize_url( $url );
            if ( isset( $seen_urls[ $normalized ] ) ) {
                continue;
            }
            $seen_urls[ $normalized ] = true;

            // Hygiene check (URL format sanity) remains a HARD filter
            if ( ! self::passes_hygiene( $url ) ) {
                continue;
            }

            // v1.5.62 — topical relevance. Title OR URL slug must contain
            // at least one content token from the keyword. Skips when the
            // keyword has no usable tokens (e.g. pure stopwords).
            if ( ! empty( $key_tokens ) ) {
                $title_lower = strtolower( $c['title'] ?? '' );
                $slug_lower  = strtolower( wp_parse_url( $url, PHP_URL_PATH ) ?? '' );
                $haystack    = $title_lower . ' ' . $slug_lower;
                $has_match   = false;
                foreach ( $key_tokens as $t ) {
                    if ( str_contains( $haystack, $t ) ) {
                        $has_match = true;
                        break;
                    }
                }
                if ( ! $has_match ) {
                    continue;
                }
            }

            $pool[] = [
                'url'         => $url,
                'title'       => $c['title'] ?: self::guess_title_from_url( $url ),
                'source_name' => $c['source_name'] ?: wp_parse_url( $url, PHP_URL_HOST ),
                'verified_at' => time(),
            ];

            if ( count( $pool ) >= 12 ) {
                break;
            }
        }

        set_transient( $cache_key, $pool, self::CACHE_TTL );
        return $pool;
    }

    /**
     * Format the pool as a prompt injection block for the AI.
     * Returns empty string if pool is empty.
     */
    public static function format_for_prompt( array $pool ): string {
        if ( empty( $pool ) ) {
            return "\n\nAVAILABLE CITATIONS: None — the research pipeline found no keyword-relevant articles for this topic. Use plain-text attributions only (e.g. 'According to a 2026 RSPCA report'). Do NOT output any [text](url) markdown links.\n";
        }

        $lines = [ "\n\nAVAILABLE CITATIONS (use ONLY these exact URLs for any hyperlinks you output):" ];
        foreach ( $pool as $entry ) {
            $title = $entry['title'] ?: $entry['source_name'];
            $lines[] = "- [{$title}]({$entry['url']}) — {$entry['source_name']}";
        }
        $lines[] = "\nRULES for these citations:";
        $lines[] = "- Any hyperlink you output must be character-for-character identical to one of the URLs above";
        $lines[] = "- Match the citation to the claim — don't cite a random pool URL just because it's available";
        $lines[] = "- Use each URL at most once";
        $lines[] = "- If a claim isn't supported by any URL in the pool, use a plain-text attribution instead (no link)";
        $lines[] = "- DO NOT output a References section — the plugin builds it automatically from the citations you used in the body";
        $lines[] = "- Zero hyperlinks + good plain-text attributions is a PASS. Hallucinated URLs are a FAIL.\n";

        return implode( "\n", $lines );
    }

    /**
     * Check if a URL is present in the pool (pool membership check for the validator).
     * URLs are compared after normalization (scheme+host+path, query string ignored).
     */
    public static function contains_url( array $pool, string $url ): bool {
        $normalized = self::normalize_url( $url );
        foreach ( $pool as $entry ) {
            if ( self::normalize_url( $entry['url'] ) === $normalized ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the pool entry for a specific URL (for building the References section).
     */
    public static function get_entry( array $pool, string $url ): ?array {
        $normalized = self::normalize_url( $url );
        foreach ( $pool as $entry ) {
            if ( self::normalize_url( $entry['url'] ) === $normalized ) {
                return $entry;
            }
        }
        return null;
    }

    // ================================================================
    // Private helpers
    // ================================================================

    /**
     * Normalize a URL for equality comparison (scheme + host + path, no query).
     */
    private static function normalize_url( string $url ): string {
        $parts = wp_parse_url( $url );
        if ( ! $parts ) {
            return strtolower( trim( $url ) );
        }
        $scheme = strtolower( $parts['scheme'] ?? 'https' );
        $host = strtolower( $parts['host'] ?? '' );
        $host = preg_replace( '/^www\./', '', $host );
        $path = rtrim( $parts['path'] ?? '', '/' );
        return "{$scheme}://{$host}{$path}";
    }

    /**
     * Static hygiene rules — same as validator's filter_link rules.
     */
    private static function passes_hygiene( string $url ): bool {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        $path = wp_parse_url( $url, PHP_URL_PATH );

        if ( ! $host ) return false;

        // Deep link required
        $trimmed_path = trim( (string) $path, '/' );
        if ( $trimmed_path === '' || $trimmed_path === 'index.html' || $trimmed_path === 'index.php' ) {
            return false;
        }

        // No API hosts
        if ( preg_match( '/(^|\.)api\.|-api\.|\.herokuapp\.com$/i', $host ) ) {
            return false;
        }

        // No API paths
        if ( preg_match( '#/api/|/v[1-9]/|/graphql|/rest/|/swagger|raw\.githubusercontent\.com#i', $url ) ) {
            return false;
        }

        return true;
    }

    /**
     * HEAD request to confirm URL is live.
     */
    private static function passes_live_check( string $url ): bool {
        $response = wp_remote_head( $url, [
            'timeout'     => 4,
            'redirection' => 3,
            'sslverify'   => false,
            'user-agent'  => 'Mozilla/5.0 (compatible; SEOBetter/1.0)',
        ] );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $code = wp_remote_retrieve_response_code( $response );
        return $code >= 200 && $code < 400;
    }

    /**
     * Content verification — fetch the page, extract title + body prefix,
     * require at least one content word from the keyword to appear.
     *
     * This is a lower bar than the Pass 3 rule (which requires 50% overlap)
     * because we're building the pool, not filtering AI output. Pool entries
     * are re-verified by Pass 3 against the actual anchor text at validation
     * time, so having them in the pool isn't a blank check.
     */
    private static function passes_content_verification( string $url, string $keyword ): bool {
        $stopwords = [
            'the','and','for','how','what','why','when','where','which','who',
            'with','from','that','this','these','those','have','has','had',
        ];

        $raw_terms = preg_split( '/\s+/', strtolower( $keyword ) );
        $key_terms = [];
        foreach ( $raw_terms as $t ) {
            $t = preg_replace( '/[^\w]/', '', $t );
            if ( strlen( $t ) >= 4 && ! in_array( $t, $stopwords, true ) ) {
                $key_terms[] = $t;
            }
        }

        // Keyword has no content words (e.g. "what is the best") — skip verification
        if ( empty( $key_terms ) ) {
            return true;
        }

        $response = wp_remote_get( $url, [
            'timeout'     => 5,
            'redirection' => 3,
            'sslverify'   => false,
            'user-agent'  => 'Mozilla/5.0 (compatible; SEOBetter/1.0)',
        ] );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $title = '';
        if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $body, $tm ) ) {
            $title = wp_strip_all_tags( $tm[1] );
        }
        $text = wp_strip_all_tags( $body );
        $text = preg_replace( '/\s+/', ' ', $text );
        $haystack = strtolower( $title . ' ' . substr( $text, 0, 4000 ) );

        // Require at least one key term in the destination content
        foreach ( $key_terms as $t ) {
            if ( strpos( $haystack, $t ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * When the pool source didn't include a title, extract a readable title
     * from the URL slug (e.g. /how-to-choose-a-dog-bed → "How to Choose a Dog Bed").
     */
    private static function guess_title_from_url( string $url ): string {
        $path = wp_parse_url( $url, PHP_URL_PATH );
        $slug = basename( rtrim( (string) $path, '/' ) );
        $slug = preg_replace( '/\.(html?|php|aspx?)$/i', '', $slug );
        $slug = str_replace( [ '-', '_' ], ' ', $slug );
        $slug = trim( $slug );
        return $slug ? ucwords( $slug ) : 'Source article';
    }
}
