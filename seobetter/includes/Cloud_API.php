<?php

namespace SEOBetter;

/**
 * SEOBetter Cloud API Client.
 *
 * Provides AI content generation for users who don't have their own API keys.
 * Requests are proxied through seobetter.com which holds the AI provider keys.
 *
 * Free tier: 5 articles/month
 * Pro tier: Unlimited
 *
 * The cloud endpoint accepts the prompt and returns generated content.
 * The site URL and plugin version are sent for rate limiting and analytics.
 *
 * Cloud URL is configurable via Settings or the SEOBETTER_CLOUD_URL constant.
 * Default: https://seobetter-cloud.vercel.app (Vercel deployment)
 * Production: https://api.seobetter.com (custom domain on Vercel)
 */
class Cloud_API {

    private const DEFAULT_CLOUD_URL = 'https://seobetter.vercel.app';

    /**
     * Get the Cloud API URL (configurable via settings or constant).
     */
    public static function get_cloud_url(): string {
        // Allow override via wp-config.php constant
        if ( defined( 'SEOBETTER_CLOUD_URL' ) ) {
            return SEOBETTER_CLOUD_URL;
        }

        // Allow override via settings
        $settings = get_option( 'seobetter_settings', [] );
        if ( ! empty( $settings['cloud_url'] ) ) {
            return rtrim( $settings['cloud_url'], '/' );
        }

        return self::DEFAULT_CLOUD_URL;
    }

    /**
     * Generate content via SEOBetter Cloud.
     */
    public static function generate( string $prompt, string $system_prompt = '', array $options = [] ): array {
        // Check if generation is allowed
        $check = License_Manager::can_generate();
        if ( ! $check['allowed'] ) {
            return [ 'success' => false, 'error' => $check['message'] ];
        }

        // If user has their own key, use that instead
        if ( $check['source'] === 'byok' ) {
            $provider = AI_Provider_Manager::get_active_provider();
            return AI_Provider_Manager::send_request(
                $provider['provider_id'],
                $prompt,
                $system_prompt,
                $options
            );
        }

        // Use SEOBetter Cloud
        $license = get_option( 'seobetter_license', [] );

        $response = wp_remote_post( self::get_cloud_url() . '/api/generate', [
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode( [
                'prompt'        => $prompt,
                'system_prompt' => $system_prompt,
                'max_tokens'    => $options['max_tokens'] ?? 4096,
                'temperature'   => $options['temperature'] ?? 0.7,
                'site_url'      => home_url(),
                'license_key'   => $license['key'] ?? '',
                'plugin_version' => SEOBETTER_VERSION,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => 'Cloud connection failed: ' . $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 429 ) {
            return [ 'success' => false, 'error' => 'Monthly generation limit reached. Connect your own API key in Settings, or upgrade to Pro.' ];
        }

        if ( $code !== 200 || empty( $body['content'] ) ) {
            $error = $body['error'] ?? $body['message'] ?? 'Unknown cloud error (HTTP ' . $code . ')';
            return [ 'success' => false, 'error' => $error ];
        }

        // Record usage
        License_Manager::record_usage();

        return [
            'success' => true,
            'content' => $body['content'],
            'model'   => $body['model'] ?? 'seobetter-cloud',
            'source'  => 'cloud',
        ];
    }

    /**
     * Check cloud API status and remaining credits.
     */
    public static function check_status(): array {
        $check = License_Manager::can_generate();

        return [
            'source'         => $check['source'] ?? 'cloud',
            'remaining'      => $check['remaining'] ?? 0,
            'monthly_used'   => License_Manager::get_monthly_usage(),
            'monthly_limit'  => License_Manager::is_pro() ? 'unlimited' : 5,
            'has_own_key'    => $check['source'] === 'byok',
        ];
    }
}
