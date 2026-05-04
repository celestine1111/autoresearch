<?php if ( ! defined( 'ABSPATH' ) ) exit;

// v1.5.13 — gate uses can_use() instead of is_pro()
$is_pro = SEOBetter\License_Manager::can_use( 'content_brief' );
$brief = null;

// Handle brief generation
if ( isset( $_POST['seobetter_generate_brief'] ) && check_admin_referer( 'seobetter_brief_nonce' ) ) {
    $keyword    = sanitize_text_field( $_POST['keyword'] ?? '' );
    $audience   = sanitize_text_field( $_POST['audience'] ?? '' );
    $domain     = sanitize_text_field( $_POST['domain'] ?? 'general' );
    $word_count = absint( $_POST['word_count'] ?? 2000 );

    if ( $keyword ) {
        $generator = new SEOBetter\Content_Brief_Generator();
        $brief = $generator->generate( $keyword, [
            'audience'   => $audience,
            'domain'     => $domain,
            'word_count' => $word_count,
        ] );
    } else {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Please enter a keyword.', 'seobetter' ) . '</p></div>';
    }
}
?>
<div class="wrap seobetter-dashboard">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
        <div>
            <h1 style="margin:0;font-size:24px;font-weight:700;color:var(--sb-text,#1e293b)">Content Brief Generator</h1>
            <p style="margin:4px 0 0;font-size:14px;color:var(--sb-text-secondary,#64748b)">Create detailed content briefs with keyword strategy, outline, and competitive analysis.</p>
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
    <div style="padding:12px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;margin-bottom:20px;font-size:13px;color:#856404">
        <span class="dashicons dashicons-info-outline" style="margin-right:4px"></span>
        Free users can generate basic briefs. <a href="https://seobetter.com/pricing" target="_blank" style="color:#856404;font-weight:600">Upgrade to Pro</a> for competitor analysis, content gap detection, and unlimited briefs.
    </div>
    <?php endif; ?>

    <!-- Brief Form -->
    <div class="seobetter-card" style="margin-bottom:24px">
        <form method="post">
            <?php wp_nonce_field( 'seobetter_brief_nonce' ); ?>

            <div class="sb-section">
                <h3 class="sb-section-header"><span class="dashicons dashicons-edit-page"></span> Brief Settings</h3>

                <div class="sb-field">
                    <label>Target Keyword <span style="color:var(--sb-error)">*</span></label>
                    <input type="text" name="keyword" value="<?php echo esc_attr( $_POST['keyword'] ?? '' ); ?>" placeholder="e.g. best protein powder for beginners" required style="width:100%;height:44px;padding:10px 16px;font-size:14px;border:1px solid var(--sb-border,#e2e8f0);border-radius:8px" />
                </div>

                <div class="sb-field-row-3">
                    <div class="sb-field">
                        <label>Target Audience</label>
                        <input type="text" name="audience" value="<?php echo esc_attr( $_POST['audience'] ?? '' ); ?>" placeholder="e.g. fitness beginners, health-conscious adults" style="height:44px;padding:10px 16px;border:1px solid var(--sb-border,#e2e8f0);border-radius:8px" />
                    </div>
                    <div class="sb-field">
                        <label>Domain</label>
                        <select name="domain">
                            <?php // v1.5.15 — keep this list IDENTICAL to content-generator.php and bulk-generator.php. See plugin_UX.md §9. ?>
                            <option value="general" <?php selected( $_POST['domain'] ?? '', 'general' ); ?>>General</option>
                            <option value="animals" <?php selected( $_POST['domain'] ?? '', 'animals' ); ?>>Animals &amp; Pets (General)</option>
                            <option value="art_design" <?php selected( $_POST['domain'] ?? '', 'art_design' ); ?>>Art &amp; Design</option>
                            <option value="blockchain" <?php selected( $_POST['domain'] ?? '', 'blockchain' ); ?>>Blockchain</option>
                            <option value="books" <?php selected( $_POST['domain'] ?? '', 'books' ); ?>>Books &amp; Literature</option>
                            <option value="business" <?php selected( $_POST['domain'] ?? '', 'business' ); ?>>Business</option>
                            <option value="cryptocurrency" <?php selected( $_POST['domain'] ?? '', 'cryptocurrency' ); ?>>Cryptocurrency</option>
                            <option value="currency" <?php selected( $_POST['domain'] ?? '', 'currency' ); ?>>Currency &amp; Forex</option>
                            <option value="ecommerce" <?php selected( $_POST['domain'] ?? '', 'ecommerce' ); ?>>Ecommerce</option>
                            <option value="education" <?php selected( $_POST['domain'] ?? '', 'education' ); ?>>Education</option>
                            <option value="employment" <?php selected( $_POST['domain'] ?? '', 'employment' ); ?>>Employment, Career &amp; Workplace</option>
                            <option value="entertainment" <?php selected( $_POST['domain'] ?? '', 'entertainment' ); ?>>Entertainment &amp; Movies</option>
                            <option value="environment" <?php selected( $_POST['domain'] ?? '', 'environment' ); ?>>Environment &amp; Climate</option>
                            <option value="finance" <?php selected( $_POST['domain'] ?? '', 'finance' ); ?>>Finance &amp; Economics</option>
                            <option value="food" <?php selected( $_POST['domain'] ?? '', 'food' ); ?>>Food &amp; Drink</option>
                            <option value="games" <?php selected( $_POST['domain'] ?? '', 'games' ); ?>>Games &amp; Gaming</option>
                            <option value="government" <?php selected( $_POST['domain'] ?? '', 'government' ); ?>>Government, Law &amp; Politics</option>
                            <option value="health" <?php selected( $_POST['domain'] ?? '', 'health' ); ?>>Health &amp; Medical (Human)</option>
                            <option value="music" <?php selected( $_POST['domain'] ?? '', 'music' ); ?>>Music</option>
                            <option value="news" <?php selected( $_POST['domain'] ?? '', 'news' ); ?>>News &amp; Media</option>
                            <option value="science" <?php selected( $_POST['domain'] ?? '', 'science' ); ?>>Science &amp; Space</option>
                            <option value="sports" <?php selected( $_POST['domain'] ?? '', 'sports' ); ?>>Sports &amp; Fitness</option>
                            <option value="technology" <?php selected( $_POST['domain'] ?? '', 'technology' ); ?>>Technology</option>
                            <option value="transportation" <?php selected( $_POST['domain'] ?? '', 'transportation' ); ?>>Transportation &amp; Logistics</option>
                            <option value="travel" <?php selected( $_POST['domain'] ?? '', 'travel' ); ?>>Travel &amp; Tourism</option>
                            <option value="veterinary" <?php selected( $_POST['domain'] ?? '', 'veterinary' ); ?>>Veterinary &amp; Pet Health (Research)</option>
                            <option value="weather" <?php selected( $_POST['domain'] ?? '', 'weather' ); ?>>Weather &amp; Climate</option>
                        </select>
                    </div>
                    <div class="sb-field">
                        <label>Target Word Count</label>
                        <select name="word_count">
                            <option value="1000" <?php selected( $_POST['word_count'] ?? '', '1000' ); ?>>1,000</option>
                            <option value="1500" <?php selected( $_POST['word_count'] ?? '', '1500' ); ?>>1,500</option>
                            <option value="2000" <?php selected( $_POST['word_count'] ?? '2000', '2000' ); ?>>2,000</option>
                            <option value="2500" <?php selected( $_POST['word_count'] ?? '', '2500' ); ?>>2,500</option>
                            <option value="3000" <?php selected( $_POST['word_count'] ?? '', '3000' ); ?>>3,000</option>
                        </select>
                    </div>
                </div>
            </div>

            <button type="submit" name="seobetter_generate_brief" class="button sb-btn-primary" style="font-size:15px;padding:12px 32px;height:50px">
                Generate Brief
            </button>
        </form>
    </div>

    <!-- Brief Results -->
    <?php if ( $brief && ! empty( $brief['success'] ) ) : ?>
    <div id="sb-brief-result">

        <!-- Action Buttons -->
        <div style="display:flex;gap:12px;margin-bottom:20px">
            <button type="button" id="sb-copy-brief" class="button sb-btn-secondary" style="height:40px">
                <span class="dashicons dashicons-clipboard" style="margin-top:4px"></span> Copy Brief
            </button>
            <button type="button" id="sb-export-brief" class="button sb-btn-secondary" style="height:40px">
                <span class="dashicons dashicons-download" style="margin-top:4px"></span> Export as Text
            </button>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seobetter-generate&keyword=' . urlencode( $brief['keyword'] ?? '' ) ) ); ?>" class="button sb-btn-primary" style="height:40px;line-height:24px">
                Generate Article from Brief &rarr;
            </a>
        </div>

        <div class="seobetter-cards">

            <!-- Title -->
            <div class="seobetter-card sb-brief-section" data-section="title">
                <h2 style="margin-bottom:12px"><span class="dashicons dashicons-heading" style="color:var(--sb-primary,#764ba2);margin-right:6px"></span> Suggested Title</h2>
                <p style="font-size:18px;font-weight:600;color:var(--sb-text,#1e293b);margin:0"><?php echo esc_html( $brief['title'] ?? '' ); ?></p>
            </div>

            <!-- Keywords -->
            <div class="seobetter-card sb-brief-section" data-section="keywords">
                <h2 style="margin-bottom:12px"><span class="dashicons dashicons-tag" style="color:var(--sb-primary,#764ba2);margin-right:6px"></span> Keywords</h2>
                <div style="margin-bottom:12px">
                    <strong style="font-size:13px;color:var(--sb-text-secondary,#64748b)">Primary:</strong>
                    <span style="display:inline-block;padding:4px 12px;background:var(--sb-primary-light,#f3eef8);border-radius:4px;font-size:13px;font-weight:600;color:var(--sb-primary,#764ba2)"><?php echo esc_html( $brief['keyword'] ?? '' ); ?></span>
                </div>
                <?php if ( ! empty( $brief['secondary_keywords'] ) ) : ?>
                <div style="margin-bottom:12px">
                    <strong style="font-size:13px;color:var(--sb-text-secondary,#64748b)">Secondary:</strong>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:4px">
                        <?php foreach ( $brief['secondary_keywords'] as $kw ) : ?>
                            <span style="padding:4px 10px;background:var(--sb-bg,#f8fafc);border:1px solid var(--sb-border,#e2e8f0);border-radius:4px;font-size:12px"><?php echo esc_html( $kw ); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ( ! empty( $brief['lsi_keywords'] ) ) : ?>
                <div>
                    <strong style="font-size:13px;color:var(--sb-text-secondary,#64748b)">LSI / Semantic:</strong>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:4px">
                        <?php foreach ( $brief['lsi_keywords'] as $kw ) : ?>
                            <span style="padding:4px 10px;background:var(--sb-bg,#f8fafc);border:1px solid var(--sb-border,#e2e8f0);border-radius:4px;font-size:12px"><?php echo esc_html( $kw ); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Search Intent -->
            <div class="seobetter-card sb-brief-section" data-section="intent">
                <h2 style="margin-bottom:12px"><span class="dashicons dashicons-search" style="color:var(--sb-primary,#764ba2);margin-right:6px"></span> Search Intent</h2>
                <div style="display:flex;gap:12px;align-items:center;margin-bottom:8px">
                    <span class="seobetter-score seobetter-score-good"><?php echo esc_html( $brief['intent_type'] ?? 'Informational' ); ?></span>
                </div>
                <p style="font-size:14px;color:var(--sb-text-secondary,#64748b);margin:0;line-height:1.6"><?php echo esc_html( $brief['intent_description'] ?? '' ); ?></p>
            </div>

            <!-- Outline -->
            <div class="seobetter-card sb-brief-section" data-section="outline">
                <h2 style="margin-bottom:12px"><span class="dashicons dashicons-list-view" style="color:var(--sb-primary,#764ba2);margin-right:6px"></span> Content Outline</h2>
                <?php if ( ! empty( $brief['outline'] ) ) : ?>
                <div style="font-size:14px;line-height:2">
                    <?php foreach ( $brief['outline'] as $section ) : ?>
                    <div style="margin-bottom:8px">
                        <strong style="color:var(--sb-text,#1e293b)"><?php echo esc_html( $section['heading'] ?? '' ); ?></strong>
                        <?php if ( ! empty( $section['subheadings'] ) ) : ?>
                        <ul style="margin:4px 0 0 20px;list-style:disc;font-size:13px;color:var(--sb-text-secondary,#64748b)">
                            <?php foreach ( $section['subheadings'] as $sub ) : ?>
                                <li><?php echo esc_html( $sub ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Required Elements -->
            <div class="seobetter-card sb-brief-section" data-section="elements">
                <h2 style="margin-bottom:12px"><span class="dashicons dashicons-yes-alt" style="color:var(--sb-primary,#764ba2);margin-right:6px"></span> Required Elements</h2>
                <?php if ( ! empty( $brief['required_elements'] ) ) : ?>
                <ul style="list-style:none;padding:0;margin:0;font-size:13px;line-height:2.2">
                    <?php foreach ( $brief['required_elements'] as $element ) : ?>
                        <li><span style="color:var(--sb-success,#059669);margin-right:8px">&#10003;</span> <?php echo esc_html( $element ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <!-- Competitors -->
            <?php if ( ! empty( $brief['competitors'] ) ) : ?>
            <div class="seobetter-card sb-brief-section" data-section="competitors">
                <h2 style="margin-bottom:12px"><span class="dashicons dashicons-chart-bar" style="color:var(--sb-primary,#764ba2);margin-right:6px"></span> Competitor Analysis</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Competitor', 'seobetter' ); ?></th>
                            <th><?php esc_html_e( 'Analysis', 'seobetter' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $brief['competitors'] as $comp ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $comp['domain'] ?? '' ); ?></strong></td>
                            <td style="font-size:12px"><?php echo esc_html( $comp['analysis'] ?? '' ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Content Gap -->
            <?php if ( ! empty( $brief['content_gap'] ) ) : ?>
            <div class="seobetter-card sb-brief-section" data-section="content_gap">
                <h2 style="margin-bottom:12px"><span class="dashicons dashicons-visibility" style="color:var(--sb-primary,#764ba2);margin-right:6px"></span> Content Gap Opportunities</h2>
                <ul style="list-style:none;padding:0;margin:0;font-size:13px;line-height:2.2">
                    <?php foreach ( $brief['content_gap'] as $gap ) : ?>
                        <li><span style="color:var(--sb-warning,#f59e0b);margin-right:8px">&#9733;</span> <?php echo esc_html( $gap ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
    (function() {
        // Collect brief text from all sections
        function getBriefText() {
            var sections = document.querySelectorAll('.sb-brief-section');
            var text = '';
            sections.forEach(function(el) {
                text += el.innerText + '\n\n';
            });
            return text.trim();
        }

        // Copy to clipboard
        var copyBtn = document.getElementById('sb-copy-brief');
        if (copyBtn) {
            copyBtn.addEventListener('click', function() {
                var text = getBriefText();
                navigator.clipboard.writeText(text).then(function() {
                    copyBtn.textContent = 'Copied!';
                    setTimeout(function() { copyBtn.innerHTML = '<span class="dashicons dashicons-clipboard" style="margin-top:4px"></span> Copy Brief'; }, 2000);
                });
            });
        }

        // Export as text file
        var exportBtn = document.getElementById('sb-export-brief');
        if (exportBtn) {
            exportBtn.addEventListener('click', function() {
                var text = getBriefText();
                var blob = new Blob([text], { type: 'text/plain' });
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'content-brief-<?php echo esc_js( sanitize_file_name( $_POST['keyword'] ?? 'brief' ) ); ?>.txt';
                a.click();
                URL.revokeObjectURL(a.href);
            });
        }
    })();
    </script>

    <?php elseif ( $brief && empty( $brief['success'] ) ) : ?>
    <div class="notice notice-error"><p><?php echo esc_html( $brief['error'] ?? __( 'Failed to generate brief.', 'seobetter' ) ); ?></p></div>
    <?php endif; ?>

</div>
