<?php if ( ! defined( 'ABSPATH' ) ) exit;

// v1.5.13 — gate uses can_use() instead of is_pro()
$is_pro = SEOBetter\License_Manager::can_use( 'internal_link_suggestions' );
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

$total_posts = count( $all_posts );

// v1.5.13 — Site-wide overview removed because nothing populates the
// _seobetter_internal_links post meta. A real implementation needs a crawl
// job that scans every post, parses internal anchors, and stores the count.
// Until that job ships, the per-post suggestion form below is the only
// reliable path. See seo-guidelines/pro-features-ideas.md backlog.
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

    <!-- Site overview info card (v1.5.13: full crawl deferred) -->
    <div class="seobetter-card" style="margin-bottom:24px;padding:18px;display:flex;gap:16px;align-items:center">
        <span class="dashicons dashicons-info-outline" style="font-size:28px;width:28px;height:28px;color:var(--sb-primary,#764ba2)"></span>
        <div>
            <div style="font-weight:600;font-size:14px;color:var(--sb-text,#1e293b);margin-bottom:2px">
                <?php echo esc_html( sprintf( _n( '%d published post', '%d published posts', $total_posts, 'seobetter' ), $total_posts ) ); ?>
            </div>
            <div style="font-size:13px;color:var(--sb-text-secondary,#64748b)">
                Site-wide link overview (avg links/post, orphan pages) is coming in a future release. For now, pick a post below to see suggested internal links.
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
        // v1.5.13 — backend returns 'suggestions' key, not 'links'
        $suggestion_rows = $suggestions['suggestions'] ?? [];
    ?>
    <div class="seobetter-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h2 style="margin:0">Link Suggestions for: <?php echo esc_html( $selected_post->post_title ?? '' ); ?></h2>
            <span style="font-size:13px;color:var(--sb-text-secondary,#64748b)"><?php echo esc_html( count( $suggestion_rows ) ); ?> suggestions found</span>
        </div>

        <?php if ( ! empty( $suggestion_rows ) ) : ?>
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
                <?php foreach ( $suggestion_rows as $link ) :
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
                        <button type="button" class="button button-small sb-copy-link"
                            data-target-url="<?php echo esc_url( get_permalink( $link['target_post_id'] ?? 0 ) ); ?>"
                            data-anchor="<?php echo esc_attr( $link['anchor_text'] ?? '' ); ?>">
                            Copy
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
    // v1.5.13 — Copy anchor + URL as a markdown link to clipboard.
    // Insert-into-post REST endpoint is on the backlog; for now the user
    // pastes manually into the editor.
    (function() {
        document.querySelectorAll('.sb-copy-link').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var targetUrl = this.dataset.targetUrl || '';
                var anchor = this.dataset.anchor || '';
                var markdown = '[' + anchor + '](' + targetUrl + ')';
                var button = this;
                navigator.clipboard.writeText(markdown).then(function() {
                    var original = button.textContent;
                    button.textContent = 'Copied!';
                    button.style.color = 'var(--sb-success,#059669)';
                    setTimeout(function() {
                        button.textContent = original;
                        button.style.color = '';
                    }, 1800);
                });
            });
        });
    })();
    </script>
    <?php endif; ?>

</div>
