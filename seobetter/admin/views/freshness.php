<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$manager = new SEOBetter\Content_Freshness_Manager();
$report = $manager->get_freshness_report();
?>
<div class="wrap seobetter-dashboard">
    <h1><?php esc_html_e( 'Content Freshness Manager', 'seobetter' ); ?></h1>
    <p class="description"><?php esc_html_e( 'Strategy: refresh 1 post per 5 new posts. Only update content 1+ year old. Freshness is a critical AI citation tiebreaker.', 'seobetter' ); ?></p>

    <div class="seobetter-cards">
        <!-- Summary -->
        <div class="seobetter-card" style="text-align:center">
            <h2><?php esc_html_e( 'Overview', 'seobetter' ); ?></h2>
            <div style="display:flex;justify-content:space-around;padding:20px 0">
                <div>
                    <div style="font-size:36px;font-weight:700;color:#dc3545"><?php echo esc_html( $report['stale_count'] ); ?></div>
                    <div><?php esc_html_e( 'Stale (1yr+)', 'seobetter' ); ?></div>
                </div>
                <div>
                    <div style="font-size:36px;font-weight:700;color:#ffc107"><?php echo esc_html( $report['warning_count'] ); ?></div>
                    <div><?php esc_html_e( 'Aging (6mo+)', 'seobetter' ); ?></div>
                </div>
                <div>
                    <div style="font-size:36px;font-weight:700;color:#28a745"><?php echo esc_html( $report['fresh_count'] ); ?></div>
                    <div><?php esc_html_e( 'Fresh', 'seobetter' ); ?></div>
                </div>
            </div>
        </div>

        <!-- Stale Content -->
        <?php if ( ! empty( $report['stale'] ) ) : ?>
        <div class="seobetter-card">
            <h2 style="color:#dc3545"><?php esc_html_e( 'Needs Refresh (1+ year old)', 'seobetter' ); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Post', 'seobetter' ); ?></th>
                        <th><?php esc_html_e( 'Last Modified', 'seobetter' ); ?></th>
                        <th><?php esc_html_e( 'Days', 'seobetter' ); ?></th>
                        <th><?php esc_html_e( 'Words', 'seobetter' ); ?></th>
                        <th><?php esc_html_e( 'Freshness Signal', 'seobetter' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'seobetter' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $report['stale'] as $item ) : ?>
                    <tr>
                        <td><a href="<?php echo esc_url( get_edit_post_link( $item['id'] ) ); ?>"><?php echo esc_html( $item['title'] ); ?></a></td>
                        <td><?php echo esc_html( date( 'M j, Y', strtotime( $item['last_modified'] ) ) ); ?></td>
                        <td><strong style="color:#dc3545"><?php echo esc_html( $item['days_since'] ); ?>d</strong></td>
                        <td><?php echo esc_html( number_format( $item['word_count'] ) ); ?></td>
                        <td>
                            <?php if ( $item['has_freshness_signal'] ) : ?>
                                <span class="dashicons dashicons-yes-alt" style="color:green"></span>
                            <?php else : ?>
                                <span class="dashicons dashicons-dismiss" style="color:red"></span>
                            <?php endif; ?>
                        </td>
                        <td><a href="<?php echo esc_url( get_edit_post_link( $item['id'] ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'seobetter' ); ?></a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Warning Content -->
        <?php if ( ! empty( $report['warning'] ) ) : ?>
        <div class="seobetter-card">
            <h2 style="color:#ffc107"><?php esc_html_e( 'Aging Content (6+ months)', 'seobetter' ); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Post', 'seobetter' ); ?></th>
                        <th><?php esc_html_e( 'Last Modified', 'seobetter' ); ?></th>
                        <th><?php esc_html_e( 'Days', 'seobetter' ); ?></th>
                        <th><?php esc_html_e( 'Words', 'seobetter' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $report['warning'] as $item ) : ?>
                    <tr>
                        <td><a href="<?php echo esc_url( get_edit_post_link( $item['id'] ) ); ?>"><?php echo esc_html( $item['title'] ); ?></a></td>
                        <td><?php echo esc_html( date( 'M j, Y', strtotime( $item['last_modified'] ) ) ); ?></td>
                        <td><strong style="color:#ffc107"><?php echo esc_html( $item['days_since'] ); ?>d</strong></td>
                        <td><?php echo esc_html( number_format( $item['word_count'] ) ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
