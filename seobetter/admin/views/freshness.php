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
                    <td>
                        <a href="<?php echo esc_url( $row['edit_url'] ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'seobetter' ); ?></a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

</div>

<style>
#seobetter-freshness-inventory th[data-sort] { user-select: none; }
#seobetter-freshness-inventory th[data-sort]:hover { background: #f1f5f9; }
#seobetter-freshness-inventory .sort-arrow { font-size: 10px; color: #cbd5e1; margin-left: 2px; }
#seobetter-freshness-inventory .sort-arrow.active { color: #475569; }
</style>

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
