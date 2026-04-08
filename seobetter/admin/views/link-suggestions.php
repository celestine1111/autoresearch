<?php if ( ! defined( 'ABSPATH' ) ) exit;

$is_pro = SEOBetter\License_Manager::is_pro();
$suggestions = null;
$selected_post_id = 0;

// Handle link suggestion request
if ( isset( $_POST['seobetter_get_links'] ) && check_admin_referer( 'seobetter_links_nonce' ) ) {
    if ( $is_pro ) {
        $selected_post_id = absint( $_POST['post_id'] ?? 0 );
        if ( $selected_post_id ) {
            $suggester = new SEOBetter\Internal_Link_Suggester();
            $suggestions = $suggester->suggest_for_post( $selected_post_id );
        }
    }
}

// Get all published posts for dropdown
$all_posts = get_posts( [
    'post_type'      => [ 'post', 'page' ],
    'post_status'    => 'publish',
    'posts_per_page' => 200,
    'orderby'        => 'title',
    'order'          => 'ASC',
] );

// Site-wide link stats
$total_posts = count( $all_posts );
$total_internal_links = 0;
$orphan_count = 0;

foreach ( $all_posts as $p ) {
    $link_count = absint( get_post_meta( $p->ID, '_seobetter_internal_links', true ) );
    $total_internal_links += $link_count;
    if ( $link_count === 0 ) {
        $orphan_count++;
    }
}
$avg_links = $total_posts > 0 ? round( $total_internal_links / $total_posts, 1 ) : 0;
?>
<div class="wrap seobetter-dashboard">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
        <div>
            <h1 style="margin:0;font-size:24px;font-weight:700;color:var(--sb-text,#1e293b)">Internal Link Suggestions</h1>
            <p style="margin:4px 0 0;font-size:14px;color:var(--sb-text-secondary,#64748b)">Discover internal linking opportunities to improve site structure and SEO.</p>
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
            Internal Link Suggestions requires Pro
        </h3>
        <p style="margin:0 0 16px;font-size:14px;color:var(--sb-text-secondary,#64748b)">Get AI-powered internal link suggestions to improve your site architecture, distribute page authority, and eliminate orphan pages.</p>
        <a href="https://seobetter.com/pricing" target="_blank" class="button sb-btn-primary" style="font-size:14px;height:44px;line-height:28px">
            Upgrade to Pro &rarr;
        </a>
    </div>
    <?php endif; ?>

    <!-- Site-Wide Link Stats -->
    <div class="seobetter-card" style="margin-bottom:24px">
        <h2 style="margin-bottom:16px">Site Link Overview</h2>
        <div style="display:flex;justify-content:space-around;padding:20px 0;text-align:center">
            <div>
                <div style="font-size:36px;font-weight:700;color:var(--sb-primary,#764ba2)"><?php echo esc_html( $total_posts ); ?></div>
                <div style="font-size:13px;color:var(--sb-text-secondary,#64748b)">Total Published Posts</div>
            </div>
            <div>
                <div style="font-size:36px;font-weight:700;color:<?php echo $avg_links >= 3 ? 'var(--sb-success,#059669)' : 'var(--sb-warning,#f59e0b)'; ?>"><?php echo esc_html( $avg_links ); ?></div>
                <div style="font-size:13px;color:var(--sb-text-secondary,#64748b)">Avg Internal Links/Post</div>
            </div>
            <div>
                <div style="font-size:36px;font-weight:700;color:<?php echo $orphan_count === 0 ? 'var(--sb-success,#059669)' : 'var(--sb-error,#dc2626)'; ?>"><?php echo esc_html( $orphan_count ); ?></div>
                <div style="font-size:13px;color:var(--sb-text-secondary,#64748b)">Orphan Pages</div>
            </div>
        </div>
    </div>

    <!-- Post Selection Form -->
    <div class="seobetter-card" style="margin-bottom:24px">
        <form method="post">
            <?php wp_nonce_field( 'seobetter_links_nonce' ); ?>

            <div class="sb-section">
                <h3 class="sb-section-header"><span class="dashicons dashicons-admin-links"></span> Get Link Suggestions</h3>

                <div style="display:flex;gap:12px;align-items:flex-end">
                    <div class="sb-field" style="flex:1">
                        <label>Select a Post</label>
                        <select name="post_id" style="width:100%;height:44px;padding:8px 12px;border:1px solid var(--sb-border,#e2e8f0);border-radius:8px;font-size:14px">
                            <option value="">-- Choose a post --</option>
                            <?php foreach ( $all_posts as $p ) : ?>
                                <option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $selected_post_id, $p->ID ); ?>>
                                    <?php echo esc_html( $p->post_title ); ?> (<?php echo esc_html( $p->post_type ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="seobetter_get_links" class="button sb-btn-primary" style="height:44px;padding:0 24px;white-space:nowrap" <?php echo ! $is_pro ? 'disabled title="Pro required"' : ''; ?>>
                        Get Suggestions
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Suggestion Results -->
    <?php if ( $suggestions && $selected_post_id ) :
        $selected_post = get_post( $selected_post_id );
    ?>
    <div class="seobetter-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h2 style="margin:0">Link Suggestions for: <?php echo esc_html( $selected_post->post_title ?? '' ); ?></h2>
            <span style="font-size:13px;color:var(--sb-text-secondary,#64748b)"><?php echo esc_html( count( $suggestions['links'] ?? [] ) ); ?> suggestions found</span>
        </div>

        <?php if ( ! empty( $suggestions['links'] ) ) : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Target Post', 'seobetter' ); ?></th>
                    <th style="width:200px"><?php esc_html_e( 'Suggested Anchor Text', 'seobetter' ); ?></th>
                    <th style="width:130px"><?php esc_html_e( 'Relevance', 'seobetter' ); ?></th>
                    <th style="width:120px"><?php esc_html_e( 'Action', 'seobetter' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $suggestions['links'] as $link ) :
                    $rel_score = $link['relevance_score'] ?? 0;
                    $rel_color = $rel_score >= 80 ? 'var(--sb-success,#059669)' : ( $rel_score >= 60 ? 'var(--sb-warning,#f59e0b)' : 'var(--sb-error,#dc2626)' );
                    $rel_class = $rel_score >= 80 ? 'good' : ( $rel_score >= 60 ? 'ok' : 'poor' );
                ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url( get_edit_post_link( $link['target_post_id'] ?? 0 ) ); ?>">
                            <strong><?php echo esc_html( $link['target_title'] ?? '' ); ?></strong>
                        </a>
                    </td>
                    <td><code style="font-size:12px;padding:3px 8px;background:var(--sb-bg,#f8fafc);border-radius:4px"><?php echo esc_html( $link['anchor_text'] ?? '' ); ?></code></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div style="flex:1;background:#e9ecef;border-radius:4px;height:8px;overflow:hidden">
                                <div style="background:<?php echo esc_attr( $rel_color ); ?>;height:100%;width:<?php echo esc_attr( $rel_score ); ?>%;border-radius:4px"></div>
                            </div>
                            <span class="seobetter-score seobetter-score-<?php echo esc_attr( $rel_class ); ?>"><?php echo esc_html( $rel_score ); ?></span>
                        </div>
                    </td>
                    <td>
                        <button type="button" class="button button-small sb-insert-link"
                            data-source-id="<?php echo esc_attr( $selected_post_id ); ?>"
                            data-target-url="<?php echo esc_url( get_permalink( $link['target_post_id'] ?? 0 ) ); ?>"
                            data-anchor="<?php echo esc_attr( $link['anchor_text'] ?? '' ); ?>">
                            Insert Link
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
            <p style="color:var(--sb-text-secondary,#64748b)">No link suggestions found for this post. Try creating more content to build linking opportunities.</p>
        <?php endif; ?>
    </div>

    <script>
    (function() {
        document.querySelectorAll('.sb-insert-link').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var sourceId = this.dataset.sourceId;
                var targetUrl = this.dataset.targetUrl;
                var anchor = this.dataset.anchor;
                var button = this;

                button.disabled = true;
                button.textContent = 'Inserting...';

                fetch('<?php echo esc_url( rest_url( 'seobetter/v1/insert-link' ) ); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'
                    },
                    body: JSON.stringify({
                        post_id: sourceId,
                        target_url: targetUrl,
                        anchor_text: anchor
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        button.textContent = 'Inserted';
                        button.style.color = 'var(--sb-success,#059669)';
                    } else {
                        button.textContent = 'Failed';
                        button.disabled = false;
                    }
                })
                .catch(function() {
                    button.textContent = 'Error';
                    button.disabled = false;
                });
            });
        });
    })();
    </script>
    <?php endif; ?>

</div>
