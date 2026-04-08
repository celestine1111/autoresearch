<?php if ( ! defined( 'ABSPATH' ) ) exit;

$is_pro = SEOBetter\License_Manager::is_pro();
$citation_result = null;
$checked_post_id = 0;

// Handle citation check
if ( isset( $_POST['seobetter_check_citation'] ) && check_admin_referer( 'seobetter_citation_nonce' ) ) {
    if ( $is_pro ) {
        $checked_post_id = absint( $_POST['post_id'] ?? 0 );
        if ( $checked_post_id ) {
            $tracker = new SEOBetter\Citation_Tracker();
            $citation_result = $tracker->check_post( $checked_post_id );
        }
    }
}

// Get all published posts with GEO scores
$posts = get_posts( [
    'post_type'      => [ 'post', 'page' ],
    'post_status'    => 'publish',
    'posts_per_page' => 100,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'meta_key'       => '_seobetter_geo_score',
] );

// Calculate site-wide summary
$total_posts = count( $posts );
$cited_count = 0;
foreach ( $posts as $p ) {
    $cached = get_post_meta( $p->ID, '_seobetter_citation_data', true );
    if ( ! empty( $cached['cited'] ) ) {
        $cited_count++;
    }
}
$citation_rate = $total_posts > 0 ? round( ( $cited_count / $total_posts ) * 100 ) : 0;
?>
<div class="wrap seobetter-dashboard">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
        <div>
            <h1 style="margin:0;font-size:24px;font-weight:700;color:var(--sb-text,#1e293b)">AI Citation Tracker</h1>
            <p style="margin:4px 0 0;font-size:14px;color:var(--sb-text-secondary,#64748b)">Track whether your content is being cited by AI search engines.</p>
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
            Citation Tracking requires Pro
        </h3>
        <p style="margin:0 0 16px;font-size:14px;color:var(--sb-text-secondary,#64748b)">Check if your content is being cited by ChatGPT, Perplexity, Gemini, and Google AI Overviews. Monitor your AI visibility over time.</p>
        <a href="https://seobetter.com/pricing" target="_blank" class="button sb-btn-primary" style="font-size:14px;height:44px;line-height:28px">
            Upgrade to Pro &rarr;
        </a>
    </div>
    <?php endif; ?>

    <!-- Site-Wide Summary -->
    <div class="seobetter-card" style="margin-bottom:24px">
        <h2 style="margin-bottom:16px">Citation Summary</h2>
        <div style="display:flex;justify-content:space-around;padding:20px 0;text-align:center">
            <div>
                <div style="font-size:36px;font-weight:700;color:var(--sb-primary,#764ba2)"><?php echo esc_html( $total_posts ); ?></div>
                <div style="font-size:13px;color:var(--sb-text-secondary,#64748b)">Published Posts</div>
            </div>
            <div>
                <div style="font-size:36px;font-weight:700;color:var(--sb-success,#059669)"><?php echo esc_html( $cited_count ); ?></div>
                <div style="font-size:13px;color:var(--sb-text-secondary,#64748b)">Posts Cited by AI</div>
            </div>
            <div>
                <div style="font-size:36px;font-weight:700;color:<?php echo $citation_rate >= 50 ? 'var(--sb-success,#059669)' : ( $citation_rate >= 25 ? 'var(--sb-warning,#f59e0b)' : 'var(--sb-error,#dc2626)' ); ?>"><?php echo esc_html( $citation_rate ); ?>%</div>
                <div style="font-size:13px;color:var(--sb-text-secondary,#64748b)">Citation Rate</div>
            </div>
        </div>
    </div>

    <!-- Citation Check Result -->
    <?php if ( $citation_result && $checked_post_id ) :
        $checked_post = get_post( $checked_post_id );
    ?>
    <div class="seobetter-card" style="margin-bottom:24px;border-left:4px solid <?php echo ! empty( $citation_result['cited'] ) ? 'var(--sb-success,#059669)' : 'var(--sb-error,#dc2626)'; ?>">
        <h2 style="margin-bottom:16px">Citation Check: <?php echo esc_html( $checked_post->post_title ?? '' ); ?></h2>

        <div style="display:flex;gap:24px;margin-bottom:20px">
            <div style="text-align:center">
                <?php
                    $vis = $citation_result['visibility_score'] ?? 0;
                    $vis_class = $vis >= 70 ? 'good' : ( $vis >= 40 ? 'ok' : 'poor' );
                ?>
                <div class="seobetter-score-circle <?php echo esc_attr( $vis_class ); ?>" style="width:80px;height:80px;margin:0 auto 8px">
                    <span class="score-number" style="font-size:24px"><?php echo esc_html( $vis ); ?></span>
                </div>
                <div style="font-size:12px;color:var(--sb-text-secondary,#64748b)">Visibility Score</div>
            </div>
            <div style="flex:1">
                <table class="widefat" style="margin-bottom:0">
                    <tr>
                        <td style="width:140px"><strong>Cited by AI:</strong></td>
                        <td>
                            <?php if ( ! empty( $citation_result['cited'] ) ) : ?>
                                <span style="color:var(--sb-success,#059669);font-weight:600"><span class="dashicons dashicons-yes-alt"></span> YES</span>
                            <?php else : ?>
                                <span style="color:var(--sb-error,#dc2626);font-weight:600"><span class="dashicons dashicons-dismiss"></span> NO</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Reason:</strong></td>
                        <td><?php echo esc_html( $citation_result['reason'] ?? '' ); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <?php if ( ! empty( $citation_result['competitors'] ) ) : ?>
        <h3 style="margin-bottom:12px">Top 10 Competitors for this Topic</h3>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width:30px">#</th>
                    <th><?php esc_html_e( 'Competitor', 'seobetter' ); ?></th>
                    <th style="width:80px"><?php esc_html_e( 'Cited', 'seobetter' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $citation_result['competitors'] as $i => $comp ) : ?>
                <tr>
                    <td><?php echo esc_html( $i + 1 ); ?></td>
                    <td>
                        <strong><?php echo esc_html( $comp['title'] ?? '' ); ?></strong><br>
                        <small style="color:var(--sb-text-muted,#94a3b8)"><?php echo esc_html( $comp['url'] ?? '' ); ?></small>
                    </td>
                    <td>
                        <?php if ( ! empty( $comp['cited'] ) ) : ?>
                            <span class="dashicons dashicons-yes-alt" style="color:var(--sb-success,#059669)"></span>
                        <?php else : ?>
                            <span class="dashicons dashicons-minus" style="color:var(--sb-text-muted,#94a3b8)"></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Posts List -->
    <div class="seobetter-card">
        <h2 style="margin-bottom:16px">Published Content</h2>

        <?php if ( empty( $posts ) ) : ?>
            <p style="color:var(--sb-text-secondary,#64748b)"><?php esc_html_e( 'No published posts with GEO scores found. Generate some content first.', 'seobetter' ); ?></p>
        <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Post', 'seobetter' ); ?></th>
                    <th style="width:100px"><?php esc_html_e( 'GEO Score', 'seobetter' ); ?></th>
                    <th style="width:80px"><?php esc_html_e( 'Cited', 'seobetter' ); ?></th>
                    <th style="width:140px"><?php esc_html_e( 'Last Checked', 'seobetter' ); ?></th>
                    <th style="width:140px"><?php esc_html_e( 'Action', 'seobetter' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $posts as $p ) :
                    $score_data = get_post_meta( $p->ID, '_seobetter_geo_score', true );
                    $score = $score_data['geo_score'] ?? '—';
                    $score_class = is_numeric( $score ) ? ( $score >= 80 ? 'good' : ( $score >= 60 ? 'ok' : 'poor' ) ) : '';
                    $cached = get_post_meta( $p->ID, '_seobetter_citation_data', true );
                    $last_checked = $cached['checked_at'] ?? null;
                ?>
                <tr>
                    <td><a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>"><strong><?php echo esc_html( $p->post_title ); ?></strong></a></td>
                    <td>
                        <?php if ( $score_class ) : ?>
                            <span class="seobetter-score seobetter-score-<?php echo esc_attr( $score_class ); ?>"><?php echo esc_html( $score ); ?></span>
                        <?php else : ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $cached ) : ?>
                            <?php if ( ! empty( $cached['cited'] ) ) : ?>
                                <span class="dashicons dashicons-yes-alt" style="color:var(--sb-success,#059669)"></span>
                            <?php else : ?>
                                <span class="dashicons dashicons-dismiss" style="color:var(--sb-error,#dc2626)"></span>
                            <?php endif; ?>
                        <?php else : ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:var(--sb-text-secondary,#64748b)">
                        <?php echo $last_checked ? esc_html( date( 'M j, Y g:ia', strtotime( $last_checked ) ) ) : '&mdash;'; ?>
                    </td>
                    <td>
                        <form method="post" style="display:inline">
                            <?php wp_nonce_field( 'seobetter_citation_nonce' ); ?>
                            <input type="hidden" name="post_id" value="<?php echo esc_attr( $p->ID ); ?>" />
                            <button type="submit" name="seobetter_check_citation" class="button button-small" <?php echo ! $is_pro ? 'disabled title="Pro required"' : ''; ?>>
                                Check Citations
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>
