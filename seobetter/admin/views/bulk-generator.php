<?php if ( ! defined( 'ABSPATH' ) ) exit;

// v1.5.13 — gate uses can_use() instead of is_pro() so feature can be moved
// between FREE/PRO tiers in License_Manager without editing each view.
$is_pro = SEOBetter\License_Manager::can_use( 'bulk_content_generation' );
$batch = null;
$batch_id = absint( $_GET['batch_id'] ?? 0 );

// Handle bulk start
if ( isset( $_POST['seobetter_bulk_start'] ) && check_admin_referer( 'seobetter_bulk_nonce' ) ) {
    if ( $is_pro ) {
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
            $new_batch_id = $bulk->create_batch( $rows, [
                'word_count' => absint( $_POST['word_count'] ?? 2000 ),
                'tone'       => sanitize_text_field( $_POST['tone'] ?? 'authoritative' ),
                'domain'     => sanitize_text_field( $_POST['domain'] ?? 'general' ),
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
?>
<div class="wrap seobetter-dashboard">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
        <div>
            <h1 style="margin:0;font-size:24px;font-weight:700;color:var(--sb-text,#1e293b)">Bulk Content Generator</h1>
            <p style="margin:4px 0 0;font-size:14px;color:var(--sb-text-secondary,#64748b)">Generate articles for multiple keywords at once via CSV or keyword list.</p>
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
    <!-- Pro Upgrade Notice -->
    <div class="seobetter-card" style="padding:20px;background:var(--sb-primary-light,#f3eef8);border:2px solid var(--sb-primary,#764ba2);border-radius:8px;margin-bottom:24px">
        <h3 style="margin:0 0 8px;color:var(--sb-primary,#764ba2)">
            <span class="seobetter-score seobetter-score-good" style="background:var(--sb-primary,#764ba2);color:#fff;margin-right:6px">PRO</span>
            Bulk Generation requires Pro
        </h3>
        <p style="margin:0 0 16px;font-size:14px;color:var(--sb-text-secondary,#64748b)">Upload a CSV with 50+ keywords and generate optimized articles for all of them in one batch. Save hours of content creation time.</p>
        <a href="https://seobetter.com/pricing" target="_blank" class="button sb-btn-primary" style="font-size:14px;height:44px;line-height:28px">
            Upgrade to Pro &rarr;
        </a>
    </div>
    <?php endif; ?>

    <!-- Input Form -->
    <div class="seobetter-card" style="margin-bottom:24px;<?php echo ! $is_pro ? 'opacity:0.5;pointer-events:none;' : ''; ?>">
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'seobetter_bulk_nonce' ); ?>

            <div class="sb-section">
                <h3 class="sb-section-header"><span class="dashicons dashicons-upload"></span> Keywords</h3>

                <div class="sb-field-row">
                    <div class="sb-field" style="flex:1">
                        <label>Upload CSV</label>
                        <input type="file" name="csv_file" accept=".csv" style="height:44px;padding:8px;border:1px solid var(--sb-border,#e2e8f0);border-radius:8px;width:100%" />
                        <div class="sb-help">
                            One keyword per row (first column). <a href="<?php echo esc_url( plugins_url( 'assets/sample-keywords.csv', dirname( __DIR__ ) ) ); ?>" download>Download sample CSV</a>
                        </div>
                    </div>
                    <div class="sb-field" style="flex:1">
                        <label>Or paste keywords (one per line)</label>
                        <textarea name="keywords_text" rows="5" placeholder="best running shoes 2026&#10;how to start a garden&#10;protein powder comparison" style="width:100%;padding:10px;border:1px solid var(--sb-border,#e2e8f0);border-radius:8px;font-size:13px"><?php echo esc_textarea( $_POST['keywords_text'] ?? '' ); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="sb-section">
                <h3 class="sb-section-header"><span class="dashicons dashicons-admin-settings"></span> Article Settings</h3>

                <div class="sb-field-row-3">
                    <div class="sb-field">
                        <label>Word Count</label>
                        <select name="word_count">
                            <option value="1000">1,000</option>
                            <option value="1500">1,500</option>
                            <option value="2000" selected>2,000</option>
                            <option value="2500">2,500</option>
                            <option value="3000">3,000</option>
                        </select>
                    </div>
                    <div class="sb-field">
                        <label>Tone</label>
                        <select name="tone">
                            <option value="authoritative" selected>Authoritative</option>
                            <option value="conversational">Conversational</option>
                            <option value="professional">Professional</option>
                            <option value="educational">Educational</option>
                            <option value="journalistic">Journalistic</option>
                        </select>
                    </div>
                    <div class="sb-field">
                        <label>Domain</label>
                        <select name="domain">
                            <?php // v1.5.15 — keep this list IDENTICAL to content-generator.php and content-brief.php. See plugin_UX.md §9. ?>
                            <option value="general">General</option>
                            <option value="animals">Animals &amp; Pets (Trivia)</option>
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
                            <option value="veterinary">Veterinary &amp; Pet Health</option>
                            <option value="weather">Weather &amp; Climate</option>
                        </select>
                    </div>
                </div>
            </div>

            <button type="submit" name="seobetter_bulk_start" class="button sb-btn-primary" style="font-size:15px;padding:12px 32px;height:50px">
                Start Bulk Generation
            </button>
        </form>
    </div>

    <!-- Batch Progress -->
    <?php if ( $batch ) : ?>
    <div class="seobetter-card" id="sb-batch-progress">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h2 style="margin:0">Batch #<?php echo esc_html( $batch['id'] ); ?> Progress</h2>
            <span id="sb-batch-status" class="seobetter-score seobetter-score-ok"><?php echo esc_html( ucfirst( $batch['status'] ?? 'pending' ) ); ?></span>
        </div>

        <!-- Progress bar -->
        <div style="background:#e9ecef;border-radius:4px;height:24px;margin-bottom:20px;overflow:hidden">
            <div id="sb-batch-bar" style="background:var(--sb-primary,#764ba2);height:100%;width:0%;transition:width 0.3s;border-radius:4px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600;font-size:12px">0%</div>
        </div>

        <table class="widefat striped" id="sb-batch-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Keyword', 'seobetter' ); ?></th>
                    <th style="width:120px"><?php esc_html_e( 'Status', 'seobetter' ); ?></th>
                    <th><?php esc_html_e( 'Post Title', 'seobetter' ); ?></th>
                    <th style="width:100px"><?php esc_html_e( 'GEO Score', 'seobetter' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $batch['items'] as $item ) :
                    $status_class = $item['status'] === 'completed' ? 'good' : ( $item['status'] === 'failed' ? 'poor' : 'ok' );
                    $score = $item['geo_score'] ?? null;
                    $score_class = $score ? ( $score >= 80 ? 'good' : ( $score >= 60 ? 'ok' : 'poor' ) ) : '';
                ?>
                <tr data-keyword="<?php echo esc_attr( $item['keyword'] ); ?>">
                    <td><?php echo esc_html( $item['keyword'] ); ?></td>
                    <td><span class="seobetter-score seobetter-score-<?php echo esc_attr( $status_class ); ?> sb-item-status"><?php echo esc_html( ucfirst( $item['status'] ) ); ?></span></td>
                    <td class="sb-item-title">
                        <?php if ( ! empty( $item['post_id'] ) ) :
                            $item_title = $item['post_title'] ?? get_the_title( $item['post_id'] );
                        ?>
                            <a href="<?php echo esc_url( get_edit_post_link( $item['post_id'] ) ); ?>"><?php echo esc_html( $item_title ); ?></a>
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
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
    (function() {
        var batchId = <?php echo absint( $batch['id'] ); ?>;
        var batchStatus = '<?php echo esc_js( $batch['status'] ?? 'pending' ); ?>';
        var bar = document.getElementById('sb-batch-bar');
        var statusEl = document.getElementById('sb-batch-status');

        function pollBatch() {
            if (batchStatus === 'completed' || batchStatus === 'failed') return;

            fetch('<?php echo esc_url( rest_url( 'seobetter/v1/bulk-process/' ) ); ?>' + batchId, {
                method: 'POST',
                headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data) return;

                // Update progress bar
                var pct = data.progress || 0;
                bar.style.width = pct + '%';
                bar.textContent = pct + '%';
                if (pct >= 100) bar.style.background = 'var(--sb-success,#059669)';

                // Update status badge
                batchStatus = data.status || batchStatus;
                statusEl.textContent = batchStatus.charAt(0).toUpperCase() + batchStatus.slice(1);
                statusEl.className = 'seobetter-score seobetter-score-' + (batchStatus === 'completed' ? 'good' : 'ok');

                // Update rows
                if (data.items) {
                    data.items.forEach(function(item) {
                        var row = document.querySelector('tr[data-keyword="' + CSS.escape(item.keyword) + '"]');
                        if (!row) return;
                        var sc = item.status === 'completed' ? 'good' : (item.status === 'failed' ? 'poor' : 'ok');
                        row.querySelector('.sb-item-status').textContent = item.status.charAt(0).toUpperCase() + item.status.slice(1);
                        row.querySelector('.sb-item-status').className = 'seobetter-score seobetter-score-' + sc + ' sb-item-status';
                        if (item.post_id && item.post_title) {
                            row.querySelector('.sb-item-title').innerHTML = '<a href="' + item.edit_url + '">' + item.post_title + '</a>';
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

</div>
