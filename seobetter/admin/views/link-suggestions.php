<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$suggester        = new SEOBetter\Internal_Link_Suggester();
$can_use_pro      = SEOBetter\License_Manager::can_use( 'internal_links_suggester' );
$active_tab       = sanitize_key( $_GET['tab'] ?? 'orphan' );
$selected_post_id = absint( $_GET['post_id'] ?? 0 );

// Always run orphan scan — it's the free tier baseline + drives Pro+ upsell context
$orphan_report = $suggester->find_orphan_posts();
?>
<div class="wrap seobetter-dashboard">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
        <div>
            <h1 style="margin:0"><?php esc_html_e( 'Internal Links', 'seobetter' ); ?></h1>
            <p style="margin:4px 0 0;color:#64748b;font-size:14px">
                <?php esc_html_e( 'Find orphan pages (zero inbound internal links) and surface AI-suggested link opportunities.', 'seobetter' ); ?>
            </p>
        </div>
    </div>

    <!-- Tab nav -->
    <h2 class="nav-tab-wrapper" style="margin-bottom:16px">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seobetter-links&tab=orphan' ) ); ?>" class="nav-tab <?php echo $active_tab === 'orphan' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Orphan Pages', 'seobetter' ); ?>
            <span style="background:#64748b;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:6px;letter-spacing:0.05em">FREE</span>
            <?php if ( $orphan_report['orphan_count'] > 0 ) : ?>
                <span style="background:#dc2626;color:#fff;font-size:11px;padding:1px 8px;border-radius:10px;margin-left:6px;font-weight:700"><?php echo esc_html( $orphan_report['orphan_count'] ); ?></span>
            <?php endif; ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=seobetter-links&tab=suggester' ) ); ?>" class="nav-tab <?php echo $active_tab === 'suggester' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Link Suggestions', 'seobetter' ); ?>
            <span style="background:#8b5cf6;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:6px;letter-spacing:0.05em">PRO+</span>
        </a>
    </h2>

    <?php if ( $active_tab === 'orphan' ) : ?>

        <!-- ============ ORPHAN PAGES TAB (FREE) ============ -->
        <div class="seobetter-card" style="margin-bottom:20px;padding:18px 20px">
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px">
                <div style="padding:14px 16px;background:#fef2f2;border-radius:8px;border:1px solid #fecaca">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#991b1b;margin-bottom:6px"><?php esc_html_e( 'Orphan posts', 'seobetter' ); ?></div>
                    <div style="font-size:28px;font-weight:700;color:#dc2626;line-height:1"><?php echo esc_html( $orphan_report['orphan_count'] ); ?></div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:6px"><?php esc_html_e( 'Zero inbound internal links', 'seobetter' ); ?></div>
                </div>
                <div style="padding:14px 16px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:6px"><?php esc_html_e( '% orphaned', 'seobetter' ); ?></div>
                    <div style="font-size:28px;font-weight:700;color:<?php echo $orphan_report['orphan_pct'] >= 30 ? '#dc2626' : ( $orphan_report['orphan_pct'] >= 15 ? '#f59e0b' : '#10b981' ); ?>;line-height:1"><?php echo esc_html( $orphan_report['orphan_pct'] ); ?>%</div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:6px"><?php
                        printf(
                            /* translators: 1: orphan count, 2: total count */
                            esc_html__( '%1$d of %2$d posts', 'seobetter' ),
                            $orphan_report['orphan_count'],
                            $orphan_report['total_scanned']
                        );
                    ?></div>
                </div>
                <div style="padding:14px 16px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:6px"><?php esc_html_e( 'Total scanned', 'seobetter' ); ?></div>
                    <div style="font-size:28px;font-weight:700;color:#1e293b;line-height:1"><?php echo esc_html( $orphan_report['total_scanned'] ); ?></div>
                    <div style="font-size:11px;color:#94a3b8;margin-top:6px"><?php esc_html_e( 'Published posts + pages', 'seobetter' ); ?></div>
                </div>
            </div>
        </div>

        <?php if ( ! $can_use_pro ) : ?>
        <div class="seobetter-card" style="margin-bottom:20px;background:linear-gradient(135deg,#faf5ff 0%,#ede9fe 100%);border:1px solid #ddd6fe">
            <h3 style="margin:0 0 8px;color:#5b21b6;font-size:14px">
                <span style="background:#8b5cf6;color:#fff;font-size:10px;padding:2px 6px;border-radius:3px;letter-spacing:0.05em">PRO+</span>
                <?php esc_html_e( 'Get AI-powered link suggestions', 'seobetter' ); ?>
            </h3>
            <p style="margin:0 0 12px;font-size:13px;color:#4c1d95">
                <?php esc_html_e( 'Found orphans? Pro+ surfaces 5 AI-ranked link suggestions per post — relevance-scored anchor text from existing posts that should link TO this orphan. Link Whisper-style in-editor flow.', 'seobetter' ); ?>
            </p>
            <a href="https://seobetter.com/pricing" target="_blank" class="button button-primary"><?php esc_html_e( 'See Pro+ →', 'seobetter' ); ?></a>
        </div>
        <?php endif; ?>

        <div class="seobetter-card">
            <h2 style="margin:0 0 14px"><?php esc_html_e( 'Orphan Pages', 'seobetter' ); ?></h2>

            <?php if ( empty( $orphan_report['orphans'] ) ) : ?>
                <div style="padding:40px 20px;text-align:center;color:#10b981">
                    <span class="dashicons dashicons-yes-alt" style="font-size:32px;width:32px;height:32px;margin-bottom:8px"></span>
                    <p style="margin:0;font-size:15px;font-weight:600"><?php esc_html_e( 'No orphan posts found.', 'seobetter' ); ?></p>
                    <p style="margin:6px 0 0;color:#64748b;font-size:13px"><?php esc_html_e( 'Every published post has at least one inbound internal link.', 'seobetter' ); ?></p>
                </div>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Orphan post', 'seobetter' ); ?></th>
                            <th style="width:90px"><?php esc_html_e( 'GEO score', 'seobetter' ); ?></th>
                            <th style="width:100px"><?php esc_html_e( 'Age', 'seobetter' ); ?></th>
                            <th style="width:90px"><?php esc_html_e( 'Words', 'seobetter' ); ?></th>
                            <th style="width:200px"><?php esc_html_e( 'Action', 'seobetter' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $orphan_report['orphans'] as $o ) :
                            $geo = $o['geo_score'];
                            $geo_color = $geo >= 80 ? '#10b981' : ( $geo >= 60 ? '#f59e0b' : ( $geo > 0 ? '#dc2626' : '#94a3b8' ) );
                            $age_label = $o['age_days'] >= 365 ? sprintf( '%dy', (int) round( $o['age_days'] / 365 ) )
                                        : ( $o['age_days'] >= 30 ? sprintf( '%dmo', (int) round( $o['age_days'] / 30 ) )
                                        : sprintf( '%dd', $o['age_days'] ) );
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( $o['edit_url'] ); ?>" style="font-weight:600">
                                    <?php echo esc_html( $o['title'] ?: __( '(no title)', 'seobetter' ) ); ?>
                                </a>
                            </td>
                            <td>
                                <?php if ( $geo > 0 ) : ?>
                                    <span style="display:inline-block;padding:3px 9px;border-radius:10px;background:<?php echo esc_attr( $geo_color ); ?>1a;color:<?php echo esc_attr( $geo_color ); ?>;font-weight:600;font-size:12px"><?php echo esc_html( $geo ); ?></span>
                                <?php else : ?>
                                    <span style="color:#cbd5e1">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;color:#64748b"><?php echo esc_html( $age_label ); ?></td>
                            <td style="font-size:12px"><?php echo esc_html( number_format( $o['word_count'] ) ); ?></td>
                            <td>
                                <?php if ( $can_use_pro ) : ?>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=seobetter-links&tab=suggester&post_id=' . $o['id'] ) ); ?>" class="button button-primary button-small"><?php esc_html_e( 'Find inbound links →', 'seobetter' ); ?></a>
                                <?php else : ?>
                                    <a href="<?php echo esc_url( $o['edit_url'] ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'seobetter' ); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="description" style="margin-top:14px;padding:10px 14px;background:#eff6ff;border-left:3px solid #3b82f6;border-radius:4px;color:#1e3a5f">
                    <strong>💡 <?php esc_html_e( 'How orphans hurt:', 'seobetter' ); ?></strong>
                    <?php esc_html_e( 'Posts with zero inbound internal links signal weak topical authority to Google + AI search engines, are harder to crawl, and rarely rank. Sorted by GEO score DESC — high-quality orphans are the highest-value fixes.', 'seobetter' ); ?>
                </p>
            <?php endif; ?>
        </div>

    <?php elseif ( $active_tab === 'suggester' ) : ?>

        <!-- ============ LINK SUGGESTER TAB (PRO+) ============ -->
        <?php if ( ! $can_use_pro ) : ?>
            <!-- Locked state — show preview + upsell -->
            <div class="seobetter-card" style="background:linear-gradient(135deg,#faf5ff 0%,#ede9fe 100%);border:2px solid #8b5cf6;text-align:center;padding:48px 24px">
                <div style="font-size:48px;margin-bottom:16px">🔒</div>
                <h2 style="margin:0 0 8px;color:#5b21b6"><?php esc_html_e( 'AI Link Suggestions — Pro+', 'seobetter' ); ?></h2>
                <p style="font-size:15px;color:#4c1d95;max-width:600px;margin:0 auto 18px">
                    <?php esc_html_e( 'Pick any post and Pro+ ranks 5 inbound link opportunities by relevance score with suggested anchor text. Link Whisper-style in-editor flow. Captures the freelance / power-solopreneur segment that proves $77/yr willingness-to-pay just for this feature.', 'seobetter' ); ?>
                </p>
                <a href="https://seobetter.com/pricing" target="_blank" class="button button-primary" style="height:44px;line-height:28px;font-size:14px;padding:8px 28px">
                    <?php esc_html_e( 'Upgrade to Pro+ — $69/mo →', 'seobetter' ); ?>
                </a>
            </div>
        <?php else : ?>
            <!-- Active suggester -->
            <div class="seobetter-card">
                <form method="get" style="display:flex;gap:10px;align-items:center;margin-bottom:18px">
                    <input type="hidden" name="page" value="seobetter-links" />
                    <input type="hidden" name="tab" value="suggester" />
                    <label style="font-weight:600"><?php esc_html_e( 'Select a post:', 'seobetter' ); ?></label>
                    <select name="post_id" onchange="this.form.submit()" style="flex:1;max-width:500px">
                        <option value="0">— <?php esc_html_e( 'Pick a post to find inbound link opportunities', 'seobetter' ); ?> —</option>
                        <?php
                        $all_posts = get_posts( [
                            'post_type'      => [ 'post', 'page' ],
                            'post_status'    => 'publish',
                            'posts_per_page' => 200,
                            'orderby'        => 'date',
                            'order'          => 'DESC',
                        ] );
                        foreach ( $all_posts as $p ) :
                        ?>
                            <option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $selected_post_id, $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php if ( $selected_post_id > 0 ) :
                    $result = $suggester->suggest_for_post( $selected_post_id, 5 );
                ?>
                    <?php if ( ! empty( $result['success'] ) && ! empty( $result['suggestions'] ) ) : ?>
                        <h2 style="margin:0 0 14px"><?php
                            printf(
                                /* translators: 1: post title */
                                esc_html__( 'Suggestions for: %s', 'seobetter' ),
                                '<em>' . esc_html( $result['post_title'] ) . '</em>'
                            );
                        ?></h2>

                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Source post (should link TO above)', 'seobetter' ); ?></th>
                                    <th style="width:200px"><?php esc_html_e( 'Suggested anchor', 'seobetter' ); ?></th>
                                    <th style="width:90px"><?php esc_html_e( 'Relevance', 'seobetter' ); ?></th>
                                    <th style="width:80px"><?php esc_html_e( 'GEO', 'seobetter' ); ?></th>
                                    <th style="width:80px"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $result['suggestions'] as $s ) :
                                    $rel_color = $s['relevance_score'] >= 50 ? '#10b981' : ( $s['relevance_score'] >= 25 ? '#f59e0b' : '#94a3b8' );
                                    $geo = (int) $s['geo_score'];
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url( $s['edit_url'] ); ?>" style="font-weight:600"><?php echo esc_html( $s['target_title'] ); ?></a>
                                    </td>
                                    <td><code style="font-size:12px;background:#f1f5f9;padding:3px 6px;border-radius:3px"><?php echo esc_html( $s['anchor_text'] ); ?></code></td>
                                    <td>
                                        <span style="display:inline-block;padding:3px 9px;border-radius:10px;background:<?php echo esc_attr( $rel_color ); ?>1a;color:<?php echo esc_attr( $rel_color ); ?>;font-weight:600;font-size:12px"><?php echo esc_html( $s['relevance_score'] ); ?></span>
                                    </td>
                                    <td style="font-size:12px"><?php echo $geo > 0 ? esc_html( $geo ) : '<span style="color:#cbd5e1">—</span>'; ?></td>
                                    <td><a href="<?php echo esc_url( $s['edit_url'] ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'seobetter' ); ?></a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <p class="description" style="margin-top:14px;padding:10px 14px;background:#eff6ff;border-left:3px solid #3b82f6;border-radius:4px;color:#1e3a5f">
                            <strong>💡 <?php esc_html_e( 'How to use:', 'seobetter' ); ?></strong>
                            <?php esc_html_e( 'Open each source post and add a link from the suggested anchor text → the target post above. Relevance score is keyword overlap; higher = stronger topical fit.', 'seobetter' ); ?>
                        </p>
                    <?php elseif ( ! empty( $result['success'] ) ) : ?>
                        <div style="padding:40px 20px;text-align:center;color:#64748b">
                            <p style="margin:0;font-size:14px"><?php esc_html_e( 'No relevant link opportunities found for this post yet. Try expanding your content library or pick a post with more keyword overlap.', 'seobetter' ); ?></p>
                        </div>
                    <?php else : ?>
                        <div class="notice notice-error inline"><p><?php echo esc_html( $result['error'] ?? 'Unknown error' ); ?></p></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</div>
