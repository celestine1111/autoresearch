<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$post_id = absint( $_GET['post_id'] ?? 0 );
$analysis = null;

if ( $post_id ) {
    $post = get_post( $post_id );
    if ( $post ) {
        $analyzer = new SEOBetter\GEO_Analyzer();
        $analysis = $analyzer->analyze( $post->post_content, $post->post_title );
    }
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'GEO Content Analyzer', 'seobetter' ); ?></h1>

    <?php if ( ! $post_id ) : ?>
        <p><?php esc_html_e( 'Select a post from the dashboard to analyze, or click "Analyze" on any post in the post list.', 'seobetter' ); ?></p>
    <?php elseif ( ! $analysis ) : ?>
        <div class="notice notice-error"><p><?php esc_html_e( 'Post not found.', 'seobetter' ); ?></p></div>
    <?php else : ?>
        <h2><?php echo esc_html( $post->post_title ); ?></h2>

        <div class="seobetter-cards">
            <!-- Overall Score -->
            <div class="seobetter-card seobetter-score-card">
                <div class="seobetter-score-circle <?php echo $analysis['geo_score'] >= 80 ? 'good' : ( $analysis['geo_score'] >= 60 ? 'ok' : 'poor' ); ?>">
                    <span class="score-number"><?php echo esc_html( $analysis['geo_score'] ); ?></span>
                    <span class="score-grade"><?php echo esc_html( $analysis['grade'] ); ?></span>
                </div>
                <p><?php printf( esc_html__( 'Word count: %s', 'seobetter' ), number_format( $analysis['word_count'] ) ); ?></p>
            </div>

            <!-- Checks Breakdown -->
            <div class="seobetter-card">
                <h3><?php esc_html_e( 'GEO Checks', 'seobetter' ); ?></h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Check', 'seobetter' ); ?></th>
                            <th><?php esc_html_e( 'Score', 'seobetter' ); ?></th>
                            <th><?php esc_html_e( 'Details', 'seobetter' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $analysis['checks'] as $key => $check ) :
                            $label = ucwords( str_replace( '_', ' ', $key ) );
                            $score_class = $check['score'] >= 80 ? 'good' : ( $check['score'] >= 60 ? 'ok' : 'poor' );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $label ); ?></td>
                            <td><span class="seobetter-score seobetter-score-<?php echo esc_attr( $score_class ); ?>"><?php echo esc_html( $check['score'] ); ?></span></td>
                            <td><?php echo esc_html( $check['detail'] ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Suggestions -->
            <?php if ( ! empty( $analysis['suggestions'] ) ) : ?>
            <div class="seobetter-card">
                <h3><?php esc_html_e( 'Optimization Suggestions', 'seobetter' ); ?></h3>
                <?php foreach ( $analysis['suggestions'] as $suggestion ) : ?>
                    <div class="seobetter-suggestion seobetter-suggestion-<?php echo esc_attr( $suggestion['priority'] ); ?>">
                        <span class="dashicons dashicons-<?php echo $suggestion['priority'] === 'high' ? 'warning' : 'info-outline'; ?>"></span>
                        <span class="seobetter-suggestion-type">[<?php echo esc_html( ucfirst( $suggestion['type'] ) ); ?>]</span>
                        <?php echo esc_html( $suggestion['message'] ); ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
