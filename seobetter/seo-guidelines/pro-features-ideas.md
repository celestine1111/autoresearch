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
- [ ] Internal Link Suggestions
- [ ] GEO Score column in Posts list (sortable)

### Advanced Pro
- [ ] Unlimited AI provider connections
- [ ] Social content generator (Twitter, LinkedIn, Instagram)
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

*Add new ideas to this file as they come up. Move items to "implemented" when built and tested.*
