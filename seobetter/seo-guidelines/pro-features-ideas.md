# SEOBetter Pro Features — Roadmap & Tier Matrix

> **Status:** LOCKED 2026-04-29 — source of truth for what gets built from here to launch.
>
> **Replaces:** the prior pro-features-ideas.md draft (deleted; this is the rewrite after the 2026-04-29 strategic re-tier).
>
> **Related docs:**
> - `pro-plan-pricing.md` — pricing math, unit economics, conversion projections, AppSumo phases
> - `website-ideas.md` §1 — locked positioning ("the wedge") used across all marketing surfaces
> - `article-marketing.md` — competitor traffic-stealing content strategy + 12-week editorial calendar
> - `security.md` — 4-layer security architecture (referenced extensively in §4 below)
> - `BUILD_LOG.md` — what's already shipped vs what's queued
>
> **Ownership:** every entry here has a phase number and a free/Pro/Pro+/Agency tier. Anything not on this list is not in scope pre-launch.

---

## 1. Mission & Positioning

**Locked positioning** (use verbatim across landing pages, plugin descriptions, AppSumo copy):

> *"SEOBetter is the WordPress plugin that engineers your content to be cited by ChatGPT, Perplexity, and Google AI Overviews — schema-first structure, GEO scoring built in, and citation tracking to prove it works. In 60+ languages. BYOK so it's free."*

**The wedge is the COMBINATION** — not any single feature:

1. **21-content-type schema engine** with proper layered emission (Article + Recipe wrapper + Speakable + citation[] + LocalBusiness + Organization auto-detect). Yoast/RankMath cover ~6 types.
2. **Multilingual GEO** — 60+ languages with localized translations of structural anchors. Surfer is English-mostly; Frase ~11 languages.
3. **BYOK pricing** — solopreneurs pay $0 in API costs to plugin owner. Surfer entry $89/mo, Frase $45/mo.
4. **AI Citation Tracker** — measures whether ChatGPT / Perplexity / Gemini / AI Overviews actually cite content. The wedge made measurable.

---

## 2. Tier Matrix — Source of Truth

This table supersedes anywhere else feature gating is described. If pro-plan-pricing.md or any code disagrees, this wins.

### Tier prices

| Tier | Monthly | Annual | Annual discount | Sites | Seats | Cloud articles/mo |
|---|---|---|---|---|---|---|
| **Free** | $0 | — | — | 1 | 1 | BYOK unlimited (zero Cloud) |
| **Pro** | **$39** | **$349** | 25% ($117 saved) | **1** | 1 | **50** |
| **Pro+** | **$69** | **$619** | 25% ($209 saved) | **3** | 1 | **100** |
| **Agency** | **$179** | **$1,790** | 17% ($358 saved) | **10** | **5** | **250** |

All Cloud articles default to **cheap config** (gpt-4.1-mini extractions, ~$0.013/article cost). Premium config (Sonnet/Opus content, ~$0.10/article) is gated to Cloud Credit pack purchases or Agency-tier opt-in. This protects margins.

### Feature matrix

Legend: ✅ = unlocked at this tier · ❌ = not available · 🔓 = unlock badge in UI when user hits feature for first time (shows upgrade CTA)

#### Generation core

| Feature | Free | Pro | Pro+ | Agency | Build status |
|---|---|---|---|---|---|
| BYOK unlimited generation | ✅ | ✅ | ✅ | ✅ | ✅ Shipped |
| Cloud articles/month included | — | 50 | 100 | 250 | ✅ Shipped |
| Content types | 3 (Blog Post, How-To, Listicle) | All 21 | All 21 | All 21 | ✅ Shipped |
| Multilingual (60+ languages) | ❌ | ✅ | ✅ | ✅ | ✅ Shipped (v1.5.216.x) |
| Country localization | 6 EN-speaking only (US/UK/AU/CA/NZ/IE) | All 80+ | All 80+ | All 80+ | ✅ Shipped |
| AI Featured Image generation | ❌ | ✅ | ✅ | ✅ | ✅ Shipped (v1.5.216.13) |
| Inline citation injection (Citation Pool) | ❌ | ✅ | ✅ | ✅ | ✅ Shipped |
| Pexels stock images | ✅ | ✅ | ✅ | ✅ | ✅ Shipped |

#### Schema

| Feature | Free | Pro | Pro+ | Agency | Build status |
|---|---|---|---|---|---|
| Basic schema (Article + FAQPage + BreadcrumbList) | ✅ | ✅ | ✅ | ✅ | ✅ Shipped |
| Advanced schema (Recipe wrapper, Speakable, citation[], TechArticle, ScholarlyArticle, etc.) | ❌ | ✅ | ✅ | ✅ | ✅ Shipped |
| Auto-detect schema (LocalBusiness / Organization / Product / Event / Course / Video / etc.) | ❌ | ✅ | ✅ | ✅ | ✅ Shipped |
| 5 Schema Blocks (Product / Event / LocalBusiness / Vacation Rental / Job Posting) | ❌ | ✅ | ✅ | ✅ | ⏳ Phase 1 |
| Rich Results validation preview (in editor) | ✅ Read-only | ✅ Read-only | ✅ Read-only | ✅ Read-only | ✅ Shipped (needs surfacing) |

#### AI Search File (llms.txt) — emerging standard, reinforces the wedge

| Feature | Free | Pro | Pro+ | Agency | Build status |
|---|---|---|---|---|---|
| Basic `/llms.txt` (site name, description, latest 20 posts, citation guidance) | ✅ | ✅ | ✅ | ✅ | ✅ Shipped (current LLMS_Txt_Generator) |
| **Optimized `/llms.txt`** (content-type categorization, GEO-score filtering, custom summary setting, language + country signals, FAQ pointers, schema declaration) | ❌ | ✅ | ✅ | ✅ | ⏳ Phase 1 (2 days rewrite) |
| **`/llms-full.txt`** (comprehensive content dump — full text of all published posts as concatenated markdown for LLM ingestion) | ❌ | ❌ | ✅ | ✅ | ⏳ Phase 1 (1 day endpoint) |
| Multilingual `/llms.txt` per-language (`/en/llms.txt`, `/fr/llms.txt`, `/ja/llms.txt`) | ❌ | ❌ | ✅ | ✅ | ⏳ Phase 1 (0.5 day, builds on multilingual gen) |
| Custom llms.txt editor (override AI-generated summary, descriptions per post) | ❌ | ❌ | ✅ | ✅ | ⏳ Phase 1 (1 day Settings UI) |
| Transient caching + invalidation hooks (regenerate on post save, 24h TTL) | ✅ | ✅ | ✅ | ✅ | ⏳ Phase 1 (0.5 day) |

#### AI Search Readiness — bridge for non-llms.txt-adopting engines (Google AI Overviews / OpenAI / Perplexity / Gemini)

> **Why:** llms.txt adoption is real for Anthropic / Stripe / Zapier / Cloudflare but uncertain for Google / OpenAI / Perplexity through 2026-27. We need features that ensure AI engines can read + extract content via HTML crawling regardless of llms.txt support. Pairs with AI Citation Tracker — together they're the wedge made measurable: "Can AI engines READ your content? Did they CITE it?"

| Feature | Free | Pro | Pro+ | Agency | Build status |
|---|---|---|---|---|---|
| **AI Crawler Access audit** — scan robots.txt + meta robots + HTTP X-Robots-Tag for blocks against GPTBot / ClaudeBot / PerplexityBot / Bingbot / ChatGPT-User / Google-Extended / CCBot / anthropic-ai. Shows pass/fail per bot. One-click fix updates robots.txt with AI-bot-friendly rules. | ✅ | ✅ | ✅ | ✅ | ⏳ Phase 1 (1-2 days) |
| **AI Search Readiness Score 0-100** — weighted composite: Crawler access 15% + AI bot activity (last 30d) 20% + Schema completeness 20% + Citation extractability 15% + llms.txt + sitemap submitted 15% + Freshness signals 15%. Shows alongside GEO score per-article + site-wide dashboard. | ✅ Score visible | ✅ + 5 action items | ✅ + per-page breakdown | ✅ + per-site rollup | ⏳ Phase 2 (1-2 days) |
| **AI Bot Activity Tracker** — server-side User-Agent logging in custom WP table; dashboard chart shows per-bot visits per day. *"GPTBot crawled 47 pages this month; PerplexityBot 23; ClaudeBot 12."* | ❌ | ❌ | ✅ Last 30 days | ✅ Last 12 months + per-site filtering | ⏳ Phase 2 (2-3 days) |
| **Engine submission checklist** — Bing Webmaster Tools (powers Copilot) / Google Search Console / Perplexity (when registration opens) / ChatGPT search registration. Status indicators per engine. | ✅ Read-only checklist | ✅ Click-to-submit | ✅ Auto-status checks | ✅ Bulk per-site | ⏳ Phase 2 (0.5 day) |
| Per-page AI bot heatmap (which articles get hit by which bots; correlate with Citation Tracker) | ❌ | ❌ | ✅ | ✅ | ⏳ Phase 5+ |
| Server-level AI crawler optimization (Cache-Control / Last-Modified / ETag tuning for AI crawler patterns) | ❌ | ❌ | ❌ | ✅ | ⏳ Phase 5+ |
| llms.txt format auto-update (if spec evolves when OpenAI/Google/Perplexity adopt) | ✅ | ✅ | ✅ | ✅ | ⏳ Phase 5+ (auto-track) |

#### Research stack

| Feature | Free | Pro | Pro+ | Agency | Build status |
|---|---|---|---|---|---|
| Jina Reader fallback | ✅ | ✅ | ✅ | ✅ | ✅ Shipped |
| OSM Places (Tier 1) | ✅ | ✅ | ✅ | ✅ | ✅ Shipped |
| Places Waterfall Tier 0 (Sonar Pro) + Tier 2/3/4 (Foursquare/HERE/Google) | ❌ | ✅ | ✅ | ✅ | ✅ Shipped |
| Firecrawl deep research | ❌ | ✅ | ✅ | ✅ | ✅ Shipped |
| Brave Search citations | ❌ | ✅ | ✅ | ✅ | ✅ Shipped |
| Serper SERP intelligence | ❌ | ✅ | ✅ | ✅ | ✅ Shipped |

#### Quality / scoring

| Feature | Free | Pro | Pro+ | Agency | Build status |
|---|---|---|---|---|---|
| GEO Analyzer (full rubric) | ✅ | ✅ | ✅ | ✅ | ✅ Shipped |
| SEOBetter Score 0-100 (composite) | ✅ | ✅ | ✅ | ✅ | ⏳ Phase 1 (1-2 days) |
| Humanizer banned-phrase regex | ✅ | ✅ | ✅ | ✅ | ✅ Shipped |
| Brand Voice profiles (sample-post enforcement) | ❌ | 1 voice | 3 voices | Unlimited + per-language | ⏳ Phase 1 (2-3 weeks) |

#### SEO plugin integration

| Feature | Free | Pro | Pro+ | Agency | Build status |
|---|---|---|---|---|---|
| Yoast/RankMath/AIOSEO/SEOPress meta sync (title, desc, OG, Twitter) | ✅ | ✅ | ✅ | ✅ | ✅ Shipped |
| Canonical URL sync | ✅ | ✅ | ✅ | ✅ | ⏳ Phase 1 (15 min) |
| AIOSEO full schema sync | ❌ | ✅ | ✅ | ✅ | ✅ Shipped |

#### Search performance / Freshness

| Feature | Free | Pro | Pro+ | Agency | Build status |
|---|---|---|---|---|---|
| GSC connect + view (clicks/impressions/queries/position) | ✅ | ✅ | ✅ | ✅ | ⏳ Phase 1 (1 week) |
| GSC-driven Freshness inventory (ranks decay-priority by GSC click + position) | ❌ | ❌ | ✅ | ✅ | ⏳ Phase 1 (1 week, builds on age-based) |
| Freshness report (age-based only) | ✅ | ✅ | ✅ | ✅ | ✅ Shipped (Content_Freshness_Manager) |
| Content Refresher (one-click stale-article refresh) | ❌ | ❌ | ✅ | ✅ | ✅ Shipped (no UI button — locked decision: destructive AI rewrite never gets a one-click trigger; users approve diffs via Refresh-brief generator instead) |
| **"Why?" diagnostic drawer on Freshness page** (signal-by-signal breakdown of priority score; clipboard-only micro-actions; non-destructive) | ❌ | ✅ age/year/missing-signal | ✅ + GSC click decay, position drift, top queries, striking-distance | ✅ + same | ✅ Shipped v1.5.216.54 |
| **Editor Freshness panel** (post-edit metabox tab + Gutenberg sidebar mirror; lazy-loads diagnostic; works in all editors via metabox) | ❌ | ❌ | ✅ | ✅ | ✅ Shipped v1.5.216.54 |
| Refresh-brief generator (side-by-side diff suggestions, humans approve, no auto-rewrite) | ❌ | ❌ | ❌ | ✅ | ⏳ Phase 5+ |
| GSC Indexing API (request indexing on save) | ❌ | ❌ | ❌ | ✅ | ⏳ Phase 5+ |

#### Internal Links (override the 2026-04-15 removal decision)

| Feature | Free | Pro | Pro+ | Agency | Build status |
|---|---|---|---|---|---|
| Orphan-pages report | ✅ | ✅ | ✅ | ✅ | ⏳ Phase 1 (2 days) |
| Editor sidebar suggester (5 suggestions/post) | ❌ | ❌ | ✅ | ✅ | ⏳ Phase 1 (1 week) |
| Unlimited suggestions + auto-linking rules (Link Whisper-style) | ❌ | ❌ | ❌ | ✅ | ⏳ Phase 5+ |

#### Link Health Scanner (broken-link detection + context-aware strip)

> **Why:** Generation-time URL filters (v62.x `is_low_quality_source`) catch known-bad patterns but can't catch URLs that 404 *after* publication, return soft-404 HTML pages, or were valid at generation but redirected to spam. Equivalent of the popular "Broken Link Checker" plugin but built-in, schema-aware, and tied to the SEOBetter citation pool / references section so removed links don't leave dangling text.
>
> **Why context-aware strip is mandatory:** A naive "remove `<a>` tag, keep text" approach breaks the article. SEOBetter links live in 4 distinct contexts and each needs a different strip strategy:
> 1. **Body inline wrap** — `<a>...</a>` around prose phrase → unwrap, keep text only.
> 2. **References list `<li>`** — entire numbered citation entry must be removed AND remaining entries renumbered AND any `[N]` brackets in body re-numbered to match. Removing the URL alone leaves a citation with no target.
> 3. **Expert quote attribution** — `<cite>` block with name + source link. Strip URL, keep name+source as plaintext attribution.
> 4. **Schema `citation[]` array** — JSON-LD entry must be removed from `Schema_Generator` output for that post (else schema cites a 404).
>
> Without context-awareness, fixing one broken link can leave the article looking malformed. This is why off-the-shelf Broken Link Checker doesn't work for SEOBetter content.

| Feature | Free | Pro | Pro+ | Agency | Build status |
|---|---|---|---|---|---|
| Per-article "Validate Links" button (Posts list row action + editor sidebar) — HEAD-then-GET, manual trigger | ✅ Manual single-post only | ✅ | ✅ | ✅ | ⏳ Phase 1.5 (3-5 days) |
| Context-aware strip (body unwrap / reference renumber / quote-attribution preserve / schema citation[] remove) | ✅ | ✅ | ✅ | ✅ | ⏳ Phase 1.5 (bundled with above) |
| Site-wide bulk scanner (admin page: SEOBetter → Link Health; queue + progress bar) | ❌ | ✅ | ✅ | ✅ | ⏳ Phase 1.5 |
| Scheduled background scans (WP-Cron / Action Scheduler, configurable cadence) | ❌ | ❌ | ✅ Weekly | ✅ Daily/weekly/custom | ⏳ Phase 1.5 |
| Audit log per post (last scan date, links checked, what was stripped, before/after diff) | ❌ | ✅ Last scan only | ✅ Full history | ✅ Full history + CSV export | ⏳ Phase 1.5 |
| Soft-404 detection (page returns 200 but body matches "page not found" / domain-parking signatures) | ❌ | ✅ | ✅ | ✅ | ⏳ Phase 1.5 |
| Redirect-chain analysis (flag `>3 hops`, parked-domain redirects, http→https demotion) | ❌ | ❌ | ✅ | ✅ | ⏳ Phase 2 |
| Auto-replace via Citation Pool (find a fresh URL from same domain or trusted-whitelist source matching the cited claim, instead of strip) | ❌ | ❌ | ❌ | ✅ | ⏳ Phase 5+ |

**Design constraints (locked):**
- Read-only by default; strips require explicit user click ("Strip 3 broken links") with diff preview. Never auto-mutates published posts without confirmation.
- HEAD first (cheap), GET fallback when HEAD returns 405/403 (some servers reject HEAD).
- Whitelist exemption for sources that block bots but humans can reach (gov sites with WAFs, paywalls returning 401/403). Exempt list lives in same `is_host_trusted` allowlist.
- Throttled at 5 req/s to avoid being treated as scraper.
- Schema strip uses Schema_Generator regenerate path — never edits stored postmeta JSON-LD blob directly (idempotency).

#### Multilingual structural-completeness sprint (v62.72+ deferred)

> **Status:** PLANNED. Discovered 2026-05-05 during T3 #5 Listicle multilingual test on `Die 10 besten waschbaren Hundebetten Deutschland 2026`. Multilingual *translation* works (German H1, German H2s, German body, real German pet brands sourced correctly). Multilingual *structural enforcement* doesn't — German listicle shipped 4 products instead of 10, products lacked the "1.", "2." numbering prefix, FAQ rendered as separate H2 questions instead of nested H3 under one FAQ H2, and the auto-injected Quick Comparison Table landed at position 11 instead of position 3.
>
> **Why deferred:** All English content types verified through T3 first (16 of 21 still to test as of 2026-05-05). Multilingual fixes touch shared infrastructure that could regress English work. Cleaner to finish English coverage, lock the regression baseline, then sprint multilingual.
>
> **Why this is a Pro-tier feature concern:** Multilingual generation is a Pro/Pro+/Agency feature (Free is English-only per Tier Matrix above). The multilingual *promise* — "60+ languages with localized translations of structural anchors" — is currently only half-true. Translations work; structural integrity in non-English doesn't. Fixing this defends the wedge ("60+ languages" claim).

| Sub-fix | Risk to English (when shipped) | Effort | Build status |
|---|---|---|---|
| **A. Translate the outline-padding synonym map per language.** Currently `Async_Generator::generate_outline()`'s `$section_matches` closure checks substring/synonyms/key-tokens against template names that are all English. For German, `Die wichtigsten Erkenntnisse` isn't recognized as covering `Key Takeaways` → padding incorrectly appends an English `Key Takeaways` slot. Fix: use `Localized_Strings::get($key, $language)` to translate every canonical template name into match-candidate variants per article's language. | **Zero** — additive, English `if` branch hits first. | 0.5 day | ⏳ v62.72+ |
| **B. Strengthen SECTION COUNT CONTRACT for non-English listicles.** The template guidance string `"10 Numbered Items (each gets H2 numbered 1-10 like '1. Product Name')"` doesn't survive cleanly through the German AI's interpretation. Fix: add an explicit numbering enforcement rule to the LANGUAGE clause (gated on `!$is_english`) — `"EVERY product H2 MUST be prefixed with 'N. ' where N is 1-10. This is mandatory, not stylistic."` Same for FAQ structure: explicitly require `## [TRANSLATED 'Frequently Asked Questions']` with `### [Question]` children. | **Zero** — gated `if (!$is_english)`, English path untouched. | 1 day | ⏳ v62.72+ |
| **C. Fix `enforce_geo_requirements` table-position for non-English.** Auto-injected Quick Comparison Table lands at end of article instead of position 3 (after Introduction) when language ≠ en. Fix: position-detection logic must use `Localized_Strings::get('introduction', $language)` to find the Introduction H2 in the article's actual language, then insert immediately after. | **Medium** — touches shared code path. Mitigate with `if ($lang !== 'en') { new logic } else { existing path }` gate. | 0.5 day | ⏳ v62.72+ |
| **D. Multilingual regression test fixture.** Once A+B+C ship, build a minimal automated regression suite that generates one article per signed-off content type × 3 priority languages (de, ja, es) and asserts: ≥80% of expected H2s present, no English template names leaking into non-English headings, structural anchors localized correctly. Runs on every v62.X+ ship that touches Async_Generator / Schema_Generator / enforce_geo_requirements. | **Zero** — pure test code, no runtime impact. | 1-2 days | ⏳ v62.72+ |

**Total effort:** 3-4 days of focused work. Schedule: after T3 English coverage hits 21/21 + at least 7 days of stable English regression. Tracking issue per sub-fix on next sprint.

**Dependency risk:** None of A-D depend on each other. Can ship piecemeal. C is the riskiest because it touches shared infrastructure — ship it LAST after A+B prove the gating pattern works cleanly.

**Spot-test discipline (in the meantime):** for each newly-signed-off English content type, generate ONE article in German as a smoke test. Don't try to fix multilingual issues found — just log them in `multilingual-bugs.md` (or this section's TODO list) so the v62.72 sprint inherits a complete bug surface.

#### AI Citation Tracker (THE wedge)

| Feature | Free | Pro | Pro+ | Agency | Build status |
|---|---|---|---|---|---|
| AI Citation Tracker | 1 prompt × Perplexity-only × monthly | 1 prompt × 4 engines × weekly | 5 prompts × 4 engines × weekly | 25 prompts × 4 engines × weekly | ⏳ Phase 2 (2-3 weeks) |
| Engines | Perplexity | ChatGPT (BYOK) + Perplexity + Gemini (BYOK) + Google AI Overviews (SerpAPI) | Same | Same | — |
| Cost to Ben (weighted) | ~$0.50/yr/free user | ~$1/mo/Pro user | ~$2/mo/Pro+ user | ~$3/mo/Agency user | — |

#### Bulk

| Feature | Free | Pro | Pro+ | Agency | Build status |
|---|---|---|---|---|---|
| Bulk CSV import | ❌ | ❌ | ❌ | ✅ 50/day cap, GEO floor 40, default to draft | ⏳ Phase 1 (5-8 days, UX layer on existing Async_Generator) |
| Cannibalization detector | ❌ | ❌ | ❌ | ✅ | ⏳ Phase 5+ |

#### WooCommerce (Pro add-on, post-launch)

| Feature | Free | Pro | Pro+ | Agency | Build status |
|---|---|---|---|---|---|
| Category Intro Generator | ❌ | ❌ | 5/site lifetime | Unlimited | ⏳ Phase 5+ Pro add-on |
| Product Description Rewriter | ❌ | ❌ | ❌ | Unlimited | ⏳ Phase 5+ Pro add-on |

#### Power features (Agency-only)

| Feature | Free | Pro | Pro+ | Agency | Build status |
|---|---|---|---|---|---|
| White-label (basic — replace logo, hide footer, custom email sender) | ❌ | ❌ | ❌ | ✅ | ⏳ Phase 2 |
| Premium white-label (custom domain, full UI rebrand, whitelisted email) | ❌ | ❌ | ❌ | $99/mo add-on | ⏳ Phase 5+ |
| API access (n8n / Zapier / custom triggers) | ❌ | ❌ | ❌ | ✅ | ⏳ Phase 2 |
| Custom prompt templates per content type | ❌ | ❌ | ❌ | ✅ | ⏳ Phase 5+ |
| Priority support | ❌ | Standard | Standard | 24h SLA + onboarding call | ⏳ Phase 2 |

#### Branding / Misc

| Feature | Free | Pro | Pro+ | Agency | Build status |
|---|---|---|---|---|---|
| "Powered by SEOBetter" footer | ✅ Shown | Removable | Removable | Removable | ✅ Shipped |
| Cloud Credits Add-on (top-up packs) | ❌ | ✅ Optional | ✅ Optional | ✅ Optional | ⏳ Phase 2 build, Phase 3 activate |

---

## 3. Build Roadmap

Each phase lists features + the security work that ships alongside (per `security.md`).

> **Strategy update 2026-05-04 — Plugin-split-first.** The original plan was: build everything with `License_Manager::can_use()` gates, ship as ONE plugin with Pro features hidden behind runtime checks, then split into Free + Pro plugins later. **Replaced** with: build everything in a single Pro codebase (current state — basically done), test, then split into two distributable zips (Free derived by stripping Pro-only files + code blocks). Reasons:
>
> 1. **Runtime gates are bypassable.** A determined attacker (or any modern AI assistant pointed at the codebase) can patch `License_Manager::can_use()` to always return `true` in seconds. The only rock-solid defense is "the Pro feature's code never landed on the free user's server."
> 2. **WordPress.org policy requires it anyway.** Their guidelines disallow "free" plugins that lock features behind license keys — so a separate free plugin is mandatory for WP.org distribution. Doing it in two phases is wasted gating work.
> 3. **Industry standard.** Yoast / Rank Math / Elementor / Beaver Builder / WPBakery all ship Free plugins (in WP.org) and separate Pro plugins (self-hosted). The Free version is genuinely missing features on disk, not gated.
> 4. **Cleaner code.** Without `if ( can_use() )` checks scattered everywhere, the master codebase reads cleanly. Defense-in-depth gating remains useful inside the Pro plugin's internals (multi-tier — Pro vs Pro+ vs Agency) but stops being the primary security mechanism.
>
> The `License_Manager::can_use()` calls already in the code stay as defense-in-depth (still useful for tier separation inside the Pro plugin — Pro vs Pro+ vs Agency). The `SEOBETTER_GATE_LIVE` flag becomes obsolete because the Free plugin physically doesn't ship Pro code.

### Phase 1 — Build complete, test, split-prep (current phase)

**Features (status as of 2026-05-04, v1.5.216.62.33):**

| Order | Task | Status | Notes |
|---|---|---|---|
| 1 | Canonical URL sync to all 4 SEO plugins | ✅ Shipped | `seobetter.php:2851-2935` — Yoast, RankMath, AIOSEO, SEOPress all wired |
| 2 | ~~License gating wire-up + `SEOBETTER_GATE_LIVE` test-mode flag~~ | ✅ Shipped (now defense-in-depth only — strategy pivot) | `License_Manager.php` ships; `can_use()` calls retained as Pro-internal tier separator (Pro vs Pro+ vs Agency); GATE_LIVE flag deprecated since the Free plugin won't have Pro code at all |
| 3 | GSC integration (OAuth + Manager + dashboard data) | ✅ Shipped | `GSC_Manager.php` + cloud-api OAuth proxy v62; data feeds Freshness panel |
| 4 | Freshness inventory MVP + Pro+ "Why?" diagnostic + Editor sidebar | ✅ Shipped (v1.5.216.54) | `Content_Freshness_Manager.php` |
| 5 | Internal Links — Orphan report Free + Pro+ suggester view | ✅ Shipped | `Technical_SEO_Auditor.php::check_orphan_pages()` + `link-suggestions.php`. ⚠️ User reports suggester not returning suggestions — needs debug |
| 6 | Brand Voice generation pipeline integration | ✅ Shipped | `Async_Generator.php:227` threads `brand_voice_id` into system prompt |
| 7 | SEOBetter Score 0-100 composite | ✅ Shipped | `includes/Score_Composite.php` |
| 8 | Rich Results validation preview surfacing | ✅ Shipped + extensively polished v62.20-62.33 | 2-state model · 13 honest tiles · 21-platform video detection · per-tile action hints · Schema Block cards inline · honest stats removed · LLM citation score with verifiable signals |
| 9 | Bulk CSV UX layer (presets + Action Scheduler queue + GEO 40 floor + default-to-draft) | ✅ Shipped | `Bulk_Generator.php` + `bulk-generator.php` (presets, save/delete handlers, Agency gate) |
| 10 | 5 Schema Blocks (Product / Event / LocalBusiness / VacationRental / JobPosting) | ✅ Shipped (v62.28-62.33) | Native Gutenberg blocks · MediaUpload picker · readable enum labels · 66-currency searchable dropdown · 89-country picker · in-block Save post button · Pro+ registration gate |
| 11 | Country allowlist split (Free 6 / Pro+ 80+) | ✅ Shipped | `content-generator.php:247-292` — 🔒 lock badges + upsell modal |
| 12 | llms.txt rewrite + `/llms-full.txt` + caching + multilingual | ✅ Shipped | `LLMS_Txt_Generator.php` — Pro/Pro+/Agency tiers, GEO-floor filtering, multilingual variants |
| 13 | Settings.php restructure into 6 tabs | ✅ Shipped (v1.5.216.32) | License & Account / AI Provider / General / Author Bio / Branding / Research & Integrations — all 6 tabs deep-linkable via `?tab=` |
| 14 | Brand Voice profile section UI | ✅ Shipped | Settings → Branding tab |
| 15 | License tier display logic (precise vs shown labels) | ✅ Shipped | `License_Manager.php` |
| 16 | Tier-aware UI gating (AI Image / Tavily / etc. lock badges) | ✅ Shipped | `License_Manager::can_use()` calls in 6+ admin views with 🔒 badges |
| 17 | License & Account tab dashboard | ✅ Shipped | tier badge + site usage + Cloud usage + upsell grid in Settings tab |
| 18 | AI Crawler Access audit (GPTBot / ClaudeBot / PerplexityBot / etc.) | ✅ Shipped | `includes/AI_Crawler_Audit.php` |
| 19 | Dashboard restructure (3-tier comparison grid) | ✅ Shipped | `dashboard.php:268-288` |
| 20 | Recent Articles SEOBetter Score column | ✅ Shipped | `seobetter.php:5164,5231` |
| 21 | Generate Content page sweep (🔒 lock badges on 18 non-free types) | ✅ Shipped | `content-generator.php:270,447` |
| 22 | Bulk Generate page tier fix (Agency $179/mo) | ✅ Shipped | `bulk-generator.php:136` |

**Phase 1 build = effectively complete.** What remains is testing and split-prep, not new feature work.

### Phase 1 remaining — Test gate + plugin split prep

| Order | Task | Effort | Status |
|---|---|---|---|
| T1 | **Internal Links suggester debug** — user reports Pro+ in-editor suggester doesn't return suggestions. Investigate `class Internal_Links_Suggester` (or wherever the suggester logic lives), test against orphan + non-orphan posts, fix and verify against test article. | 0.5-1 day | ⏳ Pending — first task tomorrow |
| T2 | **3 launch articles at GEO 90+** (per pro-plan-pricing.md §6): how-to dog raw food, best washable dog beds AU listicle, best Melbourne dog food delivery listicle | 1-2 days each + iterate | ⏳ Pending |
| T3 | **All 21 content type smoke test** — generate one article per content type, verify it completes, schema validates in Google Rich Results Test, no PHP errors | 2-3 days | ⏳ Pending |
| T4 | **Bulk Generate end-to-end test** — upload CSV of 5-10 keywords, verify presets save/load, Action Scheduler queue runs, GEO 40 floor blocks low-quality drafts, default-to-draft saves correctly | 0.5 day | ⏳ Pending |
| T5 | **Freshness inventory test** — verify age-based loads, Pro+ "Why?" diagnostic shows GSC click decay + position drift, editor sidebar mirrors metabox panel | 0.5 day | ⏳ Pending |
| T6 | **5 Schema Blocks live render test** — insert each Pro+ block in a real post, fill required fields, verify card renders in editor + on front-end, JSON-LD validates in Schema.org Validator + Google Rich Results Test | 1 day | ⏳ Pending |
| T7 | **AI Featured Image** — verify all 7 style presets work across English / Portuguese / German / Japanese | 0.5 day | ⏳ Pending |
| T8 | **AI Crawler Access audit** — confirm robots.txt scan finds blocked bots, one-click fix updates robots.txt correctly | 0.25 day | ⏳ Pending |
| T9 | **llms.txt + /llms-full.txt** — verify both endpoints serve, transient cache invalidates on post save | 0.25 day | ⏳ Pending |
| T10 | **Multilingual gen smoke** — generate 1 article in each of 10+ languages (en/es/fr/de/it/pt/ja/ko/zh/ar) | 1-2 days | ⏳ Pending |

**Plugin split prep (replaces old "Phase 2 Layer 3 split"):**

| Order | Task | Effort | What it does |
|---|---|---|---|
| P1 | **Inject button cleanup (S3 → P1)** — remove `Analyze & Improve inject buttons` code entirely from codebase. Locked NO per §7. | 1 day | Code hygiene |
| P2 | **Mark Pro-only files** — annotate `Schema_Blocks_Manager.php`, `Schema_Blocks_Registry.php`, `Brand_Voice*` (when shipped), `Bulk_Generator.php`, `Citation_Tracker.php`, `Content_Freshness_Manager.php` (Pro+ portions), `LLMS_Txt_Generator.php` (Pro+ multilingual portions), the AI Featured Image generator, etc. with `// @pro:start` … `// @pro:end` markers | 1 day | Marks the strip boundaries |
| P3 | **Build the strip script** — bash script that copies `seobetter/` to `build/seobetter-free/`, deletes Pro-only files, removes `// @pro:start … // @pro:end` blocks via sed, rewrites the plugin header (Plugin Name → "SEOBetter (Free)", Plugin URI), removes Pro-only menu items, generates the free zip. Yoast / Rank Math / Elementor all use this pattern | 2-3 days | One commit → two zips |
| P4 | **`do_action()` hook stubs (S1 → P4)** — In the Free plugin, leave hook stubs where Pro features used to fire. The Pro plugin (loaded as a separate WP plugin) registers handlers on those hooks. Cracking the Free plugin unlocks zero Pro capability because the Pro logic doesn't exist there. | 3 days | Wires Free + Pro plugin pair |
| P5 | **Cloud API license verification (S2 → P5)** — every Pro endpoint validates Freemius license, refuses on invalid. Defense-in-depth even if a customer extracts the Pro plugin code; the Cloud API still won't serve them | 2 days | Server-side defense |
| P6 | **Free-plugin smoke test** — install only the generated free zip on a clean WP, verify all Free features work, no Pro features visible, no broken pages | 0.5 day | Validates the strip |
| P7 | **Pro-plugin install test** — install Free zip + Pro zip, verify Pro features activate cleanly via the do_action stubs | 0.5 day | Validates the wiring |

### Phase 1.5 — Sell-ready infrastructure (week 3 — UNLOCKS REVENUE)

After test gate (week 1) and plugin split (week 2), one more week to start charging:

| Order | Task | Effort | Notes |
|---|---|---|---|
| L1 | **Freemius SDK integration** — drop-in. Adds activation opt-in flow (Touchpoint 1 of `email-marketing.md` §2 — 40-60% conversion), license-key delivery, subscription billing, GDPR-compliant unsubscribe. Wraps the existing `License_Manager` checks. | 2-3 days | Single biggest unlock — Freemius handles email capture + delivery + GDPR for free |
| L2 | **Activation opt-in copy** — write the 1 sentence that goes in Freemius's activation prompt: "Allow SEOBetter to collect diagnostic data and send you weekly tips on getting higher GEO scores?" Skippable, plugin works 100% normally if declined. | 0.5 day | Per `email-marketing.md` §2 Touchpoint 1 |
| L3 | **seobetter.com pricing page + Stripe Checkout** — landing page with 4-tier comparison grid (Free / Pro / Pro+ / Agency), Stripe Checkout for direct sales (Freemius takes a cut; direct Stripe at this stage = higher margin per founder customer), automated post-payment Pro plugin zip delivery via email | 2-3 days | Marketing site work; doesn't block launch but enables it |
| L4 | **Email capture in plugin (Touchpoint 2 + 3)** — non-blocking banners after first article success and after Optimize All success. Email field + opt-in checkbox. Sends to Freemius mailing list segment. Show once, dismiss permanently. Per `email-marketing.md` §2 Touchpoints 2-3. | 1-2 days | Optional for first sales (Freemius activation opt-in catches most users); ship as Phase 2 if time-pressed |
| L5 | **Founder customer outreach** — WordPress Facebook groups, Reddit r/SEO + r/IndieHackers, IndieHackers post, cold email to 10-20 SEO bloggers (offer free Pro for review). Per `pro-plan-pricing.md` §7B First-20-Users Playbook. | ongoing | The actual selling work |

### Phase 1 remaining timeline & cost analysis

| Week | Milestone | Cost in (your spend) | Revenue out |
|---|---|---|---|
| 1 | Test gate green (T1-T10) | $50 fixed infra + ~$10 API testing | $0 |
| 2 | Plugin split done (P1-P7) | $50 fixed infra | $0 |
| 3 | Freemius wired + pricing page live (L1-L3) | $50 fixed + ~$30 Freemius setup costs | $0 |
| 4 | First 5 founder buyers ($99/yr each) | $50 fixed | **+$495 first revenue** |
| 5-6 | Founders 6-15 + ongoing outreach (L5) | $50 fixed | **+$990 cumulative** |
| 7-9 | AppSumo application + first 30 organic Pro users | $50-80 fixed (more API for trial users) | **+$3-4K cumulative** ($99/yr founders + $39-69/mo regulars) |
| 10-14 | AppSumo LTD launch | $80-150 fixed (LTD users testing) | **+$15-20K LTD payout** (after AppSumo cut) |
| 15-18 | WP.org submission + free plugin live | $80-150 fixed | **slow MRR ramp begins** (~$700 new MRR/mo per `email-marketing.md` §8 math) |

**Year 1 totals (realistic):**
- Costs: ~$1,000 fixed infra + ~$7,500 ongoing API for paying users = **~$8,500**
- Revenue: ~$1,500 founders + ~$3,000 Freemius regulars + ~$20,000 AppSumo + ~$5,000 first 6mo MRR ramp = **~$30,000**
- **Net Year 1: ~$20,000.** Bootstrappable.

### "Sell-ready" milestone — when to alert Ben

The plugin is sell-ready when:
- ✅ Test gate items T1-T10 all pass (no PHP errors on 21 content types; 3 launch articles GEO 90+; Schema Blocks render + validate; Bulk + Freshness + Internal Links + AI Crawler + llms.txt all work end-to-end)
- ✅ Plugin split items P1-P7 all complete (Free zip strips correctly; Pro plugin activates against Free plugin; Cloud API rejects unlicensed Pro requests)

When BOTH gates pass, Claude reports: **"v62.x is sell-ready. Recommend: Day 1 build seobetter.com pricing page + Stripe Checkout, Day 2 wire Freemius SDK, Day 3 send the first 5 founder DMs. First revenue within ~7 days from this alert."**

This is the trigger — at that point, code work pauses and outreach begins.

### Email infrastructure — split between Freemius (free) and plugin code

Per `email-marketing.md` §9 Implementation Order and `automated-emails.md` §4-6:

| Phase | Email work | Source |
|---|---|---|
| **Phase 1.5 (sell-ready)** | Activation opt-in only — Freemius default, ~5 min config (L2 above) | Freemius built-in |
| **Phase 2 (post-AppSumo)** | In-app capture banners (L4) + Sequence A 7-email Free Onboarding drip | Plugin code (L4) + Freemius email templates |
| **Phase 3 (1000+ users)** | Sequence B Trial Activation (5 emails) + behavioral triggers (article hits 90+, Optimize All success) | Freemius behavioral triggers |
| **Phase 4 (5000+ users)** | Sequence C Win-Back + Pro feature alerts (AI Citation Tracker matches) | Custom `Email_Router.php` per `automated-emails.md` §4.2 |
| **Phase 5+** | Full custom email pipeline (`Email_Router`, `Email_Templates`, `Email_Event_Log`, `Email_Preferences`) IF outgrowing Freemius email | Custom build |

**Key insight: NO custom email infrastructure required for first revenue.** Freemius SDK ships activation opt-in + transactional emails (purchase receipt, license key delivery, password reset, etc.) for free. Plugin only needs to enable the opt-in checkbox and write the activation copy. **Don't build Email_Router until Phase 4+** — premature for solo founder.

WordPress.org compliance (per `email-marketing.md` §6):
- ✅ No required email to use plugin (BYOK works without any account)
- ✅ Activation opt-in is non-blocking + skippable + dismissed permanently
- ✅ One-click unsubscribe (Freemius default)
- ✅ Privacy policy link during opt-in (seobetter.com/privacy already live)
- ✅ Diagnostic data collection only with consent

These are already designed-in — Freemius handles them automatically once integrated. No additional work needed for WP.org acceptance.

### Sequencing — the actual order to execute

1. **Tomorrow (Day 1):** T1 (fix Internal Links bug) + T6 (Schema Blocks smoke test)
2. **Days 2-5:** T2 (3 launch articles GEO 90+) → T3 (21-content-type smoke)
3. **Days 6-8:** T4 (Bulk) → T5 (Freshness) → T7-T10 (AI Image / AI Crawler / llms.txt / multilingual smoke)
4. **Days 9-12:** Plugin split P1-P7
5. **Days 13-15:** Freemius L1 + opt-in copy L2 + seobetter.com pricing L3
6. **Day 15+: SELL.** Founder outreach L5 — first revenue target Day 21
7. **Week 6+:** AppSumo application prep + ongoing organic outreach
8. **Week 10-14:** AppSumo launch
9. **Week 16-18:** WP.org submission

**Compressed timeline = ~3 weeks build + sell from there.**

### Excluded from launch (deferred to Phase 5+ to ship faster)

Per the cross-check of `pro-plan-pricing.md` tier features against shipped reality (2026-05-04):

| Feature | Original tier | Decision |
|---|---|---|
| AI Citation Tracker | Pro/Pro+/Agency | **Defer to v1.5.217 (post-revenue, ~30 days post-launch).** Marketing copy: "Coming Q3 2026 — included in your tier from launch day." Build cost: 2-3 weeks. Don't burn revenue runway building it pre-launch. |
| WooCommerce Category Intros / Product Description Rewriter | Pro+ / Agency | Defer to Phase 5+. Add-on product, not launch-tier feature. |
| Cannibalization detector | Agency | Defer to Phase 5+. |
| Refresh-brief generator | Agency | Defer to Phase 5+. Locked-NO on auto-rewrite per §7. |
| GSC Indexing API | Agency | Defer to Phase 5+. |
| Auto-linking rules (Link Whisper-style) | Agency | Defer to Phase 5+. |
| Custom prompt templates per content type | Agency | Defer to Phase 5+. |
| White-label basic | Agency | **Build in Phase 2 alongside Freemius (~3 days).** Required for agency-tier credibility at AppSumo launch. |
| API access (n8n / Zapier) | Agency | **Build in Phase 2 (~3 days).** Same reasoning. |
| 5 team seats | Agency | **Verify Freemius supports it (likely yes by default).** No custom build needed. |
| Free tier feature list update | Free | **Update `pro-plan-pricing.md` §2** to reflect what shipped (SEOBetter Score, Rich Results preview, GSC connect, Internal Links orphan, age-based Freshness, AI Crawler audit, basic llms.txt, Yoast/RankMath/AIOSEO/SEOPress meta sync, canonical URL sync). Drop "Recipe + Organization + Person" from Free schema list — those are Pro per the tier matrix. |

### Phase 2 — Freemius integration & plugin split (~3 weeks)

**Features + infrastructure:**

| Task | Effort |
|---|---|
| Integrate Freemius SDK (license keys, subscription billing, trial management, refunds, analytics) | 2-3 days |
| Wire 7 contextual upgrade CTAs (per pro-plan-pricing.md §8) | 3 days |
| Set up [seobetter.com](https://seobetter.com) pricing page with annual toggle | 1 day |
| Beta users (Phase 1 founder-pricing buyers) migrated to Freemius as grandfathered Pro accounts | 1 day |
| Cloud Credits backend + UI ($19/$49/$129 packs; balance counter; one-click top-up) | 3 days |
| White-label (basic) implementation — logo upload, footer toggle, email sender override | 3 days |
| API access endpoint (n8n/Zapier-compatible auth + REST routes) | 3 days |
| 24h priority support inbox setup | 1 day |
| **AI Search Readiness Score 0-100** — composite score (Crawler access 15% + AI bot activity 20% + Schema completeness 20% + Citation extractability 15% + llms.txt + sitemap submitted 15% + Freshness signals 15%). Surfaces alongside GEO score per-article + site-wide dashboard. | 1-2 days |
| **AI Bot Activity Tracker** — server-side User-Agent logging in custom WP table (`{prefix}_seobetter_ai_bot_log`). Dashboard chart per-bot per-day. Pro+: last 30 days. Agency: last 12 months + per-site filtering. Pairs with AI Citation Tracker as the input/output sides of the wedge ("Did AI engines READ your content? Did they CITE it?") | 2-3 days |
| **Engine submission checklist** — Settings UI listing Bing Webmaster Tools / GSC / Perplexity / ChatGPT search registration with status indicators. Free shows static checklist; Pro+ adds auto-status checks (ping each engine for indexed-by status). | 0.5 day |
| ✅ **SHIPPED v1.5.216.62 — Centralized GSC OAuth proxy** — Code complete on both Cloud API (`cloud-api/api/gsc-oauth/{start,callback,exchange,refresh}.js` + `privacy.js` + `terms.js`) and plugin (`GSC_Manager::use_proxy()` defaults to true; `is_oauth_configured()` returns true unconditionally in proxy mode; `build_auth_url()`, `handle_oauth_callback()`, `refresh_access_token()` all dispatch on proxy vs BYO). Settings UI now shows simple "verify in GSC + Connect" flow by default, full GCP setup hidden in `<details>` Advanced toggle. **Remaining manual steps for full launch:** (1) Set Cloud API env vars: `SEOBETTER_GSC_CLIENT_ID`, `SEOBETTER_GSC_CLIENT_SECRET`, `SEOBETTER_GSC_REDIRECT_URI`, `GSC_OAUTH_HMAC_SECRET`, plus existing `UPSTASH_REDIS_REST_*`. (2) Configure Google Cloud OAuth consent screen with privacy URL `<cloud-api>/api/privacy` + terms URL `<cloud-api>/api/terms`. (3) Submit for Google verification — typical wait 7-30 days for non-sensitive `webmasters.readonly` + `userinfo.email` scopes. During wait, beta users added as Test Users bypass the warning. See BUILD_LOG entry for v1.5.216.62 for full deployment guide. | shipped — verification pending Google review |

**Security work (the big one):**

| Order | Task | Effort | Layer |
|---|---|---|---|
| S4 | **Layer 3 plugin split** — extract ALL Pro PHP into separate `seobetter-pro` codebase. Free plugin (`seobetter`) ships ONLY free-tier code + hook stubs. Cracking the free plugin unlocks NOTHING. | 1-2 weeks | Layer 3 |
| S5 | Freemius Bundle Generator setup — automatic build of `seobetter-pro.zip` from Pro codebase | 1 day | Layer 3 |
| S6 | **Pre-launch security audit** — verify Layers 1-3 all pass: HMAC signing, SSRF prevention, rate limits, cost caps, server-side license verification, plugin split (cracked free plugin = zero Pro capability) | 2-3 days | Audit |

### Phase 3 — AppSumo Lifetime Deal launch (week 7-14)

**5-tier LTD ladder activated** (see §5 below for cap details).

**Cloud Credits activated publicly** — LTD buyers exceeding their lifetime Cloud cap can buy credit packs to top up.

**No new feature work** — this is a launch promotion using already-built infrastructure.

### Phase 4 — WordPress.org directory submission (~1 week prep + 7-14 days approval)

**Features:** none — submission only.

**Security & compliance work:**

| Task | Effort |
|---|---|
| WP.org compliance audit — proper escaping, sanitization, no hardcoded external calls in free plugin, GPL2+ compatible, capability checks on every admin route | 3-5 days |
| Final external security review (paid, ~$500-1500 via WPSec / Patchstack / similar specialist) | 1 week elapsed, ~6h Ben's time |
| Build the WP.org directory listing — hero video, 8 screenshots, benefit-led description, FAQ | 1 week |
| Prepare 3 launch blog posts on seobetter.com (per `article-marketing.md` Top-10 keywords) | 1 week |

### Phase 5+ — Post-revenue (months 5-12+)

**Features (priority order, ship one per release):**

| Task | Tier |
|---|---|
| Refresh-brief generator (side-by-side diff suggestions) | Agency |
| GSC Indexing API integration | Agency |
| Cannibalization detector | Agency |
| Custom prompt templates per content type | Agency |
| Outdated-stat LLM detection in Freshness | Pro+ |
| Internal Links unlimited + auto-linking rules (Link Whisper-style) | Agency |
| Premium white-label add-on ($99/mo) — custom domain, full UI rebrand, whitelisted email | Agency add-on |
| **WooCommerce Pro add-on** (Category Intro Generator + Product Description Rewriter) | Pro+ Intros (5/site); Agency unlimited + Rewriter |
| **Events Calendar Pro add-on** (Event blocks + Event schema enrichment) | Pro+ |
| **WP Job Manager Pro add-on** (Job Posting blocks + JobPosting schema) | Pro+ |
| Phase 6: 3 free Cloud articles/mo to free tier (only if MRR > $8K/mo) | Free |
| Per-page AI bot heatmap — which articles get hit by which bots; correlate with Citation Tracker | Pro+ |
| Server-level AI crawler optimization — Cache-Control / Last-Modified / ETag tuning for AI crawler patterns | Agency |
| llms.txt format auto-update — when OpenAI/Google/Perplexity announce adoption, plugin auto-updates llms.txt format if spec evolves (monitors llmstxt.org for spec changes; safe migration of existing user customizations) | All |

**Security work:**

| Task | Layer |
|---|---|
| Layer 4 — UUID install fingerprinting (Freemius tracks UUID + URL + license + activation timestamp; duplicate UUIDs auto-flag) | Layer 4 |
| Layer 4 — Plugin self-hash + tamper detection (`X-Seobetter-Hash` header on every cloud-api request) | Layer 4 |
| Layer 4 — Runtime license pings (cloud-api refuses if license-not-pinged > N hours) | Layer 4 |
| Layer 4 — License-used-on-too-many-sites auto-downgrade | Layer 4 |

---

## 4. Security & Anti-Tamper Roadmap

This summarizes `security.md` — the canonical 4-layer plan. Every Pro feature ships with its security layer alongside.

### Layer 1 — Vercel endpoint hardening ✅ SHIPPED v1.5.211-212

| Component | Status |
|---|---|
| HMAC request signing | ✅ |
| Origin validation | ✅ |
| SSRF prevention | ✅ |
| Input sanitization | ✅ |
| Rate limiting (Upstash) | ✅ |
| Cost circuit breaker (Serper / Firecrawl / OpenRouter / Anthropic / Groq) | ✅ |
| Environment variable hygiene | ✅ |

### Layer 2 — Defense-in-depth (Pro plugin internals only)

**Strategy pivot 2026-05-04 — demoted from primary defense to defense-in-depth.** Originally Layer 2 was the primary security mechanism with `License_Manager::can_use()` checks at every Pro feature route. Plus runtime gating via `SEOBETTER_GATE_LIVE` flag.

That approach is bypassable in seconds — find the gate function, change `return false` to `return true`, save. Modern AI assistants can do this faster than the developer who wrote the gate.

**New role for Layer 2:** the existing `License_Manager::can_use()` calls remain inside the Pro plugin as a tier separator (Pro vs Pro+ vs Agency). They are NO LONGER the primary defense — that's now Layer 3 (plugin split, promoted to Phase 1).

**Rule 1 — Pro features must execute server-side.** Where possible. Every Pro endpoint on the cloud API verifies the Freemius license before executing. A cracked plugin still can't get cloud-side behavior because the code lives behind the API.

**Rule 2 — License verification via Freemius.** Plugin sends license key with every cloud-api request. Cloud API validates against Freemius API; refuses on invalid. Cached for 1h with revocation honored. Belt-and-braces alongside the plugin split.

### Layer 3 — Plugin split (PHASE 1 — promoted from Phase 2)

**This is now the primary security mechanism.** Per the strategy pivot in §3, the Free + Pro plugins are physically separate codebases, distributed separately. Cracking the Free plugin reveals NO Pro code because the Pro code never landed in the free zip in the first place.

**Free plugin `seobetter` — WordPress.org**:
- Unobfuscated PHP (WP.org rule)
- Free tier features ONLY: 3 content types (blog_post / how_to / listicle), basic schema (Article + FAQPage + BreadcrumbList), GEO Analyzer, Pexels stock, GSC connect, country allowlist 6 EN-only, basic llms.txt, AI Crawler Access audit, SEOBetter Score 0-100, orphan-pages report, age-based Freshness, Rich Results validation preview (read-only), Yoast/RankMath/AIOSEO/SEOPress meta sync, footer link
- Contains `do_action()` hook stubs where Pro features used to fire
- **No Pro logic on disk** — even if cracked, zero Pro capability unlocked

**Pro plugin `seobetter-pro` — Freemius distribution only (NOT WP.org)**:
- Distributed via Freemius downloads after purchase
- Contains all Pro/Pro+/Agency PHP: 5 Schema Blocks render_callbacks, Brand Voice logic, AI Citation Tracker, Bulk CSV UX, AIOSEO full schema, Places Pro tiers, Internal Links Pro+ suggester, GSC Freshness driver, multilingual llms.txt, /llms-full.txt, AI Featured Image generator, all 80+ countries, all 21 content types, Brave Search, Firecrawl, Serper SERP intelligence, Multilingual gen 60+ langs, Cloud Credits backend, etc.
- Hooks into free plugin's `do_action` stubs
- Internal `License_Manager::can_use()` calls separate Pro vs Pro+ vs Agency tier features

**Build tooling — `build/strip.sh`**:
- Bash script that copies `seobetter/` → `build/seobetter-free/`
- Deletes Pro-only files (Schema_Blocks_*, Brand_Voice*, Bulk_Generator, Citation_Tracker, etc.)
- Removes `// @pro:start … // @pro:end` blocks from shared files via `sed`
- Rewrites the plugin header (Plugin Name, Plugin URI) for the free version
- Generates `seobetter-free.zip` for WP.org and `seobetter-pro.zip` for Freemius
- Yoast / Rank Math / Elementor all use this pattern

**User flow:**
1. Install free plugin from WP.org → gets free tier
2. Buy Pro/Pro+/Agency via Freemius → Freemius emails Pro plugin ZIP download
3. Upload Pro plugin ZIP via Plugins → Add New → Upload → activates as a separate plugin
4. Free plugin detects companion via `function_exists()` check on a Pro plugin loader → Pro features activate via the `do_action` stubs

### Layer 4 — Anti-tamper + fingerprinting (Phase 5+)

| Component | What it does |
|---|---|
| UUID install fingerprinting | Plugin generates UUID on activation; Freemius tracks UUID + URL + license + timestamp. Duplicate UUIDs across different licenses = auto-flag. |
| Plugin self-hash | Plugin computes SHA256 of its PHP files on load; sends `X-Seobetter-Hash` header on every cloud-api request. Hash mismatch = tamper detected. |
| Runtime license pings | Cloud API refuses if license-not-pinged > 24h (prevents air-gapped license sharing). |
| License-used-on-too-many-sites | Auto-downgrade to free tier when site count exceeds entitlement. |

### Pre-launch security gate (BLOCKER)

⚠️ **Do NOT ship Freemius gating or accept payments until ALL of these pass:**

1. Layer 1 audit: HMAC signing works, rate limits hold under stress test, cost caps trigger at expected thresholds
2. Layer 2 audit: every Pro endpoint refuses unauthenticated requests; license validation cached + revocation respected
3. Layer 3 audit: cracked free plugin (license check removed manually) has ZERO Pro capability — confirmed by trying every Pro feature route
4. WP.org compliance review: escaping, sanitization, no hardcoded URLs, GPL2+, capability checks
5. External paid security review: ~$500-1500 by WPSec / Patchstack / similar — catches issues self-audit misses

---

## 5. AppSumo LTD Structure

**Goal:** $89,500 gross / ~$62,650 net cash injection. 500 buyers × weighted-average $179. Lifetime support sustainable because of caps + cheap-config-only enforcement.

### 5-tier ladder

| Tier | Price | Sites | Seats | Cloud articles/mo (lifetime cap) | Equivalent subscription tier |
|---|---|---|---|---|---|
| Tier 1 | **$69** | 1 | 1 | 5 | Free++ |
| Tier 2 | **$129** | 3 | 1 | 15 | Pro features for life |
| Tier 3 | **$249** | 5 | 1 | 30 | Pro+ features for life |
| Tier 4 | **$349** | 10 | 5 | 75 | Agency features for life |
| Tier 5 | **$499** | 25 | 5 | 150 | Agency+ for life (incl. premium WL) |

### Vendor-protection rules (mandatory)

| Protection | Mechanism |
|---|---|
| **BYOK unlimited at every tier** | User connects own AI key → unlimited generation → $0 cost to Ben |
| **Cheap config FORCED for Cloud articles** | gpt-4.1-mini extractions only (~$0.013/article). Premium config (Sonnet/Opus) gated to subscription tiers + credit packs only |
| **Hard monthly Cloud caps** | Tier exceeds → must use BYOK or buy Cloud Credit packs; cannot overflow |
| **Cloud Credit pack stacking** | Available to LTD buyers — additional revenue stream from heavy users |
| **Premium WL gated to Tier 5** | Custom domain + full UI rebrand requires DNS+DKIM support burden — Tier 5 buyers expect that level of service |

### Margin sanity check

5-year lifetime exposure at full Cloud cap usage (cheap config only):

| Tier | Net to Ben (after AppSumo 30%) | 5yr cost | 5yr profit | Margin |
|---|---|---|---|---|
| Tier 1 ($69) | $48.30 | $3.90 | $44.40 | **92%** |
| Tier 2 ($129) | $90.30 | $11.70 | $78.60 | **87%** |
| Tier 3 ($249) | $174.30 | $23.40 | $150.90 | **87%** |
| Tier 4 ($349) | $244.30 | $58.50 | $185.80 | **76%** |
| Tier 5 ($499) | $349.30 | $117.00 | $232.30 | **67%** |

All tiers maintain ≥67% profit margin even at full lifetime usage. Sustainable.

### Buyer feature unlocks per tier

**Tier 1 ($69)** — 1 site · 5 Cloud/mo · BYOK unlimited · 3 content types · basic schema · GSC connect · Pexels images

**Tier 2 ($129) = Pro for life** — All 21 content types · Multilingual · AI Featured Image · Brand Voice (1) · AI Citation Tracker (1 prompt × 4 engines × weekly) · 15 Cloud/mo · 3 sites

**Tier 3 ($249) = Pro+ for life** — Adds: 3 Brand Voices · GSC-driven Freshness · Internal Links suggester · WooCommerce Category Intros · AI Citation Tracker (5 prompts) · 30 Cloud/mo · 5 sites

**Tier 4 ($349) = Agency for life** — Adds: Bulk CSV (50/day, quality gate) · Cannibalization · Refresh-brief · GSC Indexing API · Basic WL · API access · AI Citation Tracker (25 prompts) · 75 Cloud/mo · 10 sites · 5 seats

**Tier 5 ($499) = Agency+ for life** — Adds: Premium WL (custom domain + full UI rebrand) · 25 sites · dedicated support · 150 Cloud/mo

---

## 6. Cloud Credits Add-on

**Purpose:** capture users who want extra Cloud articles beyond their tier's monthly cap, without forcing a tier upgrade.

### Pack pricing

| Pack | Price | Articles | $/article | Cost to Ben | Margin |
|---|---|---|---|---|---|
| Starter | $19 | ~50 | $0.38 | $6.50 | 66% |
| Creator | $49 | ~150 | $0.33 | $19.50 | 60% |
| Agency | $129 | ~500 | $0.26 | $65.00 | 50% |

### Build & launch timing

| Phase | Cloud Credits status |
|---|---|
| **Phase 1** (beta, 20 users) | Not yet — beta users on $99/yr founder pricing get full Pro Cloud quota |
| **Phase 2** (Freemius integration) | **Build the backend + UI** — pack purchase, balance tracking, debit on Cloud article generation |
| **Phase 3** (AppSumo launch) | **Activate publicly** — LTD buyers exceeding lifetime Cloud cap can buy packs |
| **Phase 4-5** (WP.org + MRR scale) | Standard offering — credit packs available to all paid tiers as overage option |

### UX

- Credit balance always visible in plugin top bar: `❇ 43 credits`
- One-click top-up modal when balance < 10
- Per-generation success card shows real spend: `"This article cost 1 credit / $0.38 — Pro saves you 30%"`

### Mirror

GitHub Copilot + Cursor's credit-pack model — dominant 2026 AI-tool pricing pattern per [Schematic HQ 2026](https://schematichq.com/blog/software-monetization-models).

---

## 7. The "Don't Build" List (locked NOs)

These were considered and explicitly rejected. Do not bring them back without clear new evidence.

| Item | Why we're not building |
|---|---|
| ❌ Native rank tracking | Every solopreneur already has Ahrefs/SEMrush/RankMath; commodity feature; massive data cost; off-wedge |
| ❌ Backlink analysis | Massive data costs; off-wedge; scope creep; specialized tools (Ahrefs/Majestic) own this category |
| ❌ AI chatbot for site visitors / on-site search | Different product, different buyer (e-commerce vs content sites), infrastructure tarpit |
| ❌ Newsletter blocks / email capture Pro | We're a content-generation tool, not a marketing-funnel tool. Out of scope. |
| ❌ Inject buttons (Analyze & Improve) | Removed entirely from codebase — not just degated. Was a feature without clear value vs full content regeneration. |
| ❌ Auto-publish bulk articles (no draft) | Reputation risk — CAS-style spam patterns. Default to draft + quality gate (GEO ≥ 40, < 40 = rejected). |
| ❌ Automatic content updater (autonomous rewrite + publish) | Reputational landmine. LLM rewrites flip meaning of nuanced sentences. Refresh-brief generator (suggest only, human approves) is the sanctioned alternative. |
| ❌ Decay alerts via email | WP.org policy violation (unsolicited email). Killed in v1.5.206d-fix17. |
| ❌ Generic Gutenberg blocks for hand-typed content (FAQ, Key Takeaways, ToC, Pros/Cons, Code, Callouts, Comparison Table) | Already auto-rendered by `Content_Formatter` during generation. Adding standalone blocks defers to user demand post-launch. |
| ❌ Social media post scheduling | 4-8 week build, off-wedge |
| ❌ Email newsletter generation | Off-wedge |
| ❌ Video script generation | Off-wedge |
| ❌ Native A/B testing of titles/meta | 4-8 week build, off-wedge |
| ❌ Ahrefs / Google Analytics integrations | Webhook out instead — don't rebuild what existing tools do |

---

## 8. License Gating Decisions Locked

The 7 features the internal audit (2026-04-29) flagged as "currently free for testing — decide before launch":

| Feature | Decision | Rationale |
|---|---|---|
| `bulk_content_generation` | **Agency only** | High API cost + reputation risk; premium feature anchor |
| `content_brief` | **Pro+ unlimited** (free 3/mo) | Free tease drives habit; Pro+ unlocks scale |
| `citation_tracker` | **Pro/Pro+/Agency tiered (1/5/25 prompts)** | THE wedge — every paid tier gets it; scaled by tier |
| `internal_link_suggestions` | **Pro+ suggester (5/post); Agency unlimited + auto-rules** | Override 2026-04-15 removal lock; Link Whisper proves $77/yr WTP |
| `cannibalization_detector` | **Agency only** | Power-user feature; low free-tier value |
| `freshness_suggestions` | **Free age-based; Pro+ GSC-driven** | Report drives habit; smart prioritization is Pro+ |
| `content_refresh` | **Pro+** | Real cost (research API calls); clearest Pro value |

---

## 9. Pricing Quick Reference

| Tier | Monthly | Annual | Sites | Seats | Cloud articles | Brand Voices |
|---|---|---|---|---|---|---|
| Free | $0 | — | 1 | 1 | BYOK ∞ | 0 |
| Pro | $39 | $349 | 1 | 1 | 50 | 1 |
| Pro+ | $69 | $619 | 3 | 1 | 100 | 3 |
| Agency | $179 | $1,790 | 10 | 5 | 250 | ∞ + per-language |

**Cloud Credit packs (stacks on any paid tier or AppSumo LTD):**
- Starter: $19 / 50 articles
- Creator: $49 / 150 articles
- Agency: $129 / 500 articles

**AppSumo LTD (Phase 3 promotion, 500 buyers cap):**
- Tier 1 $69 · Tier 2 $129 · Tier 3 $249 · Tier 4 $349 · Tier 5 $499

**Phase 1 founder-tier (first 20 beta users only):**
- $99/year locked forever (50% off $349 regular Pro; closes at 20 sold)

**Premium white-label add-on (Phase 5+, Agency only):**
- $99/mo on top of Agency $179

---

## 10. Cross-references

- `pro-plan-pricing.md` — pricing math, unit economics, Phase 1-6 launch sequencing, MRR projections, contextual upgrade CTAs
- `website-ideas.md` §1 — locked positioning + marketing taglines
- `article-marketing.md` — 20-article competitor traffic-stealing plan + 30 keyword targets + 12-week editorial calendar
- `security.md` — 4-layer security architecture (Layer 1 ✅ shipped; Layers 2-3 Phase 1-2; Layer 4 Phase 5+)
- `BUILD_LOG.md` — chronological record of what's actually shipped with file:line anchors
- `plugin_UX.md` / `plugin_functionality_wordpress.md` — UI checklist + technical reference
- `SEO-GEO-AI-GUIDELINES.md` — content generation rules
- `structured-data.md` — schema reference

---

*This file is the authoritative roadmap. When in doubt about whether a feature ships free, Pro, Pro+, or Agency — check §2 (Tier Matrix). When in doubt about when something ships — check §3 (Build Roadmap). When in doubt about whether to build something at all — check §7 (Don't Build List) first.*
