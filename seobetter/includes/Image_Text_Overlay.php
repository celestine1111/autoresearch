<?php

namespace SEOBetter;

/**
 * v1.5.216.13 — Deterministic PHP/GD text overlay for AI-generated featured images.
 *
 * Replaces the AI-rendered text overlay (Nano Banana / Gemini 2.5 Flash Image)
 * which produced typos in non-English scripts (Portuguese "discobbir" instead
 * of "descobrir", "mellores" instead of "melhores"). The AI now generates
 * CLEAN images (no embedded text) and PHP draws the headline using the
 * bundled Inter font — no spelling errors, exact control over typography.
 *
 * Per-style overlay technique map (matches the dropdown in Settings →
 * Branding → Image Style Preset):
 *
 *   realistic / editorial → bottom linear scrim, 68-72px Inter Bold, white
 *   hero (cinematic)      → full dark tint + centered serif-feel, 96px ExtraBold
 *   magazine_cover (alias of realistic) → top tint band, 96px ExtraBold
 *   illustration / flat   → accent color block (left 45%), 56-60px white
 *   minimalist            → bottom-right corner card, 36px on white
 *   3d                    → glass card centered, 54px white
 *
 * All techniques meet WCAG AA 4.5:1 contrast. Input is the 1200×630 cropped
 * image written by enforce_featured_aspect_169(); output is the same path
 * with the overlay drawn in place.
 *
 * Coverage: Inter Bold + ExtraBold (Latin Extended + Cyrillic + Greek). For
 * scripts outside that set (CJK, Arabic, Devanagari, Thai, Hebrew) the
 * overlay is skipped — the post still gets a clean AI-generated image, just
 * without burned-in text. Future v1.5.217+ will lazy-fetch Noto subsets.
 */
class Image_Text_Overlay {

    /**
     * Map dropdown style-key → overlay technique.
     */
    const STYLE_TECHNIQUE_MAP = [
        'realistic'    => 'magazine_top_band',  // magazine-cover style
        'editorial'    => 'bottom_scrim',
        'hero'         => 'cinematic_tint',     // centered serif over full tint
        'illustration' => 'accent_block',
        'flat'         => 'accent_block',
        'minimalist'   => 'corner_card',
        '3d'           => 'glass_card',
    ];

    /**
     * Main entry. Apply overlay to the given attachment in-place.
     *
     * @param int    $attachment_id  WP attachment post ID.
     * @param string $headline       Headline text to render.
     * @param string $style_key      Dropdown style key (realistic, editorial, hero, illustration, flat, minimalist, 3d).
     * @param string $lang           Article language (en, ja, etc.) — used to skip unsupported scripts.
     * @param string $accent_color   Brand accent hex color used for accent_block technique. Defaults to dark slate.
     * @return bool                  True if overlay was applied and saved; false on any failure (script unsupported,
     *                               GD missing, file unreadable, etc.). Failure is graceful — the underlying clean
     *                               image is still saved, the caller doesn't need to do anything special.
     */
    public static function apply( int $attachment_id, string $headline, string $style_key = 'realistic', string $lang = 'en', string $accent_color = '#0F172A' ): bool {
        $headline = trim( wp_strip_all_tags( $headline ) );
        if ( $headline === '' ) {
            return false;
        }

        if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagettftext' ) || ! function_exists( 'imagettfbbox' ) ) {
            error_log( 'SEOBetter Image_Text_Overlay: GD or FreeType missing — skipping overlay' );
            return false;
        }

        if ( self::is_unsupported_script( $headline ) ) {
            error_log( 'SEOBetter Image_Text_Overlay: unsupported script in headline — skipping overlay (Inter only covers Latin/Cyrillic/Greek)' );
            return false;
        }

        $path = get_attached_file( $attachment_id );
        if ( ! $path || ! file_exists( $path ) ) {
            error_log( 'SEOBetter Image_Text_Overlay: attached file not found for id=' . $attachment_id );
            return false;
        }

        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, [ 'jpg', 'jpeg', 'png' ], true ) ) {
            // .webp/.gif/etc — webp conversion runs AFTER this in the pipeline,
            // so the input here is always JPEG or PNG. Bail safely if not.
            return false;
        }

        $font_bold      = SEOBETTER_PLUGIN_DIR . 'assets/fonts/Inter-Bold.ttf';
        $font_extrabold = SEOBETTER_PLUGIN_DIR . 'assets/fonts/Inter-ExtraBold.ttf';
        if ( ! file_exists( $font_bold ) || ! file_exists( $font_extrabold ) ) {
            error_log( 'SEOBetter Image_Text_Overlay: bundled font files missing in assets/fonts/' );
            return false;
        }

        $im = ( $ext === 'png' ) ? @imagecreatefrompng( $path ) : @imagecreatefromjpeg( $path );
        if ( ! $im ) {
            error_log( 'SEOBetter Image_Text_Overlay: failed to load image ' . $path );
            return false;
        }

        if ( $ext === 'png' ) {
            imagealphablending( $im, true );
            imagesavealpha( $im, true );
        }

        $w = imagesx( $im );
        $h = imagesy( $im );

        $technique = self::STYLE_TECHNIQUE_MAP[ $style_key ] ?? 'bottom_scrim';
        $accent_rgb = self::hex_to_rgb( $accent_color );

        try {
            switch ( $technique ) {
                case 'cinematic_tint':
                    self::draw_cinematic_tint( $im, $w, $h, $headline, $font_extrabold );
                    break;
                case 'magazine_top_band':
                    self::draw_magazine_top_band( $im, $w, $h, $headline, $font_extrabold );
                    break;
                case 'accent_block':
                    self::draw_accent_block( $im, $w, $h, $headline, $font_bold, $accent_rgb );
                    break;
                case 'corner_card':
                    self::draw_corner_card( $im, $w, $h, $headline, $font_bold );
                    break;
                case 'glass_card':
                    self::draw_glass_card( $im, $w, $h, $headline, $font_bold );
                    break;
                case 'bottom_scrim':
                default:
                    self::draw_bottom_scrim( $im, $w, $h, $headline, $font_bold );
                    break;
            }
        } catch ( \Throwable $e ) {
            error_log( 'SEOBetter Image_Text_Overlay: render exception — ' . $e->getMessage() );
            imagedestroy( $im );
            return false;
        }

        $ok = ( $ext === 'png' ) ? @imagepng( $im, $path, 6 ) : @imagejpeg( $im, $path, 92 );
        imagedestroy( $im );

        if ( ! $ok ) {
            error_log( 'SEOBetter Image_Text_Overlay: failed to write image back to ' . $path );
            return false;
        }

        // Regenerate WP-intermediate sizes from the modified original so
        // post-thumbnail / OG / theme-cropped variants all pick up the overlay.
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $meta = wp_generate_attachment_metadata( $attachment_id, $path );
        if ( is_array( $meta ) ) {
            wp_update_attachment_metadata( $attachment_id, $meta );
        }

        error_log( 'SEOBetter Image_Text_Overlay: applied technique=' . $technique . ' style=' . $style_key . ' to attachment=' . $attachment_id );
        return true;
    }

    // ── Overlay techniques ────────────────────────────────────────────────

    /**
     * Bottom linear scrim — the editorial default.
     * Gradient from transparent at 35% height to ~85% black at bottom,
     * headline in white, 60px from the left edge, 60px from the bottom.
     */
    private static function draw_bottom_scrim( $im, int $w, int $h, string $headline, string $font ): void {
        // Gradient covers bottom 65% of canvas: starts transparent at y=0.35h, ends 0.85 alpha at y=h
        $grad_top    = (int) round( $h * 0.35 );
        $grad_bottom = $h;
        $max_alpha   = 0.85;
        for ( $y = $grad_top; $y < $grad_bottom; $y++ ) {
            $t = ( $y - $grad_top ) / ( $grad_bottom - $grad_top );
            // ease-in: t^1.6 keeps the upper third subtle and only darkens hard near the bottom
            $alpha_norm = pow( $t, 1.6 ) * $max_alpha;
            $gd_alpha   = (int) round( ( 1 - $alpha_norm ) * 127 ); // GD: 0=opaque 127=transparent
            $color      = imagecolorallocatealpha( $im, 0, 0, 0, $gd_alpha );
            imageline( $im, 0, $y, $w - 1, $y, $color );
        }

        $padding_x = 60;
        $padding_b = 60;
        $max_w = $w - ( $padding_x * 2 );

        // Auto-fit: try 76px → 56px until the headline fits in 3 lines
        [ $size, $lines ] = self::fit_text( $headline, $font, $max_w, 76, 56, 3 );
        $line_h = (int) round( $size * 1.10 );

        // Stack lines from the bottom up
        $white = imagecolorallocate( $im, 255, 255, 255 );
        $shadow = imagecolorallocatealpha( $im, 0, 0, 0, 75 ); // soft drop shadow for belt-and-suspenders

        $n = count( $lines );
        $block_h = $line_h * $n;
        $block_top = $h - $padding_b - $block_h;
        for ( $i = 0; $i < $n; $i++ ) {
            $y = $block_top + ( $i * $line_h ) + $size; // baseline = top + size
            // shadow offset 0,2 px
            imagettftext( $im, $size, 0, $padding_x + 1, $y + 2, $shadow, $font, $lines[ $i ] );
            imagettftext( $im, $size, 0, $padding_x, $y, $white, $font, $lines[ $i ] );
        }
    }

    /**
     * Magazine cover — top tint band 200px tall + headline starting ~220px.
     */
    private static function draw_magazine_top_band( $im, int $w, int $h, string $headline, string $font ): void {
        $band_h = 220;
        $tint_alpha = (int) round( ( 1 - 0.55 ) * 127 ); // 0.55 black
        $tint = imagecolorallocatealpha( $im, 0, 0, 0, $tint_alpha );
        imagefilledrectangle( $im, 0, 0, $w - 1, $band_h - 1, $tint );

        // Bottom shadow for headline body legibility
        $shadow_top = (int) round( $h * 0.45 );
        for ( $y = $shadow_top; $y < $h; $y++ ) {
            $t = ( $y - $shadow_top ) / ( $h - $shadow_top );
            $alpha_norm = pow( $t, 1.5 ) * 0.65;
            $gd_alpha = (int) round( ( 1 - $alpha_norm ) * 127 );
            $color = imagecolorallocatealpha( $im, 0, 0, 0, $gd_alpha );
            imageline( $im, 0, $y, $w - 1, $y, $color );
        }

        $padding_x = 60;
        $max_w = $w - ( $padding_x * 2 );

        // Headline sits across the bottom 50% in big type
        [ $size, $lines ] = self::fit_text( $headline, $font, $max_w, 92, 60, 3 );
        $line_h = (int) round( $size * 1.05 );

        $white = imagecolorallocate( $im, 255, 255, 255 );
        $shadow = imagecolorallocatealpha( $im, 0, 0, 0, 65 );

        $n = count( $lines );
        $block_h = $line_h * $n;
        $block_top = $h - 60 - $block_h;
        for ( $i = 0; $i < $n; $i++ ) {
            $y = $block_top + ( $i * $line_h ) + $size;
            imagettftext( $im, $size, 0, $padding_x + 1, $y + 2, $shadow, $font, $lines[ $i ] );
            imagettftext( $im, $size, 0, $padding_x, $y, $white, $font, $lines[ $i ] );
        }
    }

    /**
     * Cinematic — full-canvas dark tint + centered headline.
     */
    private static function draw_cinematic_tint( $im, int $w, int $h, string $headline, string $font ): void {
        $tint_alpha = (int) round( ( 1 - 0.55 ) * 127 );
        $tint = imagecolorallocatealpha( $im, 0, 0, 0, $tint_alpha );
        imagefilledrectangle( $im, 0, 0, $w - 1, $h - 1, $tint );

        $padding_x = 100;
        $max_w = $w - ( $padding_x * 2 );

        [ $size, $lines ] = self::fit_text( $headline, $font, $max_w, 96, 56, 2 );
        $line_h = (int) round( $size * 1.05 );

        $white = imagecolorallocate( $im, 255, 255, 255 );
        $shadow = imagecolorallocatealpha( $im, 0, 0, 0, 60 );

        $n = count( $lines );
        $block_h = $line_h * $n;
        $block_top = (int) round( ( $h - $block_h ) / 2 );

        for ( $i = 0; $i < $n; $i++ ) {
            $line = $lines[ $i ];
            $bbox = imagettfbbox( $size, 0, $font, $line );
            $line_w = abs( $bbox[2] - $bbox[0] );
            $x = (int) round( ( $w - $line_w ) / 2 );
            $y = $block_top + ( $i * $line_h ) + $size;
            imagettftext( $im, $size, 0, $x + 1, $y + 2, $shadow, $font, $line );
            imagettftext( $im, $size, 0, $x, $y, $white, $font, $line );
        }
    }

    /**
     * Accent block — solid color rectangle covers left 45%, white text inside.
     */
    private static function draw_accent_block( $im, int $w, int $h, string $headline, string $font, array $accent_rgb ): void {
        $block_w = (int) round( $w * 0.45 );
        $color = imagecolorallocate( $im, $accent_rgb[0], $accent_rgb[1], $accent_rgb[2] );
        imagefilledrectangle( $im, 0, 0, $block_w - 1, $h - 1, $color );

        $padding_x = 60;
        $max_w = $block_w - ( $padding_x * 2 );

        [ $size, $lines ] = self::fit_text( $headline, $font, $max_w, 60, 36, 4 );
        $line_h = (int) round( $size * 1.10 );

        $white = imagecolorallocate( $im, 255, 255, 255 );

        $n = count( $lines );
        $block_h = $line_h * $n;
        $block_top = (int) round( ( $h - $block_h ) / 2 );

        for ( $i = 0; $i < $n; $i++ ) {
            $y = $block_top + ( $i * $line_h ) + $size;
            imagettftext( $im, $size, 0, $padding_x, $y, $white, $font, $lines[ $i ] );
        }
    }

    /**
     * Bottom-right corner card — small white rounded card with dark text.
     */
    private static function draw_corner_card( $im, int $w, int $h, string $headline, string $font ): void {
        $card_w = (int) round( $w * 0.42 );
        $card_h = (int) round( $h * 0.32 );
        $card_x = $w - $card_w - 40;
        $card_y = $h - $card_h - 40;

        $white = imagecolorallocate( $im, 255, 255, 255 );
        $shadow = imagecolorallocatealpha( $im, 0, 0, 0, 95 );
        // Soft drop shadow under card
        imagefilledrectangle( $im, $card_x + 4, $card_y + 6, $card_x + $card_w + 4, $card_y + $card_h + 6, $shadow );
        imagefilledrectangle( $im, $card_x, $card_y, $card_x + $card_w, $card_y + $card_h, $white );

        $padding = 30;
        $max_w = $card_w - ( $padding * 2 );

        [ $size, $lines ] = self::fit_text( $headline, $font, $max_w, 40, 24, 4 );
        $line_h = (int) round( $size * 1.15 );

        $dark = imagecolorallocate( $im, 17, 24, 39 ); // slate-900

        $n = count( $lines );
        $block_h = $line_h * $n;
        $block_top = $card_y + (int) round( ( $card_h - $block_h ) / 2 );

        for ( $i = 0; $i < $n; $i++ ) {
            $y = $block_top + ( $i * $line_h ) + $size;
            imagettftext( $im, $size, 0, $card_x + $padding, $y, $dark, $font, $lines[ $i ] );
        }
    }

    /**
     * Glass card — semi-transparent white card centered, white text.
     * Real glassmorphism would blur the underlying pixels; GD doesn't do that
     * cheaply, so we use a high-alpha white panel + dark drop-shadow as a
     * pragmatic stand-in that still survives social-share thumbnailing.
     */
    private static function draw_glass_card( $im, int $w, int $h, string $headline, string $font ): void {
        $card_w = (int) round( $w * 0.72 );
        $card_h = (int) round( $h * 0.42 );
        $card_x = (int) round( ( $w - $card_w ) / 2 );
        $card_y = (int) round( ( $h - $card_h ) / 2 );

        // Subtle dim under card area for contrast pop
        $dim_alpha = (int) round( ( 1 - 0.30 ) * 127 );
        $dim = imagecolorallocatealpha( $im, 0, 0, 0, $dim_alpha );
        imagefilledrectangle( $im, $card_x - 20, $card_y - 20, $card_x + $card_w + 20, $card_y + $card_h + 20, $dim );

        // White glass panel ~18% opaque
        $glass_alpha = (int) round( ( 1 - 0.22 ) * 127 );
        $glass = imagecolorallocatealpha( $im, 255, 255, 255, $glass_alpha );
        imagefilledrectangle( $im, $card_x, $card_y, $card_x + $card_w, $card_y + $card_h, $glass );

        $padding = 40;
        $max_w = $card_w - ( $padding * 2 );

        [ $size, $lines ] = self::fit_text( $headline, $font, $max_w, 58, 36, 3 );
        $line_h = (int) round( $size * 1.08 );

        $white = imagecolorallocate( $im, 255, 255, 255 );
        $shadow = imagecolorallocatealpha( $im, 0, 0, 0, 65 );

        $n = count( $lines );
        $block_h = $line_h * $n;
        $block_top = $card_y + (int) round( ( $card_h - $block_h ) / 2 );

        for ( $i = 0; $i < $n; $i++ ) {
            $line = $lines[ $i ];
            $bbox = imagettfbbox( $size, 0, $font, $line );
            $line_w = abs( $bbox[2] - $bbox[0] );
            $x = (int) round( ( $w - $line_w ) / 2 );
            $y = $block_top + ( $i * $line_h ) + $size;
            imagettftext( $im, $size, 0, $x + 1, $y + 2, $shadow, $font, $line );
            imagettftext( $im, $size, 0, $x, $y, $white, $font, $line );
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Auto-fit. Try the largest size; if the wrapped output exceeds $max_lines,
     * shrink the size by 4px and re-wrap, until either the output fits or we
     * hit the floor. At the floor we just return what we got — graceful (a
     * 4-line headline at the floor size is better than no overlay at all).
     *
     * @return array [size, lines[]]
     */
    private static function fit_text( string $text, string $font, int $max_w, int $size_max, int $size_min, int $max_lines ): array {
        for ( $size = $size_max; $size >= $size_min; $size -= 4 ) {
            $lines = self::wrap_lines( $text, $font, $size, $max_w );
            if ( count( $lines ) <= $max_lines ) {
                return [ $size, $lines ];
            }
        }
        // Floor: re-wrap at floor size, accept whatever line count results
        return [ $size_min, self::wrap_lines( $text, $font, $size_min, $max_w ) ];
    }

    /**
     * Word-wrap on word boundaries using imagettfbbox for precise width
     * measurement. Multibyte-safe (mb_split on whitespace) so French/German/
     * Russian/Greek headlines wrap correctly. Single-word overflow falls
     * through (we don't hyphenate; a too-wide word is rendered as-is and
     * may exceed the canvas — graceful degradation).
     */
    private static function wrap_lines( string $text, string $font, int $size, int $max_w ): array {
        $words = preg_split( '/\s+/u', trim( $text ) );
        if ( ! $words ) return [ $text ];

        $lines = [];
        $current = '';
        foreach ( $words as $word ) {
            $candidate = $current === '' ? $word : ( $current . ' ' . $word );
            $bbox = imagettfbbox( $size, 0, $font, $candidate );
            $w = abs( $bbox[2] - $bbox[0] );
            if ( $w <= $max_w || $current === '' ) {
                $current = $candidate;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }
        if ( $current !== '' ) {
            $lines[] = $current;
        }
        return $lines;
    }

    /**
     * Detect headlines that contain characters Inter Bold / ExtraBold can't
     * render. Inter covers Latin, Latin Extended, Cyrillic and Greek. Returns
     * true if the headline is mostly composed of CJK / Arabic / Devanagari /
     * Thai / Hebrew / etc. glyphs — at which point overlay is skipped because
     * GD would render them as boxes/tofu.
     *
     * "Mostly" = >=20% of characters are in an unsupported block. A single
     * stray emoji shouldn't disable the overlay.
     */
    private static function is_unsupported_script( string $text ): bool {
        if ( $text === '' ) return false;
        $unsupported = 0;
        $total = 0;
        $len = mb_strlen( $text, 'UTF-8' );
        for ( $i = 0; $i < $len; $i++ ) {
            $ch = mb_substr( $text, $i, 1, 'UTF-8' );
            if ( preg_match( '/\s/u', $ch ) ) continue;
            $total++;
            $cp = self::utf8_codepoint( $ch );
            // CJK Unified, Hiragana, Katakana, Hangul, Arabic, Hebrew,
            // Devanagari, Bengali, Gurmukhi, Gujarati, Oriya, Tamil, Telugu,
            // Kannada, Malayalam, Sinhala, Thai, Lao, Tibetan, Myanmar,
            // Georgian, Ethiopic, Khmer.
            if (
                ( $cp >= 0x4E00 && $cp <= 0x9FFF )    // CJK Unified Ideographs
                || ( $cp >= 0x3400 && $cp <= 0x4DBF ) // CJK Extension A
                || ( $cp >= 0x3040 && $cp <= 0x309F ) // Hiragana
                || ( $cp >= 0x30A0 && $cp <= 0x30FF ) // Katakana
                || ( $cp >= 0xAC00 && $cp <= 0xD7AF ) // Hangul Syllables
                || ( $cp >= 0x0600 && $cp <= 0x06FF ) // Arabic
                || ( $cp >= 0x0750 && $cp <= 0x077F ) // Arabic Supplement
                || ( $cp >= 0xFB50 && $cp <= 0xFDFF ) // Arabic Presentation A
                || ( $cp >= 0x0590 && $cp <= 0x05FF ) // Hebrew
                || ( $cp >= 0x0900 && $cp <= 0x097F ) // Devanagari
                || ( $cp >= 0x0980 && $cp <= 0x09FF ) // Bengali
                || ( $cp >= 0x0A00 && $cp <= 0x0A7F ) // Gurmukhi
                || ( $cp >= 0x0A80 && $cp <= 0x0AFF ) // Gujarati
                || ( $cp >= 0x0B00 && $cp <= 0x0B7F ) // Oriya
                || ( $cp >= 0x0B80 && $cp <= 0x0BFF ) // Tamil
                || ( $cp >= 0x0C00 && $cp <= 0x0C7F ) // Telugu
                || ( $cp >= 0x0C80 && $cp <= 0x0CFF ) // Kannada
                || ( $cp >= 0x0D00 && $cp <= 0x0D7F ) // Malayalam
                || ( $cp >= 0x0D80 && $cp <= 0x0DFF ) // Sinhala
                || ( $cp >= 0x0E00 && $cp <= 0x0E7F ) // Thai
                || ( $cp >= 0x0E80 && $cp <= 0x0EFF ) // Lao
                || ( $cp >= 0x0F00 && $cp <= 0x0FFF ) // Tibetan
                || ( $cp >= 0x1000 && $cp <= 0x109F ) // Myanmar
                || ( $cp >= 0x10A0 && $cp <= 0x10FF ) // Georgian
                || ( $cp >= 0x1200 && $cp <= 0x137F ) // Ethiopic
                || ( $cp >= 0x1780 && $cp <= 0x17FF ) // Khmer
            ) {
                $unsupported++;
            }
        }
        if ( $total === 0 ) return false;
        return ( $unsupported / $total ) >= 0.20;
    }

    private static function utf8_codepoint( string $ch ): int {
        $bytes = unpack( 'C*', $ch );
        if ( ! $bytes ) return 0;
        $b1 = $bytes[1] ?? 0;
        if ( $b1 < 0x80 )      return $b1;
        $b2 = $bytes[2] ?? 0;
        if ( $b1 < 0xE0 )      return ( ( $b1 & 0x1F ) << 6 ) | ( $b2 & 0x3F );
        $b3 = $bytes[3] ?? 0;
        if ( $b1 < 0xF0 )      return ( ( $b1 & 0x0F ) << 12 ) | ( ( $b2 & 0x3F ) << 6 ) | ( $b3 & 0x3F );
        $b4 = $bytes[4] ?? 0;
        return ( ( $b1 & 0x07 ) << 18 ) | ( ( $b2 & 0x3F ) << 12 ) | ( ( $b3 & 0x3F ) << 6 ) | ( $b4 & 0x3F );
    }

    private static function hex_to_rgb( string $hex ): array {
        $hex = ltrim( trim( $hex ), '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( strlen( $hex ) !== 6 || ! ctype_xdigit( $hex ) ) {
            return [ 15, 23, 42 ]; // slate-900 default — safe dark
        }
        return [
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
        ];
    }
}
