<?php

namespace SEOBetter;

/**
 * Bulk Content Generator.
 *
 * Generates multiple articles from a CSV or keyword list.
 *
 * v1.5.216.28 — Phase 1 item 9: full UX layer rewrite.
 *
 * Five deliverables per locked plan:
 *   - PRESETS — save/load named configurations (Agency runs 50+ batches/month;
 *     repeating the same defaults each time is friction-heavy)
 *   - PER-ROW OVERRIDE — already worked at the parser level; UI now visualises
 *     which CSV columns will override which preset/default value
 *   - ACTION SCHEDULER QUEUE — when AS is present (via WooCommerce or
 *     standalone), enqueue items as async jobs so the user can close the
 *     browser. Falls back to the existing AJAX-polled flow when AS is absent
 *   - GEO 40 FLOOR — items scoring below 40 get marked `failed_quality` and
 *     the post is NOT saved. Prevents hallucinated/junk articles from
 *     polluting the user's CMS during 50-keyword overnight runs
 *   - DEFAULT-TO-DRAFT — already in place (`'post_status' => 'draft'`); now
 *     surfaced as an explicit toggle so users know auto-publish is opt-in
 *
 * Tier: Agency only ($179/mo) — `bulk_content_generation` feature in
 * License_Manager::AGENCY_FEATURES.
 *
 * Storage:
 *   - Batches: `seobetter_bulk_batch_{timestamp}` option (one per batch)
 *   - Presets: `seobetter_bulk_presets` option (JSON array keyed by preset_id)
 */
class Bulk_Generator {

    private const OPTION_PREFIX  = 'seobetter_bulk_batch_';
    private const PRESETS_OPTION = 'seobetter_bulk_presets';
    private const MAX_KEYWORDS   = 100;

    /**
     * GEO score floor — items below this don't get saved as drafts.
     * Set deliberately at 40 (F-grade boundary in the existing rubric) so
     * that anything garbage-tier is rejected but borderline articles still
     * land for the user to review.
     */
    public const QUALITY_FLOOR = 40;

    /**
     * Action Scheduler hook name. Used when AS is available.
     */
    public const AS_HOOK = 'seobetter_bulk_process_item';

    /**
     * Action Scheduler group — keeps SEOBetter jobs visible in the AS admin
     * UI under their own group filter rather than mixed with WC jobs.
     */
    public const AS_GROUP = 'seobetter-bulk';

    /**
     * Parse a CSV file into keyword rows.
     */
    public function parse_csv( string $file_path ): array {
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return [ 'success' => false, 'error' => 'File not found or not readable.' ];
        }

        $rows = [];
        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return [ 'success' => false, 'error' => 'Could not open file.' ];
        }

        $header = fgetcsv( $handle );
        if ( ! $header ) {
            fclose( $handle );
            return [ 'success' => false, 'error' => 'Empty CSV file.' ];
        }

        $header = array_map( 'strtolower', array_map( 'trim', $header ) );

        while ( ( $row = fgetcsv( $handle ) ) !== false && count( $rows ) < self::MAX_KEYWORDS ) {
            $data = array_combine( $header, array_pad( $row, count( $header ), '' ) );
            $keyword = trim( $data['keyword'] ?? '' );
            if ( empty( $keyword ) ) continue;

            // Track which columns the CSV ACTUALLY supplied per row — drives
            // the UI per-row-override visualisation (column present = override).
            $overrides = [];
            foreach ( [ 'word_count', 'tone', 'domain', 'content_type', 'country', 'language', 'secondary_keywords' ] as $col ) {
                if ( isset( $data[ $col ] ) && $data[ $col ] !== '' ) {
                    $overrides[] = $col;
                }
            }

            $rows[] = [
                'keyword'            => sanitize_text_field( $keyword ),
                'secondary_keywords' => sanitize_text_field( $data['secondary_keywords'] ?? '' ),
                'word_count'         => absint( $data['word_count'] ?? 2000 ) ?: 2000,
                'tone'               => sanitize_text_field( $data['tone'] ?? 'authoritative' ),
                'domain'             => sanitize_text_field( $data['domain'] ?? 'general' ),
                'content_type'       => sanitize_text_field( $data['content_type'] ?? 'blog_post' ),
                'country'            => sanitize_text_field( $data['country'] ?? '' ),
                // v1.5.192 — optional per-row language (falls back to 'en')
                'language'           => sanitize_text_field( $data['language'] ?? 'en' ),
                // v1.5.216.28 — track what this row overrides for UI display
                '_csv_overrides'     => $overrides,
            ];
        }

        fclose( $handle );

        return [ 'success' => true, 'rows' => $rows, 'count' => count( $rows ) ];
    }

    /**
     * Parse a textarea of keywords (one per line).
     */
    public function parse_textarea( string $text ): array {
        $lines = array_filter( array_map( 'trim', explode( "\n", $text ) ) );
        $rows = [];

        foreach ( $lines as $line ) {
            if ( count( $rows ) >= self::MAX_KEYWORDS ) break;
            $rows[] = [
                'keyword'            => sanitize_text_field( $line ),
                'secondary_keywords' => '',
                'word_count'         => 2000,
                'tone'               => 'authoritative',
                'domain'             => 'general',
                '_csv_overrides'     => [], // textarea keywords have no per-row overrides
            ];
        }

        return $rows;
    }

    /**
     * Create a batch job from keywords.
     *
     * @param array $keywords Parsed rows from parse_csv() / parse_textarea()
     * @param array $defaults Page-form defaults — applied when row doesn't override
     *   Recognised keys: word_count, tone, domain, content_type, country,
     *   language, secondary_keywords, auto_publish (bool), quality_floor (int)
     * @return int Batch ID (timestamp)
     */
    public function create_batch( array $keywords, array $defaults = [] ): int {
        $batch_id = time();

        // v1.5.216.28 — auto-publish is OPT-IN; default false (saves as draft).
        // Per-row CSV column `status` could override but we keep the toggle
        // page-wide for simplicity (Agency users either trust the run or don't).
        $auto_publish  = (bool) ( $defaults['auto_publish'] ?? false );
        $quality_floor = (int) ( $defaults['quality_floor'] ?? self::QUALITY_FLOOR );

        $items = [];
        foreach ( $keywords as $kw ) {
            $items[] = [
                'keyword'            => $kw['keyword'],
                'secondary_keywords' => $kw['secondary_keywords'] ?? $defaults['secondary_keywords'] ?? '',
                'word_count'         => $kw['word_count'] ?? $defaults['word_count'] ?? 2000,
                'tone'               => $kw['tone'] ?? $defaults['tone'] ?? 'authoritative',
                'domain'             => $kw['domain'] ?? $defaults['domain'] ?? 'general',
                'content_type'       => $kw['content_type'] ?? $defaults['content_type'] ?? 'blog_post',
                'country'            => $kw['country'] ?? $defaults['country'] ?? '',
                'language'           => $kw['language'] ?? $defaults['language'] ?? 'en',
                '_csv_overrides'     => $kw['_csv_overrides'] ?? [],
                'status'             => 'pending',
                'post_id'            => null,
                'post_title'         => null,
                'geo_score'          => null,
                'error'              => null,
            ];
        }

        $batch = [
            'id'             => $batch_id,
            'created'        => current_time( 'mysql' ),
            'status'         => 'pending',
            'total'          => count( $items ),
            'completed'      => 0,
            'failed'         => 0,
            'failed_quality' => 0, // v1.5.216.28 — separate counter for quality-gate rejections
            'items'          => $items,
            'auto_publish'   => $auto_publish,
            'quality_floor'  => $quality_floor,
            'preset_id'      => sanitize_key( $defaults['preset_id'] ?? '' ),
            // Track whether AS was used so the UI can show "queued in
            // background" vs "browser-driven" appropriately
            'queue_mode'     => self::has_action_scheduler() ? 'action_scheduler' : 'ajax',
        ];

        update_option( self::OPTION_PREFIX . $batch_id, $batch, false );

        // v1.5.216.28 — When Action Scheduler is available, enqueue all items
        // as separate async jobs so the user can close the browser tab.
        // Otherwise the AJAX-polled UI keeps driving processing as before.
        if ( self::has_action_scheduler() ) {
            $this->enqueue_batch_to_action_scheduler( $batch_id );
        }

        return $batch_id;
    }

    /**
     * Process the next pending item in a batch.
     *
     * Called either by the AJAX-polled UI (when AS isn't available) or by
     * the registered Action Scheduler hook (when AS is). Same logic both
     * paths — keeps the surface area minimal.
     */
    public function process_next( int $batch_id ): array {
        if ( ! License_Manager::can_use( 'bulk_content_generation' ) ) {
            return [ 'success' => false, 'error' => __( 'Bulk generation requires SEOBetter Agency ($179/mo).', 'seobetter' ) ];
        }

        $batch = $this->get_batch( $batch_id );
        if ( ! $batch ) {
            return [ 'success' => false, 'error' => 'Batch not found.' ];
        }

        // Find next pending item
        $next_index = null;
        foreach ( $batch['items'] as $i => $item ) {
            if ( $item['status'] === 'pending' ) {
                $next_index = $i;
                break;
            }
        }

        if ( $next_index === null ) {
            $batch['status'] = 'completed';
            update_option( self::OPTION_PREFIX . $batch_id, $batch, false );
            return [ 'success' => true, 'done' => true, 'batch' => $batch ];
        }

        // Mark as processing
        $batch['items'][ $next_index ]['status'] = 'processing';
        $batch['status'] = 'processing';
        update_option( self::OPTION_PREFIX . $batch_id, $batch, false );

        $item = $batch['items'][ $next_index ];

        try {
            $secondary = array_filter( array_map( 'trim', explode( ',', $item['secondary_keywords'] ) ) );

            // Step 1: Start the job
            $start = Async_Generator::start_job( [
                'primary_keyword'    => $item['keyword'],
                'secondary_keywords' => implode( ', ', $secondary ),
                'word_count'         => $item['word_count'],
                'tone'               => $item['tone'] ?? 'authoritative',
                'domain'             => $item['domain'] ?? 'general',
                'content_type'       => $item['content_type'] ?? 'blog_post',
                'accent_color'       => '#764ba2',
                'country'            => $item['country'] ?? '',
                'language'           => $item['language'] ?? 'en',
            ] );

            if ( empty( $start['success'] ) ) {
                throw new \RuntimeException( $start['error'] ?? 'Failed to start job.' );
            }

            $job_id = $start['job_id'];

            // Step 2: Run all steps sequentially
            $max_steps = 30;
            for ( $step = 0; $step < $max_steps; $step++ ) {
                $step_result = Async_Generator::process_step( $job_id );
                if ( ! empty( $step_result['done'] ) ) break;
                if ( ! empty( $step_result['error'] ) && empty( $step_result['can_retry'] ) ) {
                    throw new \RuntimeException( $step_result['error'] );
                }
            }

            // Step 3: Get the final result
            $result = Async_Generator::get_result( $job_id );

            if ( ! empty( $result['success'] ) ) {
                $geo_score = (int) ( $result['geo_score'] ?? 0 );
                $quality_floor = (int) ( $batch['quality_floor'] ?? self::QUALITY_FLOOR );

                // v1.5.216.28 — GEO 40 floor: reject low-quality output instead
                // of polluting the CMS. Article still gets a result row with
                // the score so the user can see WHY it was rejected.
                if ( $geo_score < $quality_floor ) {
                    $batch['items'][ $next_index ]['status']    = 'failed_quality';
                    $batch['items'][ $next_index ]['geo_score'] = $geo_score;
                    $batch['items'][ $next_index ]['error']     = sprintf(
                        /* translators: 1: actual GEO score, 2: quality floor */
                        __( 'Quality gate: GEO score %1$d below floor %2$d. Article not saved — try regenerating with different keyword phrasing or content type.', 'seobetter' ),
                        $geo_score,
                        $quality_floor
                    );
                    $batch['failed_quality'] = (int) ( $batch['failed_quality'] ?? 0 ) + 1;
                    $batch['failed']++;
                } else {
                    // Quality OK — save the draft (or publish if auto_publish toggle ON)
                    $plugin_instance = \SEOBetter::get_instance();
                    $markdown = $result['markdown'] ?? '';
                    $content_html = $result['content'] ?? '';
                    $accent = '#764ba2';

                    if ( ! empty( $markdown ) ) {
                        $markdown = \SEOBetter::cleanup_ai_markdown( $markdown );
                        $combined_pool = $result['citation_pool'] ?? [];
                        if ( ! empty( $combined_pool ) ) {
                            $markdown = \SEOBetter::linkify_bracketed_references( $markdown, $combined_pool );
                        }
                        $formatter = new Content_Formatter();
                        $content_html = $formatter->format( $markdown, 'hybrid', [
                            'accent_color' => $accent,
                            'content_type' => $item['content_type'] ?? 'blog_post',
                            'language'     => $item['language'] ?? 'en',
                        ] );
                    }

                    // v1.5.216.62.83 — sanitize the headline through the same
                    // pipeline as rest_save_draft. Pre-fix raw $result['headlines'][0]
                    // shipped straight into post_title, so every v62.79-82 fix
                    // (citation-echo detection, ellipsis strip, middle-dot replace,
                    // 60-char cap, brand_caps) was bypassed for Bulk Generate.
                    $raw_title       = $result['headlines'][0] ?? $item['keyword'];
                    $citation_pool   = $result['citation_pool'] ?? [];
                    $sanitized_title = \SEOBetter::sanitize_headline(
                        (string) $raw_title,
                        (string) ( $item['keyword'] ?? '' ),
                        is_array( $citation_pool ) ? $citation_pool : []
                    );
                    $post_title = sanitize_text_field( $sanitized_title );

                    // v1.5.216.28 — auto_publish toggle. Default false → draft.
                    $post_status = ! empty( $batch['auto_publish'] ) ? 'publish' : 'draft';

                    $post_id = wp_insert_post( [
                        'post_title'   => $post_title,
                        'post_content' => $content_html,
                        'post_status'  => $post_status,
                        'post_type'    => 'post',
                    ] );

                    if ( $post_id && ! is_wp_error( $post_id ) ) {
                        update_post_meta( $post_id, '_seobetter_focus_keyword', $item['keyword'] );
                        update_post_meta( $post_id, '_seobetter_geo_score', $result['geo_score'] ?? 0 );
                        update_post_meta( $post_id, '_seobetter_content_type', $item['content_type'] ?? 'blog_post' );
                        if ( ! empty( $item['country'] ) ) {
                            update_post_meta( $post_id, '_seobetter_country', sanitize_text_field( $item['country'] ) );
                        }
                        if ( ! empty( $item['language'] ) ) {
                            update_post_meta( $post_id, '_seobetter_language', sanitize_text_field( $item['language'] ) );
                        }
                    }

                    $batch['items'][ $next_index ]['status']     = 'completed';
                    $batch['items'][ $next_index ]['post_id']    = $post_id;
                    $batch['items'][ $next_index ]['post_title'] = $post_title;
                    $batch['items'][ $next_index ]['geo_score']  = $geo_score;
                    $batch['completed']++;
                }
            } else {
                $batch['items'][ $next_index ]['status'] = 'failed';
                $batch['items'][ $next_index ]['error'] = $result['error'] ?? 'Generation failed.';
                $batch['failed']++;
            }
        } catch ( \Throwable $e ) {
            $batch['items'][ $next_index ]['status'] = 'failed';
            $batch['items'][ $next_index ]['error'] = $e->getMessage();
            $batch['failed']++;
        }

        // Check if all done
        $pending = count( array_filter( $batch['items'], fn( $i ) => $i['status'] === 'pending' ) );
        if ( $pending === 0 ) {
            $batch['status'] = 'completed';
        }

        update_option( self::OPTION_PREFIX . $batch_id, $batch, false );

        $progress = round( ( $batch['completed'] + $batch['failed'] ) / max( 1, $batch['total'] ) * 100 );
        $items_with_urls = array_map( function ( $it ) {
            if ( ! empty( $it['post_id'] ) ) {
                $it['edit_url'] = get_edit_post_link( $it['post_id'], 'raw' );
            }
            return $it;
        }, $batch['items'] );

        return [
            'success'        => true,
            'done'           => $pending === 0,
            'status'         => $batch['status'],
            'progress'       => $progress,
            'items'          => $items_with_urls,
            'remaining'      => $pending,
            'completed'      => $batch['completed'],
            'failed'         => $batch['failed'],
            'failed_quality' => (int) ( $batch['failed_quality'] ?? 0 ),
            'queue_mode'     => $batch['queue_mode'] ?? 'ajax',
        ];
    }

    /**
     * Get a batch by ID.
     */
    public function get_batch( int $batch_id ): ?array {
        $batch = get_option( self::OPTION_PREFIX . $batch_id );
        return is_array( $batch ) ? $batch : null;
    }

    /**
     * Get all recent batches.
     */
    public function get_all_batches(): array {
        global $wpdb;

        $rows = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options}
            WHERE option_name LIKE 'seobetter_bulk_batch_%'
            ORDER BY option_name DESC
            LIMIT 20"
        );

        $batches = [];
        foreach ( $rows as $name ) {
            $batch = get_option( $name );
            if ( is_array( $batch ) ) {
                $batches[] = $batch;
            }
        }

        return $batches;
    }

    /**
     * Delete a batch.
     */
    public function delete_batch( int $batch_id ): bool {
        return delete_option( self::OPTION_PREFIX . $batch_id );
    }

    /**
     * Generate sample CSV content.
     */
    public static function sample_csv(): string {
        return "keyword,secondary_keywords,word_count,tone,domain,content_type,country,language\n"
             . "\"best running shoes\",\"running sneakers, jogging shoes\",2000,authoritative,ecommerce,buying_guide,US,en\n"
             . "\"how to train for a marathon\",\"marathon training plan, running schedule\",2500,educational,health,how_to,,en\n"
             . "\"running shoe reviews 2026\",\"top running shoes, shoe comparison\",2000,journalistic,ecommerce,review,US,en\n";
    }

    // ====================================================================
    // v1.5.216.28 — PRESETS CRUD (Phase 1 item 9)
    //
    // Agency users running 50+ keyword batches every week shouldn't have to
    // re-pick word_count / tone / domain / content_type / country / language
    // each time. Presets save these as named configurations applied on next
    // batch start.
    // ====================================================================

    /**
     * Get all saved presets.
     *
     * @return array<string, array> Map of preset_id → preset data.
     */
    public static function get_presets(): array {
        $presets = get_option( self::PRESETS_OPTION, [] );
        return is_array( $presets ) ? $presets : [];
    }

    /**
     * Get a single preset by id. Returns null if not found.
     */
    public static function get_preset( string $preset_id ): ?array {
        $all = self::get_presets();
        return $all[ $preset_id ] ?? null;
    }

    /**
     * Save (create or update) a preset.
     *
     * @param string $preset_id  '' to create new (auto-generates id); existing id to update
     * @param array  $data       ['name', 'word_count', 'tone', 'domain', 'content_type', 'country', 'language', 'auto_publish']
     * @return array             ['success' => bool, 'preset_id' => string, 'error' => string?]
     */
    public static function save_preset( string $preset_id, array $data ): array {
        $name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
        if ( $name === '' ) {
            return [ 'success' => false, 'error' => __( 'Preset name is required.', 'seobetter' ) ];
        }

        $all = self::get_presets();
        $is_new = ( $preset_id === '' || ! isset( $all[ $preset_id ] ) );

        if ( $is_new ) {
            $preset_id  = self::generate_preset_id();
            $created_at = time();
        } else {
            $created_at = (int) ( $all[ $preset_id ]['created_at'] ?? time() );
        }

        $preset = [
            'id'           => $preset_id,
            'name'         => $name,
            'word_count'   => absint( $data['word_count'] ?? 2000 ) ?: 2000,
            'tone'         => sanitize_text_field( $data['tone'] ?? 'authoritative' ),
            'domain'       => sanitize_text_field( $data['domain'] ?? 'general' ),
            'content_type' => sanitize_text_field( $data['content_type'] ?? 'blog_post' ),
            'country'      => sanitize_text_field( $data['country'] ?? '' ),
            'language'     => sanitize_text_field( $data['language'] ?? 'en' ),
            'auto_publish' => (bool) ( $data['auto_publish'] ?? false ),
            'created_at'   => $created_at,
            'updated_at'   => time(),
        ];

        $all[ $preset_id ] = $preset;
        update_option( self::PRESETS_OPTION, $all, false );

        return [ 'success' => true, 'preset_id' => $preset_id ];
    }

    /**
     * Delete a preset by id.
     */
    public static function delete_preset( string $preset_id ): bool {
        $all = self::get_presets();
        if ( ! isset( $all[ $preset_id ] ) ) return false;
        unset( $all[ $preset_id ] );
        update_option( self::PRESETS_OPTION, $all, false );
        return true;
    }

    /**
     * Generate a stable, URL-safe preset id.
     */
    private static function generate_preset_id(): string {
        return 'p_' . substr( wp_generate_uuid4(), 0, 8 );
    }

    // ====================================================================
    // v1.5.216.28 — ACTION SCHEDULER INTEGRATION (Phase 1 item 9)
    //
    // When Action Scheduler is available (via WooCommerce, GravityForms, or
    // standalone install), enqueue items as async jobs so the user can close
    // the browser tab during 50-keyword overnight runs. Falls back to the
    // existing AJAX-polled flow when AS is absent.
    //
    // The fallback isn't a feature regression — the AJAX flow already worked
    // and is the only path for users without AS. We just add the better path
    // when available.
    // ====================================================================

    /**
     * Whether Action Scheduler is available on this site.
     * AS exposes `as_enqueue_async_action()` as its public API surface.
     */
    public static function has_action_scheduler(): bool {
        return function_exists( 'as_enqueue_async_action' );
    }

    /**
     * Register the Action Scheduler callback. Called once at plugin boot.
     * Each AS job receives `$batch_id` and processes one item from the
     * batch — the next-pending finder in process_next() handles which one.
     */
    public static function register_action_scheduler_hook(): void {
        if ( ! self::has_action_scheduler() ) return;
        add_action( self::AS_HOOK, [ __CLASS__, 'as_handle_item' ], 10, 1 );
    }

    /**
     * AS callback: process one pending item from the batch, then schedule
     * the next iteration if more items remain. This pattern (re-enqueue on
     * completion) avoids enqueuing N jobs upfront and keeps the AS queue
     * shallow + cancelable.
     */
    public static function as_handle_item( int $batch_id ): void {
        $bulk = new self();
        $result = $bulk->process_next( $batch_id );
        if ( empty( $result['done'] ) && self::has_action_scheduler() ) {
            // Re-enqueue with a small delay so failures don't hammer the
            // generator's REST endpoint and consume rate-limit budget.
            as_schedule_single_action( time() + 5, self::AS_HOOK, [ $batch_id ], self::AS_GROUP );
        }
    }

    /**
     * Enqueue the first item of a newly-created batch. Subsequent items get
     * scheduled by `as_handle_item()` itself once the previous completes.
     */
    private function enqueue_batch_to_action_scheduler( int $batch_id ): void {
        if ( ! self::has_action_scheduler() ) return;
        as_enqueue_async_action( self::AS_HOOK, [ $batch_id ], self::AS_GROUP );
    }
}
