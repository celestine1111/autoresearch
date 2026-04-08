<?php

namespace SEOBetter;

/**
 * License Manager — Free/Pro tier gating.
 *
 * Free features (available to all):
 * - AI Content Generator (5 articles/month via SEOBetter Cloud, or BYOK unlimited)
 * - AI Content Outline Generator
 * - GEO Analyzer (score + suggestions)
 * - Technical SEO Auditor (site-wide + per-post)
 * - SEO Checklist (40+ points)
 * - Schema Generator (Article, Breadcrumb, FAQ)
 * - Image SEO Analyzer
 * - Content Freshness Manager (report only)
 * - Readability scoring
 * - Gutenberg sidebar with GEO Score
 * - llms.txt generator
 * - Social Meta Generator (Open Graph + Twitter Cards)
 * - 1 AI provider connection (BYOK)
 *
 * Pro features ($59-99/yr):
 * - Unlimited AI content generation via SEOBetter Cloud
 * - GEO Optimizer (auto-enrich with quotes, stats, citations)
 * - Featured Snippet Optimizer
 * - Schema Generator (HowTo, Product, LocalBusiness, Review, Event, Video)
 * - Content Freshness auto-refresh suggestions
 * - Keyword cannibalization detector
 * - Internal link suggestions
 * - Unlimited AI provider connections (BYOK)
 * - Bulk content generation (CSV keyword import)
 * - Priority support
 * - Future: Ahrefs API integration
 * - Future: Google Search Console integration
 * - Future: Google Analytics integration
 */
class License_Manager {

    private const OPTION_KEY = 'seobetter_license';
    /**
     * Get the license validation URL (uses same cloud API).
     */
    private static function get_validation_url(): string {
        return Cloud_API::get_cloud_url() . '/api/validate';
    }

    /**
     * Feature tier definitions.
     */
    private const FREE_FEATURES = [
        'ai_content_generator',
        'ai_outline_generator',
        'geo_analyzer',
        'tech_auditor',
        'seo_checklist',
        'schema_article',
        'schema_breadcrumb',
        'schema_faq',
        'image_analyzer',
        'freshness_report',
        'readability',
        'editor_sidebar',
        'llms_txt',
        'social_meta',
        'single_ai_provider',
    ];

    private const PRO_FEATURES = [
        'unlimited_cloud_generation',
        'geo_optimizer',
        'snippet_optimizer',
        'schema_howto',
        'schema_product',
        'schema_localbusiness',
        'schema_review',
        'schema_event',
        'schema_video',
        'freshness_suggestions',
        'cannibalization_detector',
        'internal_link_suggestions',
        'unlimited_ai_providers',
        'bulk_content_generation',
        'citation_tracker',
        'content_refresh',
        'content_brief',
        'content_export',
        'decay_alerts',
        'white_label',
        'ahrefs_integration',
        'gsc_integration',
        'ga_integration',
        'priority_support',
    ];

    /**
     * Free tier monthly limits.
     */
    private const FREE_MONTHLY_LIMIT = 5; // articles per month via Cloud

    /**
     * Check if a feature is available in the current tier.
     */
    public static function can_use( string $feature ): bool {
        if ( in_array( $feature, self::FREE_FEATURES, true ) ) {
            return true;
        }
        if ( in_array( $feature, self::PRO_FEATURES, true ) ) {
            return self::is_pro();
        }
        return false;
    }

    /**
     * Check if the current installation has an active Pro license.
     */
    public static function is_pro(): bool {
        $license = get_option( self::OPTION_KEY, [] );

        if ( empty( $license['key'] ) ) {
            return false;
        }

        // Check cached validation (valid for 24 hours)
        if ( ! empty( $license['valid_until'] ) && time() < $license['valid_until'] ) {
            return (bool) ( $license['is_active'] ?? false );
        }

        // Re-validate
        return self::validate_license( $license['key'] );
    }

    /**
     * Activate a license key.
     */
    public static function activate( string $key ): array {
        $key = sanitize_text_field( trim( $key ) );

        if ( empty( $key ) ) {
            return [ 'success' => false, 'message' => 'License key is required.' ];
        }

        // For development/testing: accept a test key
        if ( $key === 'SEOBETTER-DEV-PRO' && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            update_option( self::OPTION_KEY, [
                'key'         => $key,
                'is_active'   => true,
                'tier'        => 'pro',
                'valid_until' => time() + DAY_IN_SECONDS,
                'activated'   => current_time( 'mysql' ),
            ] );
            return [ 'success' => true, 'message' => 'Development Pro license activated.' ];
        }

        $valid = self::validate_license( $key );

        if ( $valid ) {
            return [ 'success' => true, 'message' => 'Pro license activated successfully!' ];
        }

        return [ 'success' => false, 'message' => 'Invalid or expired license key.' ];
    }

    /**
     * Deactivate the current license.
     */
    public static function deactivate(): void {
        delete_option( self::OPTION_KEY );
    }

    /**
     * Check if user can generate content (has remaining cloud credits or own API key).
     */
    public static function can_generate(): array {
        // If user has their own API key configured, always allow
        $provider = AI_Provider_Manager::get_active_provider();
        if ( $provider ) {
            return [ 'allowed' => true, 'source' => 'byok', 'remaining' => 'unlimited' ];
        }

        // Cloud generation: check monthly limit
        $usage = self::get_monthly_usage();
        $limit = self::is_pro() ? PHP_INT_MAX : self::FREE_MONTHLY_LIMIT;
        $remaining = max( 0, $limit - $usage );

        if ( $remaining <= 0 ) {
            return [
                'allowed'   => false,
                'source'    => 'cloud',
                'remaining' => 0,
                'message'   => 'Monthly free limit reached (' . self::FREE_MONTHLY_LIMIT . ' articles). Connect your own AI API key or upgrade to Pro for unlimited.',
            ];
        }

        return [ 'allowed' => true, 'source' => 'cloud', 'remaining' => self::is_pro() ? 'unlimited' : $remaining ];
    }

    /**
     * Record a cloud generation usage.
     */
    public static function record_usage(): void {
        $key = 'seobetter_usage_' . date( 'Y_m' );
        $count = (int) get_option( $key, 0 );
        update_option( $key, $count + 1, false );
    }

    /**
     * Get current month's cloud generation count.
     */
    public static function get_monthly_usage(): int {
        $key = 'seobetter_usage_' . date( 'Y_m' );
        return (int) get_option( $key, 0 );
    }

    /**
     * Get current license info.
     */
    public static function get_info(): array {
        $license = get_option( self::OPTION_KEY, [] );

        return [
            'is_pro'     => self::is_pro(),
            'tier'       => self::is_pro() ? 'pro' : 'free',
            'key'        => ! empty( $license['key'] ) ? self::mask_key( $license['key'] ) : '',
            'activated'  => $license['activated'] ?? '',
        ];
    }

    /**
     * Get all features with their availability status.
     */
    public static function get_feature_list(): array {
        $features = [];

        foreach ( self::FREE_FEATURES as $f ) {
            $features[ $f ] = [ 'tier' => 'free', 'available' => true ];
        }
        foreach ( self::PRO_FEATURES as $f ) {
            $features[ $f ] = [ 'tier' => 'pro', 'available' => self::is_pro() ];
        }

        return $features;
    }

    /**
     * Validate license key against the licensing server.
     */
    private static function validate_license( string $key ): bool {
        $response = wp_remote_post( self::get_validation_url(), [
            'timeout' => 15,
            'body'    => [
                'license_key' => $key,
                'site_url'    => home_url(),
                'plugin_version' => SEOBETTER_VERSION,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            // Network error — use cached status with 48-hour grace period
            $cached = get_option( self::OPTION_KEY, [] );
            $grace_period = 2 * DAY_IN_SECONDS;
            $last_validated = $cached['valid_until'] ?? 0;

            // If within grace period, trust cached status; otherwise fall back to free
            if ( time() < ( $last_validated + $grace_period ) ) {
                return (bool) ( $cached['is_active'] ?? false );
            }
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $is_active = ! empty( $body['valid'] );

        update_option( self::OPTION_KEY, [
            'key'         => $key,
            'is_active'   => $is_active,
            'tier'        => $is_active ? 'pro' : 'free',
            'valid_until' => time() + DAY_IN_SECONDS,
            'activated'   => current_time( 'mysql' ),
        ] );

        return $is_active;
    }

    private static function mask_key( string $key ): string {
        if ( strlen( $key ) <= 8 ) {
            return str_repeat( '*', strlen( $key ) );
        }
        return substr( $key, 0, 4 ) . str_repeat( '*', strlen( $key ) - 8 ) . substr( $key, -4 );
    }
}
