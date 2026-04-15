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
- ❌ Internal linking auto-injector
- ❌ AIOSEO / Yoast / RankMath auto-population
- ❌ LocalBusiness + HowTo + ItemList schema
- ❌ Bulk CSV import
- ❌ Priority support

---

## 3. Pro Tier — **$29/month or $249/year** (29% annual discount)

**Target customer:** solo bloggers, small site owners, DIY SEO operators running 1 WordPress site.

**What's unlocked over Free:**
- All 21 content types
- Analyze & Improve inject buttons (+5 to +10 points per click, never edits existing text)
- Full 5-tier Places waterfall: Perplexity Sonar Pro → OSM → Foursquare → HERE → Google Places
- AI Featured Image with branding (Pollinations free / Gemini 2.5 Flash Image / DALL-E 3 / FLUX Pro)
- Brave Search API integration (real web citations in References section)
- Country localization (80+ countries with local category/gov APIs)
- Internal linking auto-injector (3-6 internal links per article, pulled from last 50 published posts)
- AIOSEO + Yoast + RankMath auto-population (focus keyword, meta title, description, OG tags, schema)
- LocalBusiness schema with verified addresses from Places pool
- HowTo schema for tutorial content
- ItemList schema for listicles
- Inline citations as clickable markdown links (via Citation Pool)
- Priority support (48h response)
- Remove "Powered by SEOBetter" footer
- 1 site license

**Pricing psychology:** $29/mo is the anchor. Annual plan shown as "$29/month billed annually (save $99)" — loss-aversion framing beats percent-off framing. The upgrade CTA mentions the savings in dollars, not percentages.

---

## 4. Agency Tier — **$99/month or $890/year** (25% annual discount)

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

**Pricing psychology:** $99 is the Pro anchor × 3.4 — justified by 10× sites + 5× seats. Positioned as "agency revenue tool" not "bigger individual plan".

---

## 5. Cloud Credits Add-on (Hybrid Usage Layer)

**Purpose:** capture users who don't want to manage API keys. Sell prepaid credit packs that cover Perplexity Sonar Pro + Claude/GPT-4o calls behind the scenes. Stacks on top of Pro or Agency. Never blocks users — acts like overage billing.

**Model:** mirrors GitHub Copilot and Cursor's credit-pack approach. [Schematic HQ 2026](https://schematichq.com/blog/software-monetization-models) identifies this as the dominant AI-tool pricing pattern in 2026.

**Packs:**
| Pack | Price | Articles covered | Cost per article |
|---|---|---|---|
| Starter | $10 | ~50 articles | $0.20 |
| Creator | $30 | ~200 articles | $0.15 |
| Agency | $100 | ~800 articles | $0.125 |

**Margin:** each "article" uses ~$0.06 Sonar Pro + ~$0.03 Claude/GPT = $0.09 cost to us. Starter margin = 55%, Creator = 40%, Agency = 28%. Volume discounts drive users to larger packs.

**UX:** credit balance always visible in the top bar ("❇ 43 credits"). One-click top-up when below 10.

---

## 6. Pre-Launch Testing Mandate (BLOCKER)

**⚠️ Do NOT ship Freemius gating or pricing until these tests pass.** Selling buggy features = refund storms + 1-star reviews that poison the WP.org listing forever.

**Test matrix:**

| # | Keyword | Content Type | Expected |
|---|---|---|---|
| 1 | `how to transition your dog to raw food safely 2026` | How-To Guide | GEO 90+, AIOSEO passes (except internal links), References with clickable links, first-hand voice, 1 comparison table |
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

**7 placements:**

| # | Trigger | CTA copy |
|---|---|---|
| 1 | User selects a Pro content type (Buying Guide / Comparison / Review) | **"Unlock all 21 content types + 🎯 comparison tables — start 7-day Pro trial"** |
| 2 | User's article GEO score card shows Tables 0 with a locked "Add Table" button | **"Add a comparison table in one click — Pro fixes tables automatically (+5 GEO points)"** |
| 3 | User enters a small-town keyword that Sonar-free tier can't cover | **"Unlock Perplexity Sonar Pro for any town worldwide — find real businesses in Mudgee, Lucignano, or any small city ($29/mo)"** |
| 4 | User enables AI Featured Image and picks a branded style | **"Upgrade to use DALL-E 3 and FLUX Pro for premium images (Pro)"** |
| 5 | User's article has plain-text citations instead of clickable links | **"Pro users get clickable citations via Brave Search — fixes AIOSEO 'no outbound links' automatically"** |
| 6 | User tries to generate a 4th article in a month (if we add usage cap to free tier later) | **"You've used all 3 free cloud articles this month. Upgrade to Pro for unlimited, or bring your own API key (still free)"** |
| 7 | User opens the Analyze & Improve panel with any locked fix | **"Apply this fix with Pro — 7-day free trial, cancel anytime"** |

**Copy rules (from SaaS CRO research):**
- Benefit-led, not feature-led ("Find real businesses" not "Unlock Sonar")
- Specific numbers ("+5 GEO points" not "improve your score")
- Time/money framing ("Save $99 with annual")
- Loss aversion ("Don't leave 15 points on the table")
- Social proof where possible ("Join 2,387 sites using SEOBetter Pro")

---

## 9. Additional Conversion Tactics

1. **7-day Pro trial with credit card required** — trials with card required convert at 25-35% vs. 3-5% for no-card. Freemius handles this natively.
2. **Lock icons on Pro UI elements** (discovery-through-friction) — show the full UI greyed out with a lock, hover reveals the Pro tooltip. Users *see* what they're missing.
3. **In-plugin email capture** on first activation — 5-7 email nurture sequence driving to Pro trial over 45 days.
4. **Social proof counter** in upgrade modal, auto-incremented from real license count: *"Join 2,387 WordPress sites using SEOBetter Pro"*. If launching with 0 users, seed with beta tester quotes.
5. **AppSumo LTD for first 500 buyers** at $149. Immediate $75k cash injection + 500 evangelist reviews.
6. **Exit-intent modal** when a user tries to downgrade from trial — offer annual discount or credit-pack alternative.
7. **GEO Score comparison in upgrade modal** — "Free tier users average 72. Pro users average 94. Upgrade to see your score rise."
8. **Annual pricing dollar savings** not percent — *"Save $99/year"* beats *"29% off"*.

---

## 10. Projected MRR (Scenario Planning)

Assume 1,000 active free installs from WordPress.org + AppSumo aftermath by month 6:

| Conversion | Pro users | Agency users | Pro MRR | Agency MRR | Credits MRR | **Total MRR** |
|---|---|---|---|---|---|---|
| 5% (pessimistic) | 45 | 5 | $1,305 | $495 | $500 | **~$2,300** |
| 8% (SaaS median) | 72 | 8 | $2,088 | $792 | $800 | **~$3,680** |
| 15% (AI tools average) | 135 | 15 | $3,915 | $1,485 | $1,200 | **~$6,600** |
| 20% (AI tools best) | 180 | 20 | $5,220 | $1,980 | $1,500 | **~$8,700** |

Scaled to 10,000 installs (typical 2-year WP.org plugin), these numbers become **$23,000 – $87,000 MRR**.

AppSumo LTD launch adds a **one-time $45,000-$120,000 cash injection** in month 2 that doesn't count toward MRR but funds 6-12 months of operations.

---

## 11. Integration with seobetter.com

The pricing page on [seobetter.com](https://seobetter.com) should mirror this document exactly:

- **Hero:** "The WordPress plugin that writes articles AI actually cites"
- **3 pricing cards:** Free / Pro ($29) / Agency ($99), with annual toggle showing dollar savings
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
| 2026-04-15 | $29 Pro / $99 Agency pricing | Anchored against competitor plugins (Yoast $99/yr, RankMath $59/yr, Rank Math Pro $129/yr) and AI content tools ($20-50 solo, $100-300 SMB per Sight AI 2026 report). $29 is the sweet spot for solo bloggers. |
| 2026-04-15 | 10 sites on Agency, not unlimited | Unlimited invites abuse. 10 covers most realistic agencies while leaving room for an Enterprise tier later. |
| 2026-04-15 | Cloud Credits as hybrid add-on, not mandatory | Matches Cursor/GitHub Copilot model. Users who don't want to manage keys can pay for credits. Users who DO want BYOK still have the free escape hatch. |
| 2026-04-15 | AppSumo LTD launch in Phase 3 | Proven WP-plugin launch tactic. $45-120k upfront funds operations. Trade: 3-month exclusivity, but worth it for cash + reviews. |
| 2026-04-15 | No release until test articles pass 90+ | Shipping broken paid features kills reviews. Test first. |

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
