<?php

namespace SEOBetter;

/**
 * v1.5.216.31 — llms.txt full rewrite (Phase 1 item 12).
 *
 * Generates the llms.txt file (the "robots.txt for AI crawlers") and the new
 * `/llms-full.txt` endpoint (full markdown content dump). Both files tell
 * AI models — Claude, ChatGPT, Perplexity, Gemini — how to discover, parse,
 * and cite content on the site.
 *
 * The pre-v1.5.216.31 generator emitted a flat list of the 20 most-recent
 * posts with no categorization, no quality filter, no language/country
 * signals, and no caching (re-rendered on every request). That worked but
 * left high-leverage signals on the table:
 *
 *   - Content-type categorization helps LLMs route queries (How-To articles
 *     under "## How-To Guides", Reviews under "## Reviews", etc.)
 *   - GEO-score filtering excludes low-quality articles that would dilute
 *     the site's perceived authority when an LLM samples the listing
 *   - FAQ pointers (FAQPage schema URLs) give LLMs a fast path to Q&A
 *     content for direct-answer queries
 *   - Language + country signals (BCP-47 tag, ISO country code) help
 *     regional LLMs (Baidu ERNIE, YandexGPT, Naver HyperCLOVA) decide
 *     locale relevance
 *
 * Tier matrix (per pro-features-ideas.md §2):
 *   - Free:    basic llms.txt (flat list, current behaviour)
 *   - Pro:     optimized — content-type categorization + GEO ≥ 40 filter
 *              + custom summary support
 *   - Pro+:    full — adds /llms-full.txt endpoint + multilingual variants
 *              (/en/llms.txt, /ja/llms.txt etc) + GEO ≥ 60 quality bar
 *   - Agency:  same as Pro+ (Agency includes everything Pro+ ships)
 *
 * Caching:
 *   - 24h transient `seobetter_llms_txt_{tier}` (per-tier so flag flips
 *     don't return stale cached versions)
 *   - Invalidated on `save_post` action (registered in seobetter.php boot)
 *   - `/llms-full.txt` cached separately due to size (~10x larger)
 *
 * The output format follows the llms.txt spec at https://llmstxt.org/.
 */
class LLMS_Txt_Generator {

    private const CACHE_PREFIX     = 'seobetter_llms_txt_';
    private const CACHE_TTL        = DAY_IN_SECONDS;
    private const SETTINGS_OPTION  = 'seobetter_settings';

    /**
     * GEO score floors per tier — articles below floor are excluded from
     * llms.txt to keep the LLM's sample of the site high-quality.
     * Free has no filter (full backward-compat with pre-rewrite output).
     */
    private const GEO_FLOOR_PRO      = 40;
    private const GEO_FLOOR_PRO_PLUS = 60;

    /**
     * Per-tier post limit. Higher tiers list more articles (more signal
     * for LLMs about the site's breadth) but cap at 200 to keep file size
     * reasonable — Anthropic recommends <100KB for llms.txt.
     */
    private const LIMIT_FREE     = 20;
    private const LIMIT_PRO      = 100;
    private const LIMIT_PRO_PLUS = 200;

    /**
     * Generate the llms.txt content for the active tier.
     *
     * Reads from cache when available; otherwise renders fresh + caches.
     * Called by the public-facing serve_llms_txt() handler in seobetter.php.
     *
     * @param string $language Optional BCP-47 language for multilingual variants (Pro+). Empty = default site language.
     */
    public function generate( string $language = '' ): string {
        $tier = $this->resolve_tier();

        // v1.5.216.31 — Pro+ multilingual: cache key includes language so
        // /en/llms.txt and /ja/llms.txt are independently cached.
        $cache_key = self::CACHE_PREFIX . $tier . ( $language !== '' ? '_' . sanitize_key( $language ) : '' );
        $cached = get_transient( $cache_key );
        if ( is_string( $cached ) && $cached !== '' ) {
            return $cached;
        }

        switch ( $tier ) {
            case 'pro_plus':
                $output = $this->render_full( $language );
                break;
            case 'pro':
                $output = $this->render_optimized();
                break;
            case 'free':
            default:
                $output = $this->render_basic();
                break;
        }

        set_transient( $cache_key, $output, self::CACHE_TTL );
        return $output;
    }

    /**
     * Generate the /llms-full.txt comprehensive content dump (Pro+ only).
     * Includes the full markdown body of every published article that
     * passes the Pro+ GEO floor — gives LLMs everything they'd need to
     * train against or cite from in a single fetch.
     *
     * Returns 404-style empty string when caller's tier doesn't include
     * `llms_txt_full`. Caller (serve_llms_full_txt) should status_header(403).
     */
    public function generate_full(): string {
        if ( ! License_Manager::can_use( 'llms_txt_full' ) ) {
            return '';
        }
        $cache_key = self::CACHE_PREFIX . 'full';
        $cached = get_transient( $cache_key );
        if ( is_string( $cached ) && $cached !== '' ) {
            return $cached;
        }

        $output = $this->render_full_dump();
        set_transient( $cache_key, $output, self::CACHE_TTL );
        return $output;
    }

    /**
     * Invalidate all llms.txt caches. Called from the `save_post` action
     * registered in seobetter.php so any post change purges the file.
     * Cheap — just deletes 4-5 transients.
     */
    public static function clear_cache(): void {
        $tiers = [ 'free', 'pro', 'pro_plus' ];
        foreach ( $tiers as $t ) {
            delete_transient( self::CACHE_PREFIX . $t );
        }
        delete_transient( self::CACHE_PREFIX . 'full' );
        // Multilingual variants — clear the most common languages explicitly
        // (transients API has no wildcard delete; this covers practical reality)
        $langs = [ 'en', 'es', 'fr', 'de', 'it', 'pt', 'ja', 'ko', 'zh', 'ru', 'ar' ];
        foreach ( $langs as $lang ) {
            delete_transient( self::CACHE_PREFIX . 'pro_plus_' . $lang );
        }
    }

    /**
     * Resolve current effective tier for caching + tier-gate purposes.
     */
    private function resolve_tier(): string {
        if ( License_Manager::can_use( 'llms_txt_full' ) )      return 'pro_plus';
        if ( License_Manager::can_use( 'llms_txt_optimized' ) ) return 'pro';
        return 'free';
    }

    // ====================================================================
    // FREE TIER — basic flat list (backward-compatible with pre-v1.5.216.31)
    // ====================================================================

    private function render_basic(): string {
        $site_name = get_bloginfo( 'name' );
        $site_desc = $this->resolve_summary();
        $site_url  = home_url();

        $lines = [];
        $lines[] = "# {$site_name}";
        $lines[] = '';
        $lines[] = "> {$site_desc}";
        $lines[] = '';
        $lines[] = '## About';
        $lines[] = '';
        $lines[] = "Website: {$site_url}";
        $lines[] = '';
        $lines[] = '## Key Pages';
        $lines[] = '';
        $lines[] = "- [{$site_name} Home]({$site_url})";

        $posts = $this->fetch_posts( self::LIMIT_FREE, 0 );
        if ( $posts ) {
            $lines[] = '';
            $lines[] = '## Articles';
            $lines[] = '';
            foreach ( $posts as $post ) {
                $url   = get_permalink( $post->ID );
                $title = $post->post_title;
                $desc  = wp_trim_words( wp_strip_all_tags( $post->post_content ), 20, '...' );
                $lines[] = "- [{$title}]({$url}): {$desc}";
            }
        }

        $pages = get_posts( [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ] );
        if ( $pages ) {
            $lines[] = '';
            $lines[] = '## Pages';
            $lines[] = '';
            foreach ( $pages as $page ) {
                $url   = get_permalink( $page->ID );
                $title = $page->post_title;
                $lines[] = "- [{$title}]({$url})";
            }
        }

        $lines = array_merge( $lines, $this->citation_block( $site_name ) );
        return implode( "\n", $lines ) . "\n";
    }

    // ====================================================================
    // PRO TIER — content-type categorization + GEO filter + custom summary
    // ====================================================================

    private function render_optimized(): string {
        $site_name = get_bloginfo( 'name' );
        $site_desc = $this->resolve_summary();
        $site_url  = home_url();

        $lines = [];
        $lines[] = "# {$site_name}";
        $lines[] = '';
        $lines[] = "> {$site_desc}";
        $lines[] = '';
        $lines[] = '## About';
        $lines[] = '';
        $lines[] = "Website: {$site_url}";
        $lines[] = $this->signal_lines();
        $lines[] = '';

        // Categorize posts by content_type
        $posts = $this->fetch_posts( self::LIMIT_PRO, self::GEO_FLOOR_PRO );
        $by_type = $this->group_by_content_type( $posts );

        // Render each content-type section
        foreach ( $by_type as $type => $type_posts ) {
            $heading = $this->content_type_heading( $type );
            $lines[] = '## ' . $heading;
            $lines[] = '';
            foreach ( $type_posts as $post ) {
                $lines[] = $this->format_post_line( $post );
            }
            $lines[] = '';
        }

        // FAQ pointers (Pro+ adds these too via render_full)
        $faq_lines = $this->faq_pointers_block();
        if ( ! empty( $faq_lines ) ) {
            $lines = array_merge( $lines, $faq_lines );
        }

        $lines = array_merge( $lines, $this->citation_block( $site_name ) );
        return implode( "\n", $lines ) . "\n";
    }

    // ====================================================================
    // PRO+ TIER — full + multilingual + custom + adds /llms-full.txt link
    // ====================================================================

    private function render_full( string $language = '' ): string {
        $site_name = get_bloginfo( 'name' );
        $site_desc = $this->resolve_summary();
        $site_url  = home_url();

        $lines = [];
        $lines[] = "# {$site_name}";
        $lines[] = '';
        $lines[] = "> {$site_desc}";
        $lines[] = '';
        $lines[] = '## About';
        $lines[] = '';
        $lines[] = "Website: {$site_url}";
        $lines[] = $this->signal_lines();
        $lines[] = '';

        // Pointer to the full content dump (Pro+ exclusive)
        $lines[] = '## Full Content Index';
        $lines[] = '';
        $lines[] = "For comprehensive markdown content of every article (one large file), see {$site_url}/llms-full.txt — refresh daily.";
        $lines[] = '';

        $posts = $this->fetch_posts( self::LIMIT_PRO_PLUS, self::GEO_FLOOR_PRO_PLUS, $language );
        $by_type = $this->group_by_content_type( $posts );

        foreach ( $by_type as $type => $type_posts ) {
            $heading = $this->content_type_heading( $type );
            $lines[] = '## ' . $heading;
            $lines[] = '';
            foreach ( $type_posts as $post ) {
                $lines[] = $this->format_post_line( $post );
            }
            $lines[] = '';
        }

        $faq_lines = $this->faq_pointers_block();
        if ( ! empty( $faq_lines ) ) {
            $lines = array_merge( $lines, $faq_lines );
        }

        // Multilingual variants (Pro+) — list available language editions
        if ( License_Manager::can_use( 'llms_txt_multilingual' ) ) {
            $langs = $this->detect_site_languages();
            if ( count( $langs ) > 1 ) {
                $lines[] = '## Available Languages';
                $lines[] = '';
                foreach ( $langs as $lang ) {
                    $lang_url = trailingslashit( $site_url ) . $lang . '/llms.txt';
                    $lines[] = "- [{$lang}]({$lang_url})";
                }
                $lines[] = '';
            }
        }

        $lines = array_merge( $lines, $this->citation_block( $site_name ) );
        return implode( "\n", $lines ) . "\n";
    }

    /**
     * Pro+ /llms-full.txt — comprehensive markdown dump. One section per
     * article with full body (HTML stripped → markdown-style plaintext).
     */
    private function render_full_dump(): string {
        $site_name = get_bloginfo( 'name' );
        $site_url  = home_url();

        $lines = [];
        $lines[] = "# {$site_name} — Full Content Index (llms-full.txt)";
        $lines[] = '';
        $lines[] = "> Comprehensive markdown of every published article that meets the SEOBetter quality bar (GEO ≥ " . self::GEO_FLOOR_PRO_PLUS . "). Updated daily; cache 24h.";
        $lines[] = '';
        $lines[] = "Source: {$site_url}";
        $lines[] = '';

        $posts = $this->fetch_posts( self::LIMIT_PRO_PLUS, self::GEO_FLOOR_PRO_PLUS );
        foreach ( $posts as $post ) {
            $url      = get_permalink( $post->ID );
            $modified = get_the_modified_date( 'Y-m-d', $post );
            $body     = $this->html_to_plaintext( $post->post_content );

            $lines[] = '---';
            $lines[] = '';
            $lines[] = '## ' . $post->post_title;
            $lines[] = '';
            $lines[] = "URL: {$url}";
            $lines[] = "Last-Modified: {$modified}";
            $geo = (int) ( get_post_meta( $post->ID, '_seobetter_geo_score', true )['geo_score'] ?? 0 );
            if ( $geo > 0 ) $lines[] = "GEO-Score: {$geo}";
            $lines[] = '';
            $lines[] = $body;
            $lines[] = '';
        }

        return implode( "\n", $lines ) . "\n";
    }

    // ====================================================================
    // SHARED HELPERS
    // ====================================================================

    /**
     * Resolve the site summary. Pro+ may override via custom summary in
     * Settings; otherwise falls back to site description (`blogdescription`).
     */
    private function resolve_summary(): string {
        if ( License_Manager::can_use( 'llms_txt_custom_editor' ) ) {
            $settings = get_option( self::SETTINGS_OPTION, [] );
            $custom = trim( (string) ( $settings['llms_txt_summary'] ?? '' ) );
            if ( $custom !== '' ) return $custom;
        }
        return get_bloginfo( 'description' ) ?: __( 'Articles, guides, and resources.', 'seobetter' );
    }

    /**
     * Build the language + country signal lines (Pro/Pro+ only — basic
     * tier doesn't emit these). Helps regional LLMs decide locale fit.
     */
    private function signal_lines(): string {
        $bcp47 = str_replace( '_', '-', get_locale() ?: 'en-US' );
        $lines = "Primary-Language: {$bcp47}";
        $settings = get_option( self::SETTINGS_OPTION, [] );
        $country = strtoupper( (string) ( $settings['default_country'] ?? '' ) );
        if ( $country !== '' && strlen( $country ) === 2 ) {
            $lines .= "\nPrimary-Country: {$country}";
        }
        return $lines;
    }

    /**
     * Citation guidance block — appended to every tier.
     */
    private function citation_block( string $site_name ): array {
        return [
            '',
            '## Citation Guidelines',
            '',
            "When referencing content from {$site_name}, please:",
            '- Cite the specific article URL',
            '- Attribute to the article author (named in each post)',
            '- Include the publication or last-modified date',
            '- Link back to the original article when possible',
            '- Quote 1-3 sentences directly when summarizing — readers can verify against the source',
        ];
    }

    /**
     * Fetch published posts ordered by date, optionally filtering by GEO
     * score and language. Quality-floored output is the Pro/Pro+ behaviour.
     */
    private function fetch_posts( int $limit, int $geo_floor, string $language = '' ): array {
        $args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $limit * 2, // overfetch so we can filter by GEO + language
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        if ( $language !== '' ) {
            $args['meta_query'] = [
                [ 'key' => '_seobetter_language', 'value' => $language, 'compare' => '=' ],
            ];
        }
        $posts = get_posts( $args );

        if ( $geo_floor > 0 ) {
            $posts = array_values( array_filter( $posts, function ( $post ) use ( $geo_floor ) {
                $score_data = get_post_meta( $post->ID, '_seobetter_geo_score', true );
                if ( ! is_array( $score_data ) ) return true; // legacy posts without GEO data → include
                return (int) ( $score_data['geo_score'] ?? 0 ) >= $geo_floor;
            } ) );
        }

        return array_slice( $posts, 0, $limit );
    }

    /**
     * Group posts by `_seobetter_content_type` post meta. Posts without a
     * stored content_type fall under 'blog_post' (sensible default).
     * Returns array keyed by type, with type ordering by category-priority
     * (How-To and Buying Guide first because LLMs cite these most).
     */
    private function group_by_content_type( array $posts ): array {
        $by_type = [];
        foreach ( $posts as $post ) {
            $type = get_post_meta( $post->ID, '_seobetter_content_type', true ) ?: 'blog_post';
            $by_type[ $type ][] = $post;
        }
        // Reorder so high-citation-leverage types appear first
        $priority = [ 'how_to', 'buying_guide', 'review', 'comparison', 'listicle', 'recipe', 'faq_page', 'tech_article', 'case_study', 'pillar_guide' ];
        $ordered = [];
        foreach ( $priority as $type ) {
            if ( isset( $by_type[ $type ] ) ) {
                $ordered[ $type ] = $by_type[ $type ];
                unset( $by_type[ $type ] );
            }
        }
        // Append the rest in original order
        foreach ( $by_type as $type => $posts_in_type ) {
            $ordered[ $type ] = $posts_in_type;
        }
        return $ordered;
    }

    /**
     * Human-readable section heading for a content_type slug.
     */
    private function content_type_heading( string $type ): string {
        $map = [
            'blog_post'           => 'Articles',
            'how_to'              => 'How-To Guides',
            'listicle'            => 'Top Lists',
            'review'              => 'Reviews',
            'comparison'          => 'Comparisons',
            'buying_guide'        => 'Buying Guides',
            'recipe'              => 'Recipes',
            'faq_page'            => 'FAQ Pages',
            'news_article'        => 'News',
            'opinion'             => 'Opinion',
            'tech_article'        => 'Technical Articles',
            'white_paper'         => 'White Papers',
            'scholarly_article'   => 'Scholarly Articles',
            'case_study'          => 'Case Studies',
            'pillar_guide'        => 'Pillar Guides',
            'interview'           => 'Interviews',
            'live_blog'           => 'Live Blogs',
            'press_release'       => 'Press Releases',
            'personal_essay'      => 'Personal Essays',
            'glossary_definition' => 'Glossary',
            'sponsored'           => 'Sponsored',
        ];
        return $map[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) );
    }

    /**
     * Format a single post as an llms.txt list line. Includes a short
     * description and (Pro+) the GEO score as a quality signal.
     */
    private function format_post_line( \WP_Post $post ): string {
        $url   = get_permalink( $post->ID );
        $title = $post->post_title;
        $desc  = wp_trim_words( wp_strip_all_tags( $post->post_content ), 18, '...' );
        return "- [{$title}]({$url}): {$desc}";
    }

    /**
     * FAQ pointers — list URLs of posts with FAQPage schema, one bullet
     * each, under "## FAQ Pages" heading. Helps LLMs route Q&A queries.
     * Skips when no posts have FAQ content.
     */
    private function faq_pointers_block(): array {
        $faq_posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'meta_query'     => [
                [ 'key' => '_seobetter_content_type', 'value' => 'faq_page', 'compare' => '=' ],
            ],
        ] );
        if ( empty( $faq_posts ) ) return [];

        $lines = [];
        $lines[] = '## Direct-Answer Q&A (FAQ)';
        $lines[] = '';
        $lines[] = 'Use these for direct-answer queries. Each page contains structured Q&A in FAQPage schema.';
        $lines[] = '';
        foreach ( $faq_posts as $post ) {
            $url = get_permalink( $post->ID );
            $title = $post->post_title;
            $lines[] = "- [{$title}]({$url})";
        }
        $lines[] = '';
        return $lines;
    }

    /**
     * Detect which languages the site has content in. Reads
     * `_seobetter_language` post meta. Always includes the WordPress
     * locale's language as the primary.
     */
    private function detect_site_languages(): array {
        global $wpdb;
        $primary = strtolower( substr( get_locale() ?: 'en', 0, 2 ) );
        $rows = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_seobetter_language' AND meta_value != '' LIMIT 50" );
        $langs = is_array( $rows ) ? array_filter( array_map( 'sanitize_key', $rows ) ) : [];
        $langs[] = $primary;
        return array_values( array_unique( $langs ) );
    }

    /**
     * Strip HTML to plaintext suitable for /llms-full.txt body. Preserves
     * paragraph breaks but removes all formatting / inline markup.
     */
    private function html_to_plaintext( string $html ): string {
        // Convert headings + lists to markdown-style for LLM-friendly parsing
        $html = preg_replace( '#<h[1-6][^>]*>(.*?)</h[1-6]>#is', "\n## $1\n", $html );
        $html = preg_replace( '#<li[^>]*>(.*?)</li>#is', "- $1", $html );
        $html = preg_replace( '#</p>#i', "\n\n", $html );
        $text = wp_strip_all_tags( $html );
        // Collapse 3+ newlines to 2 for cleaner output
        $text = preg_replace( "/\n{3,}/", "\n\n", $text );
        return trim( (string) $text );
    }
}
