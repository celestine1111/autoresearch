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

### 1.1 Always-On Sources (5 free — every article)

| Source | URL Pattern | What It Returns | Timeout |
|---|---|---|---|
| **DuckDuckGo** | `html.duckduckgo.com/html/?q={keyword}` | 8 real web results with titles, URLs, snippets. URLs become article references. | 8s |
| **Reddit** | `old.reddit.com/search.json?q={keyword}&sort=relevance&t=month&limit=10` | Posts with scores, comments, subreddit, selftext. Provides community quotes and trending discussions. | 8s |
| **Hacker News** | `hn.algolia.com/api/v1/search_by_date?query={keyword}&tags=story&hitsPerPage=8` | Tech stories with points, URLs. Best for technology/startup articles. | 8s |
| **Wikipedia** | `en.wikipedia.org/api/rest_v1/page/summary/{keyword}` | Page extract, definition, URL. Provides quotable definitions and background facts. | 6s |
| **Google Trends** | `trends.google.com/trends/api/autocomplete/{keyword}?hl=en-US` | Related trending topics and queries. Provides freshness context. | 6s |

### 1.2 Optional Pro Source

| Source | URL Pattern | Requires | What It Returns |
|---|---|---|---|
| **Brave Search** | `api.search.brave.com/res/v1/web/search?q={keyword}` | User's Brave API key | 10 web results with descriptions, ages. Statistics extracted from snippets. |

### 1.3 Category-Specific APIs (25 categories)

Selected by the user's **Category** dropdown. All run in parallel with 6s timeout.

| Category | APIs Called | API Count |
|---|---|---|
| **Animals & Pets** | FishWatch, Zoo Animals, Dog Facts, Cat Facts, MeowFacts | 5 |
| **Art & Design** | Art Institute of Chicago, Metropolitan Museum | 2 |
| **Blockchain** | CoinGecko, CoinCap, Mempool, Coinpaprika | 4 |
| **Books & Literature** | Open Library, PoetryDB, Crossref, Quotable | 4 |
| **Business** | Econdb, World Bank, Fed Treasury | 3 |
| **Cryptocurrency** | CoinGecko, CoinCap, CoinDesk BPI, Coinpaprika, Coinlore, CryptoCompare, Mempool | 7 |
| **Currency & Forex** | Frankfurter, Currency-API, World Bank | 3 |
| **Ecommerce** | Open Food Facts | 1 |
| **Education** | Universities List, Nobel Prize, Crossref, Open Library, World Bank | 5 |
| **Entertainment & Movies** | Open Trivia, OMDb/IMDb, SWAPI, PokéAPI, Quotable | 5 |
| **Environment & Climate** | OpenAQ, UK Carbon Intensity, CO2 Offset, USGS Water | 4 |
| **Finance & Economics** | Econdb, Fed Treasury, SEC EDGAR, World Bank | 4 |
| **Food & Drink** | Open Food Facts, Fruityvice, Open Brewery DB | 3 |
| **Games & Gaming** | FreeToGame, RAWG, PokéAPI, Open Trivia | 4 |
| **General** | Quotable, Nager.Date holidays, Numbers API | 3 |
| **Government & Politics** | Data USA, FBI Wanted, Interpol, Federal Register, Nager.Date | 5 |
| **Health & Medical** | disease.sh (COVID/flu), openFDA (drug events) | 2 |
| **Law & Legal** | FBI Wanted, Data USA, Interpol, Federal Register, Nager.Date | 5 |
| **Music** | MusicBrainz, Bandsintown | 2 |
| **News & Media** | Spaceflight News, HN Top Stories, Federal Register | 3 |
| **Science & Space** | NASA, USGS Earthquakes, Launch Library, SpaceX, USGS Water, Sunrise/Sunset, Numbers API, Crossref | 8 |
| **Sports & Fitness** | balldontlie (NBA), Ergast F1, NHL Stats, CityBikes | 4 |
| **Technology** | HN Top Stories, Crossref | 2 |
| **Transportation & Travel** | OpenSky, Open Charge Map, ADS-B Exchange, CityBikes, NHTSA | 5 |
| **Weather & Climate** | Open-Meteo, US NWS, Sunrise/Sunset, OpenAQ | 4 |

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

### 3.3 Context Detection

Lists are styled based on the preceding heading text:
- Contains "pros/advantage/benefit" → green pros box
- Contains "cons/disadvantage/drawback" → red cons box
- Contains "ingredient/supplies/what you need" → amber ingredients box
- Contains "key takeaway" → gradient takeaways box

Paragraphs are styled based on text start:
- Starts with "Tip:" → blue callout
- Starts with "Note:/Important:" → amber callout
- Starts with "Warning:/Caution:" → red callout

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

---

*This document is the authoritative technical reference for all SEOBetter functionality. Update when features are added or changed.*
