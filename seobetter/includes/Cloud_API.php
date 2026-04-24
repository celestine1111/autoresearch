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
     * v1.5.211 — HMAC signing secret for cloud-api requests.
     *
     * This is NOT cryptographic security against a determined attacker — PHP
     * source is visible per WP.org rules. What it IS:
     *   - Stops random scripts that discover the Vercel endpoints from burning
     *     Ben's Serper/Firecrawl/Pexels/OpenRouter quotas
     *   - Creates per-installation signal so Vercel can rate-limit per site_url
     *   - Rotates per release (server accepts multiple active secrets during
     *     a 7-day rotation window) so old installs keep working briefly after
     *     the secret changes, but long-lived cracked copies stop working
     *
     * When Freemius ships (Phase 1 per pro-plan-pricing.md §7), the real
     * cryptographic signing secret becomes the per-site license key + domain
     * pair. This constant is the pre-Freemius stop-gap.
     *
     * Format: base64(random 32 bytes). Vercel env var `SEOBETTER_SIGNING_SECRETS`
     * is a comma-separated list of currently-accepted plaintext secrets.
     */
    private const SIGNING_SECRET = 'c2ItdjEtNzI4NGZlNGMtYjJiZi00MmMzLWE0NzktZGE0NGVkNjVmYmJl';

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
     * v1.5.211 — Sign a cloud-api request body with HMAC-SHA256.
     * Used by every wp_remote_post() call to a seobetter.vercel.app endpoint
     * so the Vercel server can verify the request came from a legitimate
     * plugin install (not a random script).
     *
     * @param array $body Request body (will be JSON-encoded before signing).
     * @return array{url: string, body: string, headers: array} Pass to wp_remote_post().
     */
    public static function sign_request( string $endpoint, array $body ): array {
        $time    = (string) time();
        $site    = home_url();
        $tier    = License_Manager::is_pro() ? 'pro' : 'free';
        $version = defined( 'SEOBETTER_VERSION' ) ? SEOBETTER_VERSION : 'dev';

        // v1.5.211-hotfix — use JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        // so the signed bytes match what Node.js reconstructs server-side.
        // Without these flags, PHP's default json_encode outputs `"https:\/\/x"`
        // while Node's JSON.stringify outputs `"https://x"`. Same logical data,
        // different bytes, different HMAC → 401. Same issue with non-ASCII
        // characters (PHP escapes to \uXXXX, Node doesn't).
        $body_json = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $payload   = "{$time}.{$site}.{$tier}.{$body_json}";
        $secret    = base64_decode( self::SIGNING_SECRET );
        $sig       = hash_hmac( 'sha256', $payload, $secret );

        return [
            'url'     => self::get_cloud_url() . $endpoint,
            'body'    => $body_json,
            'headers' => [
                'Content-Type'         => 'application/json',
                'X-Seobetter-Sig'      => 'sha256=' . $sig,
                'X-Seobetter-Time'     => $time,
                'X-Seobetter-Site'     => $site,
                'X-Seobetter-Tier'     => $tier,
                'X-Seobetter-Version'  => $version,
            ],
        ];
    }

    /**
     * v1.5.211 — Convenience wrapper: sign + wp_remote_post in one call.
     * Returns the same shape as wp_remote_post() so callers can continue using
     * wp_remote_retrieve_* helpers.
     *
     * @param string $endpoint Path (e.g. '/api/research', '/api/generate').
     * @param array  $body     Request body array (auto-JSON-encoded).
     * @param array  $args     Extra wp_remote_post args (timeout, etc.).
     * @return array|\WP_Error wp_remote_post() result.
     */
    public static function signed_post( string $endpoint, array $body, array $args = [] ) {
        $signed = self::sign_request( $endpoint, $body );
        return wp_remote_post( $signed['url'], array_merge( [
            'timeout' => 60,
            'headers' => $signed['headers'],
            'body'    => $signed['body'],
        ], $args ) );
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

        // Use SEOBetter Cloud — v1.5.211: signed request
        $license = get_option( 'seobetter_license', [] );

        $response = self::signed_post( '/api/generate', [
            'prompt'         => $prompt,
            'system_prompt'  => $system_prompt,
            'max_tokens'     => $options['max_tokens'] ?? 4096,
            'temperature'    => $options['temperature'] ?? 0.7,
            'site_url'       => home_url(),
            'license_key'    => $license['key'] ?? '',
            'plugin_version' => SEOBETTER_VERSION,
        ], [ 'timeout' => 120 ] );

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
