<?php

namespace SEOBetter;

/**
 * Social Meta / Open Graph Generator.
 *
 * Generates Open Graph and Twitter Card meta tags for social sharing.
 * OG image: 1200x627px recommended. OG title: 60-90 chars.
 * Social sharing with prominent buttons can increase sharing 700%.
 */
class Social_Meta_Generator {

    /**
     * Output social meta tags in wp_head.
     */
    public function output_meta( int $post_id ): void {
        if ( ! is_singular() ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        // Don't output if another plugin already handles OG tags
        if ( $this->other_plugin_handles_og() ) {
            return;
        }

        $data = $this->get_meta_data( $post );

        // Open Graph
        echo '<meta property="og:type" content="article" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr( $data['title'] ) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr( $data['description'] ) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url( $data['url'] ) . '" />' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr( $data['site_name'] ) . '" />' . "\n";

        if ( $data['image'] ) {
            echo '<meta property="og:image" content="' . esc_url( $data['image'] ) . '" />' . "\n";
            echo '<meta property="og:image:width" content="1200" />' . "\n";
            echo '<meta property="og:image:height" content="627" />' . "\n";
        }

        echo '<meta property="article:published_time" content="' . esc_attr( get_the_date( 'c', $post ) ) . '" />' . "\n";
        echo '<meta property="article:modified_time" content="' . esc_attr( get_the_modified_date( 'c', $post ) ) . '" />' . "\n";

        // Twitter Card
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr( $data['title'] ) . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr( $data['description'] ) . '" />' . "\n";

        if ( $data['image'] ) {
            echo '<meta name="twitter:image" content="' . esc_url( $data['image'] ) . '" />' . "\n";
        }
    }

    /**
     * Get meta data for a post.
     */
    public function get_meta_data( \WP_Post $post ): array {
        $title = get_post_meta( $post->ID, '_seobetter_og_title', true ) ?: $post->post_title;
        $description = get_post_meta( $post->ID, '_seobetter_og_description', true )
            ?: get_post_meta( $post->ID, '_seobetter_meta_description', true )
            ?: wp_trim_words( wp_strip_all_tags( $post->post_content ), 25 );

        // Enforce OG title length (60-90 chars)
        if ( mb_strlen( $title ) > 90 ) {
            $title = mb_substr( $title, 0, 87 ) . '...';
        }

        // Enforce description length
        if ( mb_strlen( $description ) > 160 ) {
            $description = mb_substr( $description, 0, 157 ) . '...';
        }

        $image = get_the_post_thumbnail_url( $post->ID, 'full' );

        return [
            'title'       => $title,
            'description' => $description,
            'url'         => get_permalink( $post->ID ),
            'image'       => $image ?: '',
            'site_name'   => get_bloginfo( 'name' ),
        ];
    }

    /**
     * Analyze social meta quality for a post.
     */
    public function analyze( \WP_Post $post ): array {
        $data = $this->get_meta_data( $post );
        $issues = [];
        $score = 100;

        // Title check
        $title_len = mb_strlen( $data['title'] );
        if ( $title_len < 30 ) {
            $issues[] = 'OG title too short (' . $title_len . ' chars). Aim for 60-90 chars.';
            $score -= 15;
        } elseif ( $title_len > 90 ) {
            $issues[] = 'OG title too long (' . $title_len . ' chars). Keep under 90 chars.';
            $score -= 10;
        }

        // Description check
        if ( empty( $data['description'] ) ) {
            $issues[] = 'No social description set.';
            $score -= 20;
        }

        // Image check (critical for social sharing)
        if ( empty( $data['image'] ) ) {
            $issues[] = 'No featured image. Social posts with images get 2.3x more engagement. Use 1200x627px.';
            $score -= 30;
        } else {
            // Check image dimensions
            $image_id = get_post_thumbnail_id( $post->ID );
            if ( $image_id ) {
                $meta = wp_get_attachment_metadata( $image_id );
                $width = $meta['width'] ?? 0;
                $height = $meta['height'] ?? 0;
                if ( $width < 1200 ) {
                    $issues[] = "Featured image is {$width}px wide. Google Discover requires 1200px+ width.";
                    $score -= 15;
                }
            }
        }

        return [
            'score'  => max( 0, $score ),
            'data'   => $data,
            'issues' => $issues,
        ];
    }

    /**
     * Check if another SEO plugin handles OG tags.
     */
    private function other_plugin_handles_og(): bool {
        // Yoast SEO
        if ( defined( 'WPSEO_VERSION' ) ) {
            return true;
        }
        // Rank Math
        if ( class_exists( 'RankMath' ) ) {
            return true;
        }
        // AIOSEO
        if ( defined( 'AIOSEO_VERSION' ) ) {
            return true;
        }
        return false;
    }
}
