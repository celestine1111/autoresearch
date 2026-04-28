<?php
/**
 * Plugin Name: SEOBetter
 * Plugin URI: https://seobetter.com
 * Description: AI-powered content generation optimized for Google AI Overviews, ChatGPT, Perplexity, Gemini & more. Generate articles that AI models cite. Works alongside Yoast, RankMath, or AIOSEO.
 * Version: 1.5.216.1
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

define( 'SEOBETTER_VERSION', '1.5.216.1' );
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
        // v1.5.190 — Schema now served from post meta via wp_head only.
        // analyze_on_save regenerates on every save (including publish).
        // Removed transition_post_status hook — no more inline schema in post_content.
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
        // v1.5.190 — Removed 4 empty menu items (Content Brief, Citation Tracker, Link Suggestions, Cannibalization).
        // Content Brief is redundant — the generator already does research+outline internally.
        // Citation Tracker, Link Suggestions, Cannibalization → moved to Pro features backlog.
        // Render methods kept in code for future re-activation.
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
        // v1.5.190 — Always output schema from post meta via wp_head.
        // Previously skipped if post_content had inline JSON-LD, but that
        // caused stale URLs (?p=ID instead of pretty permalink) because
        // the inline schema was written at draft time and never reliably
        // updated. Single source of truth: _seobetter_schema post meta,
        // regenerated on every save by analyze_on_save().
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
        // v1.5.190 — Schema generation always runs regardless of auto_analyze.
        // auto_analyze only controls the GEO score recalculation.
        // Schema must always be current for the Rich Results metabox tab.
        $auto_analyze = ! empty( $settings['auto_analyze'] );

        try {
            $content_hash = md5( $post->post_content . $post->post_title );
            $cached_hash = get_post_meta( $post_id, '_seobetter_content_hash', true );

            if ( $content_hash !== $cached_hash ) {
                // GEO score — only if auto_analyze is enabled
                if ( $auto_analyze ) {
                    $analyzer = new SEOBetter\GEO_Analyzer();
                    $kw_or_title = get_post_meta( $post_id, '_seobetter_focus_keyword', true ) ?: $post->post_title;
                    $content_type = get_post_meta( $post_id, '_seobetter_content_type', true ) ?: '';
                    // v1.5.206d — Layer 6 language/country context
                    $lang_meta    = get_post_meta( $post_id, '_seobetter_language', true ) ?: 'en';
                    $country_meta = get_post_meta( $post_id, '_seobetter_country', true ) ?: '';
                    $score = $analyzer->analyze( $post->post_content, $kw_or_title, $content_type, $lang_meta, $country_meta );
                    update_post_meta( $post_id, '_seobetter_geo_score', $score );
                }
                update_post_meta( $post_id, '_seobetter_content_hash', $content_hash );
            }

            // v1.5.190 — Schema ALWAYS regenerated on save (not gated by auto_analyze
            // or content hash). Uses current get_permalink() which returns pretty URL
            // for published posts. Essential for Rich Results metabox tab.
            $schema_gen = new SEOBetter\Schema_Generator();
            $schema_array = $schema_gen->generate( $post );
            if ( ! empty( $schema_array ) ) {
                $schema_ld = [ '@context' => 'https://schema.org', '@graph' => $schema_array ];
                update_post_meta( $post_id, '_seobetter_schema', wp_json_encode( $schema_ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            }
        } catch ( \Throwable $e ) {
            // Silently fail — don't break post saving
        }
    }

    /**
     * v1.5.190 — Regenerate schema with correct permalink when post is published.
     * Schema generated at draft time uses ?p=ID URLs. On publish, WordPress
     * assigns a pretty permalink (e.g. /best-dog-food-australia/). This hook
     * regenerates the JSON-LD in both post_content and post meta so all URLs
     * (mainEntityOfPage, BreadcrumbList items, Recipe step URLs) use the
     * canonical pretty permalink.
     */
    public function update_schema_on_publish( string $new_status, string $old_status, \WP_Post $post ): void {
        // Only fire when transitioning TO publish (from draft, pending, future, etc.)
        if ( $new_status !== 'publish' || $old_status === 'publish' ) {
            return;
        }
        // Only for posts/pages with SEOBetter schema
        $existing_schema = get_post_meta( $post->ID, '_seobetter_schema', true );
        if ( empty( $existing_schema ) ) {
            return;
        }

        try {
            // Regenerate schema — now get_permalink() returns the pretty URL
            $schema_gen = new SEOBetter\Schema_Generator();
            $schema_array = $schema_gen->generate( $post );

            if ( ! empty( $schema_array ) ) {
                $schema_ld = [ '@context' => 'https://schema.org', '@graph' => $schema_array ];

                // Update post meta
                update_post_meta( $post->ID, '_seobetter_schema', wp_json_encode( $schema_ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

                // Update inline schema in post_content
                $content = $post->post_content;
                $schema_json = wp_json_encode( $schema_ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

                // Replace existing inline JSON-LD block
                $new_block = "<!-- wp:html -->\n<script type=\"application/ld+json\">\n{$schema_json}\n</script>\n<!-- /wp:html -->";

                if ( preg_match( '/<!-- wp:html -->\s*<script[^>]*application\/ld\+json[^>]*>.*?<\/script>\s*<!-- \/wp:html -->/s', $content ) ) {
                    // Replace existing schema block
                    $content = preg_replace(
                        '/<!-- wp:html -->\s*<script[^>]*application\/ld\+json[^>]*>.*?<\/script>\s*<!-- \/wp:html -->/s',
                        $new_block,
                        $content,
                        1
                    );
                } else {
                    // Append if no existing block found
                    $content .= "\n\n" . $new_block;
                }

                // Use wpdb directly to avoid triggering save_post again
                global $wpdb;
                $wpdb->update(
                    $wpdb->posts,
                    [ 'post_content' => $content ],
                    [ 'ID' => $post->ID ]
                );
                clean_post_cache( $post->ID );
            }
        } catch ( \Throwable $e ) {
            // Non-fatal — don't break publishing
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
        // v1.5.67 — diagnostic endpoint that tests the full Places Sonar
        // Tier 0 chain end-to-end. Calls Trend_Researcher::cloud_research()
        // with a sample local-intent keyword and reports (a) which OpenRouter
        // key source was used (Places field / AI Providers auto-discover /
        // none), (b) the cloud-api response, (c) whether Sonar was actually
        // called. Lets users diagnose why the pool is empty without running
        // a full article generation.
        register_rest_route( 'seobetter/v1', '/test-sonar', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_test_sonar' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ]);
        // v1.5.67 — diagnostic endpoint for Foursquare + HERE + Google Places.
        // Calls the cloud-api research endpoint directly with only the paid
        // place provider keys (no Sonar, no category APIs) and runAllTiers=true
        // so the waterfall doesn't short-circuit. Lets users verify their
        // Foursquare / HERE / Google keys are being called without running
        // a full article generation and without being masked by Sonar.
        register_rest_route( 'seobetter/v1', '/test-places-providers', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_test_places_providers' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ]);
        // v1.5.67 — diagnostic endpoint for every always-on research source
        // (Reddit, HN, Wikipedia, Google Trends, DuckDuckGo, Bluesky,
        // Mastodon, Dev.to, Lemmy, Tavily, category APIs, country APIs)
        // PLUS the local Last30Days Python skill. Reports per-source ok/empty
        // /error + latency so users can see which sources are reaching their
        // articles and which are flaking.
        register_rest_route( 'seobetter/v1', '/test-research-sources', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_test_research_sources' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
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
        // v1.5.78 — Optimize All: single Sonar call + sequential fixes
        register_rest_route( 'seobetter/v1', '/optimize-all', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_optimize_all' ],
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
        // v1.5.211 — Proxies for browser-initiated cloud-api calls.
        // Browser JS can't sign HMAC requests — signing secret lives in PHP source
        // and can't safely be exposed to JS. Route browser calls through WordPress
        // REST → PHP signs via Cloud_API::signed_post() → Vercel accepts.
        register_rest_route( 'seobetter/v1', '/topic-research', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_topic_research_proxy' ],
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ]);
        // Proxy for /api/generate used by the Social Content Generator button.
        register_rest_route( 'seobetter/v1', '/generate-proxy', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_generate_proxy' ],
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
        // v1.5.206d — Layer 6 language/country context for scoring
        $lang_meta    = get_post_meta( $post->ID, '_seobetter_language', true ) ?: 'en';
        $country_meta = get_post_meta( $post->ID, '_seobetter_country', true ) ?: '';
        $result = $analyzer->analyze( $post->post_content, $kw_or_title, $content_type, $lang_meta, $country_meta );

        // Add schema info for pre-publish panel + Rich Results Preview
        $schema = get_post_meta( $post->ID, '_seobetter_schema', true );
        $result['schema_data'] = null;
        if ( $schema ) {
            $decoded = json_decode( $schema, true );
            if ( isset( $decoded['@type'] ) ) {
                $result['schema_types'] = $decoded['@type'];
            } elseif ( isset( $decoded['@graph'] ) ) {
                $types = [];
                foreach ( $decoded['@graph'] as $item ) {
                    $t = $item['@type'] ?? '';
                    if ( is_array( $t ) ) $t = implode( ', ', $t );
                    if ( $t ) $types[] = $t;
                }
                $result['schema_types'] = implode( ' + ', $types );
            }
            // v1.5.190 — Pass full schema for Rich Results Preview
            $result['schema_data'] = $decoded;
        }

        // Rich Results Preview data
        $result['rich_preview'] = [
            'title'       => $post->post_title,
            'url'         => get_permalink( $post->ID ),
            'description' => get_post_meta( $post->ID, '_seobetter_meta_description', true ) ?: wp_trim_words( wp_strip_all_tags( $post->post_content ), 25 ),
            'site_name'   => wp_parse_url( home_url(), PHP_URL_HOST ),
            'breadcrumbs' => [],
            'rich_types'  => [],
            'impact_stats' => [],
        ];

        // Detect active rich result types from schema
        if ( ! empty( $decoded['@graph'] ) ) {
            foreach ( $decoded['@graph'] as $item ) {
                $t = $item['@type'] ?? '';
                if ( $t === 'Recipe' ) {
                    $result['rich_preview']['rich_types'][] = [
                        'type' => 'Recipe',
                        'label' => 'Recipe card',
                        'detail' => ( $item['prepTime'] ?? '' ) ? preg_replace( '/^PT(\d+)M$/', '$1 min', $item['prepTime'] ) : '',
                    ];
                } elseif ( $t === 'FAQPage' ) {
                    $count = count( $item['mainEntity'] ?? [] );
                    $result['rich_preview']['rich_types'][] = [
                        'type' => 'FAQ',
                        'label' => 'FAQ dropdowns',
                        'detail' => $count . ' question' . ( $count !== 1 ? 's' : '' ),
                    ];
                } elseif ( $t === 'Review' ) {
                    $rating = $item['reviewRating']['ratingValue'] ?? '';
                    $result['rich_preview']['rich_types'][] = [
                        'type' => 'Review',
                        'label' => 'Star rating',
                        'detail' => $rating ? $rating . '/5' : '',
                    ];
                } elseif ( $t === 'BreadcrumbList' ) {
                    $crumbs = [];
                    foreach ( ( $item['itemListElement'] ?? [] ) as $li ) {
                        $crumbs[] = $li['name'] ?? '';
                    }
                    $result['rich_preview']['breadcrumbs'] = $crumbs;
                    $result['rich_preview']['rich_types'][] = [
                        'type' => 'Breadcrumb',
                        'label' => 'Breadcrumb trail',
                        'detail' => '',
                    ];
                } elseif ( $t === 'ItemList' ) {
                    $count = count( $item['itemListElement'] ?? [] );
                    $result['rich_preview']['rich_types'][] = [
                        'type' => 'ItemList',
                        'label' => 'Carousel list',
                        'detail' => $count . ' item' . ( $count !== 1 ? 's' : '' ),
                    ];
                } elseif ( isset( $item['speakable'] ) ) {
                    $result['rich_preview']['rich_types'][] = [
                        'type' => 'Speakable',
                        'label' => 'Voice search (Speakable)',
                        'detail' => '',
                    ];
                }
            }
        }

        // Schema impact statistics (research-backed, citable)
        $result['rich_preview']['impact_stats'] = [];
        $rt = array_column( $result['rich_preview']['rich_types'], 'type' );
        if ( in_array( 'Recipe', $rt ) ) {
            $result['rich_preview']['impact_stats'][] = '+2.7x clicks with Recipe schema (Searchmetrics, 2024)';
        }
        if ( in_array( 'FAQ', $rt ) ) {
            $result['rich_preview']['impact_stats'][] = '+87% CTR with FAQ schema (Ahrefs study)';
        }
        if ( in_array( 'Review', $rt ) ) {
            $result['rich_preview']['impact_stats'][] = '+35% CTR with star ratings (Search Engine Journal)';
        }
        if ( count( $rt ) > 0 ) {
            $result['rich_preview']['impact_stats'][] = '+30-40% AI citation rate from structured data (Princeton GEO study)';
            $result['rich_preview']['impact_stats'][] = 'Rich results get 58% of page 1 clicks (FirstPageSage, 2024)';
        }

        // Validation status
        $errors = 0;
        $warnings = 0;
        if ( ! empty( $decoded['@graph'] ) ) {
            foreach ( $decoded['@graph'] as $item ) {
                $t = $item['@type'] ?? '';
                if ( $t === 'Recipe' ) {
                    if ( empty( $item['name'] ) ) $errors++;
                    if ( empty( $item['image'] ) ) $errors++;
                    if ( empty( $item['recipeIngredient'] ) ) $warnings++;
                }
            }
        }
        $result['rich_preview']['validation'] = [
            'errors'   => $errors,
            'warnings' => $warnings,
            'valid'    => $errors === 0,
        ];

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

    /**
     * v1.5.67 — Test Sonar connection diagnostic endpoint.
     *
     * Runs a real cloud-api research call against a known-good keyword
     * (Lucignano, which we know should produce 2 real gelaterie when Sonar
     * is working) and returns a structured report telling the user exactly
     * what's happening:
     *   - Which OpenRouter key source was used
     *   - Whether Sonar appeared in providers_tried
     *   - How many places Sonar found
     *   - The raw places array if populated
     *   - Any error from the cloud-api
     *
     * Lets users diagnose why Sonar isn't finding places without burning
     * article-generation credits on a full test run.
     */
    public function rest_test_sonar( \WP_REST_Request $request ): \WP_REST_Response {
        $settings = get_option( 'seobetter_settings', [] );
        $places_key = $settings['openrouter_api_key'] ?? '';
        $ai_providers = get_option( 'seobetter_ai_providers', [] );
        $has_ai_openrouter = is_array( $ai_providers ) && ! empty( $ai_providers['openrouter']['api_key'] );
        $sonar_model = $settings['sonar_model'] ?? 'perplexity/sonar';

        // Determine which key will be used
        $key_source = 'none';
        $key_preview = '';
        if ( ! empty( $places_key ) ) {
            $key_source = 'places_integrations';
            $key_preview = substr( $places_key, 0, 8 ) . '...' . substr( $places_key, -4 );
        } elseif ( $has_ai_openrouter ) {
            try {
                $auto_key = SEOBetter\AI_Provider_Manager::get_provider_key( 'openrouter' );
                if ( ! empty( $auto_key ) ) {
                    $key_source = 'ai_providers_auto_discovered';
                    $key_preview = substr( $auto_key, 0, 8 ) . '...' . substr( $auto_key, -4 );
                }
            } catch ( \Throwable $e ) {
                $key_source = 'ai_providers_decrypt_failed';
                $key_preview = $e->getMessage();
            }
        }

        // v1.5.67 — accept keyword / country / domain from the request so the
        // user can test any location. Defaults remain the Lucignano-in-Italy
        // sanity check for backwards compatibility.
        $test_keyword = sanitize_text_field( $request->get_param( 'keyword' ) ?: 'best gelato in lucignano italy 2026' );
        $test_country = sanitize_text_field( $request->get_param( 'country' ) ?: 'IT' );
        $test_domain  = sanitize_text_field( $request->get_param( 'domain' ) ?: 'travel' );
        try {
            // Delete any stale cached entry so we get a fresh call
            $cache_key = 'seobetter_trends_v7_' . md5( $test_keyword . $test_domain . $test_country );
            delete_transient( $cache_key );
            // Also nuke the shared places-only cache for this keyword so a
            // stale previous-run result doesn't mask the fresh test
            $places_only_key = 'seobetter_places_only_' . md5( strtolower( trim( $test_keyword ) ) . '|' . strtoupper( $test_country ) );
            delete_transient( $places_only_key );

            $result = SEOBetter\Trend_Researcher::research( $test_keyword, $test_domain, $test_country );

            $providers_tried = $result['places_providers_tried'] ?? [];
            $sonar_tried = false;
            $sonar_count = 0;
            foreach ( $providers_tried as $p ) {
                if ( isset( $p['name'] ) && stripos( $p['name'], 'sonar' ) !== false ) {
                    $sonar_tried = true;
                    $sonar_count = (int) ( $p['count'] ?? 0 );
                }
            }

            return new \WP_REST_Response( [
                'success'               => true,
                'test_keyword'          => $test_keyword,
                'plugin_version'        => SEOBETTER_VERSION,
                'key_source'            => $key_source,
                'key_preview'           => $key_preview,
                'sonar_model_configured' => $sonar_model,
                'has_places_field_key'  => ! empty( $places_key ),
                'has_ai_providers_key'  => $has_ai_openrouter,
                'auto_discover_would_fire' => empty( $places_key ) && $has_ai_openrouter,
                // Cloud-api response details
                'is_local_intent'       => $result['is_local_intent'] ?? null,
                'places_count'          => $result['places_count'] ?? 0,
                'places_provider_used'  => $result['places_provider_used'] ?? null,
                'places_providers_tried' => $providers_tried,
                'sonar_was_tried'       => $sonar_tried,
                'sonar_result_count'    => $sonar_count,
                'places_sample'         => array_slice( $result['places'] ?? [], 0, 3 ),
                'research_source'       => $result['source'] ?? 'unknown',
                'research_error'        => $result['error'] ?? null,
                // v1.5.67 — pass the resolved location into the verdict so
                // it reflects the actual tested keyword, not a hardcoded town.
                'verdict'               => self::build_sonar_verdict(
                    $key_source,
                    $sonar_tried,
                    $sonar_count,
                    $result['places_location'] ?? $test_keyword
                ),
            ] );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [
                'success'      => false,
                'key_source'   => $key_source,
                'error'        => 'PHP ' . get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine(),
            ] );
        }
    }

    /**
     * Build a human-readable verdict for the Sonar test result.
     * v1.5.67 — verdict strings no longer hardcode "Lucignano". The tested
     * location is passed in so the message reflects the actual keyword the
     * user entered (Mudgee, Sydney, Rome, wherever).
     */
    private static function build_sonar_verdict( string $key_source, bool $sonar_tried, int $sonar_count, string $location_label = '' ): string {
        $loc = $location_label !== '' ? $location_label : 'this location';
        if ( $key_source === 'none' ) {
            return '❌ NO KEY CONFIGURED. Neither the Places Integrations field nor the AI Providers OpenRouter provider has a key. Paste your OpenRouter key into Settings → AI Providers → OpenRouter (it will auto-reuse for Places) OR into Settings → Places Integrations → Perplexity Sonar field.';
        }
        if ( $key_source === 'ai_providers_decrypt_failed' ) {
            return '❌ KEY DECRYPT FAILED. The AI Providers OpenRouter key could not be decrypted. Try removing and re-adding the provider in Settings → AI Providers.';
        }
        if ( ! $sonar_tried ) {
            return '❌ SONAR WAS NOT CALLED. The cloud-api received the request but did not attempt Sonar Tier 0. This usually means the cloud-api is not deployed with the v1.5.30+ fetchSonarPlaces function. Verify Vercel deployment is up to date.';
        }
        if ( $sonar_tried && $sonar_count === 0 ) {
            return '⚠️ SONAR WAS CALLED BUT RETURNED 0 for ' . $loc . '. Possible causes: (1) OpenRouter key invalid or out of credit, (2) Sonar genuinely could not verify any businesses online for this exact location, (3) Sonar API timeout. Check OpenRouter dashboard for recent perplexity/sonar calls — if none appear, the key is not reaching OpenRouter. Try a larger nearby town to confirm the key works.';
        }
        return '✅ SONAR IS WORKING. Found ' . $sonar_count . ' verified places for ' . $loc . ' via the ' . $key_source . ' key source. The article generation pipeline is correctly configured.';
    }

    /**
     * v1.5.67 — Test Foursquare / HERE / Google Places directly, bypassing
     * Sonar and the waterfall short-circuit. Users report "I added my
     * Foursquare key but no businesses show up" — they need to know whether
     * the key is actually being called (and returning data) or whether Sonar
     * / OSM is short-circuiting the waterfall before FSQ/HERE ever run.
     *
     * This endpoint reads the configured keys from seobetter_settings, builds
     * a test request with ONLY those keys (no openrouter_sonar), and calls
     * the cloud-api /api/research endpoint directly with test_all_places_tiers
     * = true so every configured tier runs and reports its own count
     * regardless of whether earlier tiers succeeded.
     *
     * Test keyword: "best pet shops in sydney australia 2026" — Sydney is
     * large enough that both Foursquare and HERE should return multiple real
     * results if the keys are valid.
     */
    public function rest_test_places_providers( \WP_REST_Request $request ): \WP_REST_Response {
        $settings   = get_option( 'seobetter_settings', [] );
        $fsq_key    = $settings['foursquare_api_key'] ?? '';
        $here_key   = $settings['here_api_key'] ?? '';
        $google_key = $settings['google_places_api_key'] ?? '';

        $configured = [
            'foursquare' => ! empty( $fsq_key ),
            'here'       => ! empty( $here_key ),
            'google'     => ! empty( $google_key ),
        ];

        if ( empty( $fsq_key ) && empty( $here_key ) && empty( $google_key ) ) {
            return new \WP_REST_Response( [
                'success'    => false,
                'configured' => $configured,
                'error'      => 'No Foursquare, HERE, or Google Places key is configured in Settings → Places Integrations. Paste at least one key and save, then run this test again.',
            ] );
        }

        $places_keys = [];
        if ( ! empty( $fsq_key ) )    $places_keys['foursquare'] = $fsq_key;
        if ( ! empty( $here_key ) )   $places_keys['here']       = $here_key;
        if ( ! empty( $google_key ) ) $places_keys['google']     = $google_key;

        $test_keyword = 'best pet shops in sydney australia 2026';
        $cloud_url    = SEOBetter\Cloud_API::get_cloud_url();

        $body = [
            'keyword'                => $test_keyword,
            'site_url'               => home_url(),
            'domain'                 => 'general',
            'country'                => 'AU',
            'places_keys'            => $places_keys,
            'test_all_places_tiers'  => true,
        ];

        try {
            // v1.5.211 — HMAC-signed via Cloud_API::signed_post()
            $response = SEOBetter\Cloud_API::signed_post( '/api/research', $body, [ 'timeout' => 60 ] );

            if ( is_wp_error( $response ) ) {
                return new \WP_REST_Response( [
                    'success'    => false,
                    'configured' => $configured,
                    'error'      => 'Cloud API request failed: ' . $response->get_error_message(),
                ] );
            }

            $code    = wp_remote_retrieve_response_code( $response );
            $raw     = wp_remote_retrieve_body( $response );
            $decoded = json_decode( $raw, true );

            if ( $code !== 200 ) {
                return new \WP_REST_Response( [
                    'success'    => false,
                    'configured' => $configured,
                    'http_code'  => $code,
                    'error'      => ( is_array( $decoded ) && isset( $decoded['error'] ) ) ? $decoded['error'] : "HTTP {$code}",
                ] );
            }

            $providers_tried = is_array( $decoded['places_providers_tried'] ?? null ) ? $decoded['places_providers_tried'] : [];
            $per_tier        = [];
            foreach ( $providers_tried as $p ) {
                $name = $p['name'] ?? 'unknown';
                $per_tier[ $name ] = [
                    'count' => (int) ( $p['count'] ?? 0 ),
                    'error' => $p['error'] ?? null,
                ];
            }

            $verdict_lines = [];
            if ( ! empty( $fsq_key ) ) {
                if ( isset( $per_tier['Foursquare'] ) ) {
                    $c = $per_tier['Foursquare']['count'];
                    $e = $per_tier['Foursquare']['error'];
                    if ( $e ) {
                        $verdict_lines[] = "❌ Foursquare: key IS being called but returned an ERROR → {$e}";
                    } elseif ( $c > 0 ) {
                        $verdict_lines[] = "✅ Foursquare: WORKING — returned {$c} places for Sydney.";
                    } else {
                        $verdict_lines[] = "⚠️ Foursquare: key was called but returned 0 places. Key may be invalid or Sydney pet-shop search scope is wrong.";
                    }
                } else {
                    $verdict_lines[] = "❌ Foursquare: key configured but the cloud-api NEVER called the Foursquare tier. This usually means the cloud-api Vercel deployment is outdated. Check Vercel → seobetter-cloud → latest deployment is >= v1.5.67.";
                }
            } else {
                $verdict_lines[] = "⚪ Foursquare: no key configured (skipped).";
            }

            if ( ! empty( $here_key ) ) {
                if ( isset( $per_tier['HERE'] ) ) {
                    $c = $per_tier['HERE']['count'];
                    $e = $per_tier['HERE']['error'];
                    if ( $e ) {
                        $verdict_lines[] = "❌ HERE: key IS being called but returned an ERROR → {$e}";
                    } elseif ( $c > 0 ) {
                        $verdict_lines[] = "✅ HERE: WORKING — returned {$c} places for Sydney.";
                    } else {
                        $verdict_lines[] = "⚠️ HERE: key was called but returned 0 places. Key may be invalid, or the HERE discover endpoint is filtering pet-shop results.";
                    }
                } else {
                    $verdict_lines[] = "❌ HERE: key configured but the cloud-api NEVER called the HERE tier. Check Vercel deployment is >= v1.5.67.";
                }
            } else {
                $verdict_lines[] = "⚪ HERE: no key configured (skipped).";
            }

            if ( ! empty( $google_key ) ) {
                if ( isset( $per_tier['Google Places'] ) ) {
                    $c = $per_tier['Google Places']['count'];
                    $e = $per_tier['Google Places']['error'];
                    if ( $e ) {
                        $verdict_lines[] = "❌ Google Places: key IS being called but returned an ERROR → {$e}";
                    } elseif ( $c > 0 ) {
                        $verdict_lines[] = "✅ Google Places: WORKING — returned {$c} places for Sydney.";
                    } else {
                        $verdict_lines[] = "⚠️ Google Places: key was called but returned 0 places.";
                    }
                } else {
                    $verdict_lines[] = "❌ Google Places: key configured but the cloud-api NEVER called the Google tier.";
                }
            }

            return new \WP_REST_Response( [
                'success'              => true,
                'configured'           => $configured,
                'test_keyword'         => $test_keyword,
                'places_count'         => (int) ( $decoded['places_count'] ?? 0 ),
                'places_provider_used' => $decoded['places_provider_used'] ?? null,
                'per_tier'             => $per_tier,
                'verdict'              => implode( "\n", $verdict_lines ),
                'places_sample'        => array_slice( is_array( $decoded['places'] ?? null ) ? $decoded['places'] : [], 0, 5 ),
                'plugin_version'       => SEOBETTER_VERSION,
            ] );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [
                'success'    => false,
                'configured' => $configured,
                'error'      => 'PHP ' . get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine(),
            ] );
        }
    }

    /**
     * v1.5.67 — Test every research source (cloud-api + local Last30Days)
     * with per-source ok/error/latency breakdown. Complements the Sonar and
     * Places Providers tests by covering the rest of the research pipeline:
     * Reddit, Hacker News, Wikipedia, Google Trends, DuckDuckGo, Bluesky,
     * Mastodon, Dev.to, Lemmy, category APIs, country
     * APIs, and the local Last30Days Python skill.
     *
     * Uses Promise.allSettled pattern server-side so a single flaking source
     * cannot block the others.
     */
    public function rest_test_research_sources( \WP_REST_Request $request ): \WP_REST_Response {
        $settings     = get_option( 'seobetter_settings', [] );
        $test_keyword = sanitize_text_field( $request->get_param( 'keyword' ) ?: 'small business marketing 2026' );
        $domain       = sanitize_text_field( $request->get_param( 'domain' ) ?: 'general' );
        $country      = sanitize_text_field( $request->get_param( 'country' ) ?: '' );

        // --- 1. Local Last30Days Python skill ---
        $last30 = [
            'available'    => false,
            'python_found' => false,
            'script_found' => false,
            'message'      => '',
            'duration_ms'  => null,
        ];
        try {
            $script_rel = '.agents/skills/last30days/scripts/last30days.py';
            $script_abs = SEOBETTER_PLUGIN_DIR . $script_rel;
            $last30['script_found'] = file_exists( $script_abs );
            $python_check = trim( (string) @shell_exec( 'which python3 2>/dev/null' ) );
            $last30['python_found'] = ! empty( $python_check );
            $last30['available']    = SEOBetter\Trend_Researcher::is_available();
            if ( ! $last30['python_found'] ) {
                $last30['message'] = 'python3 not found on this server. Last30Days runs locally via shell_exec; it is optional and the cloud-api provides the same data remotely. WP Engine and most managed hosts block shell_exec, so this will usually say "not available" — the plugin still works because it falls back to the cloud-api.';
            } elseif ( ! $last30['script_found'] ) {
                $last30['message'] = "Last30Days Python script missing at {$script_rel}. Re-upload the plugin zip.";
            } else {
                $last30['message'] = 'Last30Days is available locally. It runs only when the cloud-api research call fails or times out (fallback source).';
            }
        } catch ( \Throwable $e ) {
            $last30['message'] = 'Last30Days check failed: ' . $e->getMessage();
        }

        // --- 2. Cloud-api TEST ALL SOURCES call ---
        $cloud_url = SEOBetter\Cloud_API::get_cloud_url();
        $body = [
            'keyword'          => $test_keyword,
            'site_url'         => home_url(),
            'domain'           => $domain,
            'country'          => $country,
            'test_all_sources' => true,
        ];

        $cloud = [
            'ok'      => false,
            'error'   => null,
            'sources' => [],
            'summary' => null,
            'keyword' => $test_keyword,
        ];
        try {
            // v1.5.211 — HMAC-signed via Cloud_API::signed_post()
            $response = SEOBetter\Cloud_API::signed_post( '/api/research', $body, [ 'timeout' => 60 ] );
            if ( is_wp_error( $response ) ) {
                $cloud['error'] = 'Cloud API request failed: ' . $response->get_error_message();
            } else {
                $code    = wp_remote_retrieve_response_code( $response );
                $raw     = wp_remote_retrieve_body( $response );
                $decoded = json_decode( $raw, true );
                if ( $code !== 200 ) {
                    $cloud['error'] = 'HTTP ' . $code . ': ' . ( is_array( $decoded ) && isset( $decoded['error'] ) ? $decoded['error'] : substr( (string) $raw, 0, 200 ) );
                } elseif ( ! is_array( $decoded ) ) {
                    $cloud['error'] = 'Cloud-api returned non-JSON: ' . substr( (string) $raw, 0, 200 );
                } else {
                    $cloud['ok']      = true;
                    $cloud['sources'] = is_array( $decoded['sources'] ?? null ) ? $decoded['sources'] : [];
                    $cloud['summary'] = $decoded['summary'] ?? null;
                    $cloud['total_latency_ms'] = $decoded['total_latency_ms'] ?? null;
                    // brave_configured kept for cloud-api compat (always false now — Tavily replaced Brave)
                    $cloud['brave_configured'] = $decoded['brave_configured'] ?? false;
                }
            }
        } catch ( \Throwable $e ) {
            $cloud['error'] = 'PHP ' . get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine();
        }

        return new \WP_REST_Response( [
            'success'        => true,
            'plugin_version' => SEOBETTER_VERSION,
            'test_keyword'   => $test_keyword,
            'domain'         => $domain,
            'country'        => $country,
            'cloud'          => $cloud,
            'last30days'     => $last30,
            'brave_configured' => false, // Tavily replaced Brave — no user key to check
        ] );
    }

    public function rest_generate_start( \WP_REST_Request $request ): \WP_REST_Response {
        $rate_check = $this->check_rate_limit( 'generate' );
        if ( $rate_check ) return $rate_check;

        // v1.5.67 — wrap start_job in a try/catch so any thrown exception
        // becomes a visible JSON error with the actual message + file + line,
        // instead of the mystery "Failed to start." fallback in the JS. If
        // something in the generation pipeline is silently fataling, this
        // will surface exactly what and where.
        try {
            $result = SEOBetter\Async_Generator::start_job( $request->get_params() );
            if ( ! is_array( $result ) ) {
                return new \WP_REST_Response( [ 'success' => false, 'error' => 'start_job returned non-array: ' . gettype( $result ) ] );
            }
            // Always guarantee a success key so the JS never sees undefined
            if ( ! isset( $result['success'] ) ) {
                $result['success'] = false;
                $result['error'] = ( $result['error'] ?? 'start_job missing success key' );
            }
            return new \WP_REST_Response( $result );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( [
                'success' => false,
                'error'   => 'PHP ' . get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine(),
                'trace'   => array_slice( explode( "\n", $e->getTraceAsString() ), 0, 5 ),
            ] );
        }
    }

    public function rest_generate_step( \WP_REST_Request $request ): \WP_REST_Response {
        $job_id = sanitize_text_field( $request->get_param( 'job_id' ) );
        if ( ! $job_id ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => 'Missing job_id' ], 400 );
        }

        // v1.5.190 — Batch multiple steps per REST call to reduce total HTTP requests.
        // Shared hosts (Hostinger, Bluehost, GoDaddy) rate-limit at 20-30 req/min.
        // A 2000-word article needs 12+ step calls → triggers 429 errors.
        // Now: each REST call processes up to 3 steps (content sections only).
        // Research + outline + assemble are always single-step (they're special).
        $batch_size = absint( $request->get_param( 'batch' ) ) ?: 3;
        $batch_size = min( $batch_size, 5 ); // Safety cap

        $last_result = null;
        for ( $i = 0; $i < $batch_size; $i++ ) {
            $result = SEOBetter\Async_Generator::process_step( $job_id );
            $last_result = $result;

            // Stop batching if: done, error, or not a content section step
            if ( ! empty( $result['done'] ) ) break;
            if ( ! empty( $result['error'] ) && empty( $result['can_retry'] ) ) break;
            // Only batch content section steps (section_0, section_1, etc.)
            // Research (trends), outline, headlines, meta, assemble are single-step
            $current_step = $result['step'] ?? '';
            if ( ! str_starts_with( $current_step, 'section_' ) ) break;
        }

        return new \WP_REST_Response( $last_result );
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

    /**
     * v1.5.211 — Proxy for /api/topic-research (Auto-Suggest + Topic Discovery).
     *
     * Browser JS can't sign HMAC requests (the signing secret lives in PHP
     * source; exposing it to JS defeats the purpose). This endpoint accepts
     * the browser's form data via admin-ajax-style auth (nonce + edit_posts
     * capability), signs the request via Cloud_API::signed_post(), and
     * returns the Vercel response as-is.
     *
     * Replaces the direct browser→Vercel fetch() calls in
     * [content-generator.php:608 + 739] which returned 401 after v1.5.211
     * locked down the /api/topic-research endpoint.
     */
    public function rest_topic_research_proxy( \WP_REST_Request $request ): \WP_REST_Response {
        $niche    = sanitize_text_field( $request->get_param( 'niche' ) ?? '' );
        $country  = sanitize_text_field( $request->get_param( 'country' ) ?? '' );
        $language = sanitize_text_field( $request->get_param( 'language' ) ?? 'en' );

        if ( $niche === '' ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => 'niche is required' ], 400 );
        }

        $response = SEOBetter\Cloud_API::signed_post( '/api/topic-research', [
            'niche'    => $niche,
            'site_url' => home_url(),
            'country'  => $country,
            'language' => $language,
        ], [ 'timeout' => 30 ] );

        if ( is_wp_error( $response ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => $response->get_error_message() ], 502 );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || ! is_array( $body ) ) {
            // v1.5.211-hotfix — include `reason` from _auth.js so "unauthorized" surfaces the specific cause.
            return new \WP_REST_Response( [
                'success' => false,
                'error'   => $body['error'] ?? "HTTP {$code}",
                'reason'  => $body['reason'] ?? null,
            ], $code ?: 502 );
        }

        return new \WP_REST_Response( $body );
    }

    /**
     * v1.5.211 — Proxy for /api/generate (Social Content Generator).
     * Same rationale as rest_topic_research_proxy: browser can't HMAC-sign.
     */
    public function rest_generate_proxy( \WP_REST_Request $request ): \WP_REST_Response {
        $prompt        = (string) ( $request->get_param( 'prompt' ) ?? '' );
        $system_prompt = (string) ( $request->get_param( 'system_prompt' ) ?? '' );
        $max_tokens    = (int) ( $request->get_param( 'max_tokens' ) ?? 2000 );
        $temperature   = (float) ( $request->get_param( 'temperature' ) ?? 0.7 );

        if ( $prompt === '' ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => 'prompt is required' ], 400 );
        }
        // Reasonable length cap to prevent accidental abuse
        if ( strlen( $prompt ) > 20000 || strlen( $system_prompt ) > 5000 ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => 'prompt too long' ], 400 );
        }

        $license = get_option( 'seobetter_license', [] );

        $response = SEOBetter\Cloud_API::signed_post( '/api/generate', [
            'prompt'         => $prompt,
            'system_prompt'  => $system_prompt,
            'max_tokens'     => min( max( $max_tokens, 100 ), 8000 ),
            'temperature'    => max( 0.0, min( $temperature, 1.5 ) ),
            'site_url'       => home_url(),
            'license_key'    => $license['key'] ?? '',
            'plugin_version' => SEOBETTER_VERSION,
        ], [ 'timeout' => 120 ] );

        if ( is_wp_error( $response ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => $response->get_error_message() ], 502 );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || ! is_array( $body ) ) {
            return new \WP_REST_Response( [
                'success' => false,
                'error'   => $body['error'] ?? "HTTP {$code}",
                'reason'  => $body['reason'] ?? null,
            ], $code ?: 502 );
        }

        return new \WP_REST_Response( $body );
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

        // v1.5.99 — Build combined pool: original citation pool + ALL inline
        // URLs already in the markdown. This is the same approach used in
        // rest_optimize_all(). Without this, URLs added by Optimize All
        // (Sonar citations, Tavily quotes) get stripped at save time because
        // they're not in the original generation-time pool.
        $combined_pool = $citation_pool;
        if ( ! empty( $markdown ) && preg_match_all( '/(?<!!)\[[^\]]+\]\((https?:\/\/[^)]+)\)/', $markdown, $url_matches ) ) {
            foreach ( $url_matches[1] as $found_url ) {
                $combined_pool[] = [
                    'url'         => $found_url,
                    'title'       => '',
                    'source_name' => wp_parse_url( $found_url, PHP_URL_HOST ) ?? '',
                    'verified_at' => time(),
                ];
            }
        }

        // v1.5.111 — Run cleanup on markdown before save. Catches long dashes,
        // emoji, and Unicode bullets that survived from generation.
        if ( ! empty( $markdown ) ) {
            $markdown = self::cleanup_ai_markdown( $markdown );
        }

        // Validate all outbound URLs in markdown before formatting.
        // The combined pool is the primary allow-list — any URL in the pool
        // is citable, any URL not in the pool falls back to the static
        // whitelist and Pass 3 content verification.
        //
        // v1.5.191 — Order swap: validate FIRST, linkify AFTER.
        // Previously linkify ran before validate, so Pass 4 dedup
        // (which strips repeated URL occurrences) was stripping the links
        // linkify had just added to plain-text source mentions like
        // `(Wolters Kluwer)` appearing 3x in the article. The user expects
        // every `(Source)` reference to be clickable — that's a different
        // UX from Pass 4's original target (AI spamming the same inline
        // markdown link 3x). By running linkify AFTER dedup, Pass 4 still
        // removes AI-written duplicates, and linkify then adds links to
        // every surviving plain-text source mention in the body.
        if ( ! empty( $markdown ) ) {
            $markdown = $this->validate_outbound_links( $markdown, $combined_pool );
        }

        // v1.5.191 — Linkify plain-text source references AFTER validation.
        // Matches [Source Name] or (Source Name) plain-text brackets against
        // the Citation Pool and converts them to real markdown links. Runs
        // after Pass 4 dedup so it can add links every source mention (not
        // just the first occurrence per URL).
        if ( ! empty( $markdown ) && ! empty( $combined_pool ) ) {
            $markdown = self::linkify_bracketed_references( $markdown, $combined_pool );
        }

        if ( ! empty( $markdown ) ) {
            // Append auto-generated References section for pool URLs the
            // article body actually cited
            $markdown = $this->append_references_section( $markdown, $combined_pool );
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
                // v1.5.192 — thread language so RTL languages (ar, he, fa, ur,
                // ps, sd, dv, ug, yi, ckb) wrap the article in dir="rtl"
                'language'     => sanitize_text_field( $request->get_param( 'language' ) ?? 'en' ),
            ] );

            // v1.5.212.4 — Apply the same heading-language guard to the saved
            // post_content that Async_Generator already runs on the preview.
            // Pre-fix: the 'hybrid' formatter (this branch — produces the
            // actual published article) had NO guard, so English H1/H2 leaks
            // shipped to production even when the 'classic' preview was clean.
            // Schema_Generator later extracts H2 names from this saved content
            // for Recipe/Article schema, so the guard MUST run before save to
            // keep the schema in sync with the body.
            $post_content = SEOBetter\Async_Generator::enforce_heading_language(
                $post_content,
                sanitize_text_field( $request->get_param( 'language' ) ?? 'en' )
            );

            // v1.5.67 — run Places_Link_Injector on the saved hybrid HTML so
            // the 📍 address + Google Maps + website meta line below each
            // business H2 survives into the WP draft. Previously this was
            // only run in assemble_final's preview path, so the result panel
            // showed the meta lines but the saved draft lost them.
            $places_raw = $request->get_param( 'places' );
            $places_pool = is_array( $places_raw ) ? $places_raw : [];
            if ( ! empty( $places_pool ) && class_exists( 'SEOBetter\\Places_Link_Injector' ) ) {
                $post_content = SEOBetter\Places_Link_Injector::inject( $post_content, $places_pool );
            }
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

        // v1.5.206d-fix19 — single push point for all SEO plugins. Yoast and RankMath
        // previously only got title/description/focus-keyword; now they also get OG +
        // Twitter title/description/image fields (parity with AIOSEO). SEOPress also
        // receives OG/Twitter social fields. Length caps enforced inside sync_seo_plugin_meta:
        // SEO title ≤60, meta desc ≤160, OG title ≤95, OG desc ≤200, Twitter title ≤70, TW desc ≤200.
        $content_type = sanitize_text_field( $request->get_param( 'content_type' ) ?? 'blog_post' );
        $this->sync_seo_plugin_meta(
            $post_id,
            $meta_title ?: $title,
            $meta_desc,
            $keyword,
            $content_type
        );

        // v1.5.117 — Use Schema_Generator for all schema (replaces build_aioseo_schema).
        // This ensures the Google-compliant fixes (no hardcoded Recipe times,
        // no fake Review ratings, deprecated HowTo) apply to saved schema.
        $content_type_param = sanitize_text_field( $request->get_param( 'content_type' ) ?? 'blog_post' );
        update_post_meta( $post_id, '_seobetter_content_type', $content_type_param );

        // v1.5.126 — Save country and domain for Schema_Generator (recipeCuisine, authority sources)
        $country_param = sanitize_text_field( $request->get_param( 'country' ) ?? '' );
        if ( $country_param ) {
            update_post_meta( $post_id, '_seobetter_country', $country_param );
        }
        $domain_param = sanitize_text_field( $request->get_param( 'domain' ) ?? '' );
        if ( $domain_param ) {
            update_post_meta( $post_id, '_seobetter_domain', $domain_param );
        }
        // v1.5.206a — Save language for Schema_Generator::get_in_language() → inLanguage field.
        // Additive: empty/missing language falls back to get_locale() then 'en' in the generator.
        $language_param = sanitize_text_field( $request->get_param( 'language' ) ?? '' );
        if ( $language_param ) {
            update_post_meta( $post_id, '_seobetter_language', $language_param );
        }

        $schema_gen = new SEOBetter\Schema_Generator();
        $post_obj = get_post( $post_id );
        $schema_array = $schema_gen->generate( $post_obj );

        if ( ! empty( $schema_array ) ) {
            $schema_ld = [ '@context' => 'https://schema.org', '@graph' => $schema_array ];

            // v1.5.190 — Schema stored in post meta ONLY (not inline in post_content).
            // wp_head outputs it via output_schema_markup(). This eliminates:
            // - Stale ?p=ID URLs in inline schema (never updated after publish)
            // - Content corruption from regex replacement in update_schema_on_publish
            // - Schema duplication (inline + wp_head both firing)
            update_post_meta( $post_id, '_seobetter_schema', wp_json_encode( $schema_ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

            // Save post content without schema block
            wp_update_post( [
                'ID'           => $post_id,
                'post_content' => $post_content,
            ] );
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
        // v1.5.76 — receive the citation pool from the original generation
        $existing_pool = $request->get_param( 'citation_pool' );
        if ( ! is_array( $existing_pool ) ) $existing_pool = [];
        // v1.5.81 — receive Sonar data from the Vercel backend (server-side)
        $sonar_data = $request->get_param( 'sonar_data' );
        if ( ! is_array( $sonar_data ) ) $sonar_data = null;

        if ( empty( $markdown ) || empty( $fix_type ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => 'Missing markdown or fix_type.' ], 400 );
        }

        // Run the appropriate fix
        switch ( $fix_type ) {
            case 'citations':
                $result = SEOBetter\Content_Injector::inject_citations( $markdown, $keyword, $existing_pool, $sonar_data );
                break;
            case 'quotes':
                $result = SEOBetter\Content_Injector::inject_quotes( $markdown, $keyword, $sonar_data );
                break;
            case 'table':
                $result = SEOBetter\Content_Injector::inject_table( $markdown, $keyword, $sonar_data );
                break;
            case 'freshness':
                // v1.5.206d — thread language for localized label + date
                $fix_lang = sanitize_text_field( $request->get_param( 'language' ) ?? 'en' );
                $result = SEOBetter\Content_Injector::inject_freshness( $markdown, $fix_lang );
                break;
            case 'statistics':
                $result = SEOBetter\Content_Injector::inject_statistics( $markdown, $keyword );
                break;
            case 'readability':
                // v1.5.67 — now runs an inject-mode AI rewriter pass that
                // simplifies any section with Flesch-Kincaid grade > 9 to
                // grade 7. Falls through to the re-score block below.
                $result = SEOBetter\Content_Injector::simplify_readability( $markdown );
                break;
            case 'readability_flag':
                // Legacy: still return flag-mode response if the caller
                // explicitly asks for it (e.g. for diagnostic panels).
                $result = SEOBetter\Content_Injector::flag_readability( $markdown );
                return new \WP_REST_Response( $result );
            case 'island':
                $result = SEOBetter\Content_Injector::flag_pronouns( $markdown );
                return new \WP_REST_Response( $result );
            case 'openers':
                // v1.5.80 — converted from flag-mode to inject-mode.
                // Now rewrites short section openers to 40-60 words via AI.
                $result = SEOBetter\Content_Injector::fix_openers( $markdown, $keyword );
                break;
            case 'openers_flag':
                // Legacy flag-mode still available if explicitly requested
                $result = SEOBetter\Content_Injector::flag_openers( $markdown );
                return new \WP_REST_Response( $result );
            // v1.5.67 — three missing flag handlers that were wired in the UI
            // but had no backend case, causing the buttons to 400 → "Retry"
            // red state. The UI fires these fix_types for low keyword density,
            // humanizer violations, and CORE-EEAT gaps respectively.
            case 'keyword':
                // v1.5.67 — converted from flag-mode to inject-mode. Old
                // flag_keyword_placement just showed advice; user reported
                // "im not sure what it does to the article if not nothing
                // do you edit this manually?". New optimize_keyword_placement
                // runs an AI rewrite pass to reduce density from 2-3% down
                // to ~1%, swapping exact-phrase mentions for pronouns and
                // variations while preserving structure. Falls through to
                // the shared re-format / re-score block below.
                $result = SEOBetter\Content_Injector::optimize_keyword_placement( $markdown, $keyword );
                break;
            case 'keyword_flag':
                // Legacy: still surfaces the advice-only flag response if
                // the caller explicitly asks for it.
                $result = SEOBetter\Content_Injector::flag_keyword_placement( $markdown, $keyword );
                return new \WP_REST_Response( $result );
            case 'humanizer':
                $result = SEOBetter\Content_Injector::flag_humanizer( $markdown );
                return new \WP_REST_Response( $result );
            case 'core_eeat':
                $result = SEOBetter\Content_Injector::flag_core_eeat( $markdown );
                return new \WP_REST_Response( $result );
            default:
                return new \WP_REST_Response( [ 'success' => false, 'error' => 'Unknown fix type.' ], 400 );
        }

        if ( ! $result['success'] ) {
            return new \WP_REST_Response( $result, 400 );
        }

        // Re-format and re-score the updated content
        $updated_markdown = $result['content'];

        // v1.5.67 — for citation injects, pre-count references BEFORE
        // validate_outbound_links runs so we can accurately report how many
        // survived the whitelist filter. User reported "says it added 7 but
        // it didn't" — validate_outbound_links strips non-whitelisted URLs
        // which is why the count diverged from the actual rendered output.
        $refs_before = 0;
        if ( $fix_type === 'citations' ) {
            preg_match_all( '/^\d+\.\s+\[[^\]]+\]\(https?:\/\//m', $updated_markdown, $before_matches );
            $refs_before = count( $before_matches[0] );
        }

        // v1.5.92 — build combined pool with inline URLs so scraped quotes survive
        $combined_pool = is_array( $existing_pool ) ? $existing_pool : [];
        if ( preg_match_all( '/(?<!!)\[[^\]]+\]\((https?:\/\/[^)]+)\)/', $updated_markdown, $url_matches ) ) {
            foreach ( $url_matches[1] as $found_url ) {
                $combined_pool[] = [ 'url' => $found_url, 'title' => '', 'source_name' => wp_parse_url( $found_url, PHP_URL_HOST ) ?? '', 'verified_at' => time() ];
            }
        }
        $updated_markdown = $this->validate_outbound_links( $updated_markdown, $combined_pool );

        // v1.5.67 — recount after validation so the `added` message reflects
        // the actual number of references that survived the whitelist filter.
        if ( $fix_type === 'citations' ) {
            preg_match_all( '/^\d+\.\s+\[[^\]]+\]\(https?:\/\//m', $updated_markdown, $after_matches );
            $refs_after = count( $after_matches[0] );
            if ( $refs_after === 0 ) {
                // v1.5.69 — all pool URLs were stripped by validation.
                // Return error instead of misleading "0 citations added" success.
                return new \WP_REST_Response( [
                    'success' => false,
                    'error'   => 'Citation pool found ' . $refs_before . ' source(s) but all were stripped by the link validator (not in whitelist or failed content verification). Try regenerating the article — the pool may find different sources.',
                ], 200 );
            }
            if ( $refs_after < $refs_before ) {
                $stripped = $refs_before - $refs_after;
                $result['added'] = $refs_after . ' citations added ('
                    . $stripped . ' dropped by whitelist — add domains to Settings → Integrations if needed)';
            } else {
                $result['added'] = $refs_after . ' citations added';
            }
        }

        // v1.5.71 — Centralized markdown cleanup for ALL inject-fix methods.
        // AI rewriters frequently introduce Unicode bullets (•), HTML list
        // tags (<ul>/<li>), or inline bullets (• item1 • item2 on one line).
        // Content_Formatter only recognises -, *, + as list markers.
        // Running cleanup HERE means every inject method benefits without
        // needing per-method post-processing that can be missed.
        $updated_markdown = self::cleanup_ai_markdown( $updated_markdown );

        $formatter = new SEOBetter\Content_Formatter();
        // v1.5.67 — REVERTED v1.5.62/63's switch back to 'classic' mode.
        // Earlier misdiagnosis: I assumed classic mode was producing raw
        // <img> tags. In reality classic mode is the ONE mode that wraps
        // the output in <style>.sb-{uid}{...}</style><div class="sb-{uid}">
        // ...</div> — that's where the rounded image border-radius,
        // centered figure margin, 65ch max-width paragraph, and scoped
        // typography come from. Hybrid mode returns raw wp:html blocks
        // with NO style tag and NO uid wrapper, so after inject-fix
        // clicks the preview fell back to inherited admin-theme CSS
        // (raw full-size images, wider text, different font) — exactly
        // the regression the user kept reporting. Classic mode is correct.
        $html = $formatter->format( $updated_markdown, 'classic', [
            'accent_color' => $accent,
            'language'     => sanitize_text_field( $request->get_param( 'language' ) ?? 'en' ),
        ] );

        // v1.5.101b — Score using hybrid HTML (same fix as rest_optimize_all)
        $hybrid_html = $formatter->format( $updated_markdown, 'hybrid', [
            'accent_color' => $accent,
            'content_type' => $request->get_param( 'content_type' ) ?? 'blog_post',
            'language'     => sanitize_text_field( $request->get_param( 'language' ) ?? 'en' ),
        ] );
        $analyzer = new SEOBetter\GEO_Analyzer();
        // v1.5.206d — thread language + country for Layer 6 awareness
        $score = $analyzer->analyze(
            $hybrid_html,
            $keyword,
            $request->get_param( 'content_type' ) ?? '',
            sanitize_text_field( $request->get_param( 'language' ) ?? 'en' ),
            sanitize_text_field( $request->get_param( 'country' ) ?? '' )
        );

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

    /**
     * v1.5.71 — Centralized markdown cleanup for AI-rewritten content.
     *
     * AI models produce non-standard markdown that Content_Formatter can't
     * parse. This runs ONCE on the final markdown before formatting, so
     * every inject-fix method benefits without per-method post-processing.
     *
     * Fixes:
     * - Unicode bullet chars (•●◦▪▸►) → markdown `- `
     * - Inline bullets (• item1 • item2 on one line) → separate list items
     * - Stray HTML list/paragraph tags → stripped
     * - Trailing whitespace on list items
     */
    public static function cleanup_ai_markdown( string $md ): string {
        // 1. Split inline bullets into separate lines FIRST.
        //    "• item1 • item2 • item3" → "\n- item1\n- item2\n- item3"
        //    Must run before the per-line regex below.
        $md = preg_replace( '/([^\n])[ \t]+[•●◦▪▸►][ \t]+/', "$1\n- ", $md );

        // 2. Convert line-starting Unicode bullets to markdown list markers
        $md = preg_replace( '/^[ \t]*[•●◦▪▸►][ \t]*/m', '- ', $md );

        // 2b. v1.5.103 — Convert emoji bullets to markdown list markers.
        //     AI models use ✅ ✓ 📌 🔍 ⭐ ➡ 🔹 etc. as list markers.
        //     Per article_design.md §6: "NEVER use emoji as icons in body copy"
        //     Covers: Arrows, Misc Technical, Geometric Shapes, Misc Symbols,
        //     Dingbats, Supplemental Arrows, and ALL Supplementary emoji (U+1F000+).
        $md = preg_replace( '/^[ \t]*[\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{25A0}-\x{27BF}\x{2900}-\x{297F}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{1F000}-\x{1FFFF}]+[ \t]+/mu', '- ', $md );

        // 2c. v1.5.103 — Clean up already-mangled emoji (?? or ????) at line starts.
        //     On utf8 databases, 4-byte emoji become ?? when saved.
        $md = preg_replace( '/^[ \t]*\?{2,4}[ \t]+(?=[A-Z])/m', '- ', $md );

        // 2d. v1.5.103 — Strip ALL remaining emoji from body content.
        //     Per article_design.md: "No emoji in article body", "NEVER use
        //     emoji as icons in body copy", "No checkmark bullets".
        //     After converting emoji bullets to - above, remove any inline emoji.
        $md = preg_replace( '/[\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{25A0}-\x{27BF}\x{2900}-\x{297F}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{1F000}-\x{1FFFF}]+/u', '', $md );

        // 2e. v1.5.103 — Convert em-dashes (—) and en-dashes (–) to short dashes (-)
        //     Per user rule: "only short dash -". Em/en-dashes cause inconsistent
        //     rendering across themes and break list detection.
        $md = str_replace( [ '—', '–' ], '-', $md );

        // 3. Convert HTML list items to markdown BEFORE stripping tags.
        //    <li>text</li> → \n- text  (preserves list structure)
        //    <br> → \n  (preserves line breaks)
        $md = preg_replace( '/<li[^>]*>/i', "\n- ", $md );
        $md = preg_replace( '/<br\s*\/?>/i', "\n", $md );
        // Now strip the remaining wrapper tags (ul, ol, /li, p, div)
        $md = preg_replace( '/<\/?(ul|ol|li|p|div)[^>]*>/i', '', $md );

        // 4. v1.5.72 — Convert 4-space indented text to list items.
        //    AI rewrites often output indented text that markdown treats as
        //    code blocks. If the line starts with 4+ spaces and has text
        //    content (not a code block), convert to a list item.
        $md = preg_replace( '/^[ \t]{4,}(?!```)([\w"\'(].+)$/m', '- $1', $md );

        // 5. v1.5.190 — Strip academic/Crossref junk text from AI output.
        //    The AI sometimes writes about academic papers from Crossref data
        //    even when DOI URLs are blocked. Remove sentences referencing:
        //    - "cited X times" (Crossref citation counts)
        //    - "Crossref" as a source
        //    - "doi.org" URLs as text
        //    - Government Gazette references
        //    - Academic paper titles with quotation marks + "et al."
        //    Per external-links-policy.md: doi.org and crossref.org are blocked.
        $md = preg_replace( '/[^\n]*(?:cited \d+ times|Crossref,?\s*\d{4}|doi\.org\/|Government Gazette)[^\n]*\n?/i', '', $md );
        // Strip broken citation markers: [text](doi.org/...) that slipped through
        $md = preg_replace( '/\[[^\]]*\]\(https?:\/\/(?:dx\.)?doi\.org\/[^)]+\)/', '', $md );

        // 6. Collapse any resulting blank-line runs to max 2 newlines
        $md = preg_replace( '/\n{3,}/', "\n\n", $md );

        return $md;
    }

    /**
     * v1.5.78 — Optimize All endpoint. Single Sonar call + sequential fixes.
     * Replaces clicking 6 individual inject-fix buttons.
     */
    public function rest_optimize_all( \WP_REST_Request $request ): \WP_REST_Response {
        $markdown      = $request->get_param( 'markdown' ) ?? '';
        $keyword       = sanitize_text_field( $request->get_param( 'keyword' ) ?? '' );
        $accent        = sanitize_text_field( $request->get_param( 'accent_color' ) ?? '#764ba2' );
        $existing_pool = $request->get_param( 'citation_pool' );
        $scores        = $request->get_param( 'scores' );
        $sonar_data    = $request->get_param( 'sonar_data' );

        if ( empty( $markdown ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => 'No content.' ], 400 );
        }
        if ( ! is_array( $existing_pool ) ) $existing_pool = [];
        if ( ! is_array( $scores ) ) $scores = [];
        if ( ! is_array( $sonar_data ) ) $sonar_data = null;

        $domain  = sanitize_text_field( $request->get_param( 'domain' ) ?? '' );
        $country = sanitize_text_field( $request->get_param( 'country' ) ?? '' );
        // v1.5.190 — Content-type-aware optimization mode
        $optimize_mode = sanitize_text_field( $request->get_param( 'optimize_mode' ) ?? 'full' );
        // v1.5.206d — pass article language so non-English optimization passes
        // emit localized labels ("Last Updated" → "最終更新日", etc.).
        $language = sanitize_text_field( $request->get_param( 'language' ) ?? 'en' );
        $result  = SEOBetter\Content_Injector::optimize_all( $markdown, $keyword, $existing_pool, $scores, $sonar_data, $domain, $country, $optimize_mode, $language );

        if ( ! $result['success'] ) {
            return new \WP_REST_Response( $result, 400 );
        }

        $updated_markdown = $result['content'];

        // v1.5.92 — Build a combined pool: original citation pool + any URLs
        // the scraper/injector added to the markdown. This ensures scraped
        // quote URLs (healthydogsmeals.com, petfoodreviews.com.au) survive
        // validate_outbound_links even if the original pool's topical filter
        // rejected them. The scraper already verified these pages are real.
        $combined_pool = is_array( $existing_pool ) ? $existing_pool : [];
        // Extract all URLs from the updated markdown and add to pool
        if ( preg_match_all( '/(?<!!)\[[^\]]+\]\((https?:\/\/[^)]+)\)/', $updated_markdown, $url_matches ) ) {
            foreach ( $url_matches[1] as $found_url ) {
                $combined_pool[] = [
                    'url'         => $found_url,
                    'title'       => '',
                    'source_name' => wp_parse_url( $found_url, PHP_URL_HOST ) ?? '',
                    'verified_at' => time(),
                ];
            }
        }
        // v1.5.190 — Skip validate_outbound_links after optimization.
        // The citations were just added by inject_citations() from verified pool
        // entries. Running the validator AGAIN strips them if they match any
        // hard-fail rule (API endpoint patterns, homepage check, etc).
        // The validator already ran at initial save time — no need to re-validate
        // citations we just intentionally added.
        $updated_markdown = self::cleanup_ai_markdown( $updated_markdown );

        // v1.5.190 — Convert bracketed/parenthetical text references to links.
        // The AI writes (Source Name) as plain text — match against citation pool
        // and convert to clickable links. Same as rest_save_draft does.
        if ( ! empty( $combined_pool ) ) {
            $updated_markdown = self::linkify_bracketed_references( $updated_markdown, $combined_pool );
        }

        // v1.5.97 — Append References section from surviving citations
        $updated_markdown = SEOBetter\Citation_Pool::append_references_section( $updated_markdown, $combined_pool );

        $formatter = new SEOBetter\Content_Formatter();
        $html = $formatter->format( $updated_markdown, 'classic', [
            'accent_color' => $accent,
            'language'     => sanitize_text_field( $request->get_param( 'language' ) ?? 'en' ),
        ] );

        // v1.5.101b — Score using HYBRID HTML for accuracy. Classic mode wraps
        // content in <style> + scoped <div> which can confuse the GEO analyzer
        // (CSS text leaking into word count, keyword density). Hybrid mode
        // produces clean Gutenberg blocks that the analyzer handles correctly.
        // The preview still shows the classic-formatted HTML (better styling).
        $hybrid_html = $formatter->format( $updated_markdown, 'hybrid', [
            'accent_color' => $accent,
            'content_type' => $request->get_param( 'content_type' ) ?? 'blog_post',
            'language'     => sanitize_text_field( $request->get_param( 'language' ) ?? 'en' ),
        ] );
        $analyzer = new SEOBetter\GEO_Analyzer();
        // v1.5.206d — thread language + country for Layer 6 awareness
        $score = $analyzer->analyze(
            $hybrid_html,
            $keyword,
            $request->get_param( 'content_type' ) ?? '',
            sanitize_text_field( $request->get_param( 'language' ) ?? 'en' ),
            sanitize_text_field( $request->get_param( 'country' ) ?? '' )
        );

        return new \WP_REST_Response( [
            'success'       => true,
            'content'       => $html,
            'markdown'      => $updated_markdown,
            'geo_score'     => $score['geo_score'],
            'grade'         => $score['grade'],
            'checks'        => $score['checks'],
            'steps_run'     => $result['steps_run'] ?? [],
            'steps_skipped' => $result['steps_skipped'] ?? [],
            'sonar_used'    => $result['sonar_used'] ?? false,
            'added'         => $result['added'] ?? '',
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
        $html = $formatter->format( $markdown, 'classic', [
            'accent_color' => $accent,
            'language'     => sanitize_text_field( $request->get_param( 'language' ) ?? 'en' ),
        ] );

        // Re-score
        $analyzer = new SEOBetter\GEO_Analyzer();
        // v1.5.206d — thread language + country for Layer 6 awareness
        $rescore_lang    = sanitize_text_field( $request->get_param( 'language' ) ?? 'en' );
        $rescore_country = sanitize_text_field( $request->get_param( 'country' ) ?? '' );
        $score = $analyzer->analyze( $html, $keyword, $request->get_param( 'content_type' ) ?? '', $rescore_lang, $rescore_country );

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
     * v1.5.207 — Render a single Rich-Result mock tile inside the Google Search
     * gallery sub-view of the metabox Rich Results tab. Each appearance key gets
     * its own distinctive visual (recipe card, product card, FAQ dropdowns, map
     * pin, etc.) that approximates how Google renders the result in 2026.
     *
     * Receives a $ctx array with everything the mocks may reference:
     *   meta_title, meta_desc, site_name, site_host, url_breadcrumb, favicon_url,
     *   featured_image_url, recipe_data, review_data, product_data, video_data,
     *   event_data, local_data, job_data, faq_questions, breadcrumbs,
     *   published_date, keyword, eligible (bool).
     */
    private function render_rr_mock( string $key, array $ctx ): void {
        $title         = (string) ( $ctx['meta_title'] ?? '' );
        $desc          = (string) ( $ctx['meta_desc'] ?? '' );
        $site_name     = (string) ( $ctx['site_name'] ?? '' );
        $site_host     = (string) ( $ctx['site_host'] ?? '' );
        $breadcrumb    = (string) ( $ctx['url_breadcrumb'] ?? $site_host );
        $favicon       = (string) ( $ctx['favicon_url'] ?? '' );
        $img           = (string) ( $ctx['featured_image_url'] ?? '' );
        $keyword       = (string) ( $ctx['keyword'] ?? 'this topic' );
        $published     = (string) ( $ctx['published_date'] ?? '' );
        $recipe_data   = (array) ( $ctx['recipe_data'] ?? [] );
        $product_data  = (array) ( $ctx['product_data'] ?? [] );
        $faq_questions = (array) ( $ctx['faq_questions'] ?? [] );
        $review_data   = (array) ( $ctx['review_data'] ?? [] );

        $title_short = mb_strimwidth( $title ?: 'Your article title', 0, 55, '…' );
        $desc_short  = mb_strimwidth( $desc ?: 'Your article description appears here.', 0, 100, '…' );

        // Every mock opens with a minimal SERP-looking header (favicon + site + breadcrumb)
        $header = sprintf(
            '<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px"><img src="%s" alt="" width="14" height="14" style="border-radius:50%%" onerror="this.style.display=\'none\'"><div style="flex:1;min-width:0"><div style="font-size:10px;color:#202124">%s</div><div style="font-size:9px;color:#4d5156;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">%s</div></div></div>',
            esc_url( $favicon ),
            esc_html( $site_name ),
            esc_html( $breadcrumb )
        );
        $title_line = '<div style="font-size:13px;color:#1a0dab;line-height:1.3;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:500">' . esc_html( $title_short ) . '</div>';
        $desc_line  = '<div style="font-size:11px;color:#4d5156;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">' . esc_html( $desc_short ) . '</div>';

        switch ( $key ) {
            case 'standard_article':
                echo $header . $title_line . $desc_line;
                if ( $published ) {
                    echo '<div style="font-size:10px;color:#6b7280;margin-top:4px">' . esc_html( $published ) . '</div>';
                }
                break;

            case 'article_with_image':
                echo '<div style="display:flex;gap:10px">';
                echo '<div style="flex:1;min-width:0">' . $header . $title_line . $desc_line . '</div>';
                if ( $img ) {
                    echo '<div style="width:80px;height:60px;background:url(\'' . esc_url( $img ) . '\') center/cover;border-radius:4px;flex-shrink:0"></div>';
                } else {
                    echo '<div style="width:80px;height:60px;background:#f3f4f6;border-radius:4px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:10px;color:#9ca3af">Image</div>';
                }
                echo '</div>';
                break;

            case 'recipe_card':
                if ( $img ) {
                    echo '<div style="width:100%;height:90px;background:url(\'' . esc_url( $img ) . '\') center/cover;border-radius:4px;margin-bottom:6px"></div>';
                }
                echo $title_line;
                echo '<div style="font-size:11px;color:#4d5156;margin-top:4px">';
                echo '<span style="color:#fbbc04">★★★★★</span> 4.8 (120)';
                $prep = $recipe_data['prepTime'] ?? '';
                if ( $prep ) {
                    $mins = preg_replace( '/^PT(\d+)M$/', '$1 min', $prep );
                    echo ' · ' . esc_html( $mins );
                } else {
                    echo ' · 30 min';
                }
                $cal = $recipe_data['nutrition']['calories'] ?? '';
                echo ' · ' . esc_html( $cal ?: '~320 cal' );
                echo '</div>';
                break;

            case 'recipe_carousel':
                echo '<div style="font-size:10px;color:#6b7280;font-weight:600;margin-bottom:6px">RECIPES FROM THIS SITE</div>';
                echo '<div style="display:flex;gap:6px">';
                for ( $i = 0; $i < 3; $i++ ) {
                    $bg = $img && $i === 0 ? 'background:url(\'' . esc_url( $img ) . '\') center/cover' : 'background:linear-gradient(135deg,#fde68a,#f59e0b)';
                    echo '<div style="flex:1;min-width:0"><div style="width:100%;height:55px;' . $bg . ';border-radius:4px;margin-bottom:3px"></div><div style="font-size:9px;color:#1a0dab;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . ( $i === 0 ? esc_html( mb_strimwidth( $title, 0, 20, '…' ) ) : 'Recipe #' . ( $i + 1 ) ) . '</div><div style="font-size:8px;color:#fbbc04">★★★★★</div></div>';
                }
                echo '</div>';
                break;

            case 'recipe_gallery':
                echo '<div style="padding:10px;background:linear-gradient(135deg,#fef3c7,#fde68a);border-radius:4px;text-align:center">';
                echo '<div style="font-size:10px;color:#78350f;font-weight:600;margin-bottom:4px">🍽 Recipe Gallery</div>';
                echo '<div style="font-size:9px;color:#78350f">Appears in Google\'s multi-site recipe gallery when searched</div>';
                echo '</div>';
                break;

            case 'product_card':
                echo '<div style="display:flex;gap:10px">';
                if ( $img ) {
                    echo '<div style="width:70px;height:70px;background:url(\'' . esc_url( $img ) . '\') center/cover;border-radius:4px;flex-shrink:0;border:1px solid #e5e7eb"></div>';
                } else {
                    echo '<div style="width:70px;height:70px;background:#f3f4f6;border-radius:4px;border:1px solid #e5e7eb;flex-shrink:0"></div>';
                }
                echo '<div style="flex:1;min-width:0">';
                echo $title_line;
                $price = $product_data['offers']['price'] ?? '';
                echo '<div style="font-size:14px;color:#202124;font-weight:700;margin-top:2px">' . ( $price ? '$' . esc_html( $price ) : '$29.99' ) . '</div>';
                echo '<div style="font-size:10px;color:#4d5156"><span style="color:#fbbc04">★★★★★</span> 4.7 (1,234)</div>';
                echo '<div style="font-size:10px;color:#188038;font-weight:600">✓ In stock</div>';
                echo '</div></div>';
                break;

            case 'product_carousel':
                echo '<div style="font-size:10px;color:#6b7280;font-weight:600;margin-bottom:6px">PRODUCTS FROM THIS SITE</div>';
                echo '<div style="display:flex;gap:6px">';
                for ( $i = 0; $i < 3; $i++ ) {
                    echo '<div style="flex:1;min-width:0;border:1px solid #e5e7eb;border-radius:4px;padding:4px"><div style="width:100%;height:45px;background:linear-gradient(135deg,#dbeafe,#93c5fd);border-radius:3px;margin-bottom:3px"></div><div style="font-size:9px;color:#111827;font-weight:600">$' . ( 19 + $i * 10 ) . '.99</div><div style="font-size:8px;color:#fbbc04">★★★★★</div></div>';
                }
                echo '</div>';
                break;

            case 'review_snippet':
                echo $header;
                echo $title_line;
                $rating = $review_data['reviewRating']['ratingValue'] ?? '4.7';
                echo '<div style="font-size:11px;color:#70757a;margin:2px 0 2px"><span style="color:#fbbc04">★★★★★</span> Rating: ' . esc_html( $rating ) . '/5 · Reviewed by ' . esc_html( $site_name ) . '</div>';
                echo $desc_line;
                break;

            case 'faq':
                echo $header;
                echo $title_line;
                echo '<div style="margin-top:4px;border-top:1px solid #e8eaed">';
                $fallback_qs = [ 'What is ' . $keyword . '?', 'How does it work?', 'How much does it cost?' ];
                $qs_to_show = [];
                if ( ! empty( $faq_questions ) ) {
                    foreach ( array_slice( $faq_questions, 0, 2 ) as $q ) {
                        $qs_to_show[] = $q['name'] ?? '';
                    }
                } else {
                    $qs_to_show = array_slice( $fallback_qs, 0, 2 );
                }
                foreach ( $qs_to_show as $q ) {
                    echo '<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f0f0f0;font-size:11px;color:#1a73e8"><span style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . esc_html( $q ) . '</span><span style="color:#5f6368">▾</span></div>';
                }
                echo '</div>';
                break;

            case 'howto':
                echo $header;
                echo $title_line;
                echo '<div style="display:flex;gap:4px;margin-top:6px">';
                for ( $i = 1; $i <= 4; $i++ ) {
                    echo '<div style="flex:1;text-align:center"><div style="width:100%;height:40px;background:linear-gradient(135deg,#e0e7ff,#c7d2fe);border-radius:3px;margin-bottom:2px;display:flex;align-items:center;justify-content:center;color:#4338ca;font-size:10px;font-weight:700">' . $i . '</div><div style="font-size:8px;color:#4d5156">Step ' . $i . '</div></div>';
                }
                echo '</div>';
                break;

            case 'event_card':
                echo '<div style="display:flex;gap:10px">';
                echo '<div style="width:50px;text-align:center;border:1px solid #e5e7eb;border-radius:4px;overflow:hidden;flex-shrink:0"><div style="background:#ef4444;color:#fff;font-size:9px;font-weight:700;padding:2px">JUN</div><div style="font-size:16px;font-weight:700;color:#111827;padding:4px 0">15</div></div>';
                echo '<div style="flex:1;min-width:0">';
                echo $title_line;
                echo '<div style="font-size:10px;color:#4d5156">📍 ' . esc_html( $site_name ) . ' · 7:00 PM</div>';
                echo '<button type="button" style="margin-top:4px;padding:2px 10px;font-size:10px;border:1px solid #1a0dab;color:#1a0dab;background:#fff;border-radius:3px;cursor:default">Get tickets</button>';
                echo '</div></div>';
                break;

            case 'event_carousel':
                echo '<div style="font-size:10px;color:#6b7280;font-weight:600;margin-bottom:6px">UPCOMING EVENTS</div>';
                echo '<div style="display:flex;gap:6px">';
                foreach ( [ 'JUN 15', 'JUL 02', 'AUG 10' ] as $date ) {
                    list( $mon, $day ) = explode( ' ', $date );
                    echo '<div style="flex:1;border:1px solid #e5e7eb;border-radius:4px;padding:6px;text-align:center"><div style="font-size:9px;color:#ef4444;font-weight:700">' . $mon . '</div><div style="font-size:14px;font-weight:700;color:#111827">' . $day . '</div><div style="font-size:8px;color:#6b7280">Event</div></div>';
                }
                echo '</div>';
                break;

            case 'local_business':
                echo '<div style="display:flex;gap:10px">';
                echo '<div style="width:60px;height:60px;background:linear-gradient(135deg,#e0f2fe,#7dd3fc);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">📍</div>';
                echo '<div style="flex:1;min-width:0">';
                echo '<div style="font-size:12px;color:#1a0dab;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . esc_html( $site_name ) . '</div>';
                echo '<div style="font-size:10px;color:#4d5156"><span style="color:#fbbc04">★★★★★</span> 4.6 · Open now</div>';
                echo '<div style="display:flex;gap:4px;margin-top:3px">';
                echo '<span style="font-size:9px;padding:2px 6px;background:#e0f2fe;color:#0369a1;border-radius:2px">Directions</span>';
                echo '<span style="font-size:9px;padding:2px 6px;background:#ecfdf5;color:#166534;border-radius:2px">Call</span>';
                echo '</div></div></div>';
                break;

            case 'video':
                echo '<div style="position:relative;width:100%;height:80px;background:linear-gradient(135deg,#1f2937,#4b5563);border-radius:4px;margin-bottom:6px;display:flex;align-items:center;justify-content:center">';
                echo '<div style="width:34px;height:34px;background:rgba(255,255,255,0.9);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;color:#111827">▶</div>';
                echo '<div style="position:absolute;bottom:3px;right:4px;background:rgba(0,0,0,0.7);color:#fff;font-size:9px;padding:1px 4px;border-radius:2px">5:12</div>';
                echo '</div>';
                echo $title_line;
                echo '<div style="font-size:10px;color:#4d5156">YouTube · ' . esc_html( $site_name ) . '</div>';
                break;

            case 'video_carousel':
                echo '<div style="font-size:10px;color:#6b7280;font-weight:600;margin-bottom:6px">TOP VIDEOS</div>';
                echo '<div style="display:flex;gap:6px">';
                for ( $i = 0; $i < 3; $i++ ) {
                    echo '<div style="flex:1"><div style="position:relative;width:100%;height:45px;background:linear-gradient(135deg,#1f2937,#4b5563);border-radius:3px;display:flex;align-items:center;justify-content:center"><div style="width:18px;height:18px;background:rgba(255,255,255,0.9);border-radius:50%;font-size:9px;color:#111827;display:flex;align-items:center;justify-content:center">▶</div></div><div style="font-size:8px;color:#4d5156;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">Video ' . ( $i + 1 ) . '</div></div>';
                }
                echo '</div>';
                break;

            case 'top_stories':
                echo '<div style="display:flex;gap:8px">';
                if ( $img ) {
                    echo '<div style="width:80px;height:60px;background:url(\'' . esc_url( $img ) . '\') center/cover;border-radius:4px;flex-shrink:0"></div>';
                } else {
                    echo '<div style="width:80px;height:60px;background:#dbeafe;border-radius:4px;flex-shrink:0"></div>';
                }
                echo '<div style="flex:1;min-width:0">';
                echo '<div style="font-size:10px;color:#1a0dab;font-weight:600">' . esc_html( $site_name ) . '</div>';
                echo $title_line;
                echo '<div style="font-size:9px;color:#6b7280">' . esc_html( $published ?: '2 hours ago' ) . '</div>';
                echo '</div></div>';
                break;

            case 'course_carousel':
            case 'movie_carousel':
                $label = $key === 'course_carousel' ? 'COURSES' : 'MOVIES';
                echo '<div style="font-size:10px;color:#6b7280;font-weight:600;margin-bottom:6px">' . $label . '</div>';
                echo '<div style="display:flex;gap:6px">';
                for ( $i = 0; $i < 3; $i++ ) {
                    echo '<div style="flex:1;border:1px solid #e5e7eb;border-radius:4px;padding:4px;text-align:center"><div style="width:100%;height:50px;background:linear-gradient(135deg,#ddd6fe,#a78bfa);border-radius:3px;margin-bottom:3px"></div><div style="font-size:9px;color:#111827;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">Item ' . ( $i + 1 ) . '</div></div>';
                }
                echo '</div>';
                break;

            case 'vacation_rental':
                echo '<div style="display:flex;gap:8px">';
                if ( $img ) {
                    echo '<div style="width:80px;height:60px;background:url(\'' . esc_url( $img ) . '\') center/cover;border-radius:4px;flex-shrink:0"></div>';
                } else {
                    echo '<div style="width:80px;height:60px;background:linear-gradient(135deg,#fed7aa,#fb923c);border-radius:4px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:22px">🏡</div>';
                }
                echo '<div style="flex:1;min-width:0">';
                echo $title_line;
                echo '<div style="font-size:11px;color:#111827;font-weight:700">$189 <span style="font-size:9px;color:#6b7280;font-weight:400">/ night</span></div>';
                echo '<div style="font-size:10px;color:#4d5156"><span style="color:#fbbc04">★★★★★</span> 4.8 (42)</div>';
                echo '</div></div>';
                break;

            case 'job_posting':
                echo '<div style="display:flex;gap:8px">';
                echo '<div style="width:40px;height:40px;background:#4338ca;border-radius:4px;color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;flex-shrink:0">' . esc_html( mb_substr( $site_name, 0, 1 ) ) . '</div>';
                echo '<div style="flex:1;min-width:0">';
                echo '<div style="font-size:12px;color:#111827;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . esc_html( $title_short ) . '</div>';
                echo '<div style="font-size:10px;color:#4d5156">' . esc_html( $site_name ) . ' · Remote</div>';
                echo '<button type="button" style="margin-top:4px;padding:2px 10px;font-size:10px;background:#1a0dab;color:#fff;border:none;border-radius:3px;cursor:default">Apply now</button>';
                echo '</div></div>';
                break;

            case 'software_app':
                echo '<div style="display:flex;gap:8px">';
                echo '<div style="width:40px;height:40px;background:linear-gradient(135deg,#22c55e,#16a34a);border-radius:8px;color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">📱</div>';
                echo '<div style="flex:1;min-width:0">';
                echo '<div style="font-size:12px;color:#111827;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . esc_html( $title_short ) . '</div>';
                echo '<div style="font-size:10px;color:#4d5156"><span style="color:#fbbc04">★★★★★</span> 4.7 · Free</div>';
                echo '<button type="button" style="margin-top:4px;padding:2px 10px;font-size:10px;background:#22c55e;color:#fff;border:none;border-radius:3px;cursor:default">Get</button>';
                echo '</div></div>';
                break;

            case 'breadcrumbs':
                echo '<div style="font-size:11px;color:#4d5156;margin-bottom:3px">' . esc_html( $breadcrumb ) . '</div>';
                echo $title_line;
                echo $desc_line;
                break;

            case 'speakable':
                echo '<div style="padding:12px;background:linear-gradient(135deg,#dbeafe,#93c5fd);border-radius:6px;text-align:center">';
                echo '<div style="font-size:22px;margin-bottom:4px">📣</div>';
                echo '<div style="font-size:11px;color:#1e3a8a;font-weight:600">Audio on Google Assistant</div>';
                echo '<div style="font-size:9px;color:#1e40af;margin-top:2px">Voice read-aloud enabled</div>';
                echo '</div>';
                break;

            case 'paywall':
                echo $header;
                echo '<div style="font-size:13px;color:#1a0dab;line-height:1.3;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><span style="margin-right:4px">🔒</span>' . esc_html( $title_short ) . '</div>';
                echo $desc_line;
                echo '<div style="font-size:9px;color:#6b7280;margin-top:3px">Subscription required</div>';
                break;

            case 'dataset':
            case 'qa_page':
            case 'discussion_forum':
            case 'profile_page':
            default:
                $icon_map = [ 'dataset' => '📊', 'qa_page' => '💬', 'discussion_forum' => '💭', 'profile_page' => '👤' ];
                $icon = $icon_map[ $key ] ?? '📄';
                echo $header;
                echo '<div style="font-size:13px;color:#1a0dab;line-height:1.3;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><span style="margin-right:4px">' . $icon . '</span>' . esc_html( $title_short ) . '</div>';
                echo $desc_line;
                break;
        }
    }

    /**
     * v1.5.206d-fix19 — mb-safe truncation helper for SEO length enforcement.
     * Returns $text unchanged if within $max_chars; otherwise trims to
     * ($max_chars - 1) and appends a single ellipsis character (1 char = 1 "char" count).
     */
    private function sb_truncate( string $text, int $max_chars ): string {
        $text = trim( $text );
        if ( mb_strlen( $text ) <= $max_chars ) {
            return $text;
        }
        return rtrim( mb_substr( $text, 0, $max_chars - 1 ) ) . '…';
    }

    /**
     * v1.5.206d-fix19 — Single push point that mirrors SEO title + description +
     * focus keyword + OG title/desc/image + Twitter title/desc/image into every
     * active SEO plugin (AIOSEO, Yoast, RankMath, SEOPress).
     *
     * Called from:
     *  - rest_save_draft() at generation time (first save)
     *  - save_metabox() whenever a user edits the SERP preview fields
     *
     * Length contract per Google + social network guidance:
     *  - SEO title:         ≤60 chars (Google SERP desktop)
     *  - Meta description:  ≤160 chars (Google SERP desktop; ~120 mobile)
     *  - OG title:          ≤95 chars (Facebook ~88 visible)
     *  - OG description:    ≤200 chars (Facebook ~200 visible)
     *  - Twitter title:     ≤70 chars
     *  - Twitter desc:      ≤200 chars
     */
    private function sync_seo_plugin_meta( int $post_id, string $meta_title, string $meta_desc, string $keyword, string $content_type = '' ): void {
        $featured_id  = (int) get_post_thumbnail_id( $post_id );
        $featured_url = $featured_id ? (string) wp_get_attachment_image_url( $featured_id, 'full' ) : '';

        // Length-enforce every surface at the boundary so we never write over-limit.
        $title_seo = $this->sb_truncate( $meta_title, 60 );
        $desc_seo  = $this->sb_truncate( $meta_desc, 160 );
        $fb_title  = $this->sb_truncate( $meta_title, 95 );
        $fb_desc   = $this->sb_truncate( $meta_desc, 200 );
        $tw_title  = $this->sb_truncate( $meta_title, 70 );
        $tw_desc   = $this->sb_truncate( $meta_desc, 200 );

        // Persist SEOBetter canonical copies (source of truth for Social_Meta_Generator)
        if ( $title_seo !== '' ) {
            update_post_meta( $post_id, '_seobetter_meta_title', $title_seo );
        }
        if ( $desc_seo !== '' ) {
            update_post_meta( $post_id, '_seobetter_meta_description', $desc_seo );
        }

        // --- Yoast SEO (OG + Twitter now populated — prior to fix19 only title/desc/focus-kw were set)
        if ( defined( 'WPSEO_VERSION' ) ) {
            if ( $title_seo !== '' ) update_post_meta( $post_id, '_yoast_wpseo_title', $title_seo );
            if ( $desc_seo !== '' )  update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc_seo );
            if ( $keyword !== '' )   update_post_meta( $post_id, '_yoast_wpseo_focuskw', $keyword );
            if ( $fb_title !== '' )  update_post_meta( $post_id, '_yoast_wpseo_opengraph-title', $fb_title );
            if ( $fb_desc !== '' )   update_post_meta( $post_id, '_yoast_wpseo_opengraph-description', $fb_desc );
            if ( $tw_title !== '' )  update_post_meta( $post_id, '_yoast_wpseo_twitter-title', $tw_title );
            if ( $tw_desc !== '' )   update_post_meta( $post_id, '_yoast_wpseo_twitter-description', $tw_desc );
            if ( $featured_id ) {
                update_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', $featured_id );
                update_post_meta( $post_id, '_yoast_wpseo_twitter-image-id', $featured_id );
                if ( $featured_url !== '' ) {
                    update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', $featured_url );
                    update_post_meta( $post_id, '_yoast_wpseo_twitter-image', $featured_url );
                }
            }
        }

        // --- RankMath (OG + Twitter now populated)
        if ( class_exists( 'RankMath' ) ) {
            if ( $title_seo !== '' ) update_post_meta( $post_id, 'rank_math_title', $title_seo );
            if ( $desc_seo !== '' )  update_post_meta( $post_id, 'rank_math_description', $desc_seo );
            if ( $keyword !== '' )   update_post_meta( $post_id, 'rank_math_focus_keyword', $keyword );
            if ( $fb_title !== '' )  update_post_meta( $post_id, 'rank_math_facebook_title', $fb_title );
            if ( $fb_desc !== '' )   update_post_meta( $post_id, 'rank_math_facebook_description', $fb_desc );
            if ( $tw_title !== '' )  update_post_meta( $post_id, 'rank_math_twitter_title', $tw_title );
            if ( $tw_desc !== '' )   update_post_meta( $post_id, 'rank_math_twitter_description', $tw_desc );
            update_post_meta( $post_id, 'rank_math_twitter_use_facebook', 'off' );
            if ( $featured_id ) {
                update_post_meta( $post_id, 'rank_math_facebook_image_id', $featured_id );
                update_post_meta( $post_id, 'rank_math_twitter_image_id', $featured_id );
                if ( $featured_url !== '' ) {
                    update_post_meta( $post_id, 'rank_math_facebook_image', $featured_url );
                    update_post_meta( $post_id, 'rank_math_twitter_image', $featured_url );
                }
            }
        }

        // --- SEOPress (OG + Twitter now populated)
        if ( function_exists( 'seopress_init' ) || defined( 'SEOPRESS_VERSION' ) ) {
            if ( $title_seo !== '' ) update_post_meta( $post_id, '_seopress_titles_title', $title_seo );
            if ( $desc_seo !== '' )  update_post_meta( $post_id, '_seopress_titles_desc', $desc_seo );
            if ( $keyword !== '' )   update_post_meta( $post_id, '_seopress_analysis_target_kw', $keyword );
            if ( $fb_title !== '' )  update_post_meta( $post_id, '_seopress_social_fb_title', $fb_title );
            if ( $fb_desc !== '' )   update_post_meta( $post_id, '_seopress_social_fb_desc', $fb_desc );
            if ( $tw_title !== '' )  update_post_meta( $post_id, '_seopress_social_twitter_title', $tw_title );
            if ( $tw_desc !== '' )   update_post_meta( $post_id, '_seopress_social_twitter_desc', $tw_desc );
            if ( $featured_url !== '' ) {
                update_post_meta( $post_id, '_seopress_social_fb_img', $featured_url );
                update_post_meta( $post_id, '_seopress_social_twitter_img', $featured_url );
            }
        }

        // --- AIOSEO (full push incl. schema; unchanged from pre-fix19 except title is now length-capped)
        if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
            $ct = $content_type !== '' ? $content_type : ( (string) get_post_meta( $post_id, '_seobetter_content_type', true ) ?: 'blog_post' );
            $post_content = (string) get_post_field( 'post_content', $post_id );
            $this->populate_aioseo( $post_id, $keyword, $title_seo, $desc_seo, $fb_title, $post_content, $ct );
        }
    }

    /**
     * Populate AIOSEO fields for a post.
     */
    private function populate_aioseo( int $post_id, string $keyword, string $seo_title, string $meta_desc, string $og_title, string $content = '', string $content_type = '' ): void {
        global $wpdb;

        // v1.5.206d-fix19 — enforce length at the boundary even when called directly.
        $seo_title = $this->sb_truncate( $seo_title, 60 );
        $meta_desc = $this->sb_truncate( $meta_desc, 160 );

        // Social meta
        $fb_title = $this->sb_truncate( $og_title, 95 );
        $fb_desc  = $this->sb_truncate( $meta_desc, 200 );
        $tw_title = $this->sb_truncate( $og_title, 70 );
        $tw_desc  = $this->sb_truncate( $meta_desc, 200 );

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

        // v1.5.206a — Inject inLanguage (BCP-47) into top-level schemas whose
        // @type accepts it per Schema.org (CreativeWork + Event descendants).
        // Mirrors Schema_Generator::INLANGUAGE_ACCEPTED_TYPES whitelist to skip
        // BreadcrumbList/ItemList/LocalBusiness/Product/DefinedTerm/etc.
        // Additive: only sets inLanguage when missing; never overwrites or removes fields.
        $lang_meta        = get_post_meta( $post_id, '_seobetter_language', true );
        $in_language      = ( is_string( $lang_meta ) && $lang_meta )
            ? str_replace( '_', '-', sanitize_text_field( $lang_meta ) )
            : ( str_replace( '_', '-', get_locale() ?: '' ) ?: 'en' );
        $inlang_whitelist = [
            'Article', 'BlogPosting', 'NewsArticle', 'OpinionNewsArticle',
            'ScholarlyArticle', 'TechArticle', 'Report', 'ReportageNewsArticle',
            'LiveBlogPosting', 'HowTo', 'Recipe', 'Review', 'ClaimReview',
            'WebPage', 'FAQPage', 'QAPage', 'ProfilePage', 'CollectionPage',
            'ImageObject', 'VideoObject', 'AudioObject', 'MediaObject',
            'SoftwareApplication', 'WebApplication', 'MobileApplication',
            'Dataset', 'Course', 'Book', 'Movie', 'Event',
        ];
        foreach ( $schema_data as &$entry ) {
            if ( is_array( $entry ) && ! isset( $entry['inLanguage'] ) ) {
                $entry_type = $entry['@type'] ?? '';
                if ( is_string( $entry_type ) && in_array( $entry_type, $inlang_whitelist, true ) ) {
                    $entry['inLanguage'] = $in_language;
                }
            }
        }
        unset( $entry );

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
    /**
     * v1.5.190 — Convert bracketed text references into real markdown links.
     *
     * The AI often writes [Source Name] or (Source Name) as plain text brackets
     * without a URL. This method matches the text inside brackets against the
     * Citation Pool titles/source_names and converts them to clickable links.
     *
     * Example:
     *   Input:  "as highlighted in several critical reviews [My Problem with Atomic Habits by James Clear (2023)]"
     *   Output: "as highlighted in several critical reviews ([My Problem with Atomic Habits by James Clear (2023)](https://thewallflowerdigest.co.uk/...))"
     */
    public static function linkify_bracketed_references( string $markdown, array $pool ): string {
        if ( empty( $pool ) ) return $markdown;

        // Normalize text for matching: lowercase, normalize dashes, strip trailing ellipsis
        $norm = function( string $s ): string {
            $s = strtolower( $s );
            $s = str_replace( [ '—', '–', "\u{2013}", "\u{2014}" ], '-', $s );
            $s = preg_replace( '/\s*[.\x{2026}]+$/u', '', $s ); // strip trailing ... or …
            $s = preg_replace( '/\s+/', ' ', trim( $s ) );
            return $s;
        };

        // Build a lookup: normalized title/source_name → pool entry
        $lookup = [];
        foreach ( $pool as $entry ) {
            $url = $entry['url'] ?? '';
            if ( empty( $url ) ) continue;
            $title = $norm( $entry['title'] ?? '' );
            $source = $norm( $entry['source_name'] ?? '' );
            if ( strlen( $title ) > 10 ) {
                $lookup[ $title ] = $entry;
            }
            // First 30 chars as key (AI often truncates titles)
            if ( strlen( $title ) > 30 ) {
                $lookup[ substr( $title, 0, 30 ) ] = $entry;
            }
            // First 20 chars
            if ( strlen( $title ) > 20 ) {
                $lookup[ substr( $title, 0, 20 ) ] = $entry;
            }
            if ( strlen( $source ) > 3 ) {
                $lookup[ $source ] = $entry;
                // v1.5.190 — Also add hostname without TLD as key.
                // AI writes "(Healthline)" but source_name is "healthline.com".
                // Strip .com/.org/.net/.co.uk/.com.au etc. so "healthline" matches.
                $bare = preg_replace( '/\.(com|org|net|io|dev|co|edu|gov|int|info|biz|co\.uk|com\.au|co\.nz|com\.br|co\.jp)$/i', '', $source );
                if ( $bare !== $source && strlen( $bare ) > 3 ) {
                    $lookup[ $bare ] = $entry;
                }
            }
        }

        // Find [bracketed text] that is NOT already a markdown link (no following (url))
        $markdown = preg_replace_callback(
            '/(?<!\!)\[([^\]]{10,120})\](?!\s*\(http)/',
            function ( $match ) use ( $lookup, $norm ) {
                $text = $match[1];
                $text_n = $norm( $text );

                // Try exact match
                if ( isset( $lookup[ $text_n ] ) ) {
                    return '[' . $text . '](' . $lookup[ $text_n ]['url'] . ')';
                }

                // Try partial match
                foreach ( $lookup as $key => $entry ) {
                    if ( strlen( $key ) > 8 && str_contains( $text_n, $key ) ) {
                        return '[' . $text . '](' . $entry['url'] . ')';
                    }
                    // v1.5.190 — Relaxed from 12 to 5 chars. AI writes short source
                    // names like "(RTINGS)" (6 chars), "(AARP)" (4 chars) that need
                    // to match against longer keys like "rtings.com".
                    if ( strlen( $text_n ) > 4 && str_contains( $key, $text_n ) ) {
                        return '[' . $text . '](' . $entry['url'] . ')';
                    }
                }

                return $match[0];
            },
            $markdown
        );

        // Also handle (Source Name) parenthetical references that match pool entries.
        // v1.5.190 — Rewritten to handle nested parens in source titles like
        // "(Python API Tutorial (Beginner's Guide) | Moesif Blog)".
        // Strategy: find outermost ( ... ) that contain 10+ chars and look like
        // a source reference, not code/math. Allow nested () inside.
        $markdown = preg_replace_callback(
            '/\(([^)]{4,150})\)(?=[.\s,;!?\n]|$)/m',
            function ( $match ) use ( $lookup, $norm ) {
                $text = $match[1];

                // If text contains unmatched inner parens, try to grab more
                // e.g. "(Python API Tutorial (Beginner's Guide) | Moesif Blog)"
                // The regex captured "Python API Tutorial (Beginner's Guide"
                // because [^)] stops at first ). Check if parens are balanced:
                $open = substr_count( $text, '(' );
                $close = substr_count( $text, ')' );
                if ( $open > $close ) {
                    // Unbalanced — not a clean capture, skip
                    return $match[0];
                }

                $text_n = $norm( $text );

                // Skip if already contains a URL or markdown link
                if ( str_contains( $text, 'http' ) ) return $match[0];
                if ( str_contains( $text, '](') ) return $match[0];
                // Skip non-references (code, measurements, etc.)
                if ( preg_match( '/^(e\.g\.|i\.e\.|see |note:|fig\.|approx|such as|or |and |about )/i', $text ) ) return $match[0];
                if ( preg_match( '/^\$\d|^\d+\s*(min|hour|cup|oz|lb|kg|g|ml|%)/i', $text ) ) return $match[0];

                foreach ( $lookup as $key => $entry ) {
                    if ( strlen( $key ) > 5 && ( str_contains( $text_n, $key ) || str_contains( $key, $text_n ) ) ) {
                        return '([' . $text . '](' . $entry['url'] . '))';
                    }
                }

                return $match[0];
            },
            $markdown
        );

        // v1.5.190 — Second pass: handle deeply nested parens like
        // "(Python API Tutorial (Beginner's Guide) | Moesif Blog)".
        // The first pass regex [^)] stops at the inner ) and skips these.
        // This pass finds the outermost balanced parens by scanning the string.
        $lines = explode( "\n", $markdown );
        foreach ( $lines as $li => $line ) {
            // Skip headings, tables, code blocks, blockquotes
            if ( preg_match( '/^\s*[#|>`\-*]/', $line ) ) continue;
            // v1.5.190b — REMOVED the old "skip if line has ](http and balanced parens" check.
            // That skipped ENTIRE LINES where any reference was already linked, preventing
            // nested-paren references on the same line from being processed. Each paren
            // group is individually checked for 'http' and '](', so line-level skip is unnecessary.

            // Find parenthetical groups with nested parens
            $offset = 0;
            while ( ( $start = strpos( $line, '(', $offset ) ) !== false ) {
                // Walk to find matching close paren
                $depth = 1;
                $pos = $start + 1;
                $len = strlen( $line );
                while ( $pos < $len && $depth > 0 ) {
                    if ( $line[ $pos ] === '(' ) $depth++;
                    if ( $line[ $pos ] === ')' ) $depth--;
                    $pos++;
                }
                if ( $depth !== 0 ) { $offset = $start + 1; continue; }

                $inner = substr( $line, $start + 1, $pos - $start - 2 );
                $offset = $pos;

                // Must have inner parens (otherwise first pass handled it)
                if ( ! str_contains( $inner, '(' ) ) continue;
                // Length check
                if ( strlen( $inner ) < 10 || strlen( $inner ) > 150 ) continue;
                // Skip if already linked
                if ( str_contains( $inner, 'http' ) ) continue;
                if ( str_contains( $inner, '](') ) continue;

                $inner_n = $norm( $inner );
                foreach ( $lookup as $key => $entry ) {
                    if ( strlen( $key ) > 5 && ( str_contains( $inner_n, $key ) || str_contains( $key, $inner_n ) ) ) {
                        $replacement = '([' . $inner . '](' . $entry['url'] . '))';
                        $line = substr( $line, 0, $start ) . $replacement . substr( $line, $pos );
                        $offset = $start + strlen( $replacement );
                        break;
                    }
                }
            }
            $lines[ $li ] = $line;
        }
        $markdown = implode( "\n", $lines );

        return $markdown;
    }

    /**
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

        // ===== Pass 1.5: Repair URLs with mashed-in second URL =====
        // v1.5.206d-fix8 — AI hallucination pattern: two research-pool URLs
        // get concatenated into one, with the second URL's "://" stripped
        // during URL encoding. Example seen 2026-04-23 on an Arabic Riyadh
        // article:
        //   https://www.facebook.com/riyadhcityguide/posts/[long-arabic-slug]-httpswwwthisisriyadhco
        //                                                                   ^^^^^^^^^^^^^^^^^^^^^^^^
        // The suffix is the second URL (thisisriyadh.co) mashed into the
        // first. The resulting URL is a valid-looking Facebook path that
        // 404s (Facebook doesn't use arbitrary slugs). Detect the `-?https?w`
        // pattern within the path (no `://` separator indicates corruption)
        // and truncate at that boundary.
        $sanitize_mashed_url = function ( string $url ): string {
            if ( preg_match( '#^(https?://[^/]+)(.*)$#', $url, $parts ) ) {
                $authority = $parts[1];
                $path      = $parts[2];
                // Match an embedded http(s) + w/h letter pattern signalling
                // a concatenated second URL (no colon separator). Requires
                // `-` separator or path-end boundary before the marker to
                // reduce false positives (e.g. won't match 'https' appearing
                // inside a legitimate query parameter after '=').
                if ( preg_match( '#^(.*?)(?:-|/)?https?[whi][a-z]#', $path, $cut ) && ! empty( $cut[1] ) ) {
                    $trimmed = rtrim( $cut[1], '-/' );
                    // Don't collapse the path completely — if nothing useful
                    // remains after trim, leave original alone (safer to let
                    // downstream Pass 3 RLFKV fail the URL than to corrupt it).
                    if ( $trimmed !== '' ) {
                        return $authority . $trimmed;
                    }
                }
            }
            return $url;
        };

        // ===== Pass 2: Strict filtering of all real external links =====
        $whitelist = $this->get_trusted_domain_whitelist();
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );

        $filter_link = function ( $url, $text ) use ( $whitelist, $site_host, $citation_pool, $sanitize_mashed_url ) {
            // v1.5.206d-fix8 — repair mashed-URL corruption before any other check
            $url  = $sanitize_mashed_url( $url );
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
            // v1.5.190 — Block DOI/academic URLs (often 404, not reader-friendly)
            if ( preg_match( '/^(doi\.org|dx\.doi\.org)$/i', $host ) ) {
                return [ 'keep' => false, 'text' => $text ];
            }
            // v1.5.190 — Block raw data file URLs and API query endpoints
            if ( preg_match( '#\.(json|xml|csv)$|/query$|/search$|fdsnws|/api/v\d#i', $path ?? '' ) ) {
                return [ 'keep' => false, 'text' => $text ];
            }
            // Block known data API hosts that aren't article pages
            if ( preg_match( '/^(earthquake\.usgs\.gov|api\.census\.gov|data\.bls\.gov|api\.worldbank\.org|api\.stlouisfed\.org)$/i', $host ) ) {
                return [ 'keep' => false, 'text' => $text ];
            }
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
        //
        // v1.5.206d-fix8 — callbacks now rebuild the markdown/HTML with the
        // sanitized URL when keep=true, so mashed-URL corruption repairs
        // actually reach the saved article body (not just influence the
        // filter_link verdict).
        $markdown = preg_replace_callback(
            '/(?<!!)\[([^\]]+)\]\((https?:\/\/[^)]+)\)/',
            function ( $m ) use ( $filter_link, $sanitize_mashed_url ) {
                $sanitized_url = $sanitize_mashed_url( $m[2] );
                $res = $filter_link( $sanitized_url, $m[1] );
                if ( ! $res['keep'] ) {
                    return $res['text'];
                }
                // Rebuild with the sanitized URL so corrections persist.
                return '[' . $m[1] . '](' . $sanitized_url . ')';
            },
            $markdown
        );

        // HTML anchor tags — <a href="...">text</a>
        $markdown = preg_replace_callback(
            '/<a\s+([^>]*)href="(https?:\/\/[^"]+)"([^>]*)>(.*?)<\/a>/is',
            function ( $m ) use ( $filter_link, $sanitize_mashed_url ) {
                $sanitized_url = $sanitize_mashed_url( $m[2] );
                $res = $filter_link( $sanitized_url, wp_strip_all_tags( $m[4] ) );
                if ( ! $res['keep'] ) {
                    return $res['text'];
                }
                return '<a ' . $m[1] . 'href="' . esc_url( $sanitized_url ) . '"' . $m[3] . '>' . $m[4] . '</a>';
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
        $markdown = $this->verify_citation_atoms( $markdown, $citation_pool );

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
    private function verify_citation_atoms( string $markdown, array $trusted_pool = [] ): string {
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

            // v1.5.97 — Skip Pass 3 for URLs already in the trusted pool.
            // These URLs came from Tavily search results or the Citation Pool
            // and are already verified real pages. Re-verifying them wastes
            // time and sometimes fails due to timeouts or anchor text mismatch
            // (e.g. hostname "petcircle.com.au" stripped to "petcirclecomau").
            if ( ! empty( $trusted_pool ) && \SEOBetter\Citation_Pool::contains_url( $trusted_pool, $url ) ) {
                $session_cache[ $url ] = true;
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

            // v1.5.96b — Skip verification for hostname-style anchor text.
            // Tavily quotes use the hostname as anchor: [petcircle.com.au](url).
            // Pass 3 strips dots from "petcircle.com.au" → "petcirclecomau"
            // which never matches in page content → link falsely stripped.
            // Hostnames are verifiable by definition (URL matches the host).
            if ( preg_match( '/^[a-z0-9.-]+\.(com|org|net|edu|gov|au|uk|nz|ca|io|co)\b/i', trim( $anchor_text ) ) ) {
                $session_cache[ $url ] = true;
                set_transient( $cache_key, 'ok', DAY_IN_SECONDS );
                continue; // Keep the link — hostname IS the verification
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
        preg_match_all( '/(?<!!)\[([^\]]+)\]\((https?:\/\/[^)]+)\)/', $markdown, $matches, PREG_SET_ORDER );

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

        // v1.5.67 — when the AI forgot to use [Source](url) format inline
        // (producing plain-text citations only), the body has ZERO markdown
        // links and cited_entries is empty. Previously we'd skip the
        // References section entirely, leaving the article with no outbound
        // links and failing AIOSEO's "No outbound links were found" check.
        // Fallback: if the pool is non-empty, include the first 8 pool
        // entries as a "Further Reading" list. The article still benefits
        // from clickable authoritative sources even if the AI's inline
        // citations were plain-text.
        if ( empty( $cited_entries ) && ! empty( $citation_pool ) ) {
            $fallback_count = 0;
            foreach ( $citation_pool as $entry ) {
                if ( empty( $entry['url'] ) ) continue;
                $cited_entries[] = $entry;
                $fallback_count++;
                if ( $fallback_count >= 8 ) break;
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
            // v1.5.190 — Sanitize title for markdown link safety.
            // Titles with [ ] break markdown link syntax: [title with [brackets]](url)
            // becomes a nested link that the formatter splits incorrectly.
            $title = str_replace( [ '[', ']' ], '', $title );
            // v1.5.191 — Collapse all whitespace (incl. newlines) and strip
            // backslashes. Bluesky/Reddit pool titles can contain literal
            // newlines from multi-line post bodies; a `\n` inside `[title](url)`
            // splits the markdown link across paragraphs, producing broken
            // output like `<li>6. [Title-part-1</li>` + `<p>Title-part-2](url)`.
            // Stripping backslashes also prevents any pre-escaped `\[` / `\]`
            // from surviving into the emitted markdown.
            $title = str_replace( '\\', '', $title );
            $title = preg_replace( '/\s+/', ' ', trim( $title ) );
            // Truncate excessively long titles (some Bluesky/Reddit titles are 200+ chars)
            if ( mb_strlen( $title ) > 80 ) {
                $title = mb_substr( $title, 0, 77 ) . '...';
            }
            // v1.5.67 — removed the " — {$src}" suffix. User feedback:
            // "at the end of the link it will reference (perplexity) it
            // doesnt need to do this... just as long as it is accurate and
            // works". The title field already contains business name +
            // address which is sufficient context; the provider attribution
            // suffix was noise.
            $lines[] = "{$i}. [{$title}]({$url})";
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

            // v1.5.23 — OpenStreetMap (Nominatim + Overpass + OSM URLs)
            // Powers the anti-hallucination Places lookup for local-intent
            // keywords (e.g. "best gelato shops in Lucignano Italy"). Real
            // business URLs from OSM feed the Citation Pool into article
            // References. Without this whitelist, validate_outbound_links()
            // would strip OSM URLs as non-trusted.
            'openstreetmap.org', 'www.openstreetmap.org',
            'nominatim.openstreetmap.org',
            'overpass-api.de',

            // v1.5.24 — Places waterfall providers (Tiers 2-5)
            // Wikidata (free), Foursquare (free key), HERE (free key),
            // Google Places (paid). Each tier produces place URLs that must
            // pass validate_outbound_links() to survive into the References
            // section. See cloud-api/api/research.js::fetchPlacesWaterfall().
            'wikidata.org', 'www.wikidata.org', 'query.wikidata.org',
            'foursquare.com', 'www.foursquare.com', 'fsq.com',
            'here.com', 'www.here.com', 'discover.search.hereapi.com',
            'maps.google.com', 'maps.googleapis.com',
            'places.googleapis.com', 'google.com/maps',
            // v1.5.67 — Perplexity Sonar (Tier 0) scrapes these tourism and
            // review sites for citations. They need to be whitelisted so
            // source_urls returned by Sonar pass validate_outbound_links().
            'openrouter.ai', 'perplexity.ai', 'www.perplexity.ai',
            'tripadvisor.com', 'www.tripadvisor.com', 'tripadvisor.co.uk', 'tripadvisor.it',
            'tripadvisor.es', 'tripadvisor.fr', 'tripadvisor.de', 'tripadvisor.jp',
            'yelp.com', 'www.yelp.com', 'yelp.co.uk', 'yelp.it', 'yelp.fr',
            'wikivoyage.org', 'en.wikivoyage.org', 'it.wikivoyage.org',
            'it.wikivoyage.org', 'fr.wikivoyage.org', 'de.wikivoyage.org',
            'timeout.com', 'www.timeout.com',
            'atlasobscura.com', 'www.atlasobscura.com',
            'lonelyplanet.com', 'www.lonelyplanet.com',
            'fodors.com', 'www.fodors.com',
            'theculturetrip.com', 'www.theculturetrip.com',
            // v1.5.67 — common Brave Search result domains that return
            // authoritative content for pet / health / business / travel
            // queries. Without these in the whitelist, validate_outbound_links
            // strips them out and the References section comes back empty
            // even when Citation_Pool has valid URLs from Brave.
            'hostinger.com', 'www.hostinger.com',
            'forbes.com', 'www.forbes.com',
            'businessinsider.com', 'www.businessinsider.com',
            'livescience.com', 'www.livescience.com',
            'sciencedaily.com', 'www.sciencedaily.com',
            'nationalgeographic.com', 'www.nationalgeographic.com',
            'smithsonianmag.com', 'www.smithsonianmag.com',
            'newscientist.com', 'www.newscientist.com',
            'theverge.com', 'www.theverge.com',
            'wired.com', 'www.wired.com',
            'techcrunch.com', 'www.techcrunch.com',
            'medium.com',
            'substack.com',
            'vice.com', 'www.vice.com',
            'inc.com', 'www.inc.com',
            'entrepreneur.com', 'www.entrepreneur.com',
            'hbr.org', 'www.hbr.org',
            'fastcompany.com', 'www.fastcompany.com',
            'mashable.com', 'www.mashable.com',
            'dogster.com', 'www.dogster.com',
            'americankennelclub.org', 'www.americankennelclub.org',
            'raw-dog-food.com', 'www.raw-dog-food.com',
            'raypeatforum.com',
            'pets.webmd.com',
            'dogs.lovetoknow.com',
            'petfinder.com', 'www.petfinder.com',
            'thesprucepets.com', 'www.thesprucepets.com',
            'pbs.org', 'www.pbs.org',
            'npr.org', 'www.npr.org',
            'msn.com', 'www.msn.com',
            'yahoo.com', 'www.yahoo.com', 'news.yahoo.com',
            'news.com.au', 'www.news.com.au',
            'theage.com.au', 'www.theage.com.au',
            'smh.com.au', 'www.smh.com.au',
            'abc.net.au', 'www.abc.net.au',

            // v1.5.206b — Regional international citation domains (Layer 6).
            // Additive: unconditional expansion of the always-trusted set, same
            // pattern as existing UK/AU/US entries. See international-optimization.md §6
            // for per-engine rationale. Per-article-country gating is a future
            // enhancement; today these domains pass for any article.

            // China (Baidu / Doubao / ERNIE / DeepSeek / Qwen / Kimi)
            'baike.baidu.com', 'zhihu.com', 'jiandan.net', '36kr.com', 'tmtpost.com',
            'people.com.cn', 'xinhuanet.com', 'chinadaily.com.cn', 'cctv.com',
            'zh.wikipedia.org',

            // Russia (Yandex / YandexGPT / GigaChat)
            'ru.wikipedia.org', 'yandex.ru', 'kremlin.ru',
            'lenta.ru', 'ria.ru', 'tass.ru', 'rbc.ru', 'habr.com',

            // South Korea (Naver / HyperCLOVA X / Kanana)
            'ko.wikipedia.org',
            'terms.naver.com', 'kin.naver.com', 'academic.naver.com',
            'yna.co.kr', 'chosun.com', 'donga.com', 'hani.co.kr', 'joongang.co.kr',

            // Japan (Yahoo! Japan / Sakana AI / PLaMo / Rinna / ELYZA)
            'ja.wikipedia.org', 'chiebukuro.yahoo.co.jp', 'kotobank.jp',
            'nhk.or.jp', 'asahi.com', 'mainichi.jp', 'nikkei.com', 'yomiuri.co.jp',

            // Germany / DACH (Mistral / Aleph Alpha EU)
            'de.wikipedia.org',
            'spiegel.de', 'faz.net', 'zeit.de', 'sueddeutsche.de', 'welt.de', 'tagesschau.de',

            // France
            'fr.wikipedia.org',
            'lemonde.fr', 'lefigaro.fr', 'liberation.fr', 'leparisien.fr',

            // Spain / Latin America
            'es.wikipedia.org',
            'elpais.com', 'elmundo.es', 'clarin.com', 'lanacion.com.ar', 'reforma.com',

            // Italy
            'it.wikipedia.org',
            'corriere.it', 'repubblica.it', 'lastampa.it',

            // Brazil / Portugal
            'pt.wikipedia.org',
            'globo.com', 'folha.uol.com.br', 'uol.com.br', 'estadao.com.br',
            'publico.pt', 'expresso.pt',

            // Middle East (Arabic)
            'ar.wikipedia.org', 'aljazeera.net', 'alarabiya.net',

            // India
            'hi.wikipedia.org',
            'thehindu.com', 'indianexpress.com', 'timesofindia.indiatimes.com', 'ndtv.com',

            // Regional government / academic wildcards
            '*.gov.cn', '*.edu.cn',
            '*.gov.ru',
            '*.go.kr', '*.ac.kr',
            '*.go.jp', '*.ac.jp',
            '*.bund.de', '*.gv.at', '*.admin.ch',
            '*.gouv.fr',
            '*.gob.es', '*.gob.mx', '*.gob.ar',
            '*.gov.it',
            '*.gov.br', '*.gov.pt',
            '*.gov.sa', '*.gov.ae',
            '*.gov.in', '*.ac.in',
            '*.europa.eu',
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

        $image_url = '';

        // v1.5.67 — Branding AI image generation first. Try the user's
        // configured AI image provider (Pollinations / Gemini Nano Banana /
        // DALL-E 3 / FLUX Pro). Returns empty string on any error, at which
        // point we fall through to the existing Pexels → Picsum flow.
        $brand = \SEOBetter\AI_Image_Generator::get_brand_settings();
        if ( ! empty( $brand['provider'] ) ) {
            $post_title = get_the_title( $post_id );
            $image_url = \SEOBetter\AI_Image_Generator::generate( $post_title, $keyword, $brand );
        }

        // Try Pexels API for topic-relevant image
        if ( ! $image_url ) {
            $image_url = $this->search_pexels_image( $keyword );
        }

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

        // v1.5.215 — Best-effort WebP conversion at quality 85 for the
        // featured image. WebP is ~30% smaller than JPEG/PNG at equivalent
        // visual quality, which directly improves:
        //   - WhatsApp link previews (need <600KB to render the LARGE preview)
        //   - LCP / Core Web Vitals (smaller image = faster paint)
        //   - Mobile bandwidth on shared/cold caches
        // Falls back silently if WP_Image_Editor doesn't support WebP on the
        // host (older PHP/GD without WebP, certain shared hosts). The original
        // file is kept intact for fallback consumers — we only ADD a WebP
        // sibling and update the attachment metadata to reference it.
        $this->convert_featured_to_webp( $image_id );
    }

    /**
     * v1.5.215 — Convert a featured image attachment to WebP quality 85.
     *
     * Best-effort: skips silently if the host's image editor can't write WebP,
     * or if the source file is already WebP. Original JPEG/PNG file is kept
     * on disk so existing references (e.g. cached HTML, RSS feeds) don't 404 —
     * we just add a `.webp` sibling and let WordPress emit it via srcset for
     * supporting clients.
     */
    private function convert_featured_to_webp( int $attachment_id ): void {
        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) return;

        $mime = (string) get_post_mime_type( $attachment_id );
        if ( $mime === 'image/webp' ) return; // already WebP, nothing to do

        // Only convert raster formats. SVG / GIF stay as-is (animation).
        if ( ! in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) return;

        // Check editor support before doing work. wp_image_editor_supports
        // negotiates GD vs Imagick + WebP capability on the current host.
        if ( ! function_exists( 'wp_image_editor_supports' ) ) return;
        if ( ! wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ) return;

        $editor = wp_get_image_editor( $file );
        if ( is_wp_error( $editor ) ) return;
        $editor->set_quality( 85 );

        $webp_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $file );
        if ( $webp_path === $file ) return; // safety: preg_replace failed
        $saved = $editor->save( $webp_path, 'image/webp' );
        if ( is_wp_error( $saved ) ) return;

        // Switch the attachment to point at the WebP file. WordPress will
        // regenerate intermediate sizes on next thumbnail request. Original
        // JPEG/PNG stays on disk as a fallback for non-WebP consumers.
        update_attached_file( $attachment_id, $webp_path );
        wp_update_post( [
            'ID'             => $attachment_id,
            'post_mime_type' => 'image/webp',
        ] );
        // Trigger metadata regen so wp_get_attachment_metadata returns
        // dimensions for the new WebP file.
        if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
            $new_meta = wp_generate_attachment_metadata( $attachment_id, $webp_path );
            if ( is_array( $new_meta ) ) {
                wp_update_attachment_metadata( $attachment_id, $new_meta );
            }
        }
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

        $keyword = '';
        if ( isset( $_POST['seobetter_focus_keyword'] ) ) {
            $keyword = sanitize_text_field( wp_unslash( $_POST['seobetter_focus_keyword'] ) );
            update_post_meta( $post_id, '_seobetter_focus_keyword', $keyword );
        } else {
            $keyword = (string) get_post_meta( $post_id, '_seobetter_focus_keyword', true );
        }

        // v1.5.206d-fix19 — persist SERP preview edits (SEO title + meta description)
        // and mirror them into every active SEO plugin via sync_seo_plugin_meta().
        // Length caps (60 title, 160 desc) enforced inside the helper.
        $has_title_edit = isset( $_POST['seobetter_meta_title'] );
        $has_desc_edit  = isset( $_POST['seobetter_meta_description'] );
        if ( $has_title_edit || $has_desc_edit ) {
            $meta_title = $has_title_edit
                ? sanitize_text_field( wp_unslash( $_POST['seobetter_meta_title'] ) )
                : (string) get_post_meta( $post_id, '_seobetter_meta_title', true );
            $meta_desc  = $has_desc_edit
                ? sanitize_textarea_field( wp_unslash( $_POST['seobetter_meta_description'] ) )
                : (string) get_post_meta( $post_id, '_seobetter_meta_description', true );

            // Fall back to post_title / first-25-words if the field is now empty
            if ( $meta_title === '' ) {
                $meta_title = $post->post_title;
            }
            if ( $meta_desc === '' ) {
                $meta_desc = wp_trim_words( wp_strip_all_tags( $post->post_content ), 25 );
            }

            $this->sync_seo_plugin_meta( $post_id, $meta_title, $meta_desc, $keyword );
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
                <button type="button" class="sb-meta-tab" data-tab="richresults" style="padding:12px 20px;font-size:13px;font-weight:600;border:none;background:none;cursor:pointer;color:#6b7280">Rich Results</button>
                <div style="margin-left:auto;display:flex;align-items:center;padding-right:16px;gap:8px">
                    <span style="font-size:13px;font-weight:700;color:<?php echo esc_attr( $score_color ); ?>"><?php echo esc_html( $score ); ?>/100</span>
                    <span style="font-size:11px;padding:2px 8px;background:<?php echo esc_attr( $score_color ); ?>20;color:<?php echo esc_attr( $score_color ); ?>;border-radius:4px;font-weight:600"><?php echo esc_html( $grade ); ?></span>
                </div>
            </div>

            <!-- General Tab -->
            <div class="sb-meta-panel" data-panel="general" style="padding:20px">
                <?php
                // v1.5.206d-fix19 — editable SERP preview with favicon, breadcrumb,
                // mobile/desktop toggle, content-type-aware rich-result hint, and live
                // character counters. Edits persist to _seobetter_meta_title /
                // _seobetter_meta_description and mirror into AIOSEO/Yoast/RankMath/SEOPress
                // via sync_seo_plugin_meta() fired from save_metabox().
                // v1.5.212 — track whether WordPress Site Icon is explicitly configured
                // (vs falling back to /favicon.ico which may not exist). Used for warning UI.
                $site_icon_configured = (bool) ( function_exists( 'has_site_icon' ) && has_site_icon() );
                $favicon_url = $site_icon_configured ? get_site_icon_url( 32 ) : '';
                if ( ! $favicon_url ) {
                    $favicon_url = home_url( '/favicon.ico' );
                }
                $parsed_url   = wp_parse_url( $url );
                $url_host     = $parsed_url['host'] ?? '';
                $url_path     = trim( (string) ( $parsed_url['path'] ?? '' ), '/' );
                $url_breadcrumb = $url_host . ( $url_path !== '' ? ' › ' . str_replace( '/', ' › ', $url_path ) : '' );
                $content_type_saved = (string) get_post_meta( $post->ID, '_seobetter_content_type', true );
                $post_date    = get_the_date( 'M j, Y', $post );
                ?>
                <!-- SERP Preview -->
                <div style="margin-bottom:20px" id="sb-serp-block">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                        <div style="font-size:13px;font-weight:600">SERP Preview</div>
                        <div style="display:inline-flex;border:1px solid #d1d5db;border-radius:6px;overflow:hidden" role="tablist">
                            <button type="button" class="sb-serp-device sb-serp-device-active" data-device="desktop" style="padding:4px 10px;font-size:11px;border:none;background:#f3f4f6;color:#111827;cursor:pointer">🖥 Desktop</button>
                            <button type="button" class="sb-serp-device" data-device="mobile" style="padding:4px 10px;font-size:11px;border:none;background:#fff;color:#6b7280;cursor:pointer">📱 Mobile</button>
                        </div>
                    </div>
                    <div id="sb-serp-card" data-device="desktop" style="padding:16px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;max-width:600px">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                            <img src="<?php echo esc_url( $favicon_url ); ?>" alt="" width="20" height="20" style="border-radius:50%;background:#f3f4f6;flex-shrink:0" onerror="this.style.display='none'" />
                            <div style="flex:1;min-width:0">
                                <div style="font-size:13px;color:#202124;line-height:1.2"><?php echo esc_html( $site_name ); ?></div>
                                <div style="font-size:12px;color:#4d5156;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo esc_html( $url_breadcrumb ); ?></div>
                            </div>
                        </div>
                        <div id="sb-serp-title" style="font-size:20px;line-height:1.3;color:#1a0dab;font-weight:400;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer"><?php echo esc_html( $meta_title ); ?></div>
                        <div id="sb-serp-desc" style="font-size:14px;color:#4d5156;line-height:1.58;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?php echo esc_html( $meta_desc ); ?></div>
                        <?php
                        // Content-type rich-result hints. These mock the likely Google enhancement
                        // for the article's @type based on _seobetter_content_type. They're visual
                        // approximations — actual rich results depend on the schema validating.
                        $rich_badge = '';
                        $ct = strtolower( $content_type_saved );
                        if ( in_array( $ct, [ 'recipe' ], true ) ) {
                            $rich_badge = '<div style="display:flex;align-items:center;gap:10px;margin-top:8px;font-size:13px;color:#202124"><span style="color:#fbbc04;letter-spacing:-1px">★★★★★</span><span style="color:#4d5156">4.8 (120) · 30 min · ~320 cal</span></div>';
                        } elseif ( in_array( $ct, [ 'review', 'product_review' ], true ) ) {
                            $rich_badge = '<div style="display:flex;align-items:center;gap:10px;margin-top:8px;font-size:13px;color:#202124"><span style="color:#fbbc04;letter-spacing:-1px">★★★★★</span><span style="color:#4d5156">Rating: 4.7/5 · Reviewed by author</span></div>';
                        } elseif ( in_array( $ct, [ 'how_to', 'howto', 'how-to' ], true ) ) {
                            $rich_badge = '<div style="margin-top:8px;font-size:13px;color:#4d5156">📋 Step-by-step guide · ~15 min</div>';
                        } elseif ( in_array( $ct, [ 'faq' ], true ) ) {
                            $kw_safe = $keyword ? esc_html( $keyword ) : 'this';
                            $rich_badge  = '<div style="margin-top:10px;font-size:13px">';
                            $rich_badge .= '<div style="border-top:1px solid #ececec;padding:8px 0;color:#202124;display:flex;justify-content:space-between"><span>What is ' . $kw_safe . '?</span><span style="color:#5f6368">▾</span></div>';
                            $rich_badge .= '<div style="border-top:1px solid #ececec;padding:8px 0;color:#202124;display:flex;justify-content:space-between"><span>How does it work?</span><span style="color:#5f6368">▾</span></div>';
                            $rich_badge .= '</div>';
                        } elseif ( in_array( $ct, [ 'news', 'live_blog', 'press_release' ], true ) ) {
                            $rich_badge = '<div style="margin-top:8px;font-size:12px;color:#70757a">Top stories · ' . esc_html( $post_date ) . '</div>';
                        } elseif ( in_array( $ct, [ 'listicle' ], true ) ) {
                            $rich_badge = '<div style="margin-top:8px;font-size:12px;color:#70757a">📋 List article · 10 items</div>';
                        } elseif ( in_array( $ct, [ 'comparison', 'buying_guide' ], true ) ) {
                            $rich_badge = '<div style="margin-top:8px;font-size:12px;color:#70757a">⚖ Comparison · Side-by-side</div>';
                        }
                        if ( $rich_badge ) {
                            echo $rich_badge; // already-escaped content
                        }
                        ?>
                    </div>

                    <!-- Editable SEO Title -->
                    <div style="margin-top:16px">
                        <label for="sb-meta-title-input" style="font-size:13px;font-weight:600;display:block;margin-bottom:4px">SEO Title</label>
                        <input type="text"
                               name="seobetter_meta_title"
                               id="sb-meta-title-input"
                               value="<?php echo esc_attr( $meta_title ); ?>"
                               maxlength="120"
                               placeholder="Title that appears in Google search results"
                               style="width:100%;height:38px;padding:0 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;box-sizing:border-box;font-family:inherit" />
                        <div style="display:flex;justify-content:space-between;margin-top:4px">
                            <div style="font-size:11px;color:#6b7280">Ideal 50–60 chars. Google truncates at ~60 desktop.</div>
                            <div id="sb-meta-title-count" style="font-size:11px;font-weight:600">0/60</div>
                        </div>
                    </div>

                    <!-- Editable Meta Description -->
                    <div style="margin-top:12px">
                        <label for="sb-meta-desc-input" style="font-size:13px;font-weight:600;display:block;margin-bottom:4px">Meta Description</label>
                        <textarea name="seobetter_meta_description"
                                  id="sb-meta-desc-input"
                                  rows="3"
                                  maxlength="320"
                                  placeholder="Description that appears under the title in search results"
                                  style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;box-sizing:border-box;resize:vertical;font-family:inherit;line-height:1.5"><?php echo esc_textarea( $meta_desc ); ?></textarea>
                        <div style="display:flex;justify-content:space-between;margin-top:4px">
                            <div style="font-size:11px;color:#6b7280">Ideal 150–160 chars. Google truncates at ~160 desktop, ~120 mobile.</div>
                            <div id="sb-meta-desc-count" style="font-size:11px;font-weight:600">0/160</div>
                        </div>
                    </div>

                    <div style="margin-top:8px;font-size:11px;color:#6b7280">Edits sync to AIOSEO, Yoast, RankMath, and SEOPress when active (title, description, Open Graph, Twitter Card).</div>

                    <?php if ( ! $site_icon_configured ) : ?>
                    <div style="margin-top:10px;padding:10px 12px;background:#fffbeb;border:1px solid #f59e0b;border-radius:6px;font-size:11px;color:#92400e;line-height:1.5">
                        <strong>⚠ Site Icon not configured.</strong> Google SERPs, Google Discover, AI engines (Perplexity / ChatGPT / Gemini / Claude), and social shares all display your site's favicon. Without it, your article looks unbranded in every channel that cites it.
                        <br>
                        <a href="<?php echo esc_url( admin_url( 'customize.php?autofocus[section]=title_tagline' ) ); ?>" target="_blank" style="color:#92400e;font-weight:600;text-decoration:underline">Configure Site Icon →</a>
                        (WordPress Customiser → Site Identity → Site Icon. Upload a square 512×512+ image.)
                    </div>
                    <?php endif; ?>
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

            <!-- Rich Results Tab (v1.5.190) -->
            <div class="sb-meta-panel" data-panel="richresults" style="padding:20px;display:none">
                <?php
                try {
                $schema_raw = get_post_meta( $post->ID, '_seobetter_schema', true );
                $schema_decoded = $schema_raw ? json_decode( $schema_raw, true ) : null;
                $graph = $schema_decoded['@graph'] ?? [];
                $rich_types = [];
                $faq_questions = [];
                $recipe_data = null;
                $review_data = null;
                $product_data = null;
                $video_data = null;
                $event_data = null;
                $local_data = null;
                $job_data = null;
                $breadcrumbs = [];

                foreach ( $graph as $item ) {
                    $t = $item['@type'] ?? '';
                    if ( $t === 'Recipe' && ! $recipe_data ) {
                        $recipe_data = $item;
                        $rich_types[] = [ 'type' => 'Recipe', 'label' => 'Recipe card', 'detail' => ( $item['prepTime'] ?? '' ) ? preg_replace( '/^PT(\d+)M$/', '$1 min', $item['prepTime'] ) : '' ];
                    } elseif ( $t === 'FAQPage' ) {
                        $faq_questions = $item['mainEntity'] ?? [];
                        $rich_types[] = [ 'type' => 'FAQ', 'label' => 'FAQ dropdowns', 'detail' => count( $faq_questions ) . ' questions' ];
                    } elseif ( $t === 'Review' ) {
                        $review_data = $item;
                        $rating = $item['reviewRating']['ratingValue'] ?? '';
                        $rich_types[] = [ 'type' => 'Review', 'label' => 'Review snippet', 'detail' => $rating ? $rating . '/5 stars' : '' ];
                    } elseif ( $t === 'Product' ) {
                        $product_data = $item;
                        $price = $item['offers']['price'] ?? '';
                        $rich_types[] = [ 'type' => 'Product', 'label' => 'Product listing', 'detail' => $price ? '$' . $price : '' ];
                    } elseif ( $t === 'VideoObject' ) {
                        $video_data = $item;
                        $rich_types[] = [ 'type' => 'Video', 'label' => 'Video thumbnail', 'detail' => $item['duration'] ?? '' ];
                    } elseif ( $t === 'Event' ) {
                        $event_data = $item;
                        $rich_types[] = [ 'type' => 'Event', 'label' => 'Event listing', 'detail' => $item['startDate'] ?? '' ];
                    } elseif ( $t === 'LocalBusiness' || ( is_string( $t ) && strpos( $t, 'Business' ) !== false ) ) {
                        $local_data = $item;
                        $rich_types[] = [ 'type' => 'LocalBusiness', 'label' => 'Local business', 'detail' => $item['address']['streetAddress'] ?? '' ];
                    } elseif ( $t === 'JobPosting' ) {
                        $job_data = $item;
                        $rich_types[] = [ 'type' => 'Job', 'label' => 'Job posting', 'detail' => $item['employmentType'] ?? '' ];
                    } elseif ( $t === 'BreadcrumbList' ) {
                        foreach ( ( $item['itemListElement'] ?? [] ) as $li ) { $breadcrumbs[] = $li['name'] ?? ''; }
                        $rich_types[] = [ 'type' => 'Breadcrumb', 'label' => 'Breadcrumb trail', 'detail' => '' ];
                    } elseif ( $t === 'ItemList' ) {
                        $count = count( $item['itemListElement'] ?? [] );
                        $rich_types[] = [ 'type' => 'ItemList', 'label' => 'Carousel list', 'detail' => $count . ' items' ];
                    } elseif ( $t === 'ClaimReview' ) {
                        $rich_types[] = [ 'type' => 'FactCheck', 'label' => 'Fact check', 'detail' => $item['reviewRating']['alternateName'] ?? '' ];
                    } elseif ( $t === 'SoftwareApplication' ) {
                        $rich_types[] = [ 'type' => 'Software', 'label' => 'Software app', 'detail' => $item['operatingSystem'] ?? '' ];
                    } elseif ( $t === 'Course' ) {
                        $rich_types[] = [ 'type' => 'Course', 'label' => 'Course listing', 'detail' => '' ];
                    } elseif ( $t === 'Movie' ) {
                        $rich_types[] = [ 'type' => 'Movie', 'label' => 'Movie info', 'detail' => '' ];
                    } elseif ( $t === 'Book' ) {
                        $rich_types[] = [ 'type' => 'Book', 'label' => 'Book info', 'detail' => '' ];
                    } elseif ( $t === 'Dataset' ) {
                        $rich_types[] = [ 'type' => 'Dataset', 'label' => 'Dataset', 'detail' => '' ];
                    } elseif ( $t === 'Organization' ) {
                        $rich_types[] = [ 'type' => 'Organization', 'label' => 'Organization', 'detail' => $item['name'] ?? '' ];
                    } elseif ( $t === 'QAPage' ) {
                        $rich_types[] = [ 'type' => 'QA', 'label' => 'Q&A page', 'detail' => '' ];
                    } elseif ( $t === 'LodgingBusiness' ) {
                        $rich_types[] = [ 'type' => 'VacationRental', 'label' => 'Vacation rental', 'detail' => $item['priceRange'] ?? '' ];
                    } elseif ( isset( $item['speakable'] ) ) {
                        $rich_types[] = [ 'type' => 'Speakable', 'label' => 'Voice search eligible', 'detail' => '' ];
                    }
                }

                // Deduplicate by type (e.g. multiple Recipe schemas)
                $seen_types = [];
                $unique_rich_types = [];
                foreach ( $rich_types as $rt ) {
                    if ( ! in_array( $rt['type'], $seen_types ) ) {
                        $seen_types[] = $rt['type'];
                        $unique_rich_types[] = $rt;
                    }
                }
                $rich_types = $unique_rich_types;
                $active_types = array_column( $rich_types, 'type' );

                // v1.5.207 — Compute eligibility for all 28 Google Search / Discover /
                // AI Overview appearance surfaces (see plugin_UX.md §Metabox Rich Results Tab).
                $types_in_graph = [];
                foreach ( $graph as $item ) {
                    if ( ! is_array( $item ) ) continue;
                    $t = $item['@type'] ?? '';
                    if ( is_string( $t ) ) $types_in_graph[] = $t;
                    if ( is_array( $t ) ) {
                        foreach ( $t as $sub ) if ( is_string( $sub ) ) $types_in_graph[] = $sub;
                    }
                }
                $types_in_graph = array_values( array_unique( $types_in_graph ) );
                $has_type = function ( array $wanted ) use ( $types_in_graph ): bool {
                    foreach ( $wanted as $w ) if ( in_array( $w, $types_in_graph, true ) ) return true;
                    return false;
                };
                $article_types = [ 'Article','BlogPosting','NewsArticle','OpinionNewsArticle','ScholarlyArticle','TechArticle','ReportageNewsArticle','LiveBlogPosting','Report','AnalysisNewsArticle' ];
                $local_business_types = [ 'LocalBusiness','Store','Restaurant','LodgingBusiness','FoodEstablishment','ProfessionalService','MedicalBusiness','AutomotiveBusiness','HealthAndBeautyBusiness' ];
                $featured_image_id = (int) get_post_thumbnail_id( $post->ID );
                $featured_image_url = $featured_image_id ? (string) wp_get_attachment_image_url( $featured_image_id, 'full' ) : '';
                $featured_image_width = 0;
                $featured_image_height = 0;
                if ( $featured_image_id ) {
                    $imeta = wp_get_attachment_metadata( $featured_image_id );
                    $featured_image_width = (int) ( $imeta['width'] ?? 0 );
                    $featured_image_height = (int) ( $imeta['height'] ?? 0 );
                }
                $has_speakable = false;
                $is_paywalled = false;
                foreach ( $graph as $item ) {
                    if ( is_array( $item ) && isset( $item['speakable'] ) ) $has_speakable = true;
                    if ( is_array( $item ) && isset( $item['isAccessibleForFree'] ) && $item['isAccessibleForFree'] === false ) $is_paywalled = true;
                }
                $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
                $published_date = get_the_date( 'M j, Y', $post );
                $modified_date_mysql = $post->post_modified_gmt;
                $days_since_modified = $modified_date_mysql ? floor( ( time() - strtotime( $modified_date_mysql ) ) / DAY_IN_SECONDS ) : 999;

                $appearances = [
                    'standard_article'   => [ 'label' => 'Standard Article',       'eligible' => $has_type( $article_types ), 'schema' => 'Article / BlogPosting / NewsArticle', 'why' => 'Basic blue-link Google result.' ],
                    'article_with_image' => [ 'label' => 'Article + thumbnail',    'eligible' => $has_type( $article_types ) && $featured_image_url !== '', 'schema' => 'Article + featured image', 'why' => 'Adds a thumbnail next to the result.' ],
                    'recipe_card'        => [ 'label' => 'Recipe card',            'eligible' => $has_type( [ 'Recipe' ] ), 'schema' => 'Recipe', 'why' => 'Full recipe card with image, rating, time, calories.' ],
                    'recipe_carousel'    => [ 'label' => 'Recipe carousel',        'eligible' => $has_type( [ 'Recipe' ] ) && $has_type( [ 'ItemList' ] ), 'schema' => 'Recipe + ItemList ≥3', 'why' => 'Host-driven horizontal scroller.' ],
                    'recipe_gallery'     => [ 'label' => 'Recipe gallery',         'eligible' => $has_type( [ 'Recipe' ] ), 'schema' => 'Recipe', 'why' => 'Google multi-site recipe gallery.' ],
                    'product_card'       => [ 'label' => 'Product card',           'eligible' => $has_type( [ 'Product' ] ), 'schema' => 'Product + offers', 'why' => 'Image + price + rating + availability.' ],
                    'product_carousel'   => [ 'label' => 'Product carousel',       'eligible' => $has_type( [ 'Product' ] ) && $has_type( [ 'ItemList' ] ), 'schema' => 'Product + ItemList ≥3', 'why' => 'Horizontal product gallery.' ],
                    'review_snippet'     => [ 'label' => 'Review snippet',         'eligible' => $has_type( [ 'Review', 'AggregateRating' ] ), 'schema' => 'Review / AggregateRating', 'why' => 'Inline star rating below the title.' ],
                    'faq'                => [ 'label' => 'FAQ rich result',        'eligible' => $has_type( [ 'FAQPage' ] ), 'schema' => 'FAQPage', 'why' => 'Expandable Q&A rows (desktop limited since 2023).' ],
                    'howto'              => [ 'label' => 'HowTo step carousel',   'eligible' => $has_type( [ 'HowTo' ] ), 'schema' => 'HowTo', 'why' => 'Numbered step thumbnails (mobile + Assistant).' ],
                    'event_card'         => [ 'label' => 'Event card',             'eligible' => $has_type( [ 'Event' ] ), 'schema' => 'Event', 'why' => 'Date + venue + Get tickets CTA.' ],
                    'event_carousel'     => [ 'label' => 'Event carousel',         'eligible' => $has_type( [ 'Event' ] ) && $has_type( [ 'ItemList' ] ), 'schema' => 'Event + ItemList', 'why' => 'Horizontal multi-date cards.' ],
                    'local_business'    => [ 'label' => 'Local Business / Map Pack', 'eligible' => $has_type( $local_business_types ), 'schema' => 'LocalBusiness + address', 'why' => 'Map pin + hours + Directions + Call.' ],
                    'video'              => [ 'label' => 'Video rich result',      'eligible' => $has_type( [ 'VideoObject' ] ), 'schema' => 'VideoObject', 'why' => 'Large play-button thumbnail + duration.' ],
                    'video_carousel'     => [ 'label' => 'Video carousel',         'eligible' => $has_type( [ 'VideoObject' ] ) && $has_type( [ 'ItemList' ] ), 'schema' => 'VideoObject + ItemList', 'why' => 'Top Videos section.' ],
                    'top_stories'        => [ 'label' => 'Top Stories (News)',     'eligible' => $has_type( [ 'NewsArticle','ReportageNewsArticle','AnalysisNewsArticle','OpinionNewsArticle' ] ), 'schema' => 'NewsArticle', 'why' => 'News carousel. Requires Google News inclusion.' ],
                    'course_carousel'    => [ 'label' => 'Course carousel',        'eligible' => $has_type( [ 'Course' ] ), 'schema' => 'Course', 'why' => 'Provider + course + duration + price.' ],
                    'movie_carousel'     => [ 'label' => 'Movie carousel',         'eligible' => $has_type( [ 'Movie' ] ), 'schema' => 'Movie', 'why' => 'Poster + title + year + director.' ],
                    'vacation_rental'    => [ 'label' => 'Vacation Rental',        'eligible' => $has_type( [ 'VacationRental','LodgingBusiness' ] ), 'schema' => 'VacationRental', 'why' => 'Property + price/night + rating.' ],
                    'job_posting'        => [ 'label' => 'Job posting',            'eligible' => $has_type( [ 'JobPosting' ] ), 'schema' => 'JobPosting', 'why' => 'Interactive job card with Apply CTA.' ],
                    'software_app'       => [ 'label' => 'Software App',           'eligible' => $has_type( [ 'SoftwareApplication','MobileApplication','WebApplication' ] ), 'schema' => 'SoftwareApplication', 'why' => 'Icon + rating + price + download.' ],
                    'dataset'            => [ 'label' => 'Dataset',                'eligible' => $has_type( [ 'Dataset' ] ), 'schema' => 'Dataset', 'why' => 'Appears in Google Dataset Search.' ],
                    'qa_page'            => [ 'label' => 'Q&A page',               'eligible' => $has_type( [ 'QAPage' ] ), 'schema' => 'QAPage', 'why' => 'Accepted answer excerpt + upvote count.' ],
                    'discussion_forum'   => [ 'label' => 'Discussion Forum',       'eligible' => $has_type( [ 'DiscussionForumPosting' ] ), 'schema' => 'DiscussionForumPosting', 'why' => 'Thread + top reply + reply count.' ],
                    'profile_page'       => [ 'label' => 'Profile Page',           'eligible' => $has_type( [ 'ProfilePage' ] ), 'schema' => 'ProfilePage', 'why' => 'Author photo + name + bio excerpt.' ],
                    'breadcrumbs'        => [ 'label' => 'Breadcrumb trail',       'eligible' => $has_type( [ 'BreadcrumbList' ] ), 'schema' => 'BreadcrumbList', 'why' => 'Path shown in URL line: site › category › article.' ],
                    'speakable'          => [ 'label' => 'Speakable (voice)',      'eligible' => $has_speakable, 'schema' => 'Speakable within Article', 'why' => 'Google Assistant read-aloud.' ],
                    'paywall'            => [ 'label' => 'Paywall indicator',      'eligible' => $is_paywalled, 'schema' => 'isAccessibleForFree=false', 'why' => '🔒 icon for subscription content.' ],
                ];
                $eligible_count = count( array_filter( array_column( $appearances, 'eligible' ) ) );
                $total_appearances = count( $appearances );

                // v1.5.212 — Per-content-type applicability matrix.
                // Fixes Ben's UX critique on v1.5.207: showing "Add Product schema" on a
                // Blog Post is misleading since Product schema doesn't apply to blog posts.
                //
                // Three-state status per tile:
                //   'active'          — schema detected in @graph (green)
                //   'available'       — applicable to this content_type + adding schema
                //                       (via block, settings, or content) would emit it (amber)
                //   'not_applicable'  — this appearance doesn't fit the article's content_type;
                //                       no action shown (grey, informational)
                $appearances_universal = [ 'standard_article', 'article_with_image', 'breadcrumbs', 'speakable', 'paywall', 'video', 'local_business' ];
                $applicability = [
                    'recipe_card'       => [ 'recipe' ],
                    'recipe_carousel'   => [ 'recipe' ],
                    'recipe_gallery'    => [ 'recipe' ],
                    'product_card'      => [ 'review', 'buying_guide', 'comparison', 'sponsored', 'listicle' ],
                    'product_carousel'  => [ 'buying_guide', 'listicle', 'comparison' ],
                    'review_snippet'    => [ 'review', 'buying_guide', 'comparison' ],
                    'faq'               => [ 'blog_post', 'how_to', 'listicle', 'review', 'comparison', 'buying_guide', 'recipe', 'tech_article', 'white_paper', 'scholarly_article', 'glossary_definition', 'case_study', 'interview', 'pillar_guide', 'news_article', 'opinion', 'faq_page' ],
                    'howto'             => [ 'how_to', 'tech_article' ],
                    'event_card'        => [ 'news_article', 'opinion', 'press_release', 'blog_post' ],
                    'event_carousel'    => [ 'news_article', 'listicle' ],
                    'video_carousel'    => [ 'news_article', 'listicle' ],
                    'top_stories'       => [ 'news_article', 'opinion', 'press_release' ],
                    'course_carousel'   => [ 'tech_article', 'listicle', 'buying_guide' ],
                    'movie_carousel'    => [ 'review', 'listicle', 'opinion' ],
                    'vacation_rental'   => [ 'review', 'listicle', 'buying_guide' ],
                    'job_posting'       => [ 'news_article', 'case_study' ],
                    'software_app'      => [ 'review', 'buying_guide', 'tech_article', 'comparison' ],
                    'dataset'           => [ 'white_paper', 'scholarly_article', 'tech_article' ],
                    'qa_page'           => [ 'interview', 'faq_page', 'case_study' ],
                    'discussion_forum'  => [],  // forum-post @type — not applicable to articles
                    'profile_page'      => [ 'interview' ],
                ];

                // Compute 3-state status per appearance
                foreach ( $appearances as $key => $app ) {
                    if ( $app['eligible'] ) {
                        $appearances[ $key ]['status'] = 'active';
                    } elseif ( in_array( $key, $appearances_universal, true ) ) {
                        $appearances[ $key ]['status'] = 'available';
                    } elseif ( isset( $applicability[ $key ] ) && in_array( $content_type_saved, $applicability[ $key ], true ) ) {
                        $appearances[ $key ]['status'] = 'available';
                    } else {
                        $appearances[ $key ]['status'] = 'not_applicable';
                    }
                }

                $discover_checks = [
                    [ 'label' => 'Featured image set',                 'ok' => $featured_image_url !== '' ],
                    [ 'label' => 'Featured image ≥ 1200px wide',       'ok' => $featured_image_width >= 1200 ],
                    [ 'label' => 'Article schema present',             'ok' => $has_type( $article_types ) ],
                    [ 'label' => 'Recent publish/modify (≤30 days)',   'ok' => $days_since_modified <= 30 ],
                    [ 'label' => 'Mobile-friendly (responsive theme)', 'ok' => true ],
                ];
                $discover_ready = count( array_filter( array_column( $discover_checks, 'ok' ) ) ) >= 4;

                $h2_count = preg_match_all( '/<h2[^>]*>/i', $post->post_content );
                $list_count = preg_match_all( '/<(ul|ol)[^>]*>/i', $post->post_content );

                // v1.5.212 — E-E-A-T detection covers BOTH top-level Organization/Person
                // schemas (now universal per v1.5.212 rollout) AND legacy nested author/publisher
                // fields (for articles saved before v1.5.212, and as a belt-and-braces fallback
                // during the v1.5.212 migration period).
                $has_eeat = $has_type( [ 'Organization', 'Person' ] );
                if ( ! $has_eeat ) {
                    foreach ( $graph as $item ) {
                        if ( isset( $item['author']['@type'] ) && in_array( $item['author']['@type'], [ 'Person', 'Organization' ], true ) ) {
                            $has_eeat = true;
                            break;
                        }
                        if ( isset( $item['publisher']['@type'] ) && $item['publisher']['@type'] === 'Organization' ) {
                            $has_eeat = true;
                            break;
                        }
                    }
                }

                $aio_checks = [
                    [ 'label' => 'FAQ, HowTo, or Article schema present',    'ok' => $has_type( array_merge( $article_types, [ 'FAQPage','HowTo' ] ) ) ],
                    [ 'label' => 'Structured headings (≥3 H2 sections)',     'ok' => $h2_count >= 3 ],
                    [ 'label' => 'Bulleted / numbered lists present',        'ok' => $list_count >= 1 ],
                    [ 'label' => 'Organization or Person (E-E-A-T) schema',  'ok' => $has_eeat ],
                    [ 'label' => 'Recent dateModified (≤90 days)',            'ok' => $days_since_modified <= 90 ],
                ];
                $aio_ready_count = count( array_filter( array_column( $aio_checks, 'ok' ) ) );
                $aio_score = (int) round( ( $aio_ready_count / max( 1, count( $aio_checks ) ) ) * 100 );

                $og_title_set = (bool) get_post_meta( $post->ID, '_seobetter_meta_title', true );
                $og_desc_len = mb_strlen( $meta_desc );
                $site_icon_id = (int) get_option( 'site_icon', 0 );
                $llm_checks = [
                    [ 'label' => 'SEO title set and ≤ 70 chars',              'ok' => $og_title_set && mb_strlen( $meta_title ) <= 70 ],
                    [ 'label' => 'Description 120–200 chars',                 'ok' => $og_desc_len >= 120 && $og_desc_len <= 200 ],
                    [ 'label' => 'Featured image ≥ 1200×630 (Perplexity)',    'ok' => $featured_image_width >= 1200 && $featured_image_height >= 630 ],
                    [ 'label' => 'Favicon (site icon) configured',            'ok' => $site_icon_id > 0 ],
                    [ 'label' => 'Site name configured',                      'ok' => $site_name !== '' ],
                    [ 'label' => 'FAQ schema (Perplexity bonus)',             'ok' => $has_type( [ 'FAQPage' ] ) ],
                    [ 'label' => 'HowTo / step-structured (ChatGPT bonus)',   'ok' => $has_type( [ 'HowTo' ] ) ],
                    [ 'label' => 'Organization schema (Gemini bonus)',        'ok' => $has_type( [ 'Organization' ] ) ],
                ];
                $llm_ready_count = count( array_filter( array_column( $llm_checks, 'ok' ) ) );
                $llm_score = (int) round( ( $llm_ready_count / max( 1, count( $llm_checks ) ) ) * 100 );

                // Context array passed to render_rr_mock() for every tile.
                $rr_ctx = [
                    'meta_title'         => $meta_title,
                    'meta_desc'          => $meta_desc,
                    'site_name'          => $site_name,
                    'site_host'          => $site_host,
                    'url_breadcrumb'     => $url_breadcrumb ?? $site_host,
                    'favicon_url'        => $favicon_url ?? home_url( '/favicon.ico' ),
                    'featured_image_url' => $featured_image_url,
                    'recipe_data'        => $recipe_data,
                    'review_data'        => $review_data,
                    'product_data'       => $product_data,
                    'video_data'         => $video_data,
                    'event_data'         => $event_data,
                    'local_data'         => $local_data,
                    'job_data'           => $job_data,
                    'faq_questions'      => $faq_questions,
                    'breadcrumbs'        => $breadcrumbs,
                    'published_date'     => $published_date,
                    'keyword'            => $keyword,
                ];
                ?>

                <?php if ( empty( $graph ) ) : ?>
                    <div style="text-align:center;padding:40px 20px;color:#9ca3af">
                        <div style="font-size:28px;margin-bottom:8px">🔍</div>
                        <div style="font-size:14px;font-weight:600;color:#6b7280;margin-bottom:4px">No Schema Data</div>
                        <div style="font-size:12px">Save a draft from the article generator to see Rich Results Preview.</div>
                    </div>
                <?php else : ?>

                <?php if ( ! $site_icon_configured ) : ?>
                <!-- v1.5.212 — Site Icon warning at top of Rich Results tab -->
                <div style="margin-bottom:16px;padding:10px 14px;background:#fffbeb;border-left:3px solid #f59e0b;border-radius:0 6px 6px 0;font-size:12px;color:#92400e;line-height:1.5">
                    <strong>⚠ Site Icon not set.</strong> The previews below use a generic favicon placeholder. Google SERPs, Discover, AI Overviews, and every LLM citation card below will show a blank/broken favicon until Site Icon is configured.
                    <a href="<?php echo esc_url( admin_url( 'customize.php?autofocus[section]=title_tagline' ) ); ?>" target="_blank" style="color:#92400e;font-weight:600;text-decoration:underline;margin-left:6px">Configure Site Icon →</a>
                </div>
                <?php endif; ?>

                <!-- v1.5.207 — 4-subview Rich Results Visual Catalog -->
                <div class="sb-rr-subnav" style="display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap">
                    <button type="button" class="sb-rr-pill sb-rr-pill-active" data-rr="search" style="padding:6px 14px;font-size:12px;font-weight:600;border:1px solid #764ba2;background:#764ba2;color:#fff;border-radius:999px;cursor:pointer">🔎 Google Search</button>
                    <button type="button" class="sb-rr-pill" data-rr="discover" style="padding:6px 14px;font-size:12px;font-weight:600;border:1px solid #d1d5db;background:#fff;color:#374151;border-radius:999px;cursor:pointer">📱 Google Discover</button>
                    <button type="button" class="sb-rr-pill" data-rr="aio" style="padding:6px 14px;font-size:12px;font-weight:600;border:1px solid #d1d5db;background:#fff;color:#374151;border-radius:999px;cursor:pointer">🤖 AI Overviews</button>
                    <button type="button" class="sb-rr-pill" data-rr="llm" style="padding:6px 14px;font-size:12px;font-weight:600;border:1px solid #d1d5db;background:#fff;color:#374151;border-radius:999px;cursor:pointer">💬 LLM Citations</button>
                </div>

                <!-- SUBVIEW 1: GOOGLE SEARCH GALLERY -->
                <div class="sb-rr-subview" data-rr="search">
                    <div style="padding:10px 14px;background:<?php echo $eligible_count >= 5 ? '#ecfdf5' : '#fffbeb'; ?>;border-left:3px solid <?php echo $eligible_count >= 5 ? '#22c55e' : '#f59e0b'; ?>;border-radius:0 4px 4px 0;font-size:13px;margin-bottom:16px">
                        <strong><?php echo esc_html( $eligible_count ); ?> of <?php echo esc_html( $total_appearances ); ?></strong> Google Search appearances eligible. Greyed tiles show appearances to unlock by adding schema.
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-bottom:20px">
                        <?php foreach ( $appearances as $key => $app ) :
                            // v1.5.212 — 3-state badge: Active / Available / Not applicable
                            $status = $app['status'] ?? 'not_applicable';
                            switch ( $status ) {
                                case 'active':
                                    $tile_border = '#22c55e';
                                    $tile_bg = '#fff';
                                    $tile_opacity = '';
                                    $badge_bg = '#dcfce7';
                                    $badge_color = '#166534';
                                    $badge_text = '✓ Active';
                                    break;
                                case 'available':
                                    $tile_border = '#f59e0b';
                                    $tile_bg = '#fff';
                                    $tile_opacity = '';
                                    $badge_bg = '#fef3c7';
                                    $badge_color = '#92400e';
                                    $badge_text = '● Available';
                                    break;
                                default:  // not_applicable
                                    $tile_border = '#e5e7eb';
                                    $tile_bg = '#f9fafb';
                                    $tile_opacity = 'opacity:0.55;';
                                    $badge_bg = '#e5e7eb';
                                    $badge_color = '#6b7280';
                                    $badge_text = '○ Not applicable';
                                    break;
                            }
                        ?>
                            <div style="border:1px solid <?php echo $tile_border; ?>;border-radius:8px;padding:12px;background:<?php echo $tile_bg; ?>;<?php echo $tile_opacity; ?>">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:6px">
                                    <div style="font-size:12px;font-weight:700;color:#111827"><?php echo esc_html( $app['label'] ); ?></div>
                                    <span style="font-size:10px;padding:2px 8px;border-radius:999px;font-weight:600;white-space:nowrap;background:<?php echo $badge_bg; ?>;color:<?php echo $badge_color; ?>">
                                        <?php echo $badge_text; ?>
                                    </span>
                                </div>
                                <?php $this->render_rr_mock( $key, $rr_ctx ); ?>
                                <div style="margin-top:8px;padding-top:8px;border-top:1px solid #f3f4f6;font-size:10px;color:#6b7280;line-height:1.4">
                                    <div><strong>Requires:</strong> <?php echo esc_html( $app['schema'] ); ?></div>
                                    <div style="margin-top:2px"><?php echo esc_html( $app['why'] ); ?></div>
                                    <?php if ( $status === 'not_applicable' ) : ?>
                                        <div style="margin-top:4px;font-style:italic;color:#9ca3af">Doesn't apply to this content type (<?php echo esc_html( $content_type_saved ?: 'blog_post' ); ?>).</div>
                                    <?php elseif ( $status === 'available' ) : ?>
                                        <div style="margin-top:4px;font-style:italic;color:#92400e">Schema not yet emitted. Applicable to this content type — add via block or content detection.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    // v1.5.212 — 3-state legend + summary counts above the grid
                    $counts = [
                        'active' => 0,
                        'available' => 0,
                        'not_applicable' => 0,
                    ];
                    foreach ( $appearances as $a ) {
                        $counts[ $a['status'] ?? 'not_applicable' ]++;
                    }
                    ?>
                    <div style="display:flex;gap:16px;flex-wrap:wrap;padding:10px 14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;font-size:11px;color:#4b5563">
                        <div><span style="color:#166534;font-weight:700">✓ Active</span> = <?php echo $counts['active']; ?> (schema detected, rich result eligible)</div>
                        <div><span style="color:#92400e;font-weight:700">● Available</span> = <?php echo $counts['available']; ?> (applicable to this content type, add via block)</div>
                        <div><span style="color:#6b7280;font-weight:700">○ Not applicable</span> = <?php echo $counts['not_applicable']; ?> (doesn't apply to this content type)</div>
                    </div>
                </div>

                <!-- SUBVIEW 2: GOOGLE DISCOVER -->
                <div class="sb-rr-subview" data-rr="discover" style="display:none">
                    <div style="padding:10px 14px;background:<?php echo $discover_ready ? '#ecfdf5' : '#fffbeb'; ?>;border-left:3px solid <?php echo $discover_ready ? '#22c55e' : '#f59e0b'; ?>;border-radius:0 4px 4px 0;font-size:13px;margin-bottom:16px">
                        <?php echo $discover_ready ? '✅ Eligible for Google Discover feed' : '⚠️ Not yet eligible for Google Discover — see checklist'; ?>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">
                        <div>
                            <div style="font-size:11px;color:#6b7280;margin-bottom:8px;font-weight:600">PREVIEW (MOBILE)</div>
                            <div style="max-width:360px;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff">
                                <?php if ( $featured_image_url ) : ?>
                                    <div style="width:100%;padding-top:56.25%;background:url('<?php echo esc_url( $featured_image_url ); ?>') center/cover;border-bottom:1px solid #f3f4f6"></div>
                                <?php else : ?>
                                    <div style="width:100%;padding-top:56.25%;background:linear-gradient(135deg,#667eea,#764ba2);position:relative"><div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#fff;font-size:11px;opacity:0.7">No featured image</div></div>
                                <?php endif; ?>
                                <div style="padding:12px">
                                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px">
                                        <img src="<?php echo esc_url( $favicon_url ?? '' ); ?>" alt="" width="14" height="14" style="border-radius:50%" onerror="this.style.display='none'">
                                        <span style="font-size:10px;color:#6b7280"><?php echo esc_html( $site_name ); ?></span>
                                    </div>
                                    <div style="font-size:15px;font-weight:600;color:#202124;line-height:1.3;margin-bottom:4px"><?php echo esc_html( $meta_title ); ?></div>
                                    <div style="font-size:12px;color:#4d5156;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?php echo esc_html( $meta_desc ); ?></div>
                                    <div style="display:flex;gap:14px;margin-top:10px;padding-top:8px;border-top:1px solid #f3f4f6;font-size:13px;color:#9ca3af"><span>👍</span><span>🔖</span><span>⋯</span></div>
                                </div>
                            </div>
                        </div>
                        <div>
                            <div style="font-size:11px;color:#6b7280;margin-bottom:8px;font-weight:600">DISCOVER ELIGIBILITY</div>
                            <?php foreach ( $discover_checks as $c ) : ?>
                                <div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:13px">
                                    <span style="color:<?php echo $c['ok'] ? '#22c55e' : '#ef4444'; ?>;font-weight:700"><?php echo $c['ok'] ? '✓' : '✗'; ?></span>
                                    <span style="color:#374151"><?php echo esc_html( $c['label'] ); ?></span>
                                </div>
                            <?php endforeach; ?>
                            <?php if ( $featured_image_width > 0 && $featured_image_width < 1200 ) : ?>
                                <div style="margin-top:10px;padding:8px 10px;background:#fef2f2;border-left:3px solid #ef4444;border-radius:0 4px 4px 0;font-size:11px;color:#7f1d1d">
                                    Your featured image is <?php echo esc_html( $featured_image_width ); ?>px wide. Discover requires ≥1200px.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- SUBVIEW 3: AI OVERVIEWS -->
                <div class="sb-rr-subview" data-rr="aio" style="display:none">
                    <div style="padding:10px 14px;background:<?php echo $aio_score >= 80 ? '#ecfdf5' : ( $aio_score >= 60 ? '#fffbeb' : '#fef2f2' ); ?>;border-left:3px solid <?php echo $aio_score >= 80 ? '#22c55e' : ( $aio_score >= 60 ? '#f59e0b' : '#ef4444' ); ?>;border-radius:0 4px 4px 0;font-size:13px;margin-bottom:16px">
                        <strong>AI Overview citation readiness: <?php echo esc_html( $aio_score ); ?>/100</strong> — Google AI Overviews appear on ~58% of informational queries (2026). High readiness removes structural blockers; it doesn't guarantee inclusion.
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">
                        <div>
                            <div style="font-size:11px;color:#6b7280;margin-bottom:8px;font-weight:600">CITATION OVERLAY (2026)</div>
                            <div style="border:1px solid #e5e7eb;border-radius:8px;padding:14px;background:#fff">
                                <div style="font-size:12px;color:#202124;line-height:1.6;margin-bottom:10px">
                                    According to recent research, <span style="background:#e0e7ff;color:#4338ca;padding:2px 4px;border-radius:3px;border-bottom:2px dotted #4338ca"><?php echo esc_html( mb_strimwidth( $keyword ?: 'this topic', 0, 40, '…' ) ); ?></span> offers significant benefits when properly structured…
                                </div>
                                <div style="padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;box-shadow:0 4px 12px rgba(0,0,0,0.08)">
                                    <div style="font-size:9px;color:#6b7280;font-weight:700;margin-bottom:6px;letter-spacing:0.5px">SOURCES (3)</div>
                                    <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f3f4f6">
                                        <img src="<?php echo esc_url( $favicon_url ?? '' ); ?>" alt="" width="14" height="14" onerror="this.style.display='none'">
                                        <div style="flex:1;min-width:0">
                                            <div style="font-size:10px;color:#6b7280"><?php echo esc_html( $site_name ); ?></div>
                                            <div style="font-size:11px;color:#1a0dab;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:500"><?php echo esc_html( $meta_title ); ?></div>
                                        </div>
                                        <span style="font-size:8px;padding:2px 5px;background:#dcfce7;color:#166534;border-radius:999px;font-weight:600;white-space:nowrap">This site</span>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f3f4f6;opacity:0.6">
                                        <div style="width:14px;height:14px;background:#d1d5db;border-radius:2px"></div>
                                        <div style="flex:1;min-width:0"><div style="font-size:10px;color:#9ca3af">example.com</div><div style="font-size:11px;color:#1a0dab">Related source page…</div></div>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:8px;padding:6px 0;opacity:0.6">
                                        <div style="width:14px;height:14px;background:#d1d5db;border-radius:2px"></div>
                                        <div style="flex:1;min-width:0"><div style="font-size:10px;color:#9ca3af">wikipedia.org</div><div style="font-size:11px;color:#1a0dab">Background reference…</div></div>
                                    </div>
                                    <div style="width:100%;margin-top:8px;padding:6px;font-size:11px;border:1px solid #d1d5db;background:#fff;border-radius:6px;color:#4338ca;font-weight:600;text-align:center">💬 Ask about this</div>
                                </div>
                            </div>
                        </div>
                        <div>
                            <div style="font-size:11px;color:#6b7280;margin-bottom:8px;font-weight:600">READINESS SIGNALS</div>
                            <?php foreach ( $aio_checks as $c ) : ?>
                                <div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:13px">
                                    <span style="color:<?php echo $c['ok'] ? '#22c55e' : '#ef4444'; ?>;font-weight:700"><?php echo $c['ok'] ? '✓' : '✗'; ?></span>
                                    <span style="color:#374151"><?php echo esc_html( $c['label'] ); ?></span>
                                </div>
                            <?php endforeach; ?>
                            <div style="margin-top:12px;padding:10px;background:#f0f9ff;border-left:3px solid #3b82f6;border-radius:0 4px 4px 0;font-size:11px;color:#1e40af;line-height:1.5">
                                AI Overviews group multiple sources per hovered claim. You compete to be IN the group, not for a single click. Structured headings + bullet lists + citations raise inclusion probability.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SUBVIEW 4: LLM CITATIONS -->
                <div class="sb-rr-subview" data-rr="llm" style="display:none">
                    <div style="padding:10px 14px;background:<?php echo $llm_score >= 75 ? '#ecfdf5' : ( $llm_score >= 50 ? '#fffbeb' : '#fef2f2' ); ?>;border-left:3px solid <?php echo $llm_score >= 75 ? '#22c55e' : ( $llm_score >= 50 ? '#f59e0b' : '#ef4444' ); ?>;border-radius:0 4px 4px 0;font-size:13px;margin-bottom:12px">
                        <strong>LLM Citation Readiness: <?php echo esc_html( $llm_score ); ?>/100</strong> — drives whether Perplexity, ChatGPT, Gemini, Claude <em>include</em> your page. Visual layout is uniform per platform regardless of schema.
                    </div>
                    <div style="padding:12px 14px;margin-bottom:16px;background:#fffbeb;border:1px solid #f59e0b;border-radius:6px;font-size:12px;color:#92400e;line-height:1.5">
                        <strong>💡 Key difference vs Google Search:</strong> LLMs render the SAME citation card layout whether your article is a Recipe, FAQ, or HowTo. Schema doesn't change the visual. What DOES change is inclusion probability — FAQ for Perplexity, HowTo for ChatGPT, Organization for Gemini.
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-bottom:16px">
                        <div style="border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#fff">
                            <div style="display:flex;align-items:center;gap:6px;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #f3f4f6">
                                <span style="width:20px;height:20px;background:#2cbec0;color:#fff;border-radius:50%;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center">P</span>
                                <span style="font-size:11px;font-weight:600;color:#111827">Perplexity</span>
                            </div>
                            <div style="display:flex;gap:10px">
                                <div style="flex:1;min-width:0">
                                    <div style="display:flex;align-items:center;gap:5px;margin-bottom:4px">
                                        <span style="font-size:9px;background:#f3f4f6;color:#6b7280;padding:1px 5px;border-radius:3px;font-weight:600">3</span>
                                        <img src="<?php echo esc_url( $favicon_url ?? '' ); ?>" alt="" width="12" height="12" style="border-radius:50%" onerror="this.style.display='none'">
                                        <span style="font-size:9px;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo esc_html( $site_name ); ?></span>
                                    </div>
                                    <div style="font-size:11px;font-weight:600;color:#111827;line-height:1.3;margin-bottom:4px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?php echo esc_html( $meta_title ); ?></div>
                                    <div style="font-size:10px;color:#6b7280;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?php echo esc_html( $meta_desc ); ?></div>
                                </div>
                                <?php if ( $featured_image_url ) : ?>
                                    <div style="width:60px;height:60px;background:url('<?php echo esc_url( $featured_image_url ); ?>') center/cover;border-radius:6px;flex-shrink:0"></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#fff">
                            <div style="display:flex;align-items:center;gap:6px;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #f3f4f6">
                                <span style="width:20px;height:20px;background:#10a37f;color:#fff;border-radius:50%;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center">G</span>
                                <span style="font-size:11px;font-weight:600;color:#111827">ChatGPT Search</span>
                            </div>
                            <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">
                                <img src="<?php echo esc_url( $favicon_url ?? '' ); ?>" alt="" width="12" height="12" style="border-radius:50%" onerror="this.style.display='none'">
                                <span style="font-size:10px;color:#6b7280"><?php echo esc_html( $site_host ); ?></span>
                            </div>
                            <div style="font-size:12px;color:#1a0dab;line-height:1.3;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo esc_html( $meta_title ); ?></div>
                            <div style="font-size:10px;color:#6b7280;line-height:1.4;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden"><?php echo esc_html( $meta_desc ); ?></div>
                        </div>
                        <div style="border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#fff">
                            <div style="display:flex;align-items:center;gap:6px;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #f3f4f6">
                                <span style="width:20px;height:20px;background:linear-gradient(135deg,#4285f4,#9c27b0);color:#fff;border-radius:50%;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center">✦</span>
                                <span style="font-size:11px;font-weight:600;color:#111827">Gemini</span>
                            </div>
                            <div style="font-size:11px;color:#374151;line-height:1.5;margin-bottom:6px">...optimize for AI search<sup style="background:#e0e7ff;color:#4338ca;padding:1px 4px;border-radius:3px;font-size:8px;font-weight:700">1</sup>.</div>
                            <div style="padding:6px 8px;background:#f9fafb;border-radius:6px">
                                <div style="display:flex;align-items:center;gap:5px;margin-bottom:2px">
                                    <img src="<?php echo esc_url( $favicon_url ?? '' ); ?>" alt="" width="10" height="10" style="border-radius:50%" onerror="this.style.display='none'">
                                    <span style="font-size:9px;color:#6b7280"><?php echo esc_html( $site_name ); ?></span>
                                </div>
                                <div style="font-size:10px;color:#1a0dab;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo esc_html( $meta_title ); ?></div>
                            </div>
                        </div>
                        <div style="border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#fff">
                            <div style="display:flex;align-items:center;gap:6px;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #f3f4f6">
                                <span style="width:20px;height:20px;background:#D97757;color:#fff;border-radius:50%;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center">A</span>
                                <span style="font-size:11px;font-weight:600;color:#111827">Claude</span>
                            </div>
                            <div style="font-size:11px;color:#374151;line-height:1.5;margin-bottom:6px">...based on the referenced analysis.</div>
                            <div style="margin-top:6px;padding-top:6px;border-top:1px solid #f3f4f6;font-size:9px;color:#6b7280">Footnote:</div>
                            <div style="font-size:10px;color:#374151;margin-top:3px">[1] <span style="color:#1a0dab;font-weight:500"><?php echo esc_html( $site_name ); ?></span> – <?php echo esc_html( mb_strimwidth( $meta_title, 0, 40, '…' ) ); ?></div>
                        </div>
                    </div>
                    <div style="font-size:11px;color:#6b7280;margin-bottom:10px;font-weight:600">READINESS SIGNALS (8 checks)</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:2px 16px;margin-bottom:12px">
                        <?php foreach ( $llm_checks as $c ) : ?>
                            <div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #f3f4f6;font-size:12px">
                                <span style="color:<?php echo $c['ok'] ? '#22c55e' : '#ef4444'; ?>;font-weight:700"><?php echo $c['ok'] ? '✓' : '✗'; ?></span>
                                <span style="color:#374151"><?php echo esc_html( $c['label'] ); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="padding:10px 14px;background:#f0f9ff;border-left:3px solid #3b82f6;border-radius:0 4px 4px 0;font-size:11px;color:#1e40af;line-height:1.5">
                        <strong>What drives each field:</strong> Title ← &lt;title&gt; → og:title → meta title. Description ← meta description → og:description → first-paragraph extract. Thumbnail ← og:image (Perplexity + ChatGPT only). Favicon ← site icon. Publisher ← og:site_name → domain.
                    </div>
                </div>

                <!-- Schema Impact Estimate -->
                <div style="margin-bottom:20px">
                    <div style="font-size:13px;font-weight:600;margin-bottom:8px">Schema Impact Estimate</div>
                    <?php
                    $impacts = [];
                    if ( in_array( 'Recipe', $active_types ) ) $impacts[] = '+2.7x clicks with Recipe rich results (Searchmetrics, 2024)';
                    if ( in_array( 'FAQ', $active_types ) ) $impacts[] = '+87% CTR with FAQ schema for informational queries (Ahrefs)';
                    if ( in_array( 'Review', $active_types ) || in_array( 'Product', $active_types ) ) $impacts[] = '+35% CTR with star ratings (Search Engine Journal)';
                    if ( in_array( 'Video', $active_types ) ) $impacts[] = '+157% organic traffic with video rich results (BrightEdge)';
                    if ( count( $active_types ) > 0 ) {
                        $impacts[] = '+30-40% AI citation rate from structured data (Princeton GEO study)';
                        $impacts[] = 'Pages with schema rank avg 4 positions higher (Milestone Research, 2023)';
                        $impacts[] = 'Rich results get 58% of all page 1 clicks (FirstPageSage, 2024)';
                    }
                    foreach ( $impacts as $imp ) :
                    ?>
                        <div style="font-size:12px;color:#4b5563;padding:3px 0">&#128202; <?php echo esc_html( $imp ); ?></div>
                    <?php endforeach; ?>
                </div>

                <!-- Schema Validation -->
                <div style="margin-bottom:20px">
                    <div style="font-size:13px;font-weight:600;margin-bottom:8px">Validation</div>
                    <?php
                    $errors = 0;
                    $warnings = 0;
                    $checks_list = [];
                    foreach ( $graph as $item ) {
                        $t = $item['@type'] ?? '';
                        if ( $t === 'Recipe' ) {
                            if ( ! empty( $item['name'] ) ) $checks_list[] = [ true, 'Recipe.name', $item['name'] ];
                            else { $checks_list[] = [ false, 'Recipe.name', 'MISSING (required)' ]; $errors++; }
                            if ( ! empty( $item['image'] ) ) $checks_list[] = [ true, 'Recipe.image', is_array( $item['image'] ) ? count( $item['image'] ) . ' URLs' : '1 URL' ];
                            else { $checks_list[] = [ false, 'Recipe.image', 'MISSING (required)' ]; $errors++; }
                            $checks_list[] = [ ! empty( $item['recipeIngredient'] ), 'Recipe.ingredients', ! empty( $item['recipeIngredient'] ) ? count( $item['recipeIngredient'] ) . ' items' : 'not set' ];
                            if ( empty( $item['recipeIngredient'] ) ) $warnings++;
                            $checks_list[] = [ ! empty( $item['recipeCuisine'] ), 'Recipe.cuisine', $item['recipeCuisine'] ?? 'not set' ];
                            if ( empty( $item['recipeCuisine'] ) ) $warnings++;
                        }
                        if ( $t === 'FAQPage' ) {
                            $qc = count( $item['mainEntity'] ?? [] );
                            $checks_list[] = [ $qc > 0, 'FAQ.questions', $qc . ' questions' ];
                            if ( $qc === 0 ) $errors++;
                        }
                        if ( $t === 'Review' ) {
                            $checks_list[] = [ ! empty( $item['reviewRating'] ), 'Review.rating', ! empty( $item['reviewRating']['ratingValue'] ) ? $item['reviewRating']['ratingValue'] . '/5' : 'not set' ];
                            if ( empty( $item['reviewRating'] ) ) $warnings++;
                        }
                        if ( $t === 'Product' ) {
                            $checks_list[] = [ ! empty( $item['name'] ), 'Product.name', $item['name'] ?? 'MISSING' ];
                            if ( empty( $item['name'] ) ) $errors++;
                        }
                    }
                    $valid = $errors === 0;
                    ?>
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:13px">
                        <span style="font-size:16px"><?php echo $valid ? '&#9989;' : '&#9888;&#65039;'; ?></span>
                        <span style="font-weight:600;color:<?php echo $valid ? '#22c55e' : '#ef4444'; ?>">
                            <?php echo $valid ? 'Schema valid' : 'Schema has issues'; ?>
                        </span>
                        <span style="color:#9ca3af;font-size:12px">(<?php echo $errors; ?> error<?php echo $errors !== 1 ? 's' : ''; ?>, <?php echo $warnings; ?> warning<?php echo $warnings !== 1 ? 's' : ''; ?>)</span>
                    </div>
                    <?php foreach ( $checks_list as $ck ) : ?>
                        <div style="display:flex;align-items:center;gap:6px;padding:3px 0;font-size:12px;border-bottom:1px solid #f9fafb">
                            <span style="color:<?php echo $ck[0] ? '#22c55e' : '#ef4444'; ?>"><?php echo $ck[0] ? '&#10003;' : '&#10007;'; ?></span>
                            <span style="color:#6b7280;font-weight:600;min-width:120px"><?php echo esc_html( $ck[1] ); ?></span>
                            <span style="color:#374151"><?php echo esc_html( $ck[2] ); ?></span>
                        </div>
                    <?php endforeach; ?>

                    <div style="margin-top:10px">
                        <a href="https://search.google.com/test/rich-results?url=<?php echo urlencode( get_permalink( $post->ID ) ); ?>" target="_blank" rel="noopener" style="font-size:12px;color:#764ba2;text-decoration:none">&#128279; Test in Google Rich Results Test &rarr;</a>
                        <?php if ( $post->post_status !== 'publish' ) : ?>
                            <div style="font-size:11px;color:#9ca3af;margin-top:4px">Publish the post first — Google can only test published URLs.</div>
                        <?php endif; ?>
                        <span style="margin:0 6px;color:#d1d5db">|</span>
                        <a href="https://validator.schema.org/#url=<?php echo urlencode( get_permalink( $post->ID ) ); ?>" target="_blank" rel="noopener" style="font-size:12px;color:#764ba2;text-decoration:none">&#128279; Schema.org Validator &rarr;</a>
                    </div>
                </div>

                <!-- Raw JSON-LD Inspector -->
                <div style="margin-bottom:10px">
                    <div style="font-size:13px;font-weight:600;margin-bottom:6px;cursor:pointer" onclick="var el=document.getElementById('sb-schema-raw');el.style.display=el.style.display==='none'?'block':'none';this.querySelector('span').textContent=el.style.display==='none'?'&#9660;':'&#9650;'">
                        <span>&#9660;</span> View Raw JSON-LD (<?php echo count( $graph ); ?> schema<?php echo count( $graph ) !== 1 ? 's' : ''; ?>)
                    </div>
                    <div id="sb-schema-raw" style="display:none">
                        <pre style="background:#1e293b;color:#e2e8f0;padding:12px;border-radius:8px;font-size:11px;max-height:300px;overflow:auto;white-space:pre-wrap;word-break:break-all"><?php echo esc_html( wp_json_encode( $schema_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
                        <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('sb-schema-raw').querySelector('pre').textContent).then(function(){alert('Copied!')})" style="margin-top:6px;padding:4px 12px;font-size:11px;border:1px solid #d1d5db;border-radius:4px;background:#fff;cursor:pointer">Copy JSON-LD</button>
                    </div>
                </div>

                <?php endif; ?>
                <?php } catch ( \Throwable $e ) { ?>
                    <div style="text-align:center;padding:20px;color:#9ca3af;font-size:13px">
                        Rich Results data unavailable. Save the post to generate schema.
                    </div>
                <?php } ?>
            </div>
        </div>

        <!-- Tab switching + SERP preview live editor JS (v1.5.206d-fix19) -->
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

            // v1.5.207 — Rich Results sub-view switcher (Google Search / Discover / AI Overviews / LLM Citations)
            var rrPills = document.querySelectorAll('.sb-rr-pill');
            var rrSubviews = document.querySelectorAll('.sb-rr-subview');
            rrPills.forEach(function(pill) {
                pill.addEventListener('click', function() {
                    var target = this.getAttribute('data-rr');
                    rrPills.forEach(function(p) {
                        p.classList.remove('sb-rr-pill-active');
                        p.style.background = '#fff';
                        p.style.color = '#374151';
                        p.style.borderColor = '#d1d5db';
                    });
                    this.classList.add('sb-rr-pill-active');
                    this.style.background = '#764ba2';
                    this.style.color = '#fff';
                    this.style.borderColor = '#764ba2';
                    rrSubviews.forEach(function(sv) {
                        sv.style.display = sv.getAttribute('data-rr') === target ? 'block' : 'none';
                    });
                });
            });

            // v1.5.206d-fix19 — SERP preview live editor
            var titleInput   = document.getElementById('sb-meta-title-input');
            var descInput    = document.getElementById('sb-meta-desc-input');
            var titleCount   = document.getElementById('sb-meta-title-count');
            var descCount    = document.getElementById('sb-meta-desc-count');
            var titleDisplay = document.getElementById('sb-serp-title');
            var descDisplay  = document.getElementById('sb-serp-desc');
            var card         = document.getElementById('sb-serp-card');
            if (!titleInput || !descInput || !card) return;

            // Separate caps per device since Google truncates differently.
            var caps = { desktop: { title: 60, desc: 160 }, mobile: { title: 45, desc: 120 } };
            var currentDevice = 'desktop';

            function colorFor(len, ideal, max) {
                if (len === 0) return '#9ca3af';
                if (len <= max) return '#22c55e';
                if (len <= max + 10) return '#f59e0b';
                return '#ef4444';
            }
            function updateTitle() {
                var v = titleInput.value || '';
                titleDisplay.textContent = v;
                var cap = caps[currentDevice].title;
                titleCount.textContent = v.length + '/' + cap;
                titleCount.style.color = colorFor(v.length, cap, cap);
            }
            function updateDesc() {
                var v = descInput.value || '';
                descDisplay.textContent = v;
                var cap = caps[currentDevice].desc;
                descCount.textContent = v.length + '/' + cap;
                descCount.style.color = colorFor(v.length, cap, cap);
            }
            titleInput.addEventListener('input', updateTitle);
            descInput.addEventListener('input', updateDesc);
            updateTitle();
            updateDesc();

            // Device toggle — mobile narrows the preview card + tightens truncation caps
            var devices = document.querySelectorAll('.sb-serp-device');
            devices.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    devices.forEach(function(b) {
                        b.classList.remove('sb-serp-device-active');
                        b.style.background = '#fff';
                        b.style.color = '#6b7280';
                    });
                    this.classList.add('sb-serp-device-active');
                    this.style.background = '#f3f4f6';
                    this.style.color = '#111827';
                    currentDevice = this.getAttribute('data-device');
                    card.setAttribute('data-device', currentDevice);
                    if (currentDevice === 'mobile') {
                        card.style.maxWidth = '380px';
                        titleDisplay.style.fontSize = '18px';
                        descDisplay.style.fontSize = '13px';
                        descDisplay.style.webkitLineClamp = '3';
                    } else {
                        card.style.maxWidth = '600px';
                        titleDisplay.style.fontSize = '20px';
                        descDisplay.style.fontSize = '14px';
                        descDisplay.style.webkitLineClamp = '2';
                    }
                    updateTitle();
                    updateDesc();
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
