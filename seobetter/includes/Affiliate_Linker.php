<?php

namespace SEOBetter;

/**
 * Affiliate Linker.
 *
 * Auto-links affiliate keywords in generated content and inserts CTA buttons.
 * Integrates with ThirstyAffiliates if active, or works standalone with manual URLs.
 *
 * Rules:
 * - Only links FIRST occurrence of each keyword (not spammy)
 * - Skips text inside existing links, headings, and alt attributes
 * - Adds rel="nofollow sponsored" and target="_blank"
 * - Inserts CTA button after the paragraph containing the linked keyword
 */
class Affiliate_Linker {

    /**
     * Check if ThirstyAffiliates is active.
     */
    public static function is_thirstyaffiliates_active(): bool {
        return post_type_exists( 'thirstylink' );
    }

    /**
     * Get all ThirstyAffiliates links for the search dropdown.
     */
    public static function get_ta_links(): array {
        if ( ! self::is_thirstyaffiliates_active() ) {
            return [];
        }

        $posts = get_posts( [
            'post_type'      => 'thirstylink',
            'posts_per_page' => 100,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        $links = [];
        foreach ( $posts as $post ) {
            $destination = get_post_meta( $post->ID, '_ta_destination_url', true );
            $cloaked_url = get_permalink( $post->ID );

            $links[] = [
                'id'          => $post->ID,
                'title'       => $post->post_title,
                'cloaked_url' => $cloaked_url,
                'destination' => $destination,
            ];
        }

        return $links;
    }

    /**
     * Process content: auto-link affiliate keywords and insert CTAs.
     *
     * @param string $content     HTML content (already formatted).
     * @param array  $affiliates  Array of [ 'keyword' => string, 'url' => string, 'name' => string ].
     * @param string $mode        'gutenberg' or 'classic'.
     * @param string $accent      Accent color for CTA buttons.
     * @return string Content with affiliate links and CTAs inserted.
     */
    public function process( string $content, array $affiliates, string $mode = 'gutenberg', string $accent = '#764ba2' ): string {
        if ( empty( $affiliates ) ) {
            return $content;
        }

        foreach ( $affiliates as $aff ) {
            $keyword = $aff['keyword'] ?? '';
            $url = $aff['url'] ?? '';
            $name = $aff['name'] ?? $keyword;

            if ( empty( $keyword ) || empty( $url ) ) {
                continue;
            }

            $content = $this->link_keyword( $content, $keyword, $url );
            $content = $this->insert_cta( $content, $keyword, $url, $name, $mode, $accent );
        }

        return $content;
    }

    /**
     * Replace the first occurrence of a keyword with an affiliate link.
     * Skips text inside existing <a> tags, headings, and img alt attributes.
     */
    private function link_keyword( string $content, string $keyword, string $url ): string {
        $escaped_keyword = preg_quote( $keyword, '/' );

        // Match the keyword only when NOT inside an HTML tag attribute or existing link
        // Strategy: split content by HTML tags, only replace in text nodes
        $parts = preg_split( '/(<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE );

        $linked = false;
        $in_link = false;
        $in_heading = false;

        for ( $i = 0; $i < count( $parts ); $i++ ) {
            $part = $parts[ $i ];

            // Track if we're inside an <a> tag or heading
            if ( preg_match( '/<a\b/i', $part ) ) {
                $in_link = true;
            }
            if ( preg_match( '/<\/a>/i', $part ) ) {
                $in_link = false;
            }
            if ( preg_match( '/<h[1-6]\b/i', $part ) ) {
                $in_heading = true;
            }
            if ( preg_match( '/<\/h[1-6]>/i', $part ) ) {
                $in_heading = false;
            }

            // Skip HTML tags, links, and headings
            if ( $linked || $in_link || $in_heading || preg_match( '/^</', $part ) ) {
                continue;
            }

            // Try to replace first occurrence in this text node
            $replacement = '<a href="' . esc_url( $url ) . '" rel="nofollow sponsored" target="_blank">' . esc_html( $keyword ) . '</a>';
            $new_part = preg_replace( '/\b' . $escaped_keyword . '\b/i', $replacement, $part, 1, $count );

            if ( $count > 0 ) {
                $parts[ $i ] = $new_part;
                $linked = true;
            }
        }

        return implode( '', $parts );
    }

    /**
     * Insert a CTA button after the paragraph containing the linked keyword.
     */
    private function insert_cta( string $content, string $keyword, string $url, string $name, string $mode, string $accent ): string {
        // Find the paragraph or block that contains the affiliate link
        $escaped_url = preg_quote( $url, '/' );

        if ( $mode === 'gutenberg' ) {
            $cta = $this->gutenberg_cta( $url, $name, $accent );
        } else {
            $cta = $this->classic_cta( $url, $name, $accent );
        }

        // Insert CTA after the </p> that contains the affiliate link
        $pattern = '/(<p[^>]*>(?:(?!<\/p>).)*' . $escaped_url . '(?:(?!<\/p>).)*<\/p>)/is';
        $content = preg_replace( $pattern, '$1' . "\n" . $cta, $content, 1 );

        return $content;
    }

    /**
     * Generate Gutenberg CTA button block.
     */
    private function gutenberg_cta( string $url, string $name, string $accent ): string {
        $text = esc_html( $this->cta_text( $name ) );
        $esc_url = esc_url( $url );

        return <<<BLOCK
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"left"}} -->
<div class="wp-block-buttons">
<!-- wp:button {"backgroundColor":"vivid-purple","style":{"border":{"radius":"6px"}},"className":"is-style-fill"} -->
<div class="wp-block-button is-style-fill"><a class="wp-block-button__link has-vivid-purple-background-color has-background wp-element-button" href="{$esc_url}" style="border-radius:6px;background-color:{$accent}" rel="nofollow sponsored" target="_blank">{$text}</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
BLOCK;
    }

    /**
     * Generate Classic editor CTA button.
     */
    private function classic_cta( string $url, string $name, string $accent ): string {
        $text = esc_html( $this->cta_text( $name ) );
        $esc_url = esc_url( $url );

        return '<div style="margin:20px 0"><a href="' . $esc_url . '" rel="nofollow sponsored" target="_blank" style="display:inline-block;padding:14px 28px;background:' . $accent . ';color:#fff;text-decoration:none;border-radius:6px;font-weight:600;font-size:15px">' . $text . '</a></div>';
    }

    /**
     * Generate CTA button text.
     */
    private function cta_text( string $name ): string {
        $name = trim( $name );
        $templates = [
            'Check %s Prices →',
            'View %s →',
            'Get %s Now →',
            'Shop %s →',
        ];

        // Pick template based on name hash for consistency
        $index = crc32( $name ) % count( $templates );
        return sprintf( $templates[ $index ], $name );
    }
}
