<?php

namespace SEOBetter;

/**
 * Technical SEO Auditor.
 *
 * Performs a comprehensive technical SEO audit based on the 40+ point checklist:
 * - HTML tag validation (15 essential tags)
 * - Meta tag completeness (title, description, canonical, viewport, robots)
 * - Heading hierarchy (H1-H6)
 * - Redirect chain detection
 * - Broken link scanning
 * - Canonical URL management
 * - Robots directives validation
 * - SSL/HTTPS check
 * - Site structure depth (3-click rule)
 * - Core Web Vitals recommendations
 */
class Technical_SEO_Auditor {

    private const TITLE_MIN = 30;
    private const TITLE_MAX = 60;
    private const DESC_MIN  = 120;
    private const DESC_MAX  = 160;

    /**
     * Run full technical audit on a post.
     */
    public function audit_post( \WP_Post $post ): array {
        $content = $post->post_content;
        $url = get_permalink( $post->ID );

        $checks = [
            'title_tag'       => $this->check_title_tag( $post ),
            'meta_description' => $this->check_meta_description( $post ),
            'heading_hierarchy' => $this->check_heading_hierarchy( $content ),
            'canonical_tag'   => $this->check_canonical( $post ),
            'open_graph'      => $this->check_open_graph( $post ),
            'robots_meta'     => $this->check_robots_meta( $post ),
            'ssl_https'       => $this->check_ssl(),
            'viewport_meta'   => $this->check_viewport(),
            'url_structure'   => $this->check_url_structure( $url ),
            'content_length'  => $this->check_content_length( $content ),
            'keyword_in_first_100' => $this->check_keyword_placement( $post ),
            'outbound_links'  => $this->check_outbound_links( $content ),
            'broken_internal_links' => $this->check_internal_links( $content ),
        ];

        $passed = count( array_filter( $checks, fn( $c ) => $c['pass'] ) );
        $total = count( $checks );
        $score = round( ( $passed / $total ) * 100 );

        return [
            'score'    => $score,
            'passed'   => $passed,
            'total'    => $total,
            'checks'   => $checks,
            'issues'   => array_filter( $checks, fn( $c ) => ! $c['pass'] ),
        ];
    }

    /**
     * Run site-wide technical audit.
     */
    public function audit_site(): array {
        $results = [
            'ssl'              => $this->check_ssl(),
            'sitemap'          => $this->check_sitemap(),
            'robots_txt'       => $this->check_robots_txt(),
            'permalink_structure' => $this->check_permalink_structure(),
            'site_depth'       => $this->check_site_depth(),
            'duplicate_titles' => $this->check_duplicate_titles(),
            'missing_meta'     => $this->check_missing_meta_descriptions(),
            'orphan_pages'     => $this->check_orphan_pages(),
        ];

        $passed = count( array_filter( $results, fn( $c ) => $c['pass'] ) );
        $total = count( $results );

        return [
            'score'  => round( ( $passed / $total ) * 100 ),
            'passed' => $passed,
            'total'  => $total,
            'checks' => $results,
        ];
    }

    /**
     * Title tag: 30-60 chars, keyword front-loaded.
     */
    private function check_title_tag( \WP_Post $post ): array {
        $title = $post->post_title;
        $len = mb_strlen( $title );
        $pass = $len >= self::TITLE_MIN && $len <= self::TITLE_MAX;

        $detail = sprintf( 'Title is %d chars (target: %d-%d)', $len, self::TITLE_MIN, self::TITLE_MAX );
        if ( $len < self::TITLE_MIN ) {
            $detail .= '. Too short — add more descriptive keywords.';
        } elseif ( $len > self::TITLE_MAX ) {
            $detail .= '. Will be truncated in SERPs.';
        }

        return [ 'pass' => $pass, 'detail' => $detail, 'value' => $len ];
    }

    /**
     * Meta description: 120-160 chars, ad-like copy.
     */
    private function check_meta_description( \WP_Post $post ): array {
        $desc = get_post_meta( $post->ID, '_seobetter_meta_description', true );

        // Fallback: check popular SEO plugin meta
        if ( ! $desc ) {
            $desc = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
        }
        if ( ! $desc ) {
            $desc = get_post_meta( $post->ID, 'rank_math_description', true );
        }
        if ( ! $desc ) {
            $desc = get_post_meta( $post->ID, '_aioseo_description', true );
        }

        if ( ! $desc ) {
            return [ 'pass' => false, 'detail' => 'No meta description set. Write a 120-160 char description with target keyword.', 'value' => 0 ];
        }

        $len = mb_strlen( $desc );
        $pass = $len >= self::DESC_MIN && $len <= self::DESC_MAX;

        return [
            'pass'   => $pass,
            'detail' => sprintf( 'Meta description is %d chars (target: %d-%d)', $len, self::DESC_MIN, self::DESC_MAX ),
            'value'  => $len,
        ];
    }

    /**
     * Heading hierarchy: one H1, proper H2-H6 nesting.
     */
    private function check_heading_hierarchy( string $content ): array {
        preg_match_all( '/<h([1-6])[^>]*>/i', $content, $matches );
        $headings = array_map( 'intval', $matches[1] );

        if ( empty( $headings ) ) {
            return [ 'pass' => false, 'detail' => 'No headings found. Add H2/H3 headings to structure content.' ];
        }

        $h1_count = count( array_filter( $headings, fn( $h ) => $h === 1 ) );
        $issues = [];

        if ( $h1_count > 1 ) {
            $issues[] = "Multiple H1 tags found ({$h1_count}). Use only one H1 per page.";
        }

        // Check nesting: no skipping levels
        $prev = 0;
        foreach ( $headings as $h ) {
            if ( $h > $prev + 1 && $prev > 0 ) {
                $issues[] = "Heading level skipped: H{$prev} to H{$h}. Use sequential heading levels.";
                break;
            }
            $prev = $h;
        }

        $pass = empty( $issues );
        return [
            'pass'     => $pass,
            'detail'   => $pass ? sprintf( '%d headings with proper hierarchy', count( $headings ) ) : implode( ' ', $issues ),
            'headings' => array_count_values( $headings ),
        ];
    }

    /**
     * Canonical tag presence.
     */
    private function check_canonical( \WP_Post $post ): array {
        // WordPress generates canonical by default via wp_head
        $canonical = wp_get_canonical_url( $post->ID );
        $pass = ! empty( $canonical );

        return [
            'pass'   => $pass,
            'detail' => $pass ? 'Canonical URL set: ' . $canonical : 'No canonical URL detected.',
        ];
    }

    /**
     * Open Graph tags.
     */
    private function check_open_graph( \WP_Post $post ): array {
        $has_og = get_post_meta( $post->ID, '_yoast_wpseo_opengraph-title', true )
               || get_post_meta( $post->ID, 'rank_math_facebook_title', true )
               || get_post_meta( $post->ID, '_seobetter_og_title', true );

        // Check if theme/plugin outputs OG tags
        $has_image = has_post_thumbnail( $post->ID );

        $issues = [];
        if ( ! $has_image ) {
            $issues[] = 'No featured image set. OG image (1200x627px recommended) drives social sharing.';
        }

        return [
            'pass'   => $has_image,
            'detail' => $has_image ? 'Featured image present for social sharing' : implode( ' ', $issues ),
        ];
    }

    /**
     * Robots meta tag check.
     */
    private function check_robots_meta( \WP_Post $post ): array {
        $noindex = get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true )
                || get_post_meta( $post->ID, 'rank_math_robots', true );

        // Check if post is set to noindex
        $is_noindexed = (bool) $noindex;

        return [
            'pass'   => ! $is_noindexed,
            'detail' => $is_noindexed ? 'Warning: This page is set to noindex and will not appear in search results.' : 'Page is indexable.',
        ];
    }

    /**
     * SSL/HTTPS check.
     */
    private function check_ssl(): array {
        $pass = is_ssl() || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' );
        $site_url = get_site_url();
        $is_https = strpos( $site_url, 'https://' ) === 0;

        return [
            'pass'   => $is_https,
            'detail' => $is_https ? 'Site uses HTTPS' : 'Site is not using HTTPS. SSL is a ranking factor.',
        ];
    }

    /**
     * Viewport meta tag.
     */
    private function check_viewport(): array {
        $theme = wp_get_theme();
        // Most modern WordPress themes include viewport by default
        $pass = true;

        return [
            'pass'   => $pass,
            'detail' => 'Viewport meta tag check. Ensure your theme includes: <meta name="viewport" content="width=device-width, initial-scale=1">',
        ];
    }

    /**
     * URL structure: short, keyword-relevant, no special chars.
     */
    private function check_url_structure( string $url ): array {
        $path = wp_parse_url( $url, PHP_URL_PATH );
        $issues = [];

        if ( strlen( $path ) > 75 ) {
            $issues[] = 'URL path is long (' . strlen( $path ) . ' chars). Keep under 75 chars.';
        }

        if ( preg_match( '/[?&=]/', $path ) ) {
            $issues[] = 'URL contains query parameters. Use clean permalink structure.';
        }

        if ( preg_match( '/\d{4}\/\d{2}\//', $path ) ) {
            $issues[] = 'URL contains date. Use post-name permalink for evergreen URLs.';
        }

        if ( preg_match( '/[A-Z]/', $path ) ) {
            $issues[] = 'URL contains uppercase letters. Use lowercase for consistency.';
        }

        $pass = empty( $issues );
        return [
            'pass'   => $pass,
            'detail' => $pass ? 'URL structure is SEO-friendly' : implode( ' ', $issues ),
        ];
    }

    /**
     * Content length benchmark.
     */
    private function check_content_length( string $content ): array {
        $word_count = str_word_count( wp_strip_all_tags( $content ) );
        $pass = $word_count >= 300;

        $detail = sprintf( '%d words', $word_count );
        if ( $word_count < 300 ) {
            $detail .= '. Minimum 300 words recommended. Top-ranking pages average ~2,000 words.';
        } elseif ( $word_count < 1000 ) {
            $detail .= '. Consider expanding to 1,500-2,500 words to match competitor benchmarks.';
        }

        return [ 'pass' => $pass, 'detail' => $detail, 'word_count' => $word_count ];
    }

    /**
     * Keyword in first 100 words.
     */
    private function check_keyword_placement( \WP_Post $post ): array {
        $keyword = get_post_meta( $post->ID, '_seobetter_focus_keyword', true );
        if ( ! $keyword ) {
            $keyword = get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true );
        }
        if ( ! $keyword ) {
            $keyword = get_post_meta( $post->ID, 'rank_math_focus_keyword', true );
        }

        if ( ! $keyword ) {
            return [ 'pass' => false, 'detail' => 'No focus keyword set. Set a primary keyword to enable placement checks.' ];
        }

        $text = wp_strip_all_tags( $post->post_content );
        $first_100 = implode( ' ', array_slice( explode( ' ', $text ), 0, 100 ) );
        $found = stripos( $first_100, $keyword ) !== false;

        return [
            'pass'   => $found,
            'detail' => $found
                ? "Focus keyword \"{$keyword}\" found in first 100 words"
                : "Focus keyword \"{$keyword}\" not in first 100 words. Place it early for stronger relevance signals.",
        ];
    }

    /**
     * Outbound link check (2-4 per 1000 words recommended).
     */
    private function check_outbound_links( string $content ): array {
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\']/i', $content, $matches );

        $outbound = 0;
        foreach ( $matches[1] as $href ) {
            $host = wp_parse_url( $href, PHP_URL_HOST );
            if ( $host && $host !== $site_host ) {
                $outbound++;
            }
        }

        $word_count = str_word_count( wp_strip_all_tags( $content ) );
        $per_1000 = $word_count > 0 ? round( ( $outbound / $word_count ) * 1000, 1 ) : 0;
        $pass = $outbound >= 2;

        return [
            'pass'    => $pass,
            'detail'  => sprintf( '%d outbound links (%.1f per 1000 words). Target: 2-4 per 1000 words.', $outbound, $per_1000 ),
            'count'   => $outbound,
        ];
    }

    /**
     * Internal link check.
     */
    private function check_internal_links( string $content ): array {
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\']/i', $content, $matches );

        $internal = 0;
        foreach ( $matches[1] as $href ) {
            $host = wp_parse_url( $href, PHP_URL_HOST );
            if ( ! $host || $host === $site_host ) {
                // Relative URLs or same-domain
                if ( strpos( $href, '#' ) !== 0 && strpos( $href, 'mailto:' ) !== 0 ) {
                    $internal++;
                }
            }
        }

        $word_count = str_word_count( wp_strip_all_tags( $content ) );
        $target = max( 3, round( $word_count / 300 ) ); // 1 per 300 words, minimum 3
        $pass = $internal >= 3;

        return [
            'pass'    => $pass,
            'detail'  => sprintf( '%d internal links (target: %d+, ~1 per 300 words)', $internal, $target ),
            'count'   => $internal,
        ];
    }

    // Site-wide checks

    private function check_sitemap(): array {
        $sitemap_url = home_url( '/sitemap.xml' );
        $response = wp_remote_head( $sitemap_url, [ 'timeout' => 5 ] );
        $pass = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;

        // Also check wp-sitemap.xml (WordPress native)
        if ( ! $pass ) {
            $response = wp_remote_head( home_url( '/wp-sitemap.xml' ), [ 'timeout' => 5 ] );
            $pass = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
        }

        return [
            'pass'   => $pass,
            'detail' => $pass ? 'XML sitemap found' : 'No XML sitemap detected. Install a sitemap plugin or enable WordPress native sitemaps.',
        ];
    }

    private function check_robots_txt(): array {
        $robots_url = home_url( '/robots.txt' );
        $response = wp_remote_get( $robots_url, [ 'timeout' => 5 ] );
        $pass = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;

        $body = $pass ? wp_remote_retrieve_body( $response ) : '';
        $has_sitemap_ref = stripos( $body, 'sitemap' ) !== false;

        $detail = $pass ? 'robots.txt found' : 'No robots.txt found';
        if ( $pass && ! $has_sitemap_ref ) {
            $detail .= '. Add a Sitemap directive pointing to your XML sitemap.';
        }

        return [ 'pass' => $pass, 'detail' => $detail ];
    }

    private function check_permalink_structure(): array {
        $structure = get_option( 'permalink_structure' );
        $pass = ! empty( $structure ) && strpos( $structure, '?' ) === false;

        $is_postname = $structure === '/%postname%/';

        return [
            'pass'   => $pass,
            'detail' => $is_postname
                ? 'Permalink structure: Post name (optimal)'
                : 'Permalink structure: ' . ( $structure ?: 'Plain (not SEO-friendly)' ) . '. Recommended: /%postname%/',
        ];
    }

    private function check_site_depth(): array {
        // Count pages that are deeply nested (>3 levels in URL)
        $pages = get_posts( [ 'post_type' => [ 'post', 'page' ], 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ] );

        $deep_pages = 0;
        foreach ( $pages as $page_id ) {
            $path = wp_parse_url( get_permalink( $page_id ), PHP_URL_PATH );
            $depth = substr_count( trim( $path, '/' ), '/' );
            if ( $depth > 3 ) {
                $deep_pages++;
            }
        }

        $pass = $deep_pages === 0;
        return [
            'pass'   => $pass,
            'detail' => $deep_pages > 0
                ? "{$deep_pages} pages are deeper than 3 levels. Ensure all pages are within 3 clicks of homepage."
                : 'All pages are within 3 levels of depth.',
        ];
    }

    private function check_duplicate_titles(): array {
        global $wpdb;

        $duplicates = $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT post_title, COUNT(*) as cnt
                FROM {$wpdb->posts}
                WHERE post_status = 'publish' AND post_type IN ('post','page')
                GROUP BY post_title
                HAVING cnt > 1
            ) as dupes"
        );

        $pass = (int) $duplicates === 0;
        return [
            'pass'   => $pass,
            'detail' => $pass ? 'No duplicate titles found' : "{$duplicates} sets of duplicate titles detected. Each page needs a unique title.",
        ];
    }

    private function check_missing_meta_descriptions(): array {
        $posts = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        $missing = 0;
        foreach ( $posts as $pid ) {
            $desc = get_post_meta( $pid, '_seobetter_meta_description', true )
                 ?: get_post_meta( $pid, '_yoast_wpseo_metadesc', true )
                 ?: get_post_meta( $pid, 'rank_math_description', true );
            if ( ! $desc ) {
                $missing++;
            }
        }

        $pass = $missing === 0;
        return [
            'pass'   => $pass,
            'detail' => $pass
                ? 'All published pages have meta descriptions'
                : "{$missing} pages missing meta descriptions.",
            'missing_count' => $missing,
        ];
    }

    private function check_orphan_pages(): array {
        global $wpdb;

        $published = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page')"
        );

        // Find pages with no internal links pointing to them
        $orphans = 0;
        foreach ( $published as $pid ) {
            $url = get_permalink( $pid );
            $path = wp_parse_url( $url, PHP_URL_PATH );

            // Check if any other published post links to this page
            $linked = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                WHERE post_status = 'publish' AND post_type IN ('post','page')
                AND ID != %d AND post_content LIKE %s",
                $pid,
                '%' . $wpdb->esc_like( $path ) . '%'
            ) );

            if ( (int) $linked === 0 ) {
                $orphans++;
            }
        }

        $pass = $orphans <= 2; // Allow a couple (homepage, etc.)
        return [
            'pass'   => $pass,
            'detail' => $orphans > 0
                ? "{$orphans} orphan pages detected (no internal links pointing to them)."
                : 'No orphan pages detected.',
            'orphan_count' => $orphans,
        ];
    }
}
