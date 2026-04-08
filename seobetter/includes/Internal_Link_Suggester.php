<?php

namespace SEOBetter;

/**
 * Internal Link Suggester.
 *
 * Suggests internal links between posts based on keyword and topic overlap.
 * Helps improve site architecture and distribute link equity.
 *
 * Pro feature.
 */
class Internal_Link_Suggester {

    private const STOP_WORDS = [ 'the', 'a', 'an', 'is', 'are', 'was', 'were', 'to', 'for', 'of', 'in', 'on', 'with', 'and', 'or', 'but', 'not', 'this', 'that', 'it', 'at', 'by', 'from', 'as', 'be', 'has', 'have', 'had', 'do', 'does', 'did', 'how', 'what', 'why', 'when', 'where', 'which', 'who' ];

    /**
     * Suggest internal links for a specific post.
     */
    public function suggest_for_post( int $post_id, int $max = 10 ): array {
        if ( ! License_Manager::can_use( 'internal_link_suggestions' ) ) {
            return [ 'success' => false, 'error' => 'Internal link suggestions require SEOBetter Pro.' ];
        }

        $source = get_post( $post_id );
        if ( ! $source ) {
            return [ 'success' => false, 'error' => 'Post not found.' ];
        }

        $source_keywords = $this->get_post_keywords( $post_id );
        $existing_links = $this->get_existing_internal_links( $source->post_content );

        // Get all other published posts
        $candidates = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'exclude'        => [ $post_id ],
        ] );

        $suggestions = [];

        foreach ( $candidates as $candidate ) {
            $url = get_permalink( $candidate->ID );

            // Skip if already linked
            if ( in_array( $url, $existing_links, true ) || in_array( $candidate->post_name, $existing_links, true ) ) {
                continue;
            }

            $target_keywords = $this->get_post_keywords( $candidate->ID );
            $relevance = $this->calculate_relevance( $source_keywords, $target_keywords );

            if ( $relevance < 15 ) continue;

            // Find best anchor text
            $anchor = $this->suggest_anchor( $target_keywords, $source->post_content );

            $geo_score = get_post_meta( $candidate->ID, '_seobetter_geo_score', true );

            $suggestions[] = [
                'target_post_id' => $candidate->ID,
                'target_title'   => $candidate->post_title,
                'target_url'     => $url,
                'edit_url'       => get_edit_post_link( $candidate->ID, 'raw' ),
                'anchor_text'    => $anchor,
                'relevance_score' => $relevance,
                'geo_score'      => is_array( $geo_score ) ? ( $geo_score['geo_score'] ?? 0 ) : 0,
            ];
        }

        // Sort by relevance
        usort( $suggestions, fn( $a, $b ) => $b['relevance_score'] - $a['relevance_score'] );

        return [
            'success'     => true,
            'post_id'     => $post_id,
            'post_title'  => $source->post_title,
            'suggestions' => array_slice( $suggestions, 0, $max ),
            'total_found' => count( $suggestions ),
        ];
    }

    /**
     * Get site-wide link opportunity report.
     */
    public function suggest_site_wide( int $limit = 50 ): array {
        $posts = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $all_suggestions = [];
        foreach ( $posts as $post ) {
            $link_count = $this->count_internal_links( $post->post_content );
            if ( $link_count > 5 ) continue; // Skip well-linked posts

            $result = $this->suggest_for_post( $post->ID, 3 );
            if ( ! empty( $result['suggestions'] ) ) {
                foreach ( $result['suggestions'] as $s ) {
                    $s['source_post_id'] = $post->ID;
                    $s['source_title'] = $post->post_title;
                    $s['source_links'] = $link_count;
                    $all_suggestions[] = $s;
                }
            }
        }

        usort( $all_suggestions, fn( $a, $b ) => $b['relevance_score'] - $a['relevance_score'] );

        return [
            'success'     => true,
            'suggestions' => array_slice( $all_suggestions, 0, $limit ),
            'total_posts' => count( $posts ),
        ];
    }

    /**
     * Extract keywords from a post.
     */
    public function get_post_keywords( int $post_id ): array {
        $keywords = [];
        $post = get_post( $post_id );
        if ( ! $post ) return [];

        // Focus keyword
        $focus = get_post_meta( $post_id, '_seobetter_focus_keyword', true )
              ?: get_post_meta( $post_id, '_yoast_wpseo_focuskw', true )
              ?: get_post_meta( $post_id, 'rank_math_focus_keyword', true );
        if ( $focus ) $keywords[] = strtolower( $focus );

        // Title words
        $title_words = $this->extract_meaningful_words( $post->post_title );
        $keywords = array_merge( $keywords, $title_words );

        // H2/H3 headings
        preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>/is', $post->post_content, $matches );
        foreach ( $matches[1] ?? [] as $heading ) {
            $heading_words = $this->extract_meaningful_words( wp_strip_all_tags( $heading ) );
            $keywords = array_merge( $keywords, $heading_words );
        }

        return array_unique( $keywords );
    }

    /**
     * Extract meaningful words/phrases from text.
     */
    private function extract_meaningful_words( string $text ): array {
        $words = array_filter(
            explode( ' ', strtolower( preg_replace( '/[^a-zA-Z0-9\s]/', '', $text ) ) ),
            fn( $w ) => strlen( $w ) > 2 && ! in_array( $w, self::STOP_WORDS, true )
        );

        $results = array_values( $words );

        // Add 2-word phrases
        for ( $i = 0; $i < count( $results ) - 1; $i++ ) {
            $results[] = $results[ $i ] . ' ' . $results[ $i + 1 ];
        }

        return $results;
    }

    /**
     * Calculate relevance between source and target keywords.
     */
    private function calculate_relevance( array $source_kw, array $target_kw ): int {
        $overlap = array_intersect( $source_kw, $target_kw );
        $score = count( $overlap ) * 15;

        // Bonus for exact focus keyword match
        if ( ! empty( $source_kw[0] ) && in_array( $source_kw[0], $target_kw, true ) ) {
            $score += 25;
        }

        return min( 100, $score );
    }

    /**
     * Suggest anchor text based on target keywords.
     */
    private function suggest_anchor( array $target_keywords, string $source_content ): string {
        $text = strtolower( wp_strip_all_tags( $source_content ) );

        // Try to find a target keyword that appears in the source content
        foreach ( $target_keywords as $kw ) {
            if ( stripos( $text, $kw ) !== false && strlen( $kw ) > 5 ) {
                return $kw;
            }
        }

        return $target_keywords[0] ?? '';
    }

    /**
     * Get existing internal link URLs from content.
     */
    private function get_existing_internal_links( string $content ): array {
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $links = [];

        preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\']/i', $content, $matches );
        foreach ( $matches[1] as $href ) {
            $host = wp_parse_url( $href, PHP_URL_HOST );
            if ( ! $host || $host === $site_host ) {
                $links[] = $href;
            }
        }

        return $links;
    }

    /**
     * Count internal links in content.
     */
    public function count_internal_links( string $content ): int {
        return count( $this->get_existing_internal_links( $content ) );
    }
}
