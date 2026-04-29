# SEOBetter Pro Plan — Pricing & Monetization Strategy

> **Purpose:** locked-in monetization plan for SEOBetter. Captured 2026-04-15 after full research review. All feature gating, pricing tiers, CRO tactics, and launch phases go here.
>
> **Status:** PLANNED — not yet implemented. Gating code not yet wired. Freemius SDK not yet integrated.
>
> **Gate flip target:** after all 3 test articles (see §6) pass GEO 90+ with real scores.
>
> **Last updated:** 2026-04-29 (post strategic re-tier — see Decision Log §12)
>
> **Tier source of truth:** `pro-features-ideas.md` §2 (Tier Matrix). If anything in this doc disagrees with that table, the tier matrix wins.

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

## 2. Free Tier (WordPress.org Listing) — BYOK-only model (v1.5.216)

**Goal:** drive organic discovery via WP.org directory. Let users prove the plugin works before asking for money. No time-limited trial. Free tier works forever.

**Critical decision (v1.5.216):** Free tier is **BYOK-only** — no SEOBetter Cloud articles included. Users connect their own AI provider key (OpenRouter / Anthropic / OpenAI / Gemini / Groq / Ollama) and pay their provider directly per token. Plugin owner pays $0 for free-tier article generation.

**Why this matters financially:** A "5 free Cloud articles/month" model created open-ended exposure at scale (5,000 installs × premium config = $2,000+/mo). Solo founders can't sustain that pre-launch. The BYOK-only model caps owner cost at the fixed $50/mo infrastructure spend (Firecrawl + Vercel + misc) regardless of install count. Mirrors Yoast / RankMath / AIOSEO pattern: full free SEO tooling, paid AI generation. WP.org-compliant + financially sustainable.

Cloud articles will be re-introduced as a goodwill bonus (~3/mo) once Pro MRR comfortably covers infra costs — see §7 Phase 5 (post-AppSumo).

**What's free (BYOK-powered):**
- ✅ **Unlimited articles via BYOK** — user provides their own OpenAI / Anthropic / OpenRouter / Gemini / Groq key. They pay their provider directly (~$0.01–$0.08 per article depending on model). Zero marginal cost to us.
- ✅ Full GEO Analyzer (score ring + rubric breakdown — runs locally, zero cost)
- ✅ Full schema generation: Article + Recipe + Organization + Person + BreadcrumbList + FAQPage (when applicable)
- ✅ 3 content types: Blog Post, How-To Guide, Listicle
- ✅ 1 AI provider connection at a time (BYOK)
- ✅ OpenStreetMap places only (Tier 1 of the Places waterfall)
- ✅ Pexels stock images via SEOBetter Cloud free pool (no Pexels key needed — costs the owner $0 via Pexels free tier 20K/mo)
- ✅ Jina Reader fallback for web research (free, no key)
- ✅ Auto-suggest secondary + LSI keywords (Google Suggest + Wikipedia + Reddit — all free)
- ✅ Humanizer banned-word check (runs locally)
- ✅ "Powered by SEOBetter" footer link (removable in Pro)

**What requires Pro (the upgrade story):**
- ❌ **SEOBetter Cloud generation** — generate without managing API keys. THIS IS THE PRO VALUE PROP. Premium-tier LLM (Claude Sonnet 4.6) for content generation, mini for extractions.
- ❌ 18 other content types (Buying Guide, Comparison, Review, Ultimate Guide, Recipe, Case Study, Interview, Tech Article, White Paper, Opinion, Press Release, Personal Essay, Glossary, Scholarly, Sponsored, Live Blog, FAQ Page, News Article)
- ❌ Analyze & Improve inject buttons (citations, quotes, tables, statistics, freshness)
- ❌ Places waterfall Tier 0 (Perplexity Sonar Pro), Tier 2 (Foursquare), Tier 3 (HERE), Tier 4 (Google Places)
- ❌ AI Featured Image with branding (5 providers including Nano Banana via OpenRouter)
- ❌ Firecrawl deep research (10× citation density vs Jina Reader)
- ❌ Serper SERP intelligence (competitor gap analysis, audience inference via LLM)
- ❌ Brave Search (Pro research source)
- ❌ Country localization (80+ country APIs)
- ❌ AIOSEO / Yoast / RankMath auto-population (focus keyword, meta, OG, schema sync)
- ❌ LocalBusiness + HowTo + ItemList schema
- ❌ Recipe Article wrapper + Speakable voice schema (v1.5.213)
- ❌ Auto-translate for 29 languages (cross-script keywords + headings + meta — v1.5.212.x)
- ❌ Bulk CSV import
- ❌ Priority support

**Free-tier financial model at 5,000 installs:**
- Article generation cost to owner: **$0** (users pay their own provider via BYOK)
- Fixed infrastructure cost: ~$50/mo (Firecrawl Standard $26 + Vercel Pro $20 + misc)
- Variable cost from free users: $0
- **Total monthly cost regardless of install count: ~$50/mo**

Compare to old "5 free Cloud articles/mo" model:
- 5,000 installs × 30% active × 5 articles/mo × $0.013 cheap config = ~$100/mo additional
- 5,000 installs × 30% active × 5 articles/mo × $0.10 premium config = ~$750/mo additional
- BYOK model removes that variable entirely.

---

## 3. Pro Tier — **$39/month or $349/year** (25% annual discount)

**Target customer:** solo bloggers, small site owners, DIY SEO operators running 1 WordPress site.

**What's unlocked over Free:**
- All 21 content types (vs 3 on Free)
- Multilingual generation (60+ languages — vs Free English-only)
- Country localization (80+ countries — vs Free 6 EN-speaking)
- Full 5-tier Places waterfall: Perplexity Sonar Pro → OSM → Foursquare → HERE → Google Places
- AI Featured Image generation with brand colors (Pollinations / Gemini 2.5 Flash Image)
- Brave Search API integration (real web citations in References section)
- AIOSEO full schema sync (Yoast + RankMath + SEOPress get meta sync at Free; AIOSEO full schema is Pro)
- All advanced schema (LocalBusiness, HowTo, ItemList, Recipe Article wrapper, Speakable, citation[], TechArticle, ScholarlyArticle)
- Auto-detect schemas (Product, Event, VideoObject, Course, etc.)
- Inline citations as clickable markdown links (via Citation Pool)
- 5 Schema Blocks (Product / Event / LocalBusiness / Vacation Rental / Job Posting)
- Brand Voice profile (1 voice — sample-post enforcement + banned-phrase regex)
- AI Citation Tracker — 1 prompt × 4 engines × weekly (THE wedge — every paid tier gets it, scaled by tier)
- Priority support (48h response)
- Remove "Powered by SEOBetter" footer
- 1 site license

**What stays at Free:** BYOK unlimited generation, basic schema (Article + FAQPage + BreadcrumbList) for the 3 free content types, GEO Analyzer + SEOBetter Score 0-100, GSC connect + view, Pexels images, OSM places, Internal Links orphan-pages report, age-based Freshness report, all SEO plugin meta sync (title + description + OG + canonical).

**Removed from prior plan:** "Analyze & Improve inject buttons" feature was removed entirely from codebase 2026-04-29 — not just degated. See Decision Log §12.

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

## 3a. Pro+ Tier — **$69/month or $619/year** (25% annual discount, NEW 2026-04-29)

**Target customer:** power solopreneur / freelance writer running 2-3 sites — captures the gap between Pro (1 site) and Agency (10 sites + 5 seats). Without Pro+, this segment overpays (buying 2-3 × Pro = $78-117/mo) or churns onto Agency overkill.

**What's unlocked over Pro:**
- **3 sites** (vs 1)
- **100 Cloud articles/mo** (vs 50)
- **3 Brand Voice profiles** (vs 1) — power solopreneurs run different voices for different niches
- **GSC-driven Freshness inventory** — uses GSC click decay + position drift for smart refresh prioritization (vs basic age-based on Pro)
- **Internal Links editor sidebar suggester** (5 suggestions/post — Link Whisper-style)
- **WooCommerce Category Intros** (5/site lifetime — Phase 5+ Pro add-on)
- **AI Citation Tracker — 5 prompts × 4 engines × weekly** (vs 1 prompt on Pro)
- **Content Brief generator unlimited** (vs Pro 3/mo)
- **Content Refresher** for stale articles (Pro doesn't get this)

**Cloud generation:** 100 Cloud articles/month using cheap config (gpt-4.1-mini extractions ~$0.013/article). Overage at $0.50/article. BYOK users (own API key) get unlimited and don't consume Cloud quota.

**Pricing psychology:** $69/mo sits cleanly between Pro $39 and Agency $179 — compromise effect anchors mid-tier as "smart pick" for buyers who outgrow 1 site but don't need an agency stack. Per-site cost: $69 ÷ 3 = $23/site (vs $39/site at Pro) — feels like value upgrade, not punishment.

**Unit economics at $69/mo (1 user, 100 articles/mo Cloud, cheap config):**
- LLM (cheap config gpt-4.1-mini): $0.013 × 100 = $1.30
- Firecrawl scrape: $0.02 × 100 = $2.00
- Serper SERP: $0.003 × 100 = $0.30
- AI Citation Tracker (5 prompts × Perplexity + SerpAPI for AI Overviews): ~$2.00
- Vercel/infra prorated: ~$0.50
- **Total cost: ~$6.10/user/mo. Margin: $69 − $6.10 = $62.90 (91%).**

---

## 4. Agency Tier — **$179/month or $1,790/year** (17% annual discount, RAISED 2026-04-29)

**Target customer:** freelance SEOs, content agencies, multi-site operators managing client WordPress sites.

**What's unlocked over Pro+:**
- **10 site licenses** (vs 3 on Pro+)
- **5 team seats** (vs 1) — agency staff each get a login
- **250 Cloud articles/mo** (vs 100 on Pro+)
- **Bulk CSV import** — 50/day cap, GEO floor 40 (rejected if below), default to draft, never auto-publish without explicit per-row `status=publish`. UX layer on existing `Async_Generator`.
- **AI Citation Tracker scaled to 25 prompts × 4 engines × weekly** (vs Pro+ 5 prompts)
- **Brand Voice unlimited + per-language** (vs Pro+ 3 voices)
- **Internal Links unlimited + auto-linking rules** (vs Pro+ 5 suggestions/post)
- **WooCommerce: unlimited Category Intros + Product Description Rewriter** (Pro+ only gets 5/site lifetime intros)
- **Cannibalization detector** — flag when two articles target the same keyword
- **Refresh-brief generator** — side-by-side diff suggestions for stale articles (humans approve, no auto-rewrite)
- **GSC Indexing API integration** — request indexing on save (Pro+ doesn't have)
- **White-label (basic)** — replace logo, hide "Powered by" footer, custom email sender. Premium WL (custom domain + full UI rebrand + whitelisted email) is a separate $99/mo add-on.
- **API access** — programmatically trigger article generation from n8n / Zapier / custom scripts
- **Custom prompt templates** per content type (save house style prompts)
- **Priority support 24h SLA + onboarding call** (first month)

**Cloud generation included:** 250 articles/month bundled across all 10 sites (pooled, not per-site). Cheap config default (~$0.013/article); premium config (Sonnet/Opus content) opt-in via Cloud Credit packs. Overage at $0.40/article (67% margin on overage — Agency rate, slight discount vs Pro overage to incentivize tier upgrade).

**Pricing psychology:** $179 is Pro × 4.6 — justified by 10× sites + 5× seats + 5× Cloud + premium features (Bulk CSV, WL, API, AI Citation Tracker scaled). Positioned as "agency revenue tool" not "bigger individual plan". Anchors at **18% under Surfer Scale AI ($219/mo)** — the optimal "slight undercut" sweet spot per Weber's Law of Just-Noticeable-Difference. Was $129 in original plan; raised to $179 on 2026-04-29 because $129 was leaving $50/mo per agency on the table — agencies bill clients $1,500-5,000/mo per site, our tool is rounding-error in their P&L.

**Unit economics at $179/mo (1 agency, 250 articles/mo Cloud, cheap config):**
- LLM (cheap config gpt-4.1-mini): $0.013 × 250 = $3.25
- Firecrawl: $0.02 × 250 = $5.00
- Serper: $0.003 × 250 = $0.75
- AI Citation Tracker (25 prompts × Perplexity + SerpAPI for AI Overviews): ~$3.00
- Vercel/infra prorated (heavier usage): ~$2.00/user/mo
- Support overhead (24h SLA, onboarding call): ~$3/user/mo amortized
- **Total cost: ~$17/user/mo. Margin: $179 − $17 = $162 (90%).**

---

## 5. Cloud Credits Add-on (Hybrid Usage Layer)

**Purpose:** capture users who don't want to manage API keys but exceed their tier's monthly quota. Sell prepaid credit packs that cover OpenRouter LLM + Firecrawl + Serper + Pexels calls behind the scenes. Stacks on top of **Pro / Pro+ / Agency / AppSumo LTD tiers**. Never blocks users — acts like overage billing.

**Build & launch timing (2026-04-29):**

| Phase | Cloud Credits status |
|---|---|
| **Phase 1** (beta, 20 users) | Not yet — beta users on $99/yr founder pricing get full Pro Cloud quota |
| **Phase 2** (Freemius integration) | **Build the backend + UI** — pack purchase, balance tracking, debit on Cloud article generation |
| **Phase 3** (AppSumo launch) | **Activate publicly** — LTD buyers exceeding lifetime Cloud cap can buy packs |
| **Phase 4-5** (WP.org + MRR scale) | Standard offering — credit packs available to all paid tiers as overage option |

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

## 7. Launch Phases — Revenue-First Sequencing (v1.5.216 update)

**Critical strategy decision (v1.5.216):** Instead of WP.org-first (which exposes a solo founder to open-ended free-tier API costs at scale), launch revenue-first. Get 20 paying beta users → AppSumo cash injection → THEN list on WP.org with proven Pro conversion.

The financial logic:
- WP.org-first risks: 5,000 free installs × open-ended Cloud quota = $500–$2,000/mo cost before any revenue lands
- Revenue-first benefits: $0 owner cost during validation phase (BYOK-only free tier per §2), then $30K-60K AppSumo cash funds 12+ months of infra

### Phase 0 — Free validation (current state, no code gates yet)
- All features unlocked for free internal testing
- Ben tests on mindiampets.com.au
- Measure actual GEO scores, AIOSEO scores, AI citation pickup (Perplexity, ChatGPT, Gemini) over 30 days
- Iterate plugin until Article 1/2/3 all land at 90+

### Phase 1 — Beta validation with 20 founder-tier paying users (week 1-4)

**Goal:** prove willingness-to-pay BEFORE listing publicly. Target $400-$1,500 MRR with 20-30 beta users.

**Pricing for beta:**
- $99/year ("Founder pricing — locked forever, never raises")
- Or $49 for 6 months
- Includes lifetime grandfathered Pro access
- 50% off normal price ($349/yr) = explicit discount in exchange for testimonial + feedback

**Distribution channels (no Twitter network required — see §7B First-20-Users Playbook below):**
- WordPress Facebook groups (free)
- Reddit r/SEO, r/Blogging, r/WordPress, r/IndieHackers (free)
- WordPress meetup organisers (warm intros)
- Paid Reddit ads ($50-100 budget)
- Cold email to existing SEO bloggers (offer free Pro in exchange for honest review)
- IndieHackers post + product update
- LinkedIn outreach (more responsive than X for B2B WordPress audience)

**Success gate:** at least 10 paying users + 5 published reviews/case studies. Don't proceed to Phase 2 until this lands.

**Owner cost during Phase 1:** ~$50/mo fixed infra. Beta users on Pro pay ~$8/mo each in API costs (covered by their $99/yr).

### Phase 2 — Freemius infrastructure (weeks 5-6)
- Integrate [Freemius SDK](https://freemius.com/wordpress/) (2-3 days of work)
- Freemius handles: license keys, subscription billing, trial management, WP.org free-variant packaging, refunds, analytics dashboard
- Build free/pro feature gates per §2 and §3
- Wire contextual upgrade CTAs at the 7 friction points (see §8)
- Set up [seobetter.com](https://seobetter.com) pricing page with monthly/annual toggle
- Beta users migrated onto Freemius as grandfathered Pro accounts

### Phase 3 — AppSumo Lifetime Deal launch (week 7-14)

**Critical step.** This is where solo-founder cash flow gets fixed.

**Restructured 2026-04-29:** original plan was a single $169 LTD price. Replaced with **5-tier ladder** matching AppSumo audience expectations (deal-hunters expect choice ladders) AND protecting Ben's lifetime API exposure with hard Cloud caps + cheap-config-only enforcement.

#### 5-tier LTD ladder

| Tier | Price | Sites | Seats | Cloud articles/mo (lifetime cap) | Equivalent subscription tier |
|---|---|---|---|---|---|
| Tier 1 | **$69** | 1 | 1 | 5 | Free++ |
| Tier 2 | **$129** | 3 | 1 | 15 | Pro features for life |
| Tier 3 | **$249** | 5 | 1 | 30 | Pro+ features for life |
| Tier 4 | **$349** | 10 | 5 | 75 | Agency features for life |
| Tier 5 | **$499** | 25 | 5 | 150 | Agency+ for life (incl. premium WL) |

#### Vendor-protection rules (mandatory)

| Protection | Mechanism |
|---|---|
| **BYOK unlimited at every tier** | User connects own AI key → unlimited generation → $0 cost to Ben |
| **Cheap config FORCED for Cloud articles** | gpt-4.1-mini extractions only (~$0.013/article). Premium config (Sonnet/Opus) gated to subscription tiers + credit packs only |
| **Hard monthly Cloud caps** | Tier exceeds → must use BYOK or buy Cloud Credit packs; cannot overflow |
| **Cloud Credit pack stacking** | Available to LTD buyers — additional revenue stream from heavy users |
| **Premium WL gated to Tier 5** | Custom domain + full UI rebrand requires DNS+DKIM support burden — Tier 5 buyers expect that level of service |

#### Margin sanity check (5-year lifetime exposure at full Cloud cap, cheap config only)

| Tier | Net to Ben (after AppSumo 30%) | 5yr cost | 5yr profit | Margin |
|---|---|---|---|---|
| Tier 1 ($69) | $48.30 | $3.90 | $44.40 | **92%** |
| Tier 2 ($129) | $90.30 | $11.70 | $78.60 | **87%** |
| Tier 3 ($249) | $174.30 | $23.40 | $150.90 | **87%** |
| Tier 4 ($349) | $244.30 | $58.50 | $185.80 | **76%** |
| Tier 5 ($499) | $349.30 | $117.00 | $232.30 | **67%** |

All tiers maintain ≥67% profit margin even at full lifetime usage. Sustainable.

#### Logistics

- Application requires: working plugin demo, 5-10 testimonials from Phase 1 beta, comparison vs competitors, screenshots, founder video pitch
- AppSumo approval: 30-60 days typically
- 7-10 day launch promotion drives the buyer rush
- AppSumo takes ~30% commission
- **Expected outcome: 500 lifetime sales × weighted avg $179 = $89,500 gross → ~$62,650 net to owner**
- Cash funds: 12+ months of infra at any reasonable scale
- Plus 500 evangelist users who leave 5-star reviews on WP.org BEFORE the directory submission goes live
- **Cloud Credits activated publicly at Phase 3 launch** — LTD buyers exceeding their lifetime monthly cap can buy credit packs ($19/$49/$129) to top up. See §5.

### Phase 4 — WordPress.org directory submission (month 4-5, AFTER AppSumo cash lands)
- Scrub free tier for WP.org guideline compliance (no hardcoded external calls, proper escaping, GPL2+, etc)
- Submit to directory (approval: 7-14 days)
- Build the directory listing: hero video, 8 screenshots, benefit-led description, FAQ
- Prepare 3 launch blog posts on seobetter.com for content traffic
- Free tier is BYOK-only at this point (per §2) — zero owner cost regardless of install count

### Phase 5 — MRR scale (months 5-12)
- WordPress.org directory drives organic free installs (~50-200/day post-launch)
- SEO content on seobetter.com targets plugin comparison keywords ("best AI content WordPress plugin 2026", "Yoast vs SEOBetter", "RankMath vs SEOBetter") — dog-fooding the product
- Retarget free installs via email nurture (day 1, 3, 7, 14, 21, 30, 45)
- Growth target: **$10K MRR by month 12** at conservative 4% conversion on ~6,000 cumulative installs

### Phase 6 — Add free Cloud articles (month 12+, optional, only if MRR comfortably covers it)
- Once MRR > $8K/mo and stable, add "3 free Cloud articles/month" as a goodwill bonus on free tier
- Cost: ~$60/mo at 5,000 installs × 30% activation × 3 articles × $0.013 cheap config
- Improves free-tier user acquisition (more generous tier = better WP.org reviews + more installs)
- Reverse if churn spikes or cost outpaces growth

---

## 7B. First-20-Paying-Users Playbook (no network required)

The fear: "I don't have 20 Twitter friends, how do I get 20 paying beta users?"
The answer: you don't need Twitter. Here are 8 proven channels for solo WordPress plugin founders, ranked by realistic conversion rate × effort.

### Channel 1: WordPress Facebook Groups (highest ROI for WP audience)
- **Groups to join (free, immediate):**
  - "WordPress Help" (200K+ members)
  - "Advanced WordPress" (40K+, more technical)
  - "WordPress Bloggers Community" (50K+, target audience)
  - "WP & SEO" (15K+, SEO-aware)
  - "WordPress Speed Up Group" (10K+, performance-focused)
- **Approach:** don't pitch immediately. Spend 1 week answering 5-10 questions in each group. THEN post a "I'm building X for $99/yr lifetime founder pricing — looking for 20 beta testers, here's a 2-min demo video" post.
- **Expected:** 5-10 paying users per active group across 2 weeks of soft engagement.
- **Cost:** $0.

### Channel 2: Reddit (r/SEO, r/Blogging, r/WordPress, r/IndieHackers)
- **Subreddits:** r/SEO (1.2M), r/Blogging (240K), r/WordPress (200K), r/IndieHackers (100K), r/SaaS (45K)
- **Approach:** post a transparent "I built this and need 20 beta users" post in r/IndieHackers (most receptive). For r/SEO and r/WordPress, frame as "Looking for SEO bloggers to test a new plugin — $99/yr founder pricing".
- **Caveat:** r/SEO and r/WordPress are STRICT on self-promotion. Read each subreddit's rules. r/IndieHackers and r/SaaS are friendly to founder pitches.
- **Expected:** 3-8 paying users per post if positioned right.
- **Cost:** $0.

### Channel 3: Cold email to existing SEO bloggers (offer free Pro in exchange for review)
- **Targets:** find 50-100 SEO/blogging YouTube creators or bloggers with ≤5K followers (smaller audience = more responsive). Use "AI SEO" / "ChatGPT SEO" / "GEO optimization" search terms.
- **Email template:**
  > Hi {name}, I'm a solo founder building SEOBetter — a WordPress plugin that writes articles AI engines (Perplexity, ChatGPT) actually cite. I'm offering free lifetime Pro access ($349/yr value) to 20 SEO content creators in exchange for: (a) honest 5-min trial, (b) one tweet/post if you like it, (c) optional 60-second testimonial. No catch, no commitment beyond trying it. Reply if interested and I'll send your license key. — Ben
- **Expected:** 5-15 reviewers from 100 emails (5-15% reply rate).
- **Cost:** $0 + 100 email outreach time (~3 hours).

### Channel 4: IndieHackers + Product Hunt soft launch
- **IndieHackers:** post in "Show IH" with "I built X, here's the code, here's the pricing, here's what I'm looking for"
- **Product Hunt:** "Coming Soon" page → email subscribers → soft launch on a Tuesday (highest traffic day)
- **Expected:** 200-500 visitors → 5-15 paying users
- **Cost:** $0.

### Channel 5: Paid Reddit ads ($50-100 budget)
- **Best subreddits to target:** r/SEO, r/Entrepreneur, r/Blogging, r/Marketing
- **Ad copy:** "AI articles your readers see in ChatGPT/Perplexity. WordPress plugin. $99/yr lifetime founder pricing → first 20 only."
- **Expected:** at $0.50 CPC, $50 = 100 visitors → 2-5 paying users.
- **Cost:** $50-100.

### Channel 6: WordPress meetup organizers (warm intros, slow-burn but high-quality)
- **Find via:** [WordPress.org/meetups/](https://www.meetup.com/topics/wordpress/) — there are ~600 active WordPress meetups worldwide
- **Approach:** email meetup organizers offering free Pro accounts for their members + 10-min lightning talk slot
- **Expected:** 1-3 organisers say yes, each → 5-10 members try it → 1-3 paying users per meetup
- **Cost:** $0 + email outreach time.

### Channel 7: LinkedIn outreach (B2B WordPress audience)
- **Search:** "WordPress consultant", "SEO consultant", "content marketing manager" + 1st degree connections
- **Approach:** message warm contacts asking if they'd test a tool you're building. Same email template as Channel 3.
- **Expected:** 5-10 paying users from 50-100 LinkedIn DMs (10% conversion is normal for warm 1st degree)
- **Cost:** $0.

### Channel 8: Niche SEO Slack/Discord communities
- **Communities:** Online Geniuses (Slack, 30K+), SEO Mavericks (Discord), Traffic Think Tank (Slack, paid but high-quality)
- **Approach:** participate genuinely for 1 week, then post the founder offer
- **Expected:** 2-5 paying users per active community.
- **Cost:** $0 (free communities) to $99/yr (Traffic Think Tank — but the audience converts at 5-10× rate).

### Aggregate target

If you hit 3-4 of these channels in the first 2 weeks, **20 paying users at $99/yr = $1,980 cash + grandfathered Pro account base** is realistic. That funds 3-4 months of infra and gives you the testimonials needed for AppSumo Phase 3.

### What NOT to do
- ❌ **Don't pay for Twitter influencer shoutouts.** The audience is wrong (consumer, not WP).
- ❌ **Don't run Google Ads early.** $5+ CPCs for "WordPress SEO plugin" — burns cash fast pre-conversion.
- ❌ **Don't do Facebook ads at this stage.** Targeting too imprecise; better used at Phase 4 retargeting.
- ❌ **Don't build a waiting list / coming soon page first.** Vanity metric. Just sell.
- ❌ **Don't discount more than 50% off.** $99/yr beta vs $349/yr regular = anchored as a deal. Below $99 signals desperation.

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

## 10. Projected MRR (Scenario Planning — Updated 2026-04-29)

Updated for 3-tier pricing (Pro $39 / Pro+ $69 / Agency $179) with industry-benchmark conversion rates.

Assume 1,000 active free installs from WordPress.org + AppSumo aftermath by month 6. Tier mix from Pro/Pro+/Agency conversion benchmarks: **60% Pro / 25% Pro+ / 15% Agency** of the converted segment.

| Conversion | Pro users | Pro+ users | Agency users | Pro MRR | Pro+ MRR | Agency MRR | Credits MRR | **Total Gross MRR** | Variable cost ~$7/Pro + $6/Pro+ + $17/Agency + 60% credits margin | **Net MRR** |
|---|---|---|---|---|---|---|---|---|---|---|
| 5% (pessimistic) | 30 | 12 | 8 | $1,170 | $828 | $1,432 | $625 | **$4,055** | ~$650 | **~$3,405** |
| 8% (SaaS median) | 48 | 20 | 12 | $1,872 | $1,380 | $2,148 | $1,000 | **$6,400** | ~$1,030 | **~$5,370** |
| 15% (AI-tools average) | 90 | 38 | 22 | $3,510 | $2,622 | $3,938 | $1,875 | **$11,945** | ~$1,930 | **~$10,015** |
| 20% (AI-tools best) | 120 | 50 | 30 | $4,680 | $3,450 | $5,370 | $2,500 | **$16,000** | ~$2,575 | **~$13,425** |

**Base case:** 8% conversion at 1,000 active installs → **~$5,370 net MRR**. Hits the $10K MRR Phase 5 target by month 12 if installs reach ~2,150 at 8% conversion, or ~1,000 at 15% conversion.

Less fixed monthly infra cost (~$50/mo) → still 95-99% net margin at scale because fixed cost is dominated by variable cost as user count grows.

Scaled to 10,000 installs (typical 2-year WP.org plugin) at 8-15% conversion: **$54,000 – $100,000 MRR**.

**AppSumo LTD 5-tier launch adds a one-time ~$62,650 net cash injection** in month 2-3 that doesn't count toward MRR but funds 6-12 months of operations. (See Phase 3 for tier breakdown.)

---

## 11. Integration with seobetter.com

The pricing page on [seobetter.com](https://seobetter.com) should mirror this document exactly:

- **Hero:** "The WordPress plugin that writes articles AI actually cites"
- **4 pricing cards:** Free / Pro ($39) / Pro+ ($69) / Agency ($179), with annual toggle showing dollar savings
- **Feature comparison table:** Free vs Pro vs Pro+ vs Agency with ✅ / ❌ marks for every feature in §2-4 (or pull directly from `pro-features-ideas.md` §2 Tier Matrix)
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
| 2026-04-29 | **3-tier paid model adopted** (Pro $39 / Pro+ $69 / Agency $179) | Original plan had 2 paid tiers. Strategic re-tier identified gap at 2-3 site segment — solopreneurs with 2 sites overpay ($78 = 2× Pro) or jump to Agency overkill. Pro+ at $69/mo with 3 sites + Brand Voice (3) + GSC Freshness driver + Internal Links suggester + AI Citation Tracker (5 prompts) + WooCommerce Category Intros captures this segment cleanly. Compromise effect anchors Pro+ as "smart middle pick" per SaaS pricing literature. ARPU lift +3% with negligible cannibalization. |
| 2026-04-29 | **Agency raised $129 → $179** (annual $1,290 → $1,790) | Competitive research showed $129 was leaving $50/mo per agency on the table. Surfer Scale AI $219, Frase Team $115 (3 seats only, no WL). Industry value of SEOBetter Agency bundle (10 sites + 5 seats + WL + Bulk + API + AI Citation Tracker scaled + Cannibalization + Refresh-brief + GSC Indexing) = $200-280/mo. Agencies are LEAST price-sensitive segment because they bill clients $1,500-5,000/mo per site — our tool is rounding-error in their P&L. $179 sits at the 18% under Surfer Scale AI sweet spot per Weber's Law of Just-Noticeable-Difference. |
| 2026-04-29 | **AppSumo LTD restructured: single $169 → 5-tier ladder ($69/$129/$249/$349/$499)** | Single price was leaving deal-hunter value on the table. AppSumo audience expects tier ladders (Scalenut, Frase, NeuronWriter all use 3-5 tier ladders historically). Weighted average $179 × 500 sales = $89,500 gross vs original $169 × 500 = $84,500. Net to Ben after AppSumo 30%: ~$62,650 vs $59,150. **Critical vendor protection:** every tier has hard monthly Cloud cap + cheap config FORCED + BYOK unlimited (so heavy users use their own keys, not Ben's). 5-yr lifetime margin sustained at ≥67% even at full cap usage. Premium WL gated to Tier 5 only (DNS+DKIM support burden). |
| 2026-04-29 | **Internal Linking RE-ADDED to roadmap** (override 2026-04-15 removal) | Strategic deep-dive recommended adding Internal Links because Link Whisper proves $77/yr willingness-to-pay just for this feature. The 2026-04-15 decision to remove it predated the deep-dive analysis. Tier split: orphan-pages report Free (teaser); editor sidebar suggester Pro+ (5 suggestions/post); unlimited + auto-linking rules Agency. |
| 2026-04-29 | Conversion rate basis locked: **5/8/15/20% benchmarks** (First Page Sage 2026, Artisan 2026 — AI-tool-specific) | Replaces back-of-envelope 3-4% generic SaaS estimate. AI-tool freemium converts higher than generic SaaS because the value prop is more concrete. Base case: 8%. Pessimistic: 5%. Optimistic: 15%. Best-case: 20%. |
| 2026-04-29 | **Inject buttons (Analyze & Improve) REMOVED entirely from codebase** | Not just feature-gated — code is deleted. Was a feature without clear value vs full content regeneration; users prefer regenerating to clicking Pro buttons. Removes maintenance burden. Locked NO. |
| 2026-04-29 | **Newsletter blocks / email capture Pro KILLED** | Off-wedge — we're a content-generation tool, not a marketing-funnel tool. Locked NO. |
| 2026-04-29 | **Cloud Credits ships Phase 2, activates publicly Phase 3** | Build the backend+UI with Freemius integration (Phase 2). Don't market until AppSumo launch (Phase 3) when LTD buyers need top-up path beyond their lifetime Cloud cap. |
| 2026-04-29 | **Country allowlist split: Free = US/UK/AU/CA/NZ/IE; Pro = 80+ countries** | The 6 EN-speaking countries get NO Regional_Context block (zero LLM cost) — confirmed in plugin_functionality_wordpress.md:391. Safe to allow free. Other 75+ countries with full localization stay Pro. |
| 2026-04-29 | **Brand Voice profiles added to Phase 1 build list** | Per competitor research §5: table-stakes Pro feature. Without it, output gets the "sounds like AI" complaint instantly. Tier split: Pro 1 voice; Pro+ 3 voices; Agency unlimited + per-language. 2-3 weeks dev. |
| 2026-04-29 | **SEOBetter Score 0-100 added to Phase 1 build list** | Per competitor research §5: psychological table-stakes (Surfer's green-dot). Buyers expect a single big number. Map existing layered GEO scores to weighted composite. 1-2 days dev. Surfaces alongside the layered GEO breakdown for power users. |
| 2026-04-29 | **Pre-launch security audit gate enforced** | New pre-Phase-3 BLOCKER: cannot accept Freemius payments until Layer 1 + Layer 2 + Layer 3 (plugin split) all pass + WP.org compliance + paid external security review (~$500-1500). Per security.md. Protects against AI-driven reverse engineering and license-bypass attempts. |

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
