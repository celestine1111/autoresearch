# SEOBetter Plugin Functionality — Complete Technical Reference

> **CODE WORD: When the user starts a prompt with `/seobetter` — READ ALL 4 .md files before coding.**
>
> **MANDATORY: Read this file + plugin_UX.md + article_design.md + SEO-GEO-AI-GUIDELINES.md before making ANY code changes.**
>
> **Purpose:** Documents every feature implemented in the SEOBetter WordPress plugin — research APIs, AI generation, content formatting, schema, scoring, and user interface.
>
> **Last updated:** April 2026

---

## 1. RESEARCH PIPELINE (Vercel Cloud API)

**Endpoint:** `POST /api/research` on `seobetter.vercel.app`
**Called by:** `Trend_Researcher::cloud_research()` in WordPress
**Parameters:** `keyword`, `domain`, `country`, `site_url`, `brave_key` (optional)

### 1.1 Always-On Sources (9 free — every article, v1.5.16+)

| Source | URL Pattern | What It Returns | Timeout |
|---|---|---|---|
| **DuckDuckGo** | `html.duckduckgo.com/html/?q={keyword}` | 8 real web results with titles, URLs, snippets. URLs become article references. | 8s |
| **Reddit** | `old.reddit.com/search.json?q={keyword}&sort=relevance&t=month&limit=10` | Posts with scores, comments, subreddit, selftext. Provides community quotes and trending discussions. | 8s |
| **Hacker News** | `hn.algolia.com/api/v1/search_by_date?query={keyword}&tags=story&hitsPerPage=8` | Tech stories with points, URLs. Best for technology/startup articles. | 8s |
| **Wikipedia** | `en.wikipedia.org/api/rest_v1/page/summary/{keyword}` | Page extract, definition, URL. Provides quotable definitions and background facts. | 6s |
| **Google Trends** | `trends.google.com/trends/api/autocomplete/{keyword}?hl=en-US` | Related trending topics and queries. Provides freshness context. | 6s |
| **Bluesky** *(v1.5.16)* | `api.bsky.app/xrpc/app.bsky.feed.searchPosts?q={keyword}&limit=8` | Public posts with author handle, likes, reposts, replies. Captures post-X tech audience. Free, no auth. | 8s |
| **Mastodon** *(v1.5.16)* | `mastodon.social/api/v2/search?q={keyword}&type=statuses&limit=8` | Public statuses from the Fediverse with author, favourites, reblogs. Multilingual (strong EU/global coverage). Free, no auth. | 8s |
| **DEV.to** *(v1.5.16)* | `dev.to/api/articles?per_page=8&search={keyword}` | Tech articles by practitioners with reactions, comments, tags, reading time. Strong for skill/coding topics globally. Free, no auth. | 8s |
| **Lemmy** *(v1.5.16)* | `lemmy.world/api/v3/search?q={keyword}&type_=Posts&sort=TopMonth` | Posts from federated Reddit-alternative communities with score, comments, community name. Free, no auth. | 8s |

**Why these 9?** As of v1.5.16 the always-on social signal is broad and free. X/Twitter is **deliberately not included** — there is no clean free X API in 2026 (see [pro-features-ideas.md → X / Twitter integration](pro-features-ideas.md) for the cookie-auth path planned for a future release). The 4 new v1.5.16 sources collectively replace ~70% of what X used to provide, across more languages and niches.

### 1.2 Optional Pro Source

| Source | URL Pattern | Requires | What It Returns |
|---|---|---|---|
| **Brave Search** | `api.search.brave.com/res/v1/web/search?q={keyword}` | User's Brave API key | 10 web results with descriptions, ages. Statistics extracted from snippets. |

### 1.3 Category-Specific APIs (25 categories — v1.5.15 fixed)

Selected by the user's **Category** dropdown. All run in parallel with 6s timeout. The same 25-category list is exposed in [admin/views/content-generator.php](../admin/views/content-generator.php), [admin/views/bulk-generator.php](../admin/views/bulk-generator.php), and [admin/views/content-brief.php](../admin/views/content-brief.php) — these MUST stay in sync (see plugin_UX.md §9).

| Category (dropdown value) | APIs Called | API Count |
|---|---|---|
| **Animals & Pets (Trivia)** (`animals`) | FishWatch, Zoo Animals, Dog Facts, Cat Facts, MeowFacts | 5 |
| **Art & Design** (`art_design`) | Art Institute of Chicago, Metropolitan Museum | 2 |
| **Blockchain** (`blockchain`) | CoinGecko, CoinCap, Mempool, Coinpaprika | 4 |
| **Books & Literature** (`books`) | Open Library, PoetryDB, Crossref, Quotable | 4 |
| **Business** (`business`) | Econdb, World Bank, Fed Treasury | 3 |
| **Cryptocurrency** (`cryptocurrency`) | CoinGecko, CoinCap, CoinDesk BPI, Coinpaprika, Coinlore, CryptoCompare, Mempool | 7 |
| **Currency & Forex** (`currency`) | Frankfurter, Currency-API, World Bank | 3 |
| **Ecommerce** (`ecommerce`) | Open Food Facts | 1 |
| **Education** (`education`) | Universities List, Nobel Prize, Crossref, Open Library, World Bank | 5 |
| **Entertainment & Movies** (`entertainment`) | Open Trivia, OMDb/IMDb, SWAPI, PokéAPI, Quotable | 5 |
| **Environment & Climate** (`environment`) | OpenAQ, UK Carbon Intensity, CO2 Offset, USGS Water | 4 |
| **Finance & Economics** (`finance`) | Econdb, Fed Treasury, SEC EDGAR, World Bank | 4 |
| **Food & Drink** (`food`) | Open Food Facts, Fruityvice, Open Brewery DB | 3 |
| **Games & Gaming** (`games`) | FreeToGame, RAWG, PokéAPI, Open Trivia | 4 |
| **General** (`general`) | Quotable, Nager.Date holidays, Numbers API | 3 |
| **Government, Law & Politics** (`government`) | Data USA, FBI Wanted, Interpol, Federal Register, Nager.Date | 5 |
| **Health & Medical (Human)** (`health`) | disease.sh (COVID/flu), openFDA (drug events) | 2 |
| **Music** (`music`) | MusicBrainz, Bandsintown | 2 |
| **News & Media** (`news`) | Spaceflight News, HN Top Stories, Federal Register | 3 |
| **Science & Space** (`science`) | NASA, USGS Earthquakes, Launch Library, SpaceX, USGS Water, Sunrise/Sunset, Numbers API, Crossref | 8 |
| **Sports & Fitness** (`sports`) | balldontlie (NBA), Ergast F1, NHL Stats, CityBikes | 4 |
| **Technology** (`technology`) | HN Top Stories, Crossref | 2 |
| **Transportation & Travel** (`transportation`) | OpenSky, Open Charge Map, ADS-B Exchange, CityBikes, NHTSA | 5 |
| **Veterinary & Pet Health** (`veterinary`) — **NEW v1.5.15** | Crossref (veterinary filtered), EuropePMC, OpenAlex (vet concept), openFDA (vet scoped), Dog Facts | 5 |
| **Weather & Climate** (`weather`) | Open-Meteo, US NWS, Sunrise/Sunset, OpenAQ | 4 |

#### v1.5.15 changes

- **Added `veterinary` category** with 3 new academic API fetchers: `fetchCrossrefFiltered()`, `fetchEuropePMC()`, `fetchOpenAlex()` — see [cloud-api/api/research.js](../cloud-api/api/research.js) lines ~1689-1735. These return real peer-reviewed veterinary literature (Crossref subject-filtered, EuropePMC biomedical, OpenAlex topic-concept-filtered) so dog/cat/equine articles get vet-grade citations instead of cat-fact trivia.
- **Merged `government` + `law_government`** into a single `government` entry with backwards-compat alias. The old `law_government` value still resolves to `government` for legacy clients.
- **Relabeled `health`** to "Health & Medical (Human)" so users don't accidentally pick it for vet topics.
- **Relabeled `animals`** to "Animals & Pets (Trivia)" so users know it's the trivia bucket, not the research bucket.
- **All 3 dropdown forms now share the same 25-category list** (was: 25 in main form, 8 in bulk + brief).

### 1.4 Country-Specific APIs (80+ countries)

Selected by the user's **Country & Language** dropdown. Each country can have:
- **CKAN portal** — government open data search (most countries)
- **Statistics office** — national stats API
- **Central bank** — exchange rates, economic data
- **Weather service** — national weather data
- **Specialized APIs** — earthquakes, archives, transport, etc.
- **Regional portals** — state/province data (top 2 queried)

#### Countries with Full API Suites

| Country | CKAN | Stats | Bank | Weather | Specialized | Regions |
|---|---|---|---|---|---|---|
| **Australia** | data.gov.au | ABS (CPI) | RBA exchange | BOM Sydney | Earthquakes, Trove Archives, Space Weather, Melbourne Data | NSW, VIC, QLD, WA, SA |
| **United States** | data.gov | Census | Treasury | NWS alerts | USGS Earthquakes, FDA, Federal Register | New York, California |
| **United Kingdom** | data.gov.uk | ONS | Carbon Intensity | Floods | Police Data, NHS | — |
| **Canada** | open.canada.ca | — | Bank of Canada | Weather GC | Earthquakes | Ontario, BC, Toronto |
| **Brazil** | dados.gov.br | IBGE | BCB exchange | — | — | — |

#### Countries with CKAN Portal Only (60+)

NZ, MX, IE, ES, PT, IT, NL, BE, CH, AT, LU, CY, MT, DK, FI, IS, EE, LV, LT, CZ, SK, HU, SI, HR, RS, BG, RO, UA, MD, MK, AL, BA, ME, XK, TR, TW, ID, PH, TH, VN, PK, BD, LK, NP, MV, MN, KZ, UZ, KG, IL, KW, OM, CO, PE, UY, PY, EC, BO, CR, PA, DO, GT, SV, JM, TT, NG, GH, TZ, UG, TN, SN, CI, CM, BF, BJ, TG, FJ

#### Countries with Specialized APIs Only

| Country | Specialized API |
|---|---|
| Germany | Bundesbank |
| France | INSEE, French Companies |
| Sweden | Riksbank |
| Norway | MET Norway weather |
| Poland | NBP exchange rates |
| Ukraine | NBU exchange rates |
| Russia | CBR exchange rates |
| Japan | e-Stat statistics |
| South Korea | KOSIS statistics |
| China/HK | HK Open Data |
| Singapore | SG Environment |
| Malaysia | Malaysia Data Catalogue |
| India | India Open Data |
| Argentina | BCRA statistics |
| Chile | mindicador.cl |
| South Africa | Wazimap demographics, Municipal Finance |
| UAE | Bayanat Open Data |
| Saudi Arabia | Saudi Open Data |
| Qatar | Qatar Open Data |
| Bahrain | Bahrain Open Data |
| Jordan | Jordan Statistics |
| Kenya | Kenya Open Data |
| Rwanda | Rwanda Statistics |
| Egypt | CAPMAS statistics |
| Morocco | Morocco Open Data |

### 1.5 Low-Authority Sources (Filtered from References)

These provide data for AI context but their URLs are excluded from article references:

Dog Facts API, Cat Facts API, MeowFacts, Zoo Animals API, Free Dictionary API, Numbers API, Fruityvice, Quotable, Open Trivia Database, SWAPI, PokéAPI, Currency Exchange API, ADS-B Exchange

### 1.6 Research Data Output

All sources combined into `for_prompt` string containing:
- **VERIFIED STATISTICS** — numbers with source attribution
- **QUOTES FROM REAL SOURCES** — text from Reddit, Wikipedia, web results
- **TRENDING DISCUSSIONS** — Reddit titles, HN stories, Google Trends
- **SOURCES FOR REFERENCES** — real URLs for article citations (up to 20)

### 1.7 Topic Research Endpoint (`/api/topic-research`, v1.5.22 enhanced)

Separate endpoint from the main `/api/research` used for generation. Pulls real keyword demand data from 5 sources (no LLM hallucination) and powers TWO UI features:

1. **Sidebar Topic Suggester** — "Suggest 10 Topics" button in the Generate page sidebar. Returns scored topics with intent classification.
2. **Auto-suggest button** — next to the Primary Keyword input. Populates Secondary Keywords + LSI Keywords fields with real Google Suggest variations + Datamuse semantic clusters.

**Request:** `POST /api/topic-research` with `{ niche, site_url }`

**Response:**
```json
{
  "success": true,
  "niche": "...",
  "topics": [ ...full topic ideas with intent/score/reason ],
  "keywords": {
    "secondary": [ "real google suggest phrase 1", "real phrase 2", ... up to 7 ],
    "lsi":       [ "datamuse word 1", "datamuse word 2", ... up to 10 ],
    "secondary_string": "pre-joined comma-separated string for UI",
    "lsi_string":       "pre-joined comma-separated string for UI"
  },
  "sources": { "google_suggest": N, "datamuse": N, "wikipedia": N, "reddit": N }
}
```

**Data sources:**
- `keywords.secondary` — extracted from Google Suggest (real search queries people type). Filtered to phrases that share at least one word with the niche, 6-80 chars long.
- `keywords.lsi` — extracted from Datamuse semantic word clusters, single words 4-30 chars. Falls back to 1-2 word Wikipedia titles if Datamuse returns fewer than 6 results.

**v1.5.22 context:** Before this release, the Auto-suggest button called `/api/generate` (LLM) with a strict-format prompt + fragile regex parser that frequently failed silently when Llama wrapped its output in markdown (e.g. `**SECONDARY:**`). The LLM path is gone. Auto-suggest now uses this endpoint directly — same data source as the sidebar Topic Suggester for consistency.

**Source:** [cloud-api/api/topic-research.js::buildKeywordSets()](../cloud-api/api/topic-research.js)

---

## 2. AI GENERATION PIPELINE (Async_Generator)

### 2.1 Generation Steps (Sequential)

| Step | What Happens |
|---|---|
| 1. **Trends** | Calls Vercel research endpoint with keyword + domain + country. Stores research data. Detects search intent. |
| 2. **Outline** | AI generates H2 heading list based on: content type prose template, search intent, tone, audience, domain, research data, keyword |
| 3-N. **Sections** | Each section generated individually with: heading, keyword rules, content type guidance, tone guidance, research data, humanizer rules |
| N+1. **Headlines** | AI generates 5 title variations scored by SEO criteria |
| N+2. **Meta** | AI generates SEO title + meta description |
| N+3. **Assemble** | Markdown assembled, images inserted, formatted to HTML, GEO scored |

### 2.2 System Prompt (Applied to Every Section)

Contains:
- Current year enforcement (never 2024/2025)
- Language instruction (if non-English)
- Keyword density rules (0.5-1.5%, every 100-200 words)
- GEO visibility rules (+41% quotes, +40% stats, +30% citations)
- E-E-A-T requirements (experience, expertise, authority, trust)
- NLP entity optimization (proper nouns, salience, 5%+ entity density)
- Humanizer rules (50+ banned words, 11+ banned patterns, rhythm, transitions)
- Anti-hallucination (never invent sources, link to specific pages)

### 2.3 Content Type Prose Templates (21 Types)

Each content type has: required sections, writing guidance, schema type, shared SEO/humanizer suffix.

| Content Type | Schema | Sections |
|---|---|---|
| Blog Post | BlogPosting | Hook → Body → Conclusion → CTA |
| How-To Guide | HowTo | Prerequisites → Steps → Troubleshooting |
| Listicle | Article + ItemList | Intro → Numbered Items → Conclusion |
| Product Review | Review | Specs → Experience → Pros/Cons → Verdict |
| Comparison | Article + FAQPage | Overview Table → Criterion Sections → Verdict |
| Buying Guide | Article + ItemList | Quick Picks → Mini-Reviews → Buying Advice |
| News Article | NewsArticle | Lede → Details → Background → Closing |
| FAQ Page | FAQPage | Intro → Q&A Pairs |
| Ultimate Guide | Article | TOC → Chapters → Summary → Resources |
| Recipe | Recipe | Story → Tips → Ingredients → Steps → Notes |
| Case Study | Article | Summary → Challenge → Solution → Results |
| Interview | Article | Intro → Bio → Q&A → Closing |
| Tech Article | TechArticle | Build → Prerequisites → Walkthrough → Testing |
| White Paper | Report | Summary → Problem → Methodology → Findings |
| Opinion | OpinionNewsArticle | Thesis → Arguments → Counterargument |
| Press Release | NewsArticle | Headline → Dateline → Body → Boilerplate |
| Personal Essay | BlogPosting | Scene → Tension → Reflection → Resolution |
| Glossary | Article | Definition → Explanation → Examples |
| Scholarly | ScholarlyArticle | Abstract → Methods → Results → Discussion |
| Sponsored | AdvertiserContentArticle | Disclosure → Body → CTA |
| Live Blog | LiveBlogPosting | Intro → Timestamped Updates |

### 2.4 Search Intent Detection

| Intent | Trigger Words | Structure Adaptation |
|---|---|---|
| Commercial | best, top, review, compare, vs | Comparison tables, pros/cons, recommendations |
| Transactional | buy, price, order, discount | Product focus, pricing, CTAs |
| Navigational | brand name, login, official | Brand-focused, official sources |
| Informational | what, how, why (default) | Comprehensive guide, definitions, FAQ |

### 2.5 Tone Guidance

| Tone | Voice Description |
|---|---|
| Authoritative | Confident, specific numbers, clear positions |
| Conversational | Contractions, "you/your", rhetorical questions |
| Professional | Business language, consultant-to-client |
| Educational | Define terms, analogies, simple to complex |
| Journalistic | Lead with facts, short paragraphs, quote people |

---

## 3. CONTENT FORMATTING (Content_Formatter)

### 3.1 Two Formats

| Format | Used For | Method |
|---|---|---|
| **Classic** | Plugin preview, improve endpoint | `format_classic()` — scoped `<div>` with `<style>` block |
| **Hybrid** | Saved WordPress draft | `format_hybrid()` — native Gutenberg blocks + wp:html for styled elements |

### 3.2 Hybrid Format Block Types

**Editable blocks (native Gutenberg):**
- `wp:heading` — H1, H2, H3
- `wp:paragraph` — body paragraphs
- `wp:list` — standard bullet/numbered lists
- `wp:image` — centered, lazy loaded
- `wp:separator` — horizontal rules

**Styled blocks (wp:html with inline styles + !important):**
- Key Takeaways — gradient bg, accent left border
- Pros list — green bg (#f0fdf4), green border, green text
- Cons list — red bg (#fef2f2), red border, red text
- Ingredients list — amber bg (#fffbeb), amber border
- Tip callout — blue left border, blue bg
- Note callout — amber left border, amber bg
- Warning callout — red left border, red bg
- Tables — accent headers, zebra striping, rounded corners
- Blockquotes — accent left border, gray bg, italic
- **Stat callout** (`v1.5.14`) — light purple bg, pulled-out 2em number on the left, body on the right
- **Expert quote** (`v1.5.14`) — italic blockquote with `<footer>` attribution line
- **Definition box** (`v1.5.14`) — gray bg, accent term + middot + body text
- **Did-You-Know box** (`v1.5.14`) — soft yellow bg, amber eyebrow label, no icons
- **Highlight sentence** (`v1.5.14`) — 1.15em accent-color sentence with 6px accent border
- **HowTo step boxes** (`v1.5.14`) — numbered circle badge per step, only for `content_type === 'how_to'` ordered lists
- **Social media citation** (`v1.5.17`) — slate bg with 4px slate left border, red uppercase "SOCIAL MEDIA CITATION — REVIEW BEFORE PUBLISHING" eyebrow, quote body, attribution link, dashed-border footnote warning about AI-generated content. Detected from blockquote starting with `[platform @handle]` marker. Required for any Reddit/HN/Bluesky/Mastodon/DEV.to/Lemmy claim so the user can verify each one before publishing.

### 3.3 Context Detection

Lists are styled based on the preceding heading text. **v1.5.14 widened these regexes** to catch more synonyms:
- `pros|advantage|benefit|upside|highlight` → green Pros box
- `cons|disadvantage|drawback|downside|limitation|trade-off` → red Cons box
- `ingredient|supplies|what you need|materials|tools|prerequisite` → amber Ingredients box
- `key takeaway|key insight|main point|at a glance|tldr|tl;dr|what to know|the bottom line` → gradient Takeaways box
- (NEW v1.5.14) ordered list inside `content_type === 'how_to'` not matching above → numbered Step boxes

Paragraphs are styled based on text content (v1.5.14 added 5 new patterns):
- Starts with "Tip:" → blue callout
- Starts with "Note:/Important:" → amber callout
- Starts with "Warning:/Caution:" → red callout
- (NEW v1.5.14) Starts with "Did you know?/Fun fact" → yellow Did-You-Know box (capped at 1/article)
- (NEW v1.5.14) Starts with `**Term**:` → gray Definition box
- (NEW v1.5.14) Entire paragraph is a single bold sentence `**...**` → accent Highlight box
- (NEW v1.5.14) Matches `"quote" — Name, Title` pattern → italic Expert Quote with attribution footer
- (NEW v1.5.14) Contains `\d%` or `\d out of \d` or `\d in \d` → Stat callout with pulled-out number

Blockquotes are styled based on the first-line marker (v1.5.17):
- (NEW v1.5.17) `> [bluesky @handle] quote text` (or mastodon/reddit/hn/dev.to/lemmy) → Social Media Citation card with review warning banner — **required for all social citations** so the user can verify before publishing
- Otherwise → generic styled blockquote with accent left border (fallback)

The system prompt at [Async_Generator::get_system_prompt()](../includes/Async_Generator.php#L601) now includes a `RICH FORMATTING` block (added v1.5.14) that instructs the AI to use these trigger words/structures naturally so the boxes fire reliably. See [SEO-GEO-AI-GUIDELINES.md §4.8](SEO-GEO-AI-GUIDELINES.md#L222) for the full trigger → box mapping.

---

## 4. SCHEMA INJECTION

### 4.1 Where Schema Goes

Schema JSON-LD is injected directly into `post_content` as a `<!-- wp:html -->` block containing `<script type="application/ld+json">`. This appears in EVERY article regardless of SEO plugin.

Additionally stored in:
- `_seobetter_schema` post meta (backup for wp_head output)
- AIOSEO `wp_aioseo_posts` table (if AIOSEO active)
- Yoast/RankMath post meta (if those plugins active)

### 4.2 Schema Types Generated

| Content Type | Primary Schema | Additional Schema |
|---|---|---|
| Blog Post | Article | FAQPage (if FAQ section exists) |
| How-To | HowTo (steps auto-extracted) | FAQPage |
| Recipe | Recipe (ingredients + instructions) | Article |
| Review | Review (itemReviewed) | Article, FAQPage |
| News | NewsArticle | FAQPage |
| FAQ Page | FAQPage | Article |
| Tech Article | TechArticle | FAQPage |
| Scholarly | ScholarlyArticle | — |
| White Paper | Report | — |
| Live Blog | LiveBlogPosting | — |
| Opinion | OpinionNewsArticle | FAQPage |
| Sponsored | AdvertiserContentArticle | FAQPage |

### 4.3 Schema Fields

Every schema includes: headline, description, datePublished, dateModified, author (name), mainEntityOfPage (URL), image (if featured image set).

FAQPage schema: Q&A pairs auto-extracted from H3 question headings followed by paragraph answers.

HowTo schema: steps auto-extracted from `<li>` elements.

Recipe schema: ingredients from `<li>` elements, instructions as HowToStep array.

---

## 5. GEO SCORING (GEO_Analyzer)

### 5.1 Scoring Rubric (11 Checks)

| Check | Weight | Score 100 | Score 0 |
|---|---|---|---|
| Readability | 12% | Grade 6-8 | Grade 14+ |
| Key Takeaways | 10% | Present with 3 bullets | Missing |
| Section Openers | 10% | 25-75 word openers | None |
| Island Test | 10% | No pronoun starts | 20%+ violate |
| Statistics | 12% | 3+ per 1000 words | 0 stats |
| Citations | 12% | 5+ inline citations | 0 citations |
| Expert Quotes | 8% | 2+ attributed quotes | 0 quotes |
| Tables | 6% | 1+ comparison tables | 0 tables |
| Lists | 5% | 4+ lists | 0 lists |
| Freshness | 7% | "Last Updated" present | Missing |
| Entity Density | 8% | 5%+ named entities | Under 1% |

### 5.2 Content-Type-Aware Scoring

Section opener check is skipped for: recipe, faq_page, live_blog, interview, glossary_definition (these have non-prose structures that shouldn't be scored for prose openers).

---

## 6. POST-GENERATION FEATURES

### 6.1 Analyze & Improve (8 Fix Types)

| Fix | Trigger | Impact | Pro Required |
|---|---|---|---|
| Simplify Readability | Grade > 8 | +12 pts | Yes |
| Add Citations | < 5 citations | +12 pts | Yes |
| Add Expert Quotes | < 2 quotes | +8 pts | Yes |
| Add Statistics | Low factual density | +12 pts | Yes |
| Add Comparison Table | No tables | +6 pts | Yes |
| Add Freshness Signal | No date | +7 pts | Yes |
| Fix Section Openings | Weak openers | +10 pts | Yes |
| Fix Pronoun Starts | Island Test fails | +10 pts | Yes |

### 6.2 Headline Selection

5 headlines generated, scored client-side:
- Length 50-60 chars: +25 pts
- Keyword front-loaded: +25 pts
- Contains number: +10 pts
- Contains current year: +10 pts
- Question format: +10 pts
- Power words: +10 pts
- Structured (colon/dash): +5 pts

### 6.3 Save Draft

Creates WordPress draft with:
- Hybrid format content (editable + styled blocks)
- JSON-LD schema injected at end
- Featured image from Pexels
- AIOSEO/Yoast/RankMath meta populated
- Post meta: focus keyword, meta title, description, generated date

---

## 7. PLUGIN UI FEATURES

See **plugin_UX.md** for the complete UI specification including:
- 11 form fields (keyword, content type, tone, category, country, etc.)
- Progress panel with timer and step counter
- GEO score dashboard (SVG ring, stat cards, bar charts)
- Analyze & Improve panel with Pro-gated fix buttons
- Headline selector with scoring
- Save draft with schema feedback

---

## 8. SEO PLUGIN INTEGRATION

| Plugin | What SEOBetter Sets |
|---|---|
| **AIOSEO** | Title, description, keyphrases, OG title/desc, Twitter title/desc, schema (in wp_aioseo_posts table) |
| **Yoast SEO** | Title, description, focus keyword (post meta) |
| **RankMath** | Title, description, focus keyword (post meta) |
| **SEOPress** | Not directly integrated (uses post meta fallback) |
| **No plugin** | Schema via wp_head hook + post meta |

---

## 9. HUMANIZER SYSTEM

Based on 3 amalgamated skills (Wikipedia AI Cleanup, humanize-writing v2.0, HumanizerAI):

### Banned Words (Tier 1 — immediate red flags)
delve, tapestry, landscape (metaphorical), paradigm, leverage (verb), harness, navigate (metaphorical), realm, embark, myriad, plethora, multifaceted, groundbreaking, revolutionize, synergy, ecosystem (non-technical), resonate, streamline, testament, pivotal, cornerstone, game-changer, nestled, breathtaking, stunning, seamless, vibrant, renowned

### Banned Patterns
- Copula avoidance (serves as → is)
- Em dash overuse (max 1 per 500 words)
- Rule of three (unless third item adds value)
- -ing phrase padding (highlighting, showcasing, underscoring)
- Negative parallelisms (not only X but Y)
- False ranges (from X to Y)
- Generic endings (future looks bright)
- Synonym cycling (pick one term, stick with it)
- Signposting (let's dive in, here's what you need to know)
- Authority tropes (the real question is, at its core)

---

## 10. MULTILINGUAL SUPPORT

- 45+ languages supported via Country & Language selector
- System prompt includes language instruction for non-English
- Article written entirely in selected language (headings, body, FAQ, references)
- Keyword used as-is regardless of language

---

## 10B. URL INTEGRITY RULES (CRITICAL — No Fake Links)

### The Problem
The AI invents plausible-looking URL paths. Real domains (rspca.org.au) with fake paths (/adopt-pet/puppy-guide) leading to 404 errors.

### Rules Enforced in Prompts
1. Use ONLY URLs from the RESEARCH DATA (DuckDuckGo results, API data)
2. If mentioning an organization NOT in research data, link to homepage domain only
3. NEVER guess or invent subpage paths — they will be 404 errors
4. Every outgoing link must lead to a real page
5. References section: only URLs from research data, no invented links

### Where This Is Enforced

**Layer 1 — AI Prompt Rules (prevention):**
- System prompt: "NEVER guess or invent a page path"
- Section prompt: "link to homepage domain only if URL not in research data"
- References prompt: "Use ONLY the URLs listed above"
- Prose template: "Every outgoing link must lead to a real page"

**Layer 2 — Post-Generation URL Validator (guarantee):**
`validate_outbound_links()` in seobetter.php runs BEFORE the article is formatted and saved:
1. Extracts both markdown `[text](url)` AND HTML `href="url"` links
2. Sends HTTP HEAD request to each URL (4s timeout, Mozilla/5.0 user-agent)
3. Based on response:
   - **200-399**: Keep the URL (verified real)
   - **404/410**: Replace with homepage domain (e.g., `https://rspca.org.au/`)
   - **403**: Replace with homepage (site blocks bots, URL may be fake)
   - **Network error**: Replace with homepage domain
   - **Homepage also dead**: Remove the link entirely (keep text only)
4. Results cached per URL to avoid duplicate checks
5. Internal links (same domain) are skipped
6. Runs on BOTH `$markdown` AND `$content` fallback paths

This validator works regardless of which AI model generates the content — Claude, GPT, Gemini, Llama, Mistral, or any model via OpenRouter. It is the final safety net that guarantees zero 404 links in published articles.

### wp:more Block
A `<!-- wp:more -->` block is inserted after the 2nd regular paragraph in every article. This creates the "Read More" break on archive/listing pages in WordPress.

---

## 11. GUTENBERG EDITOR INTEGRATION

### 11.1 Pre-Publish Panel (PluginPrePublishPanel)

Appears in the Gutenberg publish confirmation screen (when user clicks "Publish" button). Shows:

| Item | Green (OK) | Red (Issue) |
|---|---|---|
| GEO Score | 70+ with grade | Below 70 |
| Citations | 5+ found | Under 5 |
| Expert Quotes | 2+ found | Under 2 |
| Readability | Grade 6-8 | Grade 10+ |
| Schema | Type detected | Missing |

**Note:** PluginPrePublishPanel was removed in v1.4.1 — crashes on WP Engine / WP 6.6+.

### 11.2 Post Sidebar Panel (PluginDocumentSettingPanel) — PRIMARY

**This is the main editor integration.** All editor features are in a single PluginDocumentSettingPanel.
PluginSidebar and PluginPrePublishPanel were removed due to crash issues on WP 6.6+ / WP Engine.

Shows in the Post tab of the right sidebar with `initialOpen: true`:

**Score Ring (SVG, animated):**
- 100px animated circle with score number and /100 text
- Color: green (80+), amber (60+), red (<60)
- Rating text: Excellent! 🔥🔥🔥 / Great! 🔥🔥 / Good 🔥 / Needs work / Improve this

**7 Stat Rows (✓/✗ indicators):**
| Stat | Pass Threshold | Display |
|---|---|---|
| 📝 Words | ≥800 | Formatted number |
| ⏱ Read Time | always pass | X min (words/200) |
| 📖 Readability | grade 6-10 | Grade number |
| 🔗 Citations | ≥5 | count/5 |
| 💬 Quotes | ≥2 | count/2 |
| 📋 Tables | ≥1 | count |
| 🕐 Freshness | score ≥100 | Yes/No |

**Headline Analyzer (collapsible):**
- Toggle with 📰 icon, ▲/▼ arrows
- Type detection: List, How-to, Question, Comparison, Review, General
- Character Count: pass 45-65 chars
- Word Count: pass 6-12 words
- Common Words %: goal 20-30%
- Power Words %: goal ≥1, shows found words
- Emotional Words %: goal ≥5%
- Sentiment: Positive 😊 / Neutral 😐 / Negative 😟
- Beginning & Ending Words: first 3 + last 3 as gray pills

**Re-analyze button:** Clears cached data and re-runs `/seobetter/v1/analyze/{post_id}`

**File:** `assets/js/editor-sidebar.js` — `SEOBetterPanel` function
**Data source:** `GET /seobetter/v1/analyze/{post_id}` REST endpoint
**Pro detection:** `window.seobetterData.isPro` via `wp_localize_script`

### 11.3 Toolbar Score Badge (DOM injection)

Colored pill badge injected into `.edit-post-header__settings` (next to Save button):
- 📊 icon + score/100 text
- Color matches score (green/amber/red)
- Injected via `document.createElement` (not React) — cannot crash the plugin
- Retries at 0ms, 1000ms, 3000ms to account for async editor rendering
- Updates when analysis data changes

**File:** `assets/js/editor-sidebar.js` — `injectToolbarBadge()` function

### 11.4 Architecture Notes

- **Single `registerPlugin('seobetter', ...)` call** — only one plugin registration
- **No PluginSidebar** — removed in v1.4.1, crashes on WP 6.6+ / WP Engine
- **No PluginPrePublishPanel** — removed in v1.4.1, same crash issue
- **All ES5 syntax** — no arrow functions, no optional chaining (?.), no const/let destructuring
- **Component resolution:** `wp.editor.PluginDocumentSettingPanel || wp.editPost.PluginDocumentSettingPanel`
- **Shared cache:** `cachedData` variable prevents duplicate API calls across re-renders
- **Error isolation:** toolbar badge is DOM-injected (outside React error boundary)

### 11.5 Footer Metabox (added in v1.5.0)

AIOSEO-style settings panel that appears below the post content area on Post and Page screens.

**Registration:** `add_meta_box('seobetter-settings', 'SEOBetter Settings', ...)` on `add_meta_boxes` hook
**Render:** `seobetter.php::render_metabox(\WP_Post $post)`
**Save:** `seobetter.php::save_metabox()` hooked to `save_post`

**Three tabs (vanilla JS tab switching, no React):**

#### Tab 1 — General
- SERP Preview card showing site name + URL + blue title + meta description
- Focus Keyword input field (saves to `_seobetter_focus_keyword`)
- 4-stat grid: GEO Score, Words, Citations count, Quotes count

#### Tab 2 — Page Analysis
12 SEO checks computed at render time:
| Check | Pass Criteria |
|---|---|
| Focus Keyword in content | Keyword appears in post content |
| Focus keyword in introduction | Keyword in first 100 words |
| Focus keyword in meta description | Keyword in meta description |
| Focus Keyword in URL | Keyword slug in permalink |
| Focus keyword length | 3-50 chars |
| Meta description length | 120-160 chars |
| Content length | ≥300 words |
| Focus Keyword in Subheadings | ≥30% of H2/H3 contain keyword |
| Focus keyword density | ≥0.5% (shows current density) |
| Focus keyword in image alt | At least 1 image alt with keyword |
| Internal links | ≥1 same-domain link |
| External links | ≥1 cross-domain link |

#### Tab 3 — Readability
- Reading Grade level from `checks.readability.flesch_grade`
- Island Test status from `checks.island_test`
- Section Openings detail from `checks.section_openings`
- Top 5 prioritized suggestions (high=red, medium=amber)

**Score badge** in top-right of metabox header — color-coded green/amber/red.

### 11.6 Inject-Only Fix System (v1.5.0)

`includes/Content_Injector.php` provides 8 fix methods that NEVER edit existing content.

**5 Inject Methods (additive — append/insert new content):**
1. `inject_citations()` — fetches real URLs from Vercel research API, appends `## References` section
2. `inject_quotes()` — AI generates 2 quotes, inserts as blockquotes after H2 headings (skips Key Takeaways/FAQ/References)
3. `inject_table()` — AI generates 4-column markdown table, inserts after first content H2
4. `inject_freshness()` — Prepends `Last Updated: [Month Year]` to article top
5. `inject_statistics()` — Pulls real stats from research API or AI fallback, inserts `**Key Statistics:**` callout

**3 Flag Methods (read-only — show issues, never edit):**
6. `flag_readability()` — returns long sentences (>25 words) + complex words with simpler replacements
7. `flag_pronouns()` — returns paragraphs starting with It/This/They/These/Those/He/She/We
8. `flag_openers()` — returns H2 headings whose first paragraph isn't 30-70 words

**REST endpoint:** `POST /seobetter/v1/inject-fix`
- Params: `fix_type`, `markdown`, `keyword`, `accent_color`
- Returns: `success`, `content`, `markdown`, `geo_score`, `grade`, `checks`, `added`, `type`
- Routes to appropriate `Content_Injector::inject_*` or `flag_*` method
- Re-formats and re-scores the updated content

**Why this approach:**
- Zero hallucinated URLs (citations come from real web search)
- Never wipes user edits (inject = append/insert, never rewrite)
- Flag-only fixes educate the user instead of black-box rewriting

---

*This document is the authoritative technical reference for all SEOBetter functionality. Update when features are added or changed.*
