<?php if ( ! defined( 'ABSPATH' ) ) exit;

// v1.5.13 — gate uses can_use() instead of is_pro()
$is_pro = SEOBetter\License_Manager::can_use( 'cannibalization_detector' );
$results = null;

// Handle cannibalization scan
if ( isset( $_POST['seobetter_scan_cannibalization'] ) && check_admin_referer( 'seobetter_cannibalization_nonce' ) ) {
    if ( $is_pro ) {
        $detector = new SEOBetter\Cannibalization_Detector();
        $results = $detector->detect();
        // v1.5.13 — cache results so revisiting the page doesn't re-scan
        if ( ! empty( $results['success'] ) ) {
            $results['scanned_at'] = current_time( 'mysql' );
            update_option( 'seobetter_cannibalization_results', $results, false );
        }
    }
}

// Load cached results if no new scan
if ( ! $results ) {
    $results = get_option( 'seobetter_cannibalization_results', null );
}

$conflicts = $results['conflicts'] ?? [];
$total_conflicts = count( $conflicts );
$affected_posts = 0;
$seen_ids = [];
foreach ( $conflicts as $group ) {
    foreach ( $group['posts'] ?? [] as $p ) {
        // v1.5.13 — backend returns 'post_id', not 'id'
        $pid = $p['post_id'] ?? 0;
        if ( $pid && ! in_array( $pid, $seen_ids, true ) ) {
            $seen_ids[] = $pid;
            $affected_posts++;
        }
    }
}
?>
<div class="wrap seobetter-dashboard">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
        <div>
            <h1 style="margin:0;font-size:24px;font-weight:700;color:var(--sb-text,#1e293b)">Keyword Cannibalization Detector</h1>
            <p style="margin:4px 0 0;font-size:14px;color:var(--sb-text-secondary,#64748b)">Find posts competing for the same keywords and get recommendations to fix conflicts.</p>
        </div>
        <div style="display:flex;gap:10px;align-items:center">
            <span class="seobetter-score seobetter-score-<?php echo $is_pro ? 'good' : 'ok'; ?>" style="font-size:13px">
                <?php echo $is_pro ? 'PRO' : 'FREE'; ?>
            </span>
            <?php if ( ! $is_pro ) : ?>
                <a href="https://seobetter.com/pricing" target="_blank" class="button sb-btn-primary" style="height:36px;padding:6px 16px;font-size:13px;line-height:22px">
                    Unlock Pro Features &rarr;
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( ! $is_pro ) : ?>
    <div class="seobetter-card" style="padding:20px;background:var(--sb-primary-light,#f3eef8);border:2px solid var(--sb-primary,#764ba2);border-radius:8px;margin-bottom:24px">
        <h3 style="margin:0 0 8px;color:var(--sb-primary,#764ba2)">
            <span class="seobetter-score seobetter-score-good" style="background:var(--sb-primary,#764ba2);color:#fff;margin-right:6px">PRO</span>
            Cannibalization Detection requires Pro
        </h3>
        <p style="margin:0 0 16px;font-size:14px;color:var(--sb-text-secondary,#64748b)">Automatically detect posts competing for the same keywords, see similarity scores, and get actionable recommendations (merge, redirect, or differentiate).</p>
        <a href="https://seobetter.com/pricing" target="_blank" class="button sb-btn-primary" style="font-size:14px;height:44px;line-height:28px">
            Upgrade to Pro &rarr;
        </a>
    </div>
    <?php endif; ?>

    <!-- Scan Button -->
    <div class="seobetter-card" style="margin-bottom:24px">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <div>
                <h2 style="margin:0 0 4px">Scan Your Content</h2>
                <p style="margin:0;font-size:13px;color:var(--sb-text-secondary,#64748b)">Analyzes all published posts to find keyword overlap and cannibalization issues.</p>
            </div>
            <form method="post">
                <?php wp_nonce_field( 'seobetter_cannibalization_nonce' ); ?>
                <button type="submit" name="seobetter_scan_cannibalization" class="button sb-btn-primary" style="height:44px;padding:0 24px;white-space:nowrap" <?php echo ! $is_pro ? 'disabled title="Pro required"' : ''; ?>>
                    Scan for Cannibalization
                </button>
            </form>
        </div>
    </div>

    <!-- Summary -->
    <?php if ( $results ) : ?>
    <div class="seobetter-card" style="margin-bottom:24px;border-left:4px solid <?php echo $total_conflicts === 0 ? 'var(--sb-success,#059669)' : 'var(--sb-warning,#f59e0b)'; ?>">
        <div style="display:flex;justify-content:space-around;padding:16px 0;text-align:center">
            <div>
                <div style="font-size:36px;font-weight:700;color:<?php echo $total_conflicts === 0 ? 'var(--sb-success,#059669)' : 'var(--sb-warning,#f59e0b)'; ?>"><?php echo esc_html( $total_conflicts ); ?></div>
                <div style="font-size:13px;color:var(--sb-text-secondary,#64748b)">Keyword Conflicts</div>
            </div>
            <div>
                <div style="font-size:36px;font-weight:700;color:<?php echo $affected_posts === 0 ? 'var(--sb-success,#059669)' : 'var(--sb-error,#dc2626)'; ?>"><?php echo esc_html( $affected_posts ); ?></div>
                <div style="font-size:13px;color:var(--sb-text-secondary,#64748b)">Affected Posts</div>
            </div>
            <div>
                <div style="font-size:12px;color:var(--sb-text-muted,#94a3b8);margin-top:10px">
                    <?php if ( ! empty( $results['scanned_at'] ) ) : ?>
                        Last scanned: <?php echo esc_html( date( 'M j, Y g:ia', strtotime( $results['scanned_at'] ) ) ); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Conflict Groups -->
    <?php if ( ! empty( $conflicts ) ) : ?>
        <?php foreach ( $conflicts as $idx => $group ) :
            $keyword = $group['keyword'] ?? 'Unknown';
            // v1.5.13 — backend returns 'similarity'; 'recommendation' is an array
            $similarity = $group['similarity'] ?? 0;
            $rec = is_array( $group['recommendation'] ?? null ) ? $group['recommendation'] : [];
            $rec_action  = $rec['action']  ?? 'differentiate';
            $rec_message = $rec['message'] ?? '';
            $rec_colors = [
                'merge'         => [ 'bg' => '#fef3cd', 'text' => '#856404', 'icon' => 'dashicons-migrate' ],
                'redirect'      => [ 'bg' => '#f8d7da', 'text' => '#721c24', 'icon' => 'dashicons-redo' ],
                'consolidate'   => [ 'bg' => '#f8d7da', 'text' => '#721c24', 'icon' => 'dashicons-redo' ],
                'differentiate' => [ 'bg' => '#d4edda', 'text' => '#155724', 'icon' => 'dashicons-randomize' ],
            ];
            $rc = $rec_colors[ $rec_action ] ?? $rec_colors['differentiate'];
            $sim_class = $similarity >= 80 ? 'poor' : ( $similarity >= 50 ? 'ok' : 'good' );
        ?>
        <div class="seobetter-card" style="margin-bottom:20px">
            <!-- Keyword heading -->
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
                <h2 style="margin:0">
                    <span class="dashicons dashicons-warning" style="color:var(--sb-warning,#f59e0b);margin-right:6px"></span>
                    "<?php echo esc_html( $keyword ); ?>"
                </h2>
                <div style="display:flex;gap:12px;align-items:center">
                    <span style="font-size:12px;color:var(--sb-text-secondary,#64748b)">Similarity:</span>
                    <span class="seobetter-score seobetter-score-<?php echo esc_attr( $sim_class ); ?>"><?php echo esc_html( $similarity ); ?>%</span>
                </div>
            </div>

            <!-- Recommendation -->
            <div style="padding:10px 16px;background:<?php echo esc_attr( $rc['bg'] ); ?>;border-radius:6px;margin-bottom:16px;font-size:13px;color:<?php echo esc_attr( $rc['text'] ); ?>">
                <span class="dashicons <?php echo esc_attr( $rc['icon'] ); ?>" style="margin-right:4px;font-size:16px;width:16px;height:16px;vertical-align:text-bottom"></span>
                <strong>Recommendation:</strong> <?php echo esc_html( ucfirst( $rec_action ) ); ?>
                <?php if ( $rec_message ) : ?>
                    — <?php echo esc_html( $rec_message ); ?>
                <?php endif; ?>
            </div>

            <!-- Conflicting posts table -->
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Post Title', 'seobetter' ); ?></th>
                        <th style="width:200px"><?php esc_html_e( 'URL', 'seobetter' ); ?></th>
                        <th style="width:80px"><?php esc_html_e( 'Words', 'seobetter' ); ?></th>
                        <th style="width:100px"><?php esc_html_e( 'GEO Score', 'seobetter' ); ?></th>
                        <th style="width:100px"><?php esc_html_e( 'Published', 'seobetter' ); ?></th>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $group['posts'] ?? [] as $cp ) :
                        $gs = isset( $cp['geo_score'] ) ? ( $cp['geo_score'] >= 80 ? 'good' : ( $cp['geo_score'] >= 60 ? 'ok' : 'poor' ) ) : '';
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $cp['title'] ?? '' ); ?></strong></td>
                        <td style="font-size:12px;word-break:break-all"><a href="<?php echo esc_url( $cp['url'] ?? '' ); ?>" target="_blank"><?php echo esc_html( wp_parse_url( $cp['url'] ?? '', PHP_URL_PATH ) ); ?></a></td>
                        <td><?php echo esc_html( number_format( $cp['word_count'] ?? 0 ) ); ?></td>
                        <td>
                            <?php if ( $gs ) : ?>
                                <span class="seobetter-score seobetter-score-<?php echo esc_attr( $gs ); ?>"><?php echo esc_html( $cp['geo_score'] ); ?></span>
                            <?php else : ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;color:var(--sb-text-secondary,#64748b)"><?php echo esc_html( $cp['published'] ?? '' ); ?></td>
                        <td><a href="<?php echo esc_url( get_edit_post_link( $cp['post_id'] ?? 0 ) ); ?>" class="button button-small">Edit</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>

    <?php elseif ( $results && empty( $conflicts ) ) : ?>
    <div class="seobetter-card" style="text-align:center;padding:40px">
        <span class="dashicons dashicons-yes-alt" style="font-size:48px;width:48px;height:48px;color:var(--sb-success,#059669);margin-bottom:12px"></span>
        <h2 style="margin:0 0 8px;color:var(--sb-success,#059669)">No Cannibalization Detected</h2>
        <p style="margin:0;color:var(--sb-text-secondary,#64748b);font-size:14px">Your content targets distinct keywords. Keep it up!</p>
    </div>
    <?php endif; ?>

</div>
