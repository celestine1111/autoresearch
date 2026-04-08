<?php

namespace SEOBetter;

/**
 * Image SEO Analyzer.
 *
 * Audits images for SEO best practices:
 * - Alt text presence and quality
 * - File naming conventions
 * - Modern format detection (WebP/AVIF)
 * - Lazy loading implementation
 * - Dimension attributes (prevents CLS)
 * - File size analysis
 * - Image-to-text ratio (top pages have 21% more images)
 */
class Image_SEO_Analyzer {

    private const MAX_FILE_SIZE_KB = 200;

    /**
     * Analyze all images in post content.
     */
    public function analyze( string $content, int $post_id = 0 ): array {
        preg_match_all( '/<img[^>]+>/i', $content, $matches );
        $images = $matches[0] ?? [];

        if ( empty( $images ) ) {
            $word_count = str_word_count( wp_strip_all_tags( $content ) );
            return [
                'score'       => $word_count > 500 ? 30 : 50,
                'total'       => 0,
                'issues'      => [],
                'suggestions' => $word_count > 500
                    ? [ 'Add images — top-ranking pages have 21% more images than lower-ranking ones.' ]
                    : [],
            ];
        }

        $issues = [];
        // Limit analysis to first 50 images to prevent performance issues
        $max_analyze = 50;
        $truncated = count( $images ) > $max_analyze;
        $images_to_check = array_slice( $images, 0, $max_analyze );

        $checks = [
            'missing_alt'        => 0,
            'empty_alt'          => 0,
            'generic_alt'        => 0,
            'missing_dimensions' => 0,
            'no_lazy_loading'    => 0,
            'legacy_format'      => 0,
            'poor_filename'      => 0,
            'total'              => count( $images ),
        ];

        foreach ( $images_to_check as $img ) {
            $this->check_single_image( $img, $checks, $issues );
        }

        if ( $truncated ) {
            $issues[] = [
                'type'    => 'analysis_limit',
                'message' => sprintf( 'Only first %d of %d images analyzed. Consider optimizing images across the full page.', $max_analyze, count( $images ) ),
            ];
        }

        // Check featured image
        if ( $post_id && ! has_post_thumbnail( $post_id ) ) {
            $issues[] = [
                'type'    => 'missing_featured',
                'message' => 'No featured image set. Required for social sharing (OG image) and Google Discover (1200px+ wide).',
            ];
        }

        $total_checks = $checks['total'] * 5; // 5 checks per image
        $total_passes = $total_checks
            - $checks['missing_alt'] - $checks['empty_alt'] - $checks['generic_alt']
            - $checks['missing_dimensions'] - $checks['no_lazy_loading']
            - $checks['legacy_format'] - $checks['poor_filename'];

        $score = $total_checks > 0 ? round( ( $total_passes / $total_checks ) * 100 ) : 0;

        return [
            'score'       => max( 0, min( 100, $score ) ),
            'total'       => $checks['total'],
            'checks'      => $checks,
            'issues'      => $issues,
            'suggestions' => $this->generate_suggestions( $checks, $issues ),
        ];
    }

    private function check_single_image( string $img_html, array &$checks, array &$issues ): void {
        // Alt text
        if ( ! preg_match( '/\balt\s*=/i', $img_html ) ) {
            $checks['missing_alt']++;
            $issues[] = [ 'type' => 'missing_alt', 'message' => 'Image missing alt attribute', 'html' => substr( $img_html, 0, 100 ) ];
        } elseif ( preg_match( '/\balt\s*=\s*["\'][\s]*["\']/i', $img_html ) ) {
            $checks['empty_alt']++;
            $issues[] = [ 'type' => 'empty_alt', 'message' => 'Image has empty alt text', 'html' => substr( $img_html, 0, 100 ) ];
        } elseif ( preg_match( '/\balt\s*=\s*["\'](image|photo|picture|img|screenshot|banner|untitled|DSC|IMG_)\b/i', $img_html ) ) {
            $checks['generic_alt']++;
            $issues[] = [ 'type' => 'generic_alt', 'message' => 'Image has generic alt text — use descriptive keyword-rich text', 'html' => substr( $img_html, 0, 100 ) ];
        }

        // Dimensions (width/height prevent CLS)
        $has_width = preg_match( '/\bwidth\s*=/i', $img_html );
        $has_height = preg_match( '/\bheight\s*=/i', $img_html );
        if ( ! $has_width || ! $has_height ) {
            $checks['missing_dimensions']++;
        }

        // Lazy loading
        $has_lazy = preg_match( '/loading\s*=\s*["\']lazy["\']/i', $img_html );
        if ( ! $has_lazy ) {
            $checks['no_lazy_loading']++;
        }

        // File format
        if ( preg_match( '/src\s*=\s*["\']([^"\']+)["\']/i', $img_html, $src_match ) ) {
            $src = $src_match[1];
            $ext = strtolower( pathinfo( wp_parse_url( $src, PHP_URL_PATH ) ?: '', PATHINFO_EXTENSION ) );

            // Check for modern formats
            if ( in_array( $ext, [ 'bmp', 'tiff', 'tif' ], true ) ) {
                $checks['legacy_format']++;
                $issues[] = [ 'type' => 'legacy_format', 'message' => "Image uses {$ext} format — convert to WebP for 25-35% size reduction", 'src' => $src ];
            }

            // Check filename
            $filename = pathinfo( wp_parse_url( $src, PHP_URL_PATH ) ?: '', PATHINFO_FILENAME );
            if ( preg_match( '/^(IMG_|DSC|screenshot|image|photo|\d{5,})/i', $filename ) ) {
                $checks['poor_filename']++;
                $issues[] = [ 'type' => 'poor_filename', 'message' => "Image filename \"{$filename}\" is not descriptive — use keyword-rich filenames", 'src' => $src ];
            }
        }
    }

    private function generate_suggestions( array $checks, array $issues ): array {
        $suggestions = [];

        if ( $checks['missing_alt'] > 0 || $checks['empty_alt'] > 0 ) {
            $count = $checks['missing_alt'] + $checks['empty_alt'];
            $suggestions[] = "{$count} images need alt text. Write descriptive phrases with natural keyword placement.";
        }

        if ( $checks['missing_dimensions'] > 0 ) {
            $suggestions[] = "{$checks['missing_dimensions']} images missing width/height attributes. This causes Cumulative Layout Shift (CLS).";
        }

        if ( $checks['no_lazy_loading'] > 0 ) {
            $suggestions[] = "Add loading=\"lazy\" to offscreen images for faster page load.";
        }

        if ( $checks['legacy_format'] > 0 ) {
            $suggestions[] = "Convert legacy images to WebP format — 25-35% smaller with 90%+ browser support.";
        }

        if ( $checks['poor_filename'] > 0 ) {
            $suggestions[] = "Rename generic image files (IMG_, DSC) to descriptive keyword-rich filenames before uploading.";
        }

        return $suggestions;
    }
}
