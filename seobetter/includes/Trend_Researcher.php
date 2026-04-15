<?php

namespace SEOBetter;

/**
 * Trend Researcher — Last30Days Integration.
 *
 * Calls the Last30Days Python research engine to pull real-time data
 * from Reddit, X/Twitter, YouTube, TikTok, Instagram, HN, Polymarket,
 * and web search for the last 30 days.
 *
 * Replaces AI-hallucinated "recent statistics" with actual web data.
 * Falls back to the AI-based trend generation if Last30Days is not available.
 */
class Trend_Researcher {

    /**
     * Path to the Last30Days script (relative to plugin dir).
     */
    private const SCRIPT_PATH = '.agents/skills/last30days/scripts/last30days.py';

    /**
     * Cache duration for research results (6 hours).
     */
    private const CACHE_TTL = 21600;
    // v1.5.34 — bump this when the research response shape changes so stale
    // cached entries from older plugin versions are never hit after an upgrade.
    // Bumping this version invalidates ALL existing seobetter_trends_* transients
    // without requiring a manual DB cleanup.
    // v7 — v1.5.26 added the `places` array, v1.5.30 added Sonar tier, v1.5.34
    // fixes stale caches from pre-v1.5.26 deployments still hitting old shape.
    private const CACHE_VERSION = 'v7';

    /**
     * Check if Last30Days is available.
     */
    public static function is_available(): bool {
        $script = SEOBETTER_PLUGIN_DIR . self::SCRIPT_PATH;
        if ( ! file_exists( $script ) ) {
            return false;
        }

        // Check if Python3 is available
        $python = shell_exec( 'which python3 2>/dev/null' );
        return ! empty( trim( $python ?? '' ) );
    }

    /**
     * Research a topic and return formatted data for article injection.
     *
     * Priority: Vercel cloud → Local Last30Days → AI fallback
     *
     * @param string $keyword Primary keyword to research.
     * @param string $type    Research type: 'general', 'recommendations', 'comparison', 'news'.
     * @return array Research results with stats, quotes, trends, and sources.
     */
    public static function research( string $keyword, string $type = 'general', string $country = '' ): array {
        // v1.5.34 — cache key includes a schema version so upgrading the
        // plugin automatically invalidates all stale cached research results.
        // Also validate the cached shape: if it lacks the v1.5.26+ fields
        // (is_local_intent, places, places_count), treat it as a cache miss
        // and re-fetch. This protects against WP object caches that might
        // ignore transient expiry.
        $cache_key = 'seobetter_trends_' . self::CACHE_VERSION . '_' . md5( $keyword . $type . $country );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['is_local_intent'] ) && array_key_exists( 'places', $cached ) ) {
            return $cached;
        }
        // If an old-format cache entry is still here, delete it so we re-fetch
        if ( $cached ) {
            delete_transient( $cache_key );
        }

        // 1. Try Vercel cloud endpoint (works everywhere, no Python needed)
        $result = self::cloud_research( $keyword, $type, $country );
        if ( $result['success'] && ! empty( $result['for_prompt'] ) ) {
            $result = self::ensure_local_intent_fields( $result, $keyword );

            // v1.5.44 — CRITICAL cache sharing fix. The main research cache
            // key includes domain ($type), so test button ('travel') and
            // article generation ($options['domain'] which may be 'food' /
            // 'general' / empty) produce DIFFERENT cache keys for the same
            // keyword. That means the article generation makes a FRESH Sonar
            // call even when the test button just proved Sonar works and
            // cached 2 real places. Sonar is non-deterministic (live web
            // search) so the fresh call can return 0 places even when a
            // previous call returned 2.
            //
            // Fix: maintain a SEPARATE places-only cache keyed by keyword +
            // country only (not domain). Places results are domain-agnostic —
            // the gelaterie in Lucignano are the same whether the user writes
            // a food article or a travel article. When the main research
            // returns empty places, fall back to this shared cache. When
            // it returns populated places, write them to this shared cache
            // for future calls to reuse.
            $places_only_key = 'seobetter_places_only_' . md5( strtolower( trim( $keyword ) ) . '|' . strtoupper( $country ) );

            if ( empty( $result['places'] ) || count( $result['places'] ) < 2 ) {
                $places_cached = get_transient( $places_only_key );
                if ( is_array( $places_cached ) && ! empty( $places_cached['places'] ) && count( $places_cached['places'] ) >= 2 ) {
                    $result['places']                 = $places_cached['places'];
                    $result['places_count']           = count( $places_cached['places'] );
                    $result['places_provider_used']   = $places_cached['provider_used'] ?? 'Perplexity Sonar (cached)';
                    $result['places_providers_tried'] = $places_cached['providers_tried'] ?? [];
                    $result['places_location']        = $places_cached['location'] ?? ( $result['places_location'] ?? '' );
                    $result['places_business_type']   = $places_cached['business_type'] ?? ( $result['places_business_type'] ?? '' );
                }
            }

            if ( ! empty( $result['places'] ) && count( $result['places'] ) >= 2 ) {
                set_transient( $places_only_key, [
                    'places'          => $result['places'],
                    'provider_used'   => $result['places_provider_used'] ?? null,
                    'providers_tried' => $result['places_providers_tried'] ?? [],
                    'location'        => $result['places_location'] ?? '',
                    'business_type'   => $result['places_business_type'] ?? '',
                    'cached_at'       => time(),
                ], 3600 ); // 1 hour TTL — shorter than main cache so stale data doesn't linger
            }

            set_transient( $cache_key, $result, self::CACHE_TTL );
            return $result;
        }

        // 2. Try local Last30Days (if Python available)
        if ( self::is_available() ) {
            $result = self::run_last30days( $keyword, $type );
            if ( $result['success'] ) {
                $result = self::ensure_local_intent_fields( $result, $keyword );
                set_transient( $cache_key, $result, self::CACHE_TTL );
                return $result;
            }
        }

        // 3. Fall back to AI-based trend generation
        $result = self::ai_fallback( $keyword );
        if ( $result['success'] ) {
            $result = self::ensure_local_intent_fields( $result, $keyword );
            set_transient( $cache_key, $result, self::CACHE_TTL );
        }
        return $result;
    }

    /**
     * v1.5.38 — CRITICAL safety net. Cloud_research timeouts (20-30s budget
     * vs Sonar's 5-15s + parallel research fanout) cause fallback to
     * run_last30days or ai_fallback, both of which return responses WITHOUT
     * the v1.5.24+ Places waterfall fields (is_local_intent, places,
     * places_count). Missing those fields means the v1.5.27 pre-gen switch
     * and v1.5.33 Local Business Mode both silently DON'T FIRE, and the
     * default listicle branch runs → model hallucinates 6 fake businesses.
     *
     * Fix: always run PHP-side local-intent detection on the keyword and
     * force-populate the missing fields. Mirrors the JS detectLocalIntent
     * in cloud-api/api/research.js. When fields are missing AND the keyword
     * looks local-intent, inject is_local_intent=true + empty places array
     * so the pre-gen switch fires and produces an informational article
     * instead of a fabricated listicle.
     */
    private static function ensure_local_intent_fields( array $result, string $keyword ): array {
        // If cloud-api provided the fields, trust them
        if ( isset( $result['is_local_intent'] ) && array_key_exists( 'places', $result ) ) {
            return $result;
        }

        // Detect local intent from the keyword via 4 regex patterns (match
        // the JS detectLocalIntent patterns in cloud-api/api/research.js).
        $kw = trim( $keyword );
        $is_local = false;
        $location = '';
        $business_hint = '';

        // Pattern 1: "X in Y"
        if ( preg_match( '/^(.+?)\s+in\s+([\w\s,\'-]+?)(?:\s+(\d{4}))?$/i', $kw, $m ) ) {
            $is_local = true;
            $business_hint = trim( $m[1] );
            $location = trim( $m[2] );
        }
        // Pattern 2: "best X in Y"
        elseif ( preg_match( '/^(?:best|top|greatest|finest)\s+(.+?)\s+(?:in|near|around)\s+([\w\s,\'-]+?)(?:\s+(\d{4}))?$/i', $kw, $m ) ) {
            $is_local = true;
            $business_hint = trim( $m[1] );
            $location = trim( $m[2] );
        }
        // Pattern 3: "near me" / "nearby" / "local"
        elseif ( preg_match( '/\b(?:near\s*me|nearby|local)\b/i', $kw ) ) {
            $is_local = true;
            $business_hint = trim( preg_replace( '/\b(?:near\s*me|nearby|local|best|top)\b/i', '', $kw ) );
        }
        // Pattern 4: "what's the best X in Y"
        elseif ( preg_match( '/^(?:what\'?s?|which|where)\s+(?:is|are)?\s*(?:the\s+)?(?:best|top)\s+(.+?)\s+(?:in|near|around|at)\s+([\w\s,\'-]+?)(?:\s+(\d{4}))?$/i', $kw, $m ) ) {
            $is_local = true;
            $business_hint = trim( $m[1] );
            $location = trim( $m[2] );
        }

        // Always populate the fields so downstream code can rely on them
        $result['is_local_intent']       = $is_local;
        $result['places']                = $result['places'] ?? [];
        $result['places_count']          = $result['places_count'] ?? 0;
        $result['places_location']       = $result['places_location'] ?? $location;
        $result['places_business_type']  = $result['places_business_type'] ?? $business_hint;
        $result['places_provider_used']  = $result['places_provider_used'] ?? null;
        $result['places_providers_tried']= $result['places_providers_tried'] ?? [];

        return $result;
    }

    /**
     * Call the Vercel cloud research endpoint.
     * Searches Reddit + HN in real-time via the SEOBetter Cloud API.
     */
    private static function cloud_research( string $keyword, string $domain = 'general', string $country = '' ): array {
        $cloud_url = Cloud_API::get_cloud_url();
        $settings = get_option( 'seobetter_settings', [] );

        $body = [
            'keyword'  => $keyword,
            'site_url' => home_url(),
            'domain'   => $domain,
            'country'  => $country,
        ];

        // Pass Brave key for Pro users
        $brave_key = $settings['brave_api_key'] ?? '';
        if ( ! empty( $brave_key ) && License_Manager::can_use( 'content_brief' ) ) {
            $body['brave_key'] = $brave_key;
        }

        // v1.5.24 — Places API keys from Settings → Integrations.
        // These power the 5-tier waterfall in cloud-api/api/research.js::
        // fetchPlacesWaterfall(). Tiers with no key are skipped. All three
        // are optional — the plugin works out of the box with OSM + Wikidata.
        $places_keys = [];
        $fsq_key    = $settings['foursquare_api_key'] ?? '';
        $here_key   = $settings['here_api_key'] ?? '';
        $google_key = $settings['google_places_api_key'] ?? '';
        // v1.5.30 — Perplexity Sonar via OpenRouter as Tier 0 of the waterfall
        // v1.5.40 — also auto-discover an OpenRouter key from the AI Providers
        // section if the Places Integrations field is empty. Users naturally
        // think one OpenRouter key should cover both the article writer and
        // the Places Sonar tier — it should, and now it does.
        $openrouter_key   = $settings['openrouter_api_key'] ?? '';
        if ( empty( $openrouter_key ) ) {
            $ai_providers = get_option( 'seobetter_ai_providers', [] );
            if ( is_array( $ai_providers ) && ! empty( $ai_providers['openrouter'] ) ) {
                $or = $ai_providers['openrouter'];
                // AI_Provider_Manager stores keys encrypted — use its decrypt method
                if ( ! empty( $or['api_key'] ) && class_exists( 'SEOBetter\\AI_Provider_Manager' ) ) {
                    try {
                        $decrypted = AI_Provider_Manager::get_provider_key( 'openrouter' );
                        if ( ! empty( $decrypted ) ) {
                            $openrouter_key = $decrypted;
                        }
                    } catch ( \Throwable $e ) {
                        // Fall through — leave key empty, Sonar tier will be skipped
                    }
                }
            }
        }
        $sonar_model      = $settings['sonar_model'] ?? 'perplexity/sonar';
        if ( ! empty( $fsq_key ) )    $places_keys['foursquare'] = $fsq_key;
        if ( ! empty( $here_key ) )   $places_keys['here']       = $here_key;
        if ( ! empty( $google_key ) ) $places_keys['google']     = $google_key;
        if ( ! empty( $openrouter_key ) ) {
            $places_keys['openrouter_sonar'] = [
                'key'   => $openrouter_key,
                'model' => $sonar_model,
            ];
        }
        if ( ! empty( $places_keys ) ) {
            $body['places_keys'] = $places_keys;
        }

        // v1.5.38 — timeout increased from 20s to 60s. The cloud-api fans out
        // to ~10 parallel sources including Sonar (Tier 0, 5-15s web search),
        // OSM Nominatim + Overpass (1-3s), Wikipedia, Reddit, HN, Brave,
        // DuckDuckGo, and country-specific category APIs. Total can exceed
        // 20s under normal conditions, especially when Sonar is configured.
        // Silent 20s timeouts were causing fallback to run_last30days /
        // ai_fallback which return responses WITHOUT is_local_intent or
        // places fields — which silently disabled the pre-gen switch and
        // Local Business Mode, letting the default listicle branch run and
        // hallucinate 6 fake businesses per article.
        $response = wp_remote_post( $cloud_url . '/api/research', [
            'timeout' => 60,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message(), 'source' => 'cloud' ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['success'] ) ) {
            return [ 'success' => false, 'error' => $body['error'] ?? "HTTP {$code}", 'source' => 'cloud' ];
        }

        return $body;
    }

    /**
     * Run the Last30Days Python script.
     */
    private static function run_last30days( string $keyword, string $type ): array {
        $script = SEOBETTER_PLUGIN_DIR . self::SCRIPT_PATH;
        $escaped_keyword = escapeshellarg( $keyword );
        $skill_dir = SEOBETTER_PLUGIN_DIR . '.agents/skills/last30days';

        // Build the command
        $cmd = "cd {$skill_dir} && python3 scripts/last30days.py {$escaped_keyword} --emit=compact --agent --quick 2>&1";

        // Execute with timeout
        $output = [];
        $return_code = 0;
        exec( $cmd, $output, $return_code );

        $raw_output = implode( "\n", $output );

        if ( $return_code !== 0 || empty( $raw_output ) ) {
            return [
                'success' => false,
                'error'   => 'Last30Days script failed: ' . substr( $raw_output, 0, 200 ),
                'source'  => 'last30days',
            ];
        }

        // Parse the compact output into structured data
        return self::parse_research_output( $raw_output, $keyword );
    }

    /**
     * Parse Last30Days compact output into article-ready data.
     */
    private static function parse_research_output( string $output, string $keyword ): array {
        $data = [
            'success'    => true,
            'source'     => 'last30days',
            'keyword'    => $keyword,
            'stats'      => [],
            'quotes'     => [],
            'trends'     => [],
            'sources'    => [],
            'reddit'     => [],
            'raw'        => $output,
            'summary'    => '',
            'for_prompt' => '', // Formatted for AI prompt injection
        ];

        // Extract statistics (numbers with context)
        if ( preg_match_all( '/(\d[\d,\.]*\s*%?)\s+([^.\n]{10,80})/i', $output, $matches, PREG_SET_ORDER ) ) {
            foreach ( array_slice( $matches, 0, 8 ) as $m ) {
                $data['stats'][] = trim( $m[0] );
            }
        }

        // Extract quoted text
        if ( preg_match_all( '/"([^"]{20,200})"(?:\s*[-—]\s*([^\n]+))?/i', $output, $matches, PREG_SET_ORDER ) ) {
            foreach ( array_slice( $matches, 0, 5 ) as $m ) {
                $data['quotes'][] = [
                    'text'   => $m[1],
                    'source' => $m[2] ?? 'Social media user',
                ];
            }
        }

        // Extract URLs as sources
        if ( preg_match_all( '/https?:\/\/[^\s\)]+/', $output, $url_matches ) ) {
            $data['sources'] = array_unique( array_slice( $url_matches[0], 0, 10 ) );
        }

        // Extract Reddit mentions
        if ( preg_match_all( '/(?:r\/\w+|reddit\.com\S*)[^.\n]{0,100}/i', $output, $reddit_matches ) ) {
            $data['reddit'] = array_slice( $reddit_matches[0], 0, 5 );
        }

        // Build the summary (first 500 chars of output)
        $data['summary'] = substr( strip_tags( $output ), 0, 500 );

        // Build the prompt injection string
        $date = wp_date( 'F Y' );
        $prompt_parts = [];
        $prompt_parts[] = "REAL-TIME DATA FROM WEB RESEARCH ({$date}):";

        if ( ! empty( $data['stats'] ) ) {
            $prompt_parts[] = "\nRecent statistics (use these, they are verified from real sources):";
            foreach ( array_slice( $data['stats'], 0, 5 ) as $stat ) {
                $prompt_parts[] = "- {$stat}";
            }
        }

        if ( ! empty( $data['quotes'] ) ) {
            $prompt_parts[] = "\nReal quotes from discussions:";
            foreach ( array_slice( $data['quotes'], 0, 3 ) as $q ) {
                $prompt_parts[] = "- \"{$q['text']}\" — {$q['source']}";
            }
        }

        if ( ! empty( $data['sources'] ) ) {
            $prompt_parts[] = "\nSources to cite:";
            foreach ( array_slice( $data['sources'], 0, 5 ) as $src ) {
                $prompt_parts[] = "- {$src}";
            }
        }

        // Add general context from raw output
        $clean = preg_replace( '/\s+/', ' ', strip_tags( $output ) );
        $context = substr( $clean, 0, 1000 );
        $prompt_parts[] = "\nResearch context:\n{$context}";

        $data['for_prompt'] = implode( "\n", $prompt_parts );

        return $data;
    }

    /**
     * AI fallback when Last30Days is not available.
     * Uses the configured AI provider to generate trend data.
     */
    private static function ai_fallback( string $keyword ): array {
        $date = wp_date( 'F Y' );
        $prompt = "Research the most recent developments, statistics, and news about \"{$keyword}\" from the last 30 days (as of {$date}).

Return 5-8 recent data points in this format:
- [Specific fact or statistic] (Source Name, {$date})

Include:
- 2-3 statistics with real numbers
- 1-2 quotes from experts or discussions
- 1-2 trending topics or developments

Only include verifiable facts. If unsure about recency, include the most recent stats you know with their actual year.";

        $provider = AI_Provider_Manager::get_active_provider();
        if ( $provider ) {
            $result = AI_Provider_Manager::send_request( $provider['provider_id'], $prompt, 'You are a research assistant providing verifiable data points.', [ 'max_tokens' => 600 ] );
        } else {
            $result = Cloud_API::generate( $prompt, 'You are a research assistant.', [ 'max_tokens' => 600 ] );
        }

        if ( ! $result['success'] ) {
            return [ 'success' => false, 'error' => $result['error'] ?? 'AI fallback failed.', 'source' => 'ai_fallback' ];
        }

        return [
            'success'    => true,
            'source'     => 'ai_fallback',
            'keyword'    => $keyword,
            'for_prompt' => $result['content'],
            'raw'        => $result['content'],
            'stats'      => [],
            'quotes'     => [],
            'trends'     => [],
            'sources'    => [],
            'summary'    => substr( $result['content'], 0, 500 ),
        ];
    }

    /**
     * Get status of Last30Days integration.
     */
    public static function get_status(): array {
        $available = self::is_available();
        $cloud_url = Cloud_API::get_cloud_url();
        $has_cloud = ! empty( $cloud_url );
        $env_file = ( $_SERVER['HOME'] ?? '' ) . '/.config/last30days/.env';
        $has_config = ! empty( $_SERVER['HOME'] ) && file_exists( $env_file );

        $sources = [ 'Reddit (threads)', 'Hacker News', 'Polymarket', 'Web' ];
        if ( $has_config ) {
            $env = file_get_contents( $env_file );
            if ( strpos( $env, 'AUTH_TOKEN' ) !== false || strpos( $env, 'FROM_BROWSER' ) !== false ) {
                $sources[] = 'X/Twitter';
            }
            if ( strpos( $env, 'SCRAPECREATORS_API_KEY' ) !== false ) {
                $sources[] = 'Reddit (comments)';
                $sources[] = 'TikTok';
                $sources[] = 'Instagram';
            }
            if ( strpos( $env, 'BSKY_HANDLE' ) !== false ) {
                $sources[] = 'Bluesky';
            }
        }

        // Cloud research is always available if cloud URL is set
        if ( $has_cloud ) {
            $sources[] = 'Cloud (Reddit + HN)';
        }

        return [
            'available'  => $available || $has_cloud,
            'configured' => $has_config || $has_cloud,
            'has_cloud'  => $has_cloud,
            'sources'    => $sources,
            'source_count' => count( $sources ),
        ];
    }
}
