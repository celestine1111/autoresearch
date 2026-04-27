# SEOBetter Pro Plan — Pricing & Monetization Strategy

> **Purpose:** locked-in monetization plan for SEOBetter. Captured 2026-04-15 after full research review. All feature gating, pricing tiers, CRO tactics, and launch phases go here.
>
> **Status:** PLANNED — not yet implemented. Gating code not yet wired. Freemius SDK not yet integrated.
>
> **Gate flip target:** after all 3 test articles (see §6) pass GEO 90+ with real scores.
>
> **Last updated:** 2026-04-15

---

## 1. Strategic Decision (Locked)

**Model:** Feature-gated freemium + hybrid usage pricing + BYOK escape hatch.

**Why this model:** 2026 AI-tool SaaS benchmarks show 15-20% freemium→paid conversion for AI-powered plugins (vs. 3-5% for generic SaaS). WordPress.org directory listing requires a free version for discoverability (~50-200 installs/day once approved). Hybrid pricing (subscription + usage overages) is now the dominant model — 46% of SaaS companies use it, 85% have a usage component. BYOK free tier removes per-article cost from us entirely since users pay their own AI tokens.

**Why NOT 100% paid:** kills WordPress.org directory traffic, no free discovery funnel, capped ceiling.

**Why NOT pure usage-cap:** feels punitive ("3 free articles then paywall"), users bounce, 1-star reviews, blocks free discovery.

**Reference benchmarks:**
- Freemium AI tools: **15-20% conversion** (First Page Sage 2026, Artisan Strategies 2026)
- Free trial with card required: **25-35% conversion**
- Personalized contextual CTAs: **+202% lift** vs. default CTAs
- Hybrid pricing adoption: 46% of SaaS, 85% have usage component (Revenera 2026)

---

## 2. Free Tier (WordPress.org Listing)

**Goal:** drive organic discovery via WP.org directory. Let users prove the plugin works before asking for money. No time-limited trial. Free tier works forever.

**What's free:**
- ✅ Unlimited articles with BYOK (user provides their own OpenAI / Claude / OpenRouter key — user pays their own token costs; zero marginal cost to us)
- ✅ 3 content types: Blog Post, How-To Guide, Listicle
- ✅ Basic GEO Analyzer (score ring + rubric breakdown, no fix buttons)
- ✅ Basic schema (Article + FAQ only)
- ✅ 1 AI provider connection at a time
- ✅ OpenStreetMap places only (Tier 1 of the waterfall)
- ✅ Picsum stock images (generic placeholder)
- ✅ Auto-suggest secondary + LSI keywords
- ✅ Humanizer banned-word check
- ✅ "Powered by SEOBetter" footer link (removable in Pro)

**What's locked (prompts upgrade):**
- ❌ 18 other content types (Buying Guide, Comparison, Review, Ultimate Guide, Recipe, Case Study, Interview, Tech Article, White Paper, Opinion, Press Release, Personal Essay, Glossary, Scholarly, Sponsored, Live Blog, FAQ Page, News Article)
- ❌ Analyze & Improve inject buttons (citations, quotes, tables, statistics, freshness)
- ❌ Places waterfall Tier 0 (Perplexity Sonar Pro), Tier 2 (Foursquare), Tier 3 (HERE), Tier 4 (Google Places)
- ❌ AI Featured Image with branding (4 providers)
- ❌ Brave Search (Pro research source)
- ❌ Country localization (80+ country APIs)
- ❌ AIOSEO / Yoast / RankMath auto-population
- ❌ LocalBusiness + HowTo + ItemList schema
- ❌ Bulk CSV import
- ❌ Priority support

---

## 3. Pro Tier — **$39/month or $349/year** (25% annual discount)

**Target customer:** solo bloggers, small site owners, DIY SEO operators running 1 WordPress site.

**What's unlocked over Free:**
- All 21 content types
- Analyze & Improve inject buttons (+5 to +10 points per click, never edits existing text)
- Full 5-tier Places waterfall: Perplexity Sonar Pro → OSM → Foursquare → HERE → Google Places
- AI Featured Image with branding (Pollinations free / Gemini 2.5 Flash Image / DALL-E 3 / FLUX Pro)
- Brave Search API integration (real web citations in References section)
- Country localization (80+ countries with local category/gov APIs)
- AIOSEO + Yoast + RankMath auto-population (focus keyword, meta title, description, OG tags, schema)
- LocalBusiness schema with verified addresses from Places pool
- HowTo schema for tutorial content
- ItemList schema for listicles
- Inline citations as clickable markdown links (via Citation Pool)
- Priority support (48h response)
- Remove "Powered by SEOBetter" footer
- 1 site license

**Cloud generation included:** 50 articles/month using SEOBetter's centralized OpenRouter + Firecrawl + Serper + Pexels stack. Above 50 → overage billing at $0.50/article (74% margin on overage). BYOK users (own API key) get unlimited generations and don't consume the Cloud quota.

**Pricing psychology:** $39/mo is the anchor — positioned alongside Jasper ($39/mo entry) and above Yoast Premium ($8/mo equivalent) since SEOBetter delivers AI generation + SEO + schema in one product. Annual plan shown as "$29/month billed annually (save $119)" — loss-aversion framing beats percent-off framing. The upgrade CTA mentions the savings in dollars, not percentages.

**Unit economics at $39/mo (1 user, 50 articles/mo Cloud):**
- LLM (OpenRouter mid-mix gpt-4.1-mini extractions + Sonnet content): ~$0.10/article × 50 = $5.00
- Firecrawl scrape (5-10 pages × $0.004): ~$0.02/article × 50 = $1.00
- Serper SERP (5-10 queries × $0.0003): ~$0.003/article × 50 = $0.15
- Pexels free tier: $0
- Upstash free tier: $0
- Vercel/infra prorated: ~$0.50/user/mo (assumes 100 paid users sharing $48/mo fixed cost)
- **Total cost: ~$6.65/user/mo. Margin: $39 − $6.65 = $32.35 (83%).**

---

## 4. Agency Tier — **$129/month or $1,290/year** (17% annual discount)

**Target customer:** freelance SEOs, content agencies, multi-site operators managing client WordPress sites.

**What's unlocked over Pro:**
- **10 site licenses** (run Pro on up to 10 WordPress sites)
- **5 team seats** (agency staff each get a login)
- **Bulk CSV import** — paste 50 keywords, batch-generate 50 articles overnight
- White-label branding option (replace "SEOBetter" with your agency name)
- **API access** — programmatically trigger article generation from n8n / Zapier / custom scripts
- AI Citation Tracker — weekly check whether ChatGPT / Perplexity / Gemini cite your published articles
- Content Decay Alerts — email when published article scores drop below 70 (signals content refresh needed)
- Keyword Cannibalization Detector — flag when two articles target the same keyword
- Custom prompt templates (save your house style prompts per content type)
- Priority support (24h response)
- Onboarding call (first month)

**Cloud generation included:** 250 articles/month bundled across all 10 sites (pooled, not per-site). Overage at $0.40/article (67% margin on overage — Agency rate, slight discount vs Pro overage to incentivize tier upgrade).

**Pricing psychology:** $129 is the Pro anchor × 3.3 — justified by 10× sites + 5× seats + 5× Cloud articles. Positioned as "agency revenue tool" not "bigger individual plan". Annual price ($1,290) anchors against Surfer SEO Business ($219/mo = $2,628/yr) and ContentBot Premium ($99/mo).

**Unit economics at $129/mo (1 agency, 250 articles/mo Cloud):**
- LLM: $0.10 × 250 = $25.00
- Firecrawl: $0.02 × 250 = $5.00
- Serper: $0.003 × 250 = $0.75
- Vercel/infra prorated (heavier usage): ~$2.00/user/mo
- Support overhead (24h SLA, onboarding call): ~$3/user/mo amortized
- **Total cost: ~$35.75/user/mo. Margin: $129 − $35.75 = $93.25 (72%).**

---

## 5. Cloud Credits Add-on (Hybrid Usage Layer)

**Purpose:** capture users who don't want to manage API keys but exceed their tier's monthly quota. Sell prepaid credit packs that cover OpenRouter LLM + Firecrawl + Serper + Pexels calls behind the scenes. Stacks on top of Pro or Agency. Never blocks users — acts like overage billing.

**Model:** mirrors GitHub Copilot and Cursor's credit-pack approach. [Schematic HQ 2026](https://schematichq.com/blog/software-monetization-models) identifies this as the dominant AI-tool pricing pattern in 2026.

### Per-article cost reality (Ben's actual outflow — Sept 2025 baseline)

Calculated from the SEOBetter cloud-api pipeline (research → outline → content → schema → headings → meta tags) across 8-10 LLM calls per article:

| Component | Per-article cost | Source |
|---|---|---|
| OpenRouter LLM (mid-mix: gpt-4.1-mini extractions + Sonnet content) | $0.08–0.15 | OpenRouter pricing 2025 |
| Firecrawl scrape (5-10 pages) | $0.02–0.04 | Firecrawl Standard plan $26/mo ÷ 12K pages |
| Serper SERP queries | $0.003 | Serper Starter $50/170K queries |
| Pexels images | $0.00 | Free tier, 20K req/mo |
| Upstash Redis | $0.00 | Free tier, 10K cmd/day |
| **Total variable cost per article** | **~$0.13 mid-mix, $0.05 cheapest, $0.40 premium** | — |

Plus fixed monthly costs amortized across paid user base:

| Item | Monthly | Notes |
|---|---|---|
| Firecrawl Standard | $26 | Required for /api/scrape (replaces Jina Reader fallback for paid users) |
| Vercel Pro hosting | $20 | When traffic exceeds Hobby tier |
| Other infra | ~$2 | Domain, SSL, monitoring |
| **Total fixed** | **~$48/mo** | Amortizes to ~$0.50/user at 100 paid users |

### Credit packs (priced at 70%+ margin)

| Pack | Price | Articles covered | Effective $/article | Cost to Ben | Margin |
|---|---|---|---|---|---|
| Starter | $19 | ~50 articles | $0.38 | $6.50 | **66%** |
| Creator | $49 | ~150 articles | $0.33 | $19.50 | **60%** |
| Agency | $129 | ~500 articles | $0.26 | $65.00 | **50%** |

**Why these prices vs. the old $10/$30/$100 packs:**
- Old Agency pack ($100 → 800 articles → $0.125/article) was a LOSS — Ben's actual cost is $0.13/article, so $0.125 is below break-even.
- New scheme uses real per-article cost ($0.13) as floor, sets margin at 50-66% (volume discount on bigger packs but never below 50%).
- Volume tiering still rewards bigger commitments (Starter $0.38 → Agency $0.26), just rebased to actually profitable numbers.

**Cost-of-goods sanity check:** at the cheapest mix (gpt-4.1-mini + minimum scrape), Ben's cost drops to ~$0.05/article and Starter pack hits 87% margin. At the premium mix (Sonnet/Opus content), cost rises to ~$0.40/article and Starter is barely break-even at $0.38 — which is why the plugin defaults to mid-mix, not premium-mix, on Cloud generations. Agency tier is the only one where users can opt into premium models (priced into the higher overage rate).

**UX:** credit balance always visible in the top bar ("❇ 43 credits"). One-click top-up when below 10. Show real per-article spend in the post-generation success card so users see the value ("This article cost 1 credit / $0.38 — Pro saves you 30%").

---

## 6. Pre-Launch Testing Mandate (BLOCKER)

**⚠️ Do NOT ship Freemius gating or pricing until these tests pass.** Selling buggy features = refund storms + 1-star reviews that poison the WP.org listing forever.

**Test matrix:**

| # | Keyword | Content Type | Expected |
|---|---|---|---|
| 1 | `how to transition your dog to raw food safely 2026` | How-To Guide | GEO 90+, AIOSEO passes (users install a separate internal-linking plugin), References with clickable links, first-hand voice, 1 comparison table |
| 2 | `best washable dog beds australia 2026` | Listicle | GEO 85+, Pros/Cons blocks, stat callouts, Brave citations, ItemList schema |
| 3 | `best dog food delivery services in melbourne australia 2026` | Listicle | GEO 85+, real Melbourne businesses as H2s, 📍 meta lines, LocalBusiness schema, no places_insufficient |

Plus:
| Test button | Passes if |
|---|---|
| Settings → Test Sonar with custom keyword | ≥2 verified places for 3 different countries |
| Settings → Test Places Providers | Foursquare + HERE return real results for Sydney |
| Settings → Test Research Sources | Brave green ✅, ≥6 of 13 sources ok |

**Post-testing signal:** only when all 3 articles hit 90+ on the real GEO score (not hallucinated) AND AIOSEO shows green across keyword density + outbound links + intro paragraph, flip to Phase 1 below.

---

## 7. Launch Phases

### Phase 0 — Free validation (current state, no code gates yet)
- All features unlocked for free internal testing
- Ben tests on mindiampets.com.au
- Measure actual GEO scores, AIOSEO scores, AI citation pickup (Perplexity, ChatGPT, Gemini) over 30 days
- Iterate plugin until Article 1/2/3 all land at 90+

### Phase 1 — Freemius infrastructure (weeks 1-2 after Phase 0 passes)
- Integrate [Freemius SDK](https://freemius.com/wordpress/) (2-3 days of work)
- Freemius handles: license keys, subscription billing, trial management, WP.org free-variant packaging, refunds, analytics dashboard
- Build free/pro feature gates per §2 and §3
- Wire contextual upgrade CTAs at the 7 friction points (see §8)
- Set up [seobetter.com](https://seobetter.com) pricing page with monthly/annual toggle

### Phase 2 — WordPress.org directory submission (week 3)
- Scrub free tier for WP.org guideline compliance (no hardcoded external calls, proper escaping, GPL2+, etc)
- Submit to directory (approval: 7-14 days)
- Build the directory listing: hero video, 8 screenshots, benefit-led description, FAQ
- Prepare 3 launch blog posts on seobetter.com for content traffic

### Phase 3 — AppSumo Lifetime Deal launch (month 2)
- Apply to AppSumo with a **$149 LTD** for first 500 customers
- Commit to 3-month AppSumo exclusivity window per their terms
- Expected outcome: 300-800 lifetime sales → **$45,000-$120,000 upfront cash**
- 500 evangelists leave reviews on AppSumo + WP.org, bootstrapping social proof

### Phase 4 — MRR scale (months 3-6)
- WordPress.org directory drives organic free installs (~50-200/day post-launch)
- SEO content on seobetter.com targets plugin comparison keywords ("best AI content WordPress plugin 2026", "Yoast vs SEOBetter", "RankMath vs SEOBetter") — dog-fooding the product
- Retarget free installs via email nurture (day 1, 3, 7, 14, 21, 30, 45)
- Growth target: **$5k MRR by month 6** at conservative 8% conversion on ~1,500 installs

---

## 8. Contextual Upgrade CTA Placements (CRO)

Research shows **personalized contextual CTAs beat default CTAs by 202%** ([WeCanTrack 2026](https://wecantrack.com/insights/wordpress-monetization-statistics/)). Don't put a single "Upgrade" button in the sidebar. Put targeted prompts at the exact moment of friction where users feel the limitation.

**Active CTA placements as of v1.5.214 (✅ shipped) + queued (📋):**

| # | Trigger | CTA copy | Status |
|---|---|---|---|
| 1 | User selects a Pro content type (Buying Guide / Comparison / Review) in the form. Right-column hints panel shows `🔒 Pro:` chip live. | **"🔒 Pro: \"Buying Guide\" requires Pro. Unlock all 21 types →"** | ✅ v1.5.214 (`#sb-context-hints`) |
| 2 | Settings → AI generation source card. Free user without BYOK key sees Cloud quota meter. | Quota meter color-grades green/amber/red at 70%/90%; CTA appears only ≥70%: **"Upgrade to Pro for unlimited Cloud articles"** | ✅ v1.5.214 (Settings card) |
| 3 | Dashboard → bundled-value Pro card. Lists ALL 11 unlocked outcomes with $39/mo anchor. | Bundle card: 50 Cloud articles + Firecrawl + Serper + all 21 types + AI featured image + Speakable + 5 Schema Blocks + AIOSEO sync + inject buttons + 48h support. **"$39/mo — See Pro plans →"** + annual savings line ("$349/yr — save $119 vs monthly") | ✅ v1.5.214 (`dashboard.php`) |
| 4 | Content Generator sidebar Pro card. Free tier only. Names 6 specific outcomes that compound on top of free generation. | **"PRO — Push this article further: Firecrawl deep research, all 21 types, AI featured image, inject buttons, 5 schema blocks, 50 Cloud articles/mo. $39/mo — See Pro plans →"** | ✅ v1.5.214 (`content-generator.php`) |
| 5 | Pre-generation hints panel. Free user — every article shows "📡 Research: Free uses Jina Reader fallback (basic). Pro adds Firecrawl deep research →" | Live JS render, no nag — passive context, click-to-Pro-page only | ✅ v1.5.214 (inline script) |
| 6 | Cross-script translator hint when user enters English keyword + non-Latin language | **"🌐 Auto-translate: Your English keyword will be translated to JA for native research + headings + meta tags."** (free feature, v1.5.212.2) | ✅ v1.5.214 (inline script) |
| 7 | Recipe + country combo shows recipeCuisine mapping | **"🍳 Recipe cuisine: Schema will mark recipeCuisine = Japanese."** (free feature, surfaces value of country selector) | ✅ v1.5.214 (inline script) |
| 8 | User's article GEO score card shows Tables 0 with a locked "Add Table" button (legacy from earlier draft) | **"Add a comparison table in one click — Pro fixes tables automatically (+5 GEO points)"** | 📋 Queued — Analyze & Improve inject buttons |
| 9 | User enters a small-town keyword that Sonar-free tier can't cover | **"Unlock Perplexity Sonar Pro for any town worldwide — find real businesses in Mudgee, Lucignano, or any small city ($39/mo)"** | 📋 Queued — needs Places_Validator gating signal |
| 10 | User's article has plain-text citations instead of clickable links | **"Pro users get clickable citations via Brave Search — fixes AIOSEO 'no outbound links' automatically"** | 📋 Queued — needs Citation_Pool format check |
| 11 | User opens the Analyze & Improve panel with any locked fix | **"Apply this fix with Pro — 7-day free trial, cancel anytime"** | 📋 Queued — Analyze & Improve panel rebuild |
| 12 | Success-moment card after GEO ≥75 generation completes | **"🎉 GEO 82 — top 18% of articles in your industry. [Publish] [Add Firecrawl pass +$0.40] [Maybe later]"** | 📋 Queued — needs result-panel hook |

**Copy rules (from SaaS CRO research):**
- Benefit-led, not feature-led ("Find real businesses" not "Unlock Sonar")
- Specific numbers ("+5 GEO points" not "improve your score")
- Time/money framing ("Save $119 with annual")
- Loss aversion ("Don't leave 15 points on the table")
- Social proof where possible ("Join 2,387 sites using SEOBetter Pro")

---

## 9. Additional Conversion Tactics

1. **7-day Pro trial with credit card required** — trials with card required convert at 25-35% vs. 3-5% for no-card. Freemius handles this natively.
2. **Lock icons on Pro UI elements** (discovery-through-friction) — show the full UI greyed out with a lock, hover reveals the Pro tooltip. Users *see* what they're missing.
3. **In-plugin email capture** on first activation — 5-7 email nurture sequence driving to Pro trial over 45 days.
4. **Social proof counter** in upgrade modal, auto-incremented from real license count: *"Join 2,387 WordPress sites using SEOBetter Pro"*. If launching with 0 users, seed with beta tester quotes.
5. **AppSumo LTD for first 500 buyers** at $169 (Pro lifetime, capped 1 site). Immediate $85k cash injection + 500 evangelist reviews. LTD priced just under 1 year of Pro ($349) to feel like a steal but ensures buyer is paying ≥1.4× annual subscription cost.
6. **Exit-intent modal** when a user tries to downgrade from trial — offer annual discount or credit-pack alternative.
7. **GEO Score comparison in upgrade modal** — "Free tier users average 72. Pro users average 94. Upgrade to see your score rise."
8. **Annual pricing dollar savings** not percent — *"Save $119/year"* beats *"25% off"*.

---

## 10. Projected MRR (Scenario Planning)

Assume 1,000 active free installs from WordPress.org + AppSumo aftermath by month 6. Pro = $39, Agency = $129, average Credits spend $25/active credit user/mo.

| Conversion | Pro users | Agency users | Pro MRR | Agency MRR | Credits MRR | **Total Gross MRR** | Variable cost @ $7/Pro + $35/Agency + 60% credits margin | **Net MRR** |
|---|---|---|---|---|---|---|---|---|
| 5% (pessimistic) | 45 | 5 | $1,755 | $645 | $500 | **$2,900** | $490 | **~$2,410** |
| 8% (SaaS median) | 72 | 8 | $2,808 | $1,032 | $800 | **$4,640** | $784 | **~$3,856** |
| 15% (AI tools average) | 135 | 15 | $5,265 | $1,935 | $1,200 | **$8,400** | $1,470 | **~$6,930** |
| 20% (AI tools best) | 180 | 20 | $7,020 | $2,580 | $1,500 | **$11,100** | $1,960 | **~$9,140** |

Less fixed monthly infra cost (~$48/mo) → still 95-99% net margin at scale because fixed cost is dominated by variable cost as user count grows.

Scaled to 10,000 installs (typical 2-year WP.org plugin), these numbers become **$23,000 – $87,000 MRR**.

AppSumo LTD launch adds a **one-time $45,000-$120,000 cash injection** in month 2 that doesn't count toward MRR but funds 6-12 months of operations.

---

## 11. Integration with seobetter.com

The pricing page on [seobetter.com](https://seobetter.com) should mirror this document exactly:

- **Hero:** "The WordPress plugin that writes articles AI actually cites"
- **3 pricing cards:** Free / Pro ($39) / Agency ($129), with annual toggle showing dollar savings
- **Feature comparison table:** Free vs Pro vs Agency with ✅ / ❌ marks for every feature in §2-4
- **Social proof:** install count from WP.org API, review stars, logos of sites using it
- **FAQ:** refund policy, trial terms, BYOK explanation, WP.org free version vs paid
- **Testimonials:** once real users exist (collect from AppSumo launch)
- **"Get started free" primary CTA** → WP.org download page
- **"Start 7-day Pro trial" secondary CTA** → Freemius checkout with card collection

---

## 12. Decision Log

| Date | Decision | Rationale |
|---|---|---|
| 2026-04-15 | Chose feature-gated freemium over usage cap | Usage caps feel punitive, hurt reviews. Feature gating gives clear upgrade path without blocking core value. |
| 2026-04-15 | BYOK free tier | Zero marginal cost on free users. Their own API keys pay for their own AI usage. |
| 2026-04-15 | $29 Pro / $99 Agency pricing | Original anchor against competitor plugins (Yoast $99/yr, RankMath $59/yr, Rank Math Pro $129/yr). |
| 2026-04-27 | **Repricing: $39 Pro / $129 Agency** | Original $29/$99 pricing didn't account for actual unit economics. Real cost per Cloud article = $0.13 (LLM $0.10 + Firecrawl $0.02 + Serper $0.003). At 50 articles/mo Pro quota, COGS = $6.50/user. Original $29 only gave 78% margin BEFORE prorated fixed costs ($48/mo Firecrawl + Vercel + infra). New $39 hits 83% margin and positions alongside Jasper ($39/mo entry) — the actual peer in AI-generation space, not Yoast (which doesn't ship AI). Agency raised $99 → $129 because original pricing showed only 65% margin once Firecrawl scrape volume is factored in; $129 hits 72%. |
| 2026-04-15 | 10 sites on Agency, not unlimited | Unlimited invites abuse. 10 covers most realistic agencies while leaving room for an Enterprise tier later. |
| 2026-04-15 | Cloud Credits as hybrid add-on, not mandatory | Matches Cursor/GitHub Copilot model. Users who don't want to manage keys can pay for credits. Users who DO want BYOK still have the free escape hatch. |
| 2026-04-15 | AppSumo LTD launch in Phase 3 | Proven WP-plugin launch tactic. $45-120k upfront funds operations. Trade: 3-month exclusivity, but worth it for cash + reviews. |
| 2026-04-15 | No release until test articles pass 90+ | Shipping broken paid features kills reviews. Test first. |
| 2026-04-15 | Internal linking REMOVED from roadmap | User will rely on an existing third-party WordPress internal linking plugin (Link Whisper, Internal Link Juicer, Rank Math internal linker, etc). SEOBetter will not duplicate that functionality. Frees engineering to focus on the unique anti-hallucination Places waterfall and GEO scoring that no other plugin has. AIOSEO's "no internal links" check is accepted as a cross-plugin concern. |
| 2026-04-24 | Pexels as free-tier default (server-side) | Picsum is random lorem-ipsum quality — bad first impression. Pexels via shared server-side key (hybrid option 3) gives free users keyword-relevant photos with zero setup. Pro unlocks priority quota + optional own key. Matches Sonar Backend Rule from memory: all research data via Ben's Vercel backend. Ships v1.5.213. |
| 2026-04-24 | 5 Schema Blocks (Product / Event / Local Business / Vacation Rental / Job Posting) ship as Pro-only | Consistent with §2 positioning: Free = Article + FAQ schema, Pro = everything else. LocalBusiness already explicitly Pro per §2 — blocks extend that cleanly. No price bump needed. Single `schema_blocks` License_Manager feature key (simpler than 5 individual keys). Ships v1.5.213. |
| 2026-04-24 | Plugin integrations (WooCommerce / Events Calendar / WP Job Manager / etc.) are Pro add-on releases post-launch | Ship blocks standalone first in v1.5.213. Integrations are Pro-tier differentiators that ship one per release (v1.5.214+) to avoid scope-creep and dependency matrix explosion. Pattern matches AIOSEO Pro WooCommerce, RankMath Pro WooCommerce. |
| 2026-04-24 | Security Layer 1 ships v1.5.211 before any new attack surface | HMAC request signing + SSRF protection + input sanitization on all cloud-api endpoints. Protects existing endpoints from cost-bombing before Schema Blocks + Pexels add new surfaces. Full 4-layer plan documented in `security.md`. Layers 2-4 ship with Freemius Phase 1 / WP.org submission / post-launch. |
| 2026-04-24 | v1.5.211 ships as 3-part split (211 security / 212 UX + Pexels / 213 Schema Blocks) | Original plan was single 65hr release. Split into logical chunks of ~8hrs / ~12hrs / ~45hrs so each ships with its own testing cycle + review surface + rollback path. Matches "no release until test articles pass 90+" rule from §6. |
| 2026-04-24 | v1.5.212 shipped: Rich Results gap fixes + Pexels hybrid + Upstash rate limits + cost caps | Consolidated 3 parked workstreams. Pexels server-side (via `/api/pexels` using `PEXELS_API_KEY`) replaces Picsum as free-tier default — matches Sonar Backend Rule. Upstash Redis wired across all 6 endpoints replacing in-memory `Map()` — persistent rate limiting survives cold starts. Cost circuit breaker on Serper/Firecrawl/OpenRouter/Anthropic/Groq. Free tier Pexels rate 100/hr keeps shared pool (20K/mo) from draining. Top-level Organization + Person schemas on every article fix AI Overview readiness gap flagged in v1.5.207 review. |

---

## Sources

- [SaaS Freemium Conversion Rates: 2026 Report – First Page Sage](https://firstpagesage.com/seo-blog/saas-freemium-conversion-rates/)
- [SaaS Conversion Rate Benchmarks 2026 – Artisan Growth Strategies](https://www.artisangrowthstrategies.com/blog/saas-conversion-rate-benchmarks-2026-data-1200-companies)
- [WordPress Monetization Statistics 2026 – WeCanTrack](https://wecantrack.com/insights/wordpress-monetization-statistics/)
- [Freemius WordPress Plugin Licensing Platform](https://freemius.com/wordpress/)
- [SaaS Pricing Models: The 2026 Guide – Revenera](https://www.revenera.com/blog/software-monetization/saas-pricing-models-guide/)
- [9 Software Monetization Models for SaaS and AI Products 2026 – Schematic](https://schematichq.com/blog/software-monetization-models)
- [Freemium vs Trial Models in SaaS – SaaSFactor](https://www.saasfactor.co/blogs/freemium-vs-trial-models-in-saas-what-really-boosts-conversions)
- [AI Content Generation Cost 2026 – Sight AI](https://www.trysight.ai/blog/ai-content-generation-cost)
