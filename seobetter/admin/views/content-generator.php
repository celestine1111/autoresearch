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

                    <div class="sb-field-row">
                        <div class="sb-field">
                            <label>Content Type
                                <span class="seobetter-tooltip"><span class="dashicons dashicons-info-outline"></span>
                                    <span class="seobetter-tooltip-text">Changes the article structure, tone, and JSON-LD schema. Each type uses a different prose template optimized for its format.</span>
                                </span>
                            </label>
                            <select name="content_type" id="sb-content-type" onchange="sbContentTypeChanged(this.value)">
                                <optgroup label="Common">
                                    <option value="blog_post" <?php selected( $_POST['content_type'] ?? 'blog_post', 'blog_post' ); ?>>Blog Post</option>
                                    <option value="how_to" <?php selected( $_POST['content_type'] ?? '', 'how_to' ); ?>>How-To Guide</option>
                                    <option value="listicle" <?php selected( $_POST['content_type'] ?? '', 'listicle' ); ?>>Listicle (Top 10...)</option>
                                    <option value="review" <?php selected( $_POST['content_type'] ?? '', 'review' ); ?>>Product Review</option>
                                    <option value="comparison" <?php selected( $_POST['content_type'] ?? '', 'comparison' ); ?>>Comparison (X vs Y)</option>
                                    <option value="buying_guide" <?php selected( $_POST['content_type'] ?? '', 'buying_guide' ); ?>>Buying Guide / Roundup</option>
                                    <option value="news_article" <?php selected( $_POST['content_type'] ?? '', 'news_article' ); ?>>News Article</option>
                                    <option value="faq_page" <?php selected( $_POST['content_type'] ?? '', 'faq_page' ); ?>>FAQ Page</option>
                                    <option value="pillar_guide" <?php selected( $_POST['content_type'] ?? '', 'pillar_guide' ); ?>>Ultimate Guide</option>
                                </optgroup>
                                <optgroup label="Specialized">
                                    <option value="recipe" <?php selected( $_POST['content_type'] ?? '', 'recipe' ); ?>>Recipe</option>
                                    <option value="case_study" <?php selected( $_POST['content_type'] ?? '', 'case_study' ); ?>>Case Study</option>
                                    <option value="tech_article" <?php selected( $_POST['content_type'] ?? '', 'tech_article' ); ?>>Technical Article</option>
                                    <option value="interview" <?php selected( $_POST['content_type'] ?? '', 'interview' ); ?>>Interview / Q&A</option>
                                    <option value="white_paper" <?php selected( $_POST['content_type'] ?? '', 'white_paper' ); ?>>White Paper / Report</option>
                                    <option value="opinion" <?php selected( $_POST['content_type'] ?? '', 'opinion' ); ?>>Opinion / Op-Ed</option>
                                    <option value="press_release" <?php selected( $_POST['content_type'] ?? '', 'press_release' ); ?>>Press Release</option>
                                    <option value="personal_essay" <?php selected( $_POST['content_type'] ?? '', 'personal_essay' ); ?>>Personal Essay</option>
                                    <option value="glossary_definition" <?php selected( $_POST['content_type'] ?? '', 'glossary_definition' ); ?>>Glossary / Definition</option>
                                    <option value="scholarly_article" <?php selected( $_POST['content_type'] ?? '', 'scholarly_article' ); ?>>Scholarly Article</option>
                                    <option value="sponsored" <?php selected( $_POST['content_type'] ?? '', 'sponsored' ); ?>>Sponsored / Advertorial</option>
                                    <option value="live_blog" <?php selected( $_POST['content_type'] ?? '', 'live_blog' ); ?>>Live Blog</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>

                    <div class="sb-field-row-3">
                        <div class="sb-field">
                            <label>Word Count
                                <span class="seobetter-tooltip"><span class="dashicons dashicons-info-outline"></span>
                                    <span class="seobetter-tooltip-text">Longer content ranks better for competitive keywords. 2,000+ words is optimal for AI citations (ChatGPT, Perplexity). Shorter works for transactional pages.</span>
                                </span>
                            </label>
                            <select name="word_count">
                                <option value="800" <?php selected( $_POST['word_count'] ?? '', '800' ); ?>>800 — Quick answer</option>
                                <option value="1000" <?php selected( $_POST['word_count'] ?? '', '1000' ); ?>>1,000 — Product/transactional</option>
                                <option value="1500" <?php selected( $_POST['word_count'] ?? '', '1500' ); ?>>1,500 — Standard article</option>
                                <option value="2000" <?php selected( $_POST['word_count'] ?? '2000', '2000' ); ?>>2,000 — AI citation optimal</option>
                                <option value="2500" <?php selected( $_POST['word_count'] ?? '', '2500' ); ?>>2,500 — Comprehensive guide</option>
                                <option value="3000" <?php selected( $_POST['word_count'] ?? '', '3000' ); ?>>3,000 — Definitive guide</option>
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
                            <label>Category <span style="color:#ef4444">*</span>
                                <span class="seobetter-tooltip"><span class="dashicons dashicons-info-outline"></span>
                                    <span class="seobetter-tooltip-text">Required. Pulls real-time data from free public APIs relevant to your topic for better citations and statistics.</span>
                                </span>
                            </label>
                            <select name="domain" required>
                                <option value="" disabled <?php selected( $_POST['domain'] ?? '', '' ); ?>>Select category...</option>
                                <option value="general" <?php selected( $_POST['domain'] ?? '', 'general' ); ?>>General</option>
                                <option value="animals" <?php selected( $_POST['domain'] ?? '', 'animals' ); ?>>Animals &amp; Pets</option>
                                <option value="art_design" <?php selected( $_POST['domain'] ?? '', 'art_design' ); ?>>Art &amp; Design</option>
                                <option value="blockchain" <?php selected( $_POST['domain'] ?? '', 'blockchain' ); ?>>Blockchain</option>
                                <option value="books" <?php selected( $_POST['domain'] ?? '', 'books' ); ?>>Books &amp; Literature</option>
                                <option value="business" <?php selected( $_POST['domain'] ?? '', 'business' ); ?>>Business</option>
                                <option value="cryptocurrency" <?php selected( $_POST['domain'] ?? '', 'cryptocurrency' ); ?>>Cryptocurrency</option>
                                <option value="currency" <?php selected( $_POST['domain'] ?? '', 'currency' ); ?>>Currency &amp; Forex</option>
                                <option value="ecommerce" <?php selected( $_POST['domain'] ?? '', 'ecommerce' ); ?>>Ecommerce</option>
                                <option value="education" <?php selected( $_POST['domain'] ?? '', 'education' ); ?>>Education</option>
                                <option value="entertainment" <?php selected( $_POST['domain'] ?? '', 'entertainment' ); ?>>Entertainment &amp; Movies</option>
                                <option value="environment" <?php selected( $_POST['domain'] ?? '', 'environment' ); ?>>Environment &amp; Climate</option>
                                <option value="finance" <?php selected( $_POST['domain'] ?? '', 'finance' ); ?>>Finance &amp; Economics</option>
                                <option value="food" <?php selected( $_POST['domain'] ?? '', 'food' ); ?>>Food &amp; Drink</option>
                                <option value="games" <?php selected( $_POST['domain'] ?? '', 'games' ); ?>>Games &amp; Gaming</option>
                                <option value="government" <?php selected( $_POST['domain'] ?? '', 'government' ); ?>>Government &amp; Politics</option>
                                <option value="health" <?php selected( $_POST['domain'] ?? '', 'health' ); ?>>Health &amp; Medical</option>
                                <option value="law_government" <?php selected( $_POST['domain'] ?? '', 'law_government' ); ?>>Law &amp; Legal</option>
                                <option value="music" <?php selected( $_POST['domain'] ?? '', 'music' ); ?>>Music</option>
                                <option value="news" <?php selected( $_POST['domain'] ?? '', 'news' ); ?>>News &amp; Media</option>
                                <option value="science" <?php selected( $_POST['domain'] ?? '', 'science' ); ?>>Science &amp; Space</option>
                                <option value="sports" <?php selected( $_POST['domain'] ?? '', 'sports' ); ?>>Sports &amp; Fitness</option>
                                <option value="technology" <?php selected( $_POST['domain'] ?? '', 'technology' ); ?>>Technology</option>
                                <option value="transportation" <?php selected( $_POST['domain'] ?? '', 'transportation' ); ?>>Transportation &amp; Travel</option>
                                <option value="weather" <?php selected( $_POST['domain'] ?? '', 'weather' ); ?>>Weather &amp; Climate</option>
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

                    <div class="sb-field-row">
                        <div class="sb-field" style="flex:1">
                            <label>Country &amp; Language
                                <span class="seobetter-tooltip"><span class="dashicons dashicons-info-outline"></span>
                                    <span class="seobetter-tooltip-text">Sets the article language and pulls statistics from country-specific government APIs. Article will be written entirely in the selected language.</span>
                                </span>
                            </label>
                            <input type="hidden" name="country" id="sb-country-val" value="<?php echo esc_attr( $_POST['country'] ?? '' ); ?>" />
                            <input type="hidden" name="language" id="sb-lang-val" value="<?php echo esc_attr( $_POST['language'] ?? 'en' ); ?>" />
                            <div id="sb-country-picker" style="position:relative">
                                <div id="sb-country-selected" style="display:flex;align-items:center;gap:8px;height:40px;padding:0 12px;border:1px solid var(--sb-border,#d1d5db);border-radius:6px;cursor:pointer;background:#fff;font-size:13px" onclick="document.getElementById('sb-country-dropdown').style.display=document.getElementById('sb-country-dropdown').style.display==='block'?'none':'block';document.getElementById('sb-country-search').focus()">
                                    <span id="sb-country-flag" style="font-size:18px">🌐</span>
                                    <span id="sb-country-label" style="flex:1;color:#374151">Global — English</span>
                                    <svg style="width:14px;height:14px;color:#6b7280;flex-shrink:0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                                </div>
                                <div id="sb-country-dropdown" style="display:none;position:absolute;top:44px;left:0;right:0;background:#fff;border:1px solid #d1d5db;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.12);z-index:9999;max-height:320px;overflow:hidden">
                                    <div style="padding:8px;border-bottom:1px solid #e5e7eb">
                                        <input type="text" id="sb-country-search" placeholder="Search country or language..." style="width:100%;height:34px;padding:0 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;outline:none;box-sizing:border-box" oninput="sbFilterCountries(this.value)" />
                                    </div>
                                    <div id="sb-country-list" style="max-height:260px;overflow-y:auto"></div>
                                </div>
                            </div>
                            <script>
                            var sbCountries = [
                                {v:'',c:'',f:'🌐',n:'Global',l:'en',ln:'English'},
                                // Oceania
                                {v:'AU',c:'AU',f:'🇦🇺',n:'Australia',l:'en',ln:'English'},
                                {v:'NZ',c:'NZ',f:'🇳🇿',n:'New Zealand',l:'en',ln:'English'},
                                {v:'FJ',c:'FJ',f:'🇫🇯',n:'Pacific Islands',l:'en',ln:'English'},
                                // North America
                                {v:'US',c:'US',f:'🇺🇸',n:'United States',l:'en',ln:'English'},
                                {v:'CA:en',c:'CA',f:'🇨🇦',n:'Canada',l:'en',ln:'English'},
                                {v:'CA:fr',c:'CA',f:'🇨🇦',n:'Canada',l:'fr',ln:'Français'},
                                {v:'MX',c:'MX',f:'🇲🇽',n:'Mexico',l:'es',ln:'Español'},
                                // Europe Western
                                {v:'GB',c:'GB',f:'🇬🇧',n:'United Kingdom',l:'en',ln:'English'},
                                {v:'IE',c:'IE',f:'🇮🇪',n:'Ireland',l:'en',ln:'English'},
                                {v:'FR',c:'FR',f:'🇫🇷',n:'France',l:'fr',ln:'Français'},
                                {v:'DE',c:'DE',f:'🇩🇪',n:'Germany',l:'de',ln:'Deutsch'},
                                {v:'ES',c:'ES',f:'🇪🇸',n:'Spain',l:'es',ln:'Español'},
                                {v:'PT',c:'PT',f:'🇵🇹',n:'Portugal',l:'pt',ln:'Português'},
                                {v:'IT',c:'IT',f:'🇮🇹',n:'Italy',l:'it',ln:'Italiano'},
                                {v:'NL',c:'NL',f:'🇳🇱',n:'Netherlands',l:'nl',ln:'Nederlands'},
                                {v:'BE:nl',c:'BE',f:'🇧🇪',n:'Belgium',l:'nl',ln:'Nederlands'},
                                {v:'BE:fr',c:'BE',f:'🇧🇪',n:'Belgium',l:'fr',ln:'Français'},
                                {v:'CH:de',c:'CH',f:'🇨🇭',n:'Switzerland',l:'de',ln:'Deutsch'},
                                {v:'CH:fr',c:'CH',f:'🇨🇭',n:'Switzerland',l:'fr',ln:'Français'},
                                {v:'CH:it',c:'CH',f:'🇨🇭',n:'Switzerland',l:'it',ln:'Italiano'},
                                {v:'AT',c:'AT',f:'🇦🇹',n:'Austria',l:'de',ln:'Deutsch'},
                                {v:'LU',c:'LU',f:'🇱🇺',n:'Luxembourg',l:'fr',ln:'Français'},
                                {v:'GR',c:'GR',f:'🇬🇷',n:'Greece',l:'el',ln:'Ελληνικά'},
                                {v:'CY',c:'CY',f:'🇨🇾',n:'Cyprus',l:'el',ln:'Ελληνικά'},
                                {v:'MT',c:'MT',f:'🇲🇹',n:'Malta',l:'en',ln:'English'},
                                // Nordic & Baltic
                                {v:'SE',c:'SE',f:'🇸🇪',n:'Sweden',l:'sv',ln:'Svenska'},
                                {v:'NO',c:'NO',f:'🇳🇴',n:'Norway',l:'no',ln:'Norsk'},
                                {v:'DK',c:'DK',f:'🇩🇰',n:'Denmark',l:'da',ln:'Dansk'},
                                {v:'FI',c:'FI',f:'🇫🇮',n:'Finland',l:'fi',ln:'Suomi'},
                                {v:'IS',c:'IS',f:'🇮🇸',n:'Iceland',l:'is',ln:'Íslenska'},
                                {v:'EE',c:'EE',f:'🇪🇪',n:'Estonia',l:'et',ln:'Eesti'},
                                {v:'LV',c:'LV',f:'🇱🇻',n:'Latvia',l:'lv',ln:'Latviešu'},
                                {v:'LT',c:'LT',f:'🇱🇹',n:'Lithuania',l:'lt',ln:'Lietuvių'},
                                // Central & Eastern Europe
                                {v:'PL',c:'PL',f:'🇵🇱',n:'Poland',l:'pl',ln:'Polski'},
                                {v:'CZ',c:'CZ',f:'🇨🇿',n:'Czech Republic',l:'cs',ln:'Čeština'},
                                {v:'SK',c:'SK',f:'🇸🇰',n:'Slovakia',l:'sk',ln:'Slovenčina'},
                                {v:'HU',c:'HU',f:'🇭🇺',n:'Hungary',l:'hu',ln:'Magyar'},
                                {v:'SI',c:'SI',f:'🇸🇮',n:'Slovenia',l:'sl',ln:'Slovenščina'},
                                {v:'HR',c:'HR',f:'🇭🇷',n:'Croatia',l:'hr',ln:'Hrvatski'},
                                {v:'RS',c:'RS',f:'🇷🇸',n:'Serbia',l:'sr',ln:'Српски'},
                                {v:'BG',c:'BG',f:'🇧🇬',n:'Bulgaria',l:'bg',ln:'Български'},
                                {v:'RO',c:'RO',f:'🇷🇴',n:'Romania',l:'ro',ln:'Română'},
                                {v:'UA',c:'UA',f:'🇺🇦',n:'Ukraine',l:'uk',ln:'Українська'},
                                {v:'MD',c:'MD',f:'🇲🇩',n:'Moldova',l:'ro',ln:'Română'},
                                {v:'TR',c:'TR',f:'🇹🇷',n:'Turkey',l:'tr',ln:'Türkçe'},
                                {v:'RU',c:'RU',f:'🇷🇺',n:'Russia',l:'ru',ln:'Русский'},
                                // Asia
                                {v:'JP',c:'JP',f:'🇯🇵',n:'Japan',l:'ja',ln:'日本語'},
                                {v:'KR',c:'KR',f:'🇰🇷',n:'South Korea',l:'ko',ln:'한국어'},
                                {v:'CN',c:'CN',f:'🇨🇳',n:'China',l:'zh',ln:'中文'},
                                {v:'TW',c:'TW',f:'🇹🇼',n:'Taiwan',l:'zh',ln:'中文'},
                                {v:'SG',c:'SG',f:'🇸🇬',n:'Singapore',l:'en',ln:'English'},
                                {v:'MY',c:'MY',f:'🇲🇾',n:'Malaysia',l:'ms',ln:'Bahasa Melayu'},
                                {v:'ID',c:'ID',f:'🇮🇩',n:'Indonesia',l:'id',ln:'Bahasa Indonesia'},
                                {v:'PH',c:'PH',f:'🇵🇭',n:'Philippines',l:'en',ln:'English'},
                                {v:'TH',c:'TH',f:'🇹🇭',n:'Thailand',l:'th',ln:'ไทย'},
                                {v:'VN',c:'VN',f:'🇻🇳',n:'Vietnam',l:'vi',ln:'Tiếng Việt'},
                                {v:'IN:en',c:'IN',f:'🇮🇳',n:'India',l:'en',ln:'English'},
                                {v:'IN:hi',c:'IN',f:'🇮🇳',n:'India',l:'hi',ln:'हिन्दी'},
                                {v:'PK',c:'PK',f:'🇵🇰',n:'Pakistan',l:'ur',ln:'اردو'},
                                {v:'BD',c:'BD',f:'🇧🇩',n:'Bangladesh',l:'bn',ln:'বাংলা'},
                                {v:'LK',c:'LK',f:'🇱🇰',n:'Sri Lanka',l:'si',ln:'සිංහල'},
                                {v:'NP',c:'NP',f:'🇳🇵',n:'Nepal',l:'ne',ln:'नेपाली'},
                                {v:'MN',c:'MN',f:'🇲🇳',n:'Mongolia',l:'mn',ln:'Монгол'},
                                {v:'KZ',c:'KZ',f:'🇰🇿',n:'Kazakhstan',l:'kk',ln:'Қазақша'},
                                {v:'UZ',c:'UZ',f:'🇺🇿',n:'Uzbekistan',l:'uz',ln:'Oʻzbekcha'},
                                // Middle East
                                {v:'IL',c:'IL',f:'🇮🇱',n:'Israel',l:'he',ln:'עברית'},
                                {v:'AE',c:'AE',f:'🇦🇪',n:'UAE',l:'ar',ln:'العربية'},
                                {v:'AE:en',c:'AE',f:'🇦🇪',n:'UAE',l:'en',ln:'English'},
                                {v:'SA',c:'SA',f:'🇸🇦',n:'Saudi Arabia',l:'ar',ln:'العربية'},
                                {v:'QA',c:'QA',f:'🇶🇦',n:'Qatar',l:'ar',ln:'العربية'},
                                {v:'BH',c:'BH',f:'🇧🇭',n:'Bahrain',l:'ar',ln:'العربية'},
                                {v:'KW',c:'KW',f:'🇰🇼',n:'Kuwait',l:'ar',ln:'العربية'},
                                {v:'OM',c:'OM',f:'🇴🇲',n:'Oman',l:'ar',ln:'العربية'},
                                {v:'JO',c:'JO',f:'🇯🇴',n:'Jordan',l:'ar',ln:'العربية'},
                                {v:'EG',c:'EG',f:'🇪🇬',n:'Egypt',l:'ar',ln:'العربية'},
                                // Latin America
                                {v:'BR',c:'BR',f:'🇧🇷',n:'Brazil',l:'pt',ln:'Português'},
                                {v:'AR',c:'AR',f:'🇦🇷',n:'Argentina',l:'es',ln:'Español'},
                                {v:'CL',c:'CL',f:'🇨🇱',n:'Chile',l:'es',ln:'Español'},
                                {v:'CO',c:'CO',f:'🇨🇴',n:'Colombia',l:'es',ln:'Español'},
                                {v:'PE',c:'PE',f:'🇵🇪',n:'Peru',l:'es',ln:'Español'},
                                {v:'UY',c:'UY',f:'🇺🇾',n:'Uruguay',l:'es',ln:'Español'},
                                {v:'EC',c:'EC',f:'🇪🇨',n:'Ecuador',l:'es',ln:'Español'},
                                {v:'CR',c:'CR',f:'🇨🇷',n:'Costa Rica',l:'es',ln:'Español'},
                                {v:'PA',c:'PA',f:'🇵🇦',n:'Panama',l:'es',ln:'Español'},
                                {v:'DO',c:'DO',f:'🇩🇴',n:'Dominican Republic',l:'es',ln:'Español'},
                                {v:'GT',c:'GT',f:'🇬🇹',n:'Guatemala',l:'es',ln:'Español'},
                                {v:'JM',c:'JM',f:'🇯🇲',n:'Jamaica',l:'en',ln:'English'},
                                // Africa
                                {v:'ZA',c:'ZA',f:'🇿🇦',n:'South Africa',l:'en',ln:'English'},
                                {v:'NG',c:'NG',f:'🇳🇬',n:'Nigeria',l:'en',ln:'English'},
                                {v:'KE',c:'KE',f:'🇰🇪',n:'Kenya',l:'en',ln:'English'},
                                {v:'GH',c:'GH',f:'🇬🇭',n:'Ghana',l:'en',ln:'English'},
                                {v:'TZ',c:'TZ',f:'🇹🇿',n:'Tanzania',l:'sw',ln:'Kiswahili'},
                                {v:'UG',c:'UG',f:'🇺🇬',n:'Uganda',l:'en',ln:'English'},
                                {v:'RW',c:'RW',f:'🇷🇼',n:'Rwanda',l:'en',ln:'English'},
                                {v:'MA',c:'MA',f:'🇲🇦',n:'Morocco',l:'ar',ln:'العربية'},
                                {v:'MA:fr',c:'MA',f:'🇲🇦',n:'Morocco',l:'fr',ln:'Français'},
                                {v:'TN',c:'TN',f:'🇹🇳',n:'Tunisia',l:'ar',ln:'العربية'},
                                {v:'SN',c:'SN',f:'🇸🇳',n:'Senegal',l:'fr',ln:'Français'},
                            ];
                            function sbRenderCountries(filter) {
                                var list = document.getElementById('sb-country-list');
                                var q = (filter||'').toLowerCase();
                                var html = '';
                                sbCountries.forEach(function(c) {
                                    var searchStr = (c.n+' '+c.ln+' '+c.l+' '+c.c).toLowerCase();
                                    if (q && searchStr.indexOf(q) === -1) return;
                                    html += '<div class="sb-country-item" style="display:flex;align-items:center;gap:10px;padding:8px 12px;cursor:pointer;font-size:13px" onmouseenter="this.style.background=\'#f1f5f9\'" onmouseleave="this.style.background=\'#fff\'" onclick="sbSelectCountry(\''+c.v+'\',\''+c.c+'\',\''+c.f+'\',\''+c.n.replace(/'/g,"\\'")+'\',\''+c.l+'\',\''+c.ln.replace(/'/g,"\\'")+'\')">';
                                    html += '<span style="font-size:20px;width:28px;text-align:center">'+c.f+'</span>';
                                    html += '<span style="flex:1;color:#1e293b">'+c.n+'</span>';
                                    html += '<span style="font-size:11px;color:#64748b;background:#f1f5f9;padding:2px 8px;border-radius:4px">'+c.ln+'</span>';
                                    html += '</div>';
                                });
                                list.innerHTML = html || '<div style="padding:16px;text-align:center;color:#9ca3af;font-size:13px">No countries found</div>';
                            }
                            function sbFilterCountries(q) { sbRenderCountries(q); }
                            function sbSelectCountry(v, c, f, n, l, ln) {
                                document.getElementById('sb-country-val').value = c;
                                document.getElementById('sb-lang-val').value = l;
                                document.getElementById('sb-country-flag').textContent = f;
                                document.getElementById('sb-country-label').textContent = n + ' — ' + ln;
                                document.getElementById('sb-country-dropdown').style.display = 'none';
                                document.getElementById('sb-country-search').value = '';
                            }
                            // Init
                            sbRenderCountries('');
                            // Close on outside click
                            document.addEventListener('click', function(e) {
                                if (!document.getElementById('sb-country-picker').contains(e.target)) {
                                    document.getElementById('sb-country-dropdown').style.display = 'none';
                                }
                            });
                            // Set initial value if exists
                            <?php if ( ! empty( $_POST['country'] ) ) : ?>
                            (function() {
                                var saved = '<?php echo esc_js( $_POST['country'] ?? '' ); ?>';
                                var lang = '<?php echo esc_js( $_POST['language'] ?? 'en' ); ?>';
                                sbCountries.forEach(function(c) { if (c.c === saved && c.l === lang) sbSelectCountry(c.v,c.c,c.f,c.n,c.l,c.ln); });
                            })();
                            <?php endif; ?>
                            </script>
                        </div>
                    </div>
                </div>

                <!-- Generate Button -->
                <div style="display:flex;gap:12px;margin-bottom:24px">
                    <button type="submit" name="seobetter_generate_article" id="seobetter-async-generate" class="button sb-btn-primary" style="font-size:15px;padding:12px 32px;height:50px">
                        Generate Article
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

// Content type auto-adjust (tone + word count)
function sbContentTypeChanged(type) {
    var toneEl = document.querySelector('[name="tone"]');
    var wcEl = document.querySelector('[name="word_count"]');
    var presets = {
        blog_post:      {tone:'conversational',wc:'1500'},
        news_article:   {tone:'journalistic',wc:'1000'},
        opinion:        {tone:'authoritative',wc:'1500'},
        how_to:         {tone:'educational',wc:'2000'},
        listicle:       {tone:'conversational',wc:'2000'},
        review:         {tone:'authoritative',wc:'1500'},
        comparison:     {tone:'authoritative',wc:'2000'},
        buying_guide:   {tone:'authoritative',wc:'2500'},
        pillar_guide:   {tone:'authoritative',wc:'3000'},
        case_study:     {tone:'professional',wc:'1500'},
        interview:      {tone:'conversational',wc:'1500'},
        faq_page:       {tone:'educational',wc:'1000'},
        recipe:         {tone:'conversational',wc:'1000'},
        tech_article:   {tone:'educational',wc:'2000'},
        white_paper:    {tone:'professional',wc:'3000'},
        scholarly_article:{tone:'professional',wc:'3000'},
        live_blog:      {tone:'journalistic',wc:'1000'},
        press_release:  {tone:'professional',wc:'800'},
        personal_essay: {tone:'conversational',wc:'1500'},
        glossary_definition:{tone:'educational',wc:'800'},
        sponsored:      {tone:'conversational',wc:'1000'},
    };
    var p = presets[type];
    if (p && toneEl && wcEl) {
        toneEl.value = p.tone;
        wcEl.value = p.wc;
    }
}

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
    btn.disabled=true; st.textContent='Researching real search demand...';
    // Use new topic-research endpoint (Google Suggest + Reddit + Wikipedia + Datamuse)
    fetch(CLOUD + '/api/topic-research', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ niche: niche, site_url: SITE })
    }).then(r=>r.json()).then(d => {
        btn.disabled=false;
        if (d.success && d.topics && d.topics.length) {
            var genUrl='<?php echo esc_js( admin_url('admin.php?page=seobetter-generate') ); ?>';
            var intentColors = {
                'informational': '#3b82f6',
                'commercial': '#22c55e',
                'transactional': '#f59e0b'
            };
            var html = d.topics.map(function(t) {
                var color = intentColors[t.intent] || '#6b7280';
                return '<div style="padding:8px 0;border-bottom:1px solid var(--sb-border)">' +
                    '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px">' +
                        '<div style="flex:1;min-width:0">' +
                            '<div style="font-size:12px;font-weight:600;color:#1f2937;line-height:1.3">' + escHtml(t.topic) + '</div>' +
                            '<div style="display:flex;gap:4px;margin-top:3px;flex-wrap:wrap">' +
                                '<span style="font-size:9px;padding:1px 5px;background:' + color + '20;color:' + color + ';border-radius:3px;font-weight:600;text-transform:uppercase">' + t.intent + '</span>' +
                                '<span style="font-size:9px;padding:1px 5px;background:#f3f4f6;color:#6b7280;border-radius:3px">' + escHtml(t.source) + '</span>' +
                                '<span style="font-size:9px;padding:1px 5px;background:#fef3c7;color:#92400e;border-radius:3px">' + Math.round(t.score) + '</span>' +
                            '</div>' +
                            '<div style="font-size:10px;color:#9ca3af;margin-top:2px">' + escHtml(t.reason) + '</div>' +
                        '</div>' +
                        '<a href="' + genUrl + '&keyword=' + encodeURIComponent(t.topic) + '" style="font-size:11px;white-space:nowrap;flex-shrink:0">Use →</a>' +
                    '</div>' +
                '</div>';
            }).join('');
            var summary = '<div style="padding:6px 8px;background:#eef2ff;border-radius:4px;font-size:10px;color:#4338ca;margin-bottom:6px">' +
                d.topics.length + ' topics from real search data: ' +
                d.sources.google_suggest + ' Google Suggest, ' +
                d.sources.reddit + ' Reddit, ' +
                d.sources.wikipedia + ' Wikipedia, ' +
                d.sources.datamuse + ' Datamuse' +
            '</div>';
            document.getElementById('sb-topics-list').innerHTML = summary + html;
            document.getElementById('sb-topics-list').style.display = 'block';
            st.textContent = d.topics.length + ' real-data topics';
        } else {
            st.textContent = d.error || 'No topics found. Try a different niche.';
        }
    }).catch(function(e) { btn.disabled=false; st.textContent='Error: ' + e.message; });
});

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

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
        var score = res.geo_score || 0;
        var sc = score >= 80 ? 'good' : (score >= 60 ? 'ok' : 'poor');
        var scoreColor = score >= 80 ? '#22c55e' : (score >= 60 ? '#f59e0b' : '#ef4444');
        var scoreBg = score >= 80 ? '#f0fdf4' : (score >= 60 ? '#fffbeb' : '#fef2f2');
        var scoreRing = score >= 80 ? '#dcfce7' : (score >= 60 ? '#fef3c7' : '#fee2e2');

        var h = '<div style="margin-top:16px">';

        // ===== SCORE DASHBOARD =====
        h += '<div style="display:grid;grid-template-columns:180px 1fr;gap:20px;padding:24px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:16px">';

        // Left: Score circle
        h += '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center">';
        h += '<div style="position:relative;width:130px;height:130px">';
        h += '<svg viewBox="0 0 120 120" style="width:130px;height:130px;transform:rotate(-90deg)">';
        h += '<circle cx="60" cy="60" r="52" fill="none" stroke="'+scoreRing+'" stroke-width="10"/>';
        h += '<circle cx="60" cy="60" r="52" fill="none" stroke="'+scoreColor+'" stroke-width="10" stroke-linecap="round" stroke-dasharray="'+(326.7*score/100)+' 326.7" style="transition:stroke-dasharray 1s ease"/>';
        h += '</svg>';
        h += '<div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center">';
        h += '<span style="font-size:36px;font-weight:800;color:'+scoreColor+'">'+score+'</span>';
        h += '<span style="font-size:12px;font-weight:600;color:#6b7280">'+esc(res.grade)+'</span>';
        h += '</div></div>';
        h += '<span style="font-size:11px;color:#9ca3af;margin-top:6px">GEO Score</span>';
        h += '</div>';

        // Right: Stats grid
        h += '<div>';
        h += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px">';
        // Words stat
        h += '<div style="padding:12px;background:#f8fafc;border-radius:8px;text-align:center">';
        h += '<div style="font-size:22px;font-weight:700;color:#1e293b">'+(res.word_count||0).toLocaleString()+'</div>';
        h += '<div style="font-size:11px;color:#64748b">Words</div></div>';
        // Citations stat
        var citCount = 0; if(res.checks&&res.checks.citations) citCount = res.checks.citations.count||0;
        h += '<div style="padding:12px;background:#f8fafc;border-radius:8px;text-align:center">';
        h += '<div style="font-size:22px;font-weight:700;color:'+(citCount>=5?'#22c55e':'#ef4444')+'">'+citCount+'</div>';
        h += '<div style="font-size:11px;color:#64748b">Citations (5+ needed)</div></div>';
        // Quotes stat
        var quoteCount = 0; if(res.checks&&res.checks.expert_quotes) quoteCount = res.checks.expert_quotes.count||0;
        h += '<div style="padding:12px;background:#f8fafc;border-radius:8px;text-align:center">';
        h += '<div style="font-size:22px;font-weight:700;color:'+(quoteCount>=2?'#22c55e':'#ef4444')+'">'+quoteCount+'</div>';
        h += '<div style="font-size:11px;color:#64748b">Expert Quotes (2+ needed)</div></div>';
        h += '</div>';

        // Score breakdown bars
        if (res.checks) {
            var barItems = [
                {label:'Readability',w:12,s:res.checks.readability},
                {label:'Citations',w:12,s:res.checks.citations},
                {label:'Statistics',w:12,s:res.checks.factual_density},
                {label:'Key Takeaways',w:10,s:res.checks.bluf_header},
                {label:'Section Openers',w:10,s:res.checks.section_openings},
                {label:'Island Test',w:10,s:res.checks.island_test},
                {label:'Expert Quotes',w:8,s:res.checks.expert_quotes},
                {label:'Entity Density',w:8,s:res.checks.entity_usage},
                {label:'Freshness',w:7,s:res.checks.freshness},
                {label:'Tables',w:6,s:res.checks.tables},
                {label:'Lists',w:5,s:res.checks.lists}
            ];
            h += '<div style="display:flex;flex-direction:column;gap:4px">';
            barItems.forEach(function(item) {
                if (!item.s) return;
                var s = item.s.score||0;
                var barColor = s >= 80 ? '#22c55e' : (s >= 50 ? '#f59e0b' : '#ef4444');
                h += '<div style="display:flex;align-items:center;gap:8px;font-size:11px">';
                h += '<span style="width:100px;color:#64748b;text-align:right">'+item.label+'</span>';
                h += '<div style="flex:1;height:8px;background:#f1f5f9;border-radius:4px;overflow:hidden"><div style="width:'+s+'%;height:100%;background:'+barColor+';border-radius:4px;transition:width 0.5s"></div></div>';
                h += '<span style="width:30px;font-weight:600;color:'+barColor+'">'+s+'</span>';
                h += '</div>';
            });
            h += '</div>';
        }
        h += '</div></div>';

        // ===== PRO UPSELL (if score < 80) =====
        if (score < 80) {
            var missingPoints = 80 - score;
            h += '<div style="padding:16px 20px;background:linear-gradient(135deg,#eef2ff,#e0e7ff);border:1px solid #c7d2fe;border-radius:10px;margin-bottom:16px;display:flex;align-items:center;gap:16px">';
            h += '<div style="flex-shrink:0;width:44px;height:44px;background:linear-gradient(135deg,#764ba2,#667eea);border-radius:10px;display:flex;align-items:center;justify-content:center"><svg width="22" height="22" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></div>';
            h += '<div style="flex:1"><div style="font-size:14px;font-weight:700;color:#312e81">+'+missingPoints+' points needed for A grade</div>';
            h += '<div style="font-size:12px;color:#4338ca;margin-top:2px">Pro plan adds Brave Search for real statistics, expert quotes, and authoritative citations that boost your GEO score to 80+</div></div>';
            h += '<a href="<?php echo esc_url( admin_url( 'admin.php?page=seobetter-settings' ) ); ?>" style="flex-shrink:0;padding:8px 16px;background:linear-gradient(135deg,#764ba2,#667eea);color:#fff;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;white-space:nowrap">Upgrade to Pro</a>';
            h += '</div>';
        }

        // ===== SUGGESTIONS =====
        if (res.suggestions && res.suggestions.length) {
            var highPri = res.suggestions.filter(function(s){return s.priority==='high'});
            var medPri = res.suggestions.filter(function(s){return s.priority!=='high'});
            if (highPri.length) {
                h += '<div style="margin-bottom:12px">';
                h += '<div style="font-size:12px;font-weight:600;color:#991b1b;margin-bottom:6px">Fix these for a higher score:</div>';
                highPri.forEach(function(s) {
                    h += '<div style="padding:8px 12px;background:#fef2f2;border-left:3px solid #ef4444;border-radius:0 6px 6px 0;margin-bottom:4px;font-size:12px;color:#991b1b">';
                    h += '<strong>['+esc(s.type||'issue')+']</strong> '+esc(s.message)+'</div>';
                });
                h += '</div>';
            }
            if (medPri.length) {
                h += '<details style="margin-bottom:12px"><summary style="font-size:12px;font-weight:600;color:#92400e;cursor:pointer">'+medPri.length+' additional suggestions</summary>';
                h += '<div style="margin-top:6px">';
                medPri.forEach(function(s) {
                    h += '<div style="padding:6px 12px;background:#fffbeb;border-left:3px solid #f59e0b;border-radius:0 6px 6px 0;margin-bottom:3px;font-size:12px;color:#92400e">';
                    h += '<strong>['+esc(s.type||'issue')+']</strong> '+esc(s.message)+'</div>';
                });
                h += '</div></details>';
            }
        }

        // ===== ANALYZE & IMPROVE PANEL =====
        var isPro = <?php echo json_encode( SEOBetter\License_Manager::is_pro() ); ?>;
        var fixes = [];

        if (res.checks) {
            var c = res.checks;
            if (c.citations && c.citations.score < 80) fixes.push({id:'citations', label:'Add Citations & References', desc:c.citations.count+' citations found. Top content has 5+. Uses real web sources (no hallucinated links).', icon:'admin-links', impact:'+12 pts', mode:'inject'});
            if (c.expert_quotes && c.expert_quotes.score < 100) fixes.push({id:'quotes', label:'Add Expert Quotes', desc:c.expert_quotes.count+' quotes found. Expert quotes boost GEO visibility by 41%. Inserts 2 quotes without editing existing text.', icon:'format-quote', impact:'+8 pts', mode:'inject'});
            if (c.factual_density && c.factual_density.score < 70) fixes.push({id:'statistics', label:'Add Statistics', desc:'Not enough numbers. Uses real research data from web search. Inserts stats without editing existing text.', icon:'chart-bar', impact:'+12 pts', mode:'inject'});
            if (c.tables && c.tables.score < 50) fixes.push({id:'table', label:'Add Comparison Table', desc:'No tables found. Tables get cited 30-40% more by AI. Inserts a table without editing existing text.', icon:'editor-table', impact:'+6 pts', mode:'inject'});
            if (c.freshness && c.freshness.score < 100) fixes.push({id:'freshness', label:'Add Freshness Signal', desc:'No "Last Updated" date. Adds date at top without editing existing text.', icon:'calendar-alt', impact:'+7 pts', mode:'inject'});
            if (c.readability && c.readability.score < 70) fixes.push({id:'readability', label:'Check Readability', desc:'Grade '+((c.readability.flesch_grade||'?'))+' is too complex. Shows complex sentences and words to simplify manually.', icon:'editor-spellcheck', impact:'+12 pts', mode:'flag'});
            if (c.island_test && c.island_test.score < 80) fixes.push({id:'island', label:'Check Pronoun Starts', desc:c.island_test.detail+'. Shows which paragraphs to fix manually.', icon:'editor-removeformatting', impact:'+10 pts', mode:'flag'});
            if (c.section_openings && c.section_openings.score < 70) fixes.push({id:'openers', label:'Check Section Openings', desc:c.section_openings.detail+'. Shows which sections need better openers.', icon:'editor-paragraph', impact:'+10 pts', mode:'flag'});
        }

        if (fixes.length > 0) {
            h += '<div style="padding:20px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:16px">';
            h += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">';
            h += '<div><h3 style="margin:0;font-size:16px;font-weight:700">Analyze &amp; Improve</h3>';
            h += '<p style="margin:4px 0 0;font-size:12px;color:#6b7280">'+fixes.length+' improvements found — fix these to reach score 80+</p></div>';
            if (!isPro) {
                h += '<span style="padding:4px 10px;background:linear-gradient(135deg,#764ba2,#667eea);color:#fff;border-radius:20px;font-size:11px;font-weight:600">PRO</span>';
            }
            h += '</div>';

            fixes.forEach(function(fix, idx) {
                h += '<div style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#f8fafc;border-radius:8px;margin-bottom:8px'+(idx===0?'':'')+'">';
                h += '<span class="dashicons dashicons-'+fix.icon+'" style="color:#764ba2;font-size:20px;width:20px;height:20px;flex-shrink:0"></span>';
                h += '<div style="flex:1;min-width:0"><div style="font-size:13px;font-weight:600;color:#1e293b">'+fix.label+'</div>';
                h += '<div style="font-size:11px;color:#64748b;margin-top:2px">'+fix.desc+'</div></div>';
                h += '<span style="font-size:11px;font-weight:600;color:#22c55e;white-space:nowrap;margin-right:8px">'+fix.impact+'</span>';
                var btnLabel = fix.mode === 'inject' ? 'Add now' : 'Check';
                var btnColor = fix.mode === 'inject' ? '#764ba2' : '#6b7280';
                h += '<button type="button" class="button sb-improve-btn" data-fix-id="'+fix.id+'" style="height:32px;font-size:12px;padding:0 14px;background:'+btnColor+';color:#fff;border:none;border-radius:6px;cursor:pointer;white-space:nowrap">'+btnLabel+'</button>';
                h += '</div>';
            });

            var totalImpact = fixes.reduce(function(sum, f) { return sum + parseInt(f.impact) }, 0);
            h += '<div style="margin-top:12px;padding:10px 16px;background:#f0fdf4;border-radius:8px;text-align:center">';
            h += '<span style="font-size:12px;color:#166534">💡 Inject fixes add content without editing existing text. Check fixes show what to fix manually. Potential: <strong>+'+totalImpact+' points</strong></span>';
            h += '</div>';
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
            keyword: res.keyword || '',
            content_type: (document.querySelector('[name="content_type"]')||{}).value||'blog_post',
            meta_title: (res.meta && res.meta.title) || bestTitle || '',
            meta_description: (res.meta && res.meta.description) || '',
            og_title: (res.meta && res.meta.og_title) || bestTitle || ''
        };

        h += '<div style="margin-top:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">';
        h += '<span style="font-size:13px;color:#6b7280">Save as</span>';
        h += '<div style="position:relative;display:inline-block">';
        h += '<select id="seobetter-post-type" style="height:40px;padding:0 32px 0 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;color:#374151;background:#fff;appearance:none;-webkit-appearance:none;cursor:pointer;outline:none;min-width:100px">';
        h += '<option value="post">Post</option>';
        h += '<option value="page">Page</option>';
        h += '</select>';
        h += '<svg style="position:absolute;right:10px;top:50%;transform:translateY(-50%);pointer-events:none;width:14px;height:14px;color:#6b7280" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>';
        h += '</div>';
        h += '<button type="button" id="seobetter-save-draft-btn" class="button sb-btn-primary" style="height:44px">Save Draft</button>';
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

            // Get selected post type
            var postTypeSelect = document.getElementById('seobetter-post-type');
            draft.post_type = postTypeSelect ? postTypeSelect.value : 'post';

            api('save-draft', 'POST', draft).then(function(r) {
                if (r.success) {
                    btn.textContent = 'Saved!';
                    btn.style.background = '#059669';
                    var schemaNote = '';
                    if (r.schema_dest === 'aioseo') schemaNote = ' <span style="font-size:11px;color:#6b7280">Schema → AIOSEO</span>';
                    else if (r.schema_dest === 'yoast') schemaNote = ' <span style="font-size:11px;color:#6b7280">Schema → Yoast SEO</span>';
                    else if (r.schema_dest === 'rankmath') schemaNote = ' <span style="font-size:11px;color:#6b7280">Schema → RankMath</span>';
                    else schemaNote = ' <span style="font-size:11px;color:#6b7280">Schema → SEOBetter (auto-injected)</span>';
                    statusEl.innerHTML = '<a href="'+r.edit_url+'" style="color:#764ba2;font-weight:600">Edit post &rarr;</a>' + schemaNote;
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

        // Wire up "Fix now" buttons — inject-only (never edits existing content)
        document.querySelectorAll('.sb-improve-btn').forEach(function(fixBtn) {
            fixBtn.addEventListener('click', function() {
                var fixId = this.getAttribute('data-fix-id');
                var draft = window._seobetterDraft;
                if (!draft || !draft.markdown) { alert('No content to improve.'); return; }

                this.disabled = true;
                this.textContent = 'Fixing...';
                var self = this;

                // Use inject-fix endpoint (inject-only, never rewrites)
                api('inject-fix', 'POST', {
                    fix_type: fixId,
                    markdown: draft.markdown,
                    keyword: draft.keyword,
                    accent_color: draft.accent_color
                }).then(function(result) {
                    if (result.type === 'flag') {
                        // Flag-only fix — show suggestions, don't edit content
                        self.textContent = 'See below';
                        self.style.background = '#f59e0b';

                        var flagHtml = '<div style="margin-top:8px;padding:10px;background:#fffbeb;border:1px solid #f59e0b;border-radius:6px;font-size:12px">';
                        flagHtml += '<strong>' + esc(result.message || '') + '</strong>';

                        if (result.long_sentences) {
                            result.long_sentences.forEach(function(s) {
                                flagHtml += '<div style="margin-top:6px;padding:6px 8px;background:#fff;border-radius:4px;border-left:2px solid #f59e0b">';
                                flagHtml += '<div style="color:#92400e">"' + esc(s.text) + '"</div>';
                                flagHtml += '<div style="color:#6b7280;font-size:11px;margin-top:2px">' + esc(s.tip) + '</div></div>';
                            });
                        }
                        if (result.complex_words) {
                            result.complex_words.forEach(function(w) {
                                flagHtml += '<div style="margin-top:4px;font-size:11px">Replace "<strong>' + esc(w.word) + '</strong>" → "<strong>' + esc(w.replacement) + '</strong>"</div>';
                            });
                        }
                        if (result.violations) {
                            result.violations.forEach(function(v) {
                                flagHtml += '<div style="margin-top:6px;padding:6px 8px;background:#fff;border-radius:4px;border-left:2px solid #f59e0b">';
                                flagHtml += '<div style="color:#92400e">"' + esc(v.text) + '"</div>';
                                flagHtml += '<div style="color:#6b7280;font-size:11px;margin-top:2px">' + esc(v.tip) + '</div></div>';
                            });
                        }
                        if (result.sections) {
                            result.sections.forEach(function(s) {
                                flagHtml += '<div style="margin-top:4px;font-size:11px"><strong>' + esc(s.heading) + '</strong> — ' + esc(s.tip) + '</div>';
                            });
                        }
                        flagHtml += '</div>';

                        self.parentElement.insertAdjacentHTML('afterend', flagHtml);
                        return;
                    }

                    if (result.success && result.content) {
                        // Inject fix succeeded — update draft
                        draft.markdown = result.markdown || draft.markdown;
                        draft.content = result.content;
                        self.textContent = '✓ ' + (result.added || 'Done');
                        self.style.background = '#22c55e';

                        // Update score display
                        var scoreEl = document.querySelector('.sb-geo-ring-score');
                        if (scoreEl) scoreEl.textContent = result.geo_score;
                        var gradeEl = document.querySelector('.sb-geo-ring-grade');
                        if (gradeEl) gradeEl.textContent = result.grade;

                        // Update preview
                        var preview = document.querySelector('.seobetter-content-preview');
                        if (preview) {
                            var newContent = result.content || '';
                            newContent = newContent.replace(/<style>[\s\S]*?<\/style>/, '');
                            preview.innerHTML = newContent;
                        }

                        setTimeout(function() { self.parentElement.style.opacity = '0.6'; }, 1500);
                    } else {
                        self.disabled = false;
                        self.textContent = 'Retry';
                        self.style.background = '#ef4444';
                    }
                }).catch(function() {
                    self.disabled = false;
                    self.textContent = 'Failed';
                        self.style.background = '#ef4444';
                    }
                }).catch(function() {
                    self.disabled = false;
                    self.textContent = 'Error';
                    self.style.background = '#ef4444';
                });
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
            content_type: (form.querySelector('[name="content_type"]')||{}).value||'blog_post',
            domain: (form.querySelector('[name="domain"]')||{}).value||'general',
            audience: (form.querySelector('[name="audience"]')||{}).value||'',
            country: (form.querySelector('[name="country"]')||{}).value||'',
            language: (form.querySelector('[name="language"]')||{}).value||'en',
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
