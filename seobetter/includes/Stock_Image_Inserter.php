<?php

namespace SEOBetter;

/**
 * Stock Image Inserter.
 *
 * Auto-inserts royalty-free stock images from Unsplash into generated articles.
 * Images are placed after the first H2 and then every 2-3 sections.
 *
 * SEO optimizations applied:
 * - Descriptive alt tags based on section context and keyword
 * - Small file sizes via Unsplash's image CDN params (w=800, q=80, fm=webp)
 * - Native lazy loading (loading="lazy")
 * - Width/height attributes to prevent CLS
 * - WebP format for smaller file sizes
 */
class Stock_Image_Inserter {

    private const UNSPLASH_BASE = 'https://images.unsplash.com';
    private const IMAGE_WIDTH   = 800;
    private const IMAGE_HEIGHT  = 450;
    private const IMAGE_QUALITY = 80;

    /**
     * Insert stock images into markdown content.
     *
     * @param string $markdown The article in Markdown.
     * @param string $keyword  Primary keyword for image search and alt text.
     * @return string Markdown with images inserted.
     */
    public function insert_images( string $markdown, string $keyword ): string {
        // Split content by H2 headings
        $parts = preg_split( '/(^## .+$)/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE );

        if ( count( $parts ) < 3 ) {
            return $markdown; // Not enough sections to insert images
        }

        $output = '';
        $h2_count = 0;
        $image_inserted = 0;
        $max_images = 3; // Don't overload with images

        for ( $i = 0; $i < count( $parts ); $i++ ) {
            $part = $parts[ $i ];

            // Check if this is an H2 heading
            if ( preg_match( '/^## (.+)$/m', $part, $m ) ) {
                $h2_count++;
                $heading_text = trim( $m[1] );
                $output .= $part;

                // Insert image after 1st H2, then every 3rd H2
                if ( $image_inserted < $max_images && ( $h2_count === 1 || $h2_count % 3 === 0 ) ) {
                    // Get the section content (next part) for context
                    $section_context = isset( $parts[ $i + 1 ] ) ? substr( wp_strip_all_tags( $parts[ $i + 1 ] ), 0, 100 ) : '';
                    $alt_text = $this->generate_alt_text( $keyword, $heading_text, $section_context );
                    $image_url = $this->get_image_url( $keyword, $heading_text, $image_inserted );

                    $output .= "\n\n![{$alt_text}]({$image_url})\n";
                    $image_inserted++;
                }
            } else {
                $output .= $part;
            }
        }

        return $output;
    }

    /**
     * Generate SEO-optimized alt text for an image.
     *
     * Rules from SEO-GEO-AI-GUIDELINES.md Section 12:
     * - Descriptive of image content + context
     * - Include primary keyword naturally (not stuffed)
     * - 8-125 characters optimal
     * - Never start with "image of" or "picture of"
     * - Format: "[What image shows] - [Context with keyword]"
     */
    private function generate_alt_text( string $keyword, string $heading, string $context ): string {
        $heading = preg_replace( '/[#*_\[\]()]/', '', $heading );
        $heading = trim( $heading, '? ' );
        $keyword_clean = trim( $keyword );

        // Build varied, descriptive alt texts based on section position
        $templates = [
            '%s comparison chart showing key features and differences',
            'Guide to choosing the best %s for your needs',
            '%s overview with expert recommendations and ratings',
            'Detailed %s breakdown with pricing and specifications',
            'Visual guide explaining %s benefits and considerations',
        ];

        // Pick template based on heading content
        $index = abs( crc32( $heading ) ) % count( $templates );

        // If heading has a question word, use a more specific pattern
        if ( preg_match( '/^(what|how|why|which|when|where)/i', $heading ) ) {
            $alt = ucfirst( strtolower( $heading ) ) . ' — ' . $keyword_clean . ' visual guide';
        } elseif ( preg_match( '/compare|vs|versus|difference/i', $heading ) ) {
            $alt = ucfirst( $keyword_clean ) . ' comparison table showing features and ratings';
        } elseif ( preg_match( '/faq|question/i', $heading ) ) {
            $alt = 'Frequently asked questions about ' . $keyword_clean . ' answered by experts';
        } elseif ( preg_match( '/takeaway|summary|key/i', $heading ) ) {
            $alt = 'Key takeaways and highlights for ' . $keyword_clean;
        } else {
            $alt = sprintf( $templates[ $index ], $keyword_clean );
        }

        // Enforce 8-125 char limit
        if ( mb_strlen( $alt ) > 125 ) {
            $alt = mb_substr( $alt, 0, 122 ) . '...';
        }

        return $alt;
    }

    /**
     * Get an Unsplash image URL with SEO-optimized params.
     *
     * Uses Unsplash Source API for keyword-based images.
     * Applies: WebP format, 800px width, 80% quality, auto-crop.
     */
    /**
     * Get a topic-relevant image URL.
     * Uses Pexels API if key is configured, falls back to Picsum.
     */
    /** Track used image URLs to prevent duplicates within an article. */
    private array $used_urls = [];

    private function get_image_url( string $keyword, string $heading, int $index ): string {
        $settings = get_option( 'seobetter_settings', [] );
        $pexels_key = $settings['pexels_api_key'] ?? '';

        if ( ! empty( $pexels_key ) ) {
            // Use different search terms per section for variety
            $searches = [
                $this->build_search_terms( $keyword, $heading ),
                $keyword,
                $this->build_search_terms( $keyword, '' ),
            ];
            foreach ( $searches as $search ) {
                $url = $this->search_pexels( $search, $pexels_key, $index );
                if ( $url && ! in_array( $url, $this->used_urls, true ) ) {
                    $this->used_urls[] = $url;
                    return $url;
                }
            }
        }

        // Fallback to Picsum — use different seeds to avoid duplicates
        $seed = abs( crc32( $keyword . $heading . $index . 'unique' ) ) % 10000;
        $url = 'https://picsum.photos/seed/' . $seed . '/' . self::IMAGE_WIDTH . '/' . self::IMAGE_HEIGHT . '.jpg';
        $this->used_urls[] = $url;
        return $url;
    }

    /**
     * Search Pexels for a relevant image.
     */
    private function search_pexels( string $query, string $api_key, int $index ): string {
        // Cache results per query to avoid hitting API for every section
        $cache_key = 'seobetter_pexels_' . md5( $query );
        $cached = get_transient( $cache_key );

        if ( $cached === false ) {
            $response = wp_remote_get(
                'https://api.pexels.com/v1/search?' . http_build_query( [
                    'query'       => $query,
                    'per_page'    => 10,
                    'orientation' => 'landscape',
                ] ),
                [
                    'timeout' => 6,
                    'headers' => [ 'Authorization' => $api_key ],
                ]
            );

            if ( is_wp_error( $response ) ) {
                return '';
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $photos = $body['photos'] ?? [];

            // Store just the URLs
            $urls = [];
            foreach ( $photos as $p ) {
                $urls[] = $p['src']['landscape'] ?? $p['src']['large'] ?? '';
            }
            $urls = array_filter( $urls );

            set_transient( $cache_key, $urls, 6 * HOUR_IN_SECONDS );
            $cached = $urls;
        }

        if ( empty( $cached ) ) {
            return '';
        }

        // Find first unused image from results
        foreach ( $cached as $url ) {
            if ( ! in_array( $url, $this->used_urls, true ) ) {
                return $url;
            }
        }

        // All used — return empty so caller tries a different search term
        return '';
    }

    /**
     * Build search terms for image lookup.
     */
    private function build_search_terms( string $keyword, string $heading ): string {
        // Extract meaningful words, remove stop words
        $stop_words = [ 'what', 'how', 'why', 'when', 'where', 'which', 'who', 'are', 'is', 'the', 'a', 'an', 'to', 'for', 'of', 'in', 'on', 'with', 'and', 'or', 'do', 'does', 'can', 'should', 'best', 'top', 'most' ];

        $words = explode( ' ', strtolower( $keyword . ' ' . $heading ) );
        $words = array_filter( $words, fn( $w ) => ! in_array( $w, $stop_words, true ) && strlen( $w ) > 2 );
        $words = array_unique( $words );

        // Take first 3-4 meaningful words
        return implode( ' ', array_slice( $words, 0, 4 ) );
    }

    /**
     * Convert markdown image syntax to Gutenberg block.
     * Called by Content_Formatter when in Gutenberg mode.
     */
    public static function markdown_image_to_gutenberg( string $alt, string $url ): string {
        $esc_alt = esc_attr( $alt );
        $esc_url = esc_url( $url );

        return "<!-- wp:image {\"align\":\"center\",\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image aligncenter size-large\"><img src=\"{$esc_url}\" alt=\"{$esc_alt}\"/></figure>\n<!-- /wp:image -->";
    }

    /**
     * Convert markdown image syntax to Classic HTML.
     * Called by Content_Formatter when in Classic mode.
     */
    public static function markdown_image_to_classic( string $alt, string $url ): string {
        $w = self::IMAGE_WIDTH;
        $h = self::IMAGE_HEIGHT;

        return '<figure style="margin:1.5em 0;text-align:center"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" width="' . $w . '" height="' . $h . '" loading="lazy" decoding="async" style="max-width:100%;height:auto;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1)" /></figure>';
    }
}
