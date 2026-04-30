<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// v1.5.216.3 — Defensive init of $result. Legacy variable referenced at
// line ~926 (social content generator gate) — leftover from the v1.5.12
// cleanup that removed the synchronous POST handlers. PHP empty() is
// null-safe per spec but PHP 8.x logs an "Undefined variable" warning
// in some configurations. Initializing here silences the warning and
// keeps the legacy gate effectively disabled (stays falsy).
$result = $result ?? null;

// ============================================================================
// v1.5.12 — Legacy synchronous POST handlers removed.
//
// Previously this file had 4 synchronous handlers (seobetter_generate_article,
// seobetter_generate_outline, seobetter_reoptimize, seobetter_create_draft)
// that ran when the Generate button was submitted as a form. They rendered a
// minimal server-side result panel that bypassed the async UI spec in
// plugin_UX.md §3 (score ring, stat cards, bar charts, Pro upsell,
// Analyze & Improve panel, headline selector, Post/Page dropdown).
//
// All article generation now flows through the async REST path:
//   POST /seobetter/v1/generate/start → step → step → result
// rendered by renderResult() in content-generator.php JS.
//
// Article saves go through POST /seobetter/v1/save-draft (REST) which uses
// the standard X-WP-Nonce header — no more stale 'seobetter_draft_nonce'
// form nonces that triggered "The link you followed has expired" errors.
//
// See plugin_UX.md §3 for the full required result-panel spec.
// ============================================================================

$status = SEOBetter\Cloud_API::check_status();
$affiliates = [];  // kept for form field compatibility — async path passes its own

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
            <!-- onsubmit="return false" — belt-and-braces so Enter key on any input
                 cannot accidentally submit the form. Generation is exclusively async JS. -->
            <form method="post" onsubmit="return false">
                <?php wp_nonce_field( 'seobetter_generate_nonce' ); ?>

                <!-- v1.5.206d-fix5 — Country + Language moved to the TOP of the form.
                     These two fields affect every downstream behaviour: keyword
                     auto-suggest (topic-research uses them for regional Google Suggest
                     + language-specific Wikipedia + language-aware audience LLM),
                     system prompt regional context (Regional_Context::get_block),
                     schema inLanguage (Schema_Generator::get_in_language),
                     localized labels (Localized_Strings), and Layer 6 scoring
                     (GEO_Analyzer::check_international_signals). Previously buried
                     at the bottom of Article Settings, which caused users to click
                     Auto-Suggest before setting them — guaranteed English pipeline. -->
                <div class="sb-section">
                    <h3 class="sb-section-header"><span class="dashicons dashicons-admin-site-alt3"></span> Country &amp; Language <span style="font-size:11px;color:#6b7280;font-weight:400;margin-left:8px">— set these FIRST; every downstream step uses them</span></h3>

                    <!-- v1.5.45 — split Country and Language into two independent fields.
                         The old combined picker forced country→language coupling: picking
                         "Italy" made the article Italian. For travel bloggers writing in
                         English about Italian places, that was exactly wrong. Now the
                         country selector controls WHERE data comes from (Places API
                         waterfall + country-specific govt stats APIs) and the language
                         selector controls what LANGUAGE the article is written in. -->
                    <div class="sb-field-row">
                        <div class="sb-field" style="flex:1">
                            <label><strong>📍 Target Country</strong> <span style="color:#6b7280;font-weight:400;font-size:11px">— where your article&rsquo;s places &amp; data come from</span>
                                <?php if ( ! SEOBetter\License_Manager::can_use( 'country_localization_80' ) ) : ?>
                                    <span style="font-size:10px;color:#7c3aed;font-weight:600;margin-left:6px;padding:2px 6px;background:#f5f3ff;border-radius:8px">6 free · 80+ Pro+</span>
                                <?php endif; ?>
                                <span class="seobetter-tooltip"><span class="dashicons dashicons-info-outline"></span>
                                    <span class="seobetter-tooltip-text"><strong>What this does:</strong> Tells the plugin WHERE to find real places, businesses, and statistics for your article. Pick Italy if you&rsquo;re writing about gelaterie in Lucignano. Pick Japan if you&rsquo;re writing about ramen shops in Tokyo. This is how the Places waterfall (Perplexity Sonar → OpenStreetMap → Foursquare → HERE → Google Places) knows which country&rsquo;s data to search. <br><br><strong>This does NOT set the article language.</strong> You can pick Italy here and still write the article in English — use the Article Language dropdown next to this for that. Leave as &ldquo;Global&rdquo; only for topics that aren&rsquo;t tied to any specific country (like general how-to guides).</span>
                                </span>
                            </label>
                            <input type="hidden" name="country" id="sb-country-val" value="<?php echo esc_attr( $_POST['country'] ?? '' ); ?>" />
                            <div id="sb-country-picker" style="position:relative">
                                <div id="sb-country-selected" style="display:flex;align-items:center;gap:8px;height:40px;padding:0 12px;border:1px solid var(--sb-border,#d1d5db);border-radius:6px;cursor:pointer;background:#fff;font-size:13px" onclick="document.getElementById('sb-country-dropdown').style.display=document.getElementById('sb-country-dropdown').style.display==='block'?'none':'block';document.getElementById('sb-country-search').focus()">
                                    <span id="sb-country-flag" style="font-size:18px">🌐</span>
                                    <span id="sb-country-label" style="flex:1;color:#374151">Global (no country filter)</span>
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
                            // v1.5.216.30 — Phase 1 item 11: Free-tier country allowlist.
                            // Mirrors License_Manager::FREE_COUNTRIES — keep in sync.
                            // Non-free countries get a 🔒 lock badge for Free users with
                            // an inline upsell on click. Pro+/Agency see all 80+ unlocked.
                            var sbFreeCountries = ['','US','GB','AU','CA','NZ','IE'];
                            var sbCanUseAllCountries = <?php echo SEOBetter\License_Manager::can_use( 'country_localization_80' ) ? 'true' : 'false'; ?>;

                            function sbCountryLocked(c) {
                                if (sbCanUseAllCountries) return false;
                                return sbFreeCountries.indexOf(c.c) === -1;
                            }
                            function sbRenderCountries(filter) {
                                var list = document.getElementById('sb-country-list');
                                var q = (filter||'').toLowerCase();
                                var html = '';
                                sbCountries.forEach(function(c) {
                                    var searchStr = (c.n+' '+c.ln+' '+c.l+' '+c.c).toLowerCase();
                                    if (q && searchStr.indexOf(q) === -1) return;
                                    var locked = sbCountryLocked(c);
                                    var clickAttr = locked
                                        ? 'onclick="sbCountryUpsell(\''+c.n.replace(/'/g,"\\'")+'\')"'
                                        : 'onclick="sbSelectCountry(\''+c.v+'\',\''+c.c+'\',\''+c.f+'\',\''+c.n.replace(/'/g,"\\'")+'\',\''+c.l+'\',\''+c.ln.replace(/'/g,"\\'")+'\')"';
                                    html += '<div class="sb-country-item" style="display:flex;align-items:center;gap:10px;padding:8px 12px;cursor:pointer;font-size:13px;'+(locked?'opacity:0.55;':'')+'" onmouseenter="this.style.background=\'#f1f5f9\'" onmouseleave="this.style.background=\'#fff\'" '+clickAttr+'>';
                                    html += '<span style="font-size:20px;width:28px;text-align:center">'+c.f+'</span>';
                                    html += '<span style="flex:1;color:#1e293b">'+c.n;
                                    if (locked) html += ' <span style="font-size:11px;color:#7c3aed;font-weight:600;margin-left:4px">🔒 Pro+</span>';
                                    html += '</span>';
                                    html += '<span style="font-size:11px;color:#64748b;background:#f1f5f9;padding:2px 8px;border-radius:4px">'+c.ln+'</span>';
                                    html += '</div>';
                                });
                                list.innerHTML = html || '<div style="padding:16px;text-align:center;color:#9ca3af;font-size:13px">No countries found</div>';
                            }
                            function sbFilterCountries(q) { sbRenderCountries(q); }
                            // v1.5.45 — sbSelectCountry only sets country now. Language is
                            // a separate independent dropdown below. This lets a US/UK/AU
                            // blogger write in English about Italian gelaterie — pick
                            // "Italy" for country, keep language as English.
                            function sbSelectCountry(v, c, f, n, l, ln) {
                                document.getElementById('sb-country-val').value = c;
                                document.getElementById('sb-country-flag').textContent = f;
                                document.getElementById('sb-country-label').textContent = n + (c === '' ? ' (no country filter)' : '');
                                document.getElementById('sb-country-dropdown').style.display = 'none';
                                document.getElementById('sb-country-search').value = '';
                            }
                            // v1.5.216.30 — Pro+ upsell when Free user clicks a locked country.
                            // Doesn't change the picker selection; just tells them what they need.
                            function sbCountryUpsell(name) {
                                if (confirm(name + ' requires Pro+ ($69/mo). Free tier supports US, GB, AU, CA, NZ, IE.\n\nClick OK to view pricing.')) {
                                    window.open('<?php echo esc_url( admin_url( 'admin.php?page=seobetter-settings' ) ); ?>', '_blank');
                                }
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
                                sbCountries.forEach(function(c) { if (c.c === saved) sbSelectCountry(c.v,c.c,c.f,c.n,c.l,c.ln); });
                            })();
                            <?php endif; ?>
                            </script>
                        </div>
                        <div class="sb-field" style="flex:1">
                            <label><strong>🗣 Article Language</strong> <span style="color:#6b7280;font-weight:400;font-size:11px">— what language the article is written in</span>
                                <span class="seobetter-tooltip"><span class="dashicons dashicons-info-outline"></span>
                                    <span class="seobetter-tooltip-text"><strong>What this does:</strong> Tells the AI which language to WRITE the article in. Headings, paragraphs, lists, FAQ answers — all rendered in this language. <br><br><strong>This is completely separate from Target Country.</strong> If you&rsquo;re a US blogger writing for an English-speaking audience about Italian gelaterie, keep this on English and set Target Country to Italy. The plugin will pull real data from Italy but write the article in English. Default is English because that&rsquo;s the most common audience language.</span>
                                </span>
                            </label>
                            <?php $sb_lang = $_POST['language'] ?? 'en'; ?>
                            <select name="language" id="sb-lang-val" style="height:40px;padding:0 12px;border:1px solid var(--sb-border,#d1d5db);border-radius:6px;background:#fff;font-size:13px;width:100%">
                                <option value="en" <?php selected( $sb_lang, 'en' ); ?>>🇬🇧 English</option>
                                <option value="es" <?php selected( $sb_lang, 'es' ); ?>>🇪🇸 Español (Spanish)</option>
                                <option value="fr" <?php selected( $sb_lang, 'fr' ); ?>>🇫🇷 Français (French)</option>
                                <option value="de" <?php selected( $sb_lang, 'de' ); ?>>🇩🇪 Deutsch (German)</option>
                                <option value="it" <?php selected( $sb_lang, 'it' ); ?>>🇮🇹 Italiano (Italian)</option>
                                <option value="pt" <?php selected( $sb_lang, 'pt' ); ?>>🇵🇹 Português (Portuguese)</option>
                                <option value="nl" <?php selected( $sb_lang, 'nl' ); ?>>🇳🇱 Nederlands (Dutch)</option>
                                <option value="sv" <?php selected( $sb_lang, 'sv' ); ?>>🇸🇪 Svenska (Swedish)</option>
                                <option value="no" <?php selected( $sb_lang, 'no' ); ?>>🇳🇴 Norsk (Norwegian)</option>
                                <option value="da" <?php selected( $sb_lang, 'da' ); ?>>🇩🇰 Dansk (Danish)</option>
                                <option value="fi" <?php selected( $sb_lang, 'fi' ); ?>>🇫🇮 Suomi (Finnish)</option>
                                <option value="pl" <?php selected( $sb_lang, 'pl' ); ?>>🇵🇱 Polski (Polish)</option>
                                <option value="cs" <?php selected( $sb_lang, 'cs' ); ?>>🇨🇿 Čeština (Czech)</option>
                                <option value="hu" <?php selected( $sb_lang, 'hu' ); ?>>🇭🇺 Magyar (Hungarian)</option>
                                <option value="ro" <?php selected( $sb_lang, 'ro' ); ?>>🇷🇴 Română (Romanian)</option>
                                <option value="el" <?php selected( $sb_lang, 'el' ); ?>>🇬🇷 Ελληνικά (Greek)</option>
                                <option value="tr" <?php selected( $sb_lang, 'tr' ); ?>>🇹🇷 Türkçe (Turkish)</option>
                                <option value="ru" <?php selected( $sb_lang, 'ru' ); ?>>🇷🇺 Русский (Russian)</option>
                                <option value="uk" <?php selected( $sb_lang, 'uk' ); ?>>🇺🇦 Українська (Ukrainian)</option>
                                <option value="ja" <?php selected( $sb_lang, 'ja' ); ?>>🇯🇵 日本語 (Japanese)</option>
                                <option value="ko" <?php selected( $sb_lang, 'ko' ); ?>>🇰🇷 한국어 (Korean)</option>
                                <option value="zh" <?php selected( $sb_lang, 'zh' ); ?>>🇨🇳 中文 (Chinese)</option>
                                <option value="ar" <?php selected( $sb_lang, 'ar' ); ?>>🇸🇦 العربية (Arabic)</option>
                                <option value="he" <?php selected( $sb_lang, 'he' ); ?>>🇮🇱 עברית (Hebrew)</option>
                                <option value="hi" <?php selected( $sb_lang, 'hi' ); ?>>🇮🇳 हिन्दी (Hindi)</option>
                                <option value="th" <?php selected( $sb_lang, 'th' ); ?>>🇹🇭 ไทย (Thai)</option>
                                <option value="vi" <?php selected( $sb_lang, 'vi' ); ?>>🇻🇳 Tiếng Việt (Vietnamese)</option>
                                <option value="id" <?php selected( $sb_lang, 'id' ); ?>>🇮🇩 Bahasa Indonesia</option>
                                <option value="ms" <?php selected( $sb_lang, 'ms' ); ?>>🇲🇾 Bahasa Melayu</option>
                            </select>
                        </div>
                    </div>

                    <p class="sb-help" style="margin:-8px 0 16px 0;padding:10px 12px;background:#eff6ff;border-left:3px solid #3b82f6;border-radius:4px;color:#1e3a5f;font-size:12px">
                        <strong>💡 Tip — these are two separate settings:</strong><br>
                        <strong>Target Country</strong> tells the plugin <em>where to look up real local businesses</em> (shops, restaurants, vets, hotels, etc). Pick the country your keyword is about.<br>
                        <strong>Article Language</strong> is the language your readers will read. These are independent — you can write an English article about Japanese restaurants by setting Country = Japan and Language = English.
                    </p>
                </div>

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
                                    <span class="seobetter-tooltip-text"><strong>What this does:</strong> Extra keyword phrases the AI will weave into headings and body text so your article ranks for multiple related search terms, not just the primary keyword. Comma-separated. Leave empty to let the AI auto-pick from research data.</span>
                                </span>
                            </label>
                            <input type="text" name="secondary_keywords" value="<?php echo esc_attr( $_POST['secondary_keywords'] ?? '' ); ?>" placeholder="horse vet supplies, equine medical supplies" />
                            <div class="sb-help">Comma-separated</div>
                        </div>
                        <div class="sb-field">
                            <label>LSI / Semantic Keywords
                                <span class="seobetter-tooltip"><span class="dashicons dashicons-info-outline"></span>
                                    <span class="seobetter-tooltip-text"><strong>What this does:</strong> Semantically-related single words and short phrases that AI search engines (ChatGPT, Perplexity, Google AI Overviews, Gemini) expect to see alongside your keyword. Helps your article get cited by AI answers. Comma-separated. Leave empty and the Auto-suggest button will fill it for you.</span>
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
                                    <span class="seobetter-tooltip-text"><strong>What this does:</strong> Tells the AI what shape of article to write. A Listicle produces numbered business/product picks. A How-To produces step-by-step instructions. A Review produces a hands-on evaluation. Each type changes the section structure, tone, and schema markup automatically. Pick the one that matches what you&rsquo;re writing.</span>
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
                                    <span class="seobetter-tooltip-text"><strong>What this does:</strong> How long the finished article will be. 2,000 words is the sweet spot for AI search citations (ChatGPT, Perplexity, Google AI Overviews tend to cite longer authoritative pieces). Shorter (800-1,000) works for product pages and buying guides. Longer (3,000+) is for ultimate-guide content.</span>
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
                            <div id="sb-wc-hint" class="sb-help" style="display:none;margin-top:4px;font-size:11px;color:#6b7280"></div>
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
                        <?php // v1.5.216.25 — Brand Voice picker (Phase 1 item 6). Tier-gated; Free sees upsell hint. ?>
                        <div class="sb-field">
                            <label>Brand Voice
                                <span class="seobetter-tooltip"><span class="dashicons dashicons-info-outline"></span>
                                    <span class="seobetter-tooltip-text"><strong>What this does:</strong> Injects a saved sample of your existing writing + tone directives + banned phrases into the AI prompt so the article sounds like you, not like ChatGPT. Manage profiles in Settings → Brand Voice.</span>
                                </span>
                            </label>
                            <?php
                            $sb_voices    = class_exists( '\\SEOBetter\\Brand_Voice_Manager' ) ? \SEOBetter\Brand_Voice_Manager::all() : [];
                            $sb_voice_cap = class_exists( '\\SEOBetter\\Brand_Voice_Manager' ) ? \SEOBetter\Brand_Voice_Manager::tier_cap() : 0;
                            $sb_voice_sel = (string) ( $_POST['brand_voice_id'] ?? '' );
                            ?>
                            <select name="brand_voice_id" <?php echo $sb_voice_cap === 0 ? 'disabled' : ''; ?>>
                                <option value="">— None (default) —</option>
                                <?php foreach ( $sb_voices as $vid => $voice ) : ?>
                                    <option value="<?php echo esc_attr( $vid ); ?>" <?php selected( $sb_voice_sel, $vid ); ?>><?php echo esc_html( $voice['name'] ?? $vid ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ( $sb_voice_cap === 0 ) : ?>
                                <div class="sb-help" style="margin-top:4px;font-size:11px;color:#6b7280">
                                    🔒 Brand Voice profiles require Pro. <a href="<?php echo esc_url( admin_url( 'admin.php?page=seobetter-settings' ) ); ?>">Upgrade →</a>
                                </div>
                            <?php elseif ( empty( $sb_voices ) ) : ?>
                                <div class="sb-help" style="margin-top:4px;font-size:11px;color:#6b7280">
                                    No voices yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=seobetter-settings' ) ); ?>">Create one in Settings →</a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="sb-field">
                            <label>Category <span style="color:#ef4444">*</span>
                                <span class="seobetter-tooltip"><span class="dashicons dashicons-info-outline"></span>
                                    <span class="seobetter-tooltip-text"><strong>What this does:</strong> Picks which free public data sources the plugin pulls real-time statistics from while writing your article. For example, &ldquo;Food &amp; Drink&rdquo; pulls from OpenFoodFacts and recipe databases, &ldquo;Finance&rdquo; pulls from economic data APIs, &ldquo;Travel &amp; Tourism&rdquo; pulls from destination info sources. This is ONLY about supporting stats woven into the article prose — it does NOT affect where the plugin looks for places or businesses (that&rsquo;s controlled by Target Country at the top of the form). Pick the category that best matches your article topic.</span>
                                </span>
                            </label>
                            <select name="domain" required>
                                <?php // v1.5.15 — keep this list IDENTICAL to bulk-generator.php and content-brief.php. See plugin_UX.md §9. ?>
                                <option value="" disabled <?php selected( $_POST['domain'] ?? '', '' ); ?>>Select category...</option>
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

                <!-- Generate Button —
                     type="button" (NOT submit) so the form can NEVER submit via Enter
                     key or any fallback path. Generation is handled EXCLUSIVELY by the
                     async JS handler at #seobetter-async-generate. If JS fails, the
                     button does nothing — it never falls back to legacy sync PHP. -->
                <div style="display:flex;gap:12px;margin-bottom:24px">
                    <button type="button" id="seobetter-async-generate" class="button sb-btn-primary" style="font-size:15px;padding:12px 32px;height:50px">
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

            </form>

            <!-- Result container for AJAX results (kept OUTSIDE the form so its
                 Save Draft button cannot bubble a click into a form submission) -->
            <div id="seobetter-async-result" style="display:none"></div>
        </div>

        <!-- ===== RIGHT: Sidebar ===== -->
        <div class="sb-generator-sidebar">

            <!-- v1.5.214 — Pre-generation contextual hints panel.
                 Reads form state via inline JS and shows what the plugin WILL do
                 based on current selections. Educates the user about features
                 they're unlocking + surfaces Pro-only paths contextually
                 (highest-converting per ProductLed PLG benchmarks). -->
            <div class="sb-sidebar-card" id="sb-context-hints" data-is-pro="<?php echo $is_pro ? '1' : '0'; ?>">
                <h3><span class="dashicons dashicons-info" style="color:var(--sb-primary);margin-right:4px;font-size:14px;width:14px;height:14px"></span> What this article will get</h3>
                <ul id="sb-context-hints-list" style="font-size:12px;line-height:1.7;margin:0;padding:0;list-style:none">
                    <li style="color:var(--sb-text-muted);font-style:italic;padding:6px 0">
                        Pick a content type, country &amp; language to see what schema, research depth, and language guards will run.
                    </li>
                </ul>
            </div>

            <!-- Topic Suggester (compact) — kept per v1.5.214 design review -->
            <div class="sb-sidebar-card">
                <h3><span class="dashicons dashicons-editor-help" style="color:var(--sb-info);margin-right:4px;font-size:14px;width:14px;height:14px"></span> Need Ideas?</h3>
                <div class="sb-field" style="margin-bottom:8px">
                    <input type="text" id="sb-niche-input" placeholder="Your niche (e.g. equine health)" style="height:36px;font-size:12px" />
                </div>
                <button type="button" id="sb-suggest-btn" class="button sb-btn-sm" style="width:100%" onclick="sbSuggestTopics(this)">Suggest 10 Topics</button>
                <span id="sb-topics-status" style="display:block;font-size:11px;color:var(--sb-text-muted);margin-top:6px"></span>
                <div id="sb-topics-list" style="display:none;margin-top:10px;font-size:12px;line-height:1.8"></div>
            </div>

            <!-- v1.5.214 — Pro upsell card (free tier only). Contextual copy
                 references real Pro features that compound on top of the free
                 generation flow, not generic "more features". -->
            <?php if ( ! $is_pro ) : ?>
            <div class="sb-upsell-card">
                <h3 style="display:flex;align-items:center;gap:6px">
                    <span style="background:var(--sb-primary,#764ba2);color:#fff;font-size:10px;padding:2px 6px;border-radius:3px;letter-spacing:0.05em">PRO</span>
                    Push this article further
                </h3>
                <ul style="font-size:12px;line-height:1.7;margin:0 0 12px 0;padding:0 0 0 16px;color:var(--sb-text)">
                    <li><strong>Firecrawl deep research</strong> — 10× citation density vs Jina fallback</li>
                    <li><strong>All 21 content types</strong> — incl. Comparison, Buying Guide, Case Study, Recipe</li>
                    <li><strong>AI featured image via Nano Banana</strong> — Pollinations free / OpenRouter / Gemini direct</li>
                    <li><strong>Analyze &amp; Improve</strong> inject buttons — one-click +5-10 GEO points</li>
                    <li><strong>5 Schema Blocks</strong> — Product, Event, LocalBusiness, Vacation Rental, Job Posting</li>
                    <li><strong>50 Cloud articles/mo</strong> on Sonnet-tier LLM (vs 5 on Free)</li>
                </ul>
                <a href="https://seobetter.com/pricing" target="_blank" class="button sb-btn-primary" style="font-size:13px;height:38px;line-height:20px;width:100%;text-align:center">$39/mo — See Pro plans →</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- v1.5.214 — Pre-generation hints script. Reads form state and renders
         contextual hints into #sb-context-hints-list. No backend calls — pure
         client-side computation from the form's existing field values. -->
    <script>
    (function() {
        var hintsRoot = document.getElementById('sb-context-hints');
        if (!hintsRoot) return;
        var listEl = document.getElementById('sb-context-hints-list');
        var isPro = hintsRoot.getAttribute('data-is-pro') === '1';

        // Map of content types to schema bundle (matches Schema_Generator::CONTENT_TYPE_MAP)
        var schemaMap = {
            'blog_post':         { primary: 'BlogPosting',          extras: ['BreadcrumbList','Organization','Person','FAQPage (auto)']},
            'how_to':            { primary: 'Article',              extras: ['BreadcrumbList','Organization','Person','Speakable','FAQPage (auto)']},
            'listicle':          { primary: 'Article + ItemList',   extras: ['BreadcrumbList','Organization','Person']},
            'review':            { primary: 'Review',               extras: ['itemReviewed (auto)','positiveNotes/negativeNotes','BreadcrumbList','Organization','Person']},
            'comparison':        { primary: 'Article',               extras: ['BreadcrumbList','Organization','Person','Product[] (auto)']},
            'buying_guide':      { primary: 'Article + ItemList',   extras: ['Product[]','BreadcrumbList','Organization','Person']},
            'recipe':            { primary: 'Recipe[] + Article wrapper', extras: ['Speakable','BreadcrumbList','Organization','Person','recipeCuisine (country-mapped)']},
            'faq_page':          { primary: 'FAQPage',              extras: ['Speakable','BreadcrumbList','Organization','Person']},
            'news_article':      { primary: 'NewsArticle',          extras: ['Speakable','BreadcrumbList','Organization','Person']},
            'opinion':           { primary: 'OpinionNewsArticle',   extras: ['Speakable','citation[]','backstory','BreadcrumbList','Organization','Person']},
            'tech_article':      { primary: 'TechArticle',          extras: ['citation[]','SoftwareApplication (auto)','BreadcrumbList','Organization','Person']},
            'white_paper':       { primary: 'Article',              extras: ['Dataset (auto)','citation[]','BreadcrumbList','Organization','Person']},
            'scholarly_article': { primary: 'ScholarlyArticle',     extras: ['citation[]','Dataset (auto)','BreadcrumbList','Organization','Person']},
            'live_blog':         { primary: 'LiveBlogPosting',      extras: ['BreadcrumbList','Organization','Person']},
            'press_release':     { primary: 'NewsArticle',          extras: ['Speakable','citation[]','BreadcrumbList','Organization (enriched)','Person']},
            'personal_essay':    { primary: 'BlogPosting',          extras: ['Speakable','backstory','BreadcrumbList','Organization','Person','ProfilePage (auto)']},
            'glossary':          { primary: 'DefinedTerm',          extras: ['BreadcrumbList','Organization','Person']},
            'sponsored':         { primary: 'BlogPosting',          extras: ['sponsor org','backstory','citation[]','BreadcrumbList','Organization','Person']},
            'case_study':        { primary: 'Article',              extras: ['Organization (client)','citation[]','BreadcrumbList','Organization','Person']},
            'interview':         { primary: 'Article + ProfilePage',extras: ['QAPage (auto)','citation[]','Speakable','BreadcrumbList','Organization','Person']},
            'pillar_guide':      { primary: 'Article + ItemList',   extras: ['Speakable','citation[]','BreadcrumbList','Organization','Person']}
        };

        // Pro-only content types (per pro-plan-pricing.md §2)
        var freeTypes = ['blog_post', 'how_to', 'listicle'];

        function render() {
            var ct = (document.querySelector('[name="content_type"]') || {}).value || 'blog_post';
            var country = (document.querySelector('[name="country"]') || {}).value || '';
            var lang = (document.querySelector('[name="language"]') || {}).value || 'en';
            var keyword = ((document.querySelector('[name="keyword"]') || {}).value || '').trim();

            var hints = [];
            var bundle = schemaMap[ct] || schemaMap['blog_post'];

            // Hint 1: Schema bundle (always shown)
            hints.push('<li style="padding:6px 0;border-bottom:1px solid #f1f5f9">' +
                '<strong style="color:#0f172a">Schema:</strong> ' + bundle.primary +
                ' <span style="color:#64748b">+ ' + bundle.extras.slice(0, 3).join(', ') + '</span></li>');

            // Hint 2: Pro content-type lock (if free user picks a Pro type)
            if (!isPro && freeTypes.indexOf(ct) === -1) {
                hints.push('<li style="padding:6px 0;border-bottom:1px solid #f1f5f9;background:linear-gradient(90deg,#faf5ff 0%,transparent 100%);margin:0 -8px;padding:6px 8px">' +
                    '<strong style="color:#7c3aed">🔒 Pro:</strong> "' + ct.replace(/_/g, ' ') + '" requires Pro. ' +
                    '<a href="https://seobetter.com/pricing" target="_blank" style="color:#7c3aed;text-decoration:underline">Unlock all 21 types →</a></li>');
            }

            // v1.5.216.6 — Auto-translate hint for ANY non-English language.
            // Pre-fix: only fired for non-Latin languages (ja/ko/ru/etc). French
            // user reported "best ramen shops in montreal 2026 + lang=fr"
            // returned English secondary/LSI keywords because the trigger
            // skipped Latin-target languages. Now: show the hint whenever
            // language is non-English and the keyword looks predominantly
            // English (Latin alphabet). The actual translator (server-side)
            // also covers all non-English now per v1.5.216.6.
            var keywordIsLatin = /^[\x00-\x7F]+$/.test(keyword) && /[A-Za-z]/.test(keyword);
            if (lang !== 'en' && keyword && keywordIsLatin) {
                var langLabel = lang.toUpperCase();
                hints.push('<li style="padding:6px 0;border-bottom:1px solid #f1f5f9">' +
                    '<strong style="color:#0f172a">🌐 Auto-translate:</strong> Your keyword will be translated to ' + langLabel + ' so research + headings + meta tags come back in ' + langLabel + ' (covers all 60+ supported languages).</li>');
            }

            // Hint 4: Country-specific recipeCuisine (if Recipe + supported country)
            if (ct === 'recipe' && country) {
                var cuisineMap = {AU:'Australian',US:'American',GB:'British',FR:'French',IT:'Italian',JP:'Japanese',IN:'Indian',MX:'Mexican',TH:'Thai',CN:'Chinese',KR:'Korean',ES:'Spanish',DE:'German',BR:'Brazilian',GR:'Greek',TR:'Turkish',VN:'Vietnamese',IE:'Irish',NZ:'New Zealand'};
                if (cuisineMap[country]) {
                    hints.push('<li style="padding:6px 0;border-bottom:1px solid #f1f5f9">' +
                        '<strong style="color:#0f172a">🍳 Recipe cuisine:</strong> Schema will mark recipeCuisine = ' + cuisineMap[country] + '.</li>');
                }
            }

            // Hint 5: Free vs Pro research depth
            if (!isPro) {
                hints.push('<li style="padding:6px 0">' +
                    '<strong style="color:#64748b">📡 Research:</strong> Free uses Jina Reader fallback (basic). ' +
                    '<a href="https://seobetter.com/pricing" target="_blank" style="color:#7c3aed">Pro adds Firecrawl deep research →</a></li>');
            } else {
                hints.push('<li style="padding:6px 0"><strong style="color:#10b981">✓ Pro research stack active:</strong> Firecrawl + Serper + Sonar Pro</li>');
            }

            // If nothing matched (no form values yet), show placeholder
            if (hints.length === 0) {
                hints.push('<li style="color:#94a3b8;font-style:italic;padding:6px 0">Pick a content type, country &amp; language to see what schema, research depth, and language guards will run.</li>');
            }

            listEl.innerHTML = hints.join('');
        }

        // Attach change listeners to relevant form fields
        ['content_type','country','language','keyword'].forEach(function(name) {
            var el = document.querySelector('[name="' + name + '"]');
            if (el) {
                el.addEventListener('change', render);
                el.addEventListener('input', render);
            }
        });

        // Initial render after a tick (gives selects time to populate)
        setTimeout(render, 100);
    })();
    </script>

    <!-- ===== RESULTS — rendered by async JS into #seobetter-async-result ===== -->
    <!-- Legacy server-side $result rendering was REMOVED in v1.5.12.
         All generation now flows through the async REST path:
           /seobetter/v1/generate/start → step → step → result
         → renderResult() JS writes the full dashboard (score ring, stat cards,
           14 bar charts, Pro upsell, Analyze & Improve, headline selector,
           Save Draft with Post/Page dropdown) into #seobetter-async-result.
         See plugin_UX.md §3 for the required UI elements. -->

</div>

<!-- ===== JAVASCRIPT ===== -->
<script>
console.log('[SEOBetter] content-generator.php script loaded — v1.5.3');
var CLOUD = '<?php echo esc_js( $cloud_url ); ?>';
var SITE  = '<?php echo esc_js( $home ); ?>';

// ===== Topic suggester (defined FIRST so nothing below can prevent it loading) =====
function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}
window.sbSuggestTopics = function(btn) {
    var nicheEl = document.getElementById('sb-niche-input');
    var niche = nicheEl ? nicheEl.value.trim() : '';
    if(!niche){alert('Enter your niche.');return;}
    var st=document.getElementById('sb-topics-status');
    btn.disabled=true; if(st) st.textContent='Researching real search demand...';
    var sbCountryEl2 = document.querySelector('[name="country"]') || document.getElementById('sb-country-val');
    var sbCountry2 = sbCountryEl2 ? (sbCountryEl2.value || '').toUpperCase() : '';
    // v1.5.211 — route through WP REST so PHP signs the HMAC request.
    // Browser JS can't sign (signing secret lives in PHP source).
    fetch('<?php echo esc_js( rest_url( 'seobetter/v1/topic-research' ) ); ?>', {
        method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':'<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'},
        body: JSON.stringify({ niche: niche, country: sbCountry2 })
    }).then(function(r){return r.json();}).then(function(d) {
        btn.disabled=false;
        if (d && d.success && d.topics && d.topics.length) {
            var genUrl='<?php echo esc_js( admin_url('admin.php?page=seobetter-generate') ); ?>';
            var intentColors = { 'informational':'#3b82f6','commercial':'#22c55e','transactional':'#f59e0b' };
            var html = d.topics.map(function(t) {
                var color = intentColors[t.intent] || '#6b7280';
                return '<div style="padding:8px 0;border-bottom:1px solid #e5e7eb">' +
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
                        '<a href="' + genUrl + '&keyword=' + encodeURIComponent(t.topic) + '" style="font-size:11px;white-space:nowrap;flex-shrink:0">Use &rarr;</a>' +
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
            var listEl = document.getElementById('sb-topics-list');
            if (listEl) { listEl.innerHTML = summary + html; listEl.style.display = 'block'; }
            if (st) st.textContent = d.topics.length + ' real-data topics';
        } else {
            if (st) st.textContent = (d && d.error) || 'No topics found. Try a different niche.';
        }
    }).catch(function(e) { btn.disabled=false; if(st) st.textContent='Error: ' + e.message; });
};

// Content type auto-adjust (tone + word count)
// v1.5.183 — Content type presets with recommended word count + minimum floor.
// Minimums based on GEO requirements: 2+ quotes, 5+ citations, 3+ stats/1000 words,
// Key Takeaways, FAQ, table. Below the minimum, articles get truncated or lack
// enough GEO elements for AI citation visibility.
var SB_TYPE_PRESETS = {
    blog_post:       {tone:'conversational', wc:'1500', min:1000, label:'Blog Post'},
    news_article:    {tone:'journalistic',   wc:'1000', min:800,  label:'News Article'},
    opinion:         {tone:'authoritative',  wc:'1500', min:1000, label:'Opinion'},
    how_to:          {tone:'educational',    wc:'2000', min:1000, label:'How-To Guide'},
    listicle:        {tone:'conversational', wc:'2000', min:1500, label:'Listicle'},
    review:          {tone:'authoritative',  wc:'1500', min:1000, label:'Review'},
    comparison:      {tone:'authoritative',  wc:'2000', min:1500, label:'Comparison'},
    buying_guide:    {tone:'authoritative',  wc:'2500', min:1500, label:'Buying Guide'},
    pillar_guide:    {tone:'authoritative',  wc:'3000', min:2000, label:'Pillar Guide'},
    case_study:      {tone:'professional',   wc:'2000', min:1500, label:'Case Study'},
    interview:       {tone:'conversational', wc:'1500', min:1000, label:'Interview'},
    faq_page:        {tone:'educational',    wc:'1500', min:1000, label:'FAQ Page'},
    recipe:          {tone:'conversational', wc:'1500', min:800,  label:'Recipe'},
    tech_article:    {tone:'educational',    wc:'2000', min:1500, label:'Tech Article'},
    white_paper:     {tone:'professional',   wc:'3000', min:2000, label:'White Paper'},
    scholarly_article:{tone:'professional',  wc:'3000', min:2000, label:'Scholarly Article'},
    live_blog:       {tone:'journalistic',   wc:'1000', min:800,  label:'Live Blog'},
    press_release:   {tone:'professional',   wc:'800',  min:500,  label:'Press Release'},
    personal_essay:  {tone:'conversational', wc:'1500', min:1000, label:'Personal Essay'},
    glossary_definition:{tone:'educational', wc:'1000', min:500,  label:'Glossary'},
    sponsored:       {tone:'conversational', wc:'1000', min:800,  label:'Sponsored'},
};
function sbContentTypeChanged(type) {
    var toneEl = document.querySelector('[name="tone"]');
    var wcEl = document.querySelector('[name="word_count"]');
    var wcHint = document.getElementById('sb-wc-hint');
    var p = SB_TYPE_PRESETS[type];
    if (p && toneEl && wcEl) {
        toneEl.value = p.tone;
        wcEl.value = p.wc;
        // Show recommended minimum hint
        if (wcHint) {
            wcHint.innerHTML = 'Recommended: <strong>' + parseInt(p.wc).toLocaleString() + '+ words</strong> for ' + p.label + '. Minimum: ' + parseInt(p.min).toLocaleString() + ' words.';
            wcHint.style.display = 'block';
        }
    }
    // Enforce minimum — if user manually changes word count below floor
    if (wcEl) {
        wcEl.addEventListener('change', function() {
            var pp = SB_TYPE_PRESETS[document.getElementById('sb-content-type').value];
            if (pp && parseInt(this.value) < pp.min) {
                // Find the next valid option at or above minimum
                var opts = this.options;
                for (var i = 0; i < opts.length; i++) {
                    if (parseInt(opts[i].value) >= pp.min) {
                        this.value = opts[i].value;
                        break;
                    }
                }
                if (wcHint) {
                    wcHint.innerHTML = '&#9888; ' + pp.label + ' requires at least ' + pp.min.toLocaleString() + ' words for proper GEO optimization. Auto-adjusted.';
                    wcHint.style.color = '#f59e0b';
                    setTimeout(function() { wcHint.style.color = ''; }, 4000);
                }
            }
        });
    }
}

// Auto-suggest keywords (v1.5.22 — real data from /api/topic-research)
//
// Before v1.5.22 this button called /api/generate (LLM) with a strict-format
// prompt and a fragile regex parser that silently failed when Llama wrapped
// its output in markdown. Now it calls /api/topic-research which pulls REAL
// keyword variations from Google Suggest + Datamuse + Wikipedia and returns
// pre-extracted secondary + lsi arrays.
var sbAutoBtn = document.getElementById('seobetter-auto-keywords');
if (sbAutoBtn) sbAutoBtn.addEventListener('click', function() {
    var kw = document.getElementById('primary_keyword').value.trim();
    if (!kw) { alert('Enter a keyword first.'); return; }
    var btn = this, st = document.getElementById('seobetter-auto-status');
    btn.disabled = true; st.textContent = 'Fetching real-data keywords...';
    // v1.5.57 — pass the selected country code so Google Suggest returns
    // region-appropriate completions. Without this, "pet shops" returns
    // US-centric suggestions like "pet shops washington" even when the
    // user has Australia selected.
    var sbCountryEl = document.querySelector('[name="country"]') || document.getElementById('sb-country-val');
    var sbCountry = sbCountryEl ? (sbCountryEl.value || '').toUpperCase() : '';
    // v1.5.206d-fix2 — send language so backend can:
    // 1) skip Datamuse (English-only) for non-English queries
    // 2) query the right Wikipedia subdomain (ru/ja/ko/de/etc.)
    // 3) ask the LLM audience inference to respond in the target language
    var sbLangEl = document.querySelector('[name="language"]');
    var sbLang = sbLangEl ? (sbLangEl.value || 'en') : 'en';
    // v1.5.211 — route through WP REST so PHP signs the HMAC request.
    fetch('<?php echo esc_js( rest_url( 'seobetter/v1/topic-research' ) ); ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' },
        body: JSON.stringify({ niche: kw, country: sbCountry, language: sbLang })
    }).then(function(r) { return r.json(); }).then(function(d) {
        btn.disabled = false;
        if (!d || !d.success) {
            st.textContent = (d && d.error) || 'Failed to fetch suggestions';
            return;
        }
        var sec = (d.keywords && d.keywords.secondary) || [];
        var lsi = (d.keywords && d.keywords.lsi) || [];
        if (sec.length === 0 && lsi.length === 0) {
            // v1.5.25 — friendlier message for ultra-long-tail keywords. Google Suggest +
            // Datamuse return zero variations for highly specific 8+ word phrases (e.g. small
            // city + business type + year). That's normal; the user can safely leave the
            // Secondary/LSI fields empty and the AI will pull keyword variations from the
            // research pool during generation.
            st.innerHTML = '<span style="color:#1e40af;font-style:normal">&#8505;&#65039; No auto-suggestions for this long-tail keyword (that\'s normal for very specific phrases). You can safely leave Secondary Keywords empty — the AI will generate variations from the research pool.</span>';
            return;
        }
        // v1.5.206d-fix4 — ALWAYS overwrite Secondary + LSI fields on
        // auto-suggest click (including clearing when server returns 0 items).
        // Previously stale values from $_POST or a previous test persisted
        // when the server returned fewer suggestions — e.g. LSI field kept
        // "equine wound care, horse first aid" from an earlier RSPCA test
        // when a fresh Korean query returned zero LSI. Same bug pattern the
        // audience field had in v1.5.193.
        var secField = document.querySelector('[name="secondary_keywords"]');
        if (secField) secField.value = sec.join(', ');
        var lsiField = document.querySelector('[name="lsi_keywords"]');
        if (lsiField) lsiField.value = lsi.join(', ');
        // v1.5.193 — Auto-suggest ALWAYS overwrites the Target Audience and
        // Category fields when the user clicks the button. Previously we
        // guarded the audience field with !audField.value.trim() so the
        // button would not clobber a manual entry — but that rule also
        // refused to overwrite stale values left in the field by PHP from
        // an earlier $_POST (e.g. the user tested a healthcare keyword, the
        // audience got set, then they typed a completely different keyword
        // like "should university be free in australia" and the old
        // healthcare audience stayed). Clicking "Auto-suggest" is an
        // explicit request for fresh suggestions — the fact that the field
        // already has text should never prevent that.
        var aud = (d.keywords && d.keywords.audience) || '';
        var audField = document.querySelector('[name="audience"]');
        if (audField) {
            // Overwrite when LLM returned an audience; clear when it didn't
            // (so the user sees a blank field and knows to fill it manually
            // rather than being silently left with stale data).
            audField.value = aud;
        }
        // v1.5.193 — Same treatment for Category: ALWAYS overwrite when the
        // LLM returned a valid category value that exists in the dropdown.
        // Previously we only overwrote when the current value was empty or
        // equal to 'general'/'business', which also preserved stale $_POST
        // values across keyword changes.
        var cat = (d.keywords && d.keywords.category) || '';
        var catField = document.querySelector('[name="domain"]');
        if (catField && cat) {
            var optExists = catField.querySelector('option[value="' + cat + '"]');
            if (optExists) {
                catField.value = cat;
            }
        }
        var serperCount = (d.sources && d.sources.serper) || 0;
        var srcLabel = serperCount > 0
            ? serperCount + ' from Google SERP, ' + (d.sources ? d.sources.google_suggest : 0) + ' from Suggest'
            : (d.sources ? d.sources.google_suggest : 0) + ' from Google Suggest, ' + (d.sources ? d.sources.datamuse : 0) + ' from Datamuse';
        var autoFields = [];
        if (sec.length) autoFields.push(sec.length + ' secondary');
        if (lsi.length) autoFields.push(lsi.length + ' LSI');
        if (aud) autoFields.push('audience');
        if (cat) autoFields.push('category: ' + cat);
        st.textContent = 'Added ' + autoFields.join(' + ') + ' (' + srcLabel + ')';
        setTimeout(function() { st.textContent = ''; }, 8000);
    }).catch(function(e) {
        btn.disabled = false;
        st.textContent = 'Error: ' + (e && e.message ? e.message : 'network');
    });
});

<?php if ( ! empty( $result ) && ! empty( $result['success'] ) ) : ?>
// Social content generator
document.getElementById('sb-gen-social').addEventListener('click', function() {
    var btn=this, st=document.getElementById('sb-social-status');
    btn.disabled=true; st.textContent='Generating (20-30s)...';
    var txt = <?php echo wp_json_encode( substr( wp_strip_all_tags( $result['content'] ?? '' ), 0, 2000 ) ); ?>;
    // v1.5.211 — route through WP REST so PHP signs the HMAC request.
    fetch('<?php echo esc_js( rest_url( 'seobetter/v1/generate-proxy' ) ); ?>', {
        method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':'<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'},
        body: JSON.stringify({ prompt:'Create social content from this article about "'+btn.dataset.keyword+'":\n'+txt+'\n\n=== TWITTER THREAD ===\n5 tweets\n\n=== LINKEDIN POST ===\n150-300 words\n\n=== INSTAGRAM CAPTION ===\nHook + takeaways + hashtags', system_prompt:'Social media expert.', max_tokens:2000, temperature:0.7 })
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
        return fetch(url, opts).then(function(r) {
            // v1.5.188 — Catch non-JSON responses (PHP fatal errors return HTML)
            var contentType = r.headers.get('content-type') || '';
            if (!r.ok) {
                return r.text().then(function(t) {
                    console.error('SEOBetter API error (' + r.status + '):', t.substring(0, 300));
                    throw new Error('Server returned ' + r.status + ': ' + t.substring(0, 100));
                });
            }
            if (contentType.indexOf('application/json') === -1) {
                return r.text().then(function(t) {
                    console.error('SEOBetter API returned non-JSON:', t.substring(0, 300));
                    throw new Error('Server returned HTML instead of JSON — check PHP error log. Preview: ' + t.substring(0, 80));
                });
            }
            return r.json();
        });
    }

    // v1.5.185 — retry counter for 429/network errors
    var retryCount = 0;
    var maxRetries = 5;

    function processNext() {
        errorEl.style.display = 'none';
        // v1.5.185 — batch=3 so server processes up to 3 sections per call
        api('generate/step', 'POST', { job_id: jobId, batch: 3 }).then(function(res) {
            retryCount = 0; // Reset on success
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
            // v1.5.185 — Auto-retry on 429/network error with exponential backoff
            retryCount++;
            if (retryCount <= maxRetries) {
                var waitSeconds = Math.min(30, 3 * Math.pow(2, retryCount - 1)); // 3s, 6s, 12s, 24s, 30s
                label.textContent = 'Server busy — retrying in ' + waitSeconds + 's (attempt ' + retryCount + '/' + maxRetries + ')...';
                setTimeout(processNext, waitSeconds * 1000);
            } else {
                errorEl.style.display = 'block';
                errorMsg.textContent = 'Server rate limit reached after ' + maxRetries + ' retries. Wait 2 minutes and click Retry. If this keeps happening, your hosting may need upgrading to VPS.';
            }
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
            // v1.5.64 — cache the initial generation response so inject-fix
            // handlers can merge their updates on top of it instead of losing
            // headlines / meta / places_validator / citation_pool.
            window._seobetterLastResult = res;
            // v1.5.67 — reset the applied-fixes set on every fresh generation
            // so buttons start clickable on a new article.
            window._seobetterAppliedFixes = {};
            try { renderResult(res); } catch(renderErr) {
                console.error('SEOBetter renderResult crash:', renderErr);
                stopTimer();
                errorEl.style.display = 'block';
                errorMsg.textContent = 'Render error: ' + (renderErr.message || renderErr);
            }
        }).catch(function(err) {
            console.error('SEOBetter fetchResult error:', err);
            stopTimer();
            errorEl.style.display = 'block';
            errorMsg.textContent = 'Failed to load results: ' + (err.message || err);
        });
    }

    function esc(s) { var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

    // v1.5.69 — optional second param: if true, skip the scrollIntoView
    // after rendering. Used by inject-fix re-renders so the user stays
    // where they are instead of being yanked to the score dashboard.
    function renderResult(res, skipScroll) {
        var score = res.geo_score || 0;
        var sc = score >= 80 ? 'good' : (score >= 60 ? 'ok' : 'poor');
        var scoreColor = score >= 80 ? '#22c55e' : (score >= 60 ? '#f59e0b' : '#ef4444');
        var scoreBg = score >= 80 ? '#f0fdf4' : (score >= 60 ? '#fffbeb' : '#fef2f2');
        var scoreRing = score >= 80 ? '#dcfce7' : (score >= 60 ? '#fef3c7' : '#fee2e2');

        var h = '<div style="margin-top:16px">';

        // ===== SCORE DASHBOARD =====
        h += '<div style="display:grid;grid-template-columns:180px 1fr;gap:20px;padding:24px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:16px">';

        // Left: Score circle
        // v1.5.65 — redesigned score ring. Previous version had a 36px score
        // above a 12px grade letter which looked unbalanced ("78" big, "B"
        // tiny underneath). New design uses a larger ring (150px), a 48px
        // score in a strong serif-ish weight, a 16px grade pill with a
        // matching-color background, smooth cubic-bezier transitions on
        // the ring fill, and a hover glow. Applied everywhere the score
        // ring renders (content-generator.php, bulk-generator.php,
        // dashboard.php).
        var ringSize = 150;
        h += '<div class="sb-geo-ring-wrap" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px">';
        h += '<div class="sb-geo-ring" style="position:relative;width:'+ringSize+'px;height:'+ringSize+'px;transition:transform 0.4s cubic-bezier(0.34,1.56,0.64,1)">';
        h += '<svg viewBox="0 0 120 120" style="width:'+ringSize+'px;height:'+ringSize+'px;transform:rotate(-90deg);filter:drop-shadow(0 2px 8px '+scoreColor+'22)">';
        h += '<circle cx="60" cy="60" r="52" fill="none" stroke="'+scoreRing+'" stroke-width="10"/>';
        h += '<circle cx="60" cy="60" r="52" fill="none" stroke="'+scoreColor+'" stroke-width="10" stroke-linecap="round" stroke-dasharray="'+(326.7*score/100)+' 326.7" style="transition:stroke-dasharray 1.2s cubic-bezier(0.4,0,0.2,1);transform-origin:60px 60px"/>';
        h += '</svg>';
        h += '<div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px">';
        h += '<span class="sb-geo-ring-score" style="font-size:44px;font-weight:800;color:'+scoreColor+';line-height:1;letter-spacing:-0.02em;font-variant-numeric:tabular-nums">'+score+'</span>';
        h += '<span class="sb-geo-ring-grade" style="display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:22px;padding:0 8px;border-radius:11px;background:'+scoreColor+';color:#fff;font-size:13px;font-weight:800;letter-spacing:0.04em;box-shadow:0 1px 4px '+scoreColor+'55">'+esc(res.grade)+'</span>';
        h += '</div></div>';
        h += '<span style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.08em;margin-top:4px">GEO Score</span>';
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

        // Score breakdown bars — 14 checks in v1.5.11+ scoring rubric
        // Weights match includes/GEO_Analyzer.php::analyze() $weights array
        if (res.checks) {
            var barItems = [
                {label:'Keyword Density',w:10,s:res.checks.keyword_density},
                {label:'Readability',w:10,s:res.checks.readability},
                {label:'Citations',w:10,s:res.checks.citations},
                {label:'Statistics',w:10,s:res.checks.factual_density},
                {label:'Key Takeaways',w:8,s:res.checks.bluf_header},
                {label:'Section Openers',w:8,s:res.checks.section_openings},
                {label:'Island Test',w:8,s:res.checks.island_test},
                {label:'Expert Quotes',w:6,s:res.checks.expert_quotes},
                {label:'Entity Density',w:6,s:res.checks.entity_usage},
                {label:'Freshness',w:6,s:res.checks.freshness},
                {label:'CORE-EEAT',w:5,s:res.checks.core_eeat},
                {label:'Tables',w:5,s:res.checks.tables},
                {label:'Humanizer',w:4,s:res.checks.humanizer},
                {label:'Lists',w:4,s:res.checks.lists}
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

        // ===== v1.5.216.27 — Phase 1 item 8: Rich Results validation preview =====
        // Shows which Google rich-result lanes WILL fire for this content type
        // BEFORE save. Reuses the same schemaMap used for the pre-generation
        // hint so the preview is byte-consistent with pre-flight expectations.
        // After save, the post-save status line gets a "Test in Google Rich
        // Results Test" link wired through `r.rich_results_test_url`.
        var ctForRR = (document.querySelector('[name="content_type"]')||{}).value||'blog_post';
        var rrBundle = (typeof schemaMap !== 'undefined' && schemaMap[ctForRR]) ? schemaMap[ctForRR] : null;
        if (rrBundle) {
            // Map schema bundle → rich-result appearance lanes the user actually sees
            // in Google search. Mirrors the metabox Rich Results tab logic at
            // seobetter.php:5077+ but pre-save (predicted, not measured).
            var rrLanes = [];
            if (/Recipe/.test(rrBundle.primary)) rrLanes.push({icon:'🍳', label:'Recipe card', color:'#dc2626', why:'Recipe @type → eligible for Recipe rich result with prep time, ratings, image'});
            if (/FAQPage/.test(rrBundle.primary) || rrBundle.extras.indexOf('FAQPage (auto)') !== -1) rrLanes.push({icon:'❓', label:'FAQ dropdowns', color:'#7c3aed', why:'FAQPage @type → expandable Q&A blocks under your search result'});
            if (/Review/.test(rrBundle.primary)) rrLanes.push({icon:'⭐', label:'Review snippet', color:'#f59e0b', why:'Review @type → star rating + reviewer info displayed in search'});
            if (/HowTo/.test(rrBundle.primary)) rrLanes.push({icon:'📋', label:'How-To carousel', color:'#0891b2', why:'HowTo @type → numbered steps may show inline above results'});
            if (/NewsArticle/.test(rrBundle.primary)) rrLanes.push({icon:'📰', label:'Top Stories carousel', color:'#1d4ed8', why:'NewsArticle @type → eligible for Top Stories news carousel'});
            if (/LiveBlog/.test(rrBundle.primary)) rrLanes.push({icon:'📡', label:'Live blog updates', color:'#dc2626', why:'LiveBlogPosting @type → ongoing-event rich result'});
            if (/ScholarlyArticle/.test(rrBundle.primary)) rrLanes.push({icon:'🎓', label:'Scholarly article', color:'#7c2d12', why:'ScholarlyArticle @type → academic citation eligibility'});
            if (/ItemList/.test(rrBundle.primary)) rrLanes.push({icon:'📚', label:'Carousel (ItemList)', color:'#9333ea', why:'ItemList @type → numbered items may render as horizontal carousel'});
            if (rrBundle.extras.indexOf('Speakable') !== -1) rrLanes.push({icon:'🔊', label:'Voice search (Speakable)', color:'#059669', why:'Speakable property → eligible for Google Assistant voice answers'});
            if (rrBundle.extras.some(function(e){return /Product/.test(e)})) rrLanes.push({icon:'🛒', label:'Product listings', color:'#0d9488', why:'Product[] @type → price, availability, rating in product carousel'});
            if (rrBundle.extras.some(function(e){return /Dataset/.test(e)})) rrLanes.push({icon:'📊', label:'Dataset (Google Dataset Search)', color:'#1f2937', why:'Dataset @type → indexed in Google Dataset Search'});
            // Always-on for any article: BreadcrumbList renders trail in search result
            rrLanes.push({icon:'🍞', label:'Breadcrumb trail', color:'#6b7280', why:'BreadcrumbList @type → site hierarchy shown above title in search'});

            h += '<div style="margin-bottom:16px;padding:14px 16px;background:linear-gradient(135deg,#f0fdf4 0%,#ecfdf5 100%);border:1px solid #bbf7d0;border-radius:10px">';
            h += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">';
            h += '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>';
            h += '<div style="font-size:13px;font-weight:700;color:#064e3b">Rich Results Preview</div>';
            h += '<span style="font-size:10px;color:#065f46;background:#a7f3d0;padding:2px 6px;border-radius:8px;font-weight:600">'+rrLanes.length+' lanes eligible</span>';
            h += '</div>';
            h += '<div style="display:flex;flex-wrap:wrap;gap:6px">';
            rrLanes.forEach(function(lane) {
                h += '<span title="'+esc(lane.why)+'" style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:#fff;border:1px solid '+lane.color+'33;border-radius:14px;font-size:11px;font-weight:600;color:'+lane.color+'">';
                h += lane.icon+' '+esc(lane.label)+'</span>';
            });
            h += '</div>';
            h += '<div style="margin-top:10px;font-size:11px;color:#065f46;line-height:1.5">';
            h += 'Schema markup will be generated when you save the draft. After save, click <strong>Test in Google Rich Results</strong> below to validate.';
            h += '</div>';
            h += '</div>';
        }

        // ===== PRO UPSELL (if score < 80) =====
        if (score < 80) {
            var missingPoints = 80 - score;
            h += '<div style="padding:16px 20px;background:linear-gradient(135deg,#eef2ff,#e0e7ff);border:1px solid #c7d2fe;border-radius:10px;margin-bottom:16px;display:flex;align-items:center;gap:16px">';
            h += '<div style="flex-shrink:0;width:44px;height:44px;background:linear-gradient(135deg,#764ba2,#667eea);border-radius:10px;display:flex;align-items:center;justify-content:center"><svg width="22" height="22" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></div>';
            h += '<div style="flex:1"><div style="font-size:14px;font-weight:700;color:#312e81">+'+missingPoints+' points needed for A grade</div>';
            h += '<div style="font-size:12px;color:#4338ca;margin-top:2px">Pro plan adds Tavily Search for real statistics, expert quotes, and authoritative citations that boost your GEO score to 80+</div></div>';
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
            // INJECT fixes (add content without editing existing text)
            // v1.5.74 — also check if the article already has a References
            // section with real links. User reported the button appearing even
            // when the article had citations from initial generation. Belt-and-
            // braces: score check + content check. The content check catches
            // cases where the scorer is wrong (v1.5.68-71 bug scored 0 always).
            var mdHasRefs = (res.markdown || '').match(/## References[\s\S]*?\[.+?\]\(https?:\/\//);
            var htmlHasLinks = (res.content || '').match(/<a\s+[^>]*href=["']https?:\/\//i);
            if (c.citations && c.citations.score < 80 && !mdHasRefs && !htmlHasLinks) fixes.push({id:'citations', label:'Add Citations & References', desc:c.citations.count+' citations found. Top content has 5+. Uses real web sources (no hallucinated links).', icon:'admin-links', impact:'+10 pts', mode:'inject'});
            if (c.expert_quotes && c.expert_quotes.score < 100) fixes.push({id:'quotes', label:'Add Expert Quotes', desc:c.expert_quotes.count+' quotes found. Expert quotes boost GEO visibility by 41%. Inserts 2 quotes without editing existing text.', icon:'format-quote', impact:'+6 pts', mode:'inject'});
            if (c.factual_density && c.factual_density.score < 70) fixes.push({id:'statistics', label:'Add Statistics', desc:'Not enough numbers. Uses real research data from web search. Inserts stats without editing existing text.', icon:'chart-bar', impact:'+10 pts', mode:'inject'});
            if (c.tables && c.tables.score < 50) fixes.push({id:'table', label:'Add Comparison Table', desc:'No tables found. Tables get cited 30-40% more by AI. Inserts a table without editing existing text.', icon:'editor-table', impact:'+5 pts', mode:'inject'});
            if (c.freshness && c.freshness.score < 100) fixes.push({id:'freshness', label:'Add Freshness Signal', desc:'No "Last Updated" date. Adds date at top without editing existing text.', icon:'calendar-alt', impact:'+6 pts', mode:'inject'});
            // FLAG fixes (show issues, user edits manually)
            if (c.readability && c.readability.score < 70) fixes.push({id:'readability', label:'Simplify Readability', desc:'Grade '+((c.readability.flesch_grade||'?'))+' is too complex. Runs an AI pass to rewrite over-complex sections to grade 7 while preserving every fact and citation.', icon:'editor-spellcheck', impact:'+10 pts', mode:'inject'});
            if (c.island_test && c.island_test.score < 80) fixes.push({id:'island', label:'Check Pronoun Starts', desc:c.island_test.detail+'. Shows which paragraphs to fix manually.', icon:'editor-removeformatting', impact:'+8 pts', mode:'flag'});
            if (c.section_openings && c.section_openings.score < 70) fixes.push({id:'openers', label:'Fix Section Openings', desc:c.section_openings.detail+'. Rewrites short openers to 40-60 words via AI.', icon:'editor-paragraph', impact:'+8 pts', mode:'inject'});
            // v1.5.11 NEW — flag-mode checks for the three new scoring dimensions
            if (c.keyword_density && c.keyword_density.score < 60) {
                var kdDesc = (c.keyword_density.density ? 'Density '+c.keyword_density.density+'%. Target 0.5-1.5%. ' : '') + (c.keyword_density.h2_coverage ? c.keyword_density.h2_coverage+'% of H2s contain the keyword.' : 'Keyword placement needs work.');
                // v1.5.67 — converted from flag to inject mode. New label
                // "Optimize Keyword Density" runs an AI rewrite pass to
                // drop density from 2-3% → ~1% by swapping exact-phrase
                // mentions for pronouns and variations.
                fixes.push({id:'keyword', label:'Optimize Keyword Density', desc:kdDesc+' Runs an AI pass to rewrite over-dense mentions as pronouns or variations, keeping the first and H2 mentions intact.', icon:'search', impact:'+10 pts', mode:'inject'});
            }
            if (c.humanizer && c.humanizer.score < 70) {
                var hmDesc = 'Found '+(c.humanizer.tier1_count||0)+' Tier-1 AI red-flag words and '+(c.humanizer.tier2_count||0)+' Tier-2 words. Shows which words to rewrite for more natural prose.';
                fixes.push({id:'humanizer', label:'Check AI Writing Patterns', desc:hmDesc, icon:'edit', impact:'+4 pts', mode:'flag'});
            }
            if (c.core_eeat && c.core_eeat.score < 70) {
                var ceDesc = (c.core_eeat.details ? c.core_eeat.details.length+'/10 CORE-EEAT items passed. ' : '') + 'Shows which E-E-A-T signals are missing (direct answer, first-hand language, tradeoffs, etc).';
                fixes.push({id:'core_eeat', label:'Check E-E-A-T Signals', desc:ceDesc, icon:'shield-alt', impact:'+5 pts', mode:'flag'});
            }
        }

        // v1.5.155 — GEO Optimization Summary. Shows what passed (green) and
        // what's missing (amber). No action button — article is optimized at
        // generation time. This is informational guidance only.
        if (c) {
            h += '<div style="padding:20px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:16px">';
            h += '<h3 style="margin:0 0 12px;font-size:16px;font-weight:700">GEO Optimization Summary</h3>';

            // Build checks: green for passing, amber for failing
            var geoChecks = [];
            if (c.citations) geoChecks.push({label:'Citations', detail: (c.citations.count||0) + ' inline links', pass: (c.citations.score||0) >= 80 || (c.citations.count||0) >= 3});
            if (c.expert_quotes) geoChecks.push({label:'Expert Quotes', detail: (c.expert_quotes.count||0) + ' quotes', pass: (c.expert_quotes.score||0) >= 80 || (c.expert_quotes.count||0) >= 2});
            if (c.factual_density) geoChecks.push({label:'Statistics', detail: (c.factual_density.score||0) >= 70 ? 'sufficient data points' : 'add more numbers', pass: (c.factual_density.score||0) >= 70});
            if (c.tables) geoChecks.push({label:'Comparison Table', detail: (c.tables.count||0) > 0 ? (c.tables.count||0) + ' table(s)' : 'consider adding for +30% AI citation', pass: (c.tables.count||0) > 0});
            if (c.readability) geoChecks.push({label:'Readability', detail: 'Grade ' + Math.round(c.readability.flesch_grade || 0), pass: (c.readability.score||0) >= 70});
            if (c.section_openings) geoChecks.push({label:'Section Openings', detail: (c.section_openings.score||0) >= 70 ? '40-60 word openers' : 'some sections need longer openers', pass: (c.section_openings.score||0) >= 70});
            if (c.freshness) geoChecks.push({label:'Freshness', detail: (c.freshness.score||0) >= 100 ? 'date included' : 'add Last Updated date', pass: (c.freshness.score||0) >= 100});

            // Keyword density — special handling
            var kdVal = (c.keyword_density && typeof c.keyword_density.density === 'number') ? c.keyword_density.density : 0;
            if (c.keyword_density) geoChecks.push({label:'Keyword Density', detail: kdVal.toFixed(1) + '%', pass: kdVal >= 0.8 && kdVal <= 2.5});

            // E-E-A-T
            if (c.core_eeat) geoChecks.push({label:'E-E-A-T Signals', detail: (c.core_eeat.score||0) >= 70 ? 'passing' : 'needs improvement', pass: (c.core_eeat.score||0) >= 70});

            var passCount = geoChecks.filter(function(g){return g.pass}).length;
            h += '<p style="margin:0 0 10px;font-size:12px;color:#6b7280">' + passCount + '/' + geoChecks.length + ' checks passing</p>';

            h += '<div style="display:flex;flex-wrap:wrap;gap:6px">';
            geoChecks.forEach(function(chk) {
                var bg = chk.pass ? '#f0fdf4' : '#fffbeb';
                var border = chk.pass ? '#bbf7d0' : '#fde68a';
                var color = chk.pass ? '#166534' : '#92400e';
                var icon = chk.pass ? '&#10003;' : '&#9888;';
                h += '<span style="font-size:11px;padding:4px 10px;background:'+bg+';border:1px solid '+border+';border-radius:12px;color:'+color+'">';
                h += icon + ' ' + chk.label + ' <span style="font-weight:400;opacity:0.8">' + chk.detail + '</span></span>';
            });
            h += '</div>';
            h += '</div>';
        }

        // v1.5.27 — Places Validator debug panel. Only shown for local-intent
        // keywords so non-local articles don't see it. Surfaces which providers
        // were tried + how many places each returned, whether Places_Validator
        // stripped any sections, and whether the pre-gen switch fired. This is
        // the primary diagnostic surface when a user reports "my listicle still
        // shows fake businesses" or "my Foursquare key isn't being used".
        if (res.places_validator && res.places_validator.is_local_intent) {
            var pv = res.places_validator;
            var bgColor = pv.force_informational ? '#fef2f2' : (pv.places_insufficient ? '#fffbeb' : '#f0fdf4');
            var borderColor = pv.force_informational ? '#ef4444' : (pv.places_insufficient ? '#f59e0b' : '#22c55e');
            var headerIcon = pv.force_informational ? '🚨' : (pv.places_insufficient ? '⚠️' : '✅');
            var headerText = pv.force_informational
                ? 'Places Validator: article was structurally hallucinated'
                : (pv.places_insufficient
                    ? 'Places Validator: places insufficient — article written as informational'
                    : 'Places Validator: real places found, listicle allowed');
            h += '<div style="padding:16px 20px;background:'+bgColor+';border-left:4px solid '+borderColor+';border-radius:0 8px 8px 0;margin-bottom:16px">';
            h += '<div style="font-weight:700;font-size:14px;margin-bottom:8px">'+headerIcon+' '+headerText+'</div>';
            h += '<div style="font-size:12px;color:#374151;line-height:1.6">';
            if (pv.places_location) {
                h += '<strong>Location:</strong> '+pv.places_location+'<br>';
            }
            if (pv.places_business_type) {
                h += '<strong>Business type:</strong> '+pv.places_business_type+'<br>';
            }
            h += '<strong>Pool size:</strong> '+(pv.pool_size || 0)+' verified places<br>';
            if (pv.warnings && pv.warnings.length) {
                h += '<strong>Validator warnings:</strong><ul style="margin:4px 0 0 16px;padding:0">';
                pv.warnings.forEach(function(w) { h += '<li>'+w+'</li>'; });
                h += '</ul>';
            }
            h += '</div>';
            if (pv.places_insufficient) {
                h += '<div style="margin-top:10px;padding:10px;background:rgba(255,255,255,0.6);border-radius:6px;font-size:12px;color:#78350f">';
                h += '<strong>Why:</strong> The Places waterfall (Perplexity Sonar → OpenStreetMap → Foursquare → HERE → Google Places) returned fewer than 2 verified businesses for this location. To prevent hallucinated business names, the article was written as a general informational guide instead of a listicle.<br><br>';
                h += '<strong>Best fix:</strong> configure Perplexity Sonar via OpenRouter in <a href="'+(window.ajaxurl||'').replace('admin-ajax.php','admin.php?page=seobetter-settings')+'">Settings → Places Integrations</a>. Sonar searches TripAdvisor / Yelp / Wikivoyage and typically finds real businesses for small cities worldwide. 1 min signup at <a href="https://openrouter.ai/keys" target="_blank">openrouter.ai/keys</a>, ~$0.008 per article.<br><br>';
                h += '<strong>If Sonar is already configured and still returns empty:</strong> (1) verify the OpenRouter key is saved in Settings, (2) check for typos in the key, (3) try running the same keyword against a larger nearby town to confirm Sonar is working — if the larger town returns real results, the smaller one genuinely has no verified sources online. Secondary fallbacks (Foursquare / HERE, both free tier) are below in the same settings card.';
                h += '</div>';
            }
            h += '</div>';
        }

        // Content preview with style block
        var content = res.content || '';
        var styleMatch = content.match(/<style>[\s\S]*?<\/style>/);
        if (styleMatch) { h += styleMatch[0]; content = content.replace(/<style>[\s\S]*?<\/style>/, ''); }
        // v1.5.70 — "Changes applied" banner above the content preview.
        // Shows after inject-fix so user can see what was done. Auto-clears
        // after 8 seconds. Only appears on re-renders (skipScroll=true).
        if (skipScroll && window._seobetterLastFixMessage) {
            h += '<div id="sb-fix-banner" style="margin:0 0 12px;padding:10px 14px;background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border:1px solid #86efac;border-radius:8px;font-size:13px;color:#166534;display:flex;align-items:center;gap:8px">';
            h += '<span style="font-size:16px">✓</span>';
            h += '<span><strong>Changes applied to article below</strong> — ' + esc(window._seobetterLastFixMessage) + '</span>';
            h += '</div>';
            window._seobetterLastFixMessage = null;
        }
        h += '<div class="seobetter-content-preview">' + content + '</div>';

        // v1.5.208 — Competitive Content Brief (read-only, informational).
        // Implements SEO-GEO-AI-GUIDELINES.md §28.1 UI surface. BM25 terms +
        // competitor H2 patterns + PAA + word-count stats from the top-N
        // Google SERP for the keyword. No action buttons — AI rewrite was
        // removed. Users who want coverage to change regenerate the article.
        // See Framework Phase 5 term_coverage report alongside it.
        var cb = res.content_brief;
        if (cb && cb.terms && cb.terms.length) {
            var termCoverage = (res.framework && res.framework.phase_5_quality_gate && res.framework.phase_5_quality_gate.term_coverage) || null;
            var coverageScore = termCoverage ? termCoverage.score : null;
            var coverageColor = coverageScore >= 80 ? '#22c55e' : (coverageScore >= 60 ? '#f59e0b' : '#ef4444');

            h += '<details style="margin-top:24px;padding:14px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px">';
            h += '<summary style="cursor:pointer;font-weight:600;font-size:14px;color:#111827;display:flex;justify-content:space-between;align-items:center;gap:8px">';
            h += '<span>🔬 Competitive Content Brief (top ' + (cb.eligible_count || 0) + ' Google results)</span>';
            if (coverageScore !== null) {
                h += '<span style="font-size:12px;padding:3px 10px;border-radius:999px;background:' + coverageColor + '20;color:' + coverageColor + ';font-weight:700">' + coverageScore + '/100 coverage</span>';
            }
            h += '</summary>';

            h += '<div style="margin-top:14px;font-size:12px;color:#6b7280;line-height:1.5">Informational only. Based on BM25 analysis of the top ' + (cb.eligible_count || 0) + ' Google results for this keyword. The article generator already used this data to steer coverage; this panel shows what was considered.</div>';

            // Word count guidance
            if (cb.word_count && cb.word_count.avg) {
                h += '<div style="margin-top:12px;padding:10px 12px;background:#fff;border:1px solid #e5e7eb;border-radius:6px">';
                h += '<div style="font-size:11px;color:#6b7280;font-weight:600;margin-bottom:4px">COMPETITOR WORD COUNT</div>';
                h += '<div style="font-size:13px;color:#111827">Avg <strong>' + cb.word_count.avg + '</strong> words (range ' + cb.word_count.min + '–' + cb.word_count.max + ', median ' + cb.word_count.median + ')</div>';
                h += '</div>';
            }

            // Term coverage detail
            if (termCoverage) {
                h += '<div style="margin-top:12px;padding:10px 12px;background:#fff;border:1px solid #e5e7eb;border-radius:6px">';
                h += '<div style="font-size:11px;color:#6b7280;font-weight:600;margin-bottom:4px">TERM COVERAGE</div>';
                h += '<div style="font-size:13px;color:#111827">' + esc(termCoverage.detail || '') + '</div>';
                if (termCoverage.missing_terms && termCoverage.missing_terms.length) {
                    h += '<div style="margin-top:6px;font-size:12px;color:#6b7280">Missing concepts (informational — no action button): <em>' + termCoverage.missing_terms.slice(0, 8).map(esc).join(', ') + '</em></div>';
                }
                h += '</div>';
            }

            // Top BM25 terms
            h += '<div style="margin-top:12px">';
            h += '<div style="font-size:11px;color:#6b7280;font-weight:600;margin-bottom:6px">TOP DISTINCTIVE CONCEPTS (BM25)</div>';
            h += '<div style="display:flex;flex-wrap:wrap;gap:6px">';
            cb.terms.slice(0, 20).forEach(function(t) {
                h += '<span style="font-size:11px;padding:3px 8px;background:#eef2ff;color:#4338ca;border-radius:999px;border:1px solid #c7d2fe" title="BM25 ' + t.score + ' · in ' + t.df + ' competitors">' + esc(t.term) + '</span>';
            });
            h += '</div></div>';

            // H2 patterns
            if (cb.h2_patterns && cb.h2_patterns.length) {
                h += '<div style="margin-top:12px">';
                h += '<div style="font-size:11px;color:#6b7280;font-weight:600;margin-bottom:6px">COMMON H2 PATTERNS (competitors used ≥2×)</div>';
                h += '<ul style="margin:0;padding-left:18px;font-size:12px;color:#374151;line-height:1.6">';
                cb.h2_patterns.slice(0, 8).forEach(function(h2) {
                    h += '<li>' + esc(h2.text) + ' <span style="color:#9ca3af">(' + h2.count + ')</span></li>';
                });
                h += '</ul></div>';
            }

            // PAA questions
            if (cb.paa_questions && cb.paa_questions.length) {
                h += '<div style="margin-top:12px">';
                h += '<div style="font-size:11px;color:#6b7280;font-weight:600;margin-bottom:6px">PEOPLE ALSO ASK</div>';
                h += '<ul style="margin:0;padding-left:18px;font-size:12px;color:#374151;line-height:1.6">';
                cb.paa_questions.slice(0, 6).forEach(function(q) {
                    h += '<li>' + esc(q) + '</li>';
                });
                h += '</ul></div>';
            }

            h += '<div style="margin-top:12px;padding-top:10px;border-top:1px solid #e5e7eb;font-size:11px;color:#9ca3af">Cached ' + (cb.cached ? 'hit' : 'fresh') + ' · BM25 k1=' + (cb.stats && cb.stats.k1 || 1.5) + ' b=' + (cb.stats && cb.stats.b || 0.75) + ' · scraped ' + (cb.stats && cb.stats.scraped || 0) + ' pages</div>';
            h += '</details>';
        }

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
                h += '<input type="radio" name="async_headline" value="'+esc(item.text).replace(/"/g,'&quot;')+'" '+(isFirst?'checked':'')+' style="margin:0">';
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
            domain: (document.querySelector('[name="domain"]')||{}).value||'',
            country: (document.getElementById('sb-country-val')||{}).value||'',
            // v1.5.206d-fix — forward language so rest_save_draft persists
            // _seobetter_language post meta. Without this, schema inLanguage
            // falls back to get_locale() (en-US), localized labels miss,
            // and Layer 6 scoring signals fail.
            language: (document.querySelector('[name="language"]')||{}).value||'en',
            meta_title: (res.meta && res.meta.title) || bestTitle || '',
            meta_description: (res.meta && res.meta.description) || '',
            og_title: (res.meta && res.meta.og_title) || bestTitle || '',
            // Citation pool from generation — used at save time by
            // validate_outbound_links() as the primary allow-list and by
            // build_references_section() to build References programmatically.
            citation_pool: res.citation_pool || [],
            // v1.5.46 — verified Places pool from generation. Used by
            // rest_save_draft() to run Places_Link_Injector on the saved
            // hybrid HTML so the 📍 address + Google Maps + website meta
            // line below each business H2 survives into the WP draft.
            // Without this, the preview shows the meta lines but the saved
            // draft loses them.
            places: res.places || [],
            // v1.5.81 — Sonar research data from Vercel backend (server-side,
            // available for all users). Passed to inject-fix + optimize-all
            // so they use cached Sonar data instead of making new API calls.
            sonar_data: res.sonar_data || null,
            // v1.5.208 — Competitive Content Brief persistence. Implements
            // SEO-GEO-AI-GUIDELINES.md §28.1 surface. Read-only on the client.
            content_brief: res.content_brief || null,
            // 5-Part Framework phase report (§28) — persisted to post meta
            // so future audits can see which phases passed/failed.
            framework: res.framework || {}
        };

        // v1.5.138 — Personalization tips per content type
        var tipMap = {
            blog_post: "Add your personal experience and photos. Update the call to action with your actual offer.",
            how_to: "Test each step yourself before publishing. Replace stock images with your own photos of each step.",
            listicle: "Reorder items based on your expertise. Add your personal pick at #1 with a note about why.",
            review: "Replace the verdict rating with your honest score. Update Pros/Cons from real experience. Add your own product photos.",
            comparison: "Update the comparison table with real specs you've verified. Declare your honest winner.",
            buying_guide: "Update prices to current values. Add affiliate links to your recommended products. Verify availability.",
            news_article: "Update the dateline with your city. Verify all facts and statistics are current.",
            faq_page: "Add questions your actual customers ask. Update answers with your specific product/service details.",
            pillar_guide: "Add internal links to your own related articles. Update each chapter with your unique insights.",
            recipe: "Verify all ingredients match the source recipe. Add your own photos of the finished dish. Test it yourself.",
            case_study: "Replace company name and metrics with your actual client's data. Get permission for the client quote.",
            tech_article: "Test all code examples before publishing. Update version numbers and dependencies.",
            interview: "Replace the Q&A with your actual interview transcript. Update the interviewee bio with real details.",
            white_paper: "Add your own data visualizations. Update the executive summary with your key findings.",
            opinion: "This is YOUR opinion piece. Strengthen the thesis with your personal experience and evidence.",
            press_release: "Replace [Company Name] with your business. Update media contact with real details. Add your logo.",
            personal_essay: "This is your story. Replace placeholder experiences with real moments from your life.",
            glossary_definition: "Add examples specific to your industry. Link to related terms on your site.",
            scholarly_article: "Add real citations from your research. Update methodology with your actual study details.",
            sponsored: "Update the disclosure with your actual sponsor name. Replace the CTA with the sponsor's real link.",
            live_blog: "Add real timestamps as events happen. Pin the most important updates to Key Moments."
        };
        var ct = (document.querySelector('#sb-content-type') || document.querySelector('[name="content_type"]') || {}).value || 'blog_post';
        var tip = tipMap[ct] || tipMap.blog_post;
        h += '<div style="margin-top:12px;padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:12px;color:#166534;display:flex;align-items:flex-start;gap:8px">';
        h += '<span style="font-size:16px;flex-shrink:0">&#128161;</span>';
        h += '<div><strong>Personalize this article:</strong> ' + tip + '</div>';
        h += '</div>';

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
        if (!skipScroll) resultEl.scrollIntoView({ behavior:'smooth', block:'start' });

        // Wire up the save button — use preventDefault to stop form submit even though
        // the button is type="button" (belt-and-braces; some browsers still bubble)
        document.getElementById('seobetter-save-draft-btn').addEventListener('click', function(e) {
            if (e && e.preventDefault) e.preventDefault();
            if (e && e.stopPropagation) e.stopPropagation();
            var btn = this;
            var statusEl = document.getElementById('seobetter-save-status');
            var draft = window._seobetterDraft;

            if (!draft || (!draft.markdown && !draft.content)) {
                alert('Error: No content to save. Please regenerate.');
                return false;
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

            // v1.5.184 — Minimize save payload to prevent PHP crash on shared hosting.
            // A 2000-word white paper produces ~100KB of HTML in 'content' + ~20KB markdown.
            // Hostinger's post_max_size is often 8MB but the REST API nonce/timeout can fail
            // on large payloads. The save endpoint re-formats markdown via format_hybrid()
            // anyway, so we only need to send markdown + metadata, not the preview HTML.
            var saveDraft = {
                title: draft.title || '',
                markdown: draft.markdown || '',
                accent_color: draft.accent_color || '#764ba2',
                keyword: draft.keyword || '',
                content_type: draft.content_type || 'blog_post',
                domain: draft.domain || '',
                country: draft.country || '',
                // v1.5.206d-fix — forward language so rest_save_draft
                // persists _seobetter_language post meta (needed for
                // schema inLanguage, localized labels, Layer 6 scoring).
                language: draft.language || 'en',
                meta_title: draft.meta_title || '',
                meta_description: draft.meta_description || '',
                og_title: draft.og_title || '',
                citation_pool: draft.citation_pool || [],
                post_type: draft.post_type || 'post',
            };

            api('save-draft', 'POST', saveDraft).then(function(r) {
                if (r.success) {
                    btn.textContent = 'Saved!';
                    btn.style.background = '#059669';
                    var schemaNote = '';
                    if (r.schema_dest === 'aioseo') schemaNote = ' <span style="font-size:11px;color:#6b7280">Schema → AIOSEO</span>';
                    else if (r.schema_dest === 'yoast') schemaNote = ' <span style="font-size:11px;color:#6b7280">Schema → Yoast SEO</span>';
                    else if (r.schema_dest === 'rankmath') schemaNote = ' <span style="font-size:11px;color:#6b7280">Schema → RankMath</span>';
                    else schemaNote = ' <span style="font-size:11px;color:#6b7280">Schema → SEOBetter (auto-injected)</span>';

                    // v1.5.216.27 — Phase 1 item 8: surface Rich Results validation link.
                    // r.rich_results_test_url is built server-side in rest_save_draft()
                    // using rawurlencode(get_permalink). Empty when post lacks a permalink
                    // (shouldn't happen for saved posts, but defensive). Also show a
                    // count of active rich-result lanes for at-a-glance feedback.
                    var rrValidate = '';
                    if (r.rich_results_test_url) {
                        var rrCount = (r.rich_results_types && r.rich_results_types.length) || 0;
                        rrValidate = ' &middot; <a href="'+r.rich_results_test_url+'" target="_blank" rel="noopener" style="color:#059669;font-weight:600;text-decoration:none" title="Validate '+rrCount+' schema @types in Google\'s official tester">🔍 Test Rich Results &rarr;</a>';
                    }
                    statusEl.innerHTML = '<a href="'+r.edit_url+'" style="color:#764ba2;font-weight:600">Edit post &rarr;</a>' + schemaNote + rrValidate;
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Save as WordPress Draft';
                    statusEl.textContent = 'Error: ' + (r.error || 'Failed to save.');
                    statusEl.style.color = '#ef4444';
                }
            }).catch(function(err) {
                console.error('SEOBetter save-draft error:', err);
                btn.disabled = false;
                btn.textContent = 'Save as WordPress Draft';
                statusEl.textContent = 'Error: ' + (err.message || 'Request failed — article may be too large for your server. Try a shorter word count.');
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
                // v1.5.75 — Animated progress bar inside the button.
                // Shows a filling bar that pulses, plus elapsed time.
                // User reported slow AI calls and "people might think
                // its not working" with just "Working..." text.
                var origWidth = Math.max(this.offsetWidth, 140);
                this.style.minWidth = origWidth + 'px';
                this.style.position = 'relative';
                this.style.overflow = 'hidden';
                this.innerHTML = '<span class="sb-btn-progress-bar"></span><span style="position:relative;z-index:1;display:inline-flex;align-items:center;gap:6px"><span class="sb-spinner"></span><span class="sb-btn-timer">Working 0s</span></span>';
                var self = this;
                var startTime = Date.now();
                var timerEl = this.querySelector('.sb-btn-timer');
                var timerInterval = setInterval(function() {
                    var elapsed = Math.round((Date.now() - startTime) / 1000);
                    if (timerEl) timerEl.textContent = 'Working ' + elapsed + 's';
                }, 1000);

                // v1.5.76 — pass citation_pool from the original generation
                // so inject_citations can reuse it instead of rebuilding.
                // User reported "it worked during generation but Add Citations
                // says 0 sources" — because the button rebuilt the pool from
                // scratch without category/country context.
                api('inject-fix', 'POST', {
                    fix_type: fixId,
                    markdown: draft.markdown,
                    keyword: draft.keyword,
                    accent_color: draft.accent_color,
                    citation_pool: draft.citation_pool || [],
                    sonar_data: draft.sonar_data || null
                }).then(function(result) {
                    clearInterval(timerInterval);
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
                        // v1.5.64 — FULL results-panel re-render after any
                        // successful inject-fix.
                        draft.markdown = result.markdown || draft.markdown;
                        draft.content = result.content;
                        draft.checks = result.checks || draft.checks;
                        draft.geo_score = result.geo_score;
                        draft.grade = result.grade;

                        // v1.5.67 — mark this fix as APPLIED so future
                        // re-renders of the Analyze & Improve panel show
                        // a grey "✓ Done" card instead of a fresh "Add now"
                        // button. User reported clicking Simplify Readability
                        // successfully but then "when i scroll back up it
                        // goes back to being able to be pressed again when
                        // it should be greyed out as done".
                        window._seobetterAppliedFixes = window._seobetterAppliedFixes || {};
                        window._seobetterAppliedFixes[fixId] = {
                            applied_at: Date.now(),
                            message: result.added || 'Applied successfully'
                        };

                        // Flash the progress bar to 100% + show ✓
                        self.classList.add('sb-btn-done');
                        self.innerHTML = '✓ ' + esc(result.added || 'Done');
                        self.style.background = '#22c55e';

                        // Build a synthetic res object preserving headlines,
                        // meta, places_validator, citation_pool, and other
                        // fields that inject-fix doesn't touch. Merges over
                        // the original generation response stored at
                        // window._seobetterLastResult (see renderResult
                        // caller below).
                        var prev = window._seobetterLastResult || {};
                        var updatedRes = Object.assign({}, prev, {
                            content: result.content,
                            markdown: result.markdown,
                            geo_score: result.geo_score,
                            grade: result.grade,
                            word_count: result.word_count || prev.word_count || 0,
                            checks: result.checks || prev.checks,
                            suggestions: result.suggestions || prev.suggestions || []
                        });
                        window._seobetterLastResult = updatedRes;

                        // v1.5.70 — store the fix message so renderResult
                        // can show a "Changes applied" banner above the
                        // content preview. User reported not being able to
                        // tell if fixes actually changed the article.
                        window._seobetterLastFixMessage = result.added || 'Changes applied to article';

                        // 800ms delay so user perceives the ✓ flash, then
                        // rebuild the entire results panel from fresh data.
                        // v1.5.69 — pass skipScroll=true so the user
                        // stays at their current position instead of
                        // being yanked to the score ring at the top.
                        setTimeout(function() {
                            if (typeof renderResult === 'function') {
                                renderResult(updatedRes, true);
                            }
                        }, 800);
                    } else {
                        // v1.5.74 — show error reason so user knows WHY
                        // it failed (e.g. "no pool sources" for citations)
                        self.disabled = false;
                        self.textContent = 'Retry';
                        self.style.background = '#ef4444';
                        self.style.minWidth = '';
                        if (result.error) {
                            var errHtml = '<div style="margin-top:6px;padding:8px 10px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;font-size:11px;color:#991b1b;line-height:1.4">' + esc(result.error) + '</div>';
                            self.parentElement.insertAdjacentHTML('afterend', errHtml);
                        }
                    }
                }).catch(function(err) {
                    clearInterval(timerInterval);
                    self.disabled = false;
                    self.textContent = 'Error';
                    self.style.background = '#ef4444';
                    self.style.minWidth = '';
                });
            });
        });

        // v1.5.78 — Optimize All button handler
        var optBtn = document.getElementById('sb-optimize-all');
        if (optBtn) {
            optBtn.addEventListener('click', function() {
                var draft = window._seobetterDraft;
                if (!draft || !draft.markdown) { alert('No content to optimize.'); return; }
                var self = this;
                self.disabled = true;
                self.style.opacity = '0.6';

                var progressPanel = document.getElementById('sb-optimize-progress');
                var barEl = document.getElementById('sb-opt-bar');
                var stepLabel = document.getElementById('sb-opt-step-label');
                var timerEl = document.getElementById('sb-opt-timer');
                var detailEl = document.getElementById('sb-opt-steps-detail');
                progressPanel.style.display = 'block';

                // Simulated progress steps (client-side animation)
                var steps = [
                    {pct:8, label:'Researching via Perplexity Sonar...'},
                    {pct:25, label:'Adding citations & references...'},
                    {pct:40, label:'Inserting expert quotes...'},
                    {pct:55, label:'Adding statistics...'},
                    {pct:68, label:'Building comparison table...'},
                    {pct:80, label:'Simplifying readability...'},
                    {pct:92, label:'Optimizing keyword density...'}
                ];
                var stepIdx = 0;
                var startTime = Date.now();
                var stepInterval = setInterval(function() {
                    var elapsed = Math.round((Date.now() - startTime) / 1000);
                    timerEl.textContent = elapsed + 's';
                    if (stepIdx < steps.length) {
                        barEl.style.width = steps[stepIdx].pct + '%';
                        stepLabel.textContent = 'Step '+(stepIdx+1)+'/'+steps.length+': '+steps[stepIdx].label;
                        stepIdx++;
                    }
                }, 4000);
                // Start first step immediately
                barEl.style.width = steps[0].pct + '%';
                stepLabel.textContent = 'Step 1/'+steps.length+': '+steps[0].label;
                stepIdx = 1;

                // Collect current scores to tell backend which fixes are needed
                var checksForApi = {};
                if (draft.checks) checksForApi = draft.checks;

                // v1.5.141 — Pass optimize mode so backend skips irrelevant steps
                var btnOptMode = (document.getElementById('sb-optimize-all') || {}).getAttribute('data-opt-mode') || 'full';
                api('optimize-all', 'POST', {
                    markdown: draft.markdown,
                    keyword: draft.keyword,
                    accent_color: draft.accent_color,
                    citation_pool: draft.citation_pool || [],
                    scores: checksForApi,
                    sonar_data: draft.sonar_data || null,
                    domain: draft.domain || '',
                    content_type: draft.content_type || 'blog_post',
                    country: draft.country || '',
                    optimize_mode: btnOptMode
                }).then(function(result) {
                    clearInterval(stepInterval);
                    var elapsed = Math.round((Date.now() - startTime) / 1000);
                    timerEl.textContent = elapsed + 's';

                    if (result.success) {
                        barEl.style.width = '100%';
                        barEl.style.background = 'linear-gradient(90deg,#22c55e,#16a34a)';
                        stepLabel.textContent = '✓ ' + (result.added || 'Optimization complete');
                        stepLabel.style.color = '#166534';

                        // Show step details
                        var detail = '';
                        if (result.steps_run && result.steps_run.length) {
                            detail += '<span style="color:#166534">✓ Applied: ' + result.steps_run.join(', ') + '</span>';
                        }
                        if (result.steps_skipped && result.steps_skipped.length) {
                            detail += '<br><span style="color:#9ca3af">Skipped: ' + result.steps_skipped.length + ' (already passing)</span>';
                        }
                        if (result.sonar_used) {
                            detail += '<br><span style="color:#764ba2">Powered by Perplexity Sonar</span>';
                        }
                        detailEl.innerHTML = detail;

                        // Update draft
                        draft.markdown = result.markdown || draft.markdown;
                        draft.content = result.content;
                        draft.checks = result.checks || draft.checks;
                        draft.geo_score = result.geo_score;
                        draft.grade = result.grade;

                        // v1.5.83 — store optimization summary for the green panel
                        window._seobetterAppliedFixes = window._seobetterAppliedFixes || {};
                        window._seobetterAppliedFixes._optimize_all = {
                            applied_at: Date.now(),
                            message: result.added || 'All fixes applied',
                            steps_run: result.steps_run || [],
                            steps_skipped: result.steps_skipped || [],
                            sonar_used: result.sonar_used || false
                        };

                        window._seobetterLastFixMessage = result.added || 'All optimizations applied';

                        var prev = window._seobetterLastResult || {};
                        var updatedRes = Object.assign({}, prev, {
                            content: result.content,
                            markdown: result.markdown,
                            geo_score: result.geo_score,
                            grade: result.grade,
                            word_count: result.word_count || prev.word_count || 0,
                            checks: result.checks || prev.checks,
                            suggestions: result.suggestions || prev.suggestions || []
                        });
                        window._seobetterLastResult = updatedRes;

                        // Re-render after a brief delay so user sees the success state
                        setTimeout(function() {
                            renderResult(updatedRes, true);
                        }, 2000);
                    } else {
                        barEl.style.width = '100%';
                        barEl.style.background = '#ef4444';
                        stepLabel.textContent = 'Optimization failed';
                        stepLabel.style.color = '#991b1b';
                        detailEl.innerHTML = '<span style="color:#991b1b">' + esc(result.error || 'Unknown error') + '</span>';
                        self.disabled = false;
                        self.style.opacity = '1';
                    }
                }).catch(function(err) {
                    clearInterval(stepInterval);
                    barEl.style.background = '#ef4444';
                    stepLabel.textContent = 'Request failed';
                    stepLabel.style.color = '#991b1b';
                    self.disabled = false;
                    self.style.opacity = '1';
                });
            });
        }
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
            accent_color: (form.querySelector('[name="accent_color"]')||{}).value||'#764ba2',
            // v1.5.216.25 — Brand Voice picker (Phase 1 item 6). Empty string = no voice (default prose).
            brand_voice_id: (form.querySelector('[name="brand_voice_id"]')||{}).value||''
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
