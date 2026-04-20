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

### 1.2 Server-Side Research (Serper + Firecrawl — v1.5.133)

**Replaces Perplexity Sonar.** Runs on Vercel using Ben's API keys. Falls back to Sonar if keys not set.

| Step | Service | What It Does | Cost |
|---|---|---|---|
| **Search** | Serper (Google SERP) | Searches Google → 8-10 real URLs with titles and snippets | $0.001/search |
| **Scrape** | Firecrawl | Scrapes top 5 URLs → clean markdown (no nav, ads, sidebars) | $0.001/page |
| **Extract** | OpenRouter (GPT-4.1-mini) | Extracts quotes, stats, comparison tables from REAL page text. Builds tables from prose comparisons even when pages don't have pre-formatted tables. | $0.003/call |

**Why this replaces Sonar:** Sonar is an AI that searches and synthesizes — it hallucinates URLs, invents quotes, makes up statistics. The new pipeline gives the extraction LLM ACTUAL page content to read, so every quote is a real sentence from a real page, every stat has a real source, every URL is from Google.

**Return shape:** Identical to Sonar — `{citations, quotes, statistics, table_data}`. No PHP changes needed.

**Env vars (Vercel):** `SERPER_API_KEY`, `FIRECRAWL_API_KEY`, `EXTRACTION_MODEL` (optional, default `openai/gpt-4.1-mini`)

**Recipe pipeline (v1.5.133):** After Tavily finds recipe URLs, PHP calls `/api/scrape` to get clean Firecrawl markdown. `extract_recipe_from_raw()` works much better on clean structured markdown than on messy Tavily raw HTML.

### 1.3 Optional Pro Source

| Source | URL Pattern | Requires | What It Returns |
|---|---|---|---|
| **Brave Search** | `api.search.brave.com/res/v1/web/search?q={keyword}` | User's Brave API key | 10 web results with descriptions, ages. Statistics extracted from snippets. |

### 1.3 Category-Specific APIs (25 categories — v1.5.15 fixed)

Selected by the user's **Category** dropdown. All run in parallel with 6s timeout. The same 25-category list is exposed in [admin/views/content-generator.php](../admin/views/content-generator.php), [admin/views/bulk-generator.php](../admin/views/bulk-generator.php), and [admin/views/content-brief.php](../admin/views/content-brief.php) — these MUST stay in sync (see plugin_UX.md §9).

| Category (dropdown value) | APIs Called | API Count |
|---|---|---|
| **Animals & Pets (General)** (`animals`) | FishWatch, Zoo Animals, Dog Facts, Cat Facts, MeowFacts | 5 |
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
| **Veterinary & Pet Health (Research)** (`veterinary`) — **NEW v1.5.15** | Crossref (veterinary filtered), EuropePMC, OpenAlex (vet concept), openFDA (vet scoped), Dog Facts | 5 |
| **Weather & Climate** (`weather`) | Open-Meteo, US NWS, Sunrise/Sunset, OpenAQ | 4 |

#### v1.5.15 changes

- **Added `veterinary` category** with 3 new academic API fetchers: `fetchCrossrefFiltered()`, `fetchEuropePMC()`, `fetchOpenAlex()` — see [cloud-api/api/research.js](../cloud-api/api/research.js) lines ~1689-1735. These return real peer-reviewed veterinary literature (Crossref subject-filtered, EuropePMC biomedical, OpenAlex topic-concept-filtered) so dog/cat/equine articles get vet-grade citations instead of cat-fact trivia.
- **Merged `government` + `law_government`** into a single `government` entry with backwards-compat alias. The old `law_government` value still resolves to `government` for legacy clients.
- **Relabeled `health`** to "Health & Medical (Human)" so users don't accidentally pick it for vet topics.
- **Relabeled `animals`** to "Animals & Pets (General)" so users know it's the trivia bucket, not the research bucket.
- **All 3 dropdown forms now share the same 25-category list** (was: 25 in main form, 8 in bulk + brief).

### 1.4 Country-Specific APIs (80+ countries)

Selected by the user's **Country & Language** dropdown. The country selection affects FIVE things (v1.5.121+):

1. **Research APIs** — country-specific data sources (see below)
2. **Authority domains** — Tavily quote search restricted to country-relevant credible sources (see authority-domains.md)
3. **AI writing prompts** — TARGET COUNTRY instruction injected into BOTH outline generation AND section writing prompts. Tells the AI to use local brands, regulations, pricing (local currency), terminology, and cultural references. Prevents US-centric defaults when user selects Australia, UK, etc.
4. **Recipe schema `recipeCuisine`** — mapped from country code (AU→"Australian", FR→"French", JP→"Japanese", etc. — 40+ countries). Ensures Google Recipe rich results show the correct cuisine.
5. **Article language** — the Language dropdown sets the `LANGUAGE` instruction in the system prompt. ALL content (headings, paragraphs, FAQ, Key Takeaways, recipe ingredients/instructions) must be in the selected language.

### 1.4.1 How Country & Language Settings Flow Through Article Generation (v1.5.121)

```
User selects: Country = AU, Language = English
    ↓
Form values stored in _seobetterDraft:
    draft.country = "AU"
    draft.domain = "animals" (category)
    ↓
Generation:
    Async_Generator receives country → injects TARGET COUNTRY: Australia
    into outline prompt + every section prompt
    AI writes with Australian brands, AUD, local terminology
    ↓
Optimization (Optimize All):
    Content_Injector receives country + domain
    → authority domains for AU + animals = rspca.org.au, apvma.gov.au, etc.
    → Tavily search restricted to these authority domains
    → quotes from Australian sources, not US defaults
    ↓
Save:
    Schema_Generator receives country from _seobetter_country post meta
    → Recipe: recipeCuisine = "Australian"
    → All schemas use Australian date format, author display_name
    ↓
Published article:
    Content: Australian brands, AUD pricing, local references
    Schema: recipeCuisine: "Australian", author from WP profile
    Citations: from rspca.org.au, abc.net.au, etc.
```

Each country can have:
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

### 1.6B Places Waterfall (Anti-Hallucination Local Businesses — v1.5.24, refined v1.5.26)

4-tier waterfall that fetches real local businesses from multiple providers and stops at the first tier returning ≥3 verified places. Replaces the v1.5.23 OSM-only fetcher. User-provided API keys flow from `seobetter_settings` → `Trend_Researcher::cloud_research()` → `research.js::fetchPlacesWaterfall()` via the `places_keys` field in the request body. Tiers with no key are skipped.

**v1.5.26 change — Wikidata removed from the active waterfall.** Live testing against Lucignano exposed that Wikidata SPARQL `geo:around` returns any entity with coordinates (churches, hamlets, town halls) regardless of business type, because small commercial businesses are non-notable to Wikidata by design. 20 wrong-type hits short-circuited the waterfall and prevented Foursquare from ever running. `fetchWikidataPlaces()` is kept as dead code in research.js for possible future use on cultural-heritage keywords (e.g. "oldest churches in X") but no longer counts toward the business waterfall.

**Tier order and coverage (v1.5.26):**

| # | Tier | Cost | User setup | Global small-city coverage |
|---|---|---|---|---|
| 1 | **OpenStreetMap** (Nominatim + Overpass) | Free | None | ~40% (70% EU, 20% rural) |
| 2 | **Foursquare Places** | Free 1K calls/day | API key via Settings → Integrations | Adds ~35% via user check-ins |
| 3 | **HERE Places** | Free 1K/day | API key via Settings | Adds ~20% for EU/Asian tier-2 cities |
| 4 | **Google Places API (New)** | Paid ($200/mo free credit ≈ 5K articles) | API key + Google Cloud billing | Adds the final ~15% for remote villages |
| — | **Hard refuse fallback** | — | — | 100% — writes a general informational article with disclaimer when all tiers return <3 places |
| — | **Places_Validator** (Layer 3, v1.5.26) | — | — | Structural guarantee: deletes any post-gen section naming a business not in the verified pool |

**Architecture:**

1. `detectLocalIntent(keyword)` — 4 regex patterns catch `"X in Y"`, `"best X in Y"`, `"X near me"`, `"what's the best X in Y"`. Non-local keywords return immediately with no API calls.
2. `matchBusinessType(businessHint)` — ~40-entry lookup map converts business hints (gelato, restaurant, cafe, vet, etc) to OSM tag pairs. Used by Tier 1 to query Overpass by tag.
3. `nominatimGeocode(location)` — single Nominatim call shared by every tier. Returns lat/lon + bounding box. Required `User-Agent: SEOBetter/1.5.24 (Research)` header per Nominatim ToS.
4. `fetchPlacesWaterfall(keyword, country, placesKeys)` — runs Tier 1, checks count, runs Tier 2 if needed, checks count, and so on. Stops at first tier with ≥3 places. User-keyless tiers 3-5 are skipped when their key is missing.
5. **Deduplication** — after all tiers run, places are deduplicated by lowercased name so the same place appearing in Tier 1 + Tier 3 is merged, not double-listed.

**Provider-specific fetchers (all in [cloud-api/api/research.js](../cloud-api/api/research.js)):**

- `fetchWikidataPlaces(businessHint, geo)` — SPARQL `?item wdt:P625 ?coord` within 15km radius of the geocoded city, filtered to entities with human-readable labels in en/it/fr/es/de/pt. Returns up to 20 named landmarks/businesses with website + coordinates + Wikidata Q-id URL.
- `fetchFoursquarePlaces(businessHint, geo, apiKey)` — `GET https://places-api.foursquare.com/places/search?query=...&ll=...&radius=5000&limit=20` with `Authorization: Bearer {apiKey}` + `X-Places-Api-Version: 2025-06-17`. Best small-city coverage via user check-ins in non-Anglophone markets.
- `fetchHEREPlaces(businessHint, geo, apiKey)` — `GET https://discover.search.hereapi.com/v1/discover?apiKey={apiKey}&q=...&at=...&limit=20`. Strong European and Asian tier-2 city coverage.
- `fetchGooglePlaces(businessHint, geo, apiKey)` — `POST https://places.googleapis.com/v1/places:searchText` with `X-Goog-Api-Key` + field mask. Gold standard global coverage.

**Wiring:**
- Runs in parallel with the 9 other always-on sources (no latency cost on non-local keywords — returns early)
- Results flow into `buildResearchResult()`:
  - Each place's URL + optional website → `sources[]` → Citation Pool → References section
  - Each place name → `stats[]` as `"{name} is a real {type} at {address} ({provider}, {year})"`
  - Formatted "REAL LOCAL PLACES" block added to `for_prompt` — now labels the source provider (e.g. "verified via OpenStreetMap + Wikidata" or "verified via Foursquare")
  - If every tier returns 0 places, a "LOCAL-INTENT WARNING" block is added telling the AI not to invent businesses + listing which providers were tried
- Return object gains `places_provider_used`, `places_providers_tried` for telemetry

**System prompt enforcement** ([Async_Generator.php::get_system_prompt()](../includes/Async_Generator.php#L601)): PLACES RULES block added in v1.5.23 is unchanged — closed-menu grounding works identically regardless of which provider produced the places.

**v1.5.26 — Layer 3 Places_Validator** ([includes/Places_Validator.php](../includes/Places_Validator.php)): after `Content_Formatter::format()` produces the final HTML and before `GEO_Analyzer::analyze()` scores it, [Async_Generator.php::assemble_final()](../includes/Async_Generator.php) calls `Places_Validator::validate($html, $places_pool, $business_type, $is_local_intent)`. The validator splits the HTML at H2/H3 boundaries, extracts a business-name candidate from each section's heading (tolerating listicle-numbering prefixes like "1. " and "#1: "), normalizes both the candidate and the pool entries (accent transliteration, article removal, whitespace collapse), and compares using three strategies: exact match, substring containment (either direction), and Levenshtein distance ≤ 3. Any section whose candidate doesn't match ANY pool entry is deleted. If more than 50% of sections get stripped, `force_informational` is set on the result and a critical warning is surfaced in the GEO suggestions panel so the user knows the article is structurally hallucinated. Mirrors the defensive post-generation pattern used by `validate_outbound_links()` for URL atoms — same pattern, different atom.

**v1.5.47 — Local Business Mode threshold lowered to ≥ 1:** after a Sonar test returned exactly 1 verified gelateria in Lucignano and produced an informational article where the real place was buried in body text with no meta line, the Local Business Mode threshold in [Async_Generator::process_step()](../includes/Async_Generator.php) was lowered from `places_count >= 2` to `places_count >= 1`, and the pre-gen switch threshold was lowered from `places_count < 2` to `places_count < 1`. A single verified place now produces a capped listicle with 1 business-name H2 (populated from the pool) + generic fill sections, which gives `Places_Link_Injector` an H2 to attach its 📍 address / Google Maps / website meta line to. The shared places-only cache in [Trend_Researcher::cloud_research()](../includes/Trend_Researcher.php) was updated to match — it now saves and restores single-place results so Sonar non-determinism lower bounds stabilize.

**v1.5.27 — Layer 0 pre-generation structural switch + empty-pool backstop:** live testing of v1.5.26 against Lucignano with no OSM coverage and no Foursquare key exposed that Places_Validator's post-generation deletion is insufficient on its own — the empty pool caused early-exit, the model happily hallucinated 6 gelaterie, no warnings fired, and the user saw a normal-looking article full of fake businesses. v1.5.27 adds a pre-generation switch in [Async_Generator::process_step()](../includes/Async_Generator.php) trends-step branch: when the research response indicates `is_local_intent === true && places_count < 2`, the `$job['options']['places_insufficient']` flag is set and propagates through to [generate_outline()](../includes/Async_Generator.php) and [generate_section()](../includes/Async_Generator.php). Both prompt builders check the flag and inject structural rules: the outline prompt FORBIDS business-name-shaped H2s ("1. Gelateria X", "Top Pick: Y") and REQUIRES informational-article section templates (history, cultural context, what to look for, regional variations, FAQ). The section prompt appends a hard rule: `*** PLACES INSUFFICIENT — HARD RULE *** ... this section MUST NOT name any specific business ... If you feel tempted to name a business, replace it with a generic noun like 'a traditional gelateria'`. This runs BEFORE any tokens are spent on listicle-shaped headings, so the model never has the option to hallucinate in the first place. Places_Validator is also updated to accept `$is_local_intent` as a 4th parameter and run its main loop even with an empty pool when local intent is true, as the backstop for any business-name sections that slip through the pre-generation forbidding. Finally a high-priority `places_insufficient` suggestion is prepended to the GEO suggestions panel explaining to the user why the listicle became informational and linking them to Settings → Integrations for the Foursquare API key signup.

**GEO_Analyzer sentinel** ([GEO_Analyzer.php::check_local_places_grounding()](../includes/GEO_Analyzer.php) + `generate_suggestions()`): for listicle/buying_guide/review/comparison articles with local-intent keywords, checks for map URLs or specific addresses. If neither, scores 0, floors `geo_score` at 40, AND emits a high-priority `local_places` suggestion pointing the user to Settings → Integrations to configure additional providers.

**User-provided API keys** live in [Settings → Places Integrations](../admin/views/settings.php) — 3 password fields for Foursquare / HERE / Google with setup instructions and free-tier limits per provider.

**Whitelisted domains** (v1.5.24): adds `wikidata.org`, `query.wikidata.org`, `foursquare.com`, `fsq.com`, `here.com`, `discover.search.hereapi.com`, `maps.google.com`, `maps.googleapis.com`, `places.googleapis.com`, `google.com/maps` — all new places provider URLs can pass `validate_outbound_links()` without being stripped.

### 1.6B-legacy OSM Places (v1.5.23, superseded by the waterfall above)

Free OpenStreetMap integration that prevents the AI from inventing business names for local-intent queries. Added after the "best gelato shops in Lucignano Italy" hallucination bug.

**Problem:** The 9 always-on sources + 25 category APIs have ZERO place/POI data. For keywords like "best gelato shops in [small town]", the LLM fell back to generating plausible-sounding business names that don't exist on Google Maps or OSM.

**Fix:** New `fetchOSMPlaces(keyword, country)` in [cloud-api/api/research.js](../cloud-api/api/research.js) runs in the always-on parallel batch. Steps:

1. **Local intent detection** via regex patterns — matches `"X in [Location]"`, `"best X in [Location]"`, `"what's the best X in [Location]"`, `"X near me"`. If no match, returns empty immediately (no API calls).
2. **Business type mapping** via hardcoded OSM tag table (~40 entries: `gelato → amenity=ice_cream`, `restaurant → amenity=restaurant`, `pet shop → shop=pet`, `vet → amenity=veterinary`, etc).
3. **Nominatim geocoding** — `GET https://nominatim.openstreetmap.org/search?q={location}&format=json&limit=1` (free, no key, rate-limited 1 req/sec, User-Agent header required). Returns lat/lon + bounding box.
4. **Overpass POI query** — `POST https://overpass-api.de/api/interpreter` with `[out:json][timeout:20];(node[tag]({bbox});way[tag]({bbox});relation[tag]({bbox}););out tags center 20;`. Returns up to 20 real places with name, address, website, phone, opening hours.
5. **Normalize + return** — each place becomes `{name, type, address, website, phone, osm_url, lat, lon, source}`.

**Wiring:**
- Runs in parallel with the 9 always-on sources (no latency cost)
- Results flow into `buildResearchResult()`:
  - Each place URL → `sources[]` (goes to Citation Pool → References section)
  - Each place name → `stats[]` as `"{name} is a real {type} at {address} (OpenStreetMap, {year})"`
  - Formatted "REAL LOCAL PLACES" block added to `for_prompt` with the business names the AI MUST use
  - If local intent detected but zero places returned, a LOCAL-INTENT WARNING block is added telling the AI not to invent businesses
- Return object gains `is_local_intent`, `places_count`, `places_location`, `places_business_type` for telemetry

**System prompt enforcement** ([Async_Generator.php::get_system_prompt()](../includes/Async_Generator.php#L601)): new PLACES RULES block with 7 rules mirroring the CITATION RULES pattern — closed-menu grounding, "if it's not in the list, you can't serve it", explicit handling for empty-places case.

**GEO_Analyzer sentinel** ([GEO_Analyzer.php::check_local_places_grounding()](../includes/GEO_Analyzer.php)): post-generation safety check. For listicle/buying_guide/review/comparison articles with local-intent keywords, verifies the content contains map URLs or specific addresses. If neither, floors `geo_score` at 40 so the user sees a red flag and regenerates.

**Whitelisted domains** (v1.5.23): `openstreetmap.org`, `www.openstreetmap.org`, `nominatim.openstreetmap.org`, `overpass-api.de`.

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
| N+3. **Assemble** | Markdown assembled, images inserted, citations injected, GEO enforced (table/FAQ/keyword density/readability), formatted to HTML, GEO scored |

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
| Recipe | Recipe × N + ItemList | Key Takeaways → Why This Matters → Comparison Table → Recipe 1 Card (Ingredients + Instructions + Storage + Calories + "Inspired by [Source](url)") → Recipe 2 Card → Recipe 3 Card → Safety → Pros/Cons → FAQ. **v1.5.123: Recipes sourced from real authority sites via Tavily during research step. v1.5.125 INGREDIENT SAFETY RULE: Ingredients and quantities copied EXACTLY from source — AI must NOT add/remove/substitute any ingredient. AI makes recipes unique via: creative name, rewritten intro, rephrased instruction wording. Temps/times kept exact. Applies to ALL recipes (human + pet).** |
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

### 11.3 Rich Results Preview (v1.5.133)

Collapsible panel in the sidebar showing how the article will appear in Google search results.

**Sections:**
1. **SERP Preview Card** — breadcrumb trail, title (blue link), description snippet, FAQ dropdown preview, Recipe star rating preview
2. **Active Rich Result Types** — checkmarks for each detected schema type (Recipe card, FAQ dropdowns, Breadcrumb trail, Speakable, ItemList carousel, Review stars)
3. **Schema Impact Estimate** — research-backed statistics (Searchmetrics, Ahrefs, Princeton GEO study, FirstPageSage) showing expected CTR/visibility boost from active schemas
4. **Validation** — error/warning count from schema analysis, valid/invalid badge
5. **Google Rich Results Test link** — opens in new tab with post URL pre-filled

**Data source:** `rich_preview` object from `GET /seobetter/v1/analyze/{post_id}` — includes `rich_types[]`, `impact_stats[]`, `validation{}`, `breadcrumbs[]`, `title`, `description`, `url`

**File:** `assets/js/editor-sidebar.js` — `renderRichPreview()` function

### 11.4 Toolbar Score Badge (DOM injection)

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

### 11.6 Analyze & Improve — Single "Optimize All" Button (v1.5.83+)

`includes/Content_Injector.php` provides 11 methods: 5 inject (add content), 2 rewrite (modify content), 3 flag (advisory), plus 1 orchestrator.

**"⚡ Optimize All" — single-click orchestrator (v1.5.78):**
- `optimize_all($markdown, $keyword, $existing_pool, $scores)` — runs all 6 fixes below in one pass
- Step 0: ONE Perplexity Sonar call via `call_sonar_research()` → returns `{citations, quotes, statistics, table_data}` as structured JSON from live web search
- Steps 1-4: injects research data from Sonar (or falls back to existing methods if no OpenRouter key)
- Steps 5-6: AI rewrites (readability, keyword density) via user's configured AI provider
- Checks score thresholds — skips fixes already passing
- Each step has try/catch — failures don't abort the pipeline
- Format/score runs ONCE at the end (not per-step)

**5 Inject Methods (additive — append/insert new content):**
1. `inject_citations($content, $keyword, $existing_pool)` — uses Citation Pool (DDG/Brave/Reddit/HN/Wikipedia) or Sonar-provided URLs. Appends `## References` section + inline `[N]` anchor links. Zero hallucinated URLs — every link traces to a real web search result.
2. `inject_quotes($content, $keyword, $sonar_data)` — v1.5.94: SCRAPED QUOTES ONLY. Quotes are real sentences extracted from real web pages by `scrapeAndExtractQuotes()` in research.js. Each quote has the exact text from the page + the page URL + the domain as source name. NO fallback to any LLM (Sonar, Trend_Researcher, AI generation). If the scraper found 0 quotes with URLs for this keyword, the step is SKIPPED — no quotes inserted. Zero hallucination guarantee.
3. `inject_table($content, $keyword)` — AI generates markdown comparison table with dynamic columns (v1.5.75: no longer hardcodes Price Range). When used via `optimize_all()`, table data comes from Sonar with real product specs.
4. `inject_freshness($content)` — Prepends `Last Updated: [Month Year]` to article top. Skips if already present.
5. `inject_statistics($content, $keyword)` — Pulls real stats from Vercel research API or AI fallback. When used via `optimize_all()`, stats come from Sonar with real source attributions.

**2 Rewrite Methods (modify existing content via AI):**
6. `simplify_readability($markdown)` — AI rewrites sections with Flesch-Kincaid grade > 8 to grade 7. Breaks long sentences, swaps complex words for simpler ones ("use" not "utilize"), converts to active voice. Preserves all facts, citations, links, structural elements. Per SEO-GEO-AI-GUIDELINES §5: targets grade 6-8 for maximum GEO visibility.
7. `optimize_keyword_placement($markdown, $keyword, $depth)` — AI replaces excess keyword mentions with pronouns/variations to reduce density from >1.5% to ~1.0%. Keeps first-paragraph keyword (AIOSEO/Yoast check this) and H2 heading keywords (30%+ rule). Auto-retries once if density > 1.5% after first pass (`$depth` parameter, max 2 passes). Per SEO-GEO-AI-GUIDELINES §5A: prevents -9% AI visibility penalty from keyword stuffing.

**3 Flag Methods (read-only — show issues, never edit):**
8. `flag_readability()` — returns long sentences (>25 words) + complex words with simpler replacements
9. `flag_pronouns()` — returns paragraphs starting with It/This/They/These/Those/He/She/We
10. `flag_openers()` — returns H2 headings whose first paragraph isn't 30-70 words

**Perplexity Sonar integration (v1.5.78):**
- `call_sonar_research($keyword)` — private method, one call to OpenRouter with `perplexity/sonar` model
- Prompt requests structured JSON with 4 categories: citations (real URLs), quotes (real attributed quotes), statistics (real numbers with sources), table_data (real product comparisons)
- Auto-discovers OpenRouter key from `AI_Provider_Manager::get_provider_key('openrouter')` or `seobetter_settings['openrouter_api_key']`
- Returns null if no key configured → each step in `optimize_all()` falls back to its existing method
- Cost: ~$0.01-0.06 per call depending on model (`perplexity/sonar` vs `perplexity/sonar-pro`)
- Sonar URLs pass through `Citation_Pool::passes_hygiene_public()` before entering the pool

**REST endpoint (individual):** `POST /seobetter/v1/inject-fix`
- Params: `fix_type`, `markdown`, `keyword`, `accent_color`, `citation_pool`
- Returns: `success`, `content`, `markdown`, `geo_score`, `grade`, `checks`, `added`, `type`
- Routes to appropriate `Content_Injector::inject_*` or `flag_*` method
- Re-formats and re-scores the updated content

**REST endpoint (all-in-one, v1.5.78):** `POST /seobetter/v1/optimize-all`
- Params: `markdown`, `keyword`, `accent_color`, `citation_pool`, `scores` (current check scores)
- Returns: `success`, `content`, `markdown`, `geo_score`, `grade`, `checks`, `steps_run`, `steps_skipped`, `sonar_used`, `added`
- Makes ONE Perplexity Sonar call for citations + quotes + statistics + table data
- Runs all 6 inject fixes sequentially on the markdown, then formats/scores ONCE
- Fallback: if no OpenRouter key, each step uses its existing method (DDG, Vercel, AI)
- Implementation: `Content_Injector::optimize_all()` → `seobetter.php::rest_optimize_all()`

**Why this approach:**
- Zero hallucinated URLs (citations come from real web search)
- Never wipes user edits (inject = append/insert, never rewrite)
- Flag-only fixes educate the user instead of black-box rewriting

---

*This document is the authoritative technical reference for all SEOBetter functionality. Update when features are added or changed.*
