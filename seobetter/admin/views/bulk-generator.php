<?php if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * v1.5.216.28 — Phase 1 item 9: Bulk CSV UX layer rewrite.
 *
 * Adds presets picker + per-row override visualization + quality gate display
 * + default-to-draft toggle + Action Scheduler queue mode indicator. Tier
 * badge updated from binary FREE/PRO → AGENCY (item 22 partial — full sweep
 * happens later when item 22 ships).
 */

// v1.5.216.28 — switched from binary is_pro check to Agency-only feature gate.
// Agency users see full UI; everyone else sees upsell with $179/mo target.
$is_agency = SEOBetter\License_Manager::can_use( 'bulk_content_generation' );
$tier_label = SEOBetter\License_Manager::get_active_tier();
$batch = null;
$batch_id = absint( $_GET['batch_id'] ?? 0 );

// Handle preset save/delete (top-of-page POST handlers run before form display)
if ( isset( $_POST['seobetter_save_bulk_preset'] ) && check_admin_referer( 'seobetter_bulk_preset_nonce' ) && $is_agency ) {
    $preset_id = sanitize_key( $_POST['preset_id'] ?? '' );
    $result = SEOBetter\Bulk_Generator::save_preset( $preset_id, [
        'name'         => $_POST['preset_name'] ?? '',
        'word_count'   => $_POST['word_count'] ?? 2000,
        'tone'         => $_POST['tone'] ?? 'authoritative',
        'domain'       => $_POST['domain'] ?? 'general',
        'content_type' => $_POST['content_type'] ?? 'blog_post',
        'country'      => $_POST['country'] ?? '',
        'language'     => $_POST['language'] ?? 'en',
        'auto_publish' => ! empty( $_POST['auto_publish'] ),
    ] );
    if ( ! empty( $result['success'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Preset saved.', 'seobetter' ) . '</p></div>';
    } else {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result['error'] ?? 'Save failed.' ) . '</p></div>';
    }
}

if ( isset( $_POST['seobetter_delete_bulk_preset'] ) && check_admin_referer( 'seobetter_bulk_preset_nonce' ) && $is_agency ) {
    $preset_id = sanitize_key( $_POST['preset_id'] ?? '' );
    if ( $preset_id !== '' && SEOBetter\Bulk_Generator::delete_preset( $preset_id ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Preset deleted.', 'seobetter' ) . '</p></div>';
    }
}

// Handle bulk start (form POST handler)
if ( isset( $_POST['seobetter_bulk_start'] ) && check_admin_referer( 'seobetter_bulk_nonce' ) ) {
    if ( $is_agency ) {
        $bulk = new SEOBetter\Bulk_Generator();
        $rows = [];

        if ( ! empty( $_FILES['csv_file']['tmp_name'] ) ) {
            $parsed = $bulk->parse_csv( $_FILES['csv_file']['tmp_name'] );
            if ( ! empty( $parsed['success'] ) ) {
                $rows = $parsed['rows'];
            }
        }

        if ( ! empty( $_POST['keywords_text'] ) ) {
            $rows = array_merge(
                $rows,
                $bulk->parse_textarea( sanitize_textarea_field( wp_unslash( $_POST['keywords_text'] ) ) )
            );
        }

        if ( ! empty( $rows ) ) {
            // v1.5.216.28 — defaults now include auto_publish + quality_floor + preset_id
            $new_batch_id = $bulk->create_batch( $rows, [
                'word_count'    => absint( $_POST['word_count'] ?? 2000 ),
                'tone'          => sanitize_text_field( $_POST['tone'] ?? 'authoritative' ),
                'domain'        => sanitize_text_field( $_POST['domain'] ?? 'general' ),
                'content_type'  => sanitize_text_field( $_POST['content_type'] ?? 'blog_post' ),
                'country'       => sanitize_text_field( $_POST['country'] ?? '' ),
                'language'      => sanitize_text_field( $_POST['language'] ?? 'en' ),
                'auto_publish'  => ! empty( $_POST['auto_publish'] ),
                'quality_floor' => absint( $_POST['quality_floor'] ?? SEOBetter\Bulk_Generator::QUALITY_FLOOR ),
                'preset_id'     => sanitize_key( $_POST['active_preset_id'] ?? '' ),
            ] );
            if ( $new_batch_id ) {
                $batch_id = $new_batch_id;
                $batch = $bulk->get_batch( $batch_id );
            }
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'No keywords provided. Please upload a CSV or enter keywords.', 'seobetter' ) . '</p></div>';
        }
    }
}

// Load existing batch
if ( $batch_id && ! $batch ) {
    $bulk_loader = new SEOBetter\Bulk_Generator();
    $batch = $bulk_loader->get_batch( $batch_id );
}

$presets = SEOBetter\Bulk_Generator::get_presets();
$has_action_scheduler = SEOBetter\Bulk_Generator::has_action_scheduler();
?>
<div class="wrap seobetter-dashboard">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
        <div>
            <h1 style="margin:0;font-size:24px;font-weight:700;color:var(--sb-text,#1e293b)">Bulk Content Generator</h1>
            <p style="margin:4px 0 0;font-size:14px;color:var(--sb-text-secondary,#64748b)">Generate articles for multiple keywords at once via CSV or keyword list. Quality-gated to GEO ≥ 40, saved as drafts by default.</p>
        </div>
        <div style="display:flex;gap:10px;align-items:center">
            <span class="seobetter-score seobetter-score-<?php echo $is_agency ? 'good' : 'ok'; ?>" style="font-size:13px;text-transform:uppercase">
                <?php echo esc_html( $tier_label ); ?>
            </span>
            <?php if ( ! $is_agency ) : ?>
                <a href="https://seobetter.com/pricing" target="_blank" class="button sb-btn-primary" style="height:36px;padding:6px 16px;font-size:13px;line-height:22px">
                    Upgrade to Agency &rarr;
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( ! $is_agency ) : ?>
    <!-- v1.5.216.28 — Agency upgrade notice (was Pro). Bulk CSV moved to Agency tier per locked plan §2 -->
    <div class="seobetter-card" style="padding:20px;background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%);border:2px solid #f59e0b;border-radius:8px;margin-bottom:24px">
        <h3 style="margin:0 0 8px;color:#92400e;display:flex;align-items:center;gap:8px">
            <span style="font-size:20px">🏢</span>
            Bulk Generation requires Agency ($179/mo)
        </h3>
        <p style="margin:0 0 16px;font-size:14px;color:#78350f">Upload a CSV with up to 100 keywords. Run quality-gated batches — articles below GEO 40 are auto-rejected so your CMS stays clean. Default-to-draft means you review before publish. 10 site licenses + 5 team seats included.</p>
        <a href="https://seobetter.com/pricing" target="_blank" class="button" style="font-size:14px;height:44px;line-height:28px;background:#92400e;color:#fff;border-color:#92400e;padding:8px 24px">
            Upgrade to Agency &rarr;
        </a>
    </div>
    <?php endif; ?>

    <!-- v1.5.216.28 — Action Scheduler status banner (when AS is available) -->
    <?php if ( $is_agency && $has_action_scheduler ) : ?>
    <div class="seobetter-card" style="padding:12px 16px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px;margin-bottom:16px;display:flex;align-items:center;gap:8px">
        <span style="font-size:16px">⚡</span>
        <div style="font-size:13px;color:#065f46">
            <strong>Background queue active.</strong> Batches run via Action Scheduler — you can close this tab and articles will keep generating. Check progress under Tools → Scheduled Actions (group: <code>seobetter-bulk</code>).
        </div>
    </div>
    <?php elseif ( $is_agency ) : ?>
    <div class="seobetter-card" style="padding:12px 16px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;margin-bottom:16px;display:flex;align-items:center;gap:8px">
        <span style="font-size:16px">📡</span>
        <div style="font-size:13px;color:#9a3412">
            <strong>Browser-driven mode.</strong> Keep this tab open during generation. Install <a href="https://wordpress.org/plugins/action-scheduler/" target="_blank" rel="noopener">Action Scheduler</a> (or activate WooCommerce) to enable background queue mode and close the tab safely.
        </div>
    </div>
    <?php endif; ?>

    <?php if ( $is_agency && ! empty( $presets ) ) : ?>
    <!-- v1.5.216.28 — Saved Presets card -->
    <div class="seobetter-card" style="margin-bottom:16px;padding:16px">
        <h3 style="margin:0 0 12px;font-size:14px;font-weight:700;color:#1f2937;display:flex;align-items:center;gap:8px">
            <span class="dashicons dashicons-saved"></span> Saved Presets
        </h3>
        <div style="display:flex;flex-wrap:wrap;gap:8px">
            <?php foreach ( $presets as $pid => $preset ) : ?>
                <div style="display:flex;align-items:center;gap:6px;padding:6px 12px;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:18px;font-size:12px">
                    <button type="button" class="sb-load-preset" data-preset='<?php echo esc_attr( wp_json_encode( $preset ) ); ?>' style="background:none;border:none;cursor:pointer;color:#5b21b6;font-weight:600;padding:0">
                        <?php echo esc_html( $preset['name'] ); ?>
                    </button>
                    <span style="color:#6b7280;font-size:11px"><?php echo esc_html( $preset['content_type'] ); ?> · <?php echo esc_html( $preset['word_count'] ); ?>w</span>
                    <form method="post" style="display:inline;margin-left:4px" onsubmit="return confirm('Delete preset \&quot;<?php echo esc_js( $preset['name'] ); ?>\&quot;?')">
                        <?php wp_nonce_field( 'seobetter_bulk_preset_nonce' ); ?>
                        <input type="hidden" name="preset_id" value="<?php echo esc_attr( $pid ); ?>" />
                        <button type="submit" name="seobetter_delete_bulk_preset" style="background:none;border:none;cursor:pointer;color:#dc2626;padding:0;font-size:14px;line-height:1" title="Delete preset">×</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Input Form -->
    <div class="seobetter-card" style="margin-bottom:24px;<?php echo ! $is_agency ? 'opacity:0.5;pointer-events:none;' : ''; ?>">
        <form method="post" enctype="multipart/form-data" id="sb-bulk-form">
            <?php wp_nonce_field( 'seobetter_bulk_nonce' ); ?>
            <input type="hidden" name="active_preset_id" id="sb-active-preset-id" value="" />

            <div class="sb-section">
                <h3 class="sb-section-header"><span class="dashicons dashicons-upload"></span> Keywords</h3>

                <div class="sb-field-row">
                    <div class="sb-field" style="flex:1">
                        <label>Upload CSV</label>
                        <input type="file" name="csv_file" accept=".csv" style="height:44px;padding:8px;border:1px solid var(--sb-border,#e2e8f0);border-radius:8px;width:100%" />
                        <div class="sb-help">
                            <strong>CSV columns (all optional except `keyword`):</strong> keyword, secondary_keywords, word_count, tone, domain, content_type, country, language. Per-row values override the defaults below. <a href="<?php echo esc_url( plugins_url( 'assets/sample-keywords.csv', dirname( __DIR__ ) ) ); ?>" download>Download sample CSV</a>
                        </div>
                    </div>
                    <div class="sb-field" style="flex:1">
                        <label>Or paste keywords (one per line)</label>
                        <textarea name="keywords_text" rows="5" placeholder="best running shoes 2026&#10;how to start a garden&#10;protein powder comparison" style="width:100%;padding:10px;border:1px solid var(--sb-border,#e2e8f0);border-radius:8px;font-size:13px"><?php echo esc_textarea( $_POST['keywords_text'] ?? '' ); ?></textarea>
                        <div class="sb-help">Textarea keywords use only the defaults below (no per-row override).</div>
                    </div>
                </div>
            </div>

            <div class="sb-section">
                <h3 class="sb-section-header"><span class="dashicons dashicons-admin-settings"></span> Article Settings <span style="font-size:11px;font-weight:400;color:#6b7280;margin-left:8px">CSV columns override these per-row</span></h3>

                <div class="sb-field-row-3">
                    <div class="sb-field">
                        <label>Word Count</label>
                        <select name="word_count" id="sb-bulk-word-count">
                            <option value="1000">1,000</option>
                            <option value="1500">1,500</option>
                            <option value="2000" selected>2,000</option>
                            <option value="2500">2,500</option>
                            <option value="3000">3,000</option>
                        </select>
                    </div>
                    <div class="sb-field">
                        <label>Tone</label>
                        <select name="tone" id="sb-bulk-tone">
                            <option value="authoritative" selected>Authoritative</option>
                            <option value="conversational">Conversational</option>
                            <option value="professional">Professional</option>
                            <option value="educational">Educational</option>
                            <option value="journalistic">Journalistic</option>
                        </select>
                    </div>
                    <div class="sb-field">
                        <label>Domain</label>
                        <select name="domain" id="sb-bulk-domain">
                            <?php // v1.5.15 — keep this list IDENTICAL to content-generator.php and content-brief.php. See plugin_UX.md §9. ?>
                            <option value="general">General</option>
                            <option value="animals">Animals &amp; Pets (General)</option>
                            <option value="art_design">Art &amp; Design</option>
                            <option value="blockchain">Blockchain</option>
                            <option value="books">Books &amp; Literature</option>
                            <option value="business">Business</option>
                            <option value="cryptocurrency">Cryptocurrency</option>
                            <option value="currency">Currency &amp; Forex</option>
                            <option value="ecommerce">Ecommerce</option>
                            <option value="education">Education</option>
                            <option value="entertainment">Entertainment &amp; Movies</option>
                            <option value="environment">Environment &amp; Climate</option>
                            <option value="finance">Finance &amp; Economics</option>
                            <option value="food">Food &amp; Drink</option>
                            <option value="games">Games &amp; Gaming</option>
                            <option value="government">Government, Law &amp; Politics</option>
                            <option value="health">Health &amp; Medical (Human)</option>
                            <option value="music">Music</option>
                            <option value="news">News &amp; Media</option>
                            <option value="science">Science &amp; Space</option>
                            <option value="sports">Sports &amp; Fitness</option>
                            <option value="technology">Technology</option>
                            <option value="transportation">Transportation &amp; Logistics</option>
                            <option value="travel">Travel &amp; Tourism</option>
                            <option value="veterinary">Veterinary &amp; Pet Health (Research)</option>
                            <option value="weather">Weather &amp; Climate</option>
                        </select>
                    </div>
                </div>

                <!-- v1.5.216.28 — additional defaults: content_type, country, language -->
                <div class="sb-field-row-3" style="margin-top:12px">
                    <div class="sb-field">
                        <label>Content Type</label>
                        <select name="content_type" id="sb-bulk-content-type">
                            <option value="blog_post" selected>Blog Post</option>
                            <option value="how_to">How-To Guide</option>
                            <option value="listicle">Listicle</option>
                            <option value="review">Review</option>
                            <option value="comparison">Comparison</option>
                            <option value="buying_guide">Buying Guide</option>
                            <option value="news_article">News Article</option>
                            <option value="recipe">Recipe</option>
                            <option value="faq_page">FAQ Page</option>
                            <option value="tech_article">Tech Article</option>
                            <option value="case_study">Case Study</option>
                        </select>
                    </div>
                    <div class="sb-field">
                        <label>Country (optional)</label>
                        <input type="text" name="country" id="sb-bulk-country" placeholder="US, GB, AU…" style="width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:8px" maxlength="2" />
                    </div>
                    <div class="sb-field">
                        <label>Language</label>
                        <select name="language" id="sb-bulk-language">
                            <option value="en" selected>English</option>
                            <option value="es">Spanish</option>
                            <option value="fr">French</option>
                            <option value="de">German</option>
                            <option value="it">Italian</option>
                            <option value="pt">Portuguese</option>
                            <option value="ja">Japanese</option>
                            <option value="ko">Korean</option>
                            <option value="zh">Chinese</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- v1.5.216.28 — Quality gate + draft toggle -->
            <div class="sb-section">
                <h3 class="sb-section-header"><span class="dashicons dashicons-shield"></span> Quality Gate</h3>
                <div class="sb-field-row">
                    <div class="sb-field" style="flex:1">
                        <label>Minimum GEO score (articles below this are rejected)</label>
                        <select name="quality_floor">
                            <option value="0">Off — accept all articles (not recommended)</option>
                            <option value="40" selected>40 (recommended) — block F-grade junk</option>
                            <option value="60">60 — only D-grade or better</option>
                            <option value="80">80 — only A-grade quality (may reject many)</option>
                        </select>
                        <div class="sb-help">Items below floor are marked <em>failed_quality</em> and not saved as posts. Their score is shown so you can see WHY they were rejected.</div>
                    </div>
                    <div class="sb-field" style="flex:1">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding-top:24px">
                            <input type="checkbox" name="auto_publish" id="sb-bulk-auto-publish" value="1" style="margin:0" />
                            <span>Auto-publish (skip draft review)</span>
                        </label>
                        <div class="sb-help">Default: OFF. Articles save as drafts — review before publish. Only enable for trusted topic + content_type + country combos.</div>
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                <button type="submit" name="seobetter_bulk_start" class="button sb-btn-primary" style="font-size:15px;padding:12px 32px;height:50px">
                    Start Bulk Generation
                </button>
                <button type="button" id="sb-save-preset-btn" class="button" style="font-size:13px;padding:8px 16px;height:44px">
                    💾 Save current settings as preset
                </button>
            </div>
        </form>
    </div>

    <!-- v1.5.216.28 — Save Preset modal (hidden by default; shown via JS) -->
    <?php if ( $is_agency ) : ?>
    <div id="sb-save-preset-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99999;align-items:center;justify-content:center">
        <form method="post" style="background:#fff;padding:24px;border-radius:8px;width:90%;max-width:500px">
            <?php wp_nonce_field( 'seobetter_bulk_preset_nonce' ); ?>
            <h3 style="margin:0 0 16px">Save Bulk Preset</h3>
            <p style="margin:0 0 16px;font-size:13px;color:#6b7280">Save the current Article Settings + Quality Gate values as a named preset. Reusable across batches.</p>
            <input type="hidden" name="preset_id" value="" />
            <input type="hidden" name="word_count" id="sb-modal-word-count" />
            <input type="hidden" name="tone" id="sb-modal-tone" />
            <input type="hidden" name="domain" id="sb-modal-domain" />
            <input type="hidden" name="content_type" id="sb-modal-content-type" />
            <input type="hidden" name="country" id="sb-modal-country" />
            <input type="hidden" name="language" id="sb-modal-language" />
            <input type="hidden" name="auto_publish" id="sb-modal-auto-publish" />
            <div class="sb-field" style="margin-bottom:16px">
                <label>Preset name</label>
                <input type="text" name="preset_name" required placeholder="e.g. Recipe Roundups EN-US" style="width:100%;padding:10px;border:1px solid #e2e8f0;border-radius:8px" />
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end">
                <button type="button" id="sb-modal-cancel" class="button">Cancel</button>
                <button type="submit" name="seobetter_save_bulk_preset" class="button button-primary">Save Preset</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Batch Progress -->
    <?php if ( $batch ) : ?>
    <div class="seobetter-card" id="sb-batch-progress">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px">
            <h2 style="margin:0">Batch #<?php echo esc_html( $batch['id'] ); ?> Progress</h2>
            <div style="display:flex;gap:8px;align-items:center">
                <?php if ( ! empty( $batch['queue_mode'] ) && $batch['queue_mode'] === 'action_scheduler' ) : ?>
                    <span style="padding:4px 10px;background:#ecfdf5;color:#065f46;border-radius:4px;font-size:11px;font-weight:600">⚡ Background queue</span>
                <?php endif; ?>
                <?php if ( ! empty( $batch['auto_publish'] ) ) : ?>
                    <span style="padding:4px 10px;background:#fef3c7;color:#92400e;border-radius:4px;font-size:11px;font-weight:600">⚠️ Auto-publish</span>
                <?php else : ?>
                    <span style="padding:4px 10px;background:#eff6ff;color:#1e40af;border-radius:4px;font-size:11px;font-weight:600">📝 Saving as drafts</span>
                <?php endif; ?>
                <span style="padding:4px 10px;background:#f5f3ff;color:#5b21b6;border-radius:4px;font-size:11px;font-weight:600">GEO floor <?php echo esc_html( $batch['quality_floor'] ?? 40 ); ?></span>
                <span id="sb-batch-status" class="seobetter-score seobetter-score-ok"><?php echo esc_html( ucfirst( $batch['status'] ?? 'pending' ) ); ?></span>
            </div>
        </div>

        <!-- v1.5.216.28 — 4-stat counter row -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:16px">
            <div style="text-align:center;padding:10px;background:#f9fafb;border-radius:6px">
                <div style="font-size:22px;font-weight:700;color:#1f2937" id="sb-stat-total"><?php echo esc_html( $batch['total'] ); ?></div>
                <div style="font-size:11px;color:#6b7280">Total</div>
            </div>
            <div style="text-align:center;padding:10px;background:#ecfdf5;border-radius:6px">
                <div style="font-size:22px;font-weight:700;color:#059669" id="sb-stat-completed"><?php echo esc_html( $batch['completed'] ); ?></div>
                <div style="font-size:11px;color:#065f46">Completed</div>
            </div>
            <div style="text-align:center;padding:10px;background:#fef2f2;border-radius:6px">
                <div style="font-size:22px;font-weight:700;color:#dc2626" id="sb-stat-failed"><?php echo esc_html( $batch['failed'] ); ?></div>
                <div style="font-size:11px;color:#991b1b">Failed</div>
            </div>
            <div style="text-align:center;padding:10px;background:#fffbeb;border-radius:6px">
                <div style="font-size:22px;font-weight:700;color:#d97706" id="sb-stat-quality"><?php echo esc_html( $batch['failed_quality'] ?? 0 ); ?></div>
                <div style="font-size:11px;color:#92400e">Quality-rejected</div>
            </div>
        </div>

        <!-- Progress bar -->
        <div style="background:#e9ecef;border-radius:4px;height:24px;margin-bottom:20px;overflow:hidden">
            <div id="sb-batch-bar" style="background:var(--sb-primary,#764ba2);height:100%;width:0%;transition:width 0.3s;border-radius:4px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600;font-size:12px">0%</div>
        </div>

        <table class="widefat striped" id="sb-batch-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Keyword', 'seobetter' ); ?></th>
                    <th style="width:140px"><?php esc_html_e( 'Status', 'seobetter' ); ?></th>
                    <th><?php esc_html_e( 'Post Title / Error', 'seobetter' ); ?></th>
                    <th style="width:90px"><?php esc_html_e( 'GEO', 'seobetter' ); ?></th>
                    <th style="width:160px"><?php esc_html_e( 'Overrides', 'seobetter' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $batch['items'] as $item ) :
                    $status = $item['status'] ?? 'pending';
                    if ( $status === 'completed' ) {
                        $status_class = 'good';
                    } elseif ( $status === 'failed_quality' ) {
                        $status_class = 'ok'; // amber for quality-rejected (different from generation-failed)
                    } elseif ( $status === 'failed' ) {
                        $status_class = 'poor';
                    } else {
                        $status_class = 'ok';
                    }
                    $score = $item['geo_score'] ?? null;
                    $score_class = $score ? ( $score >= 80 ? 'good' : ( $score >= 60 ? 'ok' : 'poor' ) ) : '';
                    $overrides = $item['_csv_overrides'] ?? [];
                ?>
                <tr data-keyword="<?php echo esc_attr( $item['keyword'] ); ?>">
                    <td><?php echo esc_html( $item['keyword'] ); ?></td>
                    <td><span class="seobetter-score seobetter-score-<?php echo esc_attr( $status_class ); ?> sb-item-status"><?php echo esc_html( str_replace( '_', ' ', ucfirst( $status ) ) ); ?></span></td>
                    <td class="sb-item-title">
                        <?php if ( ! empty( $item['post_id'] ) ) :
                            $item_title = $item['post_title'] ?? get_the_title( $item['post_id'] );
                        ?>
                            <a href="<?php echo esc_url( get_edit_post_link( $item['post_id'] ) ); ?>"><?php echo esc_html( $item_title ); ?></a>
                        <?php elseif ( ! empty( $item['error'] ) ) : ?>
                            <span style="color:#dc2626;font-size:12px"><?php echo esc_html( $item['error'] ); ?></span>
                        <?php else : ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td class="sb-item-score">
                        <?php if ( $score_class ) : ?>
                            <span class="seobetter-score seobetter-score-<?php echo esc_attr( $score_class ); ?>"><?php echo esc_html( $score ); ?></span>
                        <?php else : ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td class="sb-item-overrides" style="font-size:11px;color:#64748b">
                        <?php if ( ! empty( $overrides ) ) : ?>
                            <?php foreach ( $overrides as $o ) : ?>
                                <span style="display:inline-block;padding:1px 6px;margin:1px;background:#f5f3ff;color:#5b21b6;border-radius:8px;font-size:10px"><?php echo esc_html( $o ); ?></span>
                            <?php endforeach; ?>
                        <?php else : ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
    (function() {
        var batchId = <?php echo absint( $batch['id'] ); ?>;
        var batchStatus = '<?php echo esc_js( $batch['status'] ?? 'pending' ); ?>';
        var queueMode = '<?php echo esc_js( $batch['queue_mode'] ?? 'ajax' ); ?>';
        var bar = document.getElementById('sb-batch-bar');
        var statusEl = document.getElementById('sb-batch-status');
        var statTotal = document.getElementById('sb-stat-total');
        var statCompleted = document.getElementById('sb-stat-completed');
        var statFailed = document.getElementById('sb-stat-failed');
        var statQuality = document.getElementById('sb-stat-quality');

        function pollBatch() {
            if (batchStatus === 'completed' || batchStatus === 'failed') return;

            // v1.5.216.28 — POST drives processing in AJAX mode; in AS mode we
            // could just GET status, but POSTing also works (process_next is
            // idempotent if no pending item) and keeps a single code path.
            var endpoint = queueMode === 'action_scheduler'
                ? '<?php echo esc_url( rest_url( 'seobetter/v1/bulk-status/' ) ); ?>' + batchId
                : '<?php echo esc_url( rest_url( 'seobetter/v1/bulk-process/' ) ); ?>' + batchId;
            var method = queueMode === 'action_scheduler' ? 'GET' : 'POST';

            fetch(endpoint, {
                method: method,
                headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data) return;

                var pct = data.progress || 0;
                bar.style.width = pct + '%';
                bar.textContent = pct + '%';
                if (pct >= 100) bar.style.background = 'var(--sb-success,#059669)';

                batchStatus = data.status || batchStatus;
                statusEl.textContent = batchStatus.charAt(0).toUpperCase() + batchStatus.slice(1);
                statusEl.className = 'seobetter-score seobetter-score-' + (batchStatus === 'completed' ? 'good' : 'ok');

                if (statCompleted && data.completed !== undefined) statCompleted.textContent = data.completed;
                if (statFailed && data.failed !== undefined) statFailed.textContent = data.failed;
                if (statQuality && data.failed_quality !== undefined) statQuality.textContent = data.failed_quality;

                if (data.items) {
                    data.items.forEach(function(item) {
                        var row = document.querySelector('tr[data-keyword="' + CSS.escape(item.keyword) + '"]');
                        if (!row) return;
                        var sc;
                        if (item.status === 'completed') sc = 'good';
                        else if (item.status === 'failed_quality') sc = 'ok';
                        else if (item.status === 'failed') sc = 'poor';
                        else sc = 'ok';
                        row.querySelector('.sb-item-status').textContent = (item.status || '').replace('_',' ').replace(/^./, function(c){return c.toUpperCase();});
                        row.querySelector('.sb-item-status').className = 'seobetter-score seobetter-score-' + sc + ' sb-item-status';
                        var titleCell = row.querySelector('.sb-item-title');
                        if (item.post_id && item.post_title) {
                            titleCell.innerHTML = '<a href="' + item.edit_url + '">' + item.post_title + '</a>';
                        } else if (item.error) {
                            titleCell.innerHTML = '<span style="color:#dc2626;font-size:12px">' + item.error + '</span>';
                        }
                        if (item.geo_score) {
                            var gs = item.geo_score >= 80 ? 'good' : (item.geo_score >= 60 ? 'ok' : 'poor');
                            row.querySelector('.sb-item-score').innerHTML = '<span class="seobetter-score seobetter-score-' + gs + '">' + item.geo_score + '</span>';
                        }
                    });
                }

                if (batchStatus !== 'completed' && batchStatus !== 'failed') {
                    setTimeout(pollBatch, 3000);
                }
            })
            .catch(function() {
                setTimeout(pollBatch, 5000);
            });
        }

        if (batchStatus === 'pending' || batchStatus === 'processing') {
            pollBatch();
        }
    })();
    </script>
    <?php endif; ?>

    <?php if ( $is_agency ) : ?>
    <script>
    // v1.5.216.28 — Preset load + save modal wiring
    (function() {
        // Load preset → fill form fields
        document.querySelectorAll('.sb-load-preset').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var preset = JSON.parse(this.getAttribute('data-preset'));
                if (!preset) return;
                var f = function(id) { return document.getElementById(id); };
                if (f('sb-bulk-word-count')) f('sb-bulk-word-count').value = preset.word_count || 2000;
                if (f('sb-bulk-tone')) f('sb-bulk-tone').value = preset.tone || 'authoritative';
                if (f('sb-bulk-domain')) f('sb-bulk-domain').value = preset.domain || 'general';
                if (f('sb-bulk-content-type')) f('sb-bulk-content-type').value = preset.content_type || 'blog_post';
                if (f('sb-bulk-country')) f('sb-bulk-country').value = preset.country || '';
                if (f('sb-bulk-language')) f('sb-bulk-language').value = preset.language || 'en';
                if (f('sb-bulk-auto-publish')) f('sb-bulk-auto-publish').checked = !!preset.auto_publish;
                if (f('sb-active-preset-id')) f('sb-active-preset-id').value = preset.id || '';
                document.getElementById('sb-bulk-form').scrollIntoView({behavior:'smooth'});
            });
        });

        // Save preset modal
        var saveBtn = document.getElementById('sb-save-preset-btn');
        var modal = document.getElementById('sb-save-preset-modal');
        var cancelBtn = document.getElementById('sb-modal-cancel');
        if (saveBtn && modal) {
            saveBtn.addEventListener('click', function() {
                // Snapshot current form values into the modal hidden inputs
                document.getElementById('sb-modal-word-count').value = document.getElementById('sb-bulk-word-count').value;
                document.getElementById('sb-modal-tone').value = document.getElementById('sb-bulk-tone').value;
                document.getElementById('sb-modal-domain').value = document.getElementById('sb-bulk-domain').value;
                document.getElementById('sb-modal-content-type').value = document.getElementById('sb-bulk-content-type').value;
                document.getElementById('sb-modal-country').value = document.getElementById('sb-bulk-country').value;
                document.getElementById('sb-modal-language').value = document.getElementById('sb-bulk-language').value;
                document.getElementById('sb-modal-auto-publish').value = document.getElementById('sb-bulk-auto-publish').checked ? '1' : '';
                modal.style.display = 'flex';
            });
        }
        if (cancelBtn && modal) {
            cancelBtn.addEventListener('click', function() { modal.style.display = 'none'; });
        }
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) modal.style.display = 'none';
            });
        }
    })();
    </script>
    <?php endif; ?>

</div>
