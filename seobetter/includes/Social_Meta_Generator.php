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

        // v1.5.216.62.86 — Standard SEO meta description (Google reads this for
        // SERP snippets). Pre-fix Social_Meta_Generator only emitted og:* and
        // twitter:* — no plain `<meta name="description">` tag, so SEOBetter
        // sites without Yoast/RankMath/AIOSEO had no meta description at all.
        if ( ! empty( $data['description'] ) ) {
            echo '<meta name="description" content="' . esc_attr( $data['description'] ) . '" />' . "\n";
        }

        // Open Graph
        echo '<meta property="og:type" content="article" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr( $data['title'] ) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr( $data['description'] ) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url( $data['url'] ) . '" />' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr( $data['site_name'] ) . '" />' . "\n";

        if ( $data['image'] ) {
            // v1.5.215 — use ACTUAL image dimensions when available (some users
            // upload square 1200×1200 logos, Pinterest pins at 1000×1500, etc).
            // og:image:type helps crawlers skip a HEAD request to detect mime;
            // og:image:alt is an accessibility win + LinkedIn renders it under
            // the share preview. Falls back to OG-standard 1200×630 when the
            // featured image attachment metadata is missing (external URL).
            $thumb_id = get_post_thumbnail_id( $post->ID );
            $img_w = 1200; $img_h = 630; $img_mime = '';
            if ( $thumb_id ) {
                $meta_img = wp_get_attachment_metadata( $thumb_id );
                if ( ! empty( $meta_img['width'] ) )  $img_w = (int) $meta_img['width'];
                if ( ! empty( $meta_img['height'] ) ) $img_h = (int) $meta_img['height'];
                $img_mime = (string) get_post_mime_type( $thumb_id );
            }
            $img_alt = $thumb_id ? (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) : '';
            if ( $img_alt === '' ) $img_alt = $data['title'];

            echo '<meta property="og:image" content="' . esc_url( $data['image'] ) . '" />' . "\n";
            echo '<meta property="og:image:width" content="' . (int) $img_w . '" />' . "\n";
            echo '<meta property="og:image:height" content="' . (int) $img_h . '" />' . "\n";
            if ( $img_mime !== '' ) {
                echo '<meta property="og:image:type" content="' . esc_attr( $img_mime ) . '" />' . "\n";
            }
            echo '<meta property="og:image:alt" content="' . esc_attr( $img_alt ) . '" />' . "\n";
        }

        echo '<meta property="article:published_time" content="' . esc_attr( get_the_date( 'c', $post ) ) . '" />' . "\n";
        echo '<meta property="article:modified_time" content="' . esc_attr( get_the_modified_date( 'c', $post ) ) . '" />' . "\n";

        // Twitter Card
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr( $data['title'] ) . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr( $data['description'] ) . '" />' . "\n";

        if ( $data['image'] ) {
            echo '<meta name="twitter:image" content="' . esc_url( $data['image'] ) . '" />' . "\n";
            // v1.5.215 — twitter:image:alt is the spec-compliant alt for the
            // Twitter Card large image. Mirrors og:image:alt above.
            echo '<meta name="twitter:image:alt" content="' . esc_attr( $img_alt ?? $data['title'] ) . '" />' . "\n";
        }
    }

    /**
     * Get meta data for a post.
     */
    public function get_meta_data( \WP_Post $post ): array {
        $title = get_post_meta( $post->ID, '_seobetter_og_title', true ) ?: $post->post_title;
        $description = get_post_meta( $post->ID, '_seobetter_og_description', true )
            ?: get_post_meta( $post->ID, '_seobetter_meta_description', true )
            ?: $this->extract_clean_fallback( $post->post_content );

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
     * v1.5.216.62.86 — Cleaner content extraction when no AI-generated meta
     * description is available. Pre-fix `wp_trim_words(wp_strip_all_tags(...))`
     * dumped the type-badge ("⇄ Comparison"), the H1 (with weird ucwords-mangled
     * caps), "Last Updated: May 2026", and duplicated "Key Takeaways" into the
     * og:description. This version strips structural chrome first.
     */
    private function extract_clean_fallback( string $content ): string {
        // Strip wp:html blocks (type badges, opinion bars, callouts, tables).
        $clean = preg_replace( '/<!-- wp:html -->.*?<!-- \/wp:html -->/s', '', (string) $content );
        // Strip all heading text (H1-H6).
        $clean = preg_replace( '/<h[1-6][^>]*>.*?<\/h[1-6]>/is', '', (string) $clean );
        // Strip "Last Updated: Month YYYY" stamps.
        $clean = preg_replace( '/Last Updated:?\s*[A-Za-z]+\s*\d{4}/i', '', (string) $clean );
        // Strip Gutenberg block comments.
        $clean = preg_replace( '/<!--\s*\/?wp:[^>]*-->/', '', (string) $clean );
        // Strip remaining HTML and collapse whitespace.
        $clean = wp_strip_all_tags( (string) $clean );
        $clean = preg_replace( '/\s+/', ' ', trim( (string) $clean ) );
        // Cap at 30 words for fallback (AI-generated description gets the full 160 chars).
        return wp_trim_words( $clean, 30 );
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
