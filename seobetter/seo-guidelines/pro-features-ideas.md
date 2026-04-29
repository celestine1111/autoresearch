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
| Content Refresher (one-click stale-article refresh) | ❌ | ❌ | ✅ | ✅ | ✅ Shipped |
| Refresh-brief generator (side-by-side diff suggestions) | ❌ | ❌ | ❌ | ✅ | ⏳ Phase 5+ |
| GSC Indexing API (request indexing on save) | ❌ | ❌ | ❌ | ✅ | ⏳ Phase 5+ |

#### Internal Links (override the 2026-04-15 removal decision)

| Feature | Free | Pro | Pro+ | Agency | Build status |
|---|---|---|---|---|---|
| Orphan-pages report | ✅ | ✅ | ✅ | ✅ | ⏳ Phase 1 (2 days) |
| Editor sidebar suggester (5 suggestions/post) | ❌ | ❌ | ✅ | ✅ | ⏳ Phase 1 (1 week) |
| Unlimited suggestions + auto-linking rules (Link Whisper-style) | ❌ | ❌ | ❌ | ✅ | ⏳ Phase 5+ |

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

### Phase 1 — Pre-launch (3-4 weeks of dev, then test gate)

**Features:**

| Order | Task | Effort | Tier |
|---|---|---|---|
| 1 | Canonical URL sync to all 4 SEO plugins | 15 min | All |
| 2 | License gating wire-up + **TEST MODE flag** — `License_Manager::can_use()` checks at every feature route. Add `SEOBETTER_GATE_LIVE` constant (default `false` during Phase 1) — when `false`, all gates return `true` so Ben can test ALL Pro/Pro+/Agency features as if licensed. UI lock badges + tooltip copy still render so the upsell experience can be tested. Flag flips to `true` only after Phase 1 test gate passes (item 18 below). | 4 h | — |
| 3 | GSC integration MVP (OAuth + daily cron + view dashboard) | 1 week | Free for connect+view; Pro+ for Freshness driver |
| 4 | Freshness inventory MVP (sortable table; GSC-driven priority for Pro+) | 1 week | Free age-based; Pro+ GSC-driven |
| 5 | Internal Links MVP (orphan report Free; editor suggester Pro+) | 1 week | Free orphan; Pro+ suggester |
| 6 | Brand Voice profiles (upload sample posts + banned-phrase regex pass + system prompt injection) | 2-3 weeks | Pro 1; Pro+ 3; Agency unlimited |
| 7 | SEOBetter Score 0-100 composite (weighted blend of existing GEO layer scores) | 1-2 days | All |
| 8 | Rich Results validation preview surfacing (data exists; needs polish) | 2 days | All |
| 9 | Bulk CSV UX layer (presets + per-row override + Action Scheduler queue + GEO 40 floor + default-to-draft) | 5-8 days | Agency only |
| 10 | 5 Schema Blocks (Product / Event / LocalBusiness / Vacation Rental / Job Posting) | 5-7 days | Pro+ |
| 11 | Country allowlist split — Free = US/UK/AU/CA/NZ/IE (no Regional_Context block fires); Pro+ = full 80+ | 1 day | Free 6; Pro 80+ |
| 12 | **llms.txt rewrite + `/llms-full.txt` + caching** — rewrite `LLMS_Txt_Generator` for content-type categorization + GEO-score filtering + custom summary + language/country signals + FAQ pointers; add `/llms-full.txt` endpoint; transient cache 24h + invalidate on post save; Settings UI for custom summary + regenerate button. **Reinforces the wedge — directly maps to article-marketing.md Top-10 keyword #6 ("llms.txt wordpress").** | 3-5 days | Basic Free; Optimized Pro; full+multilingual+custom Pro+ |
| 13 | **Settings.php restructure into 6 tabs** — License & Account / AI Provider / General / Author Bio / Branding / Research & Integrations. WordPress nav-tab-wrapper pattern, deep-linkable via `?tab=`, save button per tab (existing per-form handlers preserved). Add tier-aware Pro/Pro+/Agency 3-card upsell grid. Remove dead settings (`target_readability`, `geo_engines`). Update all marketing copy to match `pro-features-ideas.md` §2 Tier Matrix. | 2-3 days | All |
| 14 | **Brand Voice profile section** (in Settings → Branding tab) — sample-post uploader (drag-drop or pick from existing posts) + banned-phrase textarea + voice profiles list (1/3/unlimited per tier with quota badge). Persists to `seobetter_brand_voices` option as array. Tier-gated number of voices. | 1 day (UI only — generation pipeline integration is separate Phase 1 task #6) | Pro 1 / Pro+ 3 / Agency unlimited |
| 15 | **License tier display logic** — internal `License_Manager` stores precise license type (`free` / `pro_subscription` / `pro_plus_subscription` / `agency_subscription` / `pro_lifetime` / `pro_plus_lifetime` / `agency_lifetime`) BUT externally displays ONLY: Free / Pro / Pro+ / Agency. AppSumo LTD buyers see the same tier badge as subscription buyers — never an "LTD" or "Lifetime" badge (would deter future paying customers from joining if they see lifetime equivalence shown publicly). LTD attribute affects only billing, hard Cloud cap enforcement, and cheap-config-forced flag — invisible to UI. | 1 day | All |
| 16 | **Tier-aware UI gating** — gate AI Image provider/style preset behind Pro license check with lock badges + upsell tooltips. Tavily field shows Pro lock badge. Places APIs stay free-accessible per Option B (article quality decision: place-citation articles need this; users provide own keys at $0 cost to Ben). | 1-2 days | Pro |
| 17 | **License & Account tab dashboard** — tier badge (Free/Pro/Pro+/Agency) · site usage meter ("1 of 3 sites") · Cloud article usage ("47 of 100 used this month" — for Cloud users only; BYOK shows "Unlimited via your provider") · Cloud Credits balance + Buy Credits button (placeholder until Phase 2 ships Credits backend) · 3-card Pro/Pro+/Agency upsell grid for free users; upgrade-to-next-tier card for current paid users · Annual savings copy in dollars not percent | 2 days | All |
| 18 | **AI Crawler Access audit** — single Settings page check (in Research & Integrations tab): scan robots.txt + meta robots + HTTP X-Robots-Tag headers for blocks against GPTBot / ClaudeBot / PerplexityBot / Bingbot / ChatGPT-User / Google-Extended / CCBot / anthropic-ai. Show pass/fail per bot. One-click fix that updates robots.txt with AI-bot-friendly rules (using the WP `do_robotstxt` filter). Bridge feature for engines that haven't adopted llms.txt yet — protects users from accidental blocks by aggressive WordPress security plugins. | 1-2 days | Free (table-stakes) |
| 19 | **Dashboard restructure** (admin.php?page=seobetter) — header tier badge expanded from binary FREE/PRO to **Free/Pro/Pro+/Agency** · onboarding adds "or skip BYOK with Pro" alternative path · "What You Get" rewrites: Free list aligned with new tier matrix (basic schema = Article+FAQPage+BreadcrumbList only — REMOVE Recipe/Organization/Person from free list) · ADD missing Free features: SEOBetter Score 0-100, Rich Results preview, basic meta sync, GSC connect+view, Internal Links orphan, age-based Freshness, AI Crawler audit, basic llms.txt · REPLACE single Pro upsell with **3-tier comparison grid** (Pro/Pro+/Agency cards) for free users · tier-aware "next-tier upgrade" card for paid users · Remove "inject buttons" line · Fix "Premium tier LLM Claude Sonnet 4.6" → "50 Cloud articles/mo using SEOBetter research stack" · Fix "Auto-translate 29 languages" → "Multilingual generation 60+ languages" · Clarify "AIOSEO/Yoast/RankMath auto-population" → "AIOSEO full schema sync" (basic meta sync to all 4 plugins is Free) · ADD missing Pro features to upsell: AI Citation Tracker (1/5/25 prompts by tier), Brand Voice (1/3/unlimited), Country localization 80+, Brave Search, inline citations, auto-detect schemas. | 2-3 days | All |
| 20 | **Recent Articles columns** (dashboard) — add SEOBetter Score 0-100 column alongside existing GEO score column (item 7 dependency); placeholder slots for Phase 2 columns (AI Citations badge, AI Readiness mini-score). | 0.5 day | All |
| 21 | **Generate Content page sweep** (?page=seobetter-generate) — add 🔒 Pro lock badges to 18 non-free content types in the dropdown (Free shows only blog_post, how_to, listicle unlocked) · fix pre-generation contextual-hints JS schema list (Free's `blog_post` only emits `Article + FAQPage + BreadcrumbList` — remove `Organization + Person` from free hint) · update sidebar Pro upsell card: remove "Analyze & Improve inject buttons" line · fix "Sonnet-tier LLM" + "5 on Free" misleading copy · add wedge features to upsell (AI Citation Tracker, Brand Voice, Multilingual 60+, Country localization, Brave Search, inline citations) · keep upsell density LOW (active task flow) — sidebar only, no full-page interrupt · add subtle "6 free / 80+ Pro" hint to country picker · remove hardcoded `5/15` Cloud count in status bar (read from License_Manager) | 1-2 days | All |
| 22 | **Bulk Generate page tier fix** (?page=seobetter-bulk) — change "Bulk Generation requires Pro" → "Bulk Generation requires Agency" · update CTA from $39/mo → $179/mo with Agency value prop · fix `$is_pro` gate to `License_Manager::can_use('bulk_csv')` which checks Agency tier · expand binary PRO/FREE header badge to Free/Pro/Pro+/Agency (consistent with dashboard) · the full UX rebuild (presets / per-row override / Action Scheduler queue / GEO floor 40 / default-to-draft) is Phase 1 item 9 which replaces the form entirely — this item is just the tier-correctness sweep that ships alongside | 0.5 day | All |
| 23 | **Phase 1 test gate** — Ben personally tests EVERY feature works correctly with `SEOBETTER_GATE_LIVE=false` (testing-as-Pro mode). Test matrix: 3 GEO-90+ articles (How-To / Listicle / Local Business Listicle per pro-plan-pricing.md §6); all 21 content types generate without errors; AI Featured Image works for all 7 style presets across major languages (English, Portuguese, German, Japanese); GSC OAuth connects + dashboard shows data; Freshness inventory loads; Internal Links suggester returns suggestions; Brand Voice enforces banned phrases; Bulk CSV runs end-to-end with quality gate; 5 Schema Blocks render and validate in Google Rich Results Test; llms.txt + llms-full.txt serve correctly; AI Crawler Access audit finds + fixes blocked bots; **Dashboard 3-tier upsell grid renders correctly with all tiers and accurate copy**; **Settings 6-tab restructure works with no broken save handlers**; **Generate Content page shows 🔒 lock icons on 18 non-free content types**; **Bulk Generate page correctly says "Agency required" not "Pro required"**; meta sync to Yoast/RankMath/AIOSEO/SEOPress works; multilingual gen works for 10+ languages. **Failure of any one halts Phase 2.** Bug-fix iteration loop until everything is green. | 1-2 weeks elapsed | — |
| 24 | **Flip `SEOBETTER_GATE_LIVE` to `true`** — after item 23 fully passes. Final smoke test: spin up a fresh free-tier WP install (no license key) and confirm: Pro features show lock badges + refuse to execute; Free features all work; upsell CTAs link to correct pricing tiers; **Dashboard tier badge correctly shows "Free" not "Pro"**; **Generate Content blocks Pro content types from execution**; **Bulk Generate blocks all non-Agency users**. THIS is the moment Pro/Pro+/Agency gating becomes real. Removes the existing `License_Manager.php` v1.5.13 testing flag. | 0.5 day | — |

**Security work (per security.md):**

| Order | Task | Effort | Layer |
|---|---|---|---|
| S1 | Build `do_action()` hook stubs in free plugin codebase for every Pro feature (preparation for Layer 3 split) | 3 days | Layer 2 prep |
| S2 | Cloud API license verification — every Pro endpoint validates Freemius license, refuses on invalid | 2 days | Layer 2 |
| S3 | Inject button cleanup — remove `Analyze & Improve inject buttons` code entirely from codebase (not just feature-gate) | 1 day | code hygiene + locked NO |

**Phase 1 test gate (BLOCKER — no Phase 2 until passes):**

Per existing pro-plan-pricing.md §6, three test articles must hit GEO 90+:
- `how to transition your dog to raw food safely 2026` (How-To)
- `best washable dog beds australia 2026` (Listicle)
- `best dog food delivery services in melbourne australia 2026` (Listicle, places-heavy)

PLUS the full feature test matrix in item 18 above. The `SEOBETTER_GATE_LIVE` flag stays `false` during testing so Ben can exercise EVERY Pro/Pro+/Agency feature as if licensed. Once all features pass, item 19 flips the flag to `true` and gating becomes real.

**Critical sequencing:**
1. Build everything (items 1-17) with `SEOBETTER_GATE_LIVE=false`
2. Test everything with full Pro access (item 18)
3. Fix bugs, retest
4. Only when fully green: flip `SEOBETTER_GATE_LIVE=true` (item 19)
5. Smoke-test free-tier behavior on a clean install
6. THEN proceed to Phase 2 (Freemius)

**Total Phase 1: ~7-9 weeks of solo dev** (some parallelizable, +1-2 weeks testing & fix iteration)

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

### Layer 2 — Pro gating architecture (Phase 1)

**Rule 1 — Pro features must execute server-side.** No client-side license checks. Every Pro endpoint on the cloud API verifies the Freemius license before executing. A cracked plugin still can't get Pro behavior because the code lives behind the API.

**Rule 2 — License verification via Freemius.** Plugin sends license key with every cloud-api request. Cloud API validates against Freemius API; refuses on invalid. Cached for 1h with revocation honored.

### Layer 3 — Plugin split (Phase 2)

**Free plugin `seobetter` — WordPress.org**:
- Unobfuscated PHP (WP.org rule)
- Free tier features ONLY: 3 content types, basic schema, GEO Analyzer, Pexels, GSC connect, footer link
- Contains hook stubs for Pro features: `do_action('seobetter_pro_inject_citation', ...)`, etc.
- **No Pro logic** — even if cracked, no Pro capability is unlocked

**Pro add-on `seobetter-pro` — Freemius distribution only (NOT WP.org)**:
- Distributed via Freemius downloads after purchase
- Contains all Pro/Pro+/Agency PHP: 5 Schema Blocks render_callbacks, Brand Voice logic, AI Citation Tracker, Bulk CSV UX, AIOSEO full schema, Places Pro tiers, Internal Links suggester, GSC Freshness driver, etc.
- Hooks into free plugin's `do_action` stubs
- Freemius Bundle Generator handles the split build automatically

**User flow:**
1. Install free plugin from WP.org → gets free tier
2. Buy Pro/Pro+/Agency via Freemius → Freemius emails ZIP download link
3. Upload Pro add-on ZIP → free plugin detects companion → Pro features activate

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
