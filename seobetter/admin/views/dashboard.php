<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$status = SEOBetter\Cloud_API::check_status();
$license = SEOBetter\License_Manager::get_info();
$is_pro = $license['is_pro'];
$provider = SEOBetter\AI_Provider_Manager::get_active_provider();
$has_key = ! empty( $provider );

// Get recent generated articles
$generated_posts = get_posts( [
    'post_type'      => [ 'post', 'page' ],
    'post_status'    => [ 'publish', 'draft', 'pending' ],
    'posts_per_page' => 10,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'meta_key'       => '_seobetter_geo_score',
] );

$total_generated = count( $generated_posts );

// Setup completion
$steps_done = 0;
if ( $has_key ) $steps_done++;
if ( $total_generated > 0 ) $steps_done++;
$setup_complete = $steps_done >= 2;

// v1.5.214 — Monthly stats for the top stat strip.
// Compute from real post meta (no extra DB tables, no API calls).
$first_of_month = strtotime( date( 'Y-m-01 00:00:00' ) );
$articles_this_month = get_posts( [
    'post_type'      => [ 'post', 'page' ],
    'post_status'    => [ 'publish', 'draft', 'pending' ],
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'meta_key'       => '_seobetter_geo_score',
    'date_query'     => [
        [ 'after' => date( 'Y-m-01' ), 'inclusive' => true ],
    ],
] );
$articles_count = count( $articles_this_month );

// Avg GEO score across this month's articles
$score_sum = 0; $score_n = 0; $high_geo = 0; $schemas_total = 0;
foreach ( $articles_this_month as $aid ) {
    $sd = get_post_meta( $aid, '_seobetter_geo_score', true );
    if ( is_array( $sd ) && isset( $sd['geo_score'] ) && is_numeric( $sd['geo_score'] ) ) {
        $score_sum += (int) $sd['geo_score'];
        $score_n++;
        if ( (int) $sd['geo_score'] >= 80 ) $high_geo++;
    }
    // Count schema nodes (proxy for "schemas shipped")
    $schema_json = get_post_meta( $aid, '_seobetter_schema', true );
    if ( $schema_json ) {
        $sd2 = json_decode( $schema_json, true );
        if ( is_array( $sd2 ) && isset( $sd2['@graph'] ) && is_array( $sd2['@graph'] ) ) {
            $schemas_total += count( $sd2['@graph'] );
        }
    }
}
$avg_geo = $score_n > 0 ? (int) round( $score_sum / $score_n ) : 0;
$avg_geo_color = $avg_geo >= 80 ? '#10b981' : ( $avg_geo >= 60 ? '#f59e0b' : ( $avg_geo > 0 ? '#ef4444' : '#94a3b8' ) );
?>
<div class="wrap seobetter-dashboard">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
        <div>
            <h1 style="margin:0;font-size:24px;font-weight:700;color:var(--sb-text,#1e293b)">SEOBetter</h1>
            <p style="margin:4px 0 0;font-size:14px;color:var(--sb-text-secondary,#64748b)">AI content that search engines and AI models cite.</p>
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

    <?php if ( ! $setup_complete ) : ?>
    <!-- Onboarding: Get Started -->
    <div class="seobetter-card" style="background:linear-gradient(135deg,#764ba2 0%,#667eea 100%);color:#fff;margin-bottom:24px">
        <h2 style="color:#fff;margin:0 0 8px;font-size:20px">Generate your first AI-optimized article in 3 steps</h2>
        <p style="opacity:0.9;margin:0 0 20px;font-size:14px">Articles built to rank on Google AND get cited by ChatGPT, Perplexity, Gemini & AI Overviews.</p>

        <!-- Progress -->
        <div style="background:rgba(255,255,255,0.2);border-radius:20px;height:8px;margin-bottom:20px;overflow:hidden">
            <div style="background:#fff;height:100%;width:<?php echo round( ( $steps_done / 2 ) * 100 ); ?>%;border-radius:20px;transition:width 0.3s"></div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <!-- Step 1 -->
            <div style="background:rgba(255,255,255,<?php echo $has_key ? '0.15' : '0.1'; ?>);padding:16px 20px;border-radius:8px;border:1px solid rgba(255,255,255,0.2)">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                    <?php if ( $has_key ) : ?>
                        <span style="background:#fff;color:#059669;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700">&#10003;</span>
                    <?php else : ?>
                        <span style="background:rgba(255,255,255,0.3);color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700">1</span>
                    <?php endif; ?>
                    <strong style="font-size:14px"><?php echo $has_key ? 'AI Connected' : 'Connect AI (30 seconds)'; ?></strong>
                </div>
                <?php if ( ! $has_key ) : ?>
                    <p style="margin:0 0 10px;font-size:13px;opacity:0.85">Get a free API key from OpenRouter or Groq — no credit card needed.</p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=seobetter-settings' ) ); ?>" style="color:#fff;font-weight:600;font-size:13px;text-decoration:underline">Set up now &rarr;</a>
                <?php else : ?>
                    <p style="margin:0;font-size:13px;opacity:0.85">Using: <?php echo esc_html( $provider['name'] ?? 'Connected' ); ?></p>
                <?php endif; ?>
            </div>

            <!-- Step 2 -->
            <div style="background:rgba(255,255,255,<?php echo $total_generated > 0 ? '0.15' : '0.1'; ?>);padding:16px 20px;border-radius:8px;border:1px solid rgba(255,255,255,0.2)">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                    <?php if ( $total_generated > 0 ) : ?>
                        <span style="background:#fff;color:#059669;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700">&#10003;</span>
                    <?php else : ?>
                        <span style="background:rgba(255,255,255,0.3);color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700">2</span>
                    <?php endif; ?>
                    <strong style="font-size:14px"><?php echo $total_generated > 0 ? 'First article created!' : 'Generate your first article'; ?></strong>
                </div>
                <?php if ( $total_generated === 0 ) : ?>
                    <p style="margin:0 0 10px;font-size:13px;opacity:0.85">Enter a keyword and SEOBetter writes an article optimized for AI citations.</p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=seobetter-generate' ) ); ?>" style="color:#fff;font-weight:600;font-size:13px;text-decoration:underline">Generate now &rarr;</a>
                <?php else : ?>
                    <p style="margin:0;font-size:13px;opacity:0.85"><?php echo esc_html( $total_generated ); ?> article(s) generated</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quick Generate Bar -->
    <div class="seobetter-card" style="margin-bottom:24px">
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;gap:12px;align-items:center">
            <input type="hidden" name="page" value="seobetter-generate" />
            <div style="flex:1">
                <input type="text" name="keyword" placeholder="Enter a keyword to generate an article..." style="width:100%;height:44px;padding:10px 16px;font-size:14px;border:1px solid var(--sb-border,#e2e8f0);border-radius:8px" />
            </div>
            <button type="submit" class="button sb-btn-primary" style="height:44px;white-space:nowrap">
                Generate Article &rarr;
            </button>
        </form>
    </div>

    <!-- v1.5.214 — Monthly stat strip. Value-first: anchor user in measurable wins
         from the free plan before any upsell. Per Reforge / ProductLed PLG research,
         users who see their own metrics convert 3-7× better than users who see only
         feature lists. -->
    <div class="seobetter-card" style="margin-bottom:24px;padding:18px 20px">
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px">
            <!-- Stat 1: Articles this month -->
            <div style="padding:14px 16px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:6px">
                    <?php esc_html_e( 'This month', 'seobetter' ); ?>
                </div>
                <div style="font-size:28px;font-weight:700;color:#1e293b;line-height:1"><?php echo (int) $articles_count; ?></div>
                <div style="font-size:11px;color:#94a3b8;margin-top:6px">
                    <?php echo esc_html( sprintf( _n( '%d article generated', '%d articles generated', $articles_count, 'seobetter' ), $articles_count ) ); ?>
                </div>
            </div>
            <!-- Stat 2: Avg GEO score -->
            <div style="padding:14px 16px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:6px">
                    <?php esc_html_e( 'Avg GEO score', 'seobetter' ); ?>
                </div>
                <div style="font-size:28px;font-weight:700;color:<?php echo esc_attr( $avg_geo_color ); ?>;line-height:1">
                    <?php echo $avg_geo > 0 ? (int) $avg_geo : '—'; ?>
                </div>
                <div style="font-size:11px;color:#94a3b8;margin-top:6px">
                    <?php
                    if ( $avg_geo >= 80 ) {
                        echo esc_html__( 'Top-tier AI citability', 'seobetter' );
                    } elseif ( $avg_geo >= 60 ) {
                        echo esc_html__( 'Solid — push to 80+ for top tier', 'seobetter' );
                    } elseif ( $avg_geo > 0 ) {
                        echo esc_html__( 'Re-optimize to lift score', 'seobetter' );
                    } else {
                        echo esc_html__( 'No data yet', 'seobetter' );
                    }
                    ?>
                </div>
            </div>
            <!-- Stat 3: High-GEO articles -->
            <div style="padding:14px 16px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:6px">
                    <?php esc_html_e( 'GEO 80+', 'seobetter' ); ?>
                </div>
                <div style="font-size:28px;font-weight:700;color:#10b981;line-height:1"><?php echo (int) $high_geo; ?></div>
                <div style="font-size:11px;color:#94a3b8;margin-top:6px">
                    <?php esc_html_e( 'Articles likely to be AI-cited', 'seobetter' ); ?>
                </div>
            </div>
            <!-- Stat 4: Schema nodes shipped -->
            <div style="padding:14px 16px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:6px">
                    <?php esc_html_e( 'Schema nodes', 'seobetter' ); ?>
                </div>
                <div style="font-size:28px;font-weight:700;color:#1e293b;line-height:1"><?php echo (int) $schemas_total; ?></div>
                <div style="font-size:11px;color:#94a3b8;margin-top:6px">
                    <?php esc_html_e( 'Across @graph (Recipe, Article, Org, Person, Breadcrumb, etc.)', 'seobetter' ); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="seobetter-cards">

        <!-- What's Included -->
        <div class="seobetter-card">
            <h2 style="margin-bottom:16px">What You Get</h2>

            <div style="margin-bottom:20px">
                <h3 style="font-size:13px;text-transform:uppercase;letter-spacing:0.05em;color:var(--sb-text-secondary,#64748b);margin-bottom:12px">
                    <span class="seobetter-score seobetter-score-good" style="margin-right:6px">FREE</span> Included
                </h3>
                <ul style="list-style:none;padding:0;margin:0;font-size:13px;line-height:2.0">
                    <li><span style="color:var(--sb-success,#059669);margin-right:8px">&#10003;</span> AI article generation (<?php echo esc_html( $status['monthly_limit'] ); ?>/month via Cloud, OR unlimited with your own API key)</li>
                    <li><span style="color:var(--sb-success,#059669);margin-right:8px">&#10003;</span> 3 content types: Blog Post, How-To, Listicle</li>
                    <li><span style="color:var(--sb-success,#059669);margin-right:8px">&#10003;</span> GEO Score — measures AI citability of your content</li>
                    <li><span style="color:var(--sb-success,#059669);margin-right:8px">&#10003;</span> Article + Recipe + Organization + Person + BreadcrumbList schema</li>
                    <li><span style="color:var(--sb-success,#059669);margin-right:8px">&#10003;</span> 5 headline variations + auto SEO meta title/description per article</li>
                    <li><span style="color:var(--sb-success,#059669);margin-right:8px">&#10003;</span> Auto-suggest secondary &amp; LSI keywords (Google Suggest + Wikipedia)</li>
                    <li><span style="color:var(--sb-success,#059669);margin-right:8px">&#10003;</span> Pexels stock images via SEOBetter Cloud (no API key needed)</li>
                    <li><span style="color:var(--sb-success,#059669);margin-right:8px">&#10003;</span> Jina Reader fallback for web research</li>
                    <li><span style="color:var(--sb-success,#059669);margin-right:8px">&#10003;</span> Topic suggestions + social media content (Twitter / LinkedIn / Instagram)</li>
                    <li><span style="color:var(--sb-success,#059669);margin-right:8px">&#10003;</span> Connect 1 AI provider (Anthropic / OpenAI / Gemini / OpenRouter / Groq / Ollama)</li>
                </ul>
            </div>

            <?php if ( ! $is_pro ) : ?>
            <!-- v1.5.214 — Bundled-value Pro card. Per pricing research (Reforge,
                 Stripe, Linear), users anchor on TOTAL feature gap, not individual
                 line items. Listing "10 locked things" beats listing "1 killer feature".
                 Each line is one specific outcome — no generic "advanced features" copy. -->
            <div style="padding:20px;background:var(--sb-primary-light,#f3eef8);border:2px solid var(--sb-primary,#764ba2);border-radius:8px">
                <h3 style="font-size:13px;text-transform:uppercase;letter-spacing:0.05em;color:var(--sb-primary,#764ba2);margin-bottom:12px">
                    <span class="seobetter-score seobetter-score-good" style="background:var(--sb-primary,#764ba2);color:#fff;margin-right:6px">PRO $39/mo</span> Unlock the rest
                </h3>
                <ul style="list-style:none;padding:0;margin:0 0 16px;font-size:13px;line-height:2.0">
                    <li><span style="color:var(--sb-primary,#764ba2);margin-right:8px">&#9733;</span> <strong>50 Cloud articles/month</strong> on Sonnet-tier LLM (vs 5 on Free)</li>
                    <li><span style="color:var(--sb-primary,#764ba2);margin-right:8px">&#9733;</span> <strong>All 21 content types</strong> — Recipe, Comparison, Buying Guide, Review, Case Study, Interview, Pillar Guide, etc. (Free: 3 types)</li>
                    <li><span style="color:var(--sb-primary,#764ba2);margin-right:8px">&#9733;</span> <strong>Firecrawl deep research</strong> — 10× citation density vs free Jina fallback</li>
                    <li><span style="color:var(--sb-primary,#764ba2);margin-right:8px">&#9733;</span> <strong>Serper SERP intelligence</strong> — competitor gap analysis, audience inference</li>
                    <li><span style="color:var(--sb-primary,#764ba2);margin-right:8px">&#9733;</span> <strong>Auto-translate</strong> for 29 languages (cross-script keywords + headings + meta)</li>
                    <li><span style="color:var(--sb-primary,#764ba2);margin-right:8px">&#9733;</span> <strong>AI featured image</strong> — DALL-E 3 / FLUX Pro / Gemini Nano Banana</li>
                    <li><span style="color:var(--sb-primary,#764ba2);margin-right:8px">&#9733;</span> <strong>Recipe Article wrapper</strong> + Speakable voice schema (2× rich-result lanes)</li>
                    <li><span style="color:var(--sb-primary,#764ba2);margin-right:8px">&#9733;</span> <strong>5 Schema Blocks</strong> — Product, Event, LocalBusiness, Vacation Rental, Job Posting</li>
                    <li><span style="color:var(--sb-primary,#764ba2);margin-right:8px">&#9733;</span> <strong>AIOSEO / Yoast / RankMath auto-population</strong> — focus keyword, meta, OG, schema</li>
                    <li><span style="color:var(--sb-primary,#764ba2);margin-right:8px">&#9733;</span> <strong>Analyze &amp; Improve inject buttons</strong> — one-click +5-10 GEO points</li>
                    <li><span style="color:var(--sb-primary,#764ba2);margin-right:8px">&#9733;</span> <strong>Priority support</strong> 48h SLA</li>
                </ul>
                <p style="font-size:12px;color:#64748b;margin:0 0 14px 0;font-style:italic">
                    <?php esc_html_e( 'Annual: $349/yr — save $119 vs monthly.', 'seobetter' ); ?>
                </p>
                <a href="https://seobetter.com/pricing" target="_blank" class="button sb-btn-primary" style="width:100%;text-align:center;font-size:14px;height:44px;line-height:28px">
                    See Pro plans &rarr;
                </a>
            </div>
            <?php else : ?>
            <div style="padding:16px;background:var(--sb-success-bg,#ecfdf5);border-radius:8px;text-align:center">
                <span style="color:var(--sb-success,#059669);font-weight:600">&#10003; Pro Active</span>
                <span style="color:var(--sb-text-secondary,#64748b);font-size:13px;margin-left:8px">All features unlocked</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- How It Works -->
        <div class="seobetter-card">
            <h2 style="margin-bottom:16px">How SEOBetter Works</h2>

            <div style="margin-bottom:24px">
                <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:20px">
                    <span style="background:var(--sb-primary,#764ba2);color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0">1</span>
                    <div>
                        <strong style="font-size:14px;display:block;margin-bottom:2px">Enter your keyword</strong>
                        <span style="font-size:13px;color:var(--sb-text-secondary,#64748b)">Type your target keyword. SEOBetter auto-suggests secondary and LSI keywords for maximum AI visibility.</span>
                    </div>
                </div>
                <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:20px">
                    <span style="background:var(--sb-primary,#764ba2);color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0">2</span>
                    <div>
                        <strong style="font-size:14px;display:block;margin-bottom:2px">AI writes a GEO-optimized article</strong>
                        <span style="font-size:13px;color:var(--sb-text-secondary,#64748b)">Built with expert quotes, verifiable statistics, comparison tables, and the structure AI models look for when choosing what to cite.</span>
                    </div>
                </div>
                <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:20px">
                    <span style="background:var(--sb-primary,#764ba2);color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0">3</span>
                    <div>
                        <strong style="font-size:14px;display:block;margin-bottom:2px">Review, optimize, publish</strong>
                        <span style="font-size:13px;color:var(--sb-text-secondary,#64748b)">Pick your headline, check the GEO Score, re-optimize if needed, then save as a WordPress draft. Generate social posts to promote it.</span>
                    </div>
                </div>
            </div>

            <!-- Why GEO Matters -->
            <div style="padding:16px;background:var(--sb-bg,#f8fafc);border-radius:8px;border:1px solid var(--sb-border,#e2e8f0)">
                <h3 style="margin:0 0 10px;font-size:14px">Why GEO optimization matters</h3>
                <p style="margin:0 0 10px;font-size:13px;color:var(--sb-text-secondary,#64748b);line-height:1.6">
                    Over 60% of searches now involve AI-generated responses. Google AI Overviews, ChatGPT, Perplexity, and Gemini decide which content to cite based on <strong>structure, not just keywords</strong>.
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px">
                    <div style="padding:8px 12px;background:#fff;border-radius:6px;border:1px solid var(--sb-border,#e2e8f0)">
                        <strong style="color:var(--sb-success,#059669);font-size:16px">+41%</strong><br>
                        <span style="color:var(--sb-text-secondary,#64748b)">Expert quotes boost</span>
                    </div>
                    <div style="padding:8px 12px;background:#fff;border-radius:6px;border:1px solid var(--sb-border,#e2e8f0)">
                        <strong style="color:var(--sb-success,#059669);font-size:16px">+30%</strong><br>
                        <span style="color:var(--sb-text-secondary,#64748b)">Statistics boost</span>
                    </div>
                    <div style="padding:8px 12px;background:#fff;border-radius:6px;border:1px solid var(--sb-border,#e2e8f0)">
                        <strong style="color:var(--sb-success,#059669);font-size:16px">+28%</strong><br>
                        <span style="color:var(--sb-text-secondary,#64748b)">Inline citations boost</span>
                    </div>
                    <div style="padding:8px 12px;background:#fff;border-radius:6px;border:1px solid var(--sb-border,#e2e8f0)">
                        <strong style="color:var(--sb-error,#dc2626);font-size:16px">-8%</strong><br>
                        <span style="color:var(--sb-text-secondary,#64748b)">Keyword stuffing hurts</span>
                    </div>
                </div>
                <p style="margin:10px 0 0;font-size:11px;color:var(--sb-text-muted,#94a3b8)">Source: GEO Research, KDD 2024 — peer-reviewed study on Generative Engine Optimization</p>
            </div>
        </div>
    </div>

    <!-- Recent Articles -->
    <?php if ( ! empty( $generated_posts ) ) : ?>
    <div class="seobetter-card" style="margin-top:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h2 style="margin:0">Recent Articles</h2>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=seobetter-generate' ) ); ?>" class="button sb-btn-sm sb-btn-secondary">+ New Article</a>
        </div>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Article', 'seobetter' ); ?></th>
                    <th style="width:80px"><?php esc_html_e( 'Status', 'seobetter' ); ?></th>
                    <th style="width:100px"><?php esc_html_e( 'GEO Score', 'seobetter' ); ?></th>
                    <th style="width:100px"><?php esc_html_e( 'Date', 'seobetter' ); ?></th>
                    <th style="width:80px"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $generated_posts as $post ) :
                    $score_data = get_post_meta( $post->ID, '_seobetter_geo_score', true );
                    $score = $score_data['geo_score'] ?? '—';
                    $grade = $score_data['grade'] ?? '';
                    $score_class = is_numeric( $score ) ? ( $score >= 80 ? 'good' : ( $score >= 60 ? 'ok' : 'poor' ) ) : '';
                ?>
                <tr>
                    <td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><strong><?php echo esc_html( $post->post_title ); ?></strong></a></td>
                    <td><span style="font-size:12px"><?php echo esc_html( get_post_status_object( $post->post_status )->label ?? $post->post_status ); ?></span></td>
                    <td>
                        <?php if ( $score_class ) : ?>
                            <span class="seobetter-score seobetter-score-<?php echo esc_attr( $score_class ); ?>"><?php echo esc_html( $score ); ?></span>
                        <?php else : ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:var(--sb-text-secondary,#64748b)"><?php echo esc_html( get_the_date( 'M j', $post ) ); ?></td>
                    <td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" class="button sb-btn-sm">Edit</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>
