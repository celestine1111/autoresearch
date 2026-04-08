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
}
