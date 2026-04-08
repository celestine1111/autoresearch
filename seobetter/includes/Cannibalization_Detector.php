<?php

namespace SEOBetter;

/**
 * Keyword Cannibalization Detector.
 *
 * Scans published posts for overlapping focus keywords and flags
 * conflicts where multiple posts compete for the same search term.
 *
 * Pro feature.
 */
class Cannibalization_Detector {

    private const STOP_WORDS = [ 'the', 'a', 'an', 'is', 'are', 'was', 'were', 'to', 'for', 'of', 'in', 'on', 'with', 'and', 'or', 'but', 'not', 'this', 'that', 'it', 'at', 'by', 'from', 'as', 'be', 'has', 'have', 'had', 'do', 'does', 'did', 'will', 'would', 'can', 'could', 'should', 'may', 'might', 'shall', 'must' ];

    /**
     * Detect keyword cannibalization across all published posts.
     */
    public function detect(): array {
        if ( ! License_Manager::can_use( 'cannibalization_detector' ) ) {
            return [ 'success' => false, 'error' => 'Cannibalization detector requires SEOBetter Pro.' ];
        }

        $keyword_map = [];
        $page = 1;
        $per_page = 200;

        do {
            $posts = get_posts( [
                'post_type'      => [ 'post', 'page' ],
                'post_status'    => 'publish',
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'fields'         => 'ids',
            ] );

            foreach ( $posts as $post_id ) {
                $keywords = $this->get_post_keywords( $post_id );
                foreach ( $keywords as $kw ) {
                    $normalized = strtolower( trim( $kw ) );
                    if ( strlen( $normalized ) < 3 ) continue;
                    $keyword_map[ $normalized ][] = $post_id;
                }
            }

            $page++;
        } while ( count( $posts ) === $per_page );

        // Find conflicts (2+ posts sharing a keyword)
        $conflicts = [];
        foreach ( $keyword_map as $keyword => $post_ids ) {
            $unique_ids = array_unique( $post_ids );
            if ( count( $unique_ids ) < 2 ) continue;

            $group = [
                'keyword' => $keyword,
                'posts'   => [],
            ];

            foreach ( $unique_ids as $pid ) {
                $post = get_post( $pid );
                if ( ! $post ) continue;

                $geo_score = get_post_meta( $pid, '_seobetter_geo_score', true );

                $group['posts'][] = [
                    'post_id'    => $pid,
                    'title'      => $post->post_title,
                    'url'        => get_permalink( $pid ),
                    'edit_url'   => get_edit_post_link( $pid, 'raw' ),
                    'published'  => $post->post_date,
                    'word_count' => str_word_count( wp_strip_all_tags( $post->post_content ) ),
                    'geo_score'  => is_array( $geo_score ) ? ( $geo_score['geo_score'] ?? 0 ) : 0,
                ];
            }

            $group['count'] = count( $group['posts'] );
            $group['recommendation'] = $this->get_recommendation( $group );
            $group['similarity'] = $this->get_similarity( $group['posts'] );

            $conflicts[] = $group;
        }

        // Sort by number of conflicting posts (most conflicts first)
        usort( $conflicts, fn( $a, $b ) => $b['count'] - $a['count'] );

        $total_posts_affected = 0;
        foreach ( $conflicts as $c ) {
            $total_posts_affected += $c['count'];
        }

        return [
            'success'              => true,
            'conflicts'            => $conflicts,
            'total_conflicts'      => count( $conflicts ),
            'total_posts_affected' => $total_posts_affected,
        ];
    }

    /**
     * Extract focus keywords from a post.
     */
    private function get_post_keywords( int $post_id ): array {
        $keywords = [];

        // Focus keyword from SEO plugins
        $focus = get_post_meta( $post_id, '_seobetter_focus_keyword', true )
              ?: get_post_meta( $post_id, '_yoast_wpseo_focuskw', true )
              ?: get_post_meta( $post_id, 'rank_math_focus_keyword', true );

        if ( $focus ) {
            $keywords[] = $focus;
        }

        // Extract meaningful phrases from title
        $title = get_the_title( $post_id );
        $title_words = array_filter(
            explode( ' ', strtolower( preg_replace( '/[^a-zA-Z0-9\s]/', '', $title ) ) ),
            fn( $w ) => strlen( $w ) > 2 && ! in_array( $w, self::STOP_WORDS, true )
        );

        // Add 2-3 word phrases from title
        $words = array_values( $title_words );
        for ( $i = 0; $i < count( $words ) - 1; $i++ ) {
            $keywords[] = $words[ $i ] . ' ' . $words[ $i + 1 ];
            if ( isset( $words[ $i + 2 ] ) ) {
                $keywords[] = $words[ $i ] . ' ' . $words[ $i + 1 ] . ' ' . $words[ $i + 2 ];
            }
        }

        return array_unique( $keywords );
    }

    /**
     * Get recommendation for a conflict group.
     */
    private function get_recommendation( array $group ): array {
        $posts = $group['posts'];
        $count = count( $posts );

        // Find the best post (highest GEO score, then longest)
        usort( $posts, function( $a, $b ) {
            if ( $b['geo_score'] !== $a['geo_score'] ) return $b['geo_score'] - $a['geo_score'];
            return $b['word_count'] - $a['word_count'];
        } );

        $best = $posts[0];
        $others = array_slice( $posts, 1 );

        if ( $count === 2 ) {
            return [
                'action'   => 'merge',
                'keep'     => $best['post_id'],
                'merge'    => array_column( $others, 'post_id' ),
                'message'  => sprintf(
                    'Merge "%s" into "%s" (higher GEO score: %d) and redirect.',
                    $others[0]['title'], $best['title'], $best['geo_score']
                ),
                'severity' => 'medium',
            ];
        }

        return [
            'action'   => 'consolidate',
            'keep'     => $best['post_id'],
            'redirect' => array_column( $others, 'post_id' ),
            'message'  => sprintf(
                'Keep "%s" as canonical. Redirect %d other posts to it.',
                $best['title'], count( $others )
            ),
            'severity' => 'high',
        ];
    }

    /**
     * Calculate content similarity between posts in a group.
     */
    private function get_similarity( array $posts ): float {
        if ( count( $posts ) < 2 ) return 0;

        $p1 = get_post( $posts[0]['post_id'] );
        $p2 = get_post( $posts[1]['post_id'] );
        if ( ! $p1 || ! $p2 ) return 0;

        $words1 = array_unique( str_word_count( strtolower( wp_strip_all_tags( $p1->post_content ) ), 1 ) );
        $words2 = array_unique( str_word_count( strtolower( wp_strip_all_tags( $p2->post_content ) ), 1 ) );

        // Filter stop words
        $words1 = array_diff( $words1, self::STOP_WORDS );
        $words2 = array_diff( $words2, self::STOP_WORDS );

        // Jaccard similarity
        $intersection = count( array_intersect( $words1, $words2 ) );
        $union = count( array_unique( array_merge( $words1, $words2 ) ) );

        return $union > 0 ? round( ( $intersection / $union ) * 100, 1 ) : 0;
    }
}
