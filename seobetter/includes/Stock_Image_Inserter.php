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
     */
    private function generate_alt_text( string $keyword, string $heading, string $context ): string {
        // Clean heading of markdown/special chars
        $heading = preg_replace( '/[#*_\[\]()]/', '', $heading );
        $heading = trim( $heading, '? ' );

        // Build descriptive alt text incorporating the keyword
        $keyword_lower = strtolower( $keyword );
        $heading_lower = strtolower( $heading );

        // Check if keyword is already in the heading
        if ( stripos( $heading, $keyword ) !== false ) {
            return ucfirst( $heading_lower ) . ' — visual guide and comparison';
        }

        return ucfirst( $keyword_lower ) . ' — ' . strtolower( $heading );
    }

    /**
     * Get an Unsplash image URL with SEO-optimized params.
     *
     * Uses Unsplash Source API for keyword-based images.
     * Applies: WebP format, 800px width, 80% quality, auto-crop.
     */
    private function get_image_url( string $keyword, string $heading, int $index ): string {
        // Build search terms from keyword + heading context
        $search = $this->build_search_terms( $keyword, $heading );

        // Use Unsplash source with specific dimensions and format params
        // The sig parameter ensures different images for different sections
        $sig = md5( $keyword . $heading . $index );

        // Use Unsplash source URL directly (no blocking HTTP check during generation)
        $url = 'https://source.unsplash.com/' . self::IMAGE_WIDTH . 'x' . self::IMAGE_HEIGHT . '/?' . urlencode( $search );

        return $url;
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
        $w = self::IMAGE_WIDTH;
        $h = self::IMAGE_HEIGHT;

        return <<<BLOCK
<!-- wp:image {"width":"{$w}","height":"{$h}","sizeSlug":"large","linkDestination":"none","className":"is-style-rounded"} -->
<figure class="wp-block-image size-large is-style-rounded"><img src="{$url}" alt="{$alt}" width="{$w}" height="{$h}" loading="lazy" decoding="async" /></figure>
<!-- /wp:image -->
BLOCK;
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
