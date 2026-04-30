<?php

namespace SEOBetter;

/**
 * Content Freshness Manager.
 *
 * Tracks content age and flags stale pages for refresh.
 * Based on research: content freshness is a critical tiebreaker for AI citations.
 * Strategy: 1 updated post per 5 new posts. Only update content 1+ year old.
 */
class Content_Freshness_Manager {

    private const STALE_DAYS     = 365; // Flag content older than 1 year
    private const WARNING_DAYS   = 180; // Warn for content older than 6 months
    private const REFRESH_RATIO  = 5;   // 1 refresh per 5 new posts

    /**
     * Get all content with freshness status.
     */
    public function get_freshness_report(): array {
        $posts = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'modified',
            'order'          => 'ASC',
        ] );

        $stale = [];
        $warning = [];
        $fresh = [];
        $now = time();

        foreach ( $posts as $post ) {
            $modified = strtotime( $post->post_modified );
            $days_since = round( ( $now - $modified ) / DAY_IN_SECONDS );

            $item = [
                'id'            => $post->ID,
                'title'         => $post->post_title,
                'url'           => get_permalink( $post->ID ),
                'last_modified' => $post->post_modified,
                'days_since'    => $days_since,
                'word_count'    => str_word_count( wp_strip_all_tags( $post->post_content ) ),
                'has_freshness_signal' => $this->has_freshness_signal( $post->post_content ),
            ];

            if ( $days_since >= self::STALE_DAYS ) {
                $item['status'] = 'stale';
                $stale[] = $item;
            } elseif ( $days_since >= self::WARNING_DAYS ) {
                $item['status'] = 'warning';
                $warning[] = $item;
            } else {
                $item['status'] = 'fresh';
                $fresh[] = $item;
            }
        }

        return [
            'stale'           => $stale,
            'warning'         => $warning,
            'fresh'           => $fresh,
            'stale_count'     => count( $stale ),
            'warning_count'   => count( $warning ),
            'fresh_count'     => count( $fresh ),
            'total'           => count( $posts ),
            'refresh_needed'  => count( $stale ),
            'next_refresh_candidates' => array_slice( $stale, 0, 5 ),
        ];
    }

    /**
     * Get refresh suggestions for a specific post.
     */
    public function get_refresh_suggestions( \WP_Post $post ): array {
        $suggestions = [];
        $content = $post->post_content;
        $text = wp_strip_all_tags( $content );

        // Check for outdated year references
        $current_year = (int) date( 'Y' );
        preg_match_all( '/\b(20[12]\d)\b/', $text, $year_matches );
        $old_years = array_filter( $year_matches[1], fn( $y ) => (int) $y < $current_year - 1 );
        if ( ! empty( $old_years ) ) {
            $suggestions[] = [
                'type'     => 'dates',
                'priority' => 'high',
                'message'  => 'Update outdated year references: ' . implode( ', ', array_unique( $old_years ) ),
            ];
        }

        // Check for freshness signal
        if ( ! $this->has_freshness_signal( $content ) ) {
            $suggestions[] = [
                'type'     => 'freshness',
                'priority' => 'high',
                'message'  => 'Add "Last Updated: ' . wp_date( 'F Y' ) . '" at the top. Freshness is a critical tiebreaker for AI citations.',
            ];
        }

        // Check word count vs benchmarks
        $word_count = str_word_count( $text );
        if ( $word_count < 1500 ) {
            $suggestions[] = [
                'type'     => 'length',
                'priority' => 'medium',
                'message'  => "Content is {$word_count} words. Consider expanding to 1,500-2,500 words to match current competitor benchmarks.",
            ];
        }

        // Check for statistics freshness
        preg_match_all( '/\(.*?(20[12]\d).*?\)/', $text, $stat_years );
        $old_stats = array_filter( $stat_years[1] ?? [], fn( $y ) => (int) $y < $current_year - 2 );
        if ( ! empty( $old_stats ) ) {
            $suggestions[] = [
                'type'     => 'statistics',
                'priority' => 'high',
                'message'  => 'Update statistics with recent data. Found references from: ' . implode( ', ', array_unique( $old_stats ) ),
            ];
        }

        // Check for broken/outdated external links
        preg_match_all( '/<a[^>]+href=["\']https?:\/\/[^"\']+["\']/i', $content, $link_matches );
        if ( count( $link_matches[0] ) > 0 ) {
            $suggestions[] = [
                'type'     => 'links',
                'priority' => 'medium',
                'message'  => 'Verify all ' . count( $link_matches[0] ) . ' external links still work. Broken links hurt authority signals.',
            ];
        }

        // Suggest adding new GEO elements
        preg_match_all( '/"[^"]{20,}"/', $text, $quotes );
        if ( count( $quotes[0] ) < 2 ) {
            $suggestions[] = [
                'type'     => 'geo',
                'priority' => 'medium',
                'message'  => 'Add fresh expert quotes from current sources. Quotation Addition boosts GEO visibility by 41%.',
            ];
        }

        // Suggest title update
        $suggestions[] = [
            'type'     => 'title',
            'priority' => 'low',
            'message'  => 'Consider adding "Updated" or current year to the title for higher CTR from SERPs.',
        ];

        return $suggestions;
    }

    /**
     * Check if content has a freshness signal.
     */
    private function has_freshness_signal( string $content ): bool {
        return (bool) preg_match( '/last\s*updated|date\s*modified|updated\s*on|reviewed\s*on|published\s*on/i', $content );
    }

    // ── v1.5.216.23 — Phase 1 item 4: sortable inventory + priority scoring ──

    /**
     * Build the sortable inventory of all published posts with refresh-priority
     * composite score. Replaces the 3-section (stale/warning/fresh) report
     * from `get_freshness_report()` with a single rich table that the new
     * Freshness admin page renders.
     *
     * Pro+ tier: priority weighted by GSC click decay + position drift.
     * Free tier: priority weighted by age + outdated-year flags + missing
     * "Last Updated" signal. License gate is `gsc_freshness_driver`.
     *
     * @param int $limit  Max posts to return (default 200; UI can paginate later).
     * @return array of post-rows ready for the freshness.php table.
     */
    public function get_inventory( int $limit = 200 ): array {
        $posts = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'modified',
            'order'          => 'ASC',
        ] );

        $now              = time();
        $current_year     = (int) date( 'Y' );
        $can_use_gsc      = License_Manager::can_use( 'gsc_freshness_driver' );
        $gsc_connected    = GSC_Manager::is_connected();
        $gsc_active       = $can_use_gsc && $gsc_connected;
        $rows             = [];

        foreach ( $posts as $post ) {
            $modified_ts   = strtotime( $post->post_modified );
            $age_days      = max( 0, (int) round( ( $now - $modified_ts ) / DAY_IN_SECONDS ) );
            $text          = wp_strip_all_tags( $post->post_content );
            $word_count    = str_word_count( $text );
            $has_signal    = $this->has_freshness_signal( $post->post_content );

            // Outdated year mentions in the body — flag any year < (current - 1)
            preg_match_all( '/\b(20[12]\d)\b/', $text, $year_matches );
            $outdated_years = 0;
            foreach ( ( $year_matches[1] ?? [] ) as $y ) {
                if ( (int) $y < $current_year - 1 ) $outdated_years++;
            }

            // GSC stats (Pro+ only — Free sees lock badge in UI, no data leak)
            $gsc_stats = [];
            if ( $gsc_active ) {
                $gsc_stats = GSC_Manager::get_post_stats( $post->ID );
            }

            // Composite priority 0-100. With GSC: 50% age/flags + 50% GSC decay
            // signals. Without GSC: 100% age/flags. Per pro-features-ideas.md
            // Phase 1 item 4 strategic deep-dive scoring formula.
            $base_priority = self::compute_base_priority( $age_days, $outdated_years, $has_signal );
            if ( $gsc_active && ! empty( $gsc_stats ) ) {
                $gsc_priority = self::compute_gsc_priority( $gsc_stats );
                $priority     = (int) round( ( $base_priority * 0.5 ) + ( $gsc_priority * 0.5 ) );
            } else {
                $priority = $base_priority;
            }
            $priority = max( 0, min( 100, $priority ) );

            $rows[] = [
                'id'             => $post->ID,
                'title'          => $post->post_title,
                'url'            => get_permalink( $post->ID ),
                'edit_url'       => get_edit_post_link( $post->ID, 'raw' ),
                'modified'       => $post->post_modified,
                'age_days'       => $age_days,
                'word_count'     => $word_count,
                'outdated_years' => $outdated_years,
                'has_signal'     => $has_signal,
                'gsc'            => $gsc_stats,
                'priority'       => $priority,
            ];
        }

        // Sort by priority DESC by default; UI JS can re-sort by any column
        usort( $rows, fn( $a, $b ) => $b['priority'] - $a['priority'] );

        return [
            'rows'           => $rows,
            'total'          => count( $rows ),
            'gsc_connected'  => $gsc_connected,
            'gsc_active'     => $gsc_active,           // can_use AND connected
            'can_use_gsc'    => $can_use_gsc,          // tier check (Pro+)
            'oauth_configured' => GSC_Manager::is_oauth_configured(),
        ];
    }

    /**
     * Base priority (no GSC) — age + flags + missing signal.
     *
     * Formula per the strategic deep-dive in pro-features-ideas.md:
     *   priority = age_days/3
     *            + outdated_year_count * 15
     *            + (missing_freshness_signal ? 10 : 0)
     *            + (age_days > 365 ? 20 : 0)
     */
    private static function compute_base_priority( int $age_days, int $outdated_years, bool $has_signal ): int {
        $score  = (int) round( $age_days / 3 );
        $score += $outdated_years * 15;
        if ( ! $has_signal )       $score += 10;
        if ( $age_days > 365 )      $score += 20;
        return max( 0, min( 100, $score ) );
    }

    /**
     * GSC-driven priority component (Pro+ only). Higher score = MORE in need
     * of refresh based on traffic-decay signals.
     *
     * Heuristic for the v1 MVP (28-day snapshot only — no historical delta yet):
     *   - Position 11-30 (striking distance, just off page 1) → high refresh
     *     priority (a small content lift might push to top 10)
     *   - Position 1-10 (already ranking) → low priority (don't break what works)
     *   - Position 30+ (deep) → moderate priority (refresh probably won't fix this alone)
     *   - Low impressions / 0 clicks → high priority (re-target the topic)
     *
     * v2 will compare 28d vs prior-28d snapshots once we have historical data;
     * for now we only have the latest snapshot.
     */
    private static function compute_gsc_priority( array $stats ): int {
        $position    = (float) ( $stats['position_28d'] ?? 0 );
        $clicks      = (int) ( $stats['clicks_28d'] ?? 0 );
        $impressions = (int) ( $stats['impressions_28d'] ?? 0 );

        $score = 0;

        // Striking distance — biggest opportunity bucket
        if ( $position >= 11 && $position <= 30 ) {
            $score += 50;
        } elseif ( $position > 30 ) {
            $score += 30;
        } elseif ( $position >= 1 && $position <= 10 ) {
            $score += 5; // ranking already; light refresh suggestion only
        }

        // Impressions but no clicks → snippet/title might be the issue
        if ( $impressions >= 100 && $clicks === 0 ) {
            $score += 25;
        }

        // No GSC presence at all → either too new or invisible
        if ( $impressions < 10 ) {
            $score += 15;
        }

        return max( 0, min( 100, $score ) );
    }
}
