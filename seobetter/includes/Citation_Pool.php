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
    // v1.5.137 — bump to force fresh pool build with Serper bypass flag.
    private const CACHE_VERSION = 'v4';

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
    /**
     * v1.5.81 — accepts optional $sonar_citations from the Vercel backend's
     * server-side Sonar call. These are high-quality URLs from Perplexity's
     * live web search and get merged as pool candidates alongside DDG/Reddit/
     * HN/Wikipedia sources. They still go through hygiene + topical filter.
     */
    public static function build( string $keyword, string $country = '', string $domain = 'general', array $sonar_citations = [] ): array {
        if ( empty( $keyword ) ) {
            return [];
        }

        $cache_key = 'seobetter_pool_' . self::CACHE_VERSION . '_' . md5( $keyword . '|' . $country . '|' . $domain );
        $cached = get_transient( $cache_key );
        // v1.5.136 — only use cache if pool is non-empty. Empty pools from
        // previous crashes should not be served from cache.
        if ( is_array( $cached ) && ! empty( $cached ) ) {
            return $cached;
        }

        // Collect candidate URLs from keyword-targeted sources only.
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

        // v1.5.137 — Serper/Firecrawl citations from the Vercel backend.
        // These are Google search results for the exact keyword — already topically
        // relevant by definition. Marked with _serper_bypass so the topical filter
        // below skips them (Google already did the relevance filtering).
        foreach ( $sonar_citations as $sc ) {
            if ( ! is_array( $sc ) || empty( $sc['url'] ) ) continue;
            $candidates[] = [
                'url'            => $sc['url'],
                'title'          => $sc['title'] ?? '',
                'source_name'    => $sc['source_name'] ?? wp_parse_url( $sc['url'], PHP_URL_HOST ),
                '_serper_bypass' => true,
            ];
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
            // v1.5.137 — Skip for Serper/Firecrawl citations (_serper_bypass).
            // Google searched for the exact keyword, so results are inherently
            // relevant. The filter was stripping ALL citations for abstract
            // keywords like "why ai wont replace teachers" where the tokens
            // (wont, replace, teachers) don't appear in citation titles.
            $bypass = ! empty( $c['_serper_bypass'] );
            if ( ! $bypass && ! empty( $key_tokens ) ) {
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

        // Only cache non-empty pools — empty pools from API failures should be retried
        if ( ! empty( $pool ) ) {
            set_transient( $cache_key, $pool, self::CACHE_TTL );
        }
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
        $lines[] = "- You CAN reuse the same URL multiple times if you reference the same source in different sections";
        $lines[] = "- Every time you mention a source by name, make it a clickable link — do NOT write plain-text source names without linking them";
        $lines[] = "- If a claim isn't supported by any URL in the pool, use a plain-text attribution instead (no link)";
        $lines[] = "- DO NOT output a References section — the plugin builds it automatically from the citations you used in the body";
        $lines[] = "- Zero hyperlinks + good plain-text attributions is a PASS. Hallucinated URLs are a FAIL.\n";

        return implode( "\n", $lines );
    }

    /**
     * Check if a URL is present in the pool (pool membership check for the validator).
     * URLs are compared after normalization (scheme+host+path, query string ignored).
     */
    /**
     * v1.5.64 — Build and append a programmatic References section to a
     * markdown article using the verified citation pool. Previously this
     * logic only lived in seobetter.php::append_references_section() and
     * ran at save time, which meant the live preview never showed the
     * References section even when citations were cited in the body.
     *
     * Walks the markdown for every `[text](url)` link, collects pool
     * entries in first-mention order, and appends a numbered
     * `## References` section. If no body links match pool URLs but the
     * pool is non-empty, falls back to the first 8 pool entries so the
     * article always has clickable outbound citations.
     *
     * Format: `N. [title](url)` — no source suffix (v1.5.46 decision).
     */
    public static function append_references_section( string $markdown, array $pool ): string {
        if ( empty( $pool ) ) {
            return $markdown;
        }

        // Remove any existing References section the AI may have written
        $markdown = preg_replace(
            '/\n(##+)\s*(references|sources|further reading|bibliography|citations)\b[^\n]*(\n[\s\S]*?)?(?=\n#{1,6}\s|\z)/i',
            "\n",
            $markdown
        );

        // Find every markdown link (non-image) and match against pool
        preg_match_all( '/(?<!!)\[([^\]]+)\]\((https?:\/\/[^)]+)\)/', $markdown, $matches, PREG_SET_ORDER );

        $cited_entries = [];
        $cited_urls = [];
        foreach ( $matches as $m ) {
            $url = $m[2];
            $entry = self::get_entry( $pool, $url );
            if ( $entry && ! in_array( $url, $cited_urls, true ) ) {
                $cited_urls[] = $url;
                $cited_entries[] = $entry;
            }
        }

        // Fallback: if no body links matched but pool is non-empty, include
        // the first 8 pool entries (v1.5.60 feature preserved)
        if ( empty( $cited_entries ) && ! empty( $pool ) ) {
            $count = 0;
            foreach ( $pool as $entry ) {
                if ( empty( $entry['url'] ) ) continue;
                $cited_entries[] = $entry;
                $count++;
                if ( $count >= 8 ) break;
            }
        }

        if ( empty( $cited_entries ) ) {
            return $markdown;
        }

        // Build the section. Locked format per article_design.md §10:
        //   ## References
        //   1. [title](url)
        //   2. [title](url)
        $lines = [ '', '## References', '' ];
        $i = 1;
        foreach ( $cited_entries as $entry ) {
            $title = trim( (string) ( $entry['title'] ?? '' ) );
            $url   = $entry['url'];
            if ( $title === '' ) {
                $title = (string) ( $entry['source_name'] ?? wp_parse_url( $url, PHP_URL_HOST ) ?: 'Source' );
            }
            // v1.5.137 — Sanitize title: brackets break markdown link syntax
            $title = str_replace( [ '[', ']' ], '', $title );
            if ( mb_strlen( $title ) > 80 ) {
                $title = mb_substr( $title, 0, 77 ) . '...';
            }
            $lines[] = "{$i}. [{$title}]({$url})";
            $i++;
        }

        return rtrim( $markdown ) . "\n" . implode( "\n", $lines ) . "\n";
    }

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

    /**
     * v1.5.78 — Public wrapper for hygiene check. Used by
     * Content_Injector::optimize_all() to validate Sonar-provided URLs
     * before merging them into the citation pool.
     */
    public static function passes_hygiene_public( string $url ): bool {
        return self::passes_hygiene( $url );
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

        // v1.5.137 — Block academic/DOI/data URLs that aren't useful for general articles.
        // DOI links often 404, government gazettes are junk for most topics,
        // and raw data API endpoints aren't reader-friendly.
        if ( preg_match( '/^(doi\.org|dx\.doi\.org)$/i', $host ) ) {
            return false;
        }
        // Block raw government data/gazette/JSON endpoints
        if ( preg_match( '#\.(json|xml|csv|pdf)$#i', $trimmed_path ) ) {
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
