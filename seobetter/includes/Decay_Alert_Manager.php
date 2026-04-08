<?php

namespace SEOBetter;

/**
 * Content Decay Alert Manager.
 *
 * Sends email notifications when posts become stale or GEO scores drop.
 * Runs via WordPress cron (weekly check).
 *
 * Pro feature.
 */
class Decay_Alert_Manager {

    private const CRON_HOOK = 'seobetter_decay_check';
    private const OPTION_KEY = 'seobetter_decay_alerts';

    /**
     * Schedule the weekly cron check.
     */
    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'weekly', self::CRON_HOOK );
        }
    }

    /**
     * Unschedule on deactivation.
     */
    public static function unschedule(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * Run the decay check (called by cron).
     */
    public function run_check(): array {
        $settings = get_option( 'seobetter_settings', [] );
        $alerts_enabled = $settings['decay_alerts'] ?? true;

        if ( ! $alerts_enabled ) {
            return [];
        }

        $stale_posts = $this->find_stale_posts();
        $dropped_posts = $this->find_score_drops();

        $alerts = array_merge( $stale_posts, $dropped_posts );

        if ( ! empty( $alerts ) ) {
            $this->send_alert_email( $alerts );
        }

        // Store last check results
        update_option( self::OPTION_KEY, [
            'last_check' => current_time( 'mysql' ),
            'alerts'     => $alerts,
        ] );

        return $alerts;
    }

    /**
     * Find posts not updated in 6+ months.
     */
    public function find_stale_posts( int $months = 6 ): array {
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months" ) );

        $posts = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'date_query'     => [
                [ 'column' => 'post_modified', 'before' => $cutoff ],
            ],
            'orderby'        => 'modified',
            'order'          => 'ASC',
        ] );

        $stale = [];
        foreach ( $posts as $post ) {
            $days_old = floor( ( time() - strtotime( $post->post_modified ) ) / DAY_IN_SECONDS );
            $stale[] = [
                'type'       => 'stale',
                'post_id'    => $post->ID,
                'title'      => $post->post_title,
                'url'        => get_permalink( $post->ID ),
                'last_updated' => $post->post_modified,
                'days_since' => $days_old,
                'severity'   => $days_old > 365 ? 'high' : 'medium',
                'message'    => sprintf( 'Not updated in %d days. Stale content loses AI citations over time.', $days_old ),
            ];
        }

        return $stale;
    }

    /**
     * Find posts whose GEO score has dropped (compare current vs stored).
     */
    public function find_score_drops(): array {
        $drops = [];

        $posts = get_posts( [
            'post_type'      => [ 'post', 'page' ],
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'meta_key'       => '_seobetter_geo_score',
        ] );

        foreach ( $posts as $post ) {
            $score_data = get_post_meta( $post->ID, '_seobetter_geo_score', true );
            if ( ! is_array( $score_data ) || ! isset( $score_data['geo_score'] ) ) {
                continue;
            }

            $current_score = $score_data['geo_score'];
            $previous_score = (int) get_post_meta( $post->ID, '_seobetter_previous_geo_score', true );

            // Store current as previous for next check
            update_post_meta( $post->ID, '_seobetter_previous_geo_score', $current_score );

            if ( $previous_score > 0 && $current_score < $previous_score - 10 ) {
                $drops[] = [
                    'type'           => 'score_drop',
                    'post_id'        => $post->ID,
                    'title'          => $post->post_title,
                    'url'            => get_permalink( $post->ID ),
                    'previous_score' => $previous_score,
                    'current_score'  => $current_score,
                    'drop'           => $previous_score - $current_score,
                    'severity'       => ( $previous_score - $current_score ) > 20 ? 'high' : 'medium',
                    'message'        => sprintf( 'GEO score dropped from %d to %d (-%d points).', $previous_score, $current_score, $previous_score - $current_score ),
                ];
            }
        }

        return $drops;
    }

    /**
     * Send alert email to site admin.
     */
    private function send_alert_email( array $alerts ): void {
        $admin_email = get_option( 'admin_email' );
        $site_name = get_bloginfo( 'name' );

        $stale_count = count( array_filter( $alerts, fn( $a ) => $a['type'] === 'stale' ) );
        $drop_count = count( array_filter( $alerts, fn( $a ) => $a['type'] === 'score_drop' ) );

        $subject = "[SEOBetter] Content alert: {$stale_count} stale posts, {$drop_count} score drops";

        $body = "SEOBetter Content Decay Report for {$site_name}\n";
        $body .= str_repeat( '=', 50 ) . "\n\n";

        if ( $stale_count > 0 ) {
            $body .= "STALE CONTENT ({$stale_count} posts)\n";
            $body .= str_repeat( '-', 30 ) . "\n";
            foreach ( $alerts as $alert ) {
                if ( $alert['type'] !== 'stale' ) continue;
                $body .= sprintf( "- %s (last updated %d days ago)\n  %s\n\n",
                    $alert['title'], $alert['days_since'], $alert['url']
                );
            }
        }

        if ( $drop_count > 0 ) {
            $body .= "\nGEO SCORE DROPS ({$drop_count} posts)\n";
            $body .= str_repeat( '-', 30 ) . "\n";
            foreach ( $alerts as $alert ) {
                if ( $alert['type'] !== 'score_drop' ) continue;
                $body .= sprintf( "- %s: %d → %d (-%d points)\n  %s\n\n",
                    $alert['title'], $alert['previous_score'], $alert['current_score'], $alert['drop'], $alert['url']
                );
            }
        }

        $body .= "\nView full report: " . admin_url( 'admin.php?page=seobetter-freshness' ) . "\n";
        $body .= "\n— SEOBetter Plugin";

        wp_mail( $admin_email, $subject, $body );
    }

    /**
     * Get the last alert check results.
     */
    public static function get_last_check(): array {
        return get_option( self::OPTION_KEY, [ 'last_check' => null, 'alerts' => [] ] );
    }
}
