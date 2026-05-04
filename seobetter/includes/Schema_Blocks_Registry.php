<?php
/**
 * Schema Blocks Registry (v1.5.216.62.28)
 *
 * Registers the 5 native Gutenberg blocks introduced in v62.28:
 *   - seobetter/product
 *   - seobetter/event
 *   - seobetter/local-business
 *   - seobetter/vacation-rental
 *   - seobetter/job-posting
 *
 * Each block is dynamic (server-rendered): `save` returns null in JS, and
 * PHP `render_callback` calls Schema_Blocks_Manager::render_html() to
 * produce the visible card. This guarantees editor preview matches the
 * front-end pixel-for-pixel because both surfaces hit the same render.
 *
 * Pro+ license-gated at registration time. If the site doesn't have the
 * `schema_blocks_5` capability, the blocks are NOT registered and the
 * inserter never shows them — Free / Pro tier users don't see ghost
 * options they can't use.
 *
 * @package SEOBetter
 */

namespace SEOBetter;

defined( 'ABSPATH' ) || exit;

class Schema_Blocks_Registry {

    /**
     * Map block-type slug → manager block-key (used by render_html /
     * build_jsonld in Schema_Blocks_Manager). The block-type slugs use
     * dashes per WordPress convention; the manager uses no-dashes per
     * pre-v62.28 storage.
     */
    public const BLOCK_NAME_MAP = [
        'seobetter/product'         => 'product',
        'seobetter/event'           => 'event',
        'seobetter/local-business'  => 'localbusiness',
        'seobetter/vacation-rental' => 'vacationrental',
        'seobetter/job-posting'     => 'jobposting',
    ];

    /**
     * Boot hooks. Called from seobetter.php during init.
     */
    public static function boot(): void {
        add_action( 'init', [ __CLASS__, 'register_blocks' ], 20 );
        add_action( 'block_categories_all', [ __CLASS__, 'register_category' ], 10, 2 );
        add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_editor_assets' ] );
    }

    /**
     * v62.28 — Register a "SEOBetter" block category so all 5 blocks
     * appear under one heading in the inserter.
     *
     * @param array $categories
     * @return array
     */
    public static function register_category( $categories ): array {
        $has_seobetter = false;
        foreach ( $categories as $c ) {
            if ( ! empty( $c['slug'] ) && $c['slug'] === 'seobetter' ) {
                $has_seobetter = true;
                break;
            }
        }
        if ( ! $has_seobetter ) {
            $categories = array_merge( $categories, [ [
                'slug'  => 'seobetter',
                'title' => __( 'SEOBetter', 'seobetter' ),
                'icon'  => 'chart-line',
            ] ] );
        }
        return $categories;
    }

    /**
     * Register the 5 schema blocks. Pro+ gated — registration is skipped
     * entirely on Free / Pro tier so the blocks don't appear in the
     * inserter at all.
     */
    public static function register_blocks(): void {
        if ( ! class_exists( License_Manager::class ) || ! License_Manager::can_use( 'schema_blocks_5' ) ) {
            return;
        }
        if ( ! function_exists( 'register_block_type' ) ) {
            return; // pre-WP 5.0
        }

        $shared = [
            'editor_script' => 'seobetter-schema-blocks',
            'category'      => 'seobetter',
            'supports'      => [ 'html' => false, 'multiple' => true, 'reusable' => true ],
        ];

        // Per-type field-spec mirrors the JS DEFS object so server +
        // client agree on attribute schemas. Source of truth for both
        // is `SEOBetter::schema_block_field_defs()` in seobetter.php.
        $blocks = [
            'seobetter/product'         => 'product',
            'seobetter/event'           => 'event',
            'seobetter/local-business'  => 'localbusiness',
            'seobetter/vacation-rental' => 'vacationrental',
            'seobetter/job-posting'     => 'jobposting',
            'seobetter/faq'             => 'faq',
        ];

        foreach ( $blocks as $block_name => $manager_key ) {
            $field_defs = \SEOBetter::schema_block_field_defs( $manager_key );
            $attributes = self::build_attributes_from_field_defs( $field_defs );
            $args       = array_merge( $shared, [
                'attributes'      => $attributes,
                'render_callback' => function ( $attrs ) use ( $manager_key ) {
                    return self::render_block( $manager_key, $attrs );
                },
            ] );
            register_block_type( $block_name, $args );
        }
    }

    /**
     * Enqueue the editor JS that registers the React block components.
     * Loaded only on block-editor screens (not on every admin page).
     * The same Pro+ gate applies — non-Pro+ users don't even see the
     * editor script.
     */
    public static function enqueue_editor_assets(): void {
        if ( ! class_exists( License_Manager::class ) || ! License_Manager::can_use( 'schema_blocks_5' ) ) {
            return;
        }
        $rel_path = 'assets/js/schema-blocks.js';
        $abs_path = SEOBETTER_PLUGIN_DIR . $rel_path;
        $version  = file_exists( $abs_path ) ? (string) filemtime( $abs_path ) : SEOBETTER_VERSION;
        wp_enqueue_script(
            'seobetter-schema-blocks',
            plugins_url( $rel_path, SEOBETTER_PLUGIN_FILE ),
            [ 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-i18n', 'wp-server-side-render' ],
            $version,
            true
        );
    }

    /**
     * Build the WordPress block attributes schema from the manager's
     * field-defs. Mirrors attrsFromDefs() in schema-blocks.js so server
     * and client agree.
     *
     * @param array $field_defs Per-field definition map.
     * @return array Attributes array suitable for register_block_type.
     */
    private static function build_attributes_from_field_defs( array $field_defs ): array {
        $attrs = [
            'enabled' => [ 'type' => 'boolean', 'default' => true ],
        ];
        foreach ( $field_defs as $key => $def ) {
            $type = 'string';
            $default = '';
            if ( ( $def['type'] ?? '' ) === 'checkbox' ) {
                $type = 'boolean';
                $default = false;
            } elseif ( ( $def['type'] ?? '' ) === 'number' ) {
                $type = 'number';
                $default = 0;
            }
            $attrs[ $key ] = [ 'type' => $type, 'default' => $default ];
        }
        return $attrs;
    }

    /**
     * Block render callback — hands off to Schema_Blocks_Manager which
     * already has the styled-card render methods (built in v62.24).
     *
     * Returns empty string when:
     *   - The block is disabled (toggle off)
     *   - Required fields are missing (per build_jsonld validation)
     *
     * Empty string means the block renders nothing — same fail-closed
     * behavior as the JSON-LD path. Never half-rendered cards.
     *
     * @param string $manager_key Schema_Blocks_Manager key (product / event / ...)
     * @param array  $attrs       Block attributes from Gutenberg.
     * @return string HTML for the styled card, or empty string.
     */
    public static function render_block( string $manager_key, array $attrs ): string {
        // Toggle-off → render nothing (block exists in post_content but
        // is dormant — schema not emitted, card not shown).
        if ( isset( $attrs['enabled'] ) && ! $attrs['enabled'] ) {
            return '';
        }
        return Schema_Blocks_Manager::render_html( $manager_key, $attrs );
    }

    /**
     * Walk a post's post_content for seobetter/* blocks and collect
     * their attribute arrays keyed by manager_key. Used by
     * Schema_Blocks_Manager::build_all_jsonld() to emit JSON-LD for the
     * block instances on a post.
     *
     * Returns a list (NOT keyed) since a single post can have multiple
     * blocks of the same type (e.g. 5 Product blocks in a buying guide).
     * Each entry is `[ 'type' => 'product', 'attrs' => [...] ]`.
     *
     * @param int $post_id WordPress post ID.
     * @return array<int, array{type: string, attrs: array}>
     */
    public static function collect_blocks_from_post( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post ) return [];
        if ( empty( $post->post_content ) ) return [];
        if ( ! function_exists( 'parse_blocks' ) ) return [];

        $parsed = parse_blocks( $post->post_content );
        $out = [];
        self::walk_blocks_recursive( $parsed, $out );
        return $out;
    }

    /**
     * Recursive walker — handles nested blocks (Group / Columns / etc.
     * that may contain a SEOBetter schema block as inner-block).
     */
    private static function walk_blocks_recursive( array $blocks, array &$out ): void {
        foreach ( $blocks as $block ) {
            $name = $block['blockName'] ?? '';
            if ( $name && isset( self::BLOCK_NAME_MAP[ $name ] ) ) {
                $manager_key = self::BLOCK_NAME_MAP[ $name ];
                $attrs       = $block['attrs'] ?? [];
                if ( isset( $attrs['enabled'] ) && ! $attrs['enabled'] ) continue;
                $out[] = [ 'type' => $manager_key, 'attrs' => $attrs ];
            }
            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                self::walk_blocks_recursive( $block['innerBlocks'], $out );
            }
        }
    }
}
