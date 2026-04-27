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
     * Style preset → prompt template mapping. The {subject} placeholder is
     * filled with the article title + keywords. {colors} is filled with the
     * user's brand colors if set.
     */
    const STYLE_PRESETS = [
        'realistic' => 'Professional high-quality photograph, editorial style, {colors} color accents, clean composition, natural lighting, shallow depth of field, 16:9 aspect ratio. Subject: {subject}. No text overlay, no logos, no watermarks.',
        'illustration' => 'Professional vector illustration, clean lines, {colors} color palette, minimal shading, editorial quality, 16:9 aspect ratio. Subject: {subject}. No text, no logos.',
        'flat' => 'Flat graphic design, bold geometric shapes, {colors} color palette, minimal composition, no photorealism, no text, clean solid background, 16:9 aspect ratio. Subject: {subject}.',
        'hero' => 'Cinematic hero banner image, dramatic lighting, {colors} color grading, wide-angle composition, magazine-cover quality, 16:9 aspect ratio. Subject: {subject}. No text, no logos.',
        'minimalist' => 'Minimalist composition, lots of negative space, {colors} color accents, soft natural lighting, clean background, 16:9 aspect ratio. Subject: {subject}. No text overlay.',
        'editorial' => 'Editorial magazine-style photograph, journalism quality, {colors} accents, thoughtful framing, professional lighting, 16:9 aspect ratio. Subject: {subject}. No text, no logos.',
        '3d' => 'Modern 3D render, soft studio lighting, {colors} color palette, clean background, professional product-shot style, 16:9 aspect ratio. Subject: {subject}.',
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
        if ( empty( $provider ) ) {
            return '';
        }

        $prompt = self::build_prompt( $title, $keyword, $brand );
        if ( $prompt === '' ) {
            return '';
        }

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
     * Compose the final image generation prompt from the article title,
     * keyword, and user's branding settings.
     */
    private static function build_prompt( string $title, string $keyword, array $brand ): string {
        $style_key = $brand['style'] ?? 'realistic';
        $template  = self::STYLE_PRESETS[ $style_key ] ?? self::STYLE_PRESETS['realistic'];

        $business_name = trim( (string) ( $brand['business_name'] ?? '' ) );
        $description   = trim( (string) ( $brand['description'] ?? '' ) );
        $primary       = trim( (string) ( $brand['color_primary'] ?? '' ) );
        $secondary     = trim( (string) ( $brand['color_secondary'] ?? '' ) );

        // Build the color description from user-set brand colors
        $colors = '';
        if ( $primary && $secondary ) {
            $colors = $primary . ' and ' . $secondary;
        } elseif ( $primary ) {
            $colors = $primary;
        } else {
            $colors = 'natural, editorial';
        }

        // Build the subject from title + keyword (+ business context if set)
        $subject = trim( $title );
        if ( $keyword && stripos( $subject, $keyword ) === false ) {
            $subject .= ' — ' . $keyword;
        }
        if ( $description ) {
            // Trim description to a short phrase so it doesn't blow out the prompt
            $short_desc = mb_substr( preg_replace( '/\s+/', ' ', $description ), 0, 80 );
            $subject .= '. Brand context: ' . $short_desc;
        }
        if ( $business_name ) {
            $subject .= ' (' . $business_name . ' brand aesthetic)';
        }

        // Fill the template
        $prompt = str_replace(
            [ '{subject}', '{colors}' ],
            [ $subject, $colors ],
            $template
        );

        // Add any user negative prompt
        $negative = trim( (string) ( $brand['negative_prompt'] ?? '' ) );
        if ( $negative ) {
            $prompt .= ' Avoid: ' . $negative;
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
        if ( empty( $api_key ) ) return '';

        // Model slug. As of late 2025 OpenRouter exposes Nano Banana as
        // `google/gemini-2.5-flash-image-preview`. If Google rotates the slug
        // upstream, OpenRouter usually keeps a stable alias — but if the slug
        // ever fails, surface it via error_log so we can update.
        $model = apply_filters( 'seobetter_openrouter_image_model', 'google/gemini-2.5-flash-image-preview' );

        $response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', [
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

        if ( is_wp_error( $response ) ) {
            error_log( 'SEOBetter OpenRouter image error: ' . $response->get_error_message() );
            return '';
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            error_log( 'SEOBetter OpenRouter image HTTP ' . $code . ': ' . substr( wp_remote_retrieve_body( $response ), 0, 200 ) );
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

        return '';
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
