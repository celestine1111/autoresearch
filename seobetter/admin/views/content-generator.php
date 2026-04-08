<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$status = SEOBetter\Cloud_API::check_status();
$result = null;
$outline_result = null;
$affiliates = [];

// Handle article generation
if ( isset( $_POST['seobetter_generate_article'] ) && check_admin_referer( 'seobetter_generate_nonce' ) ) {
    $generator = new SEOBetter\AI_Content_Generator();

    $primary = sanitize_text_field( $_POST['primary_keyword'] ?? '' );
    $secondary = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $_POST['secondary_keywords'] ?? '' ) ) ) );
    $lsi = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $_POST['lsi_keywords'] ?? '' ) ) ) );

    // Parse affiliate links
    $affiliates = [];
    if ( ! empty( $_POST['affiliates'] ) && is_array( $_POST['affiliates'] ) ) {
        foreach ( $_POST['affiliates'] as $aff ) {
            $url = esc_url_raw( $aff['url'] ?? '' );
            $keyword = sanitize_text_field( $aff['keyword'] ?? '' );
            $name = sanitize_text_field( $aff['name'] ?? $keyword );
            if ( $url && $keyword ) {
                $affiliates[] = [ 'url' => $url, 'keyword' => $keyword, 'name' => $name ];
            }
        }
    }

    $raw_color = sanitize_text_field( $_POST['accent_color'] ?? '#764ba2' );
    $accent_color = preg_match( '/^#[0-9a-fA-F]{6}$/', $raw_color ) ? $raw_color : '#764ba2';

    $result = $generator->generate( $primary, [
        'word_count'         => absint( $_POST['word_count'] ?? 2000 ),
        'tone'               => sanitize_text_field( $_POST['tone'] ?? 'authoritative' ),
        'audience'           => sanitize_text_field( $_POST['audience'] ?? 'general' ),
        'domain'             => sanitize_text_field( $_POST['domain'] ?? 'general' ),
        'secondary_keywords' => $secondary,
        'lsi_keywords'       => $lsi,
        'editor_mode'        => 'classic',
        'accent_color'       => $accent_color,
    ] );

    // Apply affiliate links to generated content
    if ( $result['success'] && ! empty( $affiliates ) ) {
        $linker = new SEOBetter\Affiliate_Linker();
        $add_cta = ! empty( $_POST['affiliate_cta'] );
        if ( ! $add_cta ) {
            // Still link keywords but skip CTAs
            foreach ( $affiliates as &$aff_item ) {
                $aff_item['skip_cta'] = true;
            }
        }
        $result['content'] = $linker->process( $result['content'], $affiliates, 'classic', $accent_color );
    }
}

// Handle outline generation
if ( isset( $_POST['seobetter_generate_outline'] ) && check_admin_referer( 'seobetter_generate_nonce' ) ) {
    $generator = new SEOBetter\AI_Content_Generator();
    $primary = sanitize_text_field( $_POST['primary_keyword'] ?? '' );
    $outline_result = $generator->generate_outline( $primary );
}

// Handle "Re-optimize" — fix GEO issues flagged by the analyzer
if ( isset( $_POST['seobetter_reoptimize'] ) && check_admin_referer( 'seobetter_draft_nonce' ) ) {
    $content_to_fix = wp_kses_post( $_POST['draft_content'] ?? '' );
    $suggestions = json_decode( stripslashes( $_POST['reoptimize_suggestions'] ?? '[]' ), true );
    $keyword = sanitize_text_field( $_POST['draft_keyword'] ?? '' );

    if ( $content_to_fix && ! empty( $suggestions ) ) {
        // Strip HTML to clean text so the AI gets readable content, not messy HTML
        $clean_text = wp_strip_all_tags( $content_to_fix );
        // Preserve headings structure by converting HTML headings to markdown first
        $md_content = preg_replace( '/<h1[^>]*>(.*?)<\/h1>/is', '# $1', $content_to_fix );
        $md_content = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/is', '## $1', $md_content );
        $md_content = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/is', '### $1', $md_content );
        $md_content = preg_replace( '/<li[^>]*>(.*?)<\/li>/is', '- $1', $md_content );
        $md_content = preg_replace( '/<blockquote[^>]*>(.*?)<\/blockquote>/is', '> $1', $md_content );
        $md_content = preg_replace( '/<strong>(.*?)<\/strong>/is', '**$1**', $md_content );
        $md_content = preg_replace( '/<em>(.*?)<\/em>/is', '*$1*', $md_content );
        $md_content = wp_strip_all_tags( $md_content );

        // Only include the specific issues that need fixing
        $fix_list = [];
        foreach ( $suggestions as $s ) {
            $fix_list[] = '- ' . ( $s['message'] ?? $s );
        }
        $fix_instructions = implode( "\n", $fix_list );

        $fix_prompt = "You are re-optimizing an existing article about \"{$keyword}\". Fix ONLY these specific issues:\n\n{$fix_instructions}\n\nCRITICAL RULES:\n- Keep the EXACT same structure, headings, and topic coverage\n- Keep all existing statistics, quotes, citations, and tables — only ADD more where needed\n- Do NOT remove or shorten any content — only enhance\n- Do NOT rewrite sections that are already good\n- Keep the article at least the same length (2000+ words)\n- Start with \"Last Updated: " . wp_date( 'F Y' ) . "\" freshness signal\n- Include ## Key Takeaways with 3 bullets near the top\n- Every H2/H3 section must open with a 40-60 word paragraph answering the heading\n- Never start paragraphs with pronouns (It, This, They)\n- Include 3+ stats per 1000 words with (Source, Year)\n- Include 2+ expert quotes\n- Include 5+ inline citations in [Source, Year] format\n- Include at least 1 comparison table in Markdown format\n- End with FAQ section (3-5 Q&A) and References section\n\nOriginal article:\n\n{$md_content}\n\nReturn the FULL improved article in GitHub Flavored Markdown. Do not truncate or shorten it.";

        $system = "You are an expert content optimizer specializing in Generative Engine Optimization (GEO). Your job is to fix the specific flagged issues while preserving everything else. The output must be LONGER and MORE detailed than the input, never shorter. Write at a grade 6-8 reading level. Output complete GitHub Flavored Markdown with tables, lists, blockquotes, and all formatting.";

        $provider = SEOBetter\AI_Provider_Manager::get_active_provider();
        $request_options = [ 'max_tokens' => 8192, 'temperature' => 0.5 ];

        if ( $provider ) {
            $reopt_result = SEOBetter\AI_Provider_Manager::send_request( $provider['provider_id'], $fix_prompt, $system, $request_options );
        } else {
            $reopt_result = SEOBetter\Cloud_API::generate( $fix_prompt, $system, $request_options );
        }

        if ( ! empty( $reopt_result['success'] ) && ! empty( $reopt_result['content'] ) ) {
            // Use the full Content_Formatter for proper HTML output
            $formatter = new SEOBetter\Content_Formatter();
            $accent = sanitize_text_field( $_POST['accent_color'] ?? '#764ba2' );
            $html = $formatter->format( $reopt_result['content'], 'classic', [
                'accent_color' => preg_match( '/^#[0-9a-fA-F]{6}$/', $accent ) ? $accent : '#764ba2',
            ] );

            $analyzer = new SEOBetter\GEO_Analyzer();
            $score = $analyzer->analyze( $html, $keyword );

            $result = [
                'success'    => true,
                'content'    => $html,
                'markdown'   => $reopt_result['content'],
                'keyword'    => $keyword,
                'geo_score'  => $score['geo_score'],
                'grade'      => $score['grade'],
                'word_count' => str_word_count( wp_strip_all_tags( $html ) ),
                'model_used' => $reopt_result['model'] ?? 'unknown',
                'suggestions' => $score['suggestions'],
                'reoptimized' => true,
            ];
        } else {
            $result = [
                'success' => false,
                'error'   => $reopt_result['error'] ?? 'Re-optimization failed.',
            ];
        }
    }
}

// Handle "Create as Draft"
if ( isset( $_POST['seobetter_create_draft'] ) && check_admin_referer( 'seobetter_draft_nonce' ) ) {
    $title    = sanitize_text_field( $_POST['draft_title'] ?? $_POST['draft_keyword'] ?? 'New Article' );
    $markdown = wp_unslash( $_POST['draft_markdown'] ?? '' );
    $accent   = sanitize_text_field( $_POST['draft_accent_color'] ?? '#764ba2' );
    if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $accent ) ) {
        $accent = '#764ba2';
    }

    $content = '';

    if ( ! empty( $markdown ) ) {
        // Format as Gutenberg blocks
        $formatter = new SEOBetter\Content_Formatter();
        $content   = $formatter->format( $markdown, 'gutenberg', [
            'accent_color' => $accent,
        ] );

        // Re-apply affiliate links
        $draft_affiliates = json_decode( wp_unslash( $_POST['draft_affiliates'] ?? '[]' ), true );
        if ( ! empty( $draft_affiliates ) && is_array( $draft_affiliates ) ) {
            $linker  = new SEOBetter\Affiliate_Linker();
            $content = $linker->process( $content, $draft_affiliates, 'classic', $accent );
        }
    }

    // If Gutenberg formatting produced empty content, use raw HTML fallback
    if ( empty( trim( $content ) ) ) {
        $raw_html = wp_unslash( $_POST['draft_content'] ?? '' );
        if ( ! empty( $raw_html ) ) {
            $content = $raw_html;
        } elseif ( ! empty( $markdown ) ) {
            // Last resort: wrap raw markdown in a single HTML block
            $content = "<!-- wp:html -->\n" . nl2br( esc_html( $markdown ) ) . "\n<!-- /wp:html -->";
        }
    }

    $post_id = wp_insert_post( [
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => 'draft',
        'post_type'    => 'post',
    ] );

    if ( $post_id && ! is_wp_error( $post_id ) ) {
        echo '<div class="notice notice-success"><p>' . sprintf(
            esc_html__( 'Draft created! %s', 'seobetter' ),
            '<a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html__( 'Edit post', 'seobetter' ) . '</a>'
        ) . '</p></div>';
    }
}

$license = SEOBetter\License_Manager::get_info();
$is_pro = $license['is_pro'];
$ta_active = SEOBetter\Affiliate_Linker::is_thirstyaffiliates_active();
$ta_links = $ta_active ? SEOBetter\Affiliate_Linker::get_ta_links() : [];
$cloud_url = SEOBetter\Cloud_API::get_cloud_url();
$home = home_url();
$saved_aff = $_POST['affiliates'] ?? [ [ 'url' => '', 'keyword' => '', 'name' => '' ] ];
$pre_keyword = $_GET['keyword'] ?? $_POST['primary_keyword'] ?? '';
?>
<div class="wrap seobetter-dashboard">
    <h1 style="margin-bottom:16px"><?php esc_html_e( 'AI Content Generator', 'seobetter' ); ?></h1>

    <!-- Status Bar -->
    <div class="seobetter-status-bar" style="margin-bottom:20px">
        <span><strong>AI:</strong>
            <?php if ( $status['has_own_key'] ) :
                $active_provider = SEOBetter\AI_Provider_Manager::get_active_provider();
                $model_name = $active_provider['model'] ?? 'unknown';
                $provider_name = $active_provider['name'] ?? '';
            ?>
                <span class="seobetter-score seobetter-score-good"><?php echo esc_html( $provider_name ); ?></span>
                <code style="font-size:11px;margin:0 6px"><?php echo esc_html( $model_name ); ?></code>
                <span style="color:var(--sb-text-muted)">Unlimited</span>
            <?php else : ?>
                <span class="seobetter-score seobetter-score-ok">Cloud</span> <span style="color:var(--sb-text-muted)">(<?php echo esc_html( $status['monthly_used'] ); ?>/<?php echo esc_html( $status['monthly_limit'] ); ?> used)</span>
            <?php endif; ?>
        </span>
        <?php
        $trend_status = SEOBetter\Trend_Researcher::get_status();
        ?>
        <span style="margin-left:12px;font-size:12px">
            <strong>Research:</strong>
            <?php if ( $trend_status['available'] ) : ?>
                <span style="color:var(--sb-success)">Last30Days</span>
                <span style="color:var(--sb-text-muted)">(<?php echo esc_html( $trend_status['source_count'] ); ?> sources)</span>
            <?php else : ?>
                <span style="color:var(--sb-text-muted)">AI only</span>
            <?php endif; ?>
        </span>
        <?php if ( ! $status['has_own_key'] ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seobetter-settings' ) ); ?>" style="margin-left:auto;font-size:12px">Connect API key for unlimited &rarr;</a>
        <?php endif; ?>
    </div>

    <!-- 2-Column Layout -->
    <div class="sb-generator-layout">

        <!-- ===== LEFT: Main Form ===== -->
        <div class="sb-generator-main">
            <form method="post">
                <?php wp_nonce_field( 'seobetter_generate_nonce' ); ?>

                <!-- Keywords Section -->
                <div class="sb-section">
                    <h3 class="sb-section-header"><span class="dashicons dashicons-search"></span> Keywords</h3>

                    <div class="sb-field">
                        <label>Primary Keyword <span style="color:var(--sb-error)">*</span></label>
                        <div style="display:flex;gap:8px">
                            <input type="text" id="primary_keyword" name="primary_keyword" value="<?php echo esc_attr( $pre_keyword ); ?>" placeholder="e.g. equine vet supplies" required style="flex:1" />
                            <button type="button" id="seobetter-auto-keywords" class="button sb-btn-secondary" style="height:44px;white-space:nowrap;padding:0 16px">Auto-suggest</button>
                        </div>
                        <div class="sb-help">Target keyword for your article. <span id="seobetter-auto-status" style="font-style:italic"></span></div>
                    </div>

                    <div class="sb-field-row">
                        <div class="sb-field">
                            <label>Secondary Keywords
                                <span class="seobetter-tooltip"><span class="dashicons dashicons-info-outline"></span>
                                    <span class="seobetter-tooltip-text">Related phrases placed in headings and body to rank for multiple terms.</span>
                                </span>
                            </label>
                            <input type="text" name="secondary_keywords" value="<?php echo esc_attr( $_POST['secondary_keywords'] ?? '' ); ?>" placeholder="horse vet supplies, equine medical supplies" />
                            <div class="sb-help">Comma-separated</div>
                        </div>
                        <div class="sb-field">
                            <label>LSI / Semantic Keywords
                                <span class="seobetter-tooltip"><span class="dashicons dashicons-info-outline"></span>
                                    <span class="seobetter-tooltip-text">Terms AI models expect alongside your keyword. Boosts citations by Google AI Overviews, ChatGPT, Perplexity &amp; Gemini.</span>
                                </span>
                            </label>
                            <input type="text" name="lsi_keywords" value="<?php echo esc_attr( $_POST['lsi_keywords'] ?? '' ); ?>" placeholder="equine wound care, horse first aid" />
                            <div class="sb-help">Comma-separated — optimizes for AI citations</div>
                        </div>
                    </div>
                </div>

                <!-- Settings Section -->
                <div class="sb-section">
                    <h3 class="sb-section-header"><span class="dashicons dashicons-admin-settings"></span> Article Settings</h3>

                    <div class="sb-field-row-3">
                        <div class="sb-field">
                            <label>Word Count</label>
                            <select name="word_count">
                                <option value="1000" <?php selected( $_POST['word_count'] ?? '', '1000' ); ?>>1,000</option>
                                <option value="1500" <?php selected( $_POST['word_count'] ?? '', '1500' ); ?>>1,500</option>
                                <option value="2000" <?php selected( $_POST['word_count'] ?? '2000', '2000' ); ?>>2,000</option>
                                <option value="2500" <?php selected( $_POST['word_count'] ?? '', '2500' ); ?>>2,500</option>
                                <option value="3000" <?php selected( $_POST['word_count'] ?? '', '3000' ); ?>>3,000</option>
                            </select>
                        </div>
                        <div class="sb-field">
                            <label>Tone</label>
                            <select name="tone">
                                <option value="authoritative" <?php selected( $_POST['tone'] ?? 'authoritative', 'authoritative' ); ?>>Authoritative</option>
                                <option value="conversational" <?php selected( $_POST['tone'] ?? '', 'conversational' ); ?>>Conversational</option>
                                <option value="professional" <?php selected( $_POST['tone'] ?? '', 'professional' ); ?>>Professional</option>
                                <option value="educational" <?php selected( $_POST['tone'] ?? '', 'educational' ); ?>>Educational</option>
                                <option value="journalistic" <?php selected( $_POST['tone'] ?? '', 'journalistic' ); ?>>Journalistic</option>
                            </select>
                        </div>
                        <div class="sb-field">
                            <label>Domain</label>
                            <select name="domain">
                                <option value="general">General</option>
                                <option value="ecommerce" <?php selected( $_POST['domain'] ?? '', 'ecommerce' ); ?>>Ecommerce</option>
                                <option value="health" <?php selected( $_POST['domain'] ?? '', 'health' ); ?>>Health / Veterinary</option>
                                <option value="technology" <?php selected( $_POST['domain'] ?? '', 'technology' ); ?>>Technology</option>
                                <option value="business" <?php selected( $_POST['domain'] ?? '', 'business' ); ?>>Business / Finance</option>
                                <option value="science" <?php selected( $_POST['domain'] ?? '', 'science' ); ?>>Science</option>
                                <option value="education" <?php selected( $_POST['domain'] ?? '', 'education' ); ?>>Education</option>
                                <option value="law_government" <?php selected( $_POST['domain'] ?? '', 'law_government' ); ?>>Law / Government</option>
                            </select>
                        </div>
                    </div>

                    <div class="sb-field-row">
                        <div class="sb-field">
                            <label>Target Audience</label>
                            <input type="text" name="audience" value="<?php echo esc_attr( $_POST['audience'] ?? '' ); ?>" placeholder="e.g. horse owners, equine vets" />
                        </div>
                        <div class="sb-field">
                            <label>Accent Color</label>
                            <div style="display:flex;gap:10px;align-items:center">
                                <input type="color" name="accent_color" value="<?php echo esc_attr( $_POST['accent_color'] ?? '#764ba2' ); ?>" style="width:44px;height:44px;padding:4px;cursor:pointer;border:1px solid var(--sb-border);border-radius:6px" />
                                <span class="sb-help" style="margin:0">Headings, borders, CTA buttons</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Affiliate Links Section -->
                <div class="sb-section">
                    <h3 class="sb-section-header">
                        <span class="dashicons dashicons-money-alt"></span> Affiliate Links
                        <span style="font-weight:400;font-size:12px;color:var(--sb-text-muted);margin-left:4px">optional</span>
                        <?php if ( $ta_active ) : ?>
                            <span style="margin-left:auto;font-size:12px;color:var(--sb-success)"><span class="dashicons dashicons-yes-alt" style="font-size:14px;width:14px;height:14px;vertical-align:middle"></span> ThirstyAffiliates</span>
                        <?php endif; ?>
                    </h3>

                    <div id="sb-aff-list">
                        <?php foreach ( $saved_aff as $idx => $aff ) : ?>
                        <div class="sb-aff-row">
                            <input type="url" name="affiliates[<?php echo $idx; ?>][url]" value="<?php echo esc_attr( $aff['url'] ?? '' ); ?>" placeholder="Affiliate URL" class="sb-aff-url" />
                            <input type="text" name="affiliates[<?php echo $idx; ?>][keyword]" value="<?php echo esc_attr( $aff['keyword'] ?? '' ); ?>" placeholder="Keyword to link" class="sb-aff-kw" />
                            <input type="text" name="affiliates[<?php echo $idx; ?>][name]" value="<?php echo esc_attr( $aff['name'] ?? '' ); ?>" placeholder="CTA name" class="sb-aff-name" />
                            <button type="button" class="sb-aff-x" onclick="if(document.querySelectorAll('.sb-aff-row').length>1)this.parentElement.remove()">&times;</button>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:flex;gap:12px;align-items:center;margin-top:8px">
                        <button type="button" id="sb-add-aff" class="button sb-btn-sm">+ Add Link</button>
                        <label style="font-size:12px;color:var(--sb-text-secondary)"><input type="checkbox" name="affiliate_cta" value="1" <?php checked( $_POST['affiliate_cta'] ?? '1', '1' ); ?> /> Auto-insert CTA buttons</label>
                    </div>
                </div>

                <!-- Generate Buttons -->
                <div style="display:flex;gap:12px;margin-bottom:24px">
                    <button type="submit" name="seobetter_generate_article" id="seobetter-async-generate" class="button sb-btn-primary" style="font-size:15px;padding:12px 32px;height:50px">
                        Generate Article
                    </button>
                    <button type="submit" name="seobetter_generate_outline" class="button sb-btn-secondary" style="font-size:15px;padding:12px 32px;height:50px">
                        Generate Outline
                    </button>
                </div>

                <!-- Async Progress Panel (hidden by default) -->
                <div id="seobetter-progress-panel" style="display:none;padding:24px;background:var(--sb-card,#fff);border:1px solid var(--sb-border,#e0e0e0);border-radius:8px;margin-bottom:24px">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                        <h3 id="seobetter-progress-title" style="margin:0;font-size:15px">Generating article...</h3>
                        <span id="seobetter-progress-time" style="font-size:12px;color:var(--sb-text-muted,#888)">0:00</span>
                    </div>
                    <div style="background:var(--sb-bg,#f5f5f5);border-radius:6px;height:28px;overflow:hidden;margin-bottom:12px">
                        <div id="seobetter-progress-bar" style="height:100%;background:linear-gradient(90deg,#764ba2,#667eea);border-radius:6px;transition:width 0.5s ease;width:0%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:600">0%</div>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <span id="seobetter-progress-label" style="font-size:13px;color:var(--sb-text-secondary,#666)">Starting...</span>
                        <span id="seobetter-progress-steps" style="font-size:12px;color:var(--sb-text-muted,#888)"></span>
                    </div>
                    <div id="seobetter-progress-error" style="display:none;margin-top:12px;padding:12px;background:#fff3cd;border:1px solid #ffc107;border-radius:6px;font-size:13px">
                        <strong>Error:</strong> <span id="seobetter-progress-error-msg"></span>
                        <button type="button" id="seobetter-retry-btn" class="button" style="margin-left:12px;height:30px;font-size:12px">Retry</button>
                    </div>
                    <div id="seobetter-progress-estimate" style="margin-top:8px;font-size:11px;color:var(--sb-text-muted,#888)"></div>
                </div>

                <!-- Result container for AJAX results -->
                <div id="seobetter-async-result" style="display:none"></div>

            </form>
        </div>

        <!-- ===== RIGHT: Sidebar ===== -->
        <div class="sb-generator-sidebar">

            <!-- GEO Tips -->
            <div class="sb-sidebar-card">
                <h3><span class="dashicons dashicons-lightbulb" style="color:var(--sb-warning);margin-right:4px;font-size:14px;width:14px;height:14px"></span> What Makes Content Rank</h3>
                <ul>
                    <li><span style="color:var(--sb-success)">+41%</span> Expert quotes with credentials</li>
                    <li><span style="color:var(--sb-success)">+30%</span> Statistics with (Source, Year)</li>
                    <li><span style="color:var(--sb-success)">+28%</span> Inline citations [Source, Year]</li>
                    <li><span style="color:var(--sb-success)">+27%</span> Fluency optimization</li>
                    <li><span style="color:var(--sb-error)">-8%</span> Keyword stuffing (blocked)</li>
                </ul>
                <div class="sb-help" style="margin-top:8px">Source: KDD 2024 GEO Research</div>
            </div>

            <!-- Pro Upsell (if not pro) -->
            <?php if ( ! $is_pro ) : ?>
            <div class="sb-upsell-card">
                <h3>Unlock Pro</h3>
                <p>Get unlimited generation, bulk CSV import, auto content refresh, comparison page builder, and internal link suggestions.</p>
                <a href="https://seobetter.com/pricing" target="_blank" class="button sb-btn-primary" style="font-size:13px;height:38px;line-height:20px">Upgrade to Pro &rarr;</a>
            </div>
            <?php endif; ?>

            <!-- Topic Suggester (compact) -->
            <div class="sb-sidebar-card">
                <h3><span class="dashicons dashicons-editor-help" style="color:var(--sb-info);margin-right:4px;font-size:14px;width:14px;height:14px"></span> Need Ideas?</h3>
                <div class="sb-field" style="margin-bottom:8px">
                    <input type="text" id="sb-niche-input" placeholder="Your niche (e.g. equine health)" style="height:36px;font-size:12px" />
                </div>
                <button type="button" id="sb-suggest-btn" class="button sb-btn-sm" style="width:100%">Suggest 10 Topics</button>
                <span id="sb-topics-status" style="display:block;font-size:11px;color:var(--sb-text-muted);margin-top:6px"></span>
                <div id="sb-topics-list" style="display:none;margin-top:10px;font-size:12px;line-height:1.8"></div>
            </div>

            <!-- Built-in Protocol -->
            <div class="sb-sidebar-card" style="background:var(--sb-bg)">
                <h3>Every Article Includes</h3>
                <ul style="font-size:11px;line-height:1.9">
                    <li>&#10003; Key Takeaways (BLUF header)</li>
                    <li>&#10003; 40-60 word snippable sections</li>
                    <li>&#10003; Island Test compliance</li>
                    <li>&#10003; Comparison tables</li>
                    <li>&#10003; FAQ section with schema</li>
                    <li>&#10003; Recent trends &amp; data</li>
                    <li>&#10003; 5 headline variations</li>
                    <li>&#10003; SEO meta tags with CTR score</li>
                    <li>&#10003; Stock images with alt tags</li>
                    <li>&#10003; References section</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- ===== RESULTS (full width, below form) ===== -->

    <?php if ( $outline_result ) : ?>
    <div class="sb-section" style="margin-top:24px">
        <h3 class="sb-section-header"><span class="dashicons dashicons-editor-ol"></span> Generated Outline</h3>
        <?php if ( $outline_result['success'] ) : ?>
            <pre style="background:var(--sb-bg);padding:16px;border-radius:6px;white-space:pre-wrap;font-size:13px;max-height:400px;overflow-y:auto;border:1px solid var(--sb-border)"><?php echo esc_html( $outline_result['content'] ); ?></pre>
        <?php else : ?>
            <div class="notice notice-error" style="margin:0"><p><?php echo esc_html( $outline_result['error'] ?? 'Failed.' ); ?></p></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ( $result ) : ?>
    <div class="sb-section" style="margin-top:24px">
        <h3 class="sb-section-header"><span class="dashicons dashicons-media-document"></span> Generated Article</h3>

        <?php if ( $result['success'] ) : ?>
            <!-- Score Bar -->
            <div style="display:flex;gap:16px;align-items:center;padding:12px 16px;background:var(--sb-bg);border-radius:6px;margin-bottom:16px;font-size:13px">
                <span><strong>GEO Score:</strong>
                    <span class="seobetter-score seobetter-score-<?php echo $result['geo_score'] >= 80 ? 'good' : ( $result['geo_score'] >= 60 ? 'ok' : 'poor' ); ?>">
                        <?php echo esc_html( $result['geo_score'] ); ?> (<?php echo esc_html( $result['grade'] ); ?>)
                    </span>
                </span>
                <span><strong>Words:</strong> <?php echo esc_html( number_format( $result['word_count'] ) ); ?></span>
                <span><strong>Model:</strong> <code style="font-size:11px"><?php echo esc_html( $result['model_used'] ); ?></code></span>
            </div>

            <!-- Issues -->
            <?php if ( ! empty( $result['suggestions'] ) ) : ?>
            <div style="margin-bottom:16px">
                <?php foreach ( $result['suggestions'] as $s ) : ?>
                <div class="seobetter-suggestion seobetter-suggestion-<?php echo esc_attr( $s['priority'] ); ?>">
                    <span class="dashicons dashicons-<?php echo $s['priority'] === 'high' ? 'warning' : 'info-outline'; ?>"></span>
                    <span class="seobetter-suggestion-type">[<?php echo esc_html( ucfirst( $s['type'] ?? 'issue' ) ); ?>]</span>
                    <?php echo esc_html( $s['message'] ); ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php elseif ( $result['geo_score'] >= 80 ) : ?>
            <div style="padding:12px 16px;background:var(--sb-success-bg);border-radius:6px;margin-bottom:16px;color:var(--sb-success);font-size:13px;font-weight:600">
                &#10003; All GEO checks passed — optimized for AI search visibility
            </div>
            <?php endif; ?>

            <!-- Content Preview -->
            <?php
            // Output the <style> block separately — wp_kses_post() strips it
            if ( preg_match( '/<style>.*?<\/style>/s', $result['content'], $style_match ) ) {
                echo $style_match[0];
            }
            $preview_html = preg_replace( '/<style>.*?<\/style>/s', '', $result['content'] );
            ?>
            <div class="seobetter-content-preview"><?php echo wp_kses_post( $preview_html ); ?></div>

            <!-- Actions -->
            <form method="post" style="margin-bottom:16px">
                <?php wp_nonce_field( 'seobetter_draft_nonce' ); ?>
                <input type="hidden" name="draft_content" value="<?php echo esc_attr( $result['content'] ); ?>" />
                <input type="hidden" name="draft_keyword" value="<?php echo esc_attr( $result['keyword'] ?? '' ); ?>" />
                <input type="hidden" name="draft_markdown" value="<?php echo esc_attr( $result['markdown'] ?? '' ); ?>" />
                <input type="hidden" name="draft_accent_color" value="<?php echo esc_attr( $_POST['accent_color'] ?? '#764ba2' ); ?>" />
                <input type="hidden" name="draft_affiliates" value="<?php echo esc_attr( wp_json_encode( $affiliates ) ); ?>" />
                <input type="hidden" name="reoptimize_suggestions" value="<?php echo esc_attr( wp_json_encode( $result['suggestions'] ?? [] ) ); ?>" />
                <input type="hidden" name="draft_title" id="seobetter-draft-title" value="<?php echo esc_attr( $result['headlines'][0] ?? $result['keyword'] ?? '' ); ?>" />

                <!-- Headlines -->
                <?php if ( ! empty( $result['headlines'] ) ) : ?>
                <div style="padding:16px;background:var(--sb-primary-light);border-radius:8px;margin-bottom:16px">
                    <h4 style="margin:0 0 10px;font-size:13px;font-weight:700">Choose Your Headline (used as post title)</h4>
                    <?php foreach ( $result['headlines'] as $idx => $hl ) :
                        $len = mb_strlen( $hl );
                        $ok = $len >= 45 && $len <= 65;
                    ?>
                    <label class="seobetter-headline-option">
                        <input type="radio" name="selected_headline" value="<?php echo esc_attr( $hl ); ?>" <?php if ( $idx === 0 ) echo 'checked'; ?> onchange="document.getElementById('seobetter-draft-title').value=this.value" />
                        <span class="headline-text"><?php echo esc_html( $hl ); ?></span>
                        <span class="headline-chars" style="color:<?php echo $ok ? 'var(--sb-success)' : 'var(--sb-error)'; ?>"><?php echo $len; ?> chars</span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <?php if ( ! empty( $result['suggestions'] ) ) : ?>
                    <button type="submit" name="seobetter_reoptimize" class="button" style="background:var(--sb-warning);border-color:var(--sb-warning);color:#fff;height:44px;padding:0 20px;font-weight:600;border-radius:6px">
                        Fix <?php echo count( $result['suggestions'] ); ?> Issues &amp; Re-optimize
                    </button>
                    <?php endif; ?>
                    <button type="submit" name="seobetter_create_draft" class="button sb-btn-primary" style="height:44px">Save as WordPress Draft</button>
                </div>
            </form>

            <!-- Meta Tags -->
            <?php if ( ! empty( $result['meta']['title'] ) ) : ?>
            <div style="padding:16px;background:var(--sb-bg);border:1px solid var(--sb-border);border-radius:8px;margin-bottom:16px">
                <h4 style="margin:0 0 12px;font-size:13px;font-weight:700">SEO Meta Tags</h4>
                <div style="margin-bottom:10px">
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                        <label style="font-size:12px;font-weight:600">Title</label>
                        <span style="font-size:11px">
                            <span class="seobetter-score seobetter-score-<?php echo $result['meta']['title_score'] >= 80 ? 'good' : ( $result['meta']['title_score'] >= 60 ? 'ok' : 'poor' ); ?>"><?php echo $result['meta']['title_score']; ?>/100</span>
                            <span style="color:var(--sb-text-muted)"><?php echo $result['meta']['title_length']; ?> chars</span>
                        </span>
                    </div>
                    <input type="text" value="<?php echo esc_attr( $result['meta']['title'] ); ?>" readonly onclick="this.select()" style="background:var(--sb-card);width:100%;height:38px;font-size:13px" />
                </div>
                <div style="margin-bottom:12px">
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                        <label style="font-size:12px;font-weight:600">Description</label>
                        <span style="font-size:11px">
                            <span class="seobetter-score seobetter-score-<?php echo $result['meta']['desc_score'] >= 80 ? 'good' : ( $result['meta']['desc_score'] >= 60 ? 'ok' : 'poor' ); ?>"><?php echo $result['meta']['desc_score']; ?>/100</span>
                            <span style="color:var(--sb-text-muted)"><?php echo $result['meta']['desc_length']; ?> chars</span>
                        </span>
                    </div>
                    <textarea rows="2" readonly onclick="this.select()" style="background:var(--sb-card);width:100%;font-size:13px"><?php echo esc_textarea( $result['meta']['description'] ); ?></textarea>
                </div>
                <!-- SERP Preview -->
                <div class="seobetter-serp-preview">
                    <div class="serp-url"><?php echo esc_html( $home ); ?> › blog</div>
                    <div class="serp-title"><?php echo esc_html( $result['meta']['title'] ); ?></div>
                    <div class="serp-desc"><?php echo esc_html( $result['meta']['description'] ); ?></div>
                </div>
                <div class="sb-help" style="margin-top:8px">Copy into Yoast, RankMath, or AIOSEO after saving draft.</div>
            </div>
            <?php endif; ?>

            <!-- Social Content -->
            <div style="padding:16px;background:var(--sb-bg);border:1px solid var(--sb-border);border-radius:8px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                    <h4 style="margin:0;font-size:13px;font-weight:700">Social Media Content</h4>
                    <button type="button" id="sb-gen-social" class="button sb-btn-sm" data-keyword="<?php echo esc_attr( $result['keyword'] ?? '' ); ?>">Generate</button>
                </div>
                <span id="sb-social-status" style="font-size:12px;color:var(--sb-text-muted)"></span>
                <div id="sb-social-grid" class="seobetter-social-grid" style="display:none;margin-top:12px">
                    <div>
                        <h4>Twitter Thread</h4>
                        <textarea id="sb-tw" rows="8" readonly onclick="this.select()"></textarea>
                        <button type="button" class="button sb-btn-sm" style="margin-top:4px" onclick="navigator.clipboard.writeText(document.getElementById('sb-tw').value);this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500)">Copy</button>
                    </div>
                    <div>
                        <h4>LinkedIn</h4>
                        <textarea id="sb-li" rows="8" readonly onclick="this.select()"></textarea>
                        <button type="button" class="button sb-btn-sm" style="margin-top:4px" onclick="navigator.clipboard.writeText(document.getElementById('sb-li').value);this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500)">Copy</button>
                    </div>
                    <div>
                        <h4>Instagram</h4>
                        <textarea id="sb-ig" rows="8" readonly onclick="this.select()"></textarea>
                        <button type="button" class="button sb-btn-sm" style="margin-top:4px" onclick="navigator.clipboard.writeText(document.getElementById('sb-ig').value);this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500)">Copy</button>
                    </div>
                </div>
            </div>

        <?php else : ?>
            <div class="notice notice-error" style="margin:0">
                <p><strong>Generation failed:</strong> <?php echo esc_html( $result['error'] ); ?></p>
                <?php if ( strpos( $result['error'] ?? '', 'limit' ) !== false ) : ?>
                <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=seobetter-settings' ) ); ?>">Connect a free API key</a> for unlimited generation.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
var CLOUD = '<?php echo esc_js( $cloud_url ); ?>';
var SITE  = '<?php echo esc_js( $home ); ?>';

// Auto-suggest keywords
document.getElementById('seobetter-auto-keywords').addEventListener('click', function() {
    var kw = document.getElementById('primary_keyword').value.trim();
    if (!kw) { alert('Enter a keyword first.'); return; }
    var btn = this, st = document.getElementById('seobetter-auto-status');
    btn.disabled = true; st.textContent = 'Generating...';
    fetch(CLOUD + '/api/generate', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ prompt:'For "'+kw+'", generate:\nSECONDARY: 5-7 related phrases\nRELATED: 8-10 semantic terms\n\nReturn ONLY:\nSECONDARY: a, b, c\nRELATED: a, b, c', system_prompt:'SEO keyword expert. Format only.', max_tokens:300, temperature:0.7, site_url:SITE })
    }).then(r=>r.json()).then(d => {
        btn.disabled = false;
        if (d.content) {
            d.content.split('\n').forEach(l => {
                if (/^SECONDARY/i.test(l)) document.querySelector('[name="secondary_keywords"]').value = l.replace(/^SECONDARY\s*:\s*/i,'');
                if (/^RELATED/i.test(l)) document.querySelector('[name="lsi_keywords"]').value = l.replace(/^RELATED\s*:\s*/i,'');
            });
            st.textContent = 'Done!'; setTimeout(()=>st.textContent='', 2000);
        } else st.textContent = d.error||'Failed';
    }).catch(e => { btn.disabled=false; st.textContent='Error'; });
});

// Add affiliate row
var affN = <?php echo count( $saved_aff ); ?>;
document.getElementById('sb-add-aff').addEventListener('click', function() {
    affN++;
    var r = document.createElement('div');
    r.className = 'sb-aff-row';
    r.innerHTML = '<input type="url" name="affiliates['+affN+'][url]" placeholder="Affiliate URL" class="sb-aff-url" />' +
        '<input type="text" name="affiliates['+affN+'][keyword]" placeholder="Keyword to link" class="sb-aff-kw" />' +
        '<input type="text" name="affiliates['+affN+'][name]" placeholder="CTA name" class="sb-aff-name" />' +
        '<button type="button" class="sb-aff-x" onclick="if(document.querySelectorAll(\'.sb-aff-row\').length>1)this.parentElement.remove()">&times;</button>';
    document.getElementById('sb-aff-list').appendChild(r);
});

<?php if ( $result && $result['success'] ) : ?>
// Social content generator
document.getElementById('sb-gen-social').addEventListener('click', function() {
    var btn=this, st=document.getElementById('sb-social-status');
    btn.disabled=true; st.textContent='Generating (20-30s)...';
    var txt = <?php echo wp_json_encode( substr( wp_strip_all_tags( $result['content'] ?? '' ), 0, 2000 ) ); ?>;
    fetch(CLOUD + '/api/generate', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ prompt:'Create social content from this article about "'+btn.dataset.keyword+'":\n'+txt+'\n\n=== TWITTER THREAD ===\n5 tweets\n\n=== LINKEDIN POST ===\n150-300 words\n\n=== INSTAGRAM CAPTION ===\nHook + takeaways + hashtags', system_prompt:'Social media expert.', max_tokens:2000, temperature:0.7, site_url:SITE })
    }).then(r=>r.json()).then(d => {
        btn.disabled=false;
        if (d.content) {
            var c=d.content;
            var tw=(c.match(/TWITTER.*?===\s*\n([\s\S]*?)(?=={3}\s*LINKEDIN|$)/i)||[])[1]||'';
            var li=(c.match(/LINKEDIN.*?===\s*\n([\s\S]*?)(?=={3}\s*INSTAGRAM|$)/i)||[])[1]||'';
            var ig=(c.match(/INSTAGRAM.*?===\s*\n([\s\S]*?)$/i)||[])[1]||'';
            document.getElementById('sb-tw').value=tw.trim();
            document.getElementById('sb-li').value=li.trim();
            document.getElementById('sb-ig').value=ig.trim();
            document.getElementById('sb-social-grid').style.display='grid';
            st.textContent='';
        } else st.textContent=d.error||'Failed';
    }).catch(e => { btn.disabled=false; st.textContent='Error'; });
});
<?php endif; ?>

// Topic suggester
document.getElementById('sb-suggest-btn').addEventListener('click', function() {
    var niche=document.getElementById('sb-niche-input').value.trim();
    if(!niche){alert('Enter your niche.');return;}
    var btn=this, st=document.getElementById('sb-topics-status');
    btn.disabled=true; st.textContent='Generating...';
    fetch(CLOUD + '/api/generate', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ prompt:'Suggest 10 article topics for "'+niche+'".\nFor each: TOPIC: [title]\nINTENT: [type]\nReturn exactly 10.', system_prompt:'SEO strategist.', max_tokens:1500, temperature:0.8, site_url:SITE })
    }).then(r=>r.json()).then(d => {
        btn.disabled=false;
        if (d.content) {
            var topics=[], cur={};
            d.content.split('\n').forEach(l => {
                var m;
                if(m=l.match(/^TOPIC:\s*(.+)/i)){if(cur.t)topics.push(cur);cur={t:m[1].replace(/^["*]+|["*]+$/g,'')};}
                if(m=l.match(/^INTENT:\s*(.+)/i))cur.i=m[1];
            });
            if(cur.t)topics.push(cur);
            var genUrl='<?php echo esc_js( admin_url('admin.php?page=seobetter-generate') ); ?>';
            var html=topics.map(t=>'<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--sb-border)"><span>'+t.t+'</span><a href="'+genUrl+'&keyword='+encodeURIComponent(t.t)+'" style="font-size:11px;white-space:nowrap">Generate &rarr;</a></div>').join('');
            document.getElementById('sb-topics-list').innerHTML=html;
            document.getElementById('sb-topics-list').style.display='block';
            st.textContent=topics.length+' topics';
        } else st.textContent=d.error||'Failed';
    }).catch(e => { btn.disabled=false; st.textContent='Error'; });
});

// ===== ASYNC ARTICLE GENERATION =====
(function() {
    var btn = document.getElementById('seobetter-async-generate');
    if (!btn) return;

    var panel = document.getElementById('seobetter-progress-panel');
    var bar = document.getElementById('seobetter-progress-bar');
    var label = document.getElementById('seobetter-progress-label');
    var stepsEl = document.getElementById('seobetter-progress-steps');
    var timeEl = document.getElementById('seobetter-progress-time');
    var titleEl = document.getElementById('seobetter-progress-title');
    var errorEl = document.getElementById('seobetter-progress-error');
    var errorMsg = document.getElementById('seobetter-progress-error-msg');
    var estimateEl = document.getElementById('seobetter-progress-estimate');
    var resultEl = document.getElementById('seobetter-async-result');
    var timer = null, elapsed = 0, jobId = null;
    var apiRoot = '<?php echo esc_js( rest_url() ); ?>';
    var apiNonce = '<?php echo esc_js( wp_create_nonce( "wp_rest" ) ); ?>';
    var draftNonce = '<?php echo esc_js( wp_create_nonce( "seobetter_draft_nonce" ) ); ?>';

    function startTimer() {
        elapsed = 0; clearInterval(timer);
        timer = setInterval(function() {
            elapsed++;
            var m = Math.floor(elapsed/60), s = elapsed%60;
            timeEl.textContent = m + ':' + (s<10?'0':'') + s;
        }, 1000);
    }
    function stopTimer() { clearInterval(timer); }

    function api(endpoint, method, data) {
        var opts = { method: method||'POST', headers: { 'Content-Type':'application/json', 'X-WP-Nonce': apiNonce } };
        if (data && method !== 'GET') opts.body = JSON.stringify(data);
        var url = apiRoot + 'seobetter/v1/' + endpoint;
        if (data && method === 'GET') url += '?' + new URLSearchParams(data).toString();
        return fetch(url, opts).then(function(r) { return r.json(); });
    }

    function processNext() {
        errorEl.style.display = 'none';
        api('generate/step', 'POST', { job_id: jobId }).then(function(res) {
            if (!res.success && res.error) {
                errorEl.style.display = 'block';
                errorMsg.textContent = res.error;
                if (!res.can_retry) stopTimer();
                return;
            }
            bar.style.width = (res.progress||0) + '%';
            bar.textContent = Math.round(res.progress||0) + '%';
            if (res.label) label.textContent = res.label;
            if (res.current && res.total) stepsEl.textContent = 'Step ' + res.current + ' of ' + res.total;

            if (res.done) {
                label.textContent = 'Loading results...';
                fetchResult();
            } else {
                processNext();
            }
        }).catch(function(err) {
            errorEl.style.display = 'block';
            errorMsg.textContent = 'Request failed — click Retry to continue';
        });
    }

    function fetchResult() {
        api('generate/result', 'GET', { job_id: jobId }).then(function(res) {
            stopTimer();
            if (!res.success) {
                errorEl.style.display = 'block';
                errorMsg.textContent = res.error || 'Failed to load results.';
                return;
            }
            bar.style.width = '100%'; bar.textContent = '100%';
            titleEl.textContent = 'Article generated!';
            label.textContent = 'Complete!';
            renderResult(res);
        }).catch(function() {
            stopTimer();
            errorEl.style.display = 'block';
            errorMsg.textContent = 'Failed to load results.';
        });
    }

    function esc(s) { var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

    function renderResult(res) {
        var sc = res.geo_score >= 80 ? 'good' : (res.geo_score >= 60 ? 'ok' : 'poor');
        var h = '<div class="seobetter-card" style="padding:20px;margin-top:16px">';
        h += '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;font-size:13px">';
        h += '<span><strong>GEO Score:</strong> <span class="seobetter-score seobetter-score-'+sc+'">'+esc(res.geo_score)+' ('+esc(res.grade)+')</span></span>';
        h += '<span><strong>Words:</strong> '+(res.word_count||0).toLocaleString()+'</span>';
        h += '</div>';

        if (res.suggestions && res.suggestions.length) {
            h += '<div style="margin-bottom:16px">';
            res.suggestions.forEach(function(s) {
                h += '<div class="seobetter-suggestion seobetter-suggestion-'+(s.priority||'medium')+'">';
                h += '<span class="seobetter-suggestion-type">['+(s.type||'issue')+']</span> '+esc(s.message)+'</div>';
            });
            h += '</div>';
        }

        // Content preview with style block
        var content = res.content || '';
        var styleMatch = content.match(/<style>[\s\S]*?<\/style>/);
        if (styleMatch) { h += styleMatch[0]; content = content.replace(/<style>[\s\S]*?<\/style>/, ''); }
        h += '<div class="seobetter-content-preview">' + content + '</div>';

        // Headlines — score, rank, and make selectable
        if (res.headlines && res.headlines.length) {
            var keyword = (res.keyword||'').toLowerCase();
            var scored = res.headlines.map(function(hl) {
                var len = hl.length, s = 0, tags = [];
                // Length: 50-60 is ideal for SERP display
                if (len >= 50 && len <= 60) { s += 25; tags.push('ideal length'); }
                else if (len >= 45 && len <= 65) { s += 15; tags.push('good length'); }
                else { tags.push(len < 45 ? 'too short' : 'may truncate'); }
                // Keyword placement
                if (hl.toLowerCase().indexOf(keyword) === 0) { s += 25; tags.push('keyword first'); }
                else if (hl.toLowerCase().indexOf(keyword) !== -1) { s += 15; tags.push('has keyword'); }
                else { tags.push('missing keyword'); }
                // Number (CTR boost)
                if (/\d/.test(hl)) { s += 10; tags.push('has number'); }
                // Year (freshness signal for AI)
                if (/20[2-3]\d/.test(hl)) { s += 10; tags.push('has year'); }
                // Question format (PAA/snippet ready)
                if (/^(what|how|why|when|where|which|can|do|is|are)\b/i.test(hl)) { s += 10; tags.push('question format'); }
                // Power words (CTR)
                if (/\b(best|top|ultimate|complete|essential|proven|expert|guide|review)\b/i.test(hl)) { s += 10; tags.push('power word'); }
                // Colon/dash structure (snippet-friendly)
                if (/[:\-–—]/.test(hl)) { s += 5; tags.push('structured'); }
                return { text: hl, score: Math.min(100, s), len: len, tags: tags };
            });
            // Sort best first
            scored.sort(function(a, b) { return b.score - a.score; });

            h += '<div style="padding:16px;background:var(--sb-primary-light,#f0f0ff);border-radius:8px;margin-top:16px">';
            h += '<h4 style="margin:0 0 4px;font-size:13px;font-weight:700">Select Headline (ranked by SEO + GEO + AI snippet score)</h4>';
            h += '<p style="margin:0 0 12px;font-size:11px;color:#888">Click to select as your post title. #1 is recommended.</p>';
            scored.forEach(function(item, i) {
                var scoreColor = item.score >= 70 ? '#22c55e' : (item.score >= 50 ? '#f59e0b' : '#ef4444');
                var isFirst = (i === 0);
                var border = isFirst ? 'border:2px solid '+scoreColor : 'border:1px solid #e0e0e0';
                h += '<label style="display:flex;align-items:center;gap:10px;padding:10px 12px;'+border+';border-radius:6px;margin-bottom:6px;cursor:pointer;background:'+(isFirst?'#f0fff4':'#fff')+'">';
                h += '<input type="radio" name="async_headline" value="'+esc(item.text)+'" '+(isFirst?'checked':'')+' style="margin:0" onchange="document.getElementById(\'async-draft-title\').value=this.value">';
                h += '<span style="flex:1;font-size:13px">'+(isFirst?'<strong>':'') + esc(item.text) + (isFirst?'</strong>':'')+'</span>';
                h += '<span style="font-size:11px;font-weight:600;color:'+scoreColor+'">'+item.score+'/100</span>';
                h += '<span style="font-size:11px;color:#888">'+item.len+' chars</span>';
                h += '</label>';
                if (item.tags.length) {
                    h += '<div style="margin:-2px 0 6px 32px;font-size:10px;color:#888">'+item.tags.join(' · ')+'</div>';
                }
            });
            h += '</div>';
        }

        // Save Draft — via AJAX (form POST kept losing content)
        var accentVal = (document.querySelector('[name="accent_color"]')?document.querySelector('[name="accent_color"]').value:'#764ba2');
        var bestTitle = (typeof scored !== 'undefined' && scored && scored.length ? scored[0].text : null) || (res.headlines&&res.headlines[0]) || res.keyword || '';

        // Store data for the save button
        window._seobetterDraft = {
            title: bestTitle,
            markdown: res.markdown || '',
            content: res.content || '',
            accent_color: accentVal,
            keyword: res.keyword || ''
        };

        h += '<div style="margin-top:16px;display:flex;gap:12px;align-items:center">';
        h += '<button type="button" id="seobetter-save-draft-btn" class="button sb-btn-primary" style="height:44px">Save as WordPress Draft</button>';
        h += '<span id="seobetter-save-status" style="font-size:13px;color:#888"></span>';
        h += '</div></div>';

        resultEl.innerHTML = h;
        resultEl.style.display = 'block';
        resultEl.scrollIntoView({ behavior:'smooth', block:'start' });

        // Wire up the save button
        document.getElementById('seobetter-save-draft-btn').addEventListener('click', function() {
            var btn = this;
            var statusEl = document.getElementById('seobetter-save-status');
            var draft = window._seobetterDraft;

            if (!draft || (!draft.markdown && !draft.content)) {
                alert('Error: No content to save. Please regenerate.');
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Saving...';
            statusEl.textContent = '';

            // Use the selected headline if user picked one
            var selectedHL = document.querySelector('[name="async_headline"]:checked');
            if (selectedHL) draft.title = selectedHL.value;

            api('save-draft', 'POST', draft).then(function(r) {
                if (r.success) {
                    btn.textContent = 'Saved!';
                    btn.style.background = '#059669';
                    statusEl.innerHTML = '<a href="'+r.edit_url+'" style="color:#764ba2;font-weight:600">Edit post &rarr;</a>';
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Save as WordPress Draft';
                    statusEl.textContent = 'Error: ' + (r.error || 'Failed to save.');
                    statusEl.style.color = '#ef4444';
                }
            }).catch(function() {
                btn.disabled = false;
                btn.textContent = 'Save as WordPress Draft';
                statusEl.textContent = 'Error: Request failed.';
                statusEl.style.color = '#ef4444';
            });
        });
    }

    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var form = btn.closest('form');
        var keyword = form.querySelector('[name="primary_keyword"]').value.trim();
        if (!keyword) { alert('Please enter a primary keyword.'); return; }

        var data = {
            keyword: keyword,
            secondary_keywords: (form.querySelector('[name="secondary_keywords"]')||{}).value||'',
            lsi_keywords: (form.querySelector('[name="lsi_keywords"]')||{}).value||'',
            word_count: (form.querySelector('[name="word_count"]')||{}).value||'2000',
            tone: (form.querySelector('[name="tone"]')||{}).value||'authoritative',
            domain: (form.querySelector('[name="domain"]')||{}).value||'general',
            audience: (form.querySelector('[name="audience"]')||{}).value||'',
            accent_color: (form.querySelector('[name="accent_color"]')||{}).value||'#764ba2'
        };

        btn.disabled = true; btn.textContent = 'Generating...';
        panel.style.display = 'block';
        resultEl.style.display = 'none';
        errorEl.style.display = 'none';
        bar.style.width = '0%'; bar.textContent = '0%';
        label.textContent = 'Starting generation...';
        stepsEl.textContent = '';
        startTimer();

        api('generate/start', 'POST', data).then(function(res) {
            if (!res.success) {
                stopTimer();
                errorEl.style.display = 'block';
                errorMsg.textContent = res.error || 'Failed to start.';
                btn.disabled = false; btn.textContent = 'Generate Article';
                return;
            }
            jobId = res.job_id;
            estimateEl.textContent = 'Estimated time: ~' + res.est_minutes + ' min';
            stepsEl.textContent = 'Step 0 of ' + res.total_steps;
            processNext();
        }).catch(function(err) {
            stopTimer();
            errorEl.style.display = 'block';
            errorMsg.textContent = 'Failed to connect to API. Check Settings.';
            btn.disabled = false; btn.textContent = 'Generate Article';
        });
    });

    // Retry button
    var retryBtn = document.getElementById('seobetter-retry-btn');
    if (retryBtn) {
        retryBtn.addEventListener('click', function() {
            if (!jobId) return;
            errorEl.style.display = 'none';
            label.textContent = 'Retrying...';
            processNext();
        });
    }
})();
</script>
