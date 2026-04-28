<?php
/**
 * AI_Image_Generator — v1.5.32
 *
 * Generates brand-aware AI featured images for articles based on the article
 * title, primary keyword, and the user's configured Branding settings.
 *
 * Supported providers (in priority order):
 *   1. Pollinations.ai — FREE, no API key required. Uses FLUX Schnell backend.
 *      Rate-limited and occasionally slow, but works out of the box with zero
 *      setup friction. Default for new users.
 *   2. Google Gemini 2.5 Flash Image ("Nano Banana") — via Gemini API direct
 *      or OpenRouter. Free tier: ~10 images/day on Google AI Studio. Paid:
 *      ~$0.039 per image. Best cheapest commercial option.
 *   3. OpenAI DALL-E 3 — $0.04 standard / $0.08 HD per image. Strong brand-
 *      aware prompt adherence. 1024×1024 or 1792×1024 landscape.
 *   4. Black Forest Labs FLUX.1 Pro 1.1 — via fal.ai. $0.055 per image.
 *      Best realism + composition for editorial hero images.
 *
 * The class composes a prompt from the user's branding settings (style preset,
 * brand colors, business description) + the article title + keyword, calls
 * the selected provider, returns a URL that the existing featured-image
 * download path (media_sideload_image) can consume.
 *
 * Returns empty string on any error — caller falls back to Pexels/Picsum.
 */

namespace SEOBetter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI_Image_Generator {

    /**
     * v1.5.216.8 — Banner-design style presets WITH controlled article-title
     * text rendering (replaces the brief no-text era).
     *
     * Each preset is a different banner design pattern (NYT-Magazine / flat /
     * cinematic / minimalist / etc) that includes the article HEADLINE as
     * legible bold sans-serif text positioned in the social-share safe zone.
     *
     * Banner-design specs (research-backed for social-media legibility):
     *   - 1200×630 Open Graph standard (covers FB/LinkedIn/Twitter/WhatsApp
     *     /iMessage/Discord). Pinterest pins use 1000×1500 separately.
     *   - Inner 1000×500 safe zone (100px margin all sides) — Slack/Discord
     *     square-crop won't clip the headline
     *   - Headline minimum 70-80px tall, optimal 90-120px (legible at the
     *     ~300px-wide mobile thumbnail social platforms display)
     *   - Subhead 40-60px tall (clearly subordinate)
     *   - Bold sans-serif (Inter / Helvetica / SF Pro family) — survives
     *     downscaling far better than serif or thin weights
     *   - WCAG AA contrast ratio (4.5:1) — semi-transparent dark scrim under
     *     light text or vice versa
     *
     * 7 banner patterns:
     *   1. realistic     — NYT-Magazine cover: bottom-third dark gradient + white headline
     *   2. illustration  — Modern flat: upper-left dark headline on light illustration
     *   3. flat          — Title-led split: left half text, right half iconographic
     *   4. hero          — Cinematic full-bleed: centered title with cinema black bars
     *   5. minimalist    — Small corner: bottom-right small title, image dominant
     *   6. editorial     — Classic magazine (NYT): title top with horizontal divider
     *   7. 3d            — Product hero: centered overlay text on rendered scene
     *
     * Placeholders:
     *   {subject}  — sanitized topic phrase (KEYWORD, not full article title)
     *                drives WHAT the image depicts
     *   {headline} — the article title (max 60 chars, sanitized) rendered
     *                AS TEXT in the banner
     *   {colors}   — brand color palette woven into color grading
     */
    const STYLE_PRESETS = [
        'realistic' =>
            'Award-winning editorial magazine cover photograph for a Tier 1 publication (Wired / The New York Times Magazine / National Geographic). Subject of the photo: {subject}. Shot on Sony A7R IV, 50mm prime lens, f/2.8 shallow depth of field, natural directional lighting, golden-hour warm tones, {colors} color grading, sharp focus on subject, soft bokeh background. The article headline is rendered as TEXT OVERLAY in the lower third of the image: "{headline}" — set in BOLD WHITE sans-serif (Inter/Helvetica/SF Pro family), large size approximately 90-120px tall on a 1200×630 canvas, positioned in the inner 1000×500 social-share safe zone, with a subtle dark gradient scrim from the bottom up for WCAG AA contrast and mobile-thumbnail legibility. NO additional text beyond the single specified headline — NO logos, NO watermarks, NO subtitles, NO chalkboards, NO menu boards, NO speech bubbles.',

        'illustration' =>
            'Modern editorial vector illustration. Subject: {subject}. Clean flat shapes with subtle gradients, {colors} dominant palette, generous negative space, geometric composition, minimalist style aligned with The New Yorker or Wired magazine illustration, crisp lines, 16:9 aspect ratio. The article headline is rendered as TEXT OVERLAY in the upper-left third: "{headline}" — set in BOLD DARK sans-serif (Inter/Helvetica/SF Pro family), large size approximately 90-120px tall on a 1200×630 canvas, positioned in the inner 1000×500 safe zone, high contrast against the illustration. NO additional text — NO logos, NO watermarks, NO subtitles, NO speech bubbles.',

        'flat' =>
            'Bold modern flat design banner with split composition. LEFT HALF: large article headline as TEXT OVERLAY: "{headline}" — set in BOLD sans-serif (Inter/Helvetica/SF Pro family), large size approximately 100-130px tall on a 1200×630 canvas, dark text on light flat background or light text on {colors} dominant background depending on contrast. RIGHT HALF: bold abstract iconographic representation of {subject}, solid color blocks no gradients, {colors} primary palette, geometric shapes, premium app-design quality. NO additional text beyond the single headline, NO numbers, NO logos, NO watermarks.',

        'hero' =>
            'Cinematic premium hero banner photograph. Subject: {subject}. Dramatic side-lighting, anamorphic lens flare, {colors} teal-and-orange film color grade, atmospheric depth, film grain, 16:9 cinema crop with subtle black letterbox bars top and bottom, premium ad-campaign aesthetic. The article headline is rendered as CENTERED TEXT OVERLAY: "{headline}" — set in BOLD WHITE sans-serif (Inter/Helvetica/SF Pro family), large size approximately 100-130px tall on a 1200×630 canvas, positioned in the inner 1000×500 safe zone, with subtle text-shadow for legibility on varied backgrounds. NO additional text, NO logos, NO watermarks, NO chalkboards or menu boards, NO secondary captions.',

        'minimalist' =>
            'Minimalist editorial composition. Subject: {subject}. Abundant negative space, soft natural lighting, single-subject focus, gallery-quality photography, monochromatic with {colors} as subtle accent, contemplative mood, premium lifestyle publication style (Kinfolk / Cereal magazine), 16:9 aspect ratio. The article headline is rendered as SMALL TEXT in the bottom-right corner: "{headline}" — set in elegant sans-serif (Inter/Helvetica/SF Pro family), modest size approximately 60-80px tall on a 1200×630 canvas, positioned within the inner 1000×500 safe zone, dark text on the image (contrast achieved through composition negative space). The subject visual dominates; text is supporting. NO logos, NO watermarks, NO additional text.',

        'editorial' =>
            'Award-winning classic editorial magazine layout (The New York Times / Atlantic style). The article headline is rendered as TEXT in the TOP THIRD of the image: "{headline}" — set in BOLD DARK serif or sans-serif (depending on subject), large size approximately 100-120px tall on a 1200×630 canvas, positioned in the inner 1000×500 safe zone, on a clean light or {colors}-tinted background. Below the headline: a thin horizontal accent divider, then the photographic subject occupying the lower two-thirds — environmental composition of {subject}, natural directional lighting, {colors} subtle color grading, professional photojournalism quality, sharp focus. NO additional text beyond the single headline, NO subtitles, NO logos, NO watermarks, NO numbers visible.',

        '3d' =>
            'Premium 3D render banner. Subject: {subject}. Soft three-point studio lighting, {colors} brand palette, clean seamless white-to-grey gradient background, hyper-detailed materials with subsurface scattering, claymation-soft shading, 35-degree camera angle, 16:9 aspect ratio, premium product-shot quality. The article headline is rendered as CENTERED TEXT OVERLAY floating above the rendered scene: "{headline}" — set in BOLD sans-serif (Inter/Helvetica/SF Pro family), large size approximately 90-120px tall on a 1200×630 canvas, positioned in the inner 1000×500 safe zone, color chosen for maximum contrast against the rendered subject. NO additional text, NO logos, NO watermarks, NO product labels visible.',
    ];

    /**
     * Main entry. Generate a featured image URL for an article.
     *
     * @param string $title    Article title.
     * @param string $keyword  Primary keyword.
     * @param array  $brand    Brand settings (business_name, description, colors, style, provider, api_key).
     * @return string Image URL, or empty string on failure.
     */
    public static function generate( string $title, string $keyword, array $brand ): string {
        $provider = $brand['provider'] ?? '';
        // v1.5.216.5 — verbose entry logging
        error_log( "SEOBetter AI_Image_Generator::generate provider=" . ( $provider ?: '(empty)' ) . " title=" . substr( $title, 0, 60 ) );

        if ( empty( $provider ) ) {
            error_log( "SEOBetter AI_Image_Generator::generate: BAIL — no provider configured" );
            return '';
        }

        $prompt = self::build_prompt( $title, $keyword, $brand );
        if ( $prompt === '' ) {
            error_log( "SEOBetter AI_Image_Generator::generate: BAIL — built prompt is empty" );
            return '';
        }
        error_log( "SEOBetter AI_Image_Generator::generate: routing to provider={$provider}, prompt len=" . strlen( $prompt ) );

        switch ( $provider ) {
            case 'pollinations':
                return self::generate_pollinations( $prompt );
            case 'openrouter':
                // v1.5.215 — OpenRouter routing for Nano Banana. Reuses the
                // user's existing OpenRouter BYOK key from AI_Provider_Manager
                // so users don't manage two keys for the same upstream account.
                return self::generate_openrouter( $prompt );
            case 'gemini':
                return self::generate_gemini( $prompt, $brand['api_key'] ?? '' );
            case 'dalle3':
                return self::generate_dalle3( $prompt, $brand['api_key'] ?? '' );
            case 'flux_pro':
                return self::generate_flux_pro( $prompt, $brand['api_key'] ?? '' );
            default:
                return '';
        }
    }

    /**
     * v1.5.216.8 — Compose the final image generation prompt with banner-
     * design intent: render the article title as bold sans-serif text overlay
     * positioned in the social-share safe zone for mobile-thumbnail legibility.
     *
     * Three substitutions filled into the style template:
     *   {subject}  — sanitized topic phrase (KEYWORD, drives WHAT the image depicts)
     *   {headline} — sanitized article title (max 60 chars, what TEXT to render)
     *   {colors}   — brand color palette (color grading hint)
     *
     * Sanitization rules:
     *   - Subject = lowercased keyword with year suffixes stripped — keeps
     *     the depiction phrase short and free of "2026" digits that would
     *     duplicate or compete with the rendered headline
     *   - Headline = the article TITLE (post_title), trimmed to 60 chars
     *     max, with trailing colon-edition-year suffixes ("— édition 2026")
     *     stripped because those would clutter the visual headline
     *   - Business name + description NO LONGER appended (those caused
     *     uncontrolled text-leak in v1.5.215; brand context is conveyed
     *     exclusively through the {colors} weave + the style template's
     *     "premium publication" framing)
     */
    private static function build_prompt( string $title, string $keyword, array $brand ): string {
        $style_key = $brand['style'] ?? 'realistic';
        $template  = self::STYLE_PRESETS[ $style_key ] ?? self::STYLE_PRESETS['realistic'];

        $primary   = trim( (string) ( $brand['color_primary'] ?? '' ) );
        $secondary = trim( (string) ( $brand['color_secondary'] ?? '' ) );

        // Color phrase
        $colors = '';
        if ( $primary && $secondary ) {
            $colors = $primary . ' and ' . $secondary;
        } elseif ( $primary ) {
            $colors = $primary;
        } else {
            $colors = 'natural editorial';
        }

        // === SUBJECT (what the image depicts) ===
        // Use keyword if available — it's a short topic phrase. Strip year
        // suffixes and trailing punctuation that don't add visual meaning
        // and would compete with the rendered headline.
        $subject = trim( $keyword !== '' ? $keyword : $title );
        $subject = preg_replace( '/\s*[:\-—]\s*(édition|edition|guide|review|complete guide)\s+\d{4}\s*$/iu', '', $subject );
        $subject = preg_replace( '/\s+\d{4}\s*$/', '', $subject );
        $subject = preg_replace( '/\s*[:\-—]\s*$/', '', $subject );
        $subject = trim( $subject );
        $subject = function_exists( 'mb_strtolower' )
            ? mb_strtolower( $subject, 'UTF-8' )
            : strtolower( $subject );
        if ( $subject === '' ) {
            $subject = 'editorial subject';
        }

        // === HEADLINE (text to render in the banner) ===
        // The article title becomes the visible banner headline. Trim
        // trailing colon-edition tails so the headline reads cleanly. Cap
        // at 60 chars so it wraps to 1-2 lines on a 1200×630 banner without
        // overflowing the safe zone — research-backed mobile-thumbnail
        // legibility threshold.
        $headline = trim( $title !== '' ? $title : $keyword );
        $headline = preg_replace( '/\s*[:\-—]\s*(édition|edition)\s+\d{4}\s*$/iu', '', $headline );
        $headline = preg_replace( '/\s*\d{4}\s*$/', '', $headline );
        $headline = trim( $headline, " \t\n\r\0\x0B.—-:" );
        if ( function_exists( 'mb_strlen' ) ? mb_strlen( $headline, 'UTF-8' ) > 60 : strlen( $headline ) > 60 ) {
            $headline = function_exists( 'mb_substr' )
                ? rtrim( mb_substr( $headline, 0, 57, 'UTF-8' ), ' .—-,:' ) . '…'
                : rtrim( substr( $headline, 0, 57 ), ' .—-,:' ) . '...';
        }
        if ( $headline === '' ) {
            $headline = ucwords( $subject );
        }

        // Fill the template
        $prompt = str_replace(
            [ '{subject}', '{headline}', '{colors}' ],
            [ $subject, $headline, $colors ],
            $template
        );

        // User negative prompt appends to the template's existing constraints.
        $negative = trim( (string) ( $brand['negative_prompt'] ?? '' ) );
        if ( $negative ) {
            $prompt .= ' Also avoid: ' . $negative;
        }

        return $prompt;
    }

    /**
     * Pollinations.ai — FREE, no API key. Fetches the generated image and
     * saves it to a local temp file with a .jpg extension so
     * media_sideload_image() can consume it (that function requires a file
     * extension in the URL path to detect mime type).
     *
     * v1.5.34: previously returned the Pollinations URL directly, but that
     * URL has no extension so media_sideload_image silently failed and
     * the featured image fell through to Picsum. Now downloads + saves
     * locally first.
     */
    private static function generate_pollinations( string $prompt ): string {
        $base = 'https://image.pollinations.ai/prompt/';
        $encoded = rawurlencode( $prompt );
        $query = http_build_query( [
            'width'    => 1200,
            'height'   => 630,
            'model'    => 'flux',
            'nologo'   => 'true',
            'enhance'  => 'true',
            'seed'     => abs( crc32( $prompt ) ) % 100000,
        ] );
        $pollinations_url = $base . $encoded . '?' . $query;

        // Fetch the actual image. Pollinations generates on first fetch
        // (can take 5-20s) and returns a JPEG.
        $response = wp_remote_get( $pollinations_url, [
            'timeout'     => 60,
            'redirection' => 3,
        ] );
        if ( is_wp_error( $response ) ) return '';
        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) return '';

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) return '';

        return self::save_binary_to_temp( $body, 'jpg' );
    }

    /**
     * v1.5.215 — Gemini 2.5 Flash Image ("Nano Banana") via OpenRouter.
     *
     * Uses the user's existing OpenRouter BYOK key (configured in Settings →
     * AI Providers for article generation) — no separate key field needed.
     * Same OpenRouter account is billed for both LLM calls and image calls;
     * single dashboard, single rate limit, single failure mode.
     *
     * Endpoint: chat completions with multimodal output. Gemini Image returns
     * the image as base64 inline_data inside the assistant message content,
     * matching the direct-Google response shape closely enough that we share
     * the parser path (with a small adapter for the OpenRouter wrapper).
     *
     * Cost: same ~$0.039/image as Google direct (OpenRouter pass-through
     * pricing as of late 2025; OpenRouter takes a small flat margin).
     */
    private static function generate_openrouter( string $prompt ): string {
        // Reuse the OpenRouter BYOK key the user already configured for article
        // generation. Falls back to env var for dev/test.
        $api_key = AI_Provider_Manager::get_provider_key( 'openrouter' );
        if ( empty( $api_key ) ) {
            $api_key = defined( 'SEOBETTER_OPENROUTER_KEY' ) ? SEOBETTER_OPENROUTER_KEY : '';
        }
        if ( empty( $api_key ) ) {
            // v1.5.215.1 — verbose logging so users can self-diagnose. Pre-fix
            // a missing OpenRouter key silently fell through to Pexels with no
            // hint to the user — they thought OpenRouter was broken. Now WP
            // debug.log shows exactly which path failed.
            error_log( 'SEOBetter OpenRouter image: no API key — configure OpenRouter in Settings → AI Providers (BYOK section) first.' );
            return '';
        }

        // v1.5.216.2 — Model slug fallback chain. Google promoted Gemini 2.5
        // Flash Image from preview → GA in late 2025 and OpenRouter dropped
        // the `-preview` suffix on the canonical slug. The `-preview` slug may
        // still resolve as an alias OR may 404 depending on when OpenRouter
        // ran their cleanup. Try the GA slug FIRST (most stable going forward),
        // fall back to `-preview` if 404, then surface the failure to debug.log.
        // Filter `seobetter_openrouter_image_model` returns a SINGLE preferred
        // slug; we always try the preferred + the alternate as fallback.
        $preferred = apply_filters( 'seobetter_openrouter_image_model', 'google/gemini-2.5-flash-image' );
        $slug_candidates = [ $preferred ];
        if ( $preferred !== 'google/gemini-2.5-flash-image-preview' ) {
            $slug_candidates[] = 'google/gemini-2.5-flash-image-preview';
        } else {
            $slug_candidates[] = 'google/gemini-2.5-flash-image';
        }

        $response = null; $model = ''; $last_error = '';
        foreach ( $slug_candidates as $candidate ) {
            $resp = self::call_openrouter_image( $api_key, $candidate, $prompt );
            if ( is_wp_error( $resp ) ) {
                $last_error = $resp->get_error_message();
                continue;
            }
            $code = wp_remote_retrieve_response_code( $resp );
            if ( $code === 200 ) {
                // Success — keep this response and break out
                $response = $resp;
                $model = $candidate;
                break;
            }
            // 404 (slug not found) → try next candidate. 401/429/etc → bail
            // immediately because retrying with another slug won't help.
            if ( $code === 404 ) {
                $last_error = 'HTTP 404 on ' . $candidate;
                continue;
            }
            $body_excerpt = substr( wp_remote_retrieve_body( $resp ), 0, 300 );
            error_log( 'SEOBetter OpenRouter image HTTP ' . $code . ' on ' . $candidate . ': ' . $body_excerpt );
            return '';
        }

        if ( ! $response ) {
            error_log( 'SEOBetter OpenRouter image: all model slugs failed. Last error: ' . $last_error . '. Tried: ' . implode( ', ', $slug_candidates ) . '. Override via filter `seobetter_openrouter_image_model` if Google rotated the slug again.' );
            return '';
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        // OpenRouter wraps the response in OpenAI-style choices[]. Inside, the
        // assistant message content can be either:
        //   (a) a string with image inlined as data URL, OR
        //   (b) an array of parts (matching Gemini direct shape).
        $message = $data['choices'][0]['message'] ?? [];
        $content = $message['content'] ?? '';

        // Some OpenRouter responses include an `images` array on the message
        // (newer schema), each with `image_url.url` as a data URL.
        if ( ! empty( $message['images'] ) && is_array( $message['images'] ) ) {
            foreach ( $message['images'] as $img ) {
                $url_or_data = $img['image_url']['url'] ?? '';
                $saved = self::save_data_url( $url_or_data );
                if ( $saved !== '' ) return $saved;
            }
        }

        // Older schema: array of parts mirroring Gemini direct.
        if ( is_array( $content ) ) {
            foreach ( $content as $part ) {
                if ( isset( $part['inlineData']['data'] ) ) {
                    return self::save_base64_to_temp(
                        $part['inlineData']['data'],
                        $part['inlineData']['mimeType'] ?? 'image/png'
                    );
                }
                if ( isset( $part['image_url']['url'] ) ) {
                    $saved = self::save_data_url( $part['image_url']['url'] );
                    if ( $saved !== '' ) return $saved;
                }
            }
        }

        // Last resort: scan a string content for an inline data URL.
        if ( is_string( $content ) && $content !== '' ) {
            $saved = self::save_data_url( $content );
            if ( $saved !== '' ) return $saved;
        }

        // v1.5.215.1 — if we got here we hit OpenRouter successfully but
        // couldn't parse an image out of the response. Log the response shape
        // (truncated) so we can update the parser when OpenRouter rotates
        // their schema. The model slug filter `seobetter_openrouter_image_model`
        // also lets advanced users override the slug if Google moves it again.
        $body_excerpt = substr( wp_remote_retrieve_body( $response ), 0, 400 );
        error_log( 'SEOBetter OpenRouter image: 200 OK but no image found in response. Model=' . $model . '. Body excerpt: ' . $body_excerpt );
        return '';
    }

    /**
     * v1.5.216.2 — Single OpenRouter chat-completions call for image generation.
     * Extracted from generate_openrouter() so the model-slug fallback loop
     * can call it multiple times with different model IDs without duplicating
     * the request boilerplate.
     */
    private static function call_openrouter_image( string $api_key, string $model, string $prompt ) {
        return wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                // OpenRouter requires HTTP-Referer + X-Title for app attribution.
                'HTTP-Referer'  => home_url(),
                'X-Title'       => 'SEOBetter',
            ],
            'body' => wp_json_encode( [
                'model'    => $model,
                'messages' => [
                    [
                        'role'    => 'user',
                        'content' => 'Generate a high-quality image for: ' . $prompt,
                    ],
                ],
                // Gemini-family image models honour these via OpenRouter's
                // pass-through; non-image models will ignore safely.
                'modalities' => [ 'image', 'text' ],
            ] ),
        ] );
    }

    /**
     * v1.5.215 — Save a `data:image/...;base64,...` URL string to a temp
     * file. Used by the OpenRouter response parser.
     */
    private static function save_data_url( string $data_url ): string {
        if ( strpos( $data_url, 'data:image/' ) !== 0 ) return '';
        if ( ! preg_match( '#^data:image/([a-z0-9+.-]+);base64,(.+)$#i', $data_url, $m ) ) return '';
        $ext = strtolower( $m[1] );
        $ext = ( $ext === 'jpeg' ) ? 'jpg' : preg_replace( '/[^a-z0-9]/', '', $ext );
        if ( $ext === '' ) $ext = 'png';
        return self::save_base64_to_temp( $m[2], 'image/' . $ext );
    }

    /**
     * Google Gemini 2.5 Flash Image ("Nano Banana") — via Gemini API direct.
     * Free tier ~10 images/day, paid ~$0.039/image.
     * API: https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent
     */
    private static function generate_gemini( string $prompt, string $api_key ): string {
        if ( empty( $api_key ) ) return '';

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image-preview:generateContent?key=' . urlencode( $api_key );

        $body = [
            'contents' => [
                [
                    'parts' => [
                        [ 'text' => 'Generate a high-quality image: ' . $prompt ],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseModalities' => [ 'Text', 'Image' ],
            ],
        ];

        $response = wp_remote_post( $url, [
            'timeout' => 45,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) return '';
        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) return '';

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        foreach ( $parts as $part ) {
            if ( isset( $part['inlineData']['data'] ) ) {
                // Gemini returns base64-encoded image data inline. We save it
                // as a temporary file and return the file URL.
                return self::save_base64_to_temp( $part['inlineData']['data'], $part['inlineData']['mimeType'] ?? 'image/png' );
            }
        }
        return '';
    }

    /**
     * OpenAI DALL-E 3 — $0.04 standard / $0.08 HD per image.
     * API: https://api.openai.com/v1/images/generations
     */
    private static function generate_dalle3( string $prompt, string $api_key ): string {
        if ( empty( $api_key ) ) return '';

        $response = wp_remote_post( 'https://api.openai.com/v1/images/generations', [
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( [
                'model'   => 'dall-e-3',
                'prompt'  => $prompt,
                'n'       => 1,
                'size'    => '1792x1024',
                'quality' => 'standard',
            ] ),
        ] );

        if ( is_wp_error( $response ) ) return '';
        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) return '';

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['data'][0]['url'] ?? '';
    }

    /**
     * FLUX.1 Pro 1.1 via fal.ai — $0.055 per image.
     * API: https://fal.run/fal-ai/flux-pro/v1.1
     */
    private static function generate_flux_pro( string $prompt, string $api_key ): string {
        if ( empty( $api_key ) ) return '';

        $response = wp_remote_post( 'https://fal.run/fal-ai/flux-pro/v1.1', [
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Key ' . $api_key,
            ],
            'body' => wp_json_encode( [
                'prompt'          => $prompt,
                'image_size'      => 'landscape_16_9',
                'num_images'      => 1,
                'enable_safety_checker' => true,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) return '';
        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) return '';

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['images'][0]['url'] ?? '';
    }

    /**
     * Save a base64-encoded image (from Gemini Nano Banana) to a temp file
     * in the uploads directory and return its URL. The caller then passes
     * this URL to media_sideload_image() which will copy it into the final
     * media library attachment.
     */
    private static function save_base64_to_temp( string $b64, string $mime ): string {
        $decoded = base64_decode( $b64, true );
        if ( $decoded === false ) return '';
        $ext = ( strpos( $mime, 'jpeg' ) !== false ) ? 'jpg' : 'png';
        return self::save_binary_to_temp( $decoded, $ext );
    }

    /**
     * v1.5.34 — shared helper that writes binary image data to a temp file
     * in the uploads dir with a proper extension, then returns the public
     * URL. Used by both Pollinations (raw JPEG fetch) and Gemini (base64 inline).
     */
    private static function save_binary_to_temp( string $binary, string $ext ): string {
        if ( empty( $binary ) ) return '';
        $upload_dir = wp_upload_dir();
        $filename = 'sb-ai-image-' . wp_generate_password( 8, false ) . '.' . $ext;
        $filepath = $upload_dir['path'] . '/' . $filename;
        $fileurl  = $upload_dir['url']  . '/' . $filename;
        if ( file_put_contents( $filepath, $binary ) === false ) return '';
        return $fileurl;
    }

    /**
     * Load brand settings from the seobetter_settings option. Returns an
     * array with normalized keys or empty array if branding is not configured.
     */
    public static function get_brand_settings(): array {
        $settings = get_option( 'seobetter_settings', [] );
        $provider = $settings['branding_provider'] ?? '';
        if ( empty( $provider ) ) return [];

        return [
            'provider'        => $provider,
            'api_key'         => $settings['branding_api_key'] ?? '',
            'style'           => $settings['branding_style'] ?? 'realistic',
            'business_name'   => $settings['branding_business_name'] ?? '',
            'description'     => $settings['branding_description'] ?? '',
            'color_primary'   => $settings['branding_color_primary'] ?? '',
            'color_secondary' => $settings['branding_color_secondary'] ?? '',
            'color_accent'    => $settings['branding_color_accent'] ?? '',
            'negative_prompt' => $settings['branding_negative_prompt'] ?? '',
        ];
    }
}
