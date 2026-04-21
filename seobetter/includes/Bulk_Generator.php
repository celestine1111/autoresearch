<?php

namespace SEOBetter;

/**
 * Bulk Content Generator.
 *
 * Generates multiple articles from a CSV or keyword list.
 * Processes one article at a time via AJAX to avoid timeouts.
 *
 * Pro feature.
 */
class Bulk_Generator {

    private const OPTION_PREFIX = 'seobetter_bulk_batch_';
    private const MAX_KEYWORDS = 100;

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

            $rows[] = [
                'keyword'            => sanitize_text_field( $keyword ),
                'secondary_keywords' => sanitize_text_field( $data['secondary_keywords'] ?? '' ),
                'word_count'         => absint( $data['word_count'] ?? 2000 ) ?: 2000,
                'tone'               => sanitize_text_field( $data['tone'] ?? 'authoritative' ),
                'domain'             => sanitize_text_field( $data['domain'] ?? 'general' ),
                'content_type'       => sanitize_text_field( $data['content_type'] ?? 'blog_post' ),
                'country'            => sanitize_text_field( $data['country'] ?? '' ),
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
            ];
        }

        return $rows;
    }

    /**
     * Create a batch job from keywords.
     */
    public function create_batch( array $keywords, array $defaults = [] ): int {
        $batch_id = time();

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
                'status'             => 'pending',
                'post_id'            => null,
                'post_title'         => null,
                'geo_score'          => null,
                'error'              => null,
            ];
        }

        $batch = [
            'id'        => $batch_id,
            'created'   => current_time( 'mysql' ),
            'status'    => 'pending',
            'total'     => count( $items ),
            'completed' => 0,
            'failed'    => 0,
            'items'     => $items,
        ];

        update_option( self::OPTION_PREFIX . $batch_id, $batch, false );

        return $batch_id;
    }

    /**
     * Process the next pending item in a batch.
     */
    public function process_next( int $batch_id ): array {
        if ( ! License_Manager::can_use( 'bulk_content_generation' ) ) {
            return [ 'success' => false, 'error' => 'Bulk generation requires SEOBetter Pro.' ];
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
            // v1.5.181 — Use Async_Generator pipeline (same as single article generation).
            // This gives bulk articles the full Serper+Firecrawl research, tables,
            // FAQ optimization, citation pool, readability enforcement, etc.
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
            ] );

            if ( empty( $start['success'] ) ) {
                throw new \RuntimeException( $start['error'] ?? 'Failed to start job.' );
            }

            $job_id = $start['job_id'];

            // Step 2: Run all steps sequentially (no polling — synchronous)
            $max_steps = 30; // Safety cap
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
                // Use rest_save_draft logic: format hybrid, validate links, build references
                $plugin = \SEOBetter::get_instance();
                $markdown = $result['markdown'] ?? '';
                $content_html = $result['content'] ?? '';
                $accent = '#764ba2';

                // Format as hybrid for Gutenberg
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
                    ] );
                }

                $post_title = $result['headlines'][0] ?? ucwords( $item['keyword'] );
                $post_id = wp_insert_post( [
                    'post_title'   => $post_title,
                    'post_content' => $content_html,
                    'post_status'  => 'draft',
                    'post_type'    => 'post',
                ] );

                if ( $post_id && ! is_wp_error( $post_id ) ) {
                    update_post_meta( $post_id, '_seobetter_focus_keyword', $item['keyword'] );
                    update_post_meta( $post_id, '_seobetter_geo_score', $result['geo_score'] ?? 0 );
                    update_post_meta( $post_id, '_seobetter_content_type', $item['content_type'] ?? 'blog_post' );
                }

                $batch['items'][ $next_index ]['status'] = 'completed';
                $batch['items'][ $next_index ]['post_id'] = $post_id;
                $batch['items'][ $next_index ]['post_title'] = $post_title;
                $batch['items'][ $next_index ]['geo_score'] = $result['geo_score'] ?? 0;
                $batch['completed']++;
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

        // Add edit_url to items for the JS table update
        $progress = round( ( $batch['completed'] + $batch['failed'] ) / max( 1, $batch['total'] ) * 100 );
        $items_with_urls = array_map( function ( $it ) {
            if ( ! empty( $it['post_id'] ) ) {
                $it['edit_url'] = get_edit_post_link( $it['post_id'], 'raw' );
            }
            return $it;
        }, $batch['items'] );

        return [
            'success'   => true,
            'done'      => $pending === 0,
            'status'    => $batch['status'],
            'progress'  => $progress,
            'items'     => $items_with_urls,
            'remaining' => $pending,
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
        return "keyword,secondary_keywords,word_count,tone,domain\n"
             . "\"best running shoes\",\"running sneakers, jogging shoes\",2000,authoritative,ecommerce\n"
             . "\"how to train for a marathon\",\"marathon training plan, running schedule\",2500,educational,health\n"
             . "\"running shoe reviews 2026\",\"top running shoes, shoe comparison\",2000,journalistic,ecommerce\n";
    }
}
