<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$manager   = new SEOBetter\Content_Freshness_Manager();
$inventory = $manager->get_inventory();
$rows      = $inventory['rows'];
$gsc_active        = $inventory['gsc_active'];
$can_use_gsc       = $inventory['can_use_gsc'];
$gsc_connected     = $inventory['gsc_connected'];
$oauth_configured  = $inventory['oauth_configured'];

// Stats summary for the header strip
$stale_count   = 0;
$warning_count = 0;
$fresh_count   = 0;
$priority_total = 0;
foreach ( $rows as $r ) {
    if ( $r['age_days'] >= 365 )      $stale_count++;
    elseif ( $r['age_days'] >= 180 )  $warning_count++;
    else                               $fresh_count++;
    $priority_total += $r['priority'];
}
$avg_priority = $rows ? (int) round( $priority_total / count( $rows ) ) : 0;
?>
<div class="wrap seobetter-dashboard">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
        <div>
            <h1 style="margin:0"><?php esc_html_e( 'Content Freshness', 'seobetter' ); ?></h1>
            <p style="margin:4px 0 0;color:#64748b;font-size:14px">
                <?php esc_html_e( 'Sortable inventory of every published post, ranked by refresh priority. Higher score = more urgent to refresh.', 'seobetter' ); ?>
            </p>
        </div>
        <?php if ( ! $gsc_connected ) : ?>
            <?php // v1.5.216.43 — link points at the tab where GSC actually lives.
                  // Pre-fix landed on default License & Account tab so GSC card
                  // wasn't visible — user reported "click does nothing". ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seobetter-settings&tab=research_integrations#gsc' ) ); ?>" class="button">
                <?php esc_html_e( 'Connect Google Search Console →', 'seobetter' ); ?>
            </a>
        <?php endif; ?>
    </div>

    <!-- Stats strip -->
    <div class="seobetter-card" style="margin-bottom:20px;padding:18px 20px">
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px">
            <div style="padding:14px 16px;background:#fef2f2;border-radius:8px;border:1px solid #fecaca">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#991b1b;margin-bottom:6px"><?php esc_html_e( 'Stale (1yr+)', 'seobetter' ); ?></div>
                <div style="font-size:28px;font-weight:700;color:#dc2626;line-height:1"><?php echo esc_html( $stale_count ); ?></div>
            </div>
            <div style="padding:14px 16px;background:#fef3c7;border-radius:8px;border:1px solid #fcd34d">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#92400e;margin-bottom:6px"><?php esc_html_e( 'Aging (6mo+)', 'seobetter' ); ?></div>
                <div style="font-size:28px;font-weight:700;color:#f59e0b;line-height:1"><?php echo esc_html( $warning_count ); ?></div>
            </div>
            <div style="padding:14px 16px;background:#dcfce7;border-radius:8px;border:1px solid #86efac">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#166534;margin-bottom:6px"><?php esc_html_e( 'Fresh', 'seobetter' ); ?></div>
                <div style="font-size:28px;font-weight:700;color:#10b981;line-height:1"><?php echo esc_html( $fresh_count ); ?></div>
            </div>
            <div style="padding:14px 16px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:6px"><?php esc_html_e( 'Avg priority', 'seobetter' ); ?></div>
                <div style="font-size:28px;font-weight:700;color:<?php echo $avg_priority >= 60 ? '#dc2626' : ( $avg_priority >= 30 ? '#f59e0b' : '#10b981' ); ?>;line-height:1"><?php echo esc_html( $avg_priority ); ?></div>
            </div>
        </div>
    </div>

    <!-- Pro+ upsell when GSC is connected but tier is below Pro+ -->
    <?php if ( $gsc_connected && ! $can_use_gsc ) : ?>
    <div class="seobetter-card" style="margin-bottom:20px;background:linear-gradient(135deg,#faf5ff 0%,#ede9fe 100%);border:1px solid #ddd6fe">
        <h3 style="margin:0 0 8px;color:#5b21b6;font-size:14px">
            <span style="background:#8b5cf6;color:#fff;font-size:10px;padding:2px 6px;border-radius:3px;letter-spacing:0.05em">PRO+</span>
            <?php esc_html_e( 'GSC-driven Refresh Priority', 'seobetter' ); ?>
        </h3>
        <p style="margin:0 0 12px;font-size:13px;color:#4c1d95">
            <?php esc_html_e( 'You\'re connected to Google Search Console. Upgrade to Pro+ ($69/mo) to weight Refresh Priority by GSC click decay + position drift — the smart version that surfaces "striking distance" pages just off page 1 first.', 'seobetter' ); ?>
        </p>
        <a href="https://seobetter.com/pricing" target="_blank" class="button button-primary"><?php esc_html_e( 'See Pro+ →', 'seobetter' ); ?></a>
    </div>
    <?php endif; ?>

    <!-- Inventory table -->
    <div class="seobetter-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h2 style="margin:0">
                <?php esc_html_e( 'Inventory', 'seobetter' ); ?>
                <span style="font-size:13px;font-weight:400;color:#64748b;margin-left:8px"><?php
                    /* translators: 1: total post count */
                    printf( esc_html( _n( '%d post', '%d posts', $inventory['total'], 'seobetter' ) ), $inventory['total'] );
                ?></span>
            </h2>
            <div style="font-size:11px;color:#64748b">
                <?php if ( $gsc_active ) : ?>
                    <span style="color:#10b981">●</span> <?php esc_html_e( 'GSC-driven priority active', 'seobetter' ); ?>
                <?php elseif ( $gsc_connected ) : ?>
                    <span style="color:#f59e0b">●</span> <?php esc_html_e( 'Age-based priority (Pro+ enables GSC weighting)', 'seobetter' ); ?>
                <?php else : ?>
                    <span style="color:#94a3b8">●</span> <?php esc_html_e( 'Age-based priority (no GSC connected)', 'seobetter' ); ?>
                <?php endif; ?>
            </div>
        </div>

        <table class="widefat striped" id="seobetter-freshness-inventory">
            <thead>
                <tr>
                    <th data-sort="title" style="cursor:pointer"><?php esc_html_e( 'Post', 'seobetter' ); ?> <span class="sort-arrow"></span></th>
                    <th data-sort="age_days" style="cursor:pointer;width:100px"><?php esc_html_e( 'Modified', 'seobetter' ); ?> <span class="sort-arrow"></span></th>
                    <th data-sort="word_count" style="cursor:pointer;width:90px"><?php esc_html_e( 'Words', 'seobetter' ); ?> <span class="sort-arrow"></span></th>
                    <th data-sort="outdated_years" style="cursor:pointer;width:90px;text-align:center" title="<?php esc_attr_e( 'Year mentions older than last year — strong refresh signal', 'seobetter' ); ?>"><?php esc_html_e( 'Old years', 'seobetter' ); ?> <span class="sort-arrow"></span></th>
                    <th style="width:90px;text-align:center" title="<?php esc_attr_e( 'Has "Last Updated:" or similar freshness signal in body', 'seobetter' ); ?>"><?php esc_html_e( 'Signal', 'seobetter' ); ?></th>
                    <?php if ( $can_use_gsc || $gsc_connected ) : ?>
                        <th data-sort="gsc_clicks" style="cursor:pointer;width:80px;text-align:right" title="<?php esc_attr_e( 'GSC clicks last 28 days', 'seobetter' ); ?>"><?php esc_html_e( 'Clicks', 'seobetter' ); ?> <span class="sort-arrow"></span></th>
                        <th data-sort="gsc_position" style="cursor:pointer;width:80px;text-align:right" title="<?php esc_attr_e( 'GSC average position last 28 days', 'seobetter' ); ?>"><?php esc_html_e( 'Position', 'seobetter' ); ?> <span class="sort-arrow"></span></th>
                    <?php endif; ?>
                    <th data-sort="priority" style="cursor:pointer;width:120px;text-align:center" title="<?php esc_attr_e( 'Composite refresh priority — higher = more urgent', 'seobetter' ); ?>"><?php esc_html_e( 'Priority', 'seobetter' ); ?> <span class="sort-arrow active">▼</span></th>
                    <th style="width:80px"></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="9" style="text-align:center;padding:40px;color:#64748b"><?php esc_html_e( 'No published posts yet. Generate your first article to populate this inventory.', 'seobetter' ); ?></td></tr>
                <?php else : foreach ( $rows as $row ) :
                    $age = $row['age_days'];
                    $age_label = $age >= 730 ? sprintf( '%dy ago', (int) round( $age / 365 ) )
                              : ( $age >= 365 ? '1y+ ago'
                              : ( $age >= 90 ? sprintf( '%dmo ago', (int) round( $age / 30 ) )
                              : ( $age >= 30 ? sprintf( '%dmo ago', (int) round( $age / 30 ) )
                              : sprintf( '%dd ago', $age ) ) ) );

                    $age_color = $age >= 365 ? '#dc2626' : ( $age >= 180 ? '#f59e0b' : '#64748b' );

                    $pri = $row['priority'];
                    $pri_color = $pri >= 60 ? '#dc2626' : ( $pri >= 30 ? '#f59e0b' : '#10b981' );

                    $gsc_clicks = $row['gsc']['clicks_28d'] ?? null;
                    $gsc_position = $row['gsc']['position_28d'] ?? null;
                ?>
                <tr
                    data-title="<?php echo esc_attr( strtolower( $row['title'] ) ); ?>"
                    data-age_days="<?php echo esc_attr( $age ); ?>"
                    data-word_count="<?php echo esc_attr( $row['word_count'] ); ?>"
                    data-outdated_years="<?php echo esc_attr( $row['outdated_years'] ); ?>"
                    data-gsc_clicks="<?php echo esc_attr( $gsc_clicks !== null ? (int) $gsc_clicks : -1 ); ?>"
                    data-gsc_position="<?php echo esc_attr( $gsc_position !== null ? (float) $gsc_position : 999 ); ?>"
                    data-priority="<?php echo esc_attr( $pri ); ?>"
                >
                    <td>
                        <a href="<?php echo esc_url( $row['edit_url'] ); ?>" style="font-weight:600">
                            <?php echo esc_html( $row['title'] ?: __( '(no title)', 'seobetter' ) ); ?>
                        </a>
                    </td>
                    <td style="color:<?php echo esc_attr( $age_color ); ?>;font-size:12px"><?php echo esc_html( $age_label ); ?></td>
                    <td style="font-size:12px"><?php echo esc_html( number_format( $row['word_count'] ) ); ?></td>
                    <td style="text-align:center">
                        <?php if ( $row['outdated_years'] > 0 ) : ?>
                            <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600"><?php echo esc_html( $row['outdated_years'] ); ?></span>
                        <?php else : ?>
                            <span style="color:#cbd5e1">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center">
                        <?php if ( $row['has_signal'] ) : ?>
                            <span style="color:#10b981" title="<?php esc_attr_e( 'Has freshness signal', 'seobetter' ); ?>">✓</span>
                        <?php else : ?>
                            <span style="color:#dc2626" title="<?php esc_attr_e( 'No freshness signal — add Last Updated:', 'seobetter' ); ?>">✗</span>
                        <?php endif; ?>
                    </td>
                    <?php if ( $can_use_gsc || $gsc_connected ) : ?>
                        <?php if ( $gsc_active ) : ?>
                            <td style="text-align:right;font-size:12px"><?php echo $gsc_clicks !== null ? esc_html( number_format( $gsc_clicks ) ) : '<span style="color:#cbd5e1">—</span>'; ?></td>
                            <td style="text-align:right;font-size:12px"><?php echo $gsc_position !== null ? esc_html( number_format( $gsc_position, 1 ) ) : '<span style="color:#cbd5e1">—</span>'; ?></td>
                        <?php else : ?>
                            <td colspan="2" style="text-align:center;font-size:11px;color:#64748b;background:linear-gradient(135deg,#faf5ff 0%,#ede9fe 100%)">
                                <span style="background:#8b5cf6;color:#fff;font-size:9px;padding:1px 5px;border-radius:3px;letter-spacing:0.05em;font-weight:600">PRO+</span>
                                <?php esc_html_e( 'unlock GSC weighting', 'seobetter' ); ?>
                            </td>
                        <?php endif; ?>
                    <?php endif; ?>
                    <td style="text-align:center">
                        <span style="display:inline-block;padding:4px 10px;border-radius:12px;background:<?php echo esc_attr( $pri_color ); ?>1a;color:<?php echo esc_attr( $pri_color ); ?>;font-weight:600;font-size:12px">
                            <?php echo esc_html( $pri ); ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap">
                        <button type="button" class="button button-small seobetter-why-btn" data-post-id="<?php echo (int) $row['id']; ?>" style="margin-right:4px"><?php esc_html_e( 'Why?', 'seobetter' ); ?></button>
                        <a href="<?php echo esc_url( $row['edit_url'] ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'seobetter' ); ?></a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php // v1.5.216.54 — "Why?" diagnostic drawer. Slides in from the right.
          // Loaded async via REST when a Why button is clicked. Tier-gated
          // inside Content_Freshness_Manager::diagnostic_for_post — Free shows
          // an upsell card, Pro shows age/year/missing-signal sections,
          // Pro+ adds GSC click decay + position drift + top queries. ?>
    <div id="seobetter-why-overlay" style="display:none;position:fixed;top:32px;right:0;bottom:0;width:480px;max-width:100vw;background:#fff;border-left:1px solid #e2e8f0;box-shadow:-4px 0 20px rgba(0,0,0,0.08);z-index:9999;overflow-y:auto">
        <div style="padding:18px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:#f8fafc;position:sticky;top:0;z-index:10">
            <div>
                <div id="seobetter-why-title" style="font-weight:700;font-size:14px;color:#0f172a"><?php esc_html_e( 'Why this priority?', 'seobetter' ); ?></div>
                <div id="seobetter-why-subtitle" style="font-size:11px;color:#64748b;margin-top:2px"></div>
            </div>
            <button type="button" id="seobetter-why-close" class="button button-small">×</button>
        </div>
        <div id="seobetter-why-body" style="padding:18px 20px">
            <p style="color:#94a3b8;text-align:center;padding:30px 0"><?php esc_html_e( 'Loading…', 'seobetter' ); ?></p>
        </div>
    </div>

</div>

<style>
#seobetter-freshness-inventory th[data-sort] { user-select: none; }
#seobetter-freshness-inventory th[data-sort]:hover { background: #f1f5f9; }
#seobetter-freshness-inventory .sort-arrow { font-size: 10px; color: #cbd5e1; margin-left: 2px; }
#seobetter-freshness-inventory .sort-arrow.active { color: #475569; }
.seobetter-why-signal { padding:14px 16px;border-radius:8px;margin-bottom:10px;border:1px solid #e2e8f0; }
.seobetter-why-signal--critical { background:#fef2f2;border-color:#fecaca; }
.seobetter-why-signal--warning  { background:#fef3c7;border-color:#fcd34d; }
.seobetter-why-signal--info     { background:#eff6ff;border-color:#bfdbfe; }
.seobetter-why-signal__head { display:flex;justify-content:space-between;align-items:center;gap:10px;font-weight:600;font-size:13px;color:#0f172a;margin-bottom:6px; }
.seobetter-why-signal__contrib { font-size:10px;font-weight:700;letter-spacing:0.05em;padding:2px 7px;border-radius:4px;white-space:nowrap; }
.seobetter-why-signal__contrib--critical { background:#fee2e2;color:#991b1b; }
.seobetter-why-signal__contrib--warning  { background:#fef3c7;color:#92400e; }
.seobetter-why-signal__contrib--info     { background:#dbeafe;color:#1e40af; }
.seobetter-why-signal__detail { font-size:12px;color:#475569;line-height:1.5;margin-bottom:8px; }
.seobetter-why-signal__action { font-size:12px;margin-top:6px; }
.seobetter-why-snippets { background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:8px 10px;margin-bottom:8px; }
.seobetter-why-snippet { font-size:12px;color:#334155;font-family:ui-monospace,'SF Mono',Menlo,Consolas,monospace;line-height:1.5;padding:4px 0;border-bottom:1px solid #f1f5f9;word-break:break-word; }
.seobetter-why-snippet:last-child { border-bottom:none; }
.seobetter-why-preview { background:#f8fafc;border:1px dashed #94a3b8;border-radius:4px;padding:6px 10px;font-family:ui-monospace,'SF Mono',Menlo,Consolas,monospace;font-size:12px;color:#0f172a;margin-bottom:6px; }
.seobetter-why-checklist { margin-bottom:8px; }
.seobetter-why-checklist__title { font-size:11px;font-weight:600;color:#0f172a;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.04em; }
.seobetter-why-checklist ul { list-style:none;padding:0;margin:0; }
.seobetter-why-checklist li { font-size:12px;color:#334155;line-height:1.5;padding:5px 0 5px 22px;border-bottom:1px solid rgba(0,0,0,0.04);position:relative; }
.seobetter-why-checklist li:last-child { border-bottom:none; }
.seobetter-why-checklist li::before { content:"☐";position:absolute;left:0;top:5px;color:#64748b;font-size:14px;line-height:1; }
.seobetter-why-toast { position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#0f172a;color:#fff;padding:10px 18px;border-radius:6px;font-size:13px;z-index:10000;opacity:0;transition:opacity .2s; }
.seobetter-why-toast.show { opacity:1; }
.seobetter-why-q { font-size:12px;padding:8px 10px;border-bottom:1px solid #f1f5f9;display:grid;grid-template-columns:1fr auto auto;gap:10px;align-items:center; }
.seobetter-why-q:last-child { border-bottom:none; }
.seobetter-why-q__sd { background:#dcfce7;color:#166534;font-size:10px;padding:1px 6px;border-radius:8px;font-weight:600;letter-spacing:.04em; }
</style>

<script>
(function() {
    var $btns = document.querySelectorAll('.seobetter-why-btn');
    var $overlay = document.getElementById('seobetter-why-overlay');
    var $body = document.getElementById('seobetter-why-body');
    var $title = document.getElementById('seobetter-why-title');
    var $subtitle = document.getElementById('seobetter-why-subtitle');
    var $close = document.getElementById('seobetter-why-close');
    if (!$btns.length || !$overlay) return;

    var nonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
    var restBase = '<?php echo esc_js( rest_url( 'seobetter/v1/freshness/diagnostic/' ) ); ?>';
    var pricingUrl = 'https://seobetter.com/pricing';

    function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
    function close() { $overlay.style.display = 'none'; }
    $close.addEventListener('click', close);
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape') close(); });

    function toast(msg) {
        var t = document.createElement('div');
        t.className = 'seobetter-why-toast';
        t.textContent = msg;
        document.body.appendChild(t);
        requestAnimationFrame(function() { t.classList.add('show'); });
        setTimeout(function() { t.classList.remove('show'); setTimeout(function(){ t.remove(); }, 250); }, 1800);
    }

    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() { toast('Copied: ' + text); });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); toast('Copied: ' + text); } catch (e) { toast('Copy failed — select and copy manually'); }
            ta.remove();
        }
    }

    function renderLocked(data, postId) {
        var tier = data.tier_required === 'pro_plus' ? 'Pro+' : 'Pro';
        return '<div style="padding:24px;background:linear-gradient(135deg,#faf5ff 0%,#ede9fe 100%);border:1px solid #ddd6fe;border-radius:8px;text-align:center">' +
            '<div style="font-size:11px;font-weight:600;letter-spacing:.05em;background:#8b5cf6;color:#fff;padding:3px 8px;border-radius:4px;display:inline-block">' + esc(tier.toUpperCase()) + ' FEATURE</div>' +
            '<h3 style="margin:14px 0 8px;font-size:15px;color:#5b21b6"><?php echo esc_js( __( 'Why? diagnostic is a Pro feature', 'seobetter' ) ); ?></h3>' +
            '<p style="margin:0 0 14px;font-size:13px;color:#4c1d95;line-height:1.5"><?php echo esc_js( __( 'Get a per-post breakdown of every signal pulling priority up — outdated year mentions, missing freshness signal, GSC click decay, position drift, and top queries that need attention.', 'seobetter' ) ); ?></p>' +
            '<a href="' + esc(pricingUrl) + '" target="_blank" class="button button-primary"><?php echo esc_js( __( 'See Pro pricing →', 'seobetter' ) ); ?></a>' +
            '</div>';
    }

    function severityLabel(sev) {
        if (sev === 'critical') return 'HIGH';
        if (sev === 'warning')  return 'MEDIUM';
        return 'LOW';
    }

    function renderSignals(data) {
        var html = '';

        // Primary CTA at the top — clear next-action for the user
        if (data.edit_url) {
            html += '<a href="' + esc(data.edit_url) + '" target="_blank" class="button button-primary" style="display:block;text-align:center;margin-bottom:14px;padding:8px"><?php echo esc_js( __( 'Edit this post →', 'seobetter' ) ); ?></a>';
        }

        if (data.signals && data.signals.length) {
            data.signals.forEach(function(s) {
                var sev = s.severity || 'info';
                html += '<div class="seobetter-why-signal seobetter-why-signal--' + esc(sev) + '">';
                html += '<div class="seobetter-why-signal__head"><div>' + esc(s.label) + '</div>';
                html += '<div class="seobetter-why-signal__contrib seobetter-why-signal__contrib--' + esc(sev) + '">' + esc(severityLabel(sev)) + '</div></div>';
                if (s.detail) html += '<div class="seobetter-why-signal__detail">' + esc(s.detail) + '</div>';

                // Inline snippets (year mentions) — show user where they appear in the post
                if (s.snippets && s.snippets.length) {
                    html += '<div class="seobetter-why-snippets">';
                    html += '<div style="font-size:11px;color:#64748b;margin-bottom:4px"><?php echo esc_js( __( 'Where they appear in your post:', 'seobetter' ) ); ?></div>';
                    s.snippets.forEach(function(snip) {
                        // Highlight the year inside the snippet
                        var safe = esc(snip).replace(/\b(20[12]\d)\b/g, '<mark style="background:#fef08a;padding:0 2px;border-radius:2px;font-weight:600">$1</mark>');
                        html += '<div class="seobetter-why-snippet">' + safe + '</div>';
                    });
                    html += '</div>';
                }

                // Inline preview line (e.g. "Last Updated: …") + Copy button
                if (s.preview_line) {
                    html += '<div class="seobetter-why-preview">' + esc(s.preview_line) + '</div>';
                }

                // Action checklist — concrete next-steps the user can take
                if (s.checklist && s.checklist.length) {
                    html += '<div class="seobetter-why-checklist"><div class="seobetter-why-checklist__title"><?php echo esc_js( __( 'What to do:', 'seobetter' ) ); ?></div><ul>';
                    s.checklist.forEach(function(step) {
                        html += '<li>' + esc(step) + '</li>';
                    });
                    html += '</ul></div>';
                }

                if (s.action && s.action.type === 'copy') {
                    html += '<div class="seobetter-why-signal__action"><button type="button" class="button button-small seobetter-why-copy" data-payload="' + esc(s.action.payload) + '">' + esc(s.action.label) + '</button></div>';
                }
                html += '</div>';
            });
        } else {
            html += '<p style="color:#64748b;font-size:13px;text-align:center;padding:20px 0"><?php echo esc_js( __( 'No urgent signals — this post is in good shape.', 'seobetter' ) ); ?></p>';
        }

        // GSC top queries (Pro+ only)
        if (data.has_gsc && data.top_queries && data.top_queries.length) {
            html += '<h4 style="margin:18px 0 8px;font-size:13px;color:#0f172a"><?php echo esc_js( __( 'Top queries — last 28 days', 'seobetter' ) ); ?></h4>';
            html += '<div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden">';
            data.top_queries.forEach(function(q) {
                html += '<div class="seobetter-why-q">';
                html += '<div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(q.query) + '">' + esc(q.query);
                if (q.striking_distance) html += ' <span class="seobetter-why-q__sd">STRIKING</span>';
                html += '</div>';
                html += '<div style="color:#475569">pos ' + esc(q.position.toFixed(1)) + '</div>';
                html += '<div style="color:#475569">' + esc(q.clicks) + ' clicks</div>';
                html += '</div>';
            });
            html += '</div>';
        } else if (data.has_gsc && (!data.top_queries || !data.top_queries.length)) {
            html += '<p style="color:#94a3b8;font-size:11px;text-align:center;padding:10px 0;margin:0"><?php echo esc_js( __( 'No GSC query data for this URL in the last 28 days.', 'seobetter' ) ); ?></p>';
        } else if (!data.gsc_connected) {
            html += '<div style="margin-top:18px;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;color:#475569"><?php echo esc_js( __( 'Connect Google Search Console (Pro+) to also see click decay, position drift, and top queries here.', 'seobetter' ) ); ?></div>';
        } else if (!data.can_use_gsc) {
            html += '<div style="margin-top:18px;padding:12px;background:linear-gradient(135deg,#faf5ff 0%,#ede9fe 100%);border:1px solid #ddd6fe;border-radius:6px;font-size:12px;color:#5b21b6"><span style="background:#8b5cf6;color:#fff;font-size:9px;padding:1px 5px;border-radius:3px;letter-spacing:.05em;font-weight:600">PRO+</span> <?php echo esc_js( __( 'Upgrade to see GSC click decay, position drift, and top queries for this post.', 'seobetter' ) ); ?></div>';
        }
        return html;
    }

    function bindCopyButtons(scope) {
        scope.querySelectorAll('.seobetter-why-copy').forEach(function(btn) {
            btn.addEventListener('click', function() {
                copyToClipboard(btn.getAttribute('data-payload') || '');
            });
        });
    }

    Array.prototype.forEach.call($btns, function(btn) {
        btn.addEventListener('click', function() {
            var postId = btn.getAttribute('data-post-id');
            $body.innerHTML = '<p style="color:#94a3b8;text-align:center;padding:30px 0"><?php echo esc_js( __( 'Loading…', 'seobetter' ) ); ?></p>';
            $title.textContent = '<?php echo esc_js( __( 'Why this priority?', 'seobetter' ) ); ?>';
            $subtitle.textContent = '';
            $overlay.style.display = 'block';

            fetch(restBase + postId, {
                headers: { 'X-WP-Nonce': nonce },
                credentials: 'same-origin'
            }).then(function(r) { return r.json(); }).then(function(data) {
                if (data && data.locked) {
                    $body.innerHTML = renderLocked(data, postId);
                    return;
                }
                if (data && data.error) {
                    $body.innerHTML = '<p style="color:#dc2626;text-align:center;padding:20px 0">' + esc(data.error) + '</p>';
                    return;
                }
                $title.textContent = data.post_title || '(post)';
                $subtitle.textContent = '<?php echo esc_js( __( 'Priority', 'seobetter' ) ); ?> ' + (data.priority || 0) + ' · ' + (data.word_count || 0) + ' <?php echo esc_js( __( 'words', 'seobetter' ) ); ?> · ' + (data.age_days || 0) + 'd';
                $body.innerHTML = renderSignals(data);
                bindCopyButtons($body);
            }).catch(function() {
                $body.innerHTML = '<p style="color:#dc2626;text-align:center;padding:20px 0"><?php echo esc_js( __( 'Failed to load diagnostic.', 'seobetter' ) ); ?></p>';
            });
        });
    });
})();
</script>

<script>
(function() {
    var table = document.getElementById('seobetter-freshness-inventory');
    if ( ! table ) return;
    var tbody = table.querySelector('tbody');
    var headers = table.querySelectorAll('th[data-sort]');
    var currentSort = { col: 'priority', dir: 'desc' };

    headers.forEach(function(th) {
        th.addEventListener('click', function() {
            var col = th.getAttribute('data-sort');
            var dir = ( currentSort.col === col && currentSort.dir === 'desc' ) ? 'asc' : 'desc';
            sortBy( col, dir );
            currentSort = { col: col, dir: dir };
            // Update arrows
            headers.forEach(function(h) {
                var arrow = h.querySelector('.sort-arrow');
                if ( ! arrow ) return;
                if ( h === th ) {
                    arrow.textContent = dir === 'desc' ? '▼' : '▲';
                    arrow.classList.add('active');
                } else {
                    arrow.textContent = '';
                    arrow.classList.remove('active');
                }
            });
        });
    });

    function sortBy( col, dir ) {
        var rows = Array.prototype.slice.call( tbody.querySelectorAll('tr') );
        rows.sort(function(a, b) {
            var av = a.getAttribute('data-' + col);
            var bv = b.getAttribute('data-' + col);
            // numeric for any non-title column
            var an = col === 'title' ? av : parseFloat(av);
            var bn = col === 'title' ? bv : parseFloat(bv);
            if ( an < bn ) return dir === 'desc' ? 1 : -1;
            if ( an > bn ) return dir === 'desc' ? -1 : 1;
            return 0;
        });
        rows.forEach(function(r) { tbody.appendChild(r); });
    }
})();
</script>
