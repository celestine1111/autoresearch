<?php

namespace SEOBetter;

/**
 * v1.5.216.22 — Google Search Console integration (Phase 1 item 3 MVP).
 *
 * What it does:
 *   - OAuth 2.0 flow with Google to obtain a refresh_token
 *   - Daily WP-Cron pulls last 28 days of clicks/impressions/position per
 *     URL from the searchAnalytics/query endpoint
 *   - Stores per-URL snapshots in {prefix}_seobetter_gsc_snapshots
 *   - Public `get_post_stats($post_id)` lets Freshness inventory + post-edit
 *     sidebar widget read GSC data on demand
 *
 * Tier:
 *   - FREE: connect + view (matches RankMath free; the data is free from Google)
 *   - PRO+: GSC-driven Freshness inventory prioritization (item 4)
 *   - AGENCY: GSC Indexing API (Phase 5+)
 *
 * Setup (Phase 1 testing):
 *   1. Create a Google Cloud project at https://console.cloud.google.com
 *   2. APIs & Services → Library → enable "Google Search Console API"
 *   3. Credentials → Create Credentials → OAuth 2.0 Client ID → Web application
 *   4. Authorized redirect URI: {your-site}/wp-json/seobetter/v1/gsc/oauth-callback
 *   5. Copy Client ID + Client Secret into wp-config.php:
 *        define( 'SEOBETTER_GSC_CLIENT_ID', '...' );
 *        define( 'SEOBETTER_GSC_CLIENT_SECRET', '...' );
 *   6. Phase 2 (Freemius integration) replaces this with a centralized OAuth
 *      proxy via cloud-api so users never register their own Google Cloud
 *      project — for MVP testing on Ben's site, per-user setup is fine.
 */
class GSC_Manager {

    private const OPTION_KEY        = 'seobetter_gsc_connection';
    private const LAST_SYNC_OPT_KEY = 'seobetter_gsc_last_sync';
    private const TABLE_NAME        = 'seobetter_gsc_snapshots';
    private const CRON_HOOK         = 'seobetter_gsc_daily_sync';

    // ── Configuration ────────────────────────────────────────────────────

    /**
     * v1.5.216.62 — Centralized OAuth proxy. Default true so end users don't
     * have to create a Google Cloud project / configure OAuth themselves.
     * The proxy at cloud-api.seobetter.com holds the verified app credentials
     * and forwards tokens to each install.
     *
     * Set `SEOBETTER_GSC_USE_PROXY` to `false` in wp-config.php only if you
     * want to BYO Google Cloud credentials (advanced — see SEOBETTER_GSC_CLIENT_ID
     * + SEOBETTER_GSC_CLIENT_SECRET below).
     */
    public static function use_proxy(): bool {
        if ( defined( 'SEOBETTER_GSC_USE_PROXY' ) ) {
            return (bool) SEOBETTER_GSC_USE_PROXY;
        }
        return true; // default ON
    }

    private static function get_proxy_base_url(): string {
        // Proxy lives on the same Cloud API endpoint as research/scrape/etc.
        // Single source of truth via Cloud_API::get_cloud_url().
        return Cloud_API::get_cloud_url();
    }

    private static function get_client_id(): string {
        return defined( 'SEOBETTER_GSC_CLIENT_ID' ) ? (string) SEOBETTER_GSC_CLIENT_ID : '';
    }

    private static function get_client_secret(): string {
        return defined( 'SEOBETTER_GSC_CLIENT_SECRET' ) ? (string) SEOBETTER_GSC_CLIENT_SECRET : '';
    }

    public static function get_redirect_uri(): string {
        return rest_url( 'seobetter/v1/gsc/oauth-callback' );
    }

    public static function is_oauth_configured(): bool {
        // Proxy mode = always configured (zero per-install setup needed)
        if ( self::use_proxy() ) return true;
        // BYO mode = both client_id and client_secret required
        return self::get_client_id() !== '' && self::get_client_secret() !== '';
    }

    // ── OAuth flow ───────────────────────────────────────────────────────

    /**
     * Build the URL the user is redirected to in order to authorize SEOBetter.
     *
     * v1.5.216.51 — switched state from wp_create_nonce() to a transient-stored
     * CSRF token. WP nonces are user-session-scoped: when Google redirects the
     * user back to the REST callback endpoint via a regular GET (no
     * X-WP-Nonce header, no AJAX context), wp_get_current_user() can return
     * 0 because the REST API auth resolution differs from wp-admin's. That
     * caused wp_verify_nonce() to fail and surface "Invalid state — please
     * retry the connect flow." even when the user was clearly still logged
     * in. The transient approach is independent of user session, has an
     * explicit 10-minute TTL (sensible OAuth window), and stores the
     * initiating user_id so the callback knows who to bind tokens to.
     */
    public static function build_auth_url(): string {
        $token = bin2hex( random_bytes( 16 ) );
        // Store the initiating user_id alongside the token so the callback
        // can bind tokens to the right user even if their session changed.
        set_transient( 'seobetter_gsc_oauth_state_' . $token, get_current_user_id(), 10 * MINUTE_IN_SECONDS );

        // v1.5.216.62 — proxy path: send user to Cloud OAuth proxy with our
        // plugin's pstate (CSRF token) + return_url. Proxy holds the
        // verified app credentials and bounces the user back through Google
        // → proxy → here. Plugin never holds the client_secret.
        if ( self::use_proxy() ) {
            $proxy_base = self::get_proxy_base_url();
            $params = [
                'return_url' => self::get_redirect_uri(),
                'pstate'     => $token,
            ];
            return rtrim( $proxy_base, '/' ) . '/api/gsc-oauth/start?' . http_build_query( $params );
        }

        // Legacy BYO-credentials path (advanced users with their own GCP project)
        $params = [
            'client_id'     => self::get_client_id(),
            'redirect_uri'  => self::get_redirect_uri(),
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/userinfo.email',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $token,
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params );
    }

    /**
     * Handle the OAuth callback — exchange the auth code for an access +
     * refresh token. Returns ['success' => bool, 'error' => string, 'email' => string].
     */
    public static function handle_oauth_callback( string $code_or_pickup, string $state ): array {
        // v1.5.216.51 — verify state against transient (set in build_auth_url).
        // Transient existence proves the request originated from this site
        // within the 10-minute OAuth window. Single-use: deleted on first verify.
        $transient_key = 'seobetter_gsc_oauth_state_' . sanitize_key( $state );
        $stored_user_id = get_transient( $transient_key );
        if ( $stored_user_id === false ) {
            return [ 'success' => false, 'error' => 'Invalid or expired state — please retry the connect flow (10-minute window).' ];
        }
        delete_transient( $transient_key );

        // v1.5.216.62 — Two paths depending on whether we used the proxy or BYO.
        // Proxy: $code_or_pickup is a Cloud-OAuth pickup token, redeemed via POST.
        // BYO:   $code_or_pickup is Google's auth code, exchanged directly.
        if ( self::use_proxy() ) {
            $tokens = self::redeem_proxy_pickup( $code_or_pickup );
            if ( ! is_array( $tokens ) || empty( $tokens['access_token'] ) ) {
                return [ 'success' => false, 'error' => is_string( $tokens ) ? $tokens : 'Cloud OAuth proxy returned no tokens — try reconnecting.' ];
            }
            $body = $tokens;
        } else {
            // Legacy BYO path
            if ( ! self::is_oauth_configured() ) {
                return [ 'success' => false, 'error' => 'OAuth not configured. Either set SEOBETTER_GSC_USE_PROXY=true (default) or set SEOBETTER_GSC_CLIENT_ID and SEOBETTER_GSC_CLIENT_SECRET in wp-config.php for BYO mode.' ];
            }
            $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
                'timeout' => 15,
                'body'    => [
                    'code'          => $code_or_pickup,
                    'client_id'     => self::get_client_id(),
                    'client_secret' => self::get_client_secret(),
                    'redirect_uri'  => self::get_redirect_uri(),
                    'grant_type'    => 'authorization_code',
                ],
            ] );
            if ( is_wp_error( $response ) ) {
                return [ 'success' => false, 'error' => 'Network error: ' . $response->get_error_message() ];
            }
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
                $err = $body['error_description'] ?? $body['error'] ?? 'token exchange failed';
                return [ 'success' => false, 'error' => 'Google rejected the auth code: ' . $err ];
            }
        }

        // Get user email so the Settings UI can display "Connected as user@gmail.com"
        $email = self::fetch_user_email( $body['access_token'] );

        $connection = [
            'access_token'  => self::encrypt( (string) $body['access_token'] ),
            'refresh_token' => self::encrypt( (string) ( $body['refresh_token'] ?? '' ) ),
            'expires_at'    => time() + (int) ( $body['expires_in'] ?? 3600 ),
            'connected_at'  => time(),
            'account_email' => $email,
            'site_url'      => self::detect_gsc_site_url(),
        ];
        update_option( self::OPTION_KEY, $connection, false );

        // Schedule the cron immediately so the first sync happens on the next cron tick
        self::schedule_cron();

        return [ 'success' => true, 'email' => $email ];
    }

    /**
     * v1.5.216.62 — Redeem a single-use pickup token issued by the Cloud
     * OAuth proxy. Returns the tokens array on success, or an error string.
     */
    private static function redeem_proxy_pickup( string $pickup ) {
        if ( $pickup === '' ) return 'Empty pickup token';
        $url = rtrim( self::get_proxy_base_url(), '/' ) . '/api/gsc-oauth/exchange';
        $response = wp_remote_post( $url, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'pickup' => $pickup ] ),
        ] );
        if ( is_wp_error( $response ) ) {
            return 'Cloud proxy network error: ' . $response->get_error_message();
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
            $err = is_array( $body ) ? ( $body['error'] ?? 'unknown' ) : 'HTTP ' . $code;
            return 'Cloud proxy rejected pickup token: ' . $err;
        }
        return $body;
    }

    /**
     * v1.5.216.52 — list every Google Search Console property the
     * authorized account can access. Used by the property-picker UI on
     * the GSC card so users can choose which site to track (instead of
     * hardcoding home_url() — which fails for agency users, dev/staging
     * setups, and any account that owns a different domain than the
     * plugin install URL). Calls Google's /sites endpoint directly.
     *
     * Returns an array of arrays:
     *   [ [ 'site_url' => 'https://example.com/', 'permission' => 'siteOwner' ], ... ]
     * — or [] if not connected / API failure.
     */
    public static function list_sites(): array {
        $access = self::get_access_token();
        if ( $access === '' ) return [];

        $response = wp_remote_get( 'https://www.googleapis.com/webmasters/v3/sites', [
            'timeout' => 10,
            'headers' => [ 'Authorization' => 'Bearer ' . $access ],
        ] );
        if ( is_wp_error( $response ) ) return [];
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['siteEntry'] ) ) return [];

        $out = [];
        foreach ( $body['siteEntry'] as $entry ) {
            if ( ! is_array( $entry ) ) continue;
            $url  = (string) ( $entry['siteUrl'] ?? '' );
            $perm = (string) ( $entry['permissionLevel'] ?? '' );
            if ( $url === '' ) continue;
            $out[] = [
                'site_url'   => $url,
                'permission' => $perm,
            ];
        }
        // Sort by URL so display is stable
        usort( $out, fn( $a, $b ) => strcmp( $a['site_url'], $b['site_url'] ) );
        return $out;
    }

    /**
     * v1.5.216.52 — update which GSC property URL syncs target.
     * Called from the property-picker dropdown. Validates the URL is
     * actually one the authorized account has access to (via list_sites)
     * to prevent users saving a property they can't read from.
     *
     * @param string $site_url The exact siteUrl from list_sites() result
     * @return array{success:bool,error?:string}
     */
    public static function set_site_url( string $site_url ): array {
        $site_url = trim( $site_url );
        if ( $site_url === '' ) {
            return [ 'success' => false, 'error' => 'site_url is required' ];
        }
        $sites = self::list_sites();
        if ( empty( $sites ) ) {
            return [ 'success' => false, 'error' => 'No GSC properties accessible by the authorized account.' ];
        }
        $owned_urls = array_column( $sites, 'site_url' );
        if ( ! in_array( $site_url, $owned_urls, true ) ) {
            return [ 'success' => false, 'error' => 'You do not have access to this property in Google Search Console.' ];
        }
        $conn = get_option( self::OPTION_KEY, [] );
        if ( empty( $conn ) ) {
            return [ 'success' => false, 'error' => 'GSC not connected.' ];
        }
        $conn['site_url'] = $site_url;
        update_option( self::OPTION_KEY, $conn, false );
        return [ 'success' => true ];
    }

    /**
     * Disconnect: revoke the access token at Google and clear local storage.
     */
    public static function disconnect(): void {
        $conn = get_option( self::OPTION_KEY, [] );
        $access = self::decrypt( $conn['access_token'] ?? '' );
        if ( $access !== '' ) {
            wp_remote_post( 'https://oauth2.googleapis.com/revoke', [
                'timeout' => 5,
                'body'    => [ 'token' => $access ],
            ] );
        }
        delete_option( self::OPTION_KEY );
        delete_option( self::LAST_SYNC_OPT_KEY );
        self::unschedule_cron();
    }

    /**
     * Get a fresh access token. Refreshes via refresh_token if expired.
     */
    private static function get_access_token(): string {
        $conn = get_option( self::OPTION_KEY, [] );
        if ( empty( $conn['access_token'] ) ) return '';

        // Refresh if expired or close to expiring
        if ( time() >= ( (int) ( $conn['expires_at'] ?? 0 ) - 60 ) ) {
            if ( ! self::refresh_access_token() ) return '';
            $conn = get_option( self::OPTION_KEY, [] );
        }
        return self::decrypt( $conn['access_token'] ?? '' );
    }

    private static function refresh_access_token(): bool {
        $conn = get_option( self::OPTION_KEY, [] );
        $refresh = self::decrypt( $conn['refresh_token'] ?? '' );
        if ( $refresh === '' ) return false;

        // v1.5.216.62 — proxy path. Plugin POSTs the refresh_token to the
        // Cloud proxy, which combines it with our central client_secret and
        // exchanges with Google. Returns just access_token + expires_in.
        // refresh_token never leaves the install except for this call.
        if ( self::use_proxy() ) {
            $url = rtrim( self::get_proxy_base_url(), '/' ) . '/api/gsc-oauth/refresh';
            $response = wp_remote_post( $url, [
                'timeout' => 15,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ 'refresh_token' => $refresh ] ),
            ] );
            if ( is_wp_error( $response ) ) return false;
            $code = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( $code === 401 ) {
                // Refresh token revoked at Google — clear connection so user re-authenticates
                error_log( 'SEOBetter GSC_Manager::refresh_access_token — refresh_token rejected by Google, clearing connection' );
                delete_option( self::OPTION_KEY );
                return false;
            }
            if ( $code !== 200 || ! is_array( $body ) || empty( $body['access_token'] ) ) return false;
            $conn['access_token'] = self::encrypt( (string) $body['access_token'] );
            $conn['expires_at']   = time() + (int) ( $body['expires_in'] ?? 3600 );
            update_option( self::OPTION_KEY, $conn, false );
            return true;
        }

        // Legacy BYO path — direct to Google with install's own client_secret
        if ( ! self::get_client_id() || ! self::get_client_secret() ) return false;
        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body'    => [
                'refresh_token' => $refresh,
                'client_id'     => self::get_client_id(),
                'client_secret' => self::get_client_secret(),
                'grant_type'    => 'refresh_token',
            ],
        ] );
        if ( is_wp_error( $response ) ) return false;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['access_token'] ) ) return false;
        $conn['access_token'] = self::encrypt( (string) $body['access_token'] );
        $conn['expires_at']   = time() + (int) ( $body['expires_in'] ?? 3600 );
        update_option( self::OPTION_KEY, $conn, false );
        return true;
    }

    private static function fetch_user_email( string $access_token ): string {
        $response = wp_remote_get( 'https://www.googleapis.com/oauth2/v2/userinfo', [
            'timeout' => 10,
            'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
        ] );
        if ( is_wp_error( $response ) ) return '';
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $body ) ? (string) ( $body['email'] ?? '' ) : '';
    }

    /**
     * Best-effort: use home_url() with trailing slash as the GSC property URL.
     * GSC supports both URL-prefix properties (https://example.com/) and
     * domain properties (sc-domain:example.com); URL-prefix is far more
     * common and matches home_url. Future enhancement: list user's verified
     * properties via the sites/list endpoint and let them pick.
     */
    private static function detect_gsc_site_url(): string {
        return rtrim( home_url( '/' ), '/' ) . '/';
    }

    // ── Status / public API ──────────────────────────────────────────────

    public static function is_connected(): bool {
        $conn = get_option( self::OPTION_KEY, [] );
        return ! empty( $conn['refresh_token'] );
    }

    public static function get_status(): array {
        $conn = get_option( self::OPTION_KEY, [] );
        $configured = self::is_oauth_configured();

        if ( empty( $conn['refresh_token'] ) ) {
            return [
                'connected'  => false,
                'configured' => $configured,
            ];
        }

        return [
            'connected'    => true,
            'configured'   => $configured,
            'email'        => (string) ( $conn['account_email'] ?? '' ),
            'site_url'     => (string) ( $conn['site_url'] ?? '' ),
            'connected_at' => (int) ( $conn['connected_at'] ?? 0 ),
            'last_sync'    => (int) get_option( self::LAST_SYNC_OPT_KEY, 0 ),
            'urls_tracked' => self::count_tracked_urls(),
        ];
    }

    private static function count_tracked_urls(): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        // Defensive: handle case where the table hasn't been installed yet
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $exists ) return 0;
        return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM `$table`" );
    }

    // ── Sync (cron + manual) ─────────────────────────────────────────────

    /**
     * Pull last 28 days of performance data for the top N URLs and store
     * one snapshot row per URL. Idempotent — re-running the same day
     * REPLACEs the existing row for that day.
     *
     * @param int $limit  Max URLs to pull (Google caps at 25,000 per request)
     * @return array {success: bool, urls?: int, error?: string}
     */
    public static function sync( int $limit = 1000 ): array {
        if ( ! self::is_connected() ) {
            return [ 'success' => false, 'error' => 'GSC not connected.' ];
        }

        $access = self::get_access_token();
        if ( $access === '' ) {
            return [ 'success' => false, 'error' => 'Could not refresh access token. Try disconnecting and reconnecting.' ];
        }

        $conn     = get_option( self::OPTION_KEY, [] );
        $site_url = (string) ( $conn['site_url'] ?? home_url( '/' ) );

        // GSC data has ~2-day lag; query yesterday-back-28 days
        $end   = date( 'Y-m-d', strtotime( 'yesterday' ) );
        $start = date( 'Y-m-d', strtotime( '-28 days', strtotime( 'yesterday' ) ) );

        $endpoint = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query';
        $response = wp_remote_post( $endpoint, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $access,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'startDate'  => $start,
                'endDate'    => $end,
                'dimensions' => [ 'page' ],
                'rowLimit'   => max( 1, min( $limit, 25000 ) ),
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'SEOBetter GSC_Manager::sync wp_error — ' . $response->get_error_message() );
            return [ 'success' => false, 'error' => 'Network error: ' . $response->get_error_message() ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $body = wp_remote_retrieve_body( $response );
            $decoded = json_decode( $body, true );
            $msg = $decoded['error']['message'] ?? ( 'HTTP ' . $code );
            error_log( 'SEOBetter GSC_Manager::sync HTTP ' . $code . ' — ' . substr( $body, 0, 500 ) );
            return [ 'success' => false, 'error' => 'GSC API: ' . $msg ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['rows'] ) ) {
            update_option( self::LAST_SYNC_OPT_KEY, time(), false );
            return [ 'success' => true, 'urls' => 0 ];
        }

        $stored = 0;
        foreach ( $body['rows'] as $row ) {
            $url = (string) ( $row['keys'][0] ?? '' );
            if ( $url === '' ) continue;
            $post_id = url_to_postid( $url );
            if ( ! $post_id ) continue;

            self::save_snapshot( $post_id, [
                'clicks'      => (int) ( $row['clicks'] ?? 0 ),
                'impressions' => (int) ( $row['impressions'] ?? 0 ),
                'ctr'         => (float) ( $row['ctr'] ?? 0 ),
                'position'    => (float) ( $row['position'] ?? 0 ),
            ] );
            $stored++;
        }

        update_option( self::LAST_SYNC_OPT_KEY, time(), false );
        error_log( 'SEOBetter GSC_Manager::sync stored ' . $stored . ' URL snapshots' );
        return [ 'success' => true, 'urls' => $stored ];
    }

    private static function save_snapshot( int $post_id, array $stats ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Use REPLACE INTO via $wpdb->replace so re-syncing the same day
        // overwrites instead of duplicating
        $wpdb->replace(
            $table,
            [
                'post_id'         => $post_id,
                'captured_at'     => date( 'Y-m-d' ),
                'clicks_28d'      => $stats['clicks'],
                'impressions_28d' => $stats['impressions'],
                'ctr_28d'         => $stats['ctr'],
                'position_28d'    => $stats['position'],
            ],
            [ '%d', '%s', '%d', '%d', '%f', '%f' ]
        );
    }

    /**
     * v1.5.216.53 — DEBUG-only test data seeder.
     *
     * Pre-launch, staging sites have no real GSC traffic, so the downstream
     * features (Freshness GSC-driven priority, Decay alerts, Striking-distance
     * detection, per-post performance widget) have nothing to render. This
     * seeder injects realistic 14-day snapshots for the 10 most-recent posts
     * so the downstream UIs can be visually verified end-to-end.
     *
     * Patterns seeded:
     *   - Post #1 (index 0): "decaying" — week 1 high clicks, week 2 collapse
     *     (triggers Decay Alert Manager warning)
     *   - Post #2 (index 1): "striking distance" — position 14, high impressions,
     *     low clicks (triggers Striking-distance high-priority refresh flag)
     *   - Post #3 (index 2): "low CTR" — high impressions, low CTR
     *     (suggests title/meta refresh — quick-win flag)
     *   - Posts #4-#10: realistic baseline (random clicks 5-500, position 5-50)
     *
     * Gated by WP_DEBUG to keep this out of production builds. Use
     * clear_test_snapshots() to wipe.
     *
     * Returns ['success' => bool, 'rows_inserted' => int, 'posts_seeded' => int]
     */
    public static function seed_test_snapshots(): array {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return [ 'success' => false, 'error' => 'Test data seeder requires WP_DEBUG=true.' ];
        }

        // Make sure the table exists (in case install_table never ran)
        self::install_table();

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Pull 10 most-recent published posts
        $posts = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ] );
        if ( empty( $posts ) ) {
            return [ 'success' => false, 'error' => 'No published posts found to seed against.' ];
        }

        $rows_inserted = 0;
        foreach ( $posts as $i => $post_id ) {
            // Pick a pattern based on the post index (deterministic so re-seeding
            // produces a stable mix of decay/striking/low-CTR/normal)
            $pattern = 'normal';
            if ( $i === 0 ) $pattern = 'decay';
            elseif ( $i === 1 ) $pattern = 'striking';
            elseif ( $i === 2 ) $pattern = 'low_ctr';

            // Generate 14 daily snapshots — captured_at = today, today-1, ..., today-13
            for ( $days_ago = 0; $days_ago < 14; $days_ago++ ) {
                $captured = gmdate( 'Y-m-d', strtotime( "-$days_ago days" ) );
                [ $clicks, $impressions, $ctr, $position ] = self::pattern_to_metrics( $pattern, $days_ago );

                // REPLACE so re-seeding overwrites cleanly (UNIQUE KEY on post_id+captured_at)
                $wpdb->replace(
                    $table,
                    [
                        'post_id'         => (int) $post_id,
                        'captured_at'     => $captured,
                        'clicks_28d'      => $clicks,
                        'impressions_28d' => $impressions,
                        'ctr_28d'         => $ctr,
                        'position_28d'    => $position,
                    ],
                    [ '%d', '%s', '%d', '%d', '%f', '%f' ]
                );
                $rows_inserted++;
            }
        }

        // Mark the connection's last_sync so Freshness page knows GSC is "active"
        $conn = get_option( self::OPTION_KEY, [] );
        update_option( self::LAST_SYNC_OPT_KEY, time(), false );

        return [
            'success'       => true,
            'rows_inserted' => $rows_inserted,
            'posts_seeded'  => count( $posts ),
        ];
    }

    /**
     * v1.5.216.53 — wipe all GSC snapshot data. WP_DEBUG-gated counterpart
     * to seed_test_snapshots() — undo the seed without touching the
     * connection itself.
     */
    public static function clear_test_snapshots(): array {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return [ 'success' => false, 'error' => 'Test data clear requires WP_DEBUG=true.' ];
        }
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $deleted = $wpdb->query( "DELETE FROM `$table`" );
        delete_option( self::LAST_SYNC_OPT_KEY );
        return [ 'success' => true, 'rows_deleted' => (int) $deleted ];
    }

    /**
     * Pattern generator for seed_test_snapshots(). Returns realistic
     * [clicks, impressions, ctr, position] for the given pattern + day index.
     *
     * @param string $pattern  'decay' | 'striking' | 'low_ctr' | 'normal'
     * @param int    $days_ago 0-13 (today = 0)
     */
    private static function pattern_to_metrics( string $pattern, int $days_ago ): array {
        switch ( $pattern ) {
            case 'decay':
                // Week 1 (days 7-13): healthy ~50 clicks/day. Week 2 (days 0-6): drops to ~10
                $clicks      = $days_ago >= 7 ? rand( 40, 60 ) : rand( 5, 15 );
                $impressions = $days_ago >= 7 ? rand( 800, 1200 ) : rand( 600, 900 );
                $position    = $days_ago >= 7 ? round( rand( 50, 80 ) / 10, 1 ) : round( rand( 90, 130 ) / 10, 1 );
                break;
            case 'striking':
                // Position 11-15 ("striking distance" — just off page 1), high impressions, few clicks
                $clicks      = rand( 8, 20 );
                $impressions = rand( 1500, 2500 );
                $position    = round( rand( 110, 150 ) / 10, 1 );
                break;
            case 'low_ctr':
                // High impressions, low CTR — title/meta likely needs refresh
                $clicks      = rand( 10, 25 );
                $impressions = rand( 3000, 5000 );
                $position    = round( rand( 40, 80 ) / 10, 1 );
                break;
            default: // normal
                $clicks      = rand( 20, 200 );
                $impressions = rand( 500, 3000 );
                $position    = round( rand( 30, 200 ) / 10, 1 );
        }
        $ctr = $impressions > 0 ? round( $clicks / $impressions, 6 ) : 0.0;
        return [ $clicks, $impressions, $ctr, $position ];
    }

    /**
     * Public API used by Freshness inventory (Phase 1 item 4) and the
     * post-edit sidebar widget. Returns the most recent snapshot for a post.
     */
    public static function get_post_stats( int $post_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $exists ) return [];

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `$table` WHERE post_id = %d ORDER BY captured_at DESC LIMIT 1",
                $post_id
            ),
            ARRAY_A
        );
        if ( ! $row ) return [];

        return [
            'clicks_28d'      => (int) $row['clicks_28d'],
            'impressions_28d' => (int) $row['impressions_28d'],
            'ctr_28d'         => (float) $row['ctr_28d'],
            'position_28d'    => (float) $row['position_28d'],
            'captured_at'     => (string) $row['captured_at'],
        ];
    }

    /**
     * v1.5.216.54 — Top queries for a single page over the last 28 days.
     *
     * Real-time call to searchAnalytics/query with dimensions=['query'] and a
     * page-equals filter. Cached in a transient for 1 hour so a Pro+ user
     * spamming the "Why?" drawer doesn't burn quota.
     *
     * Returns up to 10 rows: [ {query, clicks, impressions, ctr, position}, ... ].
     * Returns [] silently on any failure (no GSC connection, API error, no data
     * for this URL, etc.) — the diagnostic UI degrades gracefully.
     */
    public static function get_post_top_queries( int $post_id ): array {
        if ( ! self::is_connected() ) return [];

        $cache_key = 'seobetter_gsc_q_' . $post_id;
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) return $cached;

        $access = self::get_access_token();
        if ( $access === '' ) return [];

        $conn      = get_option( self::OPTION_KEY, [] );
        $site_url  = (string) ( $conn['site_url'] ?? home_url( '/' ) );
        $page_url  = get_permalink( $post_id );
        if ( ! $page_url ) return [];

        $end   = date( 'Y-m-d', strtotime( 'yesterday' ) );
        $start = date( 'Y-m-d', strtotime( '-28 days', strtotime( 'yesterday' ) ) );

        $endpoint = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query';
        $response = wp_remote_post( $endpoint, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $access,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'startDate'        => $start,
                'endDate'          => $end,
                'dimensions'       => [ 'query' ],
                'dimensionFilterGroups' => [ [
                    'filters' => [ [
                        'dimension'  => 'page',
                        'operator'   => 'equals',
                        'expression' => $page_url,
                    ] ],
                ] ],
                'rowLimit' => 10,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) return [];
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['rows'] ) ) {
            // Cache empty result too — avoid hammering the API for posts with no GSC data
            set_transient( $cache_key, [], HOUR_IN_SECONDS );
            return [];
        }

        $queries = [];
        foreach ( $body['rows'] as $row ) {
            $position = (float) ( $row['position'] ?? 0 );
            $queries[] = [
                'query'             => (string) ( $row['keys'][0] ?? '' ),
                'clicks'            => (int) ( $row['clicks'] ?? 0 ),
                'impressions'       => (int) ( $row['impressions'] ?? 0 ),
                'ctr'               => (float) ( $row['ctr'] ?? 0 ),
                'position'          => $position,
                'striking_distance' => $position >= 11 && $position <= 20,
            ];
        }

        set_transient( $cache_key, $queries, HOUR_IN_SECONDS );
        return $queries;
    }

    // ── Cron ─────────────────────────────────────────────────────────────

    public static function cron_daily_sync(): void {
        if ( ! self::is_connected() ) return;
        self::sync();
    }

    public static function schedule_cron(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    public static function unschedule_cron(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    // ── Schema install ───────────────────────────────────────────────────

    public static function install_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            captured_at DATE NOT NULL,
            clicks_28d INT UNSIGNED NOT NULL DEFAULT 0,
            impressions_28d INT UNSIGNED NOT NULL DEFAULT 0,
            ctr_28d DECIMAL(8,6) NOT NULL DEFAULT 0,
            position_28d DECIMAL(6,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY post_date (post_id, captured_at),
            KEY post_id (post_id),
            KEY captured_at (captured_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // ── Encryption helpers ───────────────────────────────────────────────

    /**
     * Encrypt a token using an AUTH_KEY-derived secret. Tokens at rest are
     * never readable from the wp_options table without WP secrets.
     */
    private static function encrypt( string $value ): string {
        if ( $value === '' ) return '';
        if ( ! defined( 'AUTH_KEY' ) || ! function_exists( 'openssl_encrypt' ) ) {
            return base64_encode( $value );
        }
        $key = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
        $iv  = openssl_random_pseudo_bytes( 16 );
        $ct  = openssl_encrypt( $value, 'aes-256-cbc', $key, 0, $iv );
        if ( $ct === false ) return base64_encode( $value );
        return base64_encode( $iv . $ct );
    }

    private static function decrypt( string $encrypted ): string {
        if ( $encrypted === '' ) return '';
        if ( ! defined( 'AUTH_KEY' ) || ! function_exists( 'openssl_decrypt' ) ) {
            return (string) base64_decode( $encrypted, true );
        }
        $data = base64_decode( $encrypted, true );
        if ( $data === false || strlen( $data ) < 17 ) return '';
        $key = substr( hash( 'sha256', AUTH_KEY ), 0, 32 );
        $iv  = substr( $data, 0, 16 );
        $ct  = substr( $data, 16 );
        $pt  = openssl_decrypt( $ct, 'aes-256-cbc', $key, 0, $iv );
        return $pt === false ? '' : $pt;
    }
}
