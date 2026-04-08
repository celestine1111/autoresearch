<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$post_id = absint( $_GET['post_id'] ?? 0 );
$auditor = new SEOBetter\Technical_SEO_Auditor();

// Site-wide audit
$site_audit = $auditor->audit_site();

// Per-post audit if requested
$post_audit = null;
if ( $post_id ) {
    $post = get_post( $post_id );
    if ( $post ) {
        $post_audit = $auditor->audit_post( $post );
    }
}
?>
<div class="wrap seobetter-dashboard">
    <h1><?php esc_html_e( 'Technical SEO Audit', 'seobetter' ); ?></h1>

    <!-- Site-Wide Audit -->
    <div class="seobetter-cards">
        <div class="seobetter-card">
            <h2><?php esc_html_e( 'Site-Wide Technical Audit', 'seobetter' ); ?></h2>
            <div class="seobetter-score-card" style="text-align:center;margin-bottom:15px;">
                <div class="seobetter-score-circle <?php echo $site_audit['score'] >= 80 ? 'good' : ( $site_audit['score'] >= 60 ? 'ok' : 'poor' ); ?>">
                    <span class="score-number"><?php echo esc_html( $site_audit['score'] ); ?></span>
                    <span class="score-grade"><?php echo esc_html( $site_audit['passed'] . '/' . $site_audit['total'] ); ?></span>
                </div>
            </div>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Check', 'seobetter' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'seobetter' ); ?></th>
                        <th><?php esc_html_e( 'Details', 'seobetter' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $site_audit['checks'] as $key => $check ) :
                        $label = ucwords( str_replace( '_', ' ', $key ) );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $label ); ?></td>
                        <td>
                            <?php if ( $check['pass'] ) : ?>
                                <span class="dashicons dashicons-yes-alt" style="color:green"></span>
                            <?php else : ?>
                                <span class="dashicons dashicons-dismiss" style="color:red"></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $check['detail'] ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ( $post_audit ) : ?>
        <div class="seobetter-card">
            <h2><?php printf( esc_html__( 'Post Audit: %s', 'seobetter' ), esc_html( $post->post_title ) ); ?></h2>
            <p><strong><?php esc_html_e( 'Score:', 'seobetter' ); ?></strong>
                <span class="seobetter-score seobetter-score-<?php echo $post_audit['score'] >= 80 ? 'good' : ( $post_audit['score'] >= 60 ? 'ok' : 'poor' ); ?>">
                    <?php echo esc_html( $post_audit['score'] ); ?>
                </span>
                (<?php echo esc_html( $post_audit['passed'] . '/' . $post_audit['total'] . ' passed' ); ?>)
            </p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Check', 'seobetter' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'seobetter' ); ?></th>
                        <th><?php esc_html_e( 'Details', 'seobetter' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $post_audit['checks'] as $key => $check ) :
                        $label = ucwords( str_replace( '_', ' ', $key ) );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $label ); ?></td>
                        <td>
                            <?php if ( $check['pass'] ) : ?>
                                <span class="dashicons dashicons-yes-alt" style="color:green"></span>
                            <?php else : ?>
                                <span class="dashicons dashicons-dismiss" style="color:red"></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $check['detail'] ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
