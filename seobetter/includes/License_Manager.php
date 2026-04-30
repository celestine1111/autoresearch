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
     *
     * v1.5.216.21 — REFACTORED for the 3-tier paid model locked 2026-04-29.
     * Previously a 2-tier model (Free + Pro) with a v1.5.13 testing override
     * that moved 7 Pro features into Free for end-to-end testing. Now:
     *
     *   - 4 tiers total: Free / Pro / Pro+ / Agency (per pro-features-ideas.md §2)
     *   - License gating master switch via SEOBETTER_GATE_LIVE constant
     *     (default false during Phase 1) — when false, can_use() returns true
     *     for ALL features so Ben can test as if Agency-licensed
     *   - Backward-compat alias: is_pro() returns true if user has any
     *     paid tier (Pro, Pro+, or Agency)
     *   - Features at higher tiers automatically include lower tier features
     *     (Agency users get everything Pro+ has, etc.)
     *
     * Source of truth for the matrix is `seo-guidelines/pro-features-ideas.md`
     * §2 Tier Matrix. Any change here must be reflected there.
     */
    private const FREE_FEATURES = [
        // Generation core
        'ai_content_generator',           // BYOK unlimited
        'ai_outline_generator',
        'single_ai_provider',
        'pexels_images',
        // Quality / scoring (local — zero marginal cost)
        'geo_analyzer',
        'seobetter_score',                // composite 0-100 (Phase 1 item 7)
        'humanizer',
        'readability',
        // Schema (basic only)
        'schema_article',
        'schema_breadcrumb',
        'schema_faq',
        // SEO plugin sync (basic — zero cost)
        'meta_sync_basic',                // title + desc + OG + canonical to Yoast/RankMath/AIOSEO/SEOPress
        // Tools (free tier baselines)
        'editor_sidebar',
        'llms_txt_basic',                 // basic llms.txt; optimized version is Pro
        'rich_results_preview',
        'social_meta',
        'image_analyzer',
        'tech_auditor',
        'seo_checklist',
        'osm_places',                     // Tier 1 of Places waterfall
        'jina_reader',                    // free fallback
        'gsc_connect',                    // GSC OAuth + view dashboard (Phase 1 item 3)
        'internal_links_orphan',          // orphan-pages report (Phase 1 item 5)
        'freshness_report',               // age-based only
        'ai_crawler_audit',               // Phase 1 item 18
        // Content types — only 3 in Free
        'content_type_blog_post',
        'content_type_how_to',
        'content_type_listicle',
        // Country localization — 6 EN-speaking only
        'country_en_speaking',            // US/UK/AU/CA/NZ/IE (zero LLM cost)
        // AI Citation Tracker minimal (1 prompt × Perplexity × monthly)
        'citation_tracker_minimal',
    ];

    private const PRO_FEATURES = [
        // Cloud generation
        'cloud_generation',               // 50 articles/mo via SEOBetter Cloud
        // Generation features
        'all_21_content_types',           // unlocks the other 18 types
        'multilingual_60_languages',
        'country_localization_80',        // full 80+ countries with Regional_Context
        'ai_featured_image',              // Pollinations / OpenRouter / Gemini Nano Banana
        'inline_citations',               // Citation Pool clickable markdown links
        // Schema (advanced)
        'schema_advanced',                // Recipe wrapper, Speakable, citation[], TechArticle, ScholarlyArticle
        'schema_auto_detect',             // LocalBusiness, Organization, Product, Event, Course, Video, etc.
        'schema_howto',                   // legacy key, still kept
        'schema_product',
        'schema_localbusiness',
        'schema_review',
        'schema_event',
        'schema_video',
        'schema_blocks_5',                // 5 Schema Blocks (Phase 1 item 10)
        // Research stack
        'firecrawl_deep_research',
        'brave_search',
        'serper_serp',
        'sonar_pro_tier0',                // Perplexity Sonar Pro
        'places_tier_2_3_4',              // Foursquare + HERE + Google Places
        // SEO plugin
        'aioseo_full_schema',             // AIOSEO custom-table schema (Yoast/RankMath/SEOPress get meta sync free)
        // Quality
        'brand_voice_1',                  // 1 voice
        // AI Citation Tracker
        'citation_tracker_pro',           // 1 prompt × 4 engines × weekly
        // llms.txt
        'llms_txt_optimized',             // content-type categorization, GEO-score filtering, custom summary
        // Power
        'remove_footer_link',
        'priority_support',
        // Legacy keys kept for backward compat with code that still references them
        'unlimited_cloud_generation',
        'geo_optimizer',
        'snippet_optimizer',
        'unlimited_ai_providers',
        'content_export',
    ];

    private const PROPLUS_FEATURES = [
        // Increased capacity
        'cloud_generation_pro_plus',      // 100 articles/mo
        'sites_3',                         // 3 site licenses
        // Quality
        'brand_voice_3',                   // 3 voices
        // GSC + Freshness
        'gsc_freshness_driver',            // GSC-driven Freshness inventory prioritization
        // Internal Links
        'internal_links_suggester',        // editor sidebar suggester (5/post)
        // AI Citation Tracker scaled
        'citation_tracker_pro_plus',       // 5 prompts × 4 engines × weekly
        // WooCommerce
        'woocommerce_category_intros',     // 5/site lifetime
        // llms.txt
        'llms_txt_full',                   // /llms-full.txt comprehensive content dump
        'llms_txt_multilingual',           // per-language /en/llms.txt etc.
        'llms_txt_custom_editor',          // user override
        // Content
        'content_brief_unlimited',         // free tier gets 3/mo; Pro+ unlimited
        'content_refresher',
    ];

    private const AGENCY_FEATURES = [
        // Increased capacity
        'cloud_generation_agency',        // 250 articles/mo
        'sites_10',                        // 10 site licenses
        'seats_5',                         // 5 team seats
        // Bulk
        'bulk_content_generation',        // CSV import 50/day, GEO floor 40, default-to-draft
        // Brand Voice unlimited + per-language
        'brand_voice_unlimited',
        // Internal Links unlimited + auto-rules
        'internal_links_unlimited',
        // WooCommerce
        'woocommerce_intros_unlimited',
        'woocommerce_product_rewriter',
        // Power Pro features
        'cannibalization_detector',
        'refresh_brief_generator',        // diff suggestions, never auto-rewrite
        'gsc_indexing_api',
        // White-label (basic — replace logo, hide footer, custom email sender)
        'white_label',
        'white_label_basic',
        // API access
        'api_access',                     // n8n/Zapier/custom triggers
        // Custom prompts
        'custom_prompt_templates',
        // AI Citation Tracker max
        'citation_tracker_agency',        // 25 prompts × 4 engines × weekly
        // Support
        'priority_support_24h',
        'onboarding_call',
        // Legacy keys (Pro features that were originally listed here)
        'decay_alerts',                   // killed in v1.5.206d-fix17 but key kept for back-compat
        'ahrefs_integration',
        'gsc_integration',                // legacy alias for gsc_connect (Free) + gsc_freshness_driver (Pro+) + gsc_indexing_api (Agency)
        'ga_integration',
    ];

    /**
     * Free tier monthly limits.
     */
    private const FREE_MONTHLY_LIMIT = 5; // articles per month via Cloud

    /**
     * Check if a feature is available in the current tier.
     *
     * v1.5.216.21 — 3-tier paid model + GATE_LIVE master switch.
     *
     * Resolution order:
     *   1. SEOBETTER_GATE_LIVE = false → ALWAYS return true (Phase 1 testing mode)
     *   2. Free features → always true
     *   3. Pro features → require Pro / Pro+ / Agency
     *   4. Pro+ features → require Pro+ / Agency
     *   5. Agency features → require Agency
     *   6. Unknown feature → false (fail closed for safety)
     */
    public static function can_use( string $feature ): bool {
        // Master switch — Phase 1 testing override
        if ( ! SEOBETTER_GATE_LIVE ) {
            return true;
        }
        if ( in_array( $feature, self::FREE_FEATURES, true ) ) {
            return true;
        }
        if ( in_array( $feature, self::PRO_FEATURES, true ) ) {
            return self::is_pro();
        }
        if ( in_array( $feature, self::PROPLUS_FEATURES, true ) ) {
            return self::is_pro_plus();
        }
        if ( in_array( $feature, self::AGENCY_FEATURES, true ) ) {
            return self::is_agency();
        }
        // Unknown feature — fail closed (don't accidentally unlock things)
        return false;
    }

    /**
     * Get the minimum tier required to use a feature. Used by UI lock badges
     * even when SEOBETTER_GATE_LIVE = false (so users SEE what's locked
     * without being blocked from testing it).
     *
     * Returns one of: 'free' / 'pro' / 'pro_plus' / 'agency' / 'unknown'.
     */
    public static function get_required_tier( string $feature ): string {
        if ( in_array( $feature, self::FREE_FEATURES, true ) )    return 'free';
        if ( in_array( $feature, self::PRO_FEATURES, true ) )     return 'pro';
        if ( in_array( $feature, self::PROPLUS_FEATURES, true ) ) return 'pro_plus';
        if ( in_array( $feature, self::AGENCY_FEATURES, true ) )  return 'agency';
        return 'unknown';
    }

    /**
     * Check if the current installation has an active paid license at any tier
     * (Pro / Pro+ / Agency). Used as a backward-compat shortcut for code that
     * just needs "is this user paid?" Cached for 24h.
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
     * v1.5.216.21 — Check if user is on Pro+ tier or higher (Pro+ OR Agency).
     */
    public static function is_pro_plus(): bool {
        if ( ! self::is_pro() ) return false;
        $tier = self::get_active_tier();
        return in_array( $tier, [ 'pro_plus', 'agency' ], true );
    }

    /**
     * v1.5.216.21 — Check if user is on Agency tier specifically.
     */
    public static function is_agency(): bool {
        if ( ! self::is_pro() ) return false;
        return self::get_active_tier() === 'agency';
    }

    /**
     * v1.5.216.30 — Phase 1 item 11: Free-tier country allowlist.
     *
     * Free tier supports 6 English-speaking countries where the default AI
     * prompt already produces good output (no Regional_Context block fires).
     * Pro+ ($69/mo) and Agency ($179/mo) unlock the full 80+ country list,
     * which routes through Regional_Context for per-country authority
     * sources, currency, date format, and editorial register conventions.
     *
     * Empty string (Global / no country filter) is always allowed — it's
     * the absence of a country selection, not a tier-locked one.
     *
     * Source of truth — keep in sync with:
     *   - Regional_Context::WESTERN_DEFAULT_COUNTRIES
     *   - rest_generate_start() $free_countries inline array
     *   - admin/views/content-generator.php sbFreeCountries JS array
     */
    public const FREE_COUNTRIES = [ 'US', 'GB', 'AU', 'CA', 'NZ', 'IE' ];

    /**
     * Whether the current license is allowed to use the supplied country.
     * Free 6 + Global ('') are universally allowed. Anything else requires
     * the `country_localization_80` Pro+ feature.
     *
     * @param string $country_code ISO 2-letter code (or '' for Global)
     */
    public static function is_country_allowed( string $country_code ): bool {
        $code = strtoupper( trim( $country_code ) );
        if ( $code === '' ) return true;
        if ( in_array( $code, self::FREE_COUNTRIES, true ) ) return true;
        return self::can_use( 'country_localization_80' );
    }

    /**
     * v1.5.216.21 — Get the user's actual paid tier as one of:
     * 'free' / 'pro' / 'pro_plus' / 'agency'.
     *
     * Internally we also track license type (subscription vs lifetime) but
     * NEVER expose "lifetime" / "LTD" externally per the locked design
     * decision — AppSumo LTD buyers see the same tier badge as subscription
     * buyers. See pro-features-ideas.md §3 item 16.
     */
    public static function get_active_tier(): string {
        $license = get_option( self::OPTION_KEY, [] );
        if ( empty( $license['is_active'] ) ) return 'free';
        $type = (string) ( $license['type'] ?? 'pro_subscription' );
        // Map internal license type → display tier
        if ( str_starts_with( $type, 'agency_' ) )   return 'agency';
        if ( str_starts_with( $type, 'pro_plus_' ) ) return 'pro_plus';
        if ( str_starts_with( $type, 'pro_' ) )      return 'pro';
        // Legacy: existing pre-v1.5.216.21 licenses with no `type` field
        // default to 'pro'. New activations always set the type field.
        return 'pro';
    }

    // ====================================================================
    // v1.5.216.34 — Phase 1 item 15: License tier display logic.
    //
    // Internal license types tracked in the `type` field of the option:
    //   free / pro_subscription / pro_plus_subscription / agency_subscription
    //   pro_lifetime / pro_plus_lifetime / agency_lifetime
    //
    // The internal types feed three INTERNAL-ONLY decisions:
    //   1. Cloud cap enforcement — LTD buyers have hard monthly caps per
    //      `pro-features-ideas.md §5` (5/15/30/75/150). Subscription tiers
    //      have unlimited Cloud (within published quotas) per §2.
    //   2. Cheap-config-forced flag — LTD Cloud articles MUST use cheap
    //      config (gpt-4.1-mini extraction only) to keep margin sustainable
    //      across 5-year lifetime exposure.
    //   3. Cloud Credits eligibility — LTD buyers exceeding caps can buy
    //      credit packs to top up; subscription buyers can't (already
    //      unlimited within their tier).
    //
    // None of these surface to the UI as "LTD" / "Lifetime" badges. Public
    // tier name from get_active_tier() is the only thing users see.
    // ====================================================================

    /**
     * All recognised internal license type strings. Anything else stored
     * in the `type` field is normalised to 'pro' display via legacy fallback.
     */
    public const LICENSE_TYPES = [
        'free',
        'pro_subscription',
        'pro_plus_subscription',
        'agency_subscription',
        'pro_lifetime',
        'pro_plus_lifetime',
        'agency_lifetime',
    ];

    /**
     * AppSumo 5-tier LTD ladder — monthly Cloud cap per tier.
     * Sourced from `pro-features-ideas.md §5` 5-tier ladder. Mapped from
     * the internal license type used at activation. New LTD types may
     * specialise these by appending a suffix (`pro_lifetime_t1`) — fall
     * back to the base type's cap.
     */
    private const LTD_CLOUD_CAPS = [
        'pro_lifetime'      => 15,   // Tier 2 ($129) baseline — also matches free++ (Tier 1) when appsumo_tier=1
        'pro_plus_lifetime' => 30,   // Tier 3 ($249)
        'agency_lifetime'   => 75,   // Tier 4 ($349) baseline — Tier 5 ($499) gets 150 via appsumo_tier=5 stored field
    ];

    /**
     * Get the precise internal license type. Returns 'free' when no
     * active license. Used by the billing system, Cloud cap enforcement,
     * and the cheap-config gate.
     *
     * NEVER call this from UI code — UI calls `get_active_tier()`.
     */
    public static function get_license_type_internal(): string {
        $license = get_option( self::OPTION_KEY, [] );
        if ( empty( $license['is_active'] ) ) return 'free';
        $type = (string) ( $license['type'] ?? 'pro_subscription' );
        return in_array( $type, self::LICENSE_TYPES, true ) ? $type : 'pro_subscription';
    }

    /**
     * Whether the active license is a lifetime (AppSumo LTD) deal.
     * Used by Cloud cap enforcement and cheap-config gating.
     */
    public static function is_lifetime(): bool {
        return str_ends_with( self::get_license_type_internal(), '_lifetime' );
    }

    /**
     * Whether the active license is a recurring subscription. Mutually
     * exclusive with `is_lifetime()` (free returns false for both).
     */
    public static function is_subscription(): bool {
        return str_ends_with( self::get_license_type_internal(), '_subscription' );
    }

    /**
     * Monthly Cloud article cap for the active license. Returns -1 to
     * signal "unlimited" (subscription tiers within published quota).
     * 0 for free tier (no Cloud — must use BYOK).
     *
     * LTD tiers return their hard cap from the AppSumo ladder; subscription
     * tiers return -1 (handled by tier-specific quota in Cloud_API).
     */
    public static function get_cloud_cap(): int {
        $type = self::get_license_type_internal();
        if ( $type === 'free' ) return 0;
        if ( str_ends_with( $type, '_subscription' ) ) return -1;

        // LTD: read AppSumo tier from license option (1-5 ladder). When
        // unset, default to the base cap for the license type.
        $license = get_option( self::OPTION_KEY, [] );
        $appsumo_tier = (int) ( $license['appsumo_tier'] ?? 0 );

        // 5-tier ladder per pro-features-ideas.md §5
        $ladder_caps = [ 1 => 5, 2 => 15, 3 => 30, 4 => 75, 5 => 150 ];
        if ( $appsumo_tier >= 1 && $appsumo_tier <= 5 ) {
            return $ladder_caps[ $appsumo_tier ];
        }

        return self::LTD_CLOUD_CAPS[ $type ] ?? 0;
    }

    /**
     * Whether the user must use cheap-config (gpt-4.1-mini extraction)
     * for Cloud articles. True for LTD buyers — keeps lifetime exposure
     * sustainable per `pro-features-ideas.md §5` margin model. False for
     * subscription buyers (their MRR funds Sonnet/Opus extraction).
     *
     * Free tier never reaches Cloud (forced BYOK); flag is moot.
     */
    public static function should_force_cheap_config(): bool {
        return self::is_lifetime();
    }

    /**
     * Number of site activations allowed by the active license. From the
     * AppSumo LTD ladder (1/3/5/10/25) for lifetime buyers, or the
     * `sites_*` feature for subscription tiers. Used by license activation
     * to check site count before binding.
     */
    public static function get_sites_allowed(): int {
        if ( self::is_lifetime() ) {
            $license = get_option( self::OPTION_KEY, [] );
            $appsumo_tier = (int) ( $license['appsumo_tier'] ?? 0 );
            $ladder_sites = [ 1 => 1, 2 => 3, 3 => 5, 4 => 10, 5 => 25 ];
            return $ladder_sites[ $appsumo_tier ] ?? 1;
        }
        // Subscription: tier-implicit quota (Pro 1, Pro+ 1, Agency 10)
        if ( self::can_use( 'sites_10' ) ) return 10;
        return 1;
    }

    /**
     * Activate a license key.
     */
    public static function activate( string $key ): array {
        $key = sanitize_text_field( trim( $key ) );

        if ( empty( $key ) ) {
            return [ 'success' => false, 'message' => 'License key is required.' ];
        }

        // v1.5.216.34 — Phase 1 item 15: Dev test keys for each tier so Ben
        // can exercise the full UI matrix locally. Only honoured when
        // WP_DEBUG is on. The display tier in the UI matches the type field.
        // LTD test keys store appsumo_tier so cap/sites helpers return the
        // correct ladder values.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $dev_keys = [
                'SEOBETTER-DEV-PRO'           => [ 'type' => 'pro_subscription' ],
                'SEOBETTER-DEV-PRO-PLUS'      => [ 'type' => 'pro_plus_subscription' ],
                'SEOBETTER-DEV-AGENCY'        => [ 'type' => 'agency_subscription' ],
                'SEOBETTER-DEV-LTD-T1'        => [ 'type' => 'pro_lifetime',      'appsumo_tier' => 1 ],
                'SEOBETTER-DEV-LTD-T2'        => [ 'type' => 'pro_lifetime',      'appsumo_tier' => 2 ],
                'SEOBETTER-DEV-LTD-T3'        => [ 'type' => 'pro_plus_lifetime', 'appsumo_tier' => 3 ],
                'SEOBETTER-DEV-LTD-T4'        => [ 'type' => 'agency_lifetime',   'appsumo_tier' => 4 ],
                'SEOBETTER-DEV-LTD-T5'        => [ 'type' => 'agency_lifetime',   'appsumo_tier' => 5 ],
            ];
            if ( isset( $dev_keys[ $key ] ) ) {
                $stored = array_merge( [
                    'key'         => $key,
                    'is_active'   => true,
                    'activated'   => current_time( 'mysql' ),
                ], $dev_keys[ $key ] );
                // Subscriptions get a far-future valid_until; LTD keys never expire
                if ( str_ends_with( $stored['type'], '_subscription' ) ) {
                    $stored['valid_until'] = time() + YEAR_IN_SECONDS;
                }
                update_option( self::OPTION_KEY, $stored );
                return [
                    'success' => true,
                    'message' => sprintf(
                        /* translators: %s: license type internal slug */
                        __( 'Development license activated (%s).', 'seobetter' ),
                        $stored['type']
                    ),
                ];
            }
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
        // v1.5.216 — Free tier is now BYOK-only. Cloud generation requires Pro.
        // Pre-fix: free tier had 5 Cloud articles/month, which created open-ended
        // financial exposure for the plugin owner at scale (5,000 installs ×
        // premium config = $2,000+/mo). Solo founders can't sustain that pre-
        // launch. New model: free tier is fully functional via BYOK (user's
        // own OpenRouter/Anthropic/OpenAI/Gemini key — they pay their provider
        // directly, plugin owner pays $0). Pro tier ($39/mo) is the Cloud value
        // prop. Mirrors Yoast/RankMath/AIOSEO pattern: full free SEO tooling,
        // paid AI generation. WP.org-compliant + financially sustainable.
        // Cloud articles will be re-introduced as a goodwill bonus (3/mo) once
        // Pro MRR comfortably covers infra costs — see pro-plan-pricing.md §7
        // Phase 5 (post-AppSumo).

        // BYOK path: any user (free or Pro) with their own provider key
        // generates unlimited articles paying their provider directly.
        $provider = AI_Provider_Manager::get_active_provider();
        if ( $provider ) {
            return [ 'allowed' => true, 'source' => 'byok', 'remaining' => 'unlimited' ];
        }

        // Cloud path: Pro only.
        if ( self::is_pro() ) {
            return [ 'allowed' => true, 'source' => 'cloud', 'remaining' => 'unlimited' ];
        }

        // Free tier without BYOK: deny with upgrade-or-BYOK message.
        return [
            'allowed'   => false,
            'source'    => 'cloud',
            'remaining' => 0,
            'message'   => 'Free tier requires you to connect your own AI API key (OpenRouter / Anthropic / OpenAI / Gemini / Groq) — you pay your provider directly, no SEOBetter Cloud cost. Or upgrade to Pro ($39/mo) for Cloud generation included.',
        ];
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
        $tier = self::get_active_tier();

        // Display label — capitalized; Pro+ has the "+" preserved
        $tier_label_map = [
            'free'     => 'Free',
            'pro'      => 'Pro',
            'pro_plus' => 'Pro+',
            'agency'   => 'Agency',
        ];

        return [
            'is_pro'      => self::is_pro(),
            'is_pro_plus' => self::is_pro_plus(),
            'is_agency'   => self::is_agency(),
            'tier'        => $tier,                                 // machine: free / pro / pro_plus / agency
            'tier_label'  => $tier_label_map[ $tier ] ?? 'Free',     // display: Free / Pro / Pro+ / Agency
            'key'         => ! empty( $license['key'] ) ? self::mask_key( $license['key'] ) : '',
            'activated'   => $license['activated'] ?? '',
            'gate_live'   => SEOBETTER_GATE_LIVE,                    // for diagnostic in Settings UI
        ];
    }

    /**
     * Get all features with their availability status across all 4 tiers.
     */
    public static function get_feature_list(): array {
        $features = [];

        foreach ( self::FREE_FEATURES as $f ) {
            $features[ $f ] = [ 'tier' => 'free', 'available' => true ];
        }
        foreach ( self::PRO_FEATURES as $f ) {
            $features[ $f ] = [ 'tier' => 'pro', 'available' => self::is_pro() ];
        }
        foreach ( self::PROPLUS_FEATURES as $f ) {
            $features[ $f ] = [ 'tier' => 'pro_plus', 'available' => self::is_pro_plus() ];
        }
        foreach ( self::AGENCY_FEATURES as $f ) {
            $features[ $f ] = [ 'tier' => 'agency', 'available' => self::is_agency() ];
        }

        return $features;
    }

    /**
     * Validate license key against the licensing server.
     * v1.5.211 — request now HMAC-signed via Cloud_API::signed_post().
     */
    private static function validate_license( string $key ): bool {
        $response = Cloud_API::signed_post( '/api/validate', [
            'license_key'    => $key,
            'site_url'       => home_url(),
            'plugin_version' => SEOBETTER_VERSION,
        ], [ 'timeout' => 15 ] );

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
