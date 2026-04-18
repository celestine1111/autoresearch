# SEOBetter Pro Features — Ideas & Planning

> **Purpose:** Brainstorm and track ideas for Pro-only features.
> Everything is currently free for testing. Once validated, features will be gated behind a license key.
>
> **Last updated:** April 2026

---

## FREE TIER (Always Available)

### Content Generation
- [ ] Generate articles (3/month via Cloud, unlimited with BYOK)
- [ ] Async generation with progress bar
- [ ] 5 headline variations with scoring
- [ ] Auto-generated meta title & description
- [ ] Auto-suggest secondary & LSI keywords
- [ ] Stock images (Picsum fallback — generic)
- [ ] GEO Score analysis (score + grade + suggestions)
- [ ] Save as Post or Page
- [ ] 1 AI provider connection (BYOK)

### Editor Integration
- [ ] GEO Score in Post sidebar panel
- [ ] Toolbar score badge
- [ ] Headline Analyzer
- [ ] Re-analyze button

### Technical SEO
- [ ] Schema markup (Article + FAQ auto-detected)
- [ ] Social meta (OG + Twitter cards)
- [ ] llms.txt generator
- [ ] AI bot meta tags (max-image-preview, max-snippet)
- [ ] Speakable schema for voice search

---

## PRO TIER IDEAS (To Gate Behind License Key)

### Content Generation Pro
- [ ] Unlimited Cloud generation (remove 3/month limit)
- [ ] Bulk CSV import (50+ keywords → batch generate)
- [ ] Content Brief Generator
- [ ] Content type templates (21 types with auto-adjusted prompts)
- [ ] Country & language targeting (90+ countries with local APIs)
- [ ] Topic-relevant images via Pexels (vs generic Picsum)
- [ ] Featured image auto-download to media library

### Analyze & Improve Pro
- [ ] "Add now" inject buttons (citations, quotes, table, stats, freshness)
- [ ] Real-time research data (Vercel: Reddit, HN, Wikipedia, DuckDuckGo)
- [ ] Brave Search integration (real web statistics)
- [ ] One-click Content Refresh for stale articles

### SEO Plugin Integration Pro
- [ ] AIOSEO auto-population (title, desc, OG, Twitter, schema, keywords)
- [ ] Yoast auto-population
- [ ] RankMath auto-population
- [ ] Schema type auto-detection (Article, FAQ, HowTo, Review, Product)

### Analytics & Monitoring Pro
- [ ] AI Citation Tracker (check if AI engines cite your content)
- [ ] Content Decay Alerts (email when posts go stale or scores drop)
- [ ] Keyword Cannibalization Detector
- [ ] GEO Score column in Posts list (sortable)

> **Internal linking — OUT OF SCOPE** (decision 2026-04-15). User will rely on an existing third-party WordPress internal linking plugin (e.g. Link Whisper, Internal Link Juicer, Rank Math internal linker) at save time. SEOBetter will not duplicate that functionality. AIOSEO's "no internal links" check is accepted as a cross-plugin concern, not a SEOBetter responsibility.

### Advanced Pro
- [ ] Unlimited AI provider connections
- [ ] Social content generator (Twitter, LinkedIn, Instagram)
- [ ] **Booking.com affiliate program integration into Places links (NEW — requested 2026-04-15)**

  **What it does:** when the Places_Link_Injector (v1.5.29) renders the meta line below every verified business H2, for hotel / motel / B&B / hostel / apartment / villa categories, append a Booking.com affiliate search link alongside the existing Google Maps + website links.

  **Example rendered line after feature ships:**
  ```
  📍 Via Rosini 20, 52046 Lucignano AR ·
  View on Google Maps · Website · ⭐ 4.7 (Foursquare) ·
  🛏️ Book on Booking.com
  ```

  **How it works:**
  - User configures Booking.com affiliate ID once in Settings → Branding or a new Settings → Affiliate Links card
  - Places_Link_Injector checks if the matched pool entry's `type` field contains one of: `hotel`, `motel`, `b&b`, `hostel`, `apartment`, `villa`, `guesthouse`, `lodging`, `resort`, `inn`
  - If yes AND user has an affiliate ID configured, appends a Booking.com search URL with the user's aid: `https://www.booking.com/searchresults.html?ss={urlencode(name+location)}&aid={user_affiliate_id}&label=seobetter`
  - The `label` param lets the user track which SEOBetter articles generate bookings in the Booking.com affiliate dashboard
  - Only fires for lodging business types — restaurants, cafes, shops, gelaterias get the normal meta line without the affiliate link

  **Extensions (later releases):**
  - **Expedia Group affiliate** (Hotels.com, Vrbo) as an alternative provider the user picks in settings
  - **GetYourGuide affiliate** for activity/tour listings (would trigger on `attraction`, `museum`, `tour` categories)
  - **Amazon affiliate** for product review listicles (would need an SKU lookup layer, more complex)
  - **Automatic affiliate-ID injection into v1.5.30 Sonar results** — when Sonar returns a TripAdvisor URL, optionally rewrite it to a TripAdvisor affiliate URL (requires TripAdvisor partner API key)

  **Why this matters:** lodging is the highest-paying affiliate vertical. A single booking can pay $20-$150 in commission. For travel-niche bloggers running articles like "best hotels in Lucignano Italy" or "where to stay in Kyoto", monetizing the verified Places data is the single biggest revenue lever the plugin could add.

  **Integration point:** [includes/Places_Link_Injector.php::build_meta_line()](includes/Places_Link_Injector.php) — add a conditional branch that checks `$entry['type']` against the lodging category list and appends the affiliate link when the user's `seobetter_settings['booking_affiliate_id']` is non-empty.

  **Estimated effort:** ~2 hours (settings field + category detection + URL builder + meta line injection).

  **Free-to-Pro gating:** could be Pro-only (unlock revenue vertical behind a paywall) or ship free (gives users immediate reason to get the plugin and try it).


- [x] **Branding page + AI-generated featured image — ✅ SHIPPED in v1.5.32 (2026-04-15)**

  See [BUILD_LOG.md v1.5.32](BUILD_LOG.md) for anchors. Settings → Branding & AI Featured Image card with business name/description, logo upload, 3 brand color pickers, 4 providers (Pollinations free / Gemini Nano Banana / DALL-E 3 / FLUX Pro), 7 style presets, negative prompt, and a `Stock_Image_Inserter::set_featured_image()` hook that tries the AI generator first and falls back to Pexels → Picsum. Original backlog entry kept below for reference:

  **What it does:** a new Settings → Branding page that lets the user upload their brand assets once, then auto-generates a high-quality featured image for every article based on the brand, article title, and keywords. Replaces the current Picsum / stock-image fallback for featured images.

  **User uploads / configures (once):**
  - Logo (PNG/SVG) — stored in media library
  - 3 brand hex colors (primary / secondary / accent) with color pickers
  - Brand style preset: `Realistic photo` / `Professional illustration` / `Flat graphic` / `Hero banner` / `Product shot` / `Minimalist` / `Editorial` / `Retro` / `3D render` (9 presets)
  - Image aspect ratio: `16:9 hero` (default) / `1:1 social` / `4:3 featured` / `9:16 Pinterest`
  - Optional: reference brand URL for style-matching AI to pull site colors/fonts
  - Optional: negative prompt (things to never include — e.g. "no text overlay, no watermark, no people's faces")

  **Per-article prompt composition (hidden from user by default):**
  The plugin prepends a style-preset prompt to the AI image generator based on the user's picks. Example for `Realistic photo` preset:

  > `"Professional high-quality photograph, {user_brand_primary_color} color accent, clean composition, natural lighting, shallow depth of field, editorial style, 16:9 aspect ratio, no text overlay, no logos, no watermarks. Subject: {article_title}. Context: {first_3_keywords}. Style reference: {user_brand_style}."`

  For `Flat graphic`:
  > `"Flat vector illustration, bold geometric shapes, {user_brand_colors} color palette, minimal composition, no photorealism, no text, clean background, 16:9 aspect ratio. Subject: {article_title}."`

  Each preset has its own prepended template. User sees a "Preview prompt" toggle that reveals the full composed prompt so they can override it if they don't like what the AI produces.

  **Override flow:**
  - After generation, user sees the image in the result panel
  - If they don't like it, they click "Regenerate with custom prompt" — modal opens with the full composed prompt pre-filled and editable
  - User edits the prompt, clicks Regenerate, sees the new image
  - No limit on regenerations (or 3 per article on free, unlimited on Pro)

  **Image generation backends (evaluated 2026-04-14):**

  | Model | Cost/image | Quality | Speed | Best for | Access |
  |---|---|---|---|---|---|
  | **Google Gemini 2.5 Flash Image** | ~$0.02 | Good | 3–5s | Cheapest, multi-language captions | Gemini API direct, or via OpenRouter |
  | **OpenAI DALL-E 3** | $0.04 (standard) / $0.08 (HD) | Very good | 5–10s | Reliable brand-aware generation, 1024×1024 or 1792×1024 | OpenAI API direct |
  | **FLUX.1 Pro 1.1** (Black Forest Labs) | ~$0.055 | Excellent | 5–8s | Best realism + composition, top choice for hero banners | via FAL.ai or Replicate |
  | **FLUX.1 Pro 1.1 Ultra** | ~$0.06 | Excellent + 4MP res | 8–15s | Print-quality featured images | via FAL.ai or Replicate |
  | **FLUX.1 Dev** | ~$0.003 | Good | 3–5s | Budget tier, good for bulk | via Replicate or fal.ai |
  | **Ideogram v2** | ~$0.08 | Very good | 6–10s | BEST for images where text needs to appear correctly (e.g. a hero showing the article title as a graphic element) | via Replicate |
  | **Stable Diffusion XL** | ~$0.003 | Fair | 3–5s | Free/cheap backup, lower quality | via Replicate, self-host via Ollama/AUTOMATIC1111 |
  | **Midjourney** | subscription only | Best-in-class | 30s+ | NOT usable — no public API as of 2026 | — |

  **Recommended default:** `FLUX.1 Pro 1.1` via FAL.ai or Replicate for quality, with `Gemini 2.5 Flash Image` as the budget tier. Both available via direct API with free/trial credit.

  **Settings page layout:**
  ```
  Settings → Branding (NEW PAGE)
  ├── Brand Assets
  │    ├── Logo upload (PNG/SVG, max 2MB)
  │    ├── Primary color (color picker)
  │    ├── Secondary color (color picker)
  │    └── Accent color (color picker)
  ├── Featured Image Generator
  │    ├── AI Model [dropdown: FLUX Pro / DALL-E 3 / Gemini Flash Image]
  │    ├── API Key for selected model [password field]
  │    ├── Style preset [9 options]
  │    ├── Aspect ratio [4 options]
  │    ├── Negative prompt [textarea, optional]
  │    └── Preview composed prompt [toggle]
  └── Free tier limits
       └── 3 images per month on free / unlimited on Pro
  ```

  **Free-to-Pro gating:**
  - Free tier: 3 images/month, Stable Diffusion XL only (cheapest), no custom prompt editing
  - Pro: unlimited images, any model (FLUX / DALL-E / Ideogram), full prompt editing, brand URL auto-extraction, aspect ratio choice, negative prompt

  **Integration point:** `Stock_Image_Inserter::get_featured_image()` currently falls back to Picsum — replace that with a call to the new `AI_Image_Generator::generate($keyword, $title, $brand_settings)` when a brand is configured. Keep Picsum as the ultimate fallback for users without a brand configured or when the AI call fails.

  **Why this matters:** Picsum images are random — they have zero relevance to the article content. AI featured images dramatically improve click-through from social shares and search results, and brand-aware images make the article look like a professional publisher's output (which is the whole differentiation vs. basic AI content tools).

  **Estimated effort:** ~10 hours (new Settings page + brand-asset storage + model fetcher + prompt composer + modal UI + 3 API integrations)

- [x] **Article Writer Model Recommender + Tier Badges — ✅ SHIPPED in v1.5.32 (2026-04-15)**

  See [BUILD_LOG.md v1.5.32](BUILD_LOG.md) for anchors. 3 quick-pick preset buttons (🥇 Best Quality / 💰 Best Value / 🆓 Free Tier) above the Advanced dropdown, compatibility badges (🟢 / 🟡 / 🔴) on every model in the advanced dropdown via `AI_Provider_Manager::MODEL_TIERS`, and a red-tier confirmation dialog when saving. Avoid list: Llama 3.1/3.3, DeepSeek R1/v3, Mixtral, o3/o4, Perplexity Sonar (research model, not a writer). Original backlog entry kept below for reference:

  **Problem:** the current Settings page lists 7 providers × 40+ models with zero guidance. A WordPress newbie picks a cheap Llama or DeepSeek R1 model, sees the plugin "hallucinate" business names, and blames the plugin — even though the hallucination is the model ignoring PLACES RULES, not the plugin failing. Some models genuinely CANNOT follow SEOBetter's strict grounding rules.

  **What to ship:**

  1. **3-tier preset selector** replacing the current dropdown-of-40:
     - 🥇 **Best Quality (Recommended)** → `claude-sonnet-4.6` direct or via OpenRouter. $0.04/article. Best instruction-following, best multilingual, best at following PLACES RULES + Citation Pool.
     - 💰 **Best Value** → `claude-haiku-4.5` direct. $0.008/article. 80% of Sonnet quality for 20% of the cost.
     - 🆓 **Free Tier** → `gemini-2.5-flash` via Gemini API (1,500 free requests/day). $0 for most users. Weaker on strict rules — only safe when Places waterfall is fully populated.
     - **Advanced** → opens the full 40-model dropdown for power users who know what they're doing.

  2. **Compatibility badges per model** in the Advanced dropdown:
     - 🟢 `Recommended — hallucination-tested with SEOBetter flow`
     - 🟡 `Works but may ignore URL rules under complex prompts`
     - 🔴 `Not recommended — known to produce fake business names despite PLACES RULES`
     - Explicit ❌ list: `deepseek/deepseek-r1`, `deepseek/deepseek-v3`, `meta-llama/llama-3.3-70b`, `meta-llama/llama-3.1-*`, `openai/o3`, `openai/o3-mini`, `openai/o4-mini`, `mistralai/mixtral-*`, `groq/llama-*`, `perplexity/sonar*` (research model, not writer)

  3. **Hallucination warning modal** when user saves a 🔴 model as their article writer AND has no Places waterfall keys configured:
     *"⚠️ You're using Llama 3.3 70B with no Places data sources configured. For local-business keywords like 'best gelato in [city]', this combination will produce hallucinated business names. Either configure Perplexity Sonar in Settings → Places Integrations OR switch to a 🟢 Recommended model."*
     Don't block the save, but make the warning loud.

  4. **Country → model auto-suggest tooltip** on the article generator form. When user picks a country, show a small tooltip under the model selector:
     - Japan / Korea → "Recommended: Gemini 2.5 Pro"
     - Italy / Spain / France → "Recommended: Claude Sonnet 4.6"
     - India / Thailand / Vietnam → "Recommended: Gemini 2.5 Pro"
     - USA / UK / AU → "Recommended: Claude Sonnet 4.6 or GPT-4o"

  5. **Plain-English model descriptions** — replace "claude-sonnet-4-6" (meaningless to newbies) with "Claude Sonnet 4.6 — Best accuracy, $0.04/article, great for any language". Each model gets a one-sentence description focused on what the user cares about, not the model's name.

  6. **"Test this model" button** — runs a 50-token generation with a dummy keyword against the selected model and returns "✅ Model responds in X seconds" or "❌ Model failed: X". Helps users verify their key works before trying a full article.

  **Estimated effort:** ~4 hours (preset-selector UI + badge system + warning modal + tooltip + test button)

  **Why this matters:** the plugin currently appears to "produce hallucinated content" when the real cause is users picking incompatible models. Simplifying the model picker eliminates ~80% of that confusion for WordPress newbies.
- [ ] Content Exporter (HTML, Markdown, Plain Text)
- [ ] Affiliate link auto-linking + CTA buttons
- [ ] White-label mode

### Internationalization (i18n) — Admin UI Translation
**Status:** Not started. Currently all admin UI text is hardcoded English even though the plugin has a 90+ country picker for article generation.

**What's missing:**
- No `/languages` folder or `.pot` template file
- No `load_plugin_textdomain()` call in main plugin file
- ~131 strings already use `__()` / `_e()` across admin views, but:
  - Content type labels ("Blog Post", "How-To Guide", "Listicle", etc.) in `content-generator.php:288-310` are hardcoded English `<option>` text
  - Other hardcoded strings likely exist throughout admin views — needs full audit
- No `.mo` files for any language

**What to do (one-time setup, free forever after):**
1. Add `load_plugin_textdomain( 'seobetter', false, dirname( SEOBETTER_PLUGIN_BASENAME ) . '/languages' );` on `plugins_loaded` hook
2. Audit all PHP admin views — wrap every user-facing string in `__( 'string', 'seobetter' )`, especially the content type dropdown options
3. Generate `.pot` template with WP-CLI: `wp i18n make-pot . languages/seobetter.pot`
4. Ship `seobetter.pot` in plugin zip under `/languages/`
5. When submitted to WP.org plugin directory → translate.wordpress.org volunteers translate for free into 100+ languages, auto-downloaded per user's WP site language

**Optional Pro feature idea:** "AI Admin UI Translator" button — one-click DeepL/GPT translation of the `.pot` file into any language, compiled to `.mo` and dropped into `/languages/`. This would be a differentiator vs. Yoast/AIOSEO which rely on volunteer translations only.

**Why this matters:** Plugin targets global markets (country picker has Spanish, Japanese, Arabic, Chinese, etc.) but the admin UI that the website owner uses to configure it is English-only. A Spanish WP user installing SEOBetter on their Spanish WP install sees English menus — feels unfinished. Yoast/AIOSEO/RankMath all have 100+ translations because they do this properly.

**Priority:** Not blocking — plugin works in English for now. Should be done **before WP.org submission** since WP.org expects translation-ready plugins and auto-populates volunteer translations.

---

## CONVERSION STRATEGY IDEAS

### Free → Pro Triggers
1. **Usage limit** — 3 articles/month free, unlimited Pro
2. **Score gate** — generate article free, but "Add now" inject fixes require Pro
3. **Preview gate** — show the improved score after fix, but require Pro to actually apply it
4. **Feature teaser** — show Citation Tracker results but blur/hide details without Pro
5. **Time-limited trial** — 7-day Pro trial on first install, then reverts to free

### Recommended Split (based on testing)
- **Free:** Generate articles, see GEO score, see suggestions, headline analyzer
- **Pro:** Fix buttons (inject), research data, Pexels images, AIOSEO integration, bulk generate, citation tracker
- **Upsell moment:** After generation, show score and fixes needed → "Add now" buttons require Pro

### Pricing Ideas
- $59/year single site
- $99/year 3 sites
- $199/year unlimited sites
- Lifetime deal: $299

### Key Metric to Track
- Free → Pro conversion rate (target: 5-10%)
- Articles generated per user per month
- "Add now" button clicks (shows intent to upgrade)

---

## SECURITY CONSIDERATIONS

### License Key Architecture
- Server-validated keys (Vercel `/api/validate`)
- Domain-locked (each key tied to site URL)
- 24-hour cache with 48-hour grace period on network errors
- Development key: `SEOBETTER-DEV-PRO` (only works with WP_DEBUG)

### Anti-Piracy
- Pro features that depend on server-side processing (research API, Brave search) can't be unlocked by editing PHP
- Client-side gating (UI buttons) can be bypassed but the actual processing requires a valid key
- Consider moving more logic to the Vercel cloud API for Pro features

---

## Research Sources Backlog

### ✅ SHIPPED in v1.5.24 — Pluggable Places Provider abstraction (Google / Foursquare / HERE) + Settings UI

**Status:** Shipped 2026-04-13 in v1.5.24 as the Places waterfall. The architecture is slightly different from the originally-proposed PHP abstraction — the 5 providers live inline in `cloud-api/api/research.js` instead of separate PHP classes, because the JS cloud-api is where all the existing research fetchers live and adding a PHP class layer would have duplicated the logic. The user-facing result is the same: a Settings → Places Integrations section with 3 API key fields, a 5-tier waterfall with automatic fallback, and provider-agnostic downstream prompt/validator logic.

**What was shipped:**
- Tier 1: OSM (already in v1.5.23)
- Tier 2: Wikidata SPARQL (free, no key)
- Tier 3: Foursquare Places (free 1K/day, user key in Settings)
- Tier 4: HERE Places (free 1K/day, user key in Settings)
- Tier 5: Google Places API New (paid, user key in Settings)
- Hard refuse fallback (write general informational article with disclaimer if all tiers return <3 places)
- Settings → Places Integrations card with 3 password fields + setup instructions per provider
- GEO_Analyzer `local_places` high-priority suggestion pointing users to Settings when the sentinel fires
- Telemetry: `places_provider_used` + `places_providers_tried` in the research response

**What was NOT included, can come in a follow-up:**
- Yelp Fusion provider (500/day free, Anglophone markets only — lower priority)
- Test Connection REST endpoints per provider in Settings
- Pre-generation coverage card above the Generate button
- Post-generation provider attribution line in the result panel

**Original entry kept below for reference:**

### Pluggable Places Provider abstraction (Google / Foursquare / Yelp / HERE) + Settings UI

**Status:** v1.5.23 ships with OSM-only Places integration (Nominatim + Overpass, fully free, no API key). The v1.6.0 follow-up is a full provider abstraction layer so users who want higher-quality Google Maps / Foursquare / Yelp / HERE data can plug in their own API keys. User requested this feature on 2026-04-13 — added here per explicit user ask.

**Why v1.5.23 ships OSM-only:**
- OSM works out of the box for every user — zero setup cost, no signup
- Global coverage, best in Europe/North America
- Stops the hallucination bleeding immediately
- v1.6.0 can layer the abstraction without breaking the v1.5.23 flow

**v1.6.0 target architecture:**

1. **New `Places_Provider` interface** (PHP) — abstract base class with one method: `search_places(location: string, type: string, limit: int): array` returning normalized place entries
2. **Concrete implementations:**
   - `Places_OSM` (always available, free) — v1.5.23 fetchOSMPlaces ported to PHP. Already used by the cloud-api JS version; this just wraps it as a PHP class for parity.
   - `Places_Google` — Google Places API (New). User provides API key. Paid tier ($200/mo free credit ≈ 17K searches). Best data quality globally.
   - `Places_Foursquare` — Foursquare FSQ Places API. User provides API key. Free tier: 1,000 calls/day. Good POI data with ratings and hours.
   - `Places_Yelp` — Yelp Fusion. User provides API key. Free tier: 500 calls/day. Strong US/CA/AU/UK coverage.
   - `Places_Here` — HERE Places API. User provides API key. Free tier: 1,000 transactions/day. Global.
3. **Settings page** — new "Integrations" tab with:
   - Dropdown "Places Data Source" (default: OSM)
   - API key fields per provider (password-masked, stored in wp_options)
   - "Test Connection" button per provider (REST endpoint validates the key + does a probe query)
   - Cascade fallback: if the configured provider fails or returns empty, auto-fallback to OSM
4. **Normalized data shape** — every provider returns the same `{name, type, address, website, phone, lat, lon, source_url, source}` structure so downstream code (Citation Pool, system prompt, GEO_Analyzer sentinel) is source-agnostic
5. **REST endpoint** `POST /seobetter/v1/places-provider-test` to validate API keys from the settings page without hitting the full research pipeline

**Files the v1.6.0 change will touch:**
- New: `includes/Places_Provider.php` (abstract)
- New: `includes/Places_OSM.php`, `Places_Google.php`, `Places_Foursquare.php`, `Places_Yelp.php`, `Places_Here.php`
- New: `admin/views/settings-integrations.php`
- Modified: `seobetter.php` — register settings tab + REST routes
- Modified: `cloud-api/api/research.js` — accept `places_provider` + `places_api_key` in request body, route to the right fetcher, fall back to OSM
- Modified: `Async_Generator.php::get_system_prompt()` — no change needed; PLACES RULES are source-agnostic
- Modified: `GEO_Analyzer.php::check_local_places_grounding()` — no change needed; sentinel checks for OSM/Google Maps URLs regardless of which provider populated them

**Estimated effort:** ~300 lines + settings UI + REST validation endpoints. About 4-6 hours of focused work. Too big for a v1.5.x hotfix, natural fit for v1.6.0.

### X / Twitter integration (research-only — no clean free path)

**Status:** Not started. Added to backlog v1.5.16. The user specifically wants X data because it's where unfiltered real-people skill discussions and trending tech ideas happen — Bluesky, Mastodon, DEV.to, and Lemmy (added in v1.5.16) cover adjacent niches but don't fully replace X for that signal.

**Why this is hard.** In 2026 X has no clean free API for read/search:
- X API Free tier ($0/mo) — 1,500 POSTs/month, basically zero read or search → useless for research
- X API Basic ($200/mo) — 10K reads/month → too expensive to bake into a plugin every user installs
- Public Nitter mirrors — mostly broken since 2023, surviving instances are unreliable
- ScrapeCreators / Apify scrapers — $30-100/mo → has to be optional and per-user-paid

**The 3 realistic paths to research:**

1. **Cookie-auth approach (recommended starting point)** — same as the `last30days` skill does.
   - User logs into X in their browser, copies their `auth_token` and `ct0` cookies into a SEOBetter Settings field.
   - Plugin uses those cookies to hit X's authenticated search endpoints from the cloud-api.
   - **Pro:** $0 cost, works for the user's own site, immediate value
   - **Con:** brittle — breaks whenever X updates anything. Per-user setup overhead. Cookies expire and need refresh. Possibly ToS-grey for commercial plugins.
   - Files to touch: new `searchXTwitter()` in `cloud-api/api/research.js`, new Settings fields in `admin/views/settings.php`, pass cookies through the research request as headers.

2. **Optional ScrapeCreators integration** — user provides their own ScrapeCreators API key.
   - **Pro:** legal, reliable, works for any user willing to pay $30+/mo
   - **Con:** gates X behind a paid third-party. Most users won't subscribe.
   - Best as a Pro-tier feature where the SEOBetter Pro license includes a metered ScrapeCreators allowance.

3. **Wait for X API pricing reset** — X has talked about lowering Basic tier pricing. Monitor and revisit when affordable.

**Why this didn't ship in v1.5.16:** all paths require either a paid third party, a Settings page redesign, or per-user cookie management. Picking the right path needs the user to decide whether SEOBetter is willing to ship a feature that breaks unpredictably (cookie auth) vs gates a feature behind a third-party paywall (ScrapeCreators) vs waits indefinitely (API price drop).

**Next decision needed:** which of the 3 paths to research first. Cookie-auth has the lowest upfront cost and `last30days` already proves it works in Python — porting to JS for the cloud-api is probably v1.5.17 or v1.5.18 territory.

**Reference implementation available:** the vendored `last30days` skill at `seobetter/.agents/skills/last30days/scripts/lib/vendor/bird-search/` already implements X cookie-auth search. Its approach can be ported to JS for the Vercel cloud-api once the user picks a path.

---

---

## FUTURE PRO TOOLS — Content Intelligence Suite

### Content Freshness Analyzer
A content freshness tool that analyzes every article from any blog, computes a freshness score, compares up to 3 blogs side-by-side, and generates a prioritized updating list based on traffic decline and content age.

- [ ] Crawl sitemap or RSS feed to index all published articles
- [ ] Compute freshness score per article (date published, last modified, stats currency, link rot)
- [ ] Side-by-side comparison of up to 3 blogs (yours vs competitors)
- [ ] Prioritized update list: highest-traffic articles with lowest freshness first
- [ ] Traffic decline detection (integrate GSC API or estimate from content signals)
- [ ] One-click "Refresh This Article" button that feeds the article to the content updater

### Internal Links Intelligence
An internal links tool that ingests your sitemap, maps all existing internal links, prioritizes pages by opportunity, and gives AI-powered placement suggestions with ready-to-paste HTML snippets.

- [ ] Sitemap ingestion — builds full link graph of the site
- [ ] Orphan page detection (pages with 0 internal links pointing to them)
- [ ] Opportunity scoring: pages with high GEO score but low internal links
- [ ] AI-powered anchor text suggestions — reads both source and target articles
- [ ] Ready-to-paste HTML snippets with exact insertion point in the source article
- [ ] Bulk "Add All Suggestions" button for power users
- [ ] Visual link graph (D3.js or similar) showing link clusters and gaps

### Automatic Content Updater
An automatic content updater that finds outdated statistics, recommends product mentions, identifies topic gaps from top-ranking competitors, and generates new sections in your writing style. You approve every suggestion before anything changes.

- [ ] Outdated stat finder — scans for statistics with dates older than 12 months, finds current replacements via Tavily/Sonar
- [ ] Product mention recommender — cross-references article topics with trending products in the category
- [ ] Competitor gap analysis — scrapes top 5 ranking articles for the keyword, identifies sections/topics they cover that yours doesn't
- [ ] Section generator — writes new paragraphs in the article's existing tone and style to fill identified gaps
- [ ] Approval workflow — every suggestion shown as a diff (before/after) with Accept/Reject buttons
- [ ] Batch mode — queue multiple articles for updating, review all diffs in one session
- [ ] Change log — tracks what was updated, when, and what the original text was (rollback capability)

---

## PRIORITY FIX — Places Waterfall PHP Fallback

**Problem:** When the Vercel research endpoint returns empty data (timeout, rate limit, deployment stale), local-intent articles get generated with store names but NO addresses, NO website links, NO Google Maps links, NO phone numbers. The Places waterfall (Sonar → OSM → Foursquare → HERE → Google Places) only runs during Vercel-side generation — there's no PHP-side fallback like there is for quotes (Tavily) and citations (Sonar).

**Impact:** Local articles like "pet shops brisbane" list businesses without any way for readers to find them. Destroys local SEO value, E-E-A-T trust, and user experience.

**Fix needed:**
- [ ] PHP-side Places fallback in `optimize_all()` — if `sonar_data['places']` is empty, run a PHP-side OSM/Nominatim + Overpass query to get real business data
- [ ] Pass places data to `Places_Link_Injector` at optimize time (currently only at save time)
- [ ] Ensure LocalBusiness schema is generated from the places data
- [ ] Add Google Maps link for each business (formatted as `https://www.google.com/maps/search/?api=1&query=BUSINESS+NAME+ADDRESS`)
- [ ] Add website URL when available from the Places waterfall
- [ ] Test with: "pet shops brisbane", "vet clinics melbourne", "dog groomers sydney" — verify addresses are real

**Priority:** HIGH — this affects every local-intent keyword for every user

---

## PRO FEATURE — WooCommerce Product Integration

Automatically integrate the user's own WooCommerce products, categories, and blog posts into generated articles when the topic matches.

### How it would work:
1. **Auto-scan WooCommerce catalog** — on article generation/save, the plugin reads the user's WooCommerce product categories and products via `wc_get_products()` and `get_terms('product_cat')`
2. **Topic matching** — compare article keyword against product names, category names, and product tags. Use fuzzy matching (keyword tokens appear in product title or category)
3. **Insert product links** — where the article mentions a product type (e.g. "grain free cat food"), auto-link to the user's matching WooCommerce category page or product page
4. **"Shop This" product block** — below the comparison table or at the article end, insert a styled block showing 2-3 matching products from the user's store with image, price, and "Shop Now" button
5. **Internal link scoring** — the EEAT checker already scores internal links. WooCommerce links would automatically satisfy this check

### Implementation details:
- [ ] `WooCommerce_Integrator` class — scans catalog, caches product index (1hr transient)
- [ ] `match_products($keyword, $content_type)` — returns matched products + categories sorted by relevance
- [ ] `inject_product_links($markdown, $matches)` — replaces generic product mentions with WooCommerce links
- [ ] `render_shop_block($matches)` — styled HTML block with product cards (image, name, price, button)
- [ ] Settings toggle: "Auto-integrate WooCommerce products" (on/off)
- [ ] Settings: "Shop block position" — after comparison table / end of article / both
- [ ] Settings: "Max products per article" — default 3
- [ ] Only activates if WooCommerce is installed and has products
- [ ] Works with variable products (shows price range "From $29.99")
- [ ] Affiliate disclosure auto-inserted if linking to own products

### Example output:
For keyword "grain free cat food" on a pet store site:
```
## Shop Grain Free Cat Food

[Product Image] **Ziwi Peak Air-Dried Cat Food** — $42.99
Free-range, grain-free recipe with 96% meat content.
[Shop Now →](https://mindiampets.com.au/product/ziwi-peak-cat-food/)

[Product Image] **Ivory Coat Grain Free Chicken** — $34.99
Australian-made, grain-free with real chicken.
[Shop Now →](https://mindiampets.com.au/product/ivory-coat-chicken/)
```

### Why this is a Pro feature:
- Direct revenue attribution — articles generate sales, not just traffic
- Internal linking — every product link is an internal link (SEO boost)
- E-E-A-T — "we sell these products" = first-hand experience
- Conversion — readers go from research to purchase without leaving the site
- Competitive moat — no other AI content plugin does this

---

## PRO FEATURE — Rich Result SERP Preview + Search Performance Dashboard

### Rich Result Preview (in post editor metabox)

A visual preview of how the article will appear in Google Search with its schema markup. Shows different previews per content type:

- [ ] 4th tab in SEOBetter metabox: "Rich Results" (alongside General, Page Analysis, Readability)
- [ ] Desktop + Mobile toggle views
- [ ] Recipe: card with image, star rating, cook time, ingredients count
- [ ] Review/Product: star rating, price, pros/cons badges below title
- [ ] FAQ: expandable dropdown questions below the listing
- [ ] News: Top Stories card with thumbnail + date
- [ ] LocalBusiness: map pin + address + hours
- [ ] Standard Article: enhanced listing with author, date, image
- [ ] Schema Impact Estimate panel with research-backed statistics:
  - "Articles with Recipe schema get 2.7x more clicks" (Searchmetrics)
  - "Product schema with star ratings increases CTR by 35%" (SEJ)
  - "Schema markup leads to 30-40% boost in AI citation rates" (Princeton GEO)
  - "Rich results get 58% of all clicks on page 1" (FirstPageSage)
- [ ] Schema Validation badge: "Valid (0 errors)" or "3 warnings"
- [ ] Link to Google Rich Results Test for the page URL
- [ ] Count of active rich result types: "4 rich results active: Recipe, FAQ, Breadcrumb, Speakable"

### Google Search Console Integration (Pro)

Real performance data per article — requires OAuth connection:

- [ ] Settings: "Connect Google Search Console" button (OAuth2 flow)
- [ ] Per-article dashboard: Impressions, Clicks, CTR, Average Position
- [ ] Before/after comparison: schema added vs no schema
- [ ] Trend charts over 30/60/90 days
- [ ] Site-wide summary: total rich result impressions, CTR improvement
- [ ] "Top Performing Articles" leaderboard sorted by clicks
- [ ] Alert: "Article dropped 5 positions — consider refreshing"

### Content Performance Stats (Free tier)

Research-backed estimates (no API needed):

- [ ] Reading time estimate (word count / 238 wpm): "5 min read — optimal for mobile"
- [ ] Readability score: "Grade 7 — accessible to 85% of readers"
- [ ] Content uniqueness signal: "92% original content"
- [ ] Schema richness score: "4/6 rich result types active"
- [ ] AI citation readiness: "High — has quotes, citations, FAQ, structured data"

---

*Add new ideas to this file as they come up. Move items to "implemented" when built and tested.*
