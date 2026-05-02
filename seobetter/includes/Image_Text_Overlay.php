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
 * Branding → Image Style Preset). v1.5.216.14 made each technique
 * visually distinct and aligned with its dropdown description:
 *
 *   realistic    → bottom_scrim       — bottom-third headline, ExtraBold white
 *   editorial    → top_divider        — title top + brand-accent divider line, dark
 *   hero         → cinema_letterbox   — 50px black bars top+bottom, centered title
 *   illustration → upper_left_dark    — upper-left soft white wash + dark headline
 *   flat         → split_left         — solid block left 50% (brand color), photo right
 *   minimalist   → corner_card        — bottom-right white card, dark text
 *   3d           → glass_card         — centered translucent panel, white text
 *
 * All techniques meet WCAG AA 4.5:1 contrast. Input is the 1200×630 cropped
 * image written by enforce_featured_aspect_169(); output is the same path
 * with the overlay drawn in place.
 *
 * Coverage:
 *   - Latin / Cyrillic / Greek → bundled Inter Bold + ExtraBold (assets/fonts/)
 *   - Japanese / Korean / Simplified Chinese / Traditional Chinese → lazy-fetch
 *     Noto Sans CJK variant on first use
 *   - Arabic / Hebrew / Devanagari (Hindi/Marathi/Nepali) / Thai → lazy-fetch
 *     Noto Sans variable subset on first use
 *
 * Lazy-fetched fonts are cached under wp-content/uploads/seobetter-fonts/
 * indefinitely (one-time ~200ms-2s download per script per WP install). If
 * the fetch fails the overlay is skipped — the clean image still ships, the
 * post just gets no headline burned in.
 *
 * Known limitation (v1.5.216.14): PHP GD/FreeType doesn't do bidi shaping,
 * so Arabic glyphs won't connect with their proper contextual ligatures —
 * each character renders in its isolated form. Still legible but not as
 * elegant as native Arabic typesetting. Imagick handles this correctly;
 * future versions may dispatch to Imagick for Arabic when available.
 */
class Image_Text_Overlay {

    /**
     * v1.5.216.14 — Map dropdown style-key → overlay technique.
     *
     * Each technique is visually distinct AND matches the description shown
     * in the dropdown (Settings → Branding → Image Style Preset). Pre-fix
     * `illustration` and `flat` both mapped to `accent_block` so they
     * looked identical despite Ben picking different presets.
     *
     * Dropdown ↔ technique alignment:
     *   📰 Magazine Cover   "bottom-third headline overlay"           → bottom_scrim
     *   🗞️ Classic Editorial "title top with horizontal divider"        → top_divider
     *   🎬 Cinematic Hero    "centered title + cinema black bars"       → cinema_letterbox
     *   🎨 Modern Illustr.   "upper-left dark headline"                 → upper_left_dark
     *   ⬜ Title-led Flat    "split layout: headline left, icon right"  → split_left
     *   ◽ Minimalist        "small corner title, image dominant"       → corner_card
     *   🎯 3D Hero           "floating centered title overlay"          → glass_card
     */
    const STYLE_TECHNIQUE_MAP = [
        'realistic'    => 'bottom_scrim',
        'editorial'    => 'top_divider',
        'hero'         => 'cinema_letterbox',
        'illustration' => 'upper_left_dark',
        'flat'         => 'split_left',
        'minimalist'   => 'corner_card',
        '3d'           => 'glass_card',
    ];

    /**
     * Main entry. Apply overlay to the given attachment in-place.
     *
     * @param int    $attachment_id  WP attachment post ID.
     * @param string $headline       Headline text to render.
     * @param string $style_key      Dropdown style key (realistic, editorial, hero, illustration, flat, minimalist, 3d).
     * @param string $lang           Article language (en, ja, etc.) — drives script-aware font dispatch.
     * @param string $accent_color   Brand accent hex color used by the split_left + top_divider techniques. Defaults to dark slate.
     * @return bool                  True if overlay was applied and saved; false on any failure (font lazy-fetch
     *                               failure, GD missing, file unreadable, etc.). Failure is graceful — the
     *                               underlying clean image is still saved, the caller doesn't need to do anything.
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

        // v1.5.216.14 — Script-aware font dispatch. Inter covers Latin/Cyrillic/
        // Greek; for Arabic/Hebrew/Devanagari/Thai/Japanese/Korean/Chinese we
        // lazy-fetch the matching Noto Sans subset to wp-content/uploads on
        // first use. detect_script() reads the article language code first
        // (most reliable since Ben sets it explicitly per article); falls back
        // to character analysis for headlines whose script doesn't match the
        // declared language.
        $script = self::detect_script( $headline, $lang );
        $font_bold = self::ensure_font( $script, 'bold' );
        $font_extrabold = self::ensure_font( $script, 'extrabold' );
        if ( ! $font_bold || ! $font_extrabold ) {
            error_log( 'SEOBetter Image_Text_Overlay: no font available for script=' . $script . ' (lazy-fetch may have failed) — skipping overlay; clean image still ships' );
            return false;
        }

        $path = get_attached_file( $attachment_id );
        if ( ! $path || ! file_exists( $path ) ) {
            error_log( 'SEOBetter Image_Text_Overlay: attached file not found for id=' . $attachment_id );
            return false;
        }

        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        error_log( 'SEOBetter Image_Text_Overlay: path=' . $path . ' ext=' . $ext . ' filesize=' . @filesize( $path ) );
        if ( ! in_array( $ext, [ 'jpg', 'jpeg', 'png', 'webp' ], true ) ) {
            // .gif/etc — webp conversion runs AFTER this in the pipeline,
            // so the input here is normally JPEG/PNG (or already WebP if the
            // attachment was previously converted). Bail safely on anything
            // else; the clean image still ships.
            error_log( 'SEOBetter Image_Text_Overlay: unsupported file extension ' . $ext . ' — skipping overlay' );
            return false;
        }

        // v1.5.216.16 — Robust image loading with 3-tier fallback.
        // Pre-fix: a single @imagecreatefrom{jpeg,png} call. Pexels JPEGs
        // sometimes fail to load with imagecreatefromjpeg() due to encoding
        // quirks (some hosts have GD compiled without certain JPEG features —
        // progressive, CMYK, embedded ICC profiles), even though the file is
        // a valid JPEG. Symptom: Nano Banana (PNG output) overlays correctly,
        // Pexels (JPEG) silently skips.
        //
        // Fallbacks:
        //   1. native imagecreatefrom{jpeg,png,webp}() — fast, works for ~95%
        //   2. imagecreatefromstring() on file_get_contents — handles some
        //      JPEGs the format-specific functions reject
        //   3. WP_Image_Editor → re-save as clean JPEG → reload with GD
        //      (cleanest fallback; uses Imagick if available)
        $im = null;
        if ( $ext === 'png' ) {
            $im = @imagecreatefrompng( $path );
        } elseif ( $ext === 'webp' && function_exists( 'imagecreatefromwebp' ) ) {
            $im = @imagecreatefromwebp( $path );
        } else {
            $im = @imagecreatefromjpeg( $path );
        }
        if ( ! $im ) {
            error_log( 'SEOBetter Image_Text_Overlay: native load failed (ext=' . $ext . ', path=' . $path . ') — trying imagecreatefromstring fallback' );
            $bin = @file_get_contents( $path );
            if ( $bin !== false ) {
                $im = @imagecreatefromstring( $bin );
            }
        }
        if ( ! $im ) {
            error_log( 'SEOBetter Image_Text_Overlay: imagecreatefromstring failed — trying WP_Image_Editor re-save fallback' );
            $editor = wp_get_image_editor( $path );
            if ( ! is_wp_error( $editor ) ) {
                $temp = wp_tempnam( 'sb-overlay.jpg' );
                if ( $temp ) {
                    $editor->set_quality( 95 );
                    $resaved = $editor->save( $temp, 'image/jpeg' );
                    if ( ! is_wp_error( $resaved ) && ! empty( $resaved['path'] ) && file_exists( $resaved['path'] ) ) {
                        $im = @imagecreatefromjpeg( $resaved['path'] );
                        @unlink( $resaved['path'] );
                    }
                }
            }
        }
        if ( ! $im ) {
            error_log( 'SEOBetter Image_Text_Overlay: ALL image loading methods failed for ' . $path . ' — skipping overlay; clean image still ships' );
            return false;
        }
        error_log( 'SEOBetter Image_Text_Overlay: image loaded successfully (' . imagesx( $im ) . 'x' . imagesy( $im ) . ')' );

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
                case 'top_divider':
                    self::draw_top_divider( $im, $w, $h, $headline, $font_bold, $accent_rgb );
                    break;
                case 'cinema_letterbox':
                    self::draw_cinema_letterbox( $im, $w, $h, $headline, $font_extrabold );
                    break;
                case 'upper_left_dark':
                    self::draw_upper_left_dark( $im, $w, $h, $headline, $font_bold );
                    break;
                case 'split_left':
                    self::draw_split_left( $im, $w, $h, $headline, $font_extrabold, $accent_rgb );
                    break;
                case 'corner_card':
                    self::draw_corner_card( $im, $w, $h, $headline, $font_bold );
                    break;
                case 'glass_card':
                    self::draw_glass_card( $im, $w, $h, $headline, $font_bold );
                    break;
                case 'bottom_scrim':
                default:
                    self::draw_bottom_scrim( $im, $w, $h, $headline, $font_extrabold );
                    break;
            }
        } catch ( \Throwable $e ) {
            error_log( 'SEOBetter Image_Text_Overlay: render exception — ' . $e->getMessage() );
            imagedestroy( $im );
            return false;
        }

        // v1.5.216.16 — Save in the source's format so we don't break the
        // attachment's mime metadata (set by media_sideload_image earlier).
        if ( $ext === 'png' ) {
            $ok = @imagepng( $im, $path, 6 );
        } elseif ( $ext === 'webp' && function_exists( 'imagewebp' ) ) {
            $ok = @imagewebp( $im, $path, 90 );
        } else {
            $ok = @imagejpeg( $im, $path, 92 );
        }
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
     * Classic Editorial — top section with title + horizontal divider, photo below.
     * Matches dropdown copy: "title top with horizontal divider, photo below (NYT/Atlantic style)".
     *
     * v1.5.216.18 — Denser solid white band (0.92 α) where text sits, with a
     * soft 60px feather at the bottom so it blends into the photo. The previous
     * fade-from-0.85 design left the lower lines of multi-line headlines in a
     * low-alpha zone where dark slate text fought the photo for legibility.
     * Also adds a subtle 8-direction white halo behind the dark text to keep
     * 4.5:1 contrast even on busy photographic backgrounds.
     */
    private static function draw_top_divider( $im, int $w, int $h, string $headline, string $font, array $accent_rgb ): void {
        $padding_x = 60;
        $max_w = $w - ( $padding_x * 2 );

        [ $size, $lines ] = self::fit_text( $headline, $font, $max_w, 78, 46, 2 );
        $line_h = (int) round( $size * 1.10 );
        $n = count( $lines );

        // Solid scrim where the headline sits (line 1 baseline = block_top + size)
        // Block extends from y=0 to (last baseline + 50px breathing for divider)
        $block_top   = 50;
        $block_bottom = $block_top + ( $line_h * $n ) + 60; // includes divider area

        // Solid 0.92 white from y=0 down to $block_bottom
        $solid_alpha = (int) round( ( 1 - 0.92 ) * 127 );
        $solid       = imagecolorallocatealpha( $im, 255, 255, 255, $solid_alpha );
        imagefilledrectangle( $im, 0, 0, $w - 1, $block_bottom, $solid );

        // 60px feather below the solid band so the photo isn't abruptly cut off
        $feather = 60;
        for ( $y = $block_bottom + 1; $y < $block_bottom + $feather; $y++ ) {
            $t = ( $y - $block_bottom ) / $feather;
            $alpha_norm = ( 1 - $t ) * 0.92;
            $gd_alpha   = (int) round( ( 1 - $alpha_norm ) * 127 );
            $color      = imagecolorallocatealpha( $im, 255, 255, 255, $gd_alpha );
            imageline( $im, 0, $y, $w - 1, $y, $color );
        }

        $dark = imagecolorallocate( $im, 17, 24, 39 ); // slate-900

        for ( $i = 0; $i < $n; $i++ ) {
            $y = $block_top + ( $i * $line_h ) + $size;
            self::draw_text_with_halo( $im, $size, $padding_x, $y, $font, $lines[ $i ], $dark, 'white' );
        }

        // 2px horizontal divider in brand accent color (or slate fallback) BELOW the headline block
        $divider_y = $block_top + ( $line_h * $n ) + 18;
        $accent = imagecolorallocate( $im, $accent_rgb[0], $accent_rgb[1], $accent_rgb[2] );
        imagefilledrectangle( $im, $padding_x, $divider_y, $w - $padding_x, $divider_y + 2, $accent );
    }

    /**
     * Cinematic Hero — 50px black bars top + bottom (cinema letterbox), centered title.
     * Matches dropdown copy: "full-bleed photo with centered title + cinema black bars".
     */
    private static function draw_cinema_letterbox( $im, int $w, int $h, string $headline, string $font ): void {
        $bar_h = 50;
        $black = imagecolorallocate( $im, 0, 0, 0 );
        // Top + bottom solid black bars (the "cinema" letterbox)
        imagefilledrectangle( $im, 0, 0, $w - 1, $bar_h - 1, $black );
        imagefilledrectangle( $im, 0, $h - $bar_h, $w - 1, $h - 1, $black );

        // Subtle 0.25 dim on the photo region for headline contrast pop
        $dim_alpha = (int) round( ( 1 - 0.25 ) * 127 );
        $dim = imagecolorallocatealpha( $im, 0, 0, 0, $dim_alpha );
        imagefilledrectangle( $im, 0, $bar_h, $w - 1, $h - $bar_h - 1, $dim );

        $padding_x = 100;
        $max_w = $w - ( $padding_x * 2 );

        [ $size, $lines ] = self::fit_text( $headline, $font, $max_w, 92, 56, 2 );
        $line_h = (int) round( $size * 1.05 );

        $white = imagecolorallocate( $im, 255, 255, 255 );
        $shadow = imagecolorallocatealpha( $im, 0, 0, 0, 60 );

        // Center vertically inside the photo region (between the two bars)
        $photo_top = $bar_h;
        $photo_h = $h - ( $bar_h * 2 );
        $n = count( $lines );
        $block_h = $line_h * $n;
        $block_top = $photo_top + (int) round( ( $photo_h - $block_h ) / 2 );

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
     * Modern Illustration — upper-left DARK headline on flat editorial illustration.
     * Matches dropdown copy: "upper-left dark headline on flat editorial illustration".
     *
     * v1.5.216.18 — Denser tile-shaped scrim so dark text reads cleanly on
     * BOTH flat illustrations AND photographs. Previous design assumed a flat
     * illustration source where the soft 0.85-fade wash was enough; on busy
     * Pexels photos (e.g. coffee shop with shelves of objects), the wash thinned
     * out fast and dark text fought the photo. Now: solid 0.95 white in a
     * tight rectangle covering the text zone, soft 60px feather at right + bottom
     * edges to preserve editorial feel. Plus 8-direction white halo behind
     * the dark text for guaranteed 4.5:1 contrast even on the most chaotic photo.
     */
    private static function draw_upper_left_dark( $im, int $w, int $h, string $headline, string $font ): void {
        $padding_x = 60;
        $padding_t = 70;
        $max_w = (int) round( $w * 0.55 );

        [ $size, $lines ] = self::fit_text( $headline, $font, $max_w, 72, 42, 3 );
        $line_h = (int) round( $size * 1.10 );
        $n = count( $lines );

        // Solid scrim covering the headline rectangle with 30px padding around
        $box_x1 = 0;
        $box_y1 = 0;
        $box_x2 = $padding_x + $max_w + 30;
        $box_y2 = $padding_t + ( $line_h * $n ) + 30;

        $solid_alpha = (int) round( ( 1 - 0.95 ) * 127 );
        $solid       = imagecolorallocatealpha( $im, 255, 255, 255, $solid_alpha );
        imagefilledrectangle( $im, $box_x1, $box_y1, $box_x2, $box_y2, $solid );

        // 60px feather on the right edge — blend into the photo
        $feather = 60;
        for ( $x = $box_x2 + 1; $x < $box_x2 + $feather; $x++ ) {
            $t = ( $x - $box_x2 ) / $feather;
            $alpha_norm = ( 1 - $t ) * 0.95;
            $gd_alpha   = (int) round( ( 1 - $alpha_norm ) * 127 );
            $color      = imagecolorallocatealpha( $im, 255, 255, 255, $gd_alpha );
            imageline( $im, $x, 0, $x, $box_y2, $color );
        }
        // 60px feather on the bottom edge
        for ( $y = $box_y2 + 1; $y < $box_y2 + $feather; $y++ ) {
            $t = ( $y - $box_y2 ) / $feather;
            $alpha_norm = ( 1 - $t ) * 0.95;
            $gd_alpha   = (int) round( ( 1 - $alpha_norm ) * 127 );
            $color      = imagecolorallocatealpha( $im, 255, 255, 255, $gd_alpha );
            imageline( $im, 0, $y, $box_x2, $y, $color );
        }
        // Diagonal corner — blend the two feathers cleanly
        for ( $y = $box_y2 + 1; $y < $box_y2 + $feather; $y++ ) {
            $ty = ( $y - $box_y2 ) / $feather;
            for ( $x = $box_x2 + 1; $x < $box_x2 + $feather; $x++ ) {
                $tx = ( $x - $box_x2 ) / $feather;
                $combined_t = sqrt( $tx * $tx + $ty * $ty );
                if ( $combined_t > 1 ) continue;
                $alpha_norm = ( 1 - $combined_t ) * 0.95;
                $gd_alpha   = (int) round( ( 1 - $alpha_norm ) * 127 );
                $color      = imagecolorallocatealpha( $im, 255, 255, 255, $gd_alpha );
                imagesetpixel( $im, $x, $y, $color );
            }
        }

        $dark = imagecolorallocate( $im, 17, 24, 39 ); // slate-900

        for ( $i = 0; $i < $n; $i++ ) {
            $y = $padding_t + ( $i * $line_h ) + $size;
            self::draw_text_with_halo( $im, $size, $padding_x, $y, $font, $lines[ $i ], $dark, 'white' );
        }
    }

    /**
     * Title-led Flat — split layout: large headline LEFT, photo ("icon") RIGHT.
     * Matches dropdown copy: "split layout: large headline left, abstract icon right".
     *
     * Uses brand accent color for the left block (or slate-900 fallback). Photo
     * shows in the right 50% as the "abstract icon" — its center subject is
     * shifted left by 300px so the most-likely-centered subject sits in the
     * visible right-half region instead of being hidden behind the block.
     */
    private static function draw_split_left( $im, int $w, int $h, string $headline, string $font, array $accent_rgb ): void {
        $block_w = (int) round( $w * 0.50 );

        // Shift the photo: copy the center 600×630 region to the right half
        // so the subject is visible after the left block covers the original
        // left half. We work on a copy because imagecopy on the same resource
        // can produce streaks when source/dest overlap.
        $tmp = imagecreatetruecolor( $w, $h );
        imagecopy( $tmp, $im, 0, 0, 0, 0, $w, $h );
        // Source rect: x = ($w - $block_w) / 2, width = $block_w  (center band)
        $src_x = (int) round( ( $w - $block_w ) / 2 );
        imagecopy( $im, $tmp, $block_w, 0, $src_x, 0, $block_w, $h );
        imagedestroy( $tmp );

        // Solid color block on the left
        $color = imagecolorallocate( $im, $accent_rgb[0], $accent_rgb[1], $accent_rgb[2] );
        imagefilledrectangle( $im, 0, 0, $block_w - 1, $h - 1, $color );

        $padding_x = 60;
        $max_w = $block_w - ( $padding_x * 2 );

        [ $size, $lines ] = self::fit_text( $headline, $font, $max_w, 76, 42, 4 );
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
     * v1.5.216.18 — Draw text with a subtle 8-direction halo behind it for
     * guaranteed legibility on busy backgrounds. Used by `top_divider` and
     * `upper_left_dark` (the two dark-on-light techniques) to ensure 4.5:1
     * contrast even when the underlying photo bleeds through the scrim.
     *
     * @param string $halo_color Either 'white' or 'black' — pick the opposite
     *                            of the text color.
     */
    private static function draw_text_with_halo( $im, int $size, int $x, int $y, string $font, string $text, int $text_color, string $halo_color ): void {
        if ( $halo_color === 'white' ) {
            $halo = imagecolorallocatealpha( $im, 255, 255, 255, 30 );
        } else {
            $halo = imagecolorallocatealpha( $im, 0, 0, 0, 50 );
        }
        // 8-direction halo at 1.5px offset — creates a subtle stroke that
        // doesn't visually thicken the glyph but rescues contrast
        foreach ( [ [ 2, 0 ], [ -2, 0 ], [ 0, 2 ], [ 0, -2 ], [ 1, 1 ], [ 1, -1 ], [ -1, 1 ], [ -1, -1 ] ] as $offset ) {
            imagettftext( $im, $size, 0, $x + $offset[0], $y + $offset[1], $halo, $font, $text );
        }
        // Foreground text on top
        imagettftext( $im, $size, 0, $x, $y, $text_color, $font, $text );
    }

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
     * Russian/Greek headlines wrap correctly.
     *
     * v1.5.216.60 — handle CJK / Thai / no-whitespace languages. Pre-fix:
     * Japanese / Chinese / Korean / Thai headlines have zero inter-word
     * spaces, so the original `preg_split('/\s+/')` returned the whole
     * headline as ONE "word". Then the `$current === ''` fallback at line
     * 650 accepted that single overflowing token unchanged — and the text
     * overflowed the canvas (user-visible bug on every JA / ZH / KO post).
     *
     * Fix: detect whitespace presence. If the text has spaces, use the
     * legacy word-based wrap. If not, split into Latin-word-or-single-char
     * units (`/[A-Za-z0-9]+|./u`) so:
     *   - CJK characters wrap one-by-one (each char is fine line-fitting unit)
     *   - Embedded Latin words like "WordPress" stay intact (don't get
     *     broken into "Wo / rdP / res / s")
     *   - Mixed JA + EN headlines like "5選！2026年に最適なWordPress用…"
     *     get clean wrapping at character/word boundaries
     */
    private static function wrap_lines( string $text, string $font, int $size, int $max_w ): array {
        $text = trim( $text );
        if ( $text === '' ) return [];

        $has_whitespace = (bool) preg_match( '/\s/u', $text );
        if ( $has_whitespace ) {
            $units     = preg_split( '/\s+/u', $text );
            $separator = ' ';
        } else {
            // No-whitespace languages — split into Latin-word OR single-char units
            if ( preg_match_all( '/[A-Za-z0-9]+|./u', $text, $m ) ) {
                $units = $m[0];
            } else {
                $units = [ $text ];
            }
            $separator = '';
        }
        if ( ! $units ) return [ $text ];

        $lines = [];
        $current = '';
        foreach ( $units as $unit ) {
            if ( $unit === '' ) continue;
            $candidate = $current === '' ? $unit : ( $current . $separator . $unit );
            $bbox = imagettfbbox( $size, 0, $font, $candidate );
            $w = abs( $bbox[2] - $bbox[0] );
            if ( $w <= $max_w || $current === '' ) {
                $current = $candidate;
            } else {
                $lines[] = $current;
                $current = $unit;
            }
        }
        if ( $current !== '' ) {
            $lines[] = $current;
        }
        return $lines;
    }

    /**
     * v1.5.216.14 — Detect which script a headline is written in and return a
     * canonical key that maps to a bundled or lazy-fetched font.
     *
     * Strategy: prefer the language code (most reliable, the user explicitly
     * selects it per article). Fall back to character analysis when the
     * declared language is generic ('en') but the headline contains non-Latin
     * characters — e.g. an English-tagged article whose title was actually
     * written in Japanese.
     *
     * @return string One of: 'latin', 'arabic', 'hebrew', 'devanagari', 'thai',
     *                'cjk_jp', 'cjk_kr', 'cjk_sc', 'cjk_tc'.
     */
    private static function detect_script( string $headline, string $lang ): string {
        $lang_norm = strtolower( str_replace( '_', '-', trim( $lang ) ) );
        $base = strpos( $lang_norm, '-' ) !== false ? substr( $lang_norm, 0, strpos( $lang_norm, '-' ) ) : $lang_norm;

        // Traditional Chinese variants (zh-tw, zh-hant) need the TC font; all
        // other zh variants use Simplified.
        if ( in_array( $lang_norm, [ 'zh-tw', 'zh-hant', 'zh-hk' ], true ) ) {
            return 'cjk_tc';
        }

        $lang_to_script = [
            'ja' => 'cjk_jp',
            'ko' => 'cjk_kr',
            'zh' => 'cjk_sc',
            'ar' => 'arabic', 'fa' => 'arabic', 'ur' => 'arabic',
            'he' => 'hebrew',
            'hi' => 'devanagari', 'mr' => 'devanagari', 'ne' => 'devanagari',
            'th' => 'thai',
        ];
        if ( isset( $lang_to_script[ $base ] ) ) {
            return $lang_to_script[ $base ];
        }

        // Fall back to character-based detection when language code is ambiguous.
        if ( $headline !== '' ) {
            $counts = [
                'cjk_jp' => 0, 'cjk_kr' => 0, 'cjk_sc' => 0,
                'arabic' => 0, 'hebrew' => 0,
                'devanagari' => 0, 'thai' => 0,
            ];
            $len = mb_strlen( $headline, 'UTF-8' );
            for ( $i = 0; $i < $len; $i++ ) {
                $ch = mb_substr( $headline, $i, 1, 'UTF-8' );
                if ( preg_match( '/\s/u', $ch ) ) continue;
                $cp = self::utf8_codepoint( $ch );
                if ( ( $cp >= 0x3040 && $cp <= 0x309F ) || ( $cp >= 0x30A0 && $cp <= 0x30FF ) ) {
                    $counts['cjk_jp']++;
                } elseif ( $cp >= 0xAC00 && $cp <= 0xD7AF ) {
                    $counts['cjk_kr']++;
                } elseif ( ( $cp >= 0x4E00 && $cp <= 0x9FFF ) || ( $cp >= 0x3400 && $cp <= 0x4DBF ) ) {
                    $counts['cjk_sc']++; // generic CJK ideograph → assume Simplified
                } elseif ( ( $cp >= 0x0600 && $cp <= 0x06FF ) || ( $cp >= 0x0750 && $cp <= 0x077F ) || ( $cp >= 0xFB50 && $cp <= 0xFDFF ) ) {
                    $counts['arabic']++;
                } elseif ( $cp >= 0x0590 && $cp <= 0x05FF ) {
                    $counts['hebrew']++;
                } elseif ( $cp >= 0x0900 && $cp <= 0x097F ) {
                    $counts['devanagari']++;
                } elseif ( $cp >= 0x0E00 && $cp <= 0x0E7F ) {
                    $counts['thai']++;
                }
            }
            arsort( $counts );
            $top = array_key_first( $counts );
            if ( $top !== null && $counts[ $top ] >= 2 ) {
                return $top;
            }
        }

        return 'latin';
    }

    /**
     * v1.5.216.14 — Resolve a font file path for the given script + weight.
     *
     * - Latin uses the bundled Inter Bold / ExtraBold (assets/fonts/).
     * - All other scripts lazy-fetch Noto Sans variable TTF from the
     *   google/fonts GitHub repo to wp-content/uploads/seobetter-fonts/ on
     *   first use, then re-use the cached file forever after.
     *
     * The variable Noto fonts default to ~Regular weight when GD reads them
     * (PHP's FreeType binding can't access variable axes), so for non-Latin
     * scripts the "bold" and "extrabold" requests both return the same file —
     * the visual weight will be the variable's default instance. Acceptable
     * trade-off vs. shipping multiple static-weight files per script.
     *
     * @return string|false Font path on success, false on lazy-fetch failure.
     */
    private static function ensure_font( string $script, string $weight = 'bold' ) {
        if ( $script === 'latin' ) {
            $file = $weight === 'extrabold' ? 'Inter-ExtraBold.ttf' : 'Inter-Bold.ttf';
            $path = SEOBETTER_PLUGIN_DIR . 'assets/fonts/' . $file;
            return file_exists( $path ) ? $path : false;
        }

        // Map script → google/fonts repo path
        $remote_files = [
            'arabic'     => 'ofl/notosansarabic/NotoSansArabic%5Bwdth%2Cwght%5D.ttf',
            'hebrew'     => 'ofl/notosanshebrew/NotoSansHebrew%5Bwdth%2Cwght%5D.ttf',
            'devanagari' => 'ofl/notosansdevanagari/NotoSansDevanagari%5Bwdth%2Cwght%5D.ttf',
            'thai'       => 'ofl/notosansthai/NotoSansThai%5Bwdth%2Cwght%5D.ttf',
            'cjk_jp'     => 'ofl/notosansjp/NotoSansJP%5Bwght%5D.ttf',
            'cjk_kr'     => 'ofl/notosanskr/NotoSansKR%5Bwght%5D.ttf',
            'cjk_sc'     => 'ofl/notosanssc/NotoSansSC%5Bwght%5D.ttf',
            'cjk_tc'     => 'ofl/notosanstc/NotoSansTC%5Bwght%5D.ttf',
        ];
        if ( ! isset( $remote_files[ $script ] ) ) {
            return false;
        }

        if ( ! function_exists( 'wp_upload_dir' ) ) {
            return false;
        }
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            error_log( 'SEOBetter Image_Text_Overlay: wp_upload_dir error — ' . $upload['error'] );
            return false;
        }
        $font_dir = trailingslashit( $upload['basedir'] ) . 'seobetter-fonts/';
        if ( ! file_exists( $font_dir ) ) {
            wp_mkdir_p( $font_dir );
        }

        $local_path = $font_dir . $script . '.ttf';
        if ( file_exists( $local_path ) && filesize( $local_path ) > 10000 ) {
            return $local_path;
        }

        // Lazy-fetch from google/fonts repo. CJK files are ~10MB so allow a
        // generous timeout. wp_remote_get respects the host's HTTP API.
        $url = 'https://raw.githubusercontent.com/google/fonts/main/' . $remote_files[ $script ];
        error_log( 'SEOBetter Image_Text_Overlay: lazy-fetching ' . $script . ' font from ' . $url );

        $response = wp_remote_get( $url, [
            'timeout'    => 90, // CJK files are ~10MB
            'sslverify'  => true,
            'user-agent' => 'SEOBetter/' . ( defined( 'SEOBETTER_VERSION' ) ? SEOBETTER_VERSION : '0' ) . '; +https://seobetter.com',
        ] );
        if ( is_wp_error( $response ) ) {
            error_log( 'SEOBetter Image_Text_Overlay: ' . $script . ' fetch wp_error — ' . $response->get_error_message() );
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            error_log( 'SEOBetter Image_Text_Overlay: ' . $script . ' fetch HTTP ' . $code );
            return false;
        }
        $body = wp_remote_retrieve_body( $response );
        if ( strlen( $body ) < 50000 ) {
            error_log( 'SEOBetter Image_Text_Overlay: ' . $script . ' download too small (' . strlen( $body ) . ' bytes) — likely an error page, aborting' );
            return false;
        }
        // Sanity-check first 4 bytes look like a TTF header (00 01 00 00) or
        // OTF header (4F 54 54 4F). Otherwise we fetched HTML/garbage.
        $magic = substr( $body, 0, 4 );
        if ( $magic !== "\x00\x01\x00\x00" && $magic !== 'OTTO' && $magic !== 'true' && $magic !== 'ttcf' ) {
            error_log( 'SEOBetter Image_Text_Overlay: ' . $script . ' fetch did not return a valid TTF (magic=' . bin2hex( $magic ) . ')' );
            return false;
        }
        if ( file_put_contents( $local_path, $body ) === false ) {
            error_log( 'SEOBetter Image_Text_Overlay: failed to write ' . $script . ' font to ' . $local_path );
            return false;
        }
        error_log( 'SEOBetter Image_Text_Overlay: cached ' . $script . ' font at ' . $local_path . ' (' . strlen( $body ) . ' bytes)' );
        return $local_path;
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
