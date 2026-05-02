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
    public static function handle_oauth_callback( string $code, string $state ): array {
        // v1.5.216.51 — verify state against transient (set in build_auth_url).
        // Transient existence proves the request originated from this site
        // within the 10-minute OAuth window. Single-use: deleted on first verify.
        $transient_key = 'seobetter_gsc_oauth_state_' . sanitize_key( $state );
        $stored_user_id = get_transient( $transient_key );
        if ( $stored_user_id === false ) {
            return [ 'success' => false, 'error' => 'Invalid or expired state — please retry the connect flow (10-minute window).' ];
        }
        delete_transient( $transient_key );
        if ( ! self::is_oauth_configured() ) {
            return [ 'success' => false, 'error' => 'OAuth not configured. Set SEOBETTER_GSC_CLIENT_ID and SEOBETTER_GSC_CLIENT_SECRET in wp-config.php.' ];
        }

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body'    => [
                'code'          => $code,
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
        if ( ! self::is_oauth_configured() ) return false;

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
