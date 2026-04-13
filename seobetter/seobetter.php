<?php
/**
 * Plugin Name: SEOBetter
 * Plugin URI: https://seobetter.com
 * Description: AI-powered content generation optimized for Google AI Overviews, ChatGPT, Perplexity, Gemini & more. Generate articles that AI models cite. Works alongside Yoast, RankMath, or AIOSEO.
 * Version: 1.5.18
 * Author: SEOBetter
 * Author URI: https://seobetter.com
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: seobetter
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SEOBETTER_VERSION', '1.5.18' );
define( 'SEOBETTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEOBETTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SEOBETTER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for plugin classes.
 */
spl_autoload_register( function ( $class ) {
    $prefix = 'SEOBetter\\';
    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
        return;
    }
    $relative_class = substr( $class, strlen( $prefix ) );
    $file = SEOBETTER_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
});

/**
 * Main plugin class.
 */
final class SEOBetter {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks(): void {
        add_action( 'init', [ $this, 'load_textdomain' ] );
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
        add_action( 'wp_head', [ $this, 'output_schema_markup' ], 1 );
        add_action( 'wp_head', [ $this, 'output_social_meta' ], 2 );
        add_action( 'wp_head', [ $this, 'output_ai_meta' ], 3 );
        add_action( 'save_post', [ $this, 'analyze_on_save' ], 20, 2 );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // llms.txt support
        add_action( 'init', [ $this, 'register_llms_txt_rewrite' ] );
        add_action( 'template_redirect', [ $this, 'serve_llms_txt' ] );

        // Content decay alerts cron
        add_action( 'seobetter_decay_check', [ $this, 'run_decay_check' ] );

        // Export handler
        add_action( 'admin_init', [ $this, 'handle_export' ] );

        // Posts list columns
        add_filter( 'manage_posts_columns', [ $this, 'add_posts_columns' ] );
        add_filter( 'manage_pages_columns', [ $this, 'add_posts_columns' ] );
        add_action( 'manage_posts_custom_column', [ $this, 'render_posts_column' ], 10, 2 );
        add_action( 'manage_pages_custom_column', [ $this, 'render_posts_column' ], 10, 2 );
        add_filter( 'manage_edit-post_sortable_columns', [ $this, 'sortable_columns' ] );
        add_filter( 'manage_edit-page_sortable_columns', [ $this, 'sortable_columns' ] );
        add_action( 'pre_get_posts', [ $this, 'sort_by_geo_score' ] );

        // Footer metabox (like AIOSEO's settings panel below the editor)
        add_action( 'add_meta_boxes', [ $this, 'register_metabox' ] );
        add_action( 'save_post', [ $this, 'save_metabox' ], 10, 2 );

        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'seobetter', false, dirname( SEOBETTER_PLUGIN_BASENAME ) . '/languages' );
    }

    public function activate(): void {
        $defaults = [
            'api_provider'      => 'none',
            'api_key'           => '',
            'auto_schema'       => true,
            'auto_analyze'      => true,
            'target_readability' => 7,
            'geo_engines'       => [ 'google_aio', 'perplexity', 'searchgpt', 'gemini', 'claude' ],
            'llms_txt_enabled'  => true,
        ];
        if ( false === get_option( 'seobetter_settings' ) ) {
            add_option( 'seobetter_settings', $defaults );
        }
        flush_rewrite_rules();
        SEOBetter\Decay_Alert_Manager::schedule();
    }

    public function deactivate(): void {
        SEOBetter\Decay_Alert_Manager::unschedule();
    }

    public function register_admin_menu(): void {
        add_menu_page(
            __( 'SEOBetter', 'seobetter' ),
            __( 'SEOBetter', 'seobetter' ),
            'manage_options',
            'seobetter',
            [ $this, 'render_dashboard' ],
            'dashicons-chart-line',
            30
        );
        add_submenu_page( 'seobetter', __( 'Content Generator', 'seobetter' ), __( 'Generate Content', 'seobetter' ), 'edit_posts', 'seobetter-generate', [ $this, 'render_content_generator' ] );
        add_submenu_page( 'seobetter', __( 'Bulk Generate', 'seobetter' ), __( 'Bulk Generate', 'seobetter' ), 'edit_posts', 'seobetter-bulk', [ $this, 'render_bulk_generator' ] );
        add_submenu_page( 'seobetter', __( 'Content Brief', 'seobetter' ), __( 'Content Brief', 'seobetter' ), 'edit_posts', 'seobetter-brief', [ $this, 'render_content_brief' ] );
        add_submenu_page( 'seobetter', __( 'Citation Tracker', 'seobetter' ), __( 'Citation Tracker', 'seobetter' ), 'edit_posts', 'seobetter-citations', [ $this, 'render_citation_tracker' ] );
        add_submenu_page( 'seobetter', __( 'Link Suggestions', 'seobetter' ), __( 'Link Suggestions', 'seobetter' ), 'edit_posts', 'seobetter-links', [ $this, 'render_link_suggestions' ] );
        add_submenu_page( 'seobetter', __( 'Cannibalization', 'seobetter' ), __( 'Cannibalization', 'seobetter' ), 'edit_posts', 'seobetter-cannibalization', [ $this, 'render_cannibalization' ] );
        add_submenu_page( 'seobetter', __( 'Settings', 'seobetter' ), __( 'Settings', 'seobetter' ), 'manage_options', 'seobetter-settings', [ $this, 'render_settings' ] );
    }

    public function render_dashboard(): void {
        require_once SEOBETTER_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    public function render_content_generator(): void {
        require_once SEOBETTER_PLUGIN_DIR . 'admin/views/content-generator.php';
    }

    public function render_settings(): void {
        require_once SEOBETTER_PLUGIN_DIR . 'admin/views/settings.php';
    }

    public function render_analyzer(): void {
        require_once SEOBETTER_PLUGIN_DIR . 'admin/views/analyzer.php';
    }

    public function render_tech_audit(): void {
        require_once SEOBETTER_PLUGIN_DIR . 'admin/views/tech-audit.php';
    }

    public function render_freshness(): void {
        require_once SEOBETTER_PLUGIN_DIR . 'admin/views/freshness.php';
    }

    public function render_checklist(): void {
        require_once SEOBETTER_PLUGIN_DIR . 'admin/views/checklist.php';
    }

    public function render_bulk_generator(): void {
        require_once SEOBETTER_PLUGIN_DIR . 'admin/views/bulk-generator.php';
    }

    public function render_content_brief(): void {
        require_once SEOBETTER_PLUGIN_DIR . 'admin/views/content-brief.php';
    }

    public function render_citation_tracker(): void {
        require_once SEOBETTER_PLUGIN_DIR . 'admin/views/citation-tracker.php';
    }

    public function render_link_suggestions(): void {
        require_once SEOBETTER_PLUGIN_DIR . 'admin/views/link-suggestions.php';
    }

    public function render_cannibalization(): void {
        require_once SEOBETTER_PLUGIN_DIR . 'admin/views/cannibalization.php';
    }

    public function run_decay_check(): void {
        $manager = new SEOBetter\Decay_Alert_Manager();
        $manager->run_check();
    }

    public function handle_export(): void {
        if ( ! isset( $_GET['seobetter_export'] ) || ! current_user_can( 'edit_posts' ) ) {
            return;
        }
        check_admin_referer( 'seobetter_export' );

        $format = sanitize_text_field( $_GET['format'] ?? 'html' );
        $content = wp_unslash( $_POST['export_content'] ?? '' );
        $markdown = wp_unslash( $_POST['export_markdown'] ?? '' );
        $title = sanitize_text_field( $_POST['export_title'] ?? 'article' );
        $keyword = sanitize_text_field( $_POST['export_keyword'] ?? '' );
        $slug = sanitize_title( $keyword ?: $title );

        switch ( $format ) {
            case 'markdown':
                SEOBetter\Content_Exporter::serve_download(
                    SEOBetter\Content_Exporter::export_markdown( $markdown, $keyword ),
                    "{$slug}.md",
                    'text/markdown'
                );
                break;
            case 'text':
                SEOBetter\Content_Exporter::serve_download(
                    SEOBetter\Content_Exporter::export_text( $content, $title, $keyword ),
                    "{$slug}.txt",
                    'text/plain'
                );
                break;
            default:
                SEOBetter\Content_Exporter::serve_download(
                    SEOBetter\Content_Exporter::export_html( $content, $title, $keyword ),
                    "{$slug}.html",
                    'text/html'
                );
        }
    }

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'seobetter' ) === false ) {
            return;
        }
        wp_enqueue_style( 'seobetter-admin', SEOBETTER_PLUGIN_URL . 'admin/css/admin.css', [], SEOBETTER_VERSION );
        wp_enqueue_script( 'seobetter-admin', SEOBETTER_PLUGIN_URL . 'admin/js/admin.js', [ 'jquery' ], SEOBETTER_VERSION, true );
        wp_localize_script( 'seobetter-admin', 'wpApiSettings', [
            'root'  => esc_url_raw( rest_url() ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    public function enqueue_editor_assets(): void {
        wp_enqueue_script(
            'seobetter-editor',
            SEOBETTER_PLUGIN_URL . 'assets/js/editor-sidebar.js',
            [ 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch' ],
            SEOBETTER_VERSION,
            true
        );
        wp_enqueue_style( 'seobetter-editor', SEOBETTER_PLUGIN_URL . 'assets/css/editor-sidebar.css', [], SEOBETTER_VERSION );

        wp_localize_script( 'seobetter-editor', 'seobetterData', [
            'isPro'       => SEOBetter\License_Manager::is_pro(),
            'settingsUrl' => admin_url( 'admin.php?page=seobetter-settings' ),
        ] );
    }

    public function output_schema_markup(): void {
        if ( ! is_singular() ) {
            return;
        }
        // Skip if an SEO plugin is handling schema (avoid duplication)
        if ( defined( 'AIOSEO_VERSION' ) || defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
            return;
        }
        $settings = get_option( 'seobetter_settings', [] );
        if ( empty( $settings['auto_schema'] ) ) {
            return;
        }
        $post_id = get_the_ID();
        $schema = get_post_meta( $post_id, '_seobetter_schema', true );
        if ( $schema ) {
            echo '<script type="application/ld+json">' . wp_json_encode( json_decode( $schema, true ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
        }
    }

    public function output_social_meta(): void {
        if ( ! is_singular() ) {
            return;
        }
        try {
            $social = new SEOBetter\Social_Meta_Generator();
            $social->output_meta( get_the_ID() );
        } catch ( \Throwable $e ) {
            // Silently fail
        }
    }

    /**
     * Output AI optimization meta tags for Google Discover, AI Overviews,
     * voice search, and LLM snippet extraction.
     */
    public function output_ai_meta(): void {
        if ( ! is_singular() ) {
            return;
        }

        // Allow maximum AI snippet extraction + Google Discover eligibility
        echo '<meta name="robots" content="max-image-preview:large, max-snippet:-1, max-video-preview:-1">' . "\n";

        // Article dates for AI freshness signals
        $post = get_post();
        if ( $post ) {
            echo '<meta property="article:published_time" content="' . esc_attr( get_the_date( 'c', $post ) ) . '">' . "\n";
            echo '<meta property="article:modified_time" content="' . esc_attr( get_the_modified_date( 'c', $post ) ) . '">' . "\n";
        }
    }

    public function analyze_on_save( int $post_id, \WP_Post $post ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
            return;
        }
        $settings = get_option( 'seobetter_settings', [] );
        if ( empty( $settings['auto_analyze'] ) ) {
            return;
        }

        try {
            // Check content hash to skip re-analysis if unchanged
            $content_hash = md5( $post->post_content . $post->post_title );
            $cached_hash = get_post_meta( $post_id, '_seobetter_content_hash', true );

            if ( $content_hash !== $cached_hash ) {
                $analyzer = new SEOBetter\GEO_Analyzer();
                // Prefer the saved focus keyword for keyword-density scoring;
                // fall back to the post title for organic posts.
                $kw_or_title = get_post_meta( $post_id, '_seobetter_focus_keyword', true ) ?: $post->post_title;
                $content_type = get_post_meta( $post_id, '_seobetter_content_type', true ) ?: '';
                $score = $analyzer->analyze( $post->post_content, $kw_or_title, $content_type );
                update_post_meta( $post_id, '_seobetter_geo_score', $score );
                update_post_meta( $post_id, '_seobetter_content_hash', $content_hash );

                $schema_gen = new SEOBetter\Schema_Generator();
                $schema = $schema_gen->generate( $post );
                update_post_meta( $post_id, '_seobetter_schema', wp_json_encode( $schema ) );
            }
        } catch ( \Throwable $e ) {
            // Silently fail — don't break post saving
        }
    }

    public function register_rest_routes(): void {
        register_rest_route( 'seobetter/v1', '/analyze/(?P<post_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_analyze' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
        register_rest_route( 'seobetter/v1', '/optimize', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_optimize' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
        register_rest_route( 'seobetter/v1', '/tech-audit/(?P<post_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_tech_audit' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
        register_rest_route( 'seobetter/v1', '/site-audit', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_site_audit' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ]);
        register_rest_route( 'seobetter/v1', '/freshness', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_freshness' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
        register_rest_route( 'seobetter/v1', '/citation-check/(?P<post_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_citation_check' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
        register_rest_route( 'seobetter/v1', '/link-suggestions/(?P<post_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_link_suggestions' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
        register_rest_route( 'seobetter/v1', '/cannibalization', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_cannibalization' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
        register_rest_route( 'seobetter/v1', '/bulk-process/(?P<batch_id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_bulk_process' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
        register_rest_route( 'seobetter/v1', '/refresh/(?P<post_id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_refresh_post' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
        register_rest_route( 'seobetter/v1', '/generate/start', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_generate_start' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
        register_rest_route( 'seobetter/v1', '/generate/step', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_generate_step' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
        register_rest_route( 'seobetter/v1', '/generate/result', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_generate_result' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
        register_rest_route( 'seobetter/v1', '/generate/estimate', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_generate_estimate' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
        register_rest_route( 'seobetter/v1', '/generate/improve', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_improve_content' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);

        register_rest_route( 'seobetter/v1', '/inject-fix', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_inject_fix' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
        register_rest_route( 'seobetter/v1', '/save-draft', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_save_draft' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
        // Full 80-item CORE-EEAT audit (guideline §15B)
        register_rest_route( 'seobetter/v1', '/core-eeat/(?P<post_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_core_eeat_audit' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
    }

    /**
     * REST: full 80-item CORE-EEAT audit with VETO items.
     *
     * GET /seobetter/v1/core-eeat/{post_id}
     *
     * Returns a comprehensive rubric score across CORE (Content Body) and
     * EEAT (Source Credibility), plus any triggered VETO items (C01 title
     * mismatch, R10 contradictions, T04 missing disclosures).
     */
    public function rest_core_eeat_audit( \WP_REST_Request $request ): \WP_REST_Response {
        $rate_check = $this->check_rate_limit( 'core_eeat' );
        if ( $rate_check ) return $rate_check;

        $post = get_post( $request->get_param( 'post_id' ) );
        if ( ! $post ) {
            return new \WP_REST_Response( [ 'error' => 'Post not found' ], 404 );
        }

        $keyword = get_post_meta( $post->ID, '_seobetter_focus_keyword', true ) ?: '';
        $content_type = get_post_meta( $post->ID, '_seobetter_content_type', true ) ?: '';
        $auditor = new SEOBetter\CORE_EEAT_Auditor();
        $result = $auditor->audit( $post->post_content, $post->post_title, $keyword, $content_type );

        // Cache the audit result
        update_post_meta( $post->ID, '_seobetter_core_eeat', wp_json_encode( $result ) );

        return new \WP_REST_Response( $result );
    }

    /**
     * Check REST API rate limit (50 requests/hour per user).
     */
    private function check_rate_limit( string $action ): ?\WP_REST_Response {
        $user_id = get_current_user_id();
        $key = "seobetter_rate_{$action}_{$user_id}";
        $count = (int) get_transient( $key );

        if ( $count >= 50 ) {
            return new \WP_REST_Response( [ 'error' => 'Rate limit exceeded. Try again later.' ], 429 );
        }

        set_transient( $key, $count + 1, HOUR_IN_SECONDS );
        return null;
    }

    public function rest_analyze( \WP_REST_Request $request ): \WP_REST_Response {
        $rate_check = $this->check_rate_limit( 'analyze' );
        if ( $rate_check ) return $rate_check;

        $post = get_post( $request->get_param( 'post_id' ) );
        if ( ! $post ) {
            return new \WP_REST_Response( [ 'error' => 'Post not found' ], 404 );
        }
        $content_type = get_post_meta( $post->ID, '_seobetter_content_type', true ) ?: '';
        $analyzer = new SEOBetter\GEO_Analyzer();
        // Prefer the saved focus keyword over the title so keyword-density
        // scoring (§5A) uses the actual keyword, not the headline text.
        $kw_or_title = get_post_meta( $post->ID, '_seobetter_focus_keyword', true ) ?: $post->post_title;
        $result = $analyzer->analyze( $post->post_content, $kw_or_title, $content_type );

        // Add schema info for pre-publish panel
        $schema = get_post_meta( $post->ID, '_seobetter_schema', true );
        if ( $schema ) {
            $decoded = json_decode( $schema, true );
            if ( isset( $decoded['@type'] ) ) {
                $result['schema_types'] = $decoded['@type'];
            } elseif ( isset( $decoded['@graph'] ) ) {
                $result['schema_types'] = implode( ' + ', array_column( $decoded['@graph'], '@type' ) );
            }
        }

        return new \WP_REST_Response( $result );
    }

    public function rest_optimize( \WP_REST_Request $request ): \WP_REST_Response {
        $rate_check = $this->check_rate_limit( 'optimize' );
        if ( $rate_check ) return $rate_check;

        $content = $request->get_param( 'content' );
        $methods = $request->get_param( 'methods' ) ?? [ 'statistics', 'quotations', 'citations' ];
        $domain = $request->get_param( 'domain' ) ?? 'general';

        $optimizer = new SEOBetter\GEO_Optimizer();
        $result = $optimizer->optimize( $content, $methods, $domain );
        return new \WP_REST_Response( $result );
    }

    public function rest_tech_audit( \WP_REST_Request $request ): \WP_REST_Response {
        $rate_check = $this->check_rate_limit( 'tech_audit' );
        if ( $rate_check ) return $rate_check;

        $post = get_post( $request->get_param( 'post_id' ) );
        if ( ! $post ) {
            return new \WP_REST_Response( [ 'error' => 'Post not found' ], 404 );
        }
        $auditor = new SEOBetter\Technical_SEO_Auditor();
        return new \WP_REST_Response( $auditor->audit_post( $post ) );
    }

    public function rest_site_audit( \WP_REST_Request $request ): \WP_REST_Response {
        $rate_check = $this->check_rate_limit( 'site_audit' );
        if ( $rate_check ) return $rate_check;

        $auditor = new SEOBetter\Technical_SEO_Auditor();
        return new \WP_REST_Response( $auditor->audit_site() );
    }

    public function rest_freshness( \WP_REST_Request $request ): \WP_REST_Response {
        $rate_check = $this->check_rate_limit( 'freshness' );
        if ( $rate_check ) return $rate_check;

        $manager = new SEOBetter\Content_Freshness_Manager();
        return new \WP_REST_Response( $manager->get_freshness_report() );
    }

    public function rest_citation_check( \WP_REST_Request $request ): \WP_REST_Response {
        $rate_check = $this->check_rate_limit( 'citation' );
        if ( $rate_check ) return $rate_check;

        $tracker = new SEOBetter\Citation_Tracker();
        return new \WP_REST_Response( $tracker->check_post( (int) $request->get_param( 'post_id' ) ) );
    }

    public function rest_link_suggestions( \WP_REST_Request $request ): \WP_REST_Response {
        $rate_check = $this->check_rate_limit( 'links' );
        if ( $rate_check ) return $rate_check;

        $suggester = new SEOBetter\Internal_Link_Suggester();
        return new \WP_REST_Response( $suggester->suggest_for_post( (int) $request->get_param( 'post_id' ) ) );
    }

    public function rest_cannibalization( \WP_REST_Request $request ): \WP_REST_Response {
        $rate_check = $this->check_rate_limit( 'cannibalization' );
        if ( $rate_check ) return $rate_check;

        $detector = new SEOBetter\Cannibalization_Detector();
        return new \WP_REST_Response( $detector->detect() );
    }

    public function rest_bulk_process( \WP_REST_Request $request ): \WP_REST_Response {
        $rate_check = $this->check_rate_limit( 'bulk' );
        if ( $rate_check ) return $rate_check;

        $bulk = new SEOBetter\Bulk_Generator();
        return new \WP_REST_Response( $bulk->process_next( (int) $request->get_param( 'batch_id' ) ) );
    }

    public function rest_refresh_post( \WP_REST_Request $request ): \WP_REST_Response {
        $rate_check = $this->check_rate_limit( 'refresh' );
        if ( $rate_check ) return $rate_check;

        $refresher = new SEOBetter\Content_Refresher();
        return new \WP_REST_Response( $refresher->refresh( (int) $request->get_param( 'post_id' ) ) );
    }

    public function rest_generate_start( \WP_REST_Request $request ): \WP_REST_Response {
        $rate_check = $this->check_rate_limit( 'generate' );
        if ( $rate_check ) return $rate_check;

        return new \WP_REST_Response( SEOBetter\Async_Generator::start_job( $request->get_params() ) );
    }

    public function rest_generate_step( \WP_REST_Request $request ): \WP_REST_Response {
        $job_id = sanitize_text_field( $request->get_param( 'job_id' ) );
        if ( ! $job_id ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => 'Missing job_id' ], 400 );
        }
        return new \WP_REST_Response( SEOBetter\Async_Generator::process_step( $job_id ) );
    }

    public function rest_generate_result( \WP_REST_Request $request ): \WP_REST_Response {
        $job_id = sanitize_text_field( $request->get_param( 'job_id' ) );
        if ( ! $job_id ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => 'Missing job_id' ], 400 );
        }
        return new \WP_REST_Response( SEOBetter\Async_Generator::get_result( $job_id ) );
    }

    public function rest_generate_estimate( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( SEOBetter\Async_Generator::get_estimate() );
    }

    public function rest_save_draft( \WP_REST_Request $request ): \WP_REST_Response {
        $title    = sanitize_text_field( $request->get_param( 'title' ) ?? 'New Article' );
        $markdown = $request->get_param( 'markdown' ) ?? '';
        $content  = $request->get_param( 'content' ) ?? '';
        $accent   = sanitize_text_field( $request->get_param( 'accent_color' ) ?? '#764ba2' );

        if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $accent ) ) {
            $accent = '#764ba2';
        }

        // Retrieve the citation pool that was built during generation.
        // Pool entries = [ { url, title, source_name, verified_at }, ... ]
        $pool_raw = $request->get_param( 'citation_pool' );
        $citation_pool = is_array( $pool_raw ) ? $pool_raw : [];

        $post_content = '';

        // Validate all outbound URLs in markdown before formatting.
        // The citation pool is passed as the primary allow-list — any URL in
        // the pool is citable, any URL not in the pool falls back to the
        // static whitelist and Pass 3 content verification.
        if ( ! empty( $markdown ) ) {
            $markdown = $this->validate_outbound_links( $markdown, $citation_pool );
            // Append auto-generated References section for pool URLs the
            // article body actually cited
            $markdown = $this->append_references_section( $markdown, $citation_pool );
        }

        if ( ! empty( $markdown ) ) {
            // Hybrid format: native Gutenberg blocks for headings, paragraphs, lists, images
            // (editable in block editor) + wp:html blocks for styled elements only
            // (key takeaways, tables, blockquotes, pros/cons, callout boxes, tips)
            $formatter = new SEOBetter\Content_Formatter();
            $post_content = $formatter->format( $markdown, 'hybrid', [
                'accent_color' => $accent,
                // v1.5.14 — thread content_type so format_hybrid() can render
                // HowTo step-number boxes for ordered lists in how_to articles
                'content_type' => sanitize_text_field( $request->get_param( 'content_type' ) ?? 'blog_post' ),
            ] );
        }

        // Fallback to raw content if gutenberg formatting produced nothing
        if ( empty( trim( $post_content ) ) && ! empty( $content ) ) {
            $content = $this->validate_outbound_links( $content, $citation_pool );
            $post_content = $content;
        }

        // Last resort: wrap markdown in HTML block
        if ( empty( trim( $post_content ) ) && ! empty( $markdown ) ) {
            $post_content = "<!-- wp:html -->\n" . nl2br( esc_html( $markdown ) ) . "\n<!-- /wp:html -->";
        }

        if ( empty( trim( $post_content ) ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => 'No content to save.' ], 400 );
        }

        $post_id = wp_insert_post( [
            'post_title'   => $title,
            'post_content' => $post_content,
            'post_status'  => 'draft',
            'post_type'    => sanitize_text_field( $request->get_param( 'post_type' ) ?? 'post' ),
        ] );

        if ( is_wp_error( $post_id ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => $post_id->get_error_message() ], 500 );
        }

        // Set featured image — download from Picsum to media library
        $this->set_featured_image( $post_id, sanitize_text_field( $request->get_param( 'keyword' ) ?? $title ) );

        // Set freshness signal via WordPress post meta (not hardcoded text in article)
        // dateModified in Article schema is auto-pulled from get_the_modified_date() in populate_aioseo()
        update_post_meta( $post_id, '_seobetter_generated_date', current_time( 'mysql' ) );
        update_post_meta( $post_id, '_seobetter_last_updated', wp_date( 'F Y' ) );

        // Store SEOBetter meta
        $keyword = sanitize_text_field( $request->get_param( 'keyword' ) ?? '' );
        $meta_title = sanitize_text_field( $request->get_param( 'meta_title' ) ?? '' );
        $meta_desc = sanitize_text_field( $request->get_param( 'meta_description' ) ?? '' );
        $og_title = sanitize_text_field( $request->get_param( 'og_title' ) ?? '' );

        if ( $keyword ) {
            update_post_meta( $post_id, '_seobetter_focus_keyword', $keyword );
        }
        if ( $meta_title ) {
            update_post_meta( $post_id, '_seobetter_meta_title', $meta_title );
        }
        if ( $meta_desc ) {
            update_post_meta( $post_id, '_seobetter_meta_description', $meta_desc );
        }

        // Store 5-Part Framework phase report (§28) if provided by generator
        $framework_raw = $request->get_param( 'framework' );
        if ( is_array( $framework_raw ) && ! empty( $framework_raw ) ) {
            update_post_meta( $post_id, '_seobetter_framework_report', wp_json_encode( $framework_raw ) );
            $q5 = $framework_raw['phase_5_quality_gate'] ?? [];
            if ( isset( $q5['passed'] ) ) {
                update_post_meta( $post_id, '_seobetter_quality_gate', $q5['passed'] ? 'passed' : 'failed' );
            }
        }

        // Populate AIOSEO fields if the plugin is active
        if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
            $content_type = sanitize_text_field( $request->get_param( 'content_type' ) ?? 'blog_post' );
            $this->populate_aioseo( $post_id, $keyword, $meta_title ?: $title, $meta_desc, $og_title ?: $meta_title ?: $title, $post_content, $content_type );
        }

        // Also populate Yoast and RankMath if active (covers all SEO plugins)
        if ( defined( 'WPSEO_VERSION' ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_title', $meta_title ?: $title );
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
            update_post_meta( $post_id, '_yoast_wpseo_focuskw', $keyword );
        }
        if ( class_exists( 'RankMath' ) ) {
            update_post_meta( $post_id, 'rank_math_title', $meta_title ?: $title );
            update_post_meta( $post_id, 'rank_math_description', $meta_desc );
            update_post_meta( $post_id, 'rank_math_focus_keyword', $keyword );
        }

        // Build and inject JSON-LD schema directly into the post content
        // This guarantees schema is in the article regardless of SEO plugin
        $content_type_param = sanitize_text_field( $request->get_param( 'content_type' ) ?? 'blog_post' );
        $schema_type_for_ld = $this->content_type_to_schema( $content_type_param );
        $schema_array = $this->build_aioseo_schema( $schema_type_for_ld, $post_id, $meta_title ?: $title, $post_content, $keyword );

        if ( ! empty( $schema_array ) ) {
            $schema_ld = [ '@context' => 'https://schema.org' ];
            if ( count( $schema_array ) === 1 ) {
                $schema_ld = array_merge( $schema_ld, $schema_array[0] );
            } else {
                $schema_ld['@graph'] = $schema_array;
            }
            $schema_json = wp_json_encode( $schema_ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

            // Append JSON-LD as a wp:html block at the end of post content
            $schema_block = "\n\n<!-- wp:html -->\n<script type=\"application/ld+json\">\n{$schema_json}\n</script>\n<!-- /wp:html -->";

            wp_update_post( [
                'ID'           => $post_id,
                'post_content' => $post_content . $schema_block,
            ] );

            // Also store in post meta for wp_head fallback
            update_post_meta( $post_id, '_seobetter_schema', wp_json_encode( $schema_ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        }

        // Detect where schema is going for user feedback
        $schema_dest = 'article';

        return new \WP_REST_Response( [
            'success'     => true,
            'post_id'     => $post_id,
            'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
            'schema_dest' => $schema_dest,
        ] );
    }

    /**
     * Re-format and re-score improved content (called by Fix Now buttons).
     */
    public function rest_inject_fix( \WP_REST_Request $request ): \WP_REST_Response {
        $fix_type = sanitize_text_field( $request->get_param( 'fix_type' ) ?? '' );
        $markdown = $request->get_param( 'markdown' ) ?? '';
        $keyword  = sanitize_text_field( $request->get_param( 'keyword' ) ?? '' );
        $accent   = sanitize_text_field( $request->get_param( 'accent_color' ) ?? '#764ba2' );

        if ( empty( $markdown ) || empty( $fix_type ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => 'Missing markdown or fix_type.' ], 400 );
        }

        // Run the appropriate fix
        switch ( $fix_type ) {
            case 'citations':
                $result = SEOBetter\Content_Injector::inject_citations( $markdown, $keyword );
                break;
            case 'quotes':
                $result = SEOBetter\Content_Injector::inject_quotes( $markdown, $keyword );
                break;
            case 'table':
                $result = SEOBetter\Content_Injector::inject_table( $markdown, $keyword );
                break;
            case 'freshness':
                $result = SEOBetter\Content_Injector::inject_freshness( $markdown );
                break;
            case 'statistics':
                $result = SEOBetter\Content_Injector::inject_statistics( $markdown, $keyword );
                break;
            case 'readability':
                $result = SEOBetter\Content_Injector::flag_readability( $markdown );
                return new \WP_REST_Response( $result );
            case 'island':
                $result = SEOBetter\Content_Injector::flag_pronouns( $markdown );
                return new \WP_REST_Response( $result );
            case 'openers':
                $result = SEOBetter\Content_Injector::flag_openers( $markdown );
                return new \WP_REST_Response( $result );
            default:
                return new \WP_REST_Response( [ 'success' => false, 'error' => 'Unknown fix type.' ], 400 );
        }

        if ( ! $result['success'] ) {
            return new \WP_REST_Response( $result, 400 );
        }

        // Re-format and re-score the updated content
        $updated_markdown = $result['content'];

        // Strip any hallucinated or non-whitelisted links the injector may have added
        $updated_markdown = $this->validate_outbound_links( $updated_markdown );

        $formatter = new SEOBetter\Content_Formatter();
        $html = $formatter->format( $updated_markdown, 'classic', [ 'accent_color' => $accent ] );

        $analyzer = new SEOBetter\GEO_Analyzer();
        $score = $analyzer->analyze( $html, $keyword );

        return new \WP_REST_Response( [
            'success'   => true,
            'content'   => $html,
            'markdown'  => $updated_markdown,
            'geo_score' => $score['geo_score'],
            'grade'     => $score['grade'],
            'checks'    => $score['checks'],
            'added'     => $result['added'] ?? '',
            'type'      => $result['type'] ?? $fix_type,
        ] );
    }

    public function rest_improve_content( \WP_REST_Request $request ): \WP_REST_Response {
        $markdown = $request->get_param( 'markdown' ) ?? '';
        $keyword  = sanitize_text_field( $request->get_param( 'keyword' ) ?? '' );
        $accent   = sanitize_text_field( $request->get_param( 'accent_color' ) ?? '#764ba2' );

        if ( empty( $markdown ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => 'No content.' ], 400 );
        }

        // Format as classic HTML
        $formatter = new SEOBetter\Content_Formatter();
        $html = $formatter->format( $markdown, 'classic', [ 'accent_color' => $accent ] );

        // Re-score
        $analyzer = new SEOBetter\GEO_Analyzer();
        $score = $analyzer->analyze( $html, $keyword );

        return new \WP_REST_Response( [
            'success'    => true,
            'content'    => $html,
            'geo_score'  => $score['geo_score'],
            'grade'      => $score['grade'],
            'word_count' => str_word_count( wp_strip_all_tags( $html ) ),
            'checks'     => $score['checks'],
        ] );
    }

    /**
     * Populate AIOSEO fields for a post.
     */
    private function populate_aioseo( int $post_id, string $keyword, string $seo_title, string $meta_desc, string $og_title, string $content = '', string $content_type = '' ): void {
        global $wpdb;

        // Social meta
        $fb_title = mb_strlen( $og_title ) > 95 ? mb_substr( $og_title, 0, 92 ) . '...' : $og_title;
        $fb_desc = mb_strlen( $meta_desc ) > 200 ? mb_substr( $meta_desc, 0, 197 ) . '...' : $meta_desc;
        $tw_title = mb_strlen( $og_title ) > 70 ? mb_substr( $og_title, 0, 67 ) . '...' : $og_title;
        $tw_desc = mb_strlen( $meta_desc ) > 200 ? mb_substr( $meta_desc, 0, 197 ) . '...' : $meta_desc;

        // Article Tags
        $tags = array_filter( array_map( 'trim', explode( ' ', strtolower( $keyword ) ) ), fn( $t ) => strlen( $t ) > 2 );
        $tags[] = strtolower( $keyword );
        $tags = array_values( array_unique( $tags ) );

        $categories = wp_get_post_categories( $post_id, [ 'fields' => 'names' ] );
        $article_section = ! empty( $categories ) ? $categories[0] : 'General';

        // --- Detect article type and build schema ---
        // Use user-selected content type if provided, otherwise auto-detect from title
        $schema_type = $content_type ? $this->content_type_to_schema( $content_type ) : $this->detect_schema_type( $seo_title, $content );
        $schema_data = $this->build_aioseo_schema( $schema_type, $post_id, $seo_title, $content, $keyword );

        // Store schema in post meta for wp_head output (used when no SEO plugin active)
        $schema_with_context = [ '@context' => 'https://schema.org' ];
        if ( count( $schema_data ) === 1 ) {
            $schema_with_context = array_merge( $schema_with_context, $schema_data[0] );
        } else {
            $schema_with_context['@graph'] = $schema_data;
        }
        update_post_meta( $post_id, '_seobetter_schema', wp_json_encode( $schema_with_context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

        // AIOSEO table
        $table = $wpdb->prefix . 'aioseo_posts';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            update_post_meta( $post_id, '_aioseo_title', $seo_title );
            update_post_meta( $post_id, '_aioseo_description', $meta_desc );
            update_post_meta( $post_id, '_aioseo_og_title', $fb_title );
            update_post_meta( $post_id, '_aioseo_og_description', $fb_desc );
            update_post_meta( $post_id, '_aioseo_twitter_title', $tw_title );
            update_post_meta( $post_id, '_aioseo_twitter_description', $tw_desc );
            return;
        }

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d", $post_id
        ) );

        $data = [
            'post_id'              => $post_id,
            'title'                => $seo_title,
            'description'          => $meta_desc,
            'keyphrases'           => wp_json_encode( [
                'focus'      => [ 'keyphrase' => $keyword ],
                'additional' => [],
            ] ),
            'og_title'             => $fb_title,
            'og_description'       => $fb_desc,
            'og_object_type'       => 'article',
            'og_image_type'        => 'featured',
            'og_article_section'   => $article_section,
            'og_article_tags'      => wp_json_encode( $tags ),
            'twitter_title'        => $tw_title,
            'twitter_description'  => $tw_desc,
            'twitter_card'         => 'summary_large_image',
            'twitter_image_type'   => 'featured',
            'twitter_use_og'       => 0,
            // Schema
            'schema'               => wp_json_encode( $schema_data ),
            'updated'              => current_time( 'mysql' ),
        ];

        if ( $exists ) {
            $wpdb->update( $table, $data, [ 'post_id' => $post_id ] );
        } else {
            $data['created'] = current_time( 'mysql' );
            $wpdb->insert( $table, $data );
        }
    }

    /**
     * Detect the schema type based on article title and content.
     */
    /**
     * Map content type to schema type string used by build_aioseo_schema.
     */
    private function content_type_to_schema( string $content_type ): string {
        $map = [
            'blog_post'          => 'article',
            'news_article'       => 'news',
            'opinion'            => 'opinion',
            'how_to'             => 'howto',
            'listicle'           => 'listicle',
            'review'             => 'review',
            'comparison'         => 'comparison',
            'buying_guide'       => 'buying_guide',
            'pillar_guide'       => 'pillar_guide',
            'case_study'         => 'case_study',
            'interview'          => 'interview',
            'faq_page'           => 'faq',
            'recipe'             => 'recipe',
            'tech_article'       => 'tech',
            'white_paper'        => 'report',
            'scholarly_article'  => 'scholarly',
            'live_blog'          => 'liveblog',
            'press_release'      => 'news',
            'personal_essay'     => 'article',
            'glossary_definition'=> 'glossary',
            'sponsored'          => 'sponsored',
        ];
        return $map[ $content_type ] ?? 'article';
    }

    private function detect_schema_type( string $title, string $content ): string {
        $title_lower = strtolower( $title );
        $content_lower = strtolower( $content );

        // How-to / Guide
        if ( preg_match( '/\b(how to|step.by.step|tutorial|guide|instructions|diy)\b/i', $title_lower ) ) {
            return 'howto';
        }

        // FAQ
        if ( preg_match( '/\b(faq|frequently asked|questions and answers)\b/i', $title_lower ) ) {
            return 'faq';
        }

        // Review / comparison
        if ( preg_match( '/\b(review|vs\.?|versus|comparison|compared|best \d+|top \d+)\b/i', $title_lower ) ) {
            return 'review';
        }

        // Product / buying guide
        if ( preg_match( '/\b(buy|price|cost|cheap|affordable|shop|store|deal)\b/i', $title_lower ) ) {
            return 'product';
        }

        // Check content for FAQ section (most articles have one)
        if ( preg_match( '/frequently asked questions|<h[23][^>]*>.*\?/i', $content_lower ) ) {
            return 'article_with_faq';
        }

        return 'article';
    }

    /**
     * Build AIOSEO schema JSON based on detected type.
     * AIOSEO stores schema as a JSON object in the `schema` column.
     */
    private function build_aioseo_schema( string $type, int $post_id, string $title, string $content, string $keyword ): array {
        $post = get_post( $post_id );
        $url = get_permalink( $post_id );
        $author = get_userdata( $post ? $post->post_author : 0 );
        $author_name = $author ? $author->display_name : 'Author';
        $thumbnail = get_the_post_thumbnail_url( $post_id, 'full' );
        $date_pub = $post ? get_the_date( 'c', $post ) : '';
        $date_mod = $post ? get_the_modified_date( 'c', $post ) : '';

        // Map type to schema.org @type
        $type_map = [
            'article'          => 'Article',
            'article_with_faq' => 'Article',
            'listicle'         => 'Article',
            'news'             => 'NewsArticle',
            'opinion'          => 'OpinionNewsArticle',
            'howto'            => 'HowTo',
            'review'           => 'Review',
            'faq'              => 'FAQPage',
            'product'          => 'Product',
            'buying_guide'     => 'Article',
            'comparison'       => 'Article',
            'pillar_guide'     => 'Article',
            'case_study'       => 'Article',
            'interview'        => 'Article',
            'tech'             => 'TechArticle',
            'report'           => 'Report',
            'scholarly'        => 'ScholarlyArticle',
            'liveblog'         => 'LiveBlogPosting',
            'sponsored'        => 'AdvertiserContentArticle',
            'recipe'           => 'Recipe',
            'glossary'         => 'DefinedTerm',
        ];
        $schema_at_type = $type_map[ $type ] ?? 'Article';

        // Base Article schema (always present)
        $schemas = [];

        $article = [
            '@type'            => $schema_at_type,
            'headline'         => $title,
            'description'      => wp_trim_words( wp_strip_all_tags( $content ), 30 ),
            'datePublished'    => $date_pub,
            'dateModified'     => $date_mod,
            'author'           => [ '@type' => 'Person', 'name' => $author_name ],
            'mainEntityOfPage' => $url,
        ];
        if ( $thumbnail ) {
            $article['image'] = $thumbnail;
        }
        $schemas[] = $article;

        // Extract FAQ Q&A pairs from content — most articles have a FAQ section
        $faq_pairs = [];
        if ( ! in_array( $type, [ 'glossary', 'liveblog', 'recipe' ], true ) ) {
            // Match H3 questions followed by paragraph answers
            if ( preg_match_all( '/<h3[^>]*>(.*?\?)<\/h3>\s*(?:<!--[^>]*-->\s*)*<p[^>]*>(.*?)<\/p>/is', $content, $faq_matches, PREG_SET_ORDER ) ) {
                foreach ( $faq_matches as $m ) {
                    $q = wp_strip_all_tags( $m[1] );
                    $a = wp_strip_all_tags( $m[2] );
                    if ( strlen( $q ) > 5 && strlen( $a ) > 10 ) {
                        $faq_pairs[] = [ 'question' => $q, 'answer' => $a ];
                    }
                }
            }
            // Also try markdown-style ### Question? patterns
            if ( empty( $faq_pairs ) && preg_match_all( '/###\s*(.*?\?)\s*\n+([^\n#]+)/i', $content, $md_matches, PREG_SET_ORDER ) ) {
                foreach ( $md_matches as $m ) {
                    $q = trim( $m[1] );
                    $a = trim( $m[2] );
                    if ( strlen( $q ) > 5 && strlen( $a ) > 10 ) {
                        $faq_pairs[] = [ 'question' => $q, 'answer' => $a ];
                    }
                }
            }
        }

        // Add FAQPage schema if we found Q&A pairs
        if ( ! empty( $faq_pairs ) ) {
            $faq_items = [];
            foreach ( array_slice( $faq_pairs, 0, 10 ) as $pair ) {
                $faq_items[] = [
                    '@type'          => 'Question',
                    'name'           => $pair['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text'  => $pair['answer'],
                    ],
                ];
            }
            $schemas[] = [
                '@type'      => 'FAQPage',
                'mainEntity' => $faq_items,
            ];
        }

        // Add HowTo schema if detected
        if ( $type === 'howto' ) {
            $steps = [];
            if ( preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $content, $step_matches ) ) {
                $step_num = 0;
                foreach ( array_slice( $step_matches[1], 0, 10 ) as $step_text ) {
                    $step_num++;
                    $clean = wp_strip_all_tags( $step_text );
                    if ( strlen( $clean ) > 5 ) {
                        $steps[] = [
                            '@type'    => 'HowToStep',
                            'position' => $step_num,
                            'text'     => $clean,
                        ];
                    }
                }
            }
            if ( ! empty( $steps ) ) {
                $schemas[] = [
                    '@type' => 'HowTo',
                    'name'  => $title,
                    'step'  => $steps,
                ];
            }
        }

        // Add Recipe schema if recipe type
        if ( $type === 'recipe' ) {
            $recipe = [
                '@type'       => 'Recipe',
                'name'        => $title,
                'description' => wp_trim_words( wp_strip_all_tags( $content ), 30 ),
                'author'      => [ '@type' => 'Person', 'name' => $author_name ],
                'datePublished' => $date_pub,
            ];
            if ( $thumbnail ) $recipe['image'] = $thumbnail;
            // Extract ingredients from list items
            if ( preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $content, $ing_matches ) ) {
                $recipe['recipeIngredient'] = array_map( 'wp_strip_all_tags', array_slice( $ing_matches[1], 0, 30 ) );
            }
            // Extract instructions from ordered list or steps
            if ( preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $content, $step_matches ) ) {
                $recipe_steps = [];
                foreach ( array_slice( $step_matches[1], 0, 15 ) as $s ) {
                    $recipe_steps[] = [ '@type' => 'HowToStep', 'text' => wp_strip_all_tags( $s ) ];
                }
                $recipe['recipeInstructions'] = $recipe_steps;
            }
            $schemas[] = $recipe;
        }

        // Add Review schema if review type
        if ( $type === 'review' ) {
            $schemas[] = [
                '@type'        => 'Review',
                'name'         => $title,
                'author'       => [ '@type' => 'Person', 'name' => $author_name ],
                'datePublished' => $date_pub,
                'itemReviewed' => [
                    '@type' => 'Product',
                    'name'  => $keyword ?: $title,
                ],
            ];
        }

        // Add ItemList schema for listicle, buying_guide, comparison, pillar_guide
        if ( in_array( $type, [ 'listicle', 'buying_guide', 'comparison', 'pillar_guide' ], true ) ) {
            $list_items = [];
            if ( preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/is', $content, $h2_matches ) ) {
                $pos = 0;
                foreach ( $h2_matches[1] as $h2_text ) {
                    $clean = wp_strip_all_tags( $h2_text );
                    // Skip non-item headings (Key Takeaways, FAQ, References, Conclusion, Introduction)
                    if ( preg_match( '/key\s*takeaway|faq|frequently|reference|conclusion|introduction/i', $clean ) ) continue;
                    $pos++;
                    $list_items[] = [
                        '@type'    => 'ListItem',
                        'position' => $pos,
                        'name'     => $clean,
                    ];
                }
            }
            if ( count( $list_items ) >= 2 ) {
                $schemas[] = [
                    '@type'           => 'ItemList',
                    'itemListElement' => $list_items,
                ];
            }
        }

        // Build DefinedTerm for glossary entries — replaces the base Article schema above
        if ( $type === 'glossary' ) {
            // Remove the generic Article we pushed first and replace with DefinedTerm
            array_shift( $schemas );
            $schemas[] = [
                '@type'            => 'DefinedTerm',
                'name'             => $title,
                'description'      => wp_trim_words( wp_strip_all_tags( $content ), 60 ),
                'url'              => $url,
                'inDefinedTermSet' => [
                    '@type' => 'DefinedTermSet',
                    'name'  => get_bloginfo( 'name' ) . ' Glossary',
                    'url'   => home_url(),
                ],
            ];
        }

        // Add Product schema as a secondary entity for buying guides
        if ( $type === 'buying_guide' ) {
            $schemas[] = [
                '@type'       => 'Product',
                'name'        => $keyword ?: $title,
                'description' => wp_trim_words( wp_strip_all_tags( $content ), 30 ),
                'image'       => $thumbnail ?: '',
                'review'      => [
                    '@type'        => 'Review',
                    'author'       => [ '@type' => 'Person', 'name' => $author_name ],
                    'reviewRating' => [
                        '@type'       => 'Rating',
                        'ratingValue' => '4.5',
                        'bestRating'  => '5',
                    ],
                ],
            ];
        }

        // Store content type in post meta for future reference
        if ( $type ) {
            update_post_meta( $post_id, '_seobetter_content_type', $type );
        }

        return $schemas;
    }

    /**
     * Download an image and set it as the post's featured image.
     * Uses Lorem Picsum (1200x630 for OG/social sharing compatibility).
     */
    /**
     * Set a topic-relevant featured image for the post.
     * Uses Pexels API (free, 15K req/month) for keyword-relevant photos.
     * Falls back to downloading a generic image if Pexels unavailable.
     */
    /**
     * Validate all outbound URLs in markdown content.
     * Does HEAD requests to check each link. Replaces 404s with homepage or removes link.
     * Runs before formatting to catch hallucinated URLs from any AI model.
     */
    /**
     * Strip or validate all outbound links in the article.
     *
     * This runs in two passes:
     *
     * 1. MALFORMED LINK PASS — strips markdown links whose "URL" isn't actually a URL
     *    (e.g. [Dog Facts API](Dog Facts API) — AI outputs literal text where a URL belongs,
     *    which WordPress then resolves as a relative URL against /wp-admin/). These are
     *    always hallucinations and get replaced with plain text.
     *
     * 2. DOMAIN WHITELIST PASS — only keeps links pointing to known-authoritative domains.
     *    Everything else gets unlinked (text preserved). This is deliberately strict because
     *    AI models frequently hallucinate plausible-looking but dead API/blog URLs (e.g.
     *    dog-facts-api.herokuapp.com, dog-api.kinduff.com/api/facts), and HEAD-request
     *    validation isn't reliable enough — dead domains return network errors, not 404s,
     *    which used to fall back to "homepage" links that were also dead.
     */
    /**
     * @param string $markdown       Raw article markdown
     * @param array  $citation_pool  Optional per-article pool of verified URLs
     *                               (from Citation_Pool::build). When provided,
     *                               any URL in the pool passes the whitelist
     *                               requirement regardless of domain.
     */
    private function validate_outbound_links( string $markdown, array $citation_pool = [] ): string {
        // ===== Pass 0: Sanitize any References / Sources section =====
        // The AI often generates a numbered References section with hallucinated
        // URLs. We strip individual reference lines whose links don't pass the
        // whitelist. If the whole section ends up empty, we remove the heading too.
        $markdown = $this->sanitize_references_section( $markdown );

        // ===== Pass -1 (runs LAST) is scheduled at end of this method =====
        // See verify_citation_atoms() — fine-grained knowledge verification adapted
        // from arxiv 2602.05723 (RLFKV). Fetches each surviving link and confirms
        // the destination page content matches the anchor text.

        // ===== Pass 1: Strip malformed markdown links =====
        // Matches [text](anything-not-starting-with-http) — catches cases where AI puts
        // literal text in the URL slot, or paths like /wp-admin/... or /blog/whatever
        $markdown = preg_replace_callback(
            '/(?<!!)\[([^\]]+)\]\(((?!https?:\/\/)[^)]*)\)/',
            function ( $m ) {
                // Keep the link text, drop the broken link wrapper
                return $m[1];
            },
            $markdown
        );

        // ===== Pass 2: Strict filtering of all real external links =====
        $whitelist = $this->get_trusted_domain_whitelist();
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );

        $filter_link = function ( $url, $text ) use ( $whitelist, $site_host, $citation_pool ) {
            $host = wp_parse_url( $url, PHP_URL_HOST );
            $path = wp_parse_url( $url, PHP_URL_PATH );

            // Malformed URL — strip
            if ( ! $host ) {
                return [ 'keep' => false, 'text' => $text ];
            }

            // Internal link — always keep
            if ( $host === $site_host ) {
                return [ 'keep' => true ];
            }

            // Hard-fail rules (apply regardless of pool membership)
            //
            // Anchor text must not be an API / dataset / tool name
            if ( preg_match( '/\b(api|endpoint|dataset|sdk|webhook)\b/i', $text ) ) {
                return [ 'keep' => false, 'text' => $text ];
            }
            // URL must not be an API or developer endpoint
            if ( preg_match( '#/api/|/v[1-9]/|/graphql|/rest/|/swagger|raw\.githubusercontent\.com#i', $url ) ) {
                return [ 'keep' => false, 'text' => $text ];
            }
            // Host must not look like an API host
            if ( preg_match( '/(^|\.)api\.|-api\.|\.herokuapp\.com$/i', $host ) ) {
                return [ 'keep' => false, 'text' => $text ];
            }
            // URL must be a DEEP link (has a real path) — not a bare homepage
            $trimmed_path = trim( (string) $path, '/' );
            if ( $trimmed_path === '' || $trimmed_path === 'index.html' || $trimmed_path === 'index.php' ) {
                return [ 'keep' => false, 'text' => $text ];
            }

            // Primary allow-list: citation pool membership
            // If the URL is in this article's verified pool, accept it even
            // if the domain isn't on the static whitelist.
            if ( ! empty( $citation_pool ) && \SEOBetter\Citation_Pool::contains_url( $citation_pool, $url ) ) {
                return [ 'keep' => true ];
            }

            // Fallback allow-list: static domain whitelist
            // Used when the pool is empty (obscure keyword) or doesn't contain
            // this specific URL. Domains like *.gov, wikipedia.org, rspca.org.au
            // etc. remain citable even without pool membership.
            if ( $this->is_host_trusted( $host, $whitelist ) ) {
                return [ 'keep' => true ];
            }

            return [ 'keep' => false, 'text' => $text ];
        };

        // Markdown links — negative lookbehind for `!` so image markdown
        // `![alt](url)` is NEVER matched. Without this guard, Pass 2 would
        // strip the inner `[alt](url)` of any image whose URL isn't on the
        // whitelist, leaving a stray `!` at the start of the line. See FM-13
        // in seo-guidelines/external-links-policy.md.
        $markdown = preg_replace_callback(
            '/(?<!!)\[([^\]]+)\]\((https?:\/\/[^)]+)\)/',
            function ( $m ) use ( $filter_link ) {
                $res = $filter_link( $m[2], $m[1] );
                return $res['keep'] ? $m[0] : $res['text'];
            },
            $markdown
        );

        // HTML anchor tags — <a href="...">text</a>
        $markdown = preg_replace_callback(
            '/<a\s+[^>]*href="(https?:\/\/[^"]+)"[^>]*>(.*?)<\/a>/is',
            function ( $m ) use ( $filter_link ) {
                $res = $filter_link( $m[1], wp_strip_all_tags( $m[2] ) );
                return $res['keep'] ? $m[0] : $res['text'];
            },
            $markdown
        );

        // ===== Pass 3: Fine-grained knowledge verification =====
        // Adapted from RLFKV (arxiv 2602.05723). For each remaining link, fetch
        // the destination and verify the anchor text's key terms appear in the
        // page content. A live URL on a whitelisted domain is not enough — the
        // linked page must actually be about what we claim it is.
        //
        // Pool URLs already passed content verification at pool-build time,
        // but we re-verify against the ANCHOR TEXT here — because a pool URL
        // about "dog beds" shouldn't be cited with anchor text "dog food",
        // even though both are dog-related.
        $markdown = $this->verify_citation_atoms( $markdown );

        // ===== Pass 4: URL deduplication (v1.5.18) =====
        // The system prompt tells the AI "use each pool URL at most once" but
        // the AI sometimes ignores it (e.g. linking en.wikipedia.org/Dog_food
        // 3 times to "dog food"). This pass walks all surviving links in
        // document order and unlinks the 2nd+ occurrence of any URL — the
        // anchor text is preserved as plain text. Same logic for both markdown
        // links [text](url) and HTML anchors <a href="url">text</a>. URLs are
        // normalized (lowercase host, strip trailing slash) so that
        // example.com/page and Example.com/page/ count as the same.
        $seen_urls = [];
        $normalize = function ( $url ) {
            $parts = wp_parse_url( $url );
            if ( ! $parts || empty( $parts['host'] ) ) return strtolower( $url );
            $host = strtolower( $parts['host'] );
            $path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
            $query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
            return $host . $path . $query;
        };
        // Pass 4a: markdown links — negative lookbehind for `!` so image markdown
        // `![alt](url)` is never matched
        $markdown = preg_replace_callback(
            '/(?<!!)\[([^\]]+)\]\((https?:\/\/[^)]+)\)/',
            function ( $m ) use ( &$seen_urls, $normalize ) {
                $key = $normalize( $m[2] );
                if ( isset( $seen_urls[ $key ] ) ) {
                    return $m[1]; // strip wrapper, keep anchor text
                }
                $seen_urls[ $key ] = true;
                return $m[0];
            },
            $markdown
        );
        // Pass 4b: HTML anchor tags
        $markdown = preg_replace_callback(
            '/<a\s+[^>]*href="(https?:\/\/[^"]+)"[^>]*>(.*?)<\/a>/is',
            function ( $m ) use ( &$seen_urls, $normalize ) {
                $key = $normalize( $m[1] );
                if ( isset( $seen_urls[ $key ] ) ) {
                    return wp_strip_all_tags( $m[2] );
                }
                $seen_urls[ $key ] = true;
                return $m[0];
            },
            $markdown
        );

        return $markdown;
    }

    /**
     * Fine-grained knowledge unit verification for surviving citations.
     *
     * Adapted from "Mitigating Hallucination in Financial RAG via Fine-Grained
     * Knowledge Verification" (Yin et al., arxiv 2602.05723). The paper proposes
     * decomposing model responses into atomic knowledge units (entity, metric,
     * value, timestamp quadruples) and verifying each unit against the retrieved
     * source documents before accepting the output.
     *
     * For our citation system, each [anchor text](url) pair is an atomic unit.
     * We verify by fetching the destination page and checking that at least
     * half of the anchor text's content words (4+ chars, non-stopword) appear
     * in the destination's title or first ~3000 chars of body text. Links that
     * fail verification are unlinked (text preserved). Results are cached in
     * WordPress transients for 24 hours per URL to keep latency bounded.
     *
     * This catches failures the earlier whitelist passes miss — for example,
     * an AI citing "dog nutrition study" and linking to a real but unrelated
     * page on rspca.org.au/about-us.
     */
    private function verify_citation_atoms( string $markdown ): string {
        // Negative lookbehind for `!` — do not treat image markdown as a link
        if ( ! preg_match_all( '/(?<!!)\[([^\]]+)\]\((https?:\/\/[^)]+)\)/', $markdown, $matches, PREG_SET_ORDER ) ) {
            return $markdown;
        }

        $stopwords = [
            'about','above','after','again','against','all','and','any','are',
            'because','been','before','being','below','between','both','but',
            'can','did','does','doing','down','during','each','few','for',
            'from','further','had','has','have','having','here','how','into',
            'its','itself','just','more','most','myself','nor','not','now',
            'off','once','only','other','our','ours','out','over','own','same',
            'she','should','some','such','than','that','the','their','theirs',
            'them','themselves','then','there','these','they','this','those',
            'through','too','under','until','very','was','were','what','when',
            'where','which','while','who','whom','why','will','with','you',
            'your','yours','with','inc','llc','ltd','org','com',
        ];

        $session_cache = [];

        foreach ( $matches as $match ) {
            $full_match = $match[0];
            $anchor_text = $match[1];
            $url = $match[2];

            // Already-decided URLs don't refetch
            if ( isset( $session_cache[ $url ] ) ) {
                if ( ! $session_cache[ $url ] ) {
                    $markdown = str_replace( $full_match, $anchor_text, $markdown );
                }
                continue;
            }

            // Persistent cache across article saves (24 hours)
            $cache_key = 'sb_cite_' . md5( $url . '|' . $anchor_text );
            $cached = get_transient( $cache_key );
            if ( $cached !== false ) {
                $verified = ( $cached === 'ok' );
                $session_cache[ $url ] = $verified;
                if ( ! $verified ) {
                    $markdown = str_replace( $full_match, $anchor_text, $markdown );
                }
                continue;
            }

            // Extract key terms from anchor text
            $raw_terms = preg_split( '/\s+/', strtolower( wp_strip_all_tags( $anchor_text ) ) );
            $key_terms = [];
            foreach ( $raw_terms as $t ) {
                $t = preg_replace( '/[^\w]/', '', $t );
                if ( strlen( $t ) >= 4 && ! in_array( $t, $stopwords, true ) ) {
                    $key_terms[] = $t;
                }
            }

            // Anchor text with no verifiable content words (e.g. "here", "this article")
            // gets stripped entirely — we can't verify it and it's low-quality anyway
            if ( empty( $key_terms ) ) {
                $session_cache[ $url ] = false;
                set_transient( $cache_key, 'fail', DAY_IN_SECONDS );
                $markdown = str_replace( $full_match, $anchor_text, $markdown );
                continue;
            }

            // Fetch the destination page
            $response = wp_remote_get( $url, [
                'timeout'     => 5,
                'redirection' => 3,
                'sslverify'   => false,
                'user-agent'  => 'Mozilla/5.0 (compatible; SEOBetter/1.0; +https://seobetter.com)',
            ] );

            if ( is_wp_error( $response ) ) {
                $session_cache[ $url ] = false;
                set_transient( $cache_key, 'fail', HOUR_IN_SECONDS );
                $markdown = str_replace( $full_match, $anchor_text, $markdown );
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code < 200 || $code >= 400 ) {
                $session_cache[ $url ] = false;
                set_transient( $cache_key, 'fail', DAY_IN_SECONDS );
                $markdown = str_replace( $full_match, $anchor_text, $markdown );
                continue;
            }

            // Extract destination content — title + first 3000 chars of body
            $body = wp_remote_retrieve_body( $response );
            $title = '';
            if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $body, $tm ) ) {
                $title = wp_strip_all_tags( $tm[1] );
            }
            $text = wp_strip_all_tags( $body );
            // Collapse whitespace so "dog\n\nbed" doesn't hide "dog bed" matches
            $text = preg_replace( '/\s+/', ' ', $text );
            $haystack = strtolower( $title . ' ' . substr( $text, 0, 3000 ) );

            // Count how many anchor key terms appear in the haystack
            $found = 0;
            foreach ( $key_terms as $t ) {
                if ( strpos( $haystack, $t ) !== false ) {
                    $found++;
                }
            }
            $ratio = $found / count( $key_terms );

            // Require at least 50% of key terms to appear in the destination.
            // This is the "fine-grained verification" step — link is only kept
            // if the destination content actually relates to what we cited it for.
            $verified = $ratio >= 0.5;

            $session_cache[ $url ] = $verified;
            set_transient( $cache_key, $verified ? 'ok' : 'fail', DAY_IN_SECONDS );

            if ( ! $verified ) {
                $markdown = str_replace( $full_match, $anchor_text, $markdown );
            }
        }

        // After stripping verified-fail links, the References section may have
        // become empty — re-run the section sanitizer to remove a heading with
        // no surviving entries
        $markdown = $this->sanitize_references_section( $markdown );

        return $markdown;
    }

    /**
     * Remove hallucinated entries from any References / Sources section.
     *
     * For each line inside a References/Sources section, we check whether it
     * contains a markdown link whose URL passes the same whitelist rules used
     * for inline links (direct article, deep path, trusted domain, not an API,
     * anchor text not an API name). Lines that fail are dropped. Plain-text
     * citation lines (no markdown link at all) are also dropped — a References
     * section with no verifiable URLs has no value.
     *
     * If the section ends up empty, the heading itself is removed.
     */
    private function sanitize_references_section( string $markdown ): string {
        // Find the References / Sources / Further Reading / Bibliography heading
        if ( ! preg_match( '/\n(##+)\s*(references|sources|further reading|bibliography|citations)\b[^\n]*\n/i', $markdown, $heading_match, PREG_OFFSET_CAPTURE ) ) {
            return $markdown;
        }

        $heading_start = $heading_match[0][1];
        $heading_end   = $heading_start + strlen( $heading_match[0][0] );
        $heading_level = strlen( $heading_match[1][0] );

        // Find where the section ends: either next same-or-higher heading, or end of document
        $after_heading = substr( $markdown, $heading_end );
        $next_heading_offset = null;
        if ( preg_match( '/\n(#{1,' . $heading_level . '})\s/', "\n" . $after_heading, $next_m, PREG_OFFSET_CAPTURE ) ) {
            // Offset is relative to "\n" + $after_heading, so subtract 1
            $next_heading_offset = $next_m[0][1] - 1;
        }

        $section_body = $next_heading_offset !== null
            ? substr( $after_heading, 0, $next_heading_offset )
            : $after_heading;

        $rest = $next_heading_offset !== null
            ? substr( $after_heading, $next_heading_offset )
            : '';

        // Process each line of the section body
        $whitelist = $this->get_trusted_domain_whitelist();
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );

        $lines = explode( "\n", $section_body );
        $kept_lines = [];
        $kept_any_reference = false;

        foreach ( $lines as $line ) {
            $trimmed = trim( $line );

            // Blank lines are passthrough
            if ( $trimmed === '' ) {
                $kept_lines[] = $line;
                continue;
            }

            // Is this a reference list entry? (numbered, bulleted, or starts with link)
            $is_list_item = preg_match( '/^(\d+\.|\-|\*)\s+/', $trimmed );

            // Does the line contain a markdown link? (images excluded via lookbehind)
            if ( preg_match( '/(?<!!)\[([^\]]+)\]\((https?:\/\/[^)]+)\)/', $trimmed, $link_match ) ) {
                $text = $link_match[1];
                $url  = $link_match[2];
                $host = wp_parse_url( $url, PHP_URL_HOST );
                $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );

                $fail = false;

                // Anchor text must not look like an API / dataset / tool name
                if ( preg_match( '/\b(api|endpoint|dataset|sdk|webhook)\b/i', $text ) ) {
                    $fail = true;
                }
                // URL must not be an API endpoint
                if ( ! $fail && preg_match( '#/api/|/v[1-9]/|/graphql|/rest/|\.herokuapp\.com|(^|\.)api\.|-api\.#i', $url ) ) {
                    $fail = true;
                }
                // URL must be on trusted whitelist (or internal)
                if ( ! $fail && $host && $host !== $site_host && ! $this->is_host_trusted( $host, $whitelist ) ) {
                    $fail = true;
                }
                // URL must be a deep link (not homepage)
                if ( ! $fail && ( $path === '' || $path === 'index.html' || $path === 'index.php' ) ) {
                    $fail = true;
                }

                if ( $fail ) {
                    // Drop the whole reference line
                    continue;
                }

                // Link passes all checks — keep it
                $kept_lines[] = $line;
                $kept_any_reference = true;
                continue;
            }

            // No markdown link. If this looks like a list item, drop it —
            // a References section entry with no link has no citation value.
            if ( $is_list_item ) {
                continue;
            }

            // Non-list lines (intro paragraph etc.) — keep as-is
            $kept_lines[] = $line;
        }

        $cleaned_section_body = implode( "\n", $kept_lines );

        // If we kept NO actual references, remove the heading entirely
        if ( ! $kept_any_reference ) {
            return substr( $markdown, 0, $heading_start ) . "\n" . ltrim( $rest );
        }

        return substr( $markdown, 0, $heading_start )
            . $heading_match[0][0]
            . $cleaned_section_body
            . $rest;
    }

    /**
     * Build and append a programmatic References section from the citation pool.
     *
     * Runs AFTER validate_outbound_links() has cleaned the body. Walks every
     * surviving markdown link in the cleaned body, checks if it's in the pool,
     * and appends a numbered References section listing each pool entry that
     * the article body actually cited. Titles come from the pool metadata
     * (scraped at pool-build time), NOT from the AI.
     *
     * If the body contains zero pool-matching citations, no References section
     * is appended. This guarantees the References section can never contain
     * hallucinations — every entry is a pool URL the article body referenced.
     */
    private function append_references_section( string $markdown, array $citation_pool ): string {
        if ( empty( $citation_pool ) ) {
            return $markdown;
        }

        // Remove any existing References heading the sanitizer may have left
        // (e.g. if the AI ignored the prompt and wrote one anyway and it was
        // all empty after stripping). We always build our own.
        $markdown = preg_replace(
            '/\n(##+)\s*(references|sources|further reading|bibliography|citations)\b[^\n]*(\n[\s\S]*?)?(?=\n#{1,6}\s|\z)/i',
            "\n",
            $markdown
        );

        // Find every markdown link that survived the validator (images excluded)
        if ( ! preg_match_all( '/(?<!!)\[([^\]]+)\]\((https?:\/\/[^)]+)\)/', $markdown, $matches, PREG_SET_ORDER ) ) {
            return $markdown;
        }

        // Keep only the pool entries the body actually cited (in order of first mention)
        $cited_entries = [];
        $cited_urls = [];
        foreach ( $matches as $m ) {
            $url = $m[2];
            $entry = \SEOBetter\Citation_Pool::get_entry( $citation_pool, $url );
            if ( $entry && ! in_array( $url, $cited_urls, true ) ) {
                $cited_urls[] = $url;
                $cited_entries[] = $entry;
            }
        }

        if ( empty( $cited_entries ) ) {
            return $markdown;
        }

        $lines = [ '', '## References', '' ];
        $i = 1;
        foreach ( $cited_entries as $entry ) {
            $title = trim( (string) ( $entry['title'] ?? '' ) );
            $url   = $entry['url'];
            $src   = trim( (string) ( $entry['source_name'] ?? wp_parse_url( $url, PHP_URL_HOST ) ) );
            if ( $title === '' ) {
                $title = $src ?: 'Source';
            }
            $lines[] = "{$i}. [{$title}]({$url}) — {$src}";
            $i++;
        }

        return rtrim( $markdown ) . "\n" . implode( "\n", $lines ) . "\n";
    }

    /**
     * Check if a host matches any pattern in the whitelist.
     * Supports wildcards (*.gov, *.edu, *.rspca.org.au).
     */
    private function is_host_trusted( string $host, array $whitelist ): bool {
        $host = strtolower( $host );
        foreach ( $whitelist as $pattern ) {
            $pattern = strtolower( $pattern );
            if ( strpos( $pattern, '*.' ) === 0 ) {
                $suffix = substr( $pattern, 2 );
                if ( $host === $suffix || substr( $host, -( strlen( $suffix ) + 1 ) ) === '.' . $suffix ) {
                    return true;
                }
            } elseif ( $host === $pattern || substr( $host, -( strlen( $pattern ) + 1 ) ) === '.' . $pattern ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whitelist of trusted domains AI models are allowed to link to.
     * Anything not on this list gets stripped (link removed, text kept).
     *
     * Filter 'seobetter_trusted_domains' lets site owners extend this.
     */
    private function get_trusted_domain_whitelist(): array {
        $default = [
            // TLD wildcards — only genuinely authoritative TLDs
            '*.gov', '*.edu', '*.mil', '*.gov.au', '*.gov.uk', '*.gc.ca', '*.gov.nz',
            '*.edu.au', '*.ac.uk', '*.ac.nz',

            // Major news & reference
            'wikipedia.org', 'reuters.com', 'apnews.com', 'bbc.com', 'bbc.co.uk',
            'theguardian.com', 'nytimes.com', 'washingtonpost.com', 'ft.com',
            'bloomberg.com', 'cnbc.com', 'wsj.com', 'economist.com',

            // Health & science
            'who.int', 'cdc.gov', 'nih.gov', 'nature.com', 'sciencedirect.com',
            'pubmed.ncbi.nlm.nih.gov', 'mayoclinic.org', 'clevelandclinic.org',
            'webmd.com', 'healthline.com', 'harvard.edu', 'ox.ac.uk',

            // Pet/animal authority (relevant for the current test site)
            'rspca.org.au', 'rspca.org.uk', 'aspca.org', 'akc.org', 'ukcdogs.com',
            'avma.org', 'ava.com.au', 'pedigree.com', 'royalcanin.com',
            'petmd.com', 'vcahospitals.com', 'bluecross.org.uk', 'dogstrust.org.uk',

            // Tech authority
            'developer.mozilla.org', 'w3.org', 'schema.org', 'google.com',
            'support.google.com', 'developers.google.com', 'search.google.com',
            'github.com', 'stackoverflow.com', 'microsoft.com', 'apple.com',

            // Research & data
            'statista.com', 'pewresearch.org', 'ourworldindata.org',
            'researchgate.net', 'arxiv.org', 'ssrn.com',

            // v1.5.15 — Academic citation APIs (Crossref, EuropePMC, OpenAlex)
            // These power the Veterinary domain category and the science/books/tech
            // Crossref fetchers. DOI domain accepts any redirected DOI URL.
            'crossref.org', 'api.crossref.org', 'doi.org',
            'europepmc.org', 'ebi.ac.uk', 'www.ebi.ac.uk',
            'openalex.org', 'api.openalex.org',

            // v1.5.16 — Social discussion sources (Bluesky, Mastodon, DEV.to, Lemmy)
            // Always-on free fetchers that contribute trending discussions and
            // citable posts to every article's research pool.
            'bsky.app', 'bsky.social',
            'mastodon.social',
            'dev.to',
            'lemmy.world',
        ];

        $custom = apply_filters( 'seobetter_trusted_domains', $default );
        return is_array( $custom ) ? $custom : $default;
    }

    private function set_featured_image( int $post_id, string $keyword ): void {
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        if ( has_post_thumbnail( $post_id ) ) {
            return;
        }

        // Try Pexels API for topic-relevant image
        $image_url = $this->search_pexels_image( $keyword );

        // Fallback: use a direct Picsum URL with .jpg extension
        if ( ! $image_url ) {
            $seed = abs( crc32( $keyword . 'featured' ) ) % 1000;
            $image_url = "https://picsum.photos/seed/{$seed}/1200/630.jpg";
        }

        $alt_text = ucwords( $keyword ) . ' — featured guide image';

        // Download to media library
        $image_id = media_sideload_image( $image_url, $post_id, $alt_text, 'id' );

        if ( is_wp_error( $image_id ) ) {
            return;
        }

        set_post_thumbnail( $post_id, $image_id );
        update_post_meta( $image_id, '_wp_attachment_image_alt', $alt_text );
        wp_update_post( [
            'ID'         => $image_id,
            'post_title' => ucwords( $keyword ) . ' Guide',
        ] );
    }

    /**
     * Search Pexels for a topic-relevant image.
     * Free API: 15,000 requests/month, no attribution required for API usage.
     */
    private function search_pexels_image( string $keyword ): string {
        $settings = get_option( 'seobetter_settings', [] );
        $pexels_key = $settings['pexels_api_key'] ?? '';

        // Use hardcoded free key for basic usage (rate limited)
        if ( empty( $pexels_key ) ) {
            $pexels_key = ''; // No default key — users must add their own
        }

        if ( empty( $pexels_key ) ) {
            return ''; // No Pexels key, fall back to Picsum
        }

        $response = wp_remote_get(
            'https://api.pexels.com/v1/search?' . http_build_query( [
                'query'    => $keyword,
                'per_page' => 5,
                'orientation' => 'landscape',
                'size'     => 'large',
            ] ),
            [
                'timeout' => 8,
                'headers' => [ 'Authorization' => $pexels_key ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $photos = $body['photos'] ?? [];

        if ( empty( $photos ) ) {
            return '';
        }

        // Pick a random photo from results for variety
        $photo = $photos[ array_rand( $photos ) ];

        // Use the landscape-cropped version at 1200px wide
        return $photo['src']['landscape'] ?? $photo['src']['large'] ?? '';
    }

    /**
     * Add SEOBetter column to posts/pages list.
     */
    public function add_posts_columns( array $columns ): array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            // Insert after title column
            if ( $key === 'title' ) {
                $new['seobetter_score'] = '<span style="color:#764ba2">SEOBetter</span>';
            }
        }
        return $new;
    }

    /**
     * Render the SEOBetter column content.
     */
    public function render_posts_column( string $column, int $post_id ): void {
        if ( $column !== 'seobetter_score' ) {
            return;
        }

        $score_data = get_post_meta( $post_id, '_seobetter_geo_score', true );
        $keyword = get_post_meta( $post_id, '_seobetter_focus_keyword', true );

        if ( ! is_array( $score_data ) || ! isset( $score_data['geo_score'] ) ) {
            echo '<span style="color:#94a3b8;font-size:12px">—</span>';
            return;
        }

        $score = $score_data['geo_score'];
        $grade = $score_data['grade'] ?? '';
        $word_count = $score_data['word_count'] ?? 0;

        // Color based on score
        if ( $score >= 80 ) {
            $color = '#059669'; $bg = '#ecfdf5';
        } elseif ( $score >= 60 ) {
            $color = '#d97706'; $bg = '#fffbeb';
        } else {
            $color = '#dc2626'; $bg = '#fef2f2';
        }

        // Score badge
        echo '<div style="display:flex;flex-direction:column;gap:3px;font-size:12px;line-height:1.4">';
        echo '<span style="display:inline-block;padding:2px 8px;background:' . $bg . ';color:' . $color . ';border-radius:4px;font-weight:700;font-size:13px;text-align:center;width:fit-content">';
        echo esc_html( $score ) . ' <span style="font-weight:400;font-size:11px">' . esc_html( $grade ) . '</span>';
        echo '</span>';

        // Details row
        $details = [];
        if ( $word_count ) {
            $details[] = number_format( $word_count ) . 'w';
        }
        if ( $keyword ) {
            $details[] = '<span title="Focus keyword: ' . esc_attr( $keyword ) . '" style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;vertical-align:bottom">' . esc_html( $keyword ) . '</span>';
        }

        // Check counts from score data
        $checks = $score_data['checks'] ?? [];
        $citations = $checks['citations']['count'] ?? null;
        $quotes = $checks['expert_quotes']['count'] ?? null;
        $tables = $checks['tables']['count'] ?? null;

        if ( $citations !== null ) $details[] = $citations . ' cites';
        if ( $quotes !== null ) $details[] = $quotes . ' quotes';

        if ( ! empty( $details ) ) {
            echo '<span style="color:#64748b;font-size:11px">' . implode( ' · ', $details ) . '</span>';
        }

        echo '</div>';
    }

    /**
     * Make the SEOBetter column sortable.
     */
    public function sortable_columns( array $columns ): array {
        $columns['seobetter_score'] = 'seobetter_score';
        return $columns;
    }

    /**
     * Handle sorting by GEO score.
     */
    public function sort_by_geo_score( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }
        if ( $query->get( 'orderby' ) === 'seobetter_score' ) {
            $query->set( 'meta_key', '_seobetter_geo_score' );
            $query->set( 'orderby', 'meta_value_num' );
        }
    }

    /**
     * Save metabox data (focus keyword) when post is saved.
     */
    public function save_metabox( int $post_id, \WP_Post $post ): void {
        if ( ! isset( $_POST['seobetter_metabox_nonce_field'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['seobetter_metabox_nonce_field'], 'seobetter_metabox_nonce' ) ) return;
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        if ( isset( $_POST['seobetter_focus_keyword'] ) ) {
            $keyword = sanitize_text_field( wp_unslash( $_POST['seobetter_focus_keyword'] ) );
            update_post_meta( $post_id, '_seobetter_focus_keyword', $keyword );
        }
    }

    /**
     * Register the SEOBetter metabox below the post editor.
     */
    public function register_metabox(): void {
        $screens = [ 'post', 'page' ];
        foreach ( $screens as $screen ) {
            add_meta_box(
                'seobetter-settings',
                'SEOBetter Settings',
                [ $this, 'render_metabox' ],
                $screen,
                'normal',
                'low'
            );
        }
    }

    /**
     * Render the SEOBetter metabox with SERP preview + page analysis.
     */
    public function render_metabox( \WP_Post $post ): void {
        $keyword = get_post_meta( $post->ID, '_seobetter_focus_keyword', true )
                ?: get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true )
                ?: get_post_meta( $post->ID, 'rank_math_focus_keyword', true )
                ?: '';

        $meta_title = get_post_meta( $post->ID, '_seobetter_meta_title', true ) ?: $post->post_title;
        $meta_desc = get_post_meta( $post->ID, '_seobetter_meta_description', true ) ?: wp_trim_words( wp_strip_all_tags( $post->post_content ), 25 );
        $url = get_permalink( $post->ID );
        $site_name = get_bloginfo( 'name' );

        // Run GEO analysis
        $score_data = get_post_meta( $post->ID, '_seobetter_geo_score', true );
        $score = is_array( $score_data ) ? ( $score_data['geo_score'] ?? 0 ) : 0;
        $grade = is_array( $score_data ) ? ( $score_data['grade'] ?? '?' ) : '?';
        $checks = is_array( $score_data ) ? ( $score_data['checks'] ?? [] ) : [];
        $suggestions = is_array( $score_data ) ? ( $score_data['suggestions'] ?? [] ) : [];
        $word_count = is_array( $score_data ) ? ( $score_data['word_count'] ?? 0 ) : 0;

        $score_color = $score >= 80 ? '#22c55e' : ( $score >= 60 ? '#f59e0b' : '#ef4444' );

        // Check keyword placement
        $content_text = wp_strip_all_tags( $post->post_content );
        $first_para = implode( ' ', array_slice( explode( ' ', $content_text ), 0, 100 ) );
        $kw_in_intro = $keyword && stripos( $first_para, $keyword ) !== false;
        $kw_in_content = $keyword && stripos( $content_text, $keyword ) !== false;
        $kw_in_meta = $keyword && stripos( $meta_desc, $keyword ) !== false;
        $kw_in_url = $keyword && stripos( $url, sanitize_title( $keyword ) ) !== false;
        $kw_len_ok = mb_strlen( $keyword ) >= 3 && mb_strlen( $keyword ) <= 50;
        $meta_len_ok = mb_strlen( $meta_desc ) >= 120 && mb_strlen( $meta_desc ) <= 160;
        $content_len_ok = $word_count >= 300;

        // Keyword density
        $kw_count = $keyword ? substr_count( strtolower( $content_text ), strtolower( $keyword ) ) : 0;
        $kw_density = $word_count > 0 ? round( ( $kw_count / $word_count ) * 100, 2 ) : 0;
        $density_ok = $kw_density >= 0.5;

        // Headings with keyword
        preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>/is', $post->post_content, $heading_matches );
        $total_headings = count( $heading_matches[1] );
        $kw_headings = 0;
        foreach ( $heading_matches[1] as $h ) {
            if ( $keyword && stripos( wp_strip_all_tags( $h ), $keyword ) !== false ) {
                $kw_headings++;
            }
        }
        $headings_ok = $total_headings > 0 && ( $kw_headings / $total_headings ) >= 0.3;

        // Internal links
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\']/i', $post->post_content, $link_matches );
        $internal_count = 0;
        foreach ( $link_matches[1] as $href ) {
            $host = wp_parse_url( $href, PHP_URL_HOST );
            if ( ! $host || $host === $site_host ) {
                if ( strpos( $href, '#' ) !== 0 && strpos( $href, 'mailto:' ) !== 0 ) {
                    $internal_count++;
                }
            }
        }
        $internal_ok = $internal_count >= 1;

        // External links
        $external_count = 0;
        foreach ( $link_matches[1] as $href ) {
            $host = wp_parse_url( $href, PHP_URL_HOST );
            if ( $host && $host !== $site_host ) {
                $external_count++;
            }
        }
        $external_ok = $external_count >= 1;

        // Image alt with keyword
        preg_match_all( '/<img[^>]+alt=["\']([^"\']*)["\']/', $post->post_content, $alt_matches );
        $alt_with_kw = 0;
        foreach ( $alt_matches[1] as $alt ) {
            if ( $keyword && stripos( $alt, $keyword ) !== false ) $alt_with_kw++;
        }
        $alt_ok = $alt_with_kw >= 1;

        // Readability
        $read_score = $checks['readability']['score'] ?? 0;
        $read_grade = $checks['readability']['flesch_grade'] ?? 0;
        ?>
        <div id="seobetter-metabox" style="margin:-6px -12px -12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif">
            <!-- Tabs -->
            <div style="display:flex;border-bottom:2px solid #e5e7eb;background:#f9fafb">
                <button type="button" class="sb-meta-tab sb-meta-tab-active" data-tab="general" style="padding:12px 20px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:2px solid #764ba2;margin-bottom:-2px;color:#764ba2">General</button>
                <button type="button" class="sb-meta-tab" data-tab="analysis" style="padding:12px 20px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;color:#6b7280">Page Analysis</button>
                <button type="button" class="sb-meta-tab" data-tab="readability" style="padding:12px 20px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;color:#6b7280">Readability</button>
                <div style="margin-left:auto;display:flex;align-items:center;padding-right:16px;gap:8px">
                    <span style="font-size:13px;font-weight:700;color:<?php echo esc_attr( $score_color ); ?>"><?php echo esc_html( $score ); ?>/100</span>
                    <span style="font-size:11px;padding:2px 8px;background:<?php echo esc_attr( $score_color ); ?>20;color:<?php echo esc_attr( $score_color ); ?>;border-radius:4px;font-weight:600"><?php echo esc_html( $grade ); ?></span>
                </div>
            </div>

            <!-- General Tab -->
            <div class="sb-meta-panel" data-panel="general" style="padding:20px">
                <!-- SERP Preview -->
                <div style="margin-bottom:20px">
                    <div style="font-size:13px;font-weight:600;margin-bottom:8px">SERP Preview</div>
                    <div style="padding:16px;border:1px solid #e5e7eb;border-radius:8px;background:#fff">
                        <div style="font-size:12px;color:#202124;margin-bottom:2px"><?php echo esc_html( $site_name ); ?> | <?php echo esc_url( $url ); ?></div>
                        <div style="font-size:18px;color:#1a0dab;margin-bottom:4px;cursor:pointer"><?php echo esc_html( $meta_title ); ?></div>
                        <div style="font-size:13px;color:#4d5156;line-height:1.5"><?php echo esc_html( $meta_desc ); ?></div>
                    </div>
                </div>

                <!-- Focus Keyword -->
                <div style="margin-bottom:16px">
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:4px">Focus Keyword</label>
                    <div style="display:flex;gap:8px;align-items:center">
                        <input type="text" name="seobetter_focus_keyword" value="<?php echo esc_attr( $keyword ); ?>" placeholder="Enter focus keyword..." style="flex:1;height:38px;padding:0 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px" />
                        <?php if ( $score > 0 ) : ?>
                            <span style="font-size:12px;font-weight:600;color:<?php echo esc_attr( $score_color ); ?>;white-space:nowrap"><?php echo esc_html( $score ); ?>/100</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- GEO Score Summary -->
                <?php if ( $score > 0 ) : ?>
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:16px">
                    <div style="text-align:center;padding:8px;background:#f9fafb;border-radius:6px">
                        <div style="font-size:18px;font-weight:700;color:<?php echo esc_attr( $score_color ); ?>"><?php echo esc_html( $score ); ?></div>
                        <div style="font-size:11px;color:#6b7280">GEO Score</div>
                    </div>
                    <div style="text-align:center;padding:8px;background:#f9fafb;border-radius:6px">
                        <div style="font-size:18px;font-weight:700;color:#1f2937"><?php echo esc_html( number_format( $word_count ) ); ?></div>
                        <div style="font-size:11px;color:#6b7280">Words</div>
                    </div>
                    <div style="text-align:center;padding:8px;background:#f9fafb;border-radius:6px">
                        <div style="font-size:18px;font-weight:700;color:<?php echo ( $checks['citations']['count'] ?? 0 ) >= 5 ? '#22c55e' : '#ef4444'; ?>"><?php echo esc_html( $checks['citations']['count'] ?? 0 ); ?></div>
                        <div style="font-size:11px;color:#6b7280">Citations</div>
                    </div>
                    <div style="text-align:center;padding:8px;background:#f9fafb;border-radius:6px">
                        <div style="font-size:18px;font-weight:700;color:<?php echo ( $checks['expert_quotes']['count'] ?? 0 ) >= 2 ? '#22c55e' : '#ef4444'; ?>"><?php echo esc_html( $checks['expert_quotes']['count'] ?? 0 ); ?></div>
                        <div style="font-size:11px;color:#6b7280">Quotes</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Page Analysis Tab -->
            <div class="sb-meta-panel" data-panel="analysis" style="padding:20px;display:none">
                <div style="font-size:14px;font-weight:600;margin-bottom:12px">Basic SEO
                    <?php
                    $seo_errors = 0;
                    if ( ! $kw_in_intro ) $seo_errors++;
                    if ( ! $internal_ok ) $seo_errors++;
                    if ( ! $headings_ok ) $seo_errors++;
                    if ( ! $density_ok ) $seo_errors++;
                    ?>
                    <?php if ( $seo_errors > 0 ) : ?>
                        <span style="font-size:12px;color:#f59e0b;margin-left:8px">• <?php echo $seo_errors; ?> Errors</span>
                    <?php else : ?>
                        <span style="font-size:12px;color:#22c55e;margin-left:8px">✓ All Good!</span>
                    <?php endif; ?>
                </div>

                <?php
                $seo_checks = [
                    [ 'label' => 'Focus Keyword in content', 'ok' => $kw_in_content, 'detail' => '' ],
                    [ 'label' => 'Focus keyword in introduction', 'ok' => $kw_in_intro, 'detail' => $kw_in_intro ? '' : 'Your Focus keyword does not appear in the first paragraph. Make sure the topic is clear immediately.' ],
                    [ 'label' => 'Focus keyword in meta description', 'ok' => $kw_in_meta, 'detail' => '' ],
                    [ 'label' => 'Focus Keyword in URL', 'ok' => $kw_in_url, 'detail' => '' ],
                    [ 'label' => 'Focus keyword length', 'ok' => $kw_len_ok, 'detail' => '' ],
                    [ 'label' => 'Meta description length', 'ok' => $meta_len_ok, 'detail' => $meta_len_ok ? '' : 'Meta description is ' . mb_strlen( $meta_desc ) . ' chars. Aim for 120-160.' ],
                    [ 'label' => 'Content length', 'ok' => $content_len_ok, 'detail' => '' ],
                    [ 'label' => 'Focus Keyword in Subheadings', 'ok' => $headings_ok, 'detail' => $headings_ok ? '' : 'Less than 30% of your H2 and H3 subheadings reflect the topic. Add the keyword to more headings.' ],
                    [ 'label' => 'Focus keyword density', 'ok' => $density_ok, 'detail' => $density_ok ? '' : 'Keyword density is ' . $kw_density . '%. The keyword appears ' . $kw_count . ' times. Aim for more than 0.5%.' ],
                    [ 'label' => 'Focus keyword in image alt', 'ok' => $alt_ok, 'detail' => '' ],
                    [ 'label' => 'Internal links', 'ok' => $internal_ok, 'detail' => $internal_ok ? '' : 'No internal links found. Add internal links to your content.' ],
                    [ 'label' => 'External links', 'ok' => $external_ok, 'detail' => '' ],
                ];
                foreach ( $seo_checks as $check ) :
                    $icon_color = $check['ok'] ? '#22c55e' : '#ef4444';
                    $icon = $check['ok'] ? '✓' : '✗';
                ?>
                <div style="border-bottom:1px solid #f3f4f6;padding:8px 0">
                    <div style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <span style="color:<?php echo $icon_color; ?>;font-weight:700;font-size:14px"><?php echo $icon; ?></span>
                        <span style="font-size:13px;font-weight:<?php echo $check['ok'] ? '400' : '600'; ?>"><?php echo esc_html( $check['label'] ); ?></span>
                    </div>
                    <?php if ( $check['detail'] ) : ?>
                        <div style="margin-left:22px;font-size:12px;color:#6b7280;margin-top:4px"><?php echo esc_html( $check['detail'] ); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Readability Tab -->
            <div class="sb-meta-panel" data-panel="readability" style="padding:20px;display:none">
                <div style="font-size:14px;font-weight:600;margin-bottom:12px">Readability
                    <?php if ( $read_score < 70 ) : ?>
                        <span style="font-size:12px;color:#ef4444;margin-left:8px">• Needs improvement</span>
                    <?php else : ?>
                        <span style="font-size:12px;color:#22c55e;margin-left:8px">✓ Good</span>
                    <?php endif; ?>
                </div>

                <div style="border-bottom:1px solid #f3f4f6;padding:8px 0">
                    <span style="color:<?php echo $read_grade <= 10 ? '#22c55e' : '#ef4444'; ?>;font-weight:700">•</span>
                    <span style="font-size:13px;font-weight:600">Reading Grade: <?php echo round( $read_grade, 1 ); ?></span>
                    <div style="font-size:12px;color:#6b7280;margin-top:2px;margin-left:16px">Target: Grade 6-8. <?php echo $read_grade > 10 ? 'Simplify your language.' : 'Great readability!'; ?></div>
                </div>

                <div style="border-bottom:1px solid #f3f4f6;padding:8px 0">
                    <span style="color:<?php echo ( $checks['island_test']['score'] ?? 0 ) >= 80 ? '#22c55e' : '#ef4444'; ?>;font-weight:700">•</span>
                    <span style="font-size:13px;font-weight:600">Island Test (no pronoun starts)</span>
                    <div style="font-size:12px;color:#6b7280;margin-top:2px;margin-left:16px"><?php echo esc_html( $checks['island_test']['detail'] ?? 'N/A' ); ?></div>
                </div>

                <div style="border-bottom:1px solid #f3f4f6;padding:8px 0">
                    <span style="color:<?php echo ( $checks['section_openings']['score'] ?? 0 ) >= 70 ? '#22c55e' : '#ef4444'; ?>;font-weight:700">•</span>
                    <span style="font-size:13px;font-weight:600">Section Openings (40-60 words)</span>
                    <div style="font-size:12px;color:#6b7280;margin-top:2px;margin-left:16px"><?php echo esc_html( $checks['section_openings']['detail'] ?? 'N/A' ); ?></div>
                </div>

                <?php if ( ! empty( $suggestions ) ) : ?>
                <div style="margin-top:12px;font-size:13px;font-weight:600;margin-bottom:8px">Suggestions</div>
                <?php foreach ( array_slice( $suggestions, 0, 5 ) as $s ) : ?>
                <div style="padding:8px 12px;margin-bottom:4px;background:<?php echo $s['priority'] === 'high' ? '#fef2f2' : '#fffbeb'; ?>;border-left:3px solid <?php echo $s['priority'] === 'high' ? '#ef4444' : '#f59e0b'; ?>;border-radius:0 4px 4px 0;font-size:12px">
                    <strong>[<?php echo esc_html( ucfirst( $s['type'] ?? 'issue' ) ); ?>]</strong> <?php echo esc_html( $s['message'] ); ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab switching JS -->
        <script>
        (function() {
            var tabs = document.querySelectorAll('.sb-meta-tab');
            var panels = document.querySelectorAll('.sb-meta-panel');
            tabs.forEach(function(tab) {
                tab.addEventListener('click', function() {
                    var target = this.getAttribute('data-tab');
                    tabs.forEach(function(t) {
                        t.style.borderBottom = 'none';
                        t.style.color = '#6b7280';
                        t.classList.remove('sb-meta-tab-active');
                    });
                    this.style.borderBottom = '2px solid #764ba2';
                    this.style.color = '#764ba2';
                    this.classList.add('sb-meta-tab-active');
                    panels.forEach(function(p) {
                        p.style.display = p.getAttribute('data-panel') === target ? 'block' : 'none';
                    });
                });
            });
        })();
        </script>

        <?php
        // Save focus keyword on post save
        wp_nonce_field( 'seobetter_metabox_nonce', 'seobetter_metabox_nonce_field' );
    }

    public function register_llms_txt_rewrite(): void {
        add_rewrite_rule( '^llms\.txt$', 'index.php?seobetter_llms_txt=1', 'top' );
        add_filter( 'query_vars', function ( $vars ) {
            $vars[] = 'seobetter_llms_txt';
            return $vars;
        });
    }

    public function serve_llms_txt(): void {
        if ( ! get_query_var( 'seobetter_llms_txt' ) ) {
            return;
        }
        $settings = get_option( 'seobetter_settings', [] );
        if ( empty( $settings['llms_txt_enabled'] ) ) {
            status_header( 404 );
            exit;
        }
        $generator = new SEOBetter\LLMS_Txt_Generator();
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo $generator->generate();
        exit;
    }
}

SEOBetter::instance();
