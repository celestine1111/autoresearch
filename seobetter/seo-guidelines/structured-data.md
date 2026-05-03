# SEOBetter Structured Data Reference

> **Purpose:** Google-compliant JSON-LD schema generation for all 21 article types.
> Based on Google's official documentation (fetched April 2026).
>
> **Sources:**
> - https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data
> - https://developers.google.com/search/docs/appearance/structured-data/sd-policies
> - https://developers.google.com/search/docs/appearance/enriched-search-results
>
> **Last updated:** 2026-04-22 (v1.5.202 — content-type schema enrichments documented)
> **Code:** `includes/Schema_Generator.php`
>
> ---
>
> ### Cross-reference note (v1.5.202)
>
> Three documents cover schema guidance and must be kept in sync on every schema change:
>
> 1. **This file (`structured-data.md`)** — Google compliance policies, required/recommended fields per @type, content-type → @type mapping (§5), validation tools.
> 2. **`SEO-GEO-AI-GUIDELINES.md` §10** — content-type → schema @type + secondary schemas table, with per-type prose structure context.
> 3. **`article_design.md` §11** — content-type schema stacking matrix with styled-block context.
>
> When `Schema_Generator.php` changes, update ALL THREE in the same commit. Step 4b of the `/seobetter` skill workflow lists the first two as mandatory co-updates — `structured-data.md` should be added to that mapping for any new schema field (citation, backstory, articleSection override, speakable refinement, enriched secondary schemas).

---

## 1. FORMAT AND PLACEMENT

- **Format:** JSON-LD (Google recommended)
- **Placement:** `<script type="application/ld+json">` in `<head>` via `wp_head` hook AND as `wp:html` block in post_content
- **JavaScript injection:** Fully supported by Google (reads DOM at render time)
- **SEO plugin detection:** Skip output if AIOSEO, Yoast, or RankMath are active (avoid duplication)

---

## 2. GOOGLE POLICIES (violations = manual action)

1. **Markup must reflect visible page content** — do NOT include data not on the page
2. **No fake/default values** — hardcoded ratings, times, yields are policy violations
3. **No self-serving reviews** — a business reviewing itself is ineligible for star snippets
4. **No aggregated third-party reviews** — don't copy reviews from other sites
5. **No misleading schema types** — don't label dog food recipes as "Main course"
6. **Author names only** — no email addresses, no job titles in the `name` field
7. **Quality over quantity** — fewer accurate fields > many inaccurate fields

---

## 3. RICH RESULT STATUS (as of May 2026 — updated v1.5.216.62.25)

**Metabox tile audit:** v62.24 dropped 4 tiles (HowTo, Paywall, DiscussionForum, LocalBusiness/MapPack — Google deprecated or doesn't deliver rich results to article-style sites). v62.25 dropped a further 11 tiles whose schema is still EMITTED by Schema_Generator (still useful for LLM citations) but whose Google rich-result lane is essentially never delivered to article-style customer sites:

| Dropped tile (v62.25) | Why no Google rich result for SEOBetter sites |
|---|---|
| Recipe carousel | Needs 3+ Recipes in ONE article. SEOBetter emits one Recipe per recipe article. |
| Recipe gallery | Google's multi-site aggregation feature, not an on-page rich result. |
| Product carousel | Needs 3+ Product Schema Blocks per post — rare in practice. |
| Event carousel | Needs 3+ Events per post — rare in practice. |
| Video carousel | Needs 3+ video embeds per post — rare in practice. |
| Course carousel | Coursera / edX / Khan Academy dominate; articles essentially never qualify. |
| Movie carousel | IMDb / Rotten Tomatoes / Letterboxd dominate; article reviews rarely fire. |
| Software App | App Store pages dominate; articles ABOUT software almost never get the lane. |
| Dataset | Google Dataset Search is a separate vertical, not the regular SERPs. |
| Q&A page | Stack Overflow / Reddit dominate the QAPage rich result. |
| Profile page | Author-archive use case; rarely fires for article posts. |

Schemas for the dropped tiles are still emitted automatically by Schema_Generator where applicable — they continue to help LLM retrieval and structured-data validators. Only the metabox tile (which advertises a Google rich-result lane) is removed.

**Final tile count after v62.25: 13 honest tiles.** Each tile shows a real activation path or notes when it doesn't apply to the current content type.

| Schema Type | Google Status | Used by SEOBetter |
|---|---|---|
| **Article / BlogPosting / NewsArticle** | ACTIVE | Yes — most article types |
| **Recipe** | ACTIVE (enriched) | Yes — recipe type |
| **Review snippet** | ACTIVE | Yes — review type |
| **FAQPage** | RESTRICTED (gov/health only) | Yes — auto-emit when content has FAQ section |
| **HowTo** | DEPRECATED (Sept 2023) | **No — `case 'HowTo'` removed in v1.5.213. Metabox tile dropped in v62.24** since Google does not deliver the rich result anymore. `how_to` content type maps to `Article` per CONTENT_TYPE_MAP. |
| **LocalBusiness** | ACTIVE for the business's own page or authoritative aggregator | **Manual Schema Block only (v62.24)** — the auto-emit-from-article-content path was retired since article-style sites do not qualify for the Map Pack rich result. Pro+ users insert a LocalBusiness Schema Block for legitimate single-business pages. |
| **ItemList** | ACTIVE | Yes — listicle type |
| **BreadcrumbList** | ACTIVE | Yes — all types |
| **DefinedTerm** | ACTIVE | Yes — glossary type |
| **Product** | ACTIVE | Manual Schema Block (Pro+) + auto-detect for review/buying_guide/comparison/sponsored |
| **Organization** | ACTIVE | Yes — universal, all article types |
| **QAPage** | ACTIVE | Yes — interview, faq_page |
| **ClaimReview** | ACTIVE | Yes — news, opinion (fact check language) |
| **JobPosting** | ACTIVE | Manual Schema Block (Pro+) |
| **VacationRental/LodgingBusiness** | ACTIVE | Manual Schema Block (Pro+) |
| **VideoObject** | ACTIVE | Yes — **auto-detected from 21 platforms in v62.24**: YouTube · Vimeo · Rumble · Bilibili · Youku · iQiyi · Niconico · Naver TV · Kakao TV · Dailymotion · Vidio · Aparat · RuTube · VK Video · Coub · TikTok · Twitch · Facebook Watch · Instagram Reels · Wistia · Mux · Brightcove |
| **SoftwareApplication** | ACTIVE | Yes — tech/business category with app mentions |
| **Event** | ACTIVE | Manual Schema Block (Pro+) + auto-detect for news/blog with date+venue |
| **Course** | ACTIVE | Yes — education/tech category |
| **Movie** | ACTIVE | Yes — entertainment category |
| **Book** | ACTIVE | Yes — books category |
| **Dataset** | ACTIVE | Yes — white_paper, scholarly with data tables |
| **ImageObject** | ACTIVE | Yes — license metadata on images |
| **ProfilePage** | ACTIVE | Yes — interview, personal_essay |
| **Speakable** | ACTIVE | Yes — blog, news, opinion, pillar, how_to, faq_page, interview, recipe, personal_essay, press_release |
| **DiscussionForumPosting** | ACTIVE for forum software | **No — metabox tile dropped in v62.24.** Schema applies to Reddit/Discourse/vBulletin-style threads, not articles. |
| **Paywall (`isAccessibleForFree=false`)** | ACTIVE for major publishers | **No — metabox tile dropped in v62.24.** Almost never appears in real Google SERPs for non-publisher sites; no clear user action path. |

---

## 4. REQUIRED AND RECOMMENDED FIELDS PER TYPE

### Universal — `inLanguage` on eligible top-level schemas (v1.5.206a)

Every top-level schema emitted by `Schema_Generator::generate()` and the legacy `build_aioseo_schema()` path is tagged with `inLanguage` (BCP-47 code: `en`, `en-US`, `zh-CN`, `ja`, `ko`, `ru`, `de`, `fr`, `es`, `pt-BR`, etc.) — **but only on @types that accept it per Schema.org**.

**Gated by `@type` whitelist** — Per Schema.org, `inLanguage` is defined on `CreativeWork`, `Event`, `LinkRole`, `PronounceableText`, `WriteAction` and their descendants. Injecting it on other types (Intangible, Organization, Place, Product descendants) triggers schema.org validator warnings.

- **Accepted (gets `inLanguage` injected):** `Article`, `BlogPosting`, `NewsArticle`, `OpinionNewsArticle`, `ScholarlyArticle`, `TechArticle`, `LiveBlogPosting`, `HowTo`, `Recipe`, `Review`, `ClaimReview`, `FAQPage`, `QAPage`, `ProfilePage`, `WebPage`, `ImageObject`, `VideoObject`, `AudioObject`, `MediaObject`, `SoftwareApplication`, `Dataset`, `Course`, `Book`, `Movie`, `Event` (+ subclasses). Full list in `Schema_Generator::INLANGUAGE_ACCEPTED_TYPES`.
- **Skipped (no `inLanguage`):** `BreadcrumbList`, `ItemList`, `DefinedTerm`, `DefinedTermSet`, `LocalBusiness`, `Organization`, `Product`, `Offer`, `AggregateOffer`, `Person`, `PostalAddress`, `Rating`, `AggregateRating`, `JobPosting`, `VacationRental`, `LodgingBusiness`, `NutritionInformation`, `HowToStep`, `Question`, `Answer`, `ListItem`, `Audience`, `Country`, `Place`, `GeoCoordinates`, `PropertyValue`.

- **Source of truth:** `_seobetter_language` post meta (saved from the `language` request param at save time).
- **Fallback chain:** `_seobetter_language` meta → `get_locale()` (converted `_` → `-`) → `'en'`.
- **Anchor:** `Schema_Generator::get_in_language()` + the type-gated injection loop in `Schema_Generator::generate()` post-processor (after `unset( $s['@context'] )`).
- **Legacy path:** `seobetter.php::populate_aioseo()` mirrors the same type-gate inline before wrapping in `@graph`.
- **Additive guarantee:** never overwrites an `inLanguage` that a specific builder has already set; never touches other fields.

### Article / BlogPosting / NewsArticle
**Required:** NONE (all recommended)
**Recommended:**
- `headline` — post title
- `image` — featured image URL (min 50K pixels, multiple ratios: 16:9, 4:3, 1:1)
- `datePublished` — ISO 8601 with timezone
- `dateModified` — ISO 8601 with timezone
- `author` — Person or Organization
  - `author.name` — name only (no email, no titles, no "posted by")
  - `author.url` — author page or social profile
- `publisher` — Organization with name and url
- `inLanguage` — BCP-47 language code (v1.5.206a — injected universally by Schema_Generator::generate() post-processor; see "Universal" note above)

### Recipe (v1.5.121 — Google-exact format)

**Required:** `name`, `image`

**Implemented fields (all extracted from content, never hardcoded):**

| Field | Source | Example |
|---|---|---|
| `name` | H2 heading of each recipe section | "Crunchy PB Pup Biscuits" |
| `image` | Featured image (1st recipe) or section `<img>` (others) — **array of 3** (Google wants 1:1, 4:3, 16:9 ratios) | `["url", "url", "url"]` |
| `author` | WordPress user display_name, fallback to site name | `{"@type":"Person","name":"Ben"}` |
| `datePublished` | Post publish date (ISO 8601) | "2026-04-19" |
| `description` | First 25 words of recipe section text | "Easy 3-ingredient treats..." |
| `recipeCuisine` | Mapped from country setting (40+ countries) | AU→"Australian", FR→"French" |
| `recipeCategory` | Extracted from content ("treat", "snack", "meal") | "Treat" |
| `keywords` | Focus keyword from article | "homemade dog treats" |
| `prepTime` | Regex: "Prep Time: X minutes" | "PT10M" |
| `cookTime` | Regex: "Cook Time: X minutes" | "PT20M" |
| `totalTime` | Regex: "Total Time: X minutes" | "PT30M" |
| `recipeYield` | Regex: "Yields: X treats/servings" | "24 treats" |
| `recipeIngredient` | `<ul>` list items in recipe section | ["2 cups flour", "1 egg"] |
| `recipeInstructions` | `<ol>` items with `name` + `text` + `url` per step | HowToStep array |

**Multi-recipe support:** Articles with 3+ recipe H2 sections generate SEPARATE Recipe schemas per recipe, plus an ItemList carousel schema. Google shows each recipe as a swipeable card.

**Article wrapper co-emission (v1.5.213):** Recipe content type emits BOTH `Article` (wrapper) AND `Recipe[]` in the @graph. The Article wrapper carries `articleSection: "Recipe"`, `speakable.cssSelector: ['h1', '.key-takeaways', 'h2 + p']`, and @id refs to the top-level Person/Organization. Per Google's @graph spec, multiple top-level @types are explicitly supported and Google picks the most-specific @type per surface — Recipe gets the Recipe rich-result lane, Article gets the Article snippet + Speakable voice readout lane. Two surfaces from one page.

**Author/Publisher @id refs (v1.5.213):** Per-Recipe author/publisher are minimal `{@type, @id, name}` refs to the canonical Person + Organization at the @graph root, NOT inlined Person objects. Pre-v1.5.213 each Recipe duplicated the full 13-field Person object (~500 bytes × 4 recipes = 2KB of redundant identity per article).

**`keywords` field translation (v1.5.213):** When article `_seobetter_language` is non-English, the focus keyword fed into Recipe `keywords` is translated via `Cloud_API::translate_strings_batch()` so the schema field language matches the article body. Fail-open: translation errors fall back to the original keyword.

**`ImageObject` node filtering (v1.5.213.2):** `detect_image_schemas()` no longer emits standalone `ImageObject` nodes for the author bio photo or the featured image — those are already represented by `Person.image` (inside the top-level Person at @graph root) and `Article/Recipe.image` (per-Article). Class-hint skip list also rejects avatar / wp-post-image / gravatar / icon / emoji / logo / author-bio / seobetter-author images. Result: standalone `ImageObject` nodes only represent in-body content images, which is what Google + Schema.org Validator expect.

| `nutrition.calories` | Regex: "X calories" or "X cal" in recipe section | `{"@type":"NutritionInformation","calories":"45 calories"}` |

**BANNED:** Hardcoded times/yields/ratings/cuisine/calories. If the content doesn't state a value, the field is OMITTED.

**Recipe data sourcing (v1.5.124):**
- Uses a DEDICATED recipe domain list (`Async_Generator::get_recipe_domains()`) — completely SEPARATE from the general authority domain list (`get_authority_domains()`). Recipe domains are recipe-specific sites in local languages. They NEVER affect other article types.
- 40+ countries with local-language recipe sites: JP→cookpad.com/kurashiru.com, FR→marmiton.org, DE→chefkoch.de, IT→giallozafferano.it, KR→10000recipe.com, CN→xiachufang.com, BR→tudogostoso.com.br, TR→nefisyemektarifleri.com, etc.
- Global recipe sites always included: allrecipes.com, bbcgoodfood.com, foodnetwork.com
- Pet recipe sites always included: petmd.com, akc.org, thesprucepets.com, mindiampets.com.au
- Query: `"keyword recipe ingredients instructions"` with `include_domains` = country recipe sites + global
- If country-specific sites return < 2 results, falls back to unrestricted Tavily search
- Extracts title, URL, and raw page content (ingredients + steps) from top 3 results
- This REAL recipe data is injected into the AI's research context
- **INGREDIENT SAFETY RULE (v1.5.125):** Ingredients and quantities MUST be copied exactly from the source recipe. AI must NOT add, remove, or substitute any ingredient or measurement. Wrong substitutions can cause allergic reactions, food safety issues, or harm animals. This applies to ALL recipes — human food, pet food, any category.
- **What AI changes to make each recipe unique:** (1) Recipe NAME — creative, different from source. (2) Intro/description — rewritten in article's voice. (3) Instruction WORDING — same steps, rephrased. (4) Cooking temperatures and times stay exactly as the source states.
- Each recipe ends with: "Inspired by [Source Name](url)"
- **NO INVENTED RECIPES (v1.5.127):** Three-layer enforcement — (1) recipe count matches source count, (2) AI prompt forbids inventing, (3) `strip_unsourced_recipes()` hard-strips any recipe without "Inspired by [Source](url)". If 0 sources found, article becomes informational (no recipe cards).

**How it works for ANY country/language:**
- Japanese user: keyword "手作り犬のおやつ" → searches cookpad.com (JP) → finds Japanese recipes → AI writes in Japanese
- French user: keyword "recettes pour chiens" → searches marmiton.org (FR) → finds French recipes → AI writes in French
- Schema `recipeCuisine` auto-set from country code. All schema fields work in any language.

**Country → Cuisine mapping (40+ countries):**
AU→Australian, US→American, GB→British, FR→French, IT→Italian, JP→Japanese, IN→Indian, MX→Mexican, TH→Thai, CN→Chinese, KR→Korean, ES→Spanish, DE→German, BR→Brazilian, GR→Greek, TR→Turkish, VN→Vietnamese, IE→Irish, NZ→New Zealand

**Google's exact JSON-LD format (what we generate):**
```json
{
  "@type": "Recipe",
  "name": "Crunchy PB Pup Biscuits",
  "image": ["https://example.com/photo.jpg"],
  "author": {"@type": "Person", "name": "Ben"},
  "datePublished": "2026-04-19",
  "description": "Easy 3-ingredient peanut butter dog treats...",
  "recipeCuisine": "Australian",
  "prepTime": "PT10M",
  "cookTime": "PT20M",
  "recipeYield": "24 treats",
  "recipeCategory": "Treat",
  "keywords": "homemade dog treats",
  "recipeIngredient": ["2 cups wholemeal flour", "1/2 cup peanut butter", "2 eggs"],
  "recipeInstructions": [
    {"@type": "HowToStep", "name": "Preheat oven", "text": "Preheat oven to 180C (350F).", "url": "https://example.com/recipe#step1-1"},
    {"@type": "HowToStep", "name": "Mix ingredients", "text": "Mix flour and peanut butter until crumbly.", "url": "https://example.com/recipe#step1-2"}
  ]
}
```

### Review (v1.5.136 — smart itemReviewed detection)

**Required:** `author`, `itemReviewed`, `itemReviewed.name`
**Recommended:** `reviewRating`, `datePublished`, `publisher`, `positiveNotes`, `negativeNotes`

**BANNED:** Hardcoded ratingValue. Rating only included if extractable from content.

**Smart itemReviewed @type detection (v1.5.136):**
The `build_review()` method auto-detects WHAT is being reviewed and sets the correct Schema.org @type:

| Content Signals | itemReviewed @type | Extra Fields Added |
|---|---|---|
| Software/app/SaaS/tool/plugin mentions + tech category | `SoftwareApplication` | operatingSystem, applicationCategory |
| iOS/Android/mobile app/Play Store mentions | `MobileApplication` | operatingSystem (iOS/Android) |
| Restaurant/cafe/diner/cuisine/menu mentions + food category | `Restaurant` | servesCuisine, priceRange, address, telephone |
| Book/novel/author/ISBN/publisher mentions + books category | `Book` | author (Person) |
| Movie/film/director/IMDB/Netflix mentions + entertainment | `Movie` | director (Person) |
| Video game/PlayStation/Xbox/Steam/gaming | `VideoGame` | gamePlatform |
| Street address detected in content | `LocalBusiness` | address (PostalAddress), telephone |
| Course/class/training/Udemy/Coursera + education | `Course` | provider (Organization) |
| Event/conference/concert + date mentions | `Event` | — |
| Default (no specific signals) | `Product` | offers (price + currency) |

**Country-aware pricing:** Currency code auto-detected from content ($, GBP, EUR) with country fallback (AU→AUD, CA→CAD, JP→JPY, NZ→NZD).

**Google-exact fields (all types):**
- `author` with `@type: Person`, `name`, `url` (author archive page)
- `publisher` with `@type: Organization`, `name` (site name)
- `image` array (3 URLs for 1:1, 4:3, 16:9 ratios)
- `datePublished` and `dateModified` in ISO 8601
- `positiveNotes` / `negativeNotes` as ItemList (from Pros/Cons section)
- `reviewRating` only if extractable (patterns: "4.5/5", "Rating: 8/10", "Score: 4 out of 5")

### OpinionNewsArticle (v1.5.192 enrichments)

**Required:** All NewsArticle fields (headline, image, datePublished, dateModified, author, publisher, mainEntityOfPage).

**v1.5.192 additions (AI-citability signals — mirrors Princeton GEO 2311.09735 and Claude/Perplexity citation Q3 2025 research):**
- `articleSection: "News"` — OpinionNewsArticle is itself the opinion signal; articleSection stays "News" (it's a subtype of NewsArticle).
- `citation[]` — every outbound URL the article body cites, up to 20 dedup'd. Author-social profiles excluded (v1.5.197) so `author.sameAs` and `citation[]` don't duplicate.
- `backstory: "Opinion piece — reflects the author's personal views, not an objective news report."` — explicit AI-disambiguation label.
- `speakable.cssSelector: ["h1", ".key-takeaways", "h2 + p"]` — voice-assistant reads opening + Key Takeaways + section intros.
- `ClaimReview` schema EXPLICITLY REMOVED from eligibility (v1.5.192) — Google policy: ClaimReview is for fact-checking others' claims, NOT for own opinions. Emitting on op-ed risks manual action.

Source: [BUILD_LOG v1.5.192](./BUILD_LOG.md) + [article_design.md §11](./article_design.md).

### NewsArticle + Press Release override (v1.5.195 / v1.5.199 enrichments)

**When content_type === 'press_release' and schema is NewsArticle:**
- `articleSection: "Press Release"` (NOT "News") — disambiguates corporate announcements from editorial reporting for AI engines and Google News.
- `citation[]` — outbound URLs the body cites (max 1-2 per Google 2025 PR rules; schema captures whatever survives).
- `speakable.cssSelector: ["h1", "h2 + p", ".seobetter-author-bio"]` — voice-assistant reads headline + lede-of-each-section + company boilerplate. Requires the `seobetter-author-bio` class to be present on the bio wrapper (v1.5.200 attached it).
- Regular `news_article` content type keeps `articleSection: "News"`.

**Organization schema enrichment (v1.5.195):**
- For `press_release, case_study, sponsored, interview` content types, the secondary Organization node now includes:
  - `description` (pulled from WP `bloginfo('description')` — site tagline)
  - `sameAs` (pulled from the 6 author-social-profile settings — social profiles serve double-duty as author AND organization entity grounding)
- Previously only `name, url, logo` were emitted.

### BlogPosting + Sponsored override (v1.5.209 — FTC/ACCC + Google disclosure compliance)

**When content_type === 'sponsored' and schema is BlogPosting:**
- `articleSection: "Sponsored"` — disambiguates paid placements from organic editorial for Google Search, AI Overviews, and LLM engines.
- `citation[]` — outbound URLs the body references (filter excludes author-social profiles per v1.5.197 rule).
- `backstory: "Sponsored content — this article is a paid placement clearly disclosed to readers. Views and claims reflect the sponsoring organisation's position, not an objective editorial assessment."` — explicit AI-disambiguation label matching the v1.5.192 pattern. Plain-English disclosure for LLMs to read.
- Optional `sponsor` Organization — populated from `_seobetter_sponsor_name` and `_seobetter_sponsor_url` post_meta if set. Omitted when absent (never faked).
- **Speakable deliberately NOT added** — Google policy discourages voice-assistant read-aloud of paid placements without audible disclosure, which WordPress cannot guarantee.

**Why this ships:** pre-v1.5.209 sponsored articles fell through to generic BlogPosting with no disclosure field. Both SEO-GEO-AI-GUIDELINES.md §10.1 and §5 below previously mapped sponsored to `AdvertiserContentArticle` — but that @type is not recognized by Google's Rich Results Test. `CONTENT_TYPE_MAP` correctly uses `BlogPosting`; this commit adds the missing disclosure signals. FTC / ACCC misleading-conduct risk addressed.

Source: [BUILD_LOG v1.5.209](./BUILD_LOG.md) + [Schema_Generator.php::generate()](../includes/Schema_Generator.php).

### BlogPosting + Personal Essay override (v1.5.201 enrichments)

**When content_type === 'personal_essay' and schema is BlogPosting:**
- `articleSection: "Personal Essay"` — disambiguates literary narrative from generic blog posts.
- `citation[]` — outbound URLs the body cites (essays can reference books, news events, songs; filter excludes author-social profiles per v1.5.197).
- `backstory: "Personal essay — first-person literary narrative based on the author's lived experience."` — explicit AI-disambiguation label mirroring Opinion pattern.
- `speakable.cssSelector: ["h1", "h2 + p", ".seobetter-author-bio"]` — voice-assistant reads opening of each section + bio.

Source: [BUILD_LOG v1.5.201](./BUILD_LOG.md).

### `build_clean_description()` helper (v1.5.197 — applies to ALL schema types)

The schema `description` field is generated from post content via `Schema_Generator::build_clean_description()`, which strips `<!-- wp:html -->` structural blocks (type badge, Opinion disclosure bar, Key Takeaways box, tables, author bio, pull-quotes) and all H1-H6 headings before summarising to 30 words. Prevents the pre-v1.5.197 pollution where descriptions read `"💬 Opinion Opinion — this piece reflects... Key Takeaways Key Takeaways..."`.

### `extract_outbound_urls()` citation filter (v1.5.197 — applies to any schema with `citation[]`)

When `citation[]` is populated (Opinion, Press Release, Personal Essay, and any future type), URLs matching the author's 6 configured social profiles (`author_linkedin, _twitter, _facebook, _instagram, _youtube, _website`) are filtered out. Those URLs belong in `author.sameAs` (Person schema), not duplicated into article `citation[]`.

### FAQPage
**Required:** `mainEntity` (array of Question/Answer)
**Note:** Rich results only for government/health authority sites. Schema still valid for semantics.
**v1.5.210 enrichment:** when `content_type === 'faq_page'` (FAQPage is the primary @type, not a secondary section), `Schema_Generator::generate_faq_schema()` now injects `speakable.cssSelector: [h1, h2 + p, h3 + p]` so voice assistants read the Q&A pairs. FAQ is the most voice-native content type — the highest-value voice-read format. Not injected when FAQPage is secondary (blog post / how-to / etc.) since the primary schema's own Speakable handles those.

### HowTo — DEPRECATED
**Status:** Google removed HowTo rich results September 14, 2023.
**Action:** Use Article/BlogPosting schema instead. HowTo markup has zero Google benefit.
**Keeping for:** Non-Google search engines (Bing, Yandex) may still use it.
**v1.5.210 enrichment:** `how_to` content type (which maps to Article @type per `CONTENT_TYPE_MAP`) now gets `speakable.cssSelector: [h1, .key-takeaways, h2 + p]` via the default SPEAKABLE_TYPES path — voice assistants read the step-by-step sections. Google Assistant still supports HowTo voice read-aloud on mobile despite the desktop rich result deprecation.

### LocalBusiness
**Required:** `name`, `address` (PostalAddress)
**Recommended:**
- `geo` (GeoCoordinates with latitude/longitude, 5+ decimal places)
- `telephone` (with country + area codes)
- `url` (link to business website)
- `openingHoursSpecification`
- `priceRange` (e.g., "$$" or "$10-50")
- Use most specific subtype: Restaurant, PetStore, Veterinarian, etc.

### ItemList (Listicles)
**Required:** `itemListElement` (array of ListItem)
- Each ListItem: `position`, `name`

### BreadcrumbList (All types)
**Required:** `itemListElement` (array of ListItem)
- Each ListItem: `position`, `name`, `item` (URL)

### Universal `citation[]` rollout (v1.5.210)

Prior to v1.5.210, `citation[]` was injected only on Opinion (v1.5.192), Press Release (v1.5.195), Personal Essay (v1.5.201), and Sponsored (v1.5.209). v1.5.210 rolls it out to 10 more content types via the `CITATION_TYPES` constant in `Schema_Generator`:

**Types now getting `citation[]`:** how_to, review, comparison, buying_guide, tech_article, white_paper, scholarly_article, case_study, interview, pillar_guide.

**Excluded (and why):**
- `recipe` — Recipe card format has its own source attribution via "Inspired by [Source]" suffix per v1.5.124
- `glossary_definition` — single-term definition, no source graph
- `live_blog` — timestamped updates, citations live inline per update not at the top-level schema
- `faq_page` — FAQPage @type doesn't support citation at the schema level per Schema.org
- `news_article` — base news type; only `press_release` and `opinion` subtypes get citation[]
- `blog_post`, `listicle` — not in v1.5.210 sign-off scope; straightforward to add in a follow-up

**Behavior:** fires AFTER all type-specific override branches in `build_article()` so Opinion / PR / Personal Essay / Sponsored citation[] injection still wins. Only adds when `$schema['citation']` is not already set AND `extract_outbound_urls()` returns at least one URL. Uses the same URL-extraction + author-social-profile-exclusion rules as v1.5.192/197.

**For `review` specifically:** review goes through `build_review()` not `build_article()`, so a mirror citation[] block was added to the end of `build_review()` with identical logic.

**LLM impact:** Hybrid BM25+vector retrievers (Perplexity / ChatGPT-with-search / Gemini / Claude) weight pages with declared `citation[]` higher. Per Princeton GEO (arxiv.org/pdf/2311.09735), citations are a +30% visibility lever. Rollout brings 10 content types from 0 citation[] → full citation[] graph.

### Speakable expansion (v1.5.210)

Prior to v1.5.210, `SPEAKABLE_TYPES` was `[blog_post, news_article, opinion, pillar_guide]`. v1.5.210 adds 3 more content types:

- **how_to** — voice assistants can read step-by-step sections. Google Assistant still supports HowTo voice read-aloud on mobile despite the desktop rich result deprecation. Uses the default selector `[h1, .key-takeaways, h2 + p]`.
- **faq_page** — Q&A format is voice-native, highest-value voice-read type. Custom selector `[h1, h2 + p, h3 + p]` captures both H2-based and H3-based FAQ formats. Injected directly in `generate_faq_schema()` because FAQPage primary doesn't flow through `build_article()`.
- **interview** — Q&A transcript lends itself to audio consumption. Uses the default selector.

Sponsored deliberately remains excluded per Google policy — voice assistants should not read paid placements without audible disclosure.

### 4.X User-edited Schema Blocks (v1.5.216.29 — Phase 1 item 10)

`Schema_Blocks_Manager` exposes 5 user-editable structured-data blocks for **Pro+ ($69/mo) and Agency ($179/mo)** tiers. These OVERRIDE `Schema_Generator`'s auto-detection for the same `@type` — when a user has manually filled in authoritative values, the heuristic regex parser is skipped for that type.

**Why the override pattern (not merge):** If both fired, the @graph would contain conflicting nodes (auto-detected price `$129` from prose vs user-entered `$129.00` with currency=USD). Google Rich Results would flag the inconsistency. Override = single source of truth per @type per post.

| Block (`Schema_Blocks_Manager::BLOCK_TYPES`) | Emitted @type | Required fields (Google) | Skipped auto-detect when user block enabled |
|---|---|---|---|
| `product` | `Product` | name, offers.price, offers.priceCurrency, offers.availability | `Schema_Generator::detect_product_schema()` |
| `event` | `Event` | name, startDate, location.name OR location.address | `Schema_Generator::detect_event_schema()` |
| `localbusiness` | `LocalBusiness` (or sub-type — Restaurant / Store / FoodEstablishment / etc.) | name, address (street OR locality) | `Schema_Generator::generate_localbusiness_schemas()` (skipped for ALL Local* sub-types when manual block present) |
| `vacationrental` | `LodgingBusiness` + `VacationRental` (multi-type array) | name, address | `Schema_Generator::detect_vacation_rental_schema()` |
| `jobposting` | `JobPosting` | title, description, datePosted, hiringOrganization.name | `Schema_Generator::detect_job_schema()` |

**Storage:** `_seobetter_schema_blocks` post meta (single array keyed by block_type slug). Each block has `enabled` flag + per-field values. `enabled = false` preserves the user's inputs without emitting JSON-LD (so toggling off doesn't lose data).

**Validation:** `Schema_Blocks_Manager::build_jsonld()` returns null when required fields are missing — we never emit invalid schema (better silent skip than Rich Results Test failure).

**Field defs:** see `SEOBetter::schema_block_field_defs()` in `seobetter.php` for the per-type field schema (label, type, required, options for select fields). Keep in sync with the per-block sanitizer schema in `Schema_Blocks_Manager::sanitize_block()`.

**Auto-detect remains** for users without Pro+ — the heuristic auto-detection of all 5 types continues to work for Free/Pro tier. Pro+ adds the manual override surface, not the underlying detection.

**REST endpoint:** `POST /seobetter/v1/schema-blocks/{post_id}` with payload `{ blocks: { product: {...}, event: {...}, ... } }`. Pro+ gated at handler level via `License_Manager::can_use('schema_blocks_5')`.

**v1.5.216.62.24 — Front-end styled card rendering.** Pre-v62.24 the Schema Blocks emitted JSON-LD ONLY — the structured data was correct in `<script type="application/ld+json">` but the post body had no visible card. v62.24 adds a front-end render path: each enabled block generates a styled HTML card prepended to the post content via `the_content` filter (priority 9, before wpautop).

**Hook:** `seobetter.php::inject_schema_block_cards()` runs only on `is_singular() && in_the_loop() && is_main_query()` and only when `License_Manager::can_use('schema_blocks_5')` is true. Calls `Schema_Blocks_Manager::render_all_html()` which iterates BLOCK_TYPES in order and concatenates each enabled block's card HTML.

**Per-block card design** (all use inline styles for theme-proofness, mobile-first single-column responsive):

| Block | Card layout |
|---|---|
| `localbusiness` | 📍 icon + name + business-type label · price-range badge · optional hero image · description · address-grid (Address / Phone with click-to-call `tel:` link) · opening-hours list under "Hours" header · CTA row → "Directions" (Google Maps URL from lat/lng or address) + "Call" button |
| `product` | optional 140px square image (left) · brand label · name · description · large price + currency · availability badge · SKU footer |
| `event` | 📅 icon + name · optional hero image · description · "When" / "Where" grid · "Get tickets" CTA (if URL set) |
| `vacationrental` | wide hero image (top) · name + price-range row · address line · description · facts row (rooms / sleeps N / pet-friendly badge) |
| `jobposting` | hiring-organization label · job title · pill row (location / employment_type / salary) · description · "Apply" CTA |

**`humanize_business_type()` helper** in Schema_Blocks_Manager maps the 50+ Schema.org LocalBusiness sub-types (Restaurant, Cafe, BarOrPub, Hotel, BedAndBreakfast, Hostel, Bakery, Brewery, Winery, ClothingStore, Hospital, Pharmacy, BeautySalon, etc.) to readable display labels for the card header. Falls back to ucfirst(type) for unmapped types.

**Render guard:** each render method runs the same required-field check as its `build_*_jsonld()` sibling — if required fields are missing, returns empty string and renders nothing. Never half-empty cards.

---

## 5. CONTENT TYPE → SCHEMA TYPE MAPPING

**v1.5.216.62.24 update:** added explicit "Auto / Manual Block" column. Auto = emitted by Schema_Generator at save time from content detection. Manual Block = Pro+ user inserts a Schema Block in the metabox panel. The two are not exclusive — a single article can have both auto-emitted Article schema AND a manual Product / Event / LocalBusiness / VacationRental / JobPosting block.

| Content Type | Primary @type | Auto secondary @types | Manual Block options (Pro+) | Notes |
|---|---|---|---|---|
| blog_post | BlogPosting | FAQPage*, Speakable, BreadcrumbList, Organization, Person | All 5 | Standard blog article |
| how_to | Article | FAQPage*, Speakable, citation[], BreadcrumbList | All 5 | NOT HowTo (deprecated by Google Sept 2023) |
| listicle | Article | ItemList, FAQPage*, BreadcrumbList | All 5 | ItemList for numbered items |
| review | Review OR Article | FAQPage*, Product*, AggregateRating, BreadcrumbList | All 5 | Review ONLY if rating extractable, else Article |
| comparison | Article | FAQPage*, Product*, BreadcrumbList | All 5 | No special schema |
| buying_guide | Article | ItemList, FAQPage*, Product*, BreadcrumbList | All 5 | ItemList for product list |
| recipe | Recipe + Article wrapper | Speakable, BreadcrumbList | None recommended | Required: name + image. No hardcoded times. v1.5.213 wrapper. |
| faq_page | FAQPage | Speakable, BreadcrumbList | None | Restricted to gov/health for rich results |
| news_article | NewsArticle | Speakable, FAQPage*, BreadcrumbList | Event | News-specific fields |
| opinion | OpinionNewsArticle | Speakable, citation[], FAQPage*, BreadcrumbList | Event | Opinion variant |
| tech_article | TechArticle | citation[], Speakable, FAQPage*, BreadcrumbList | Product | Technical article |
| white_paper | Article | citation[], FAQPage*, Dataset (tables), BreadcrumbList | None | Report format |
| scholarly_article | ScholarlyArticle | citation[], FAQPage*, Dataset, BreadcrumbList | None | Academic format |
| live_blog | LiveBlogPosting | BreadcrumbList | None | Live event coverage |
| press_release | NewsArticle (enriched) | Speakable, citation[], Organization, FAQPage*, BreadcrumbList | Event | Corporate announcement |
| personal_essay | BlogPosting (enriched) | Speakable, ProfilePage, citation[], BreadcrumbList | None | Personal narrative |
| glossary_definition | DefinedTerm | BreadcrumbList | None | Single term definition |
| sponsored | BlogPosting (sponsored disclosure) | Organization, citation[], FAQPage*, BreadcrumbList | Product | `articleSection: "Sponsored"` + `backstory` + `citation[]` + optional `sponsor` Organization. AdvertiserContentArticle rejected by Google — BlogPosting with disclosure enrichments is the compliant path. |
| case_study | Article | Organization, citation[], FAQPage*, BreadcrumbList | All 5 | Business results |
| interview | Article + ProfilePage | QAPage, citation[], Speakable, FAQPage*, BreadcrumbList | None | Q&A format |
| pillar_guide | Article + ItemList | citation[], Speakable, FAQPage*, BreadcrumbList | All 5 | Comprehensive guide |

*FAQPage = automatic when article body contains a `## Frequently Asked Questions` section with 3+ Q&A pairs. Excluded types: recipe, live_blog, faq_page-as-primary.

*Product (auto) = detected from review/comparison content with extractable price + product name. Manual Product Schema Block always overrides auto-detection for the same @type.

**LocalBusiness as primary @type** = manual Schema Block ONLY (Pro+). v1.5.216.62.24 retired the auto-emit-from-article-listing path because article-style sites do not qualify for Google's Map Pack rich result; the manual block exists for the legitimate single-business-page use case where a customer is publishing about *their own* business.

---

## 6. AUTHOR MARKUP RULES

- `author.@type`: "Person" (for individual authors) or "Organization" (for brand/company)
- `author.name`: Display name only. NEVER email address.
  - Get from: WordPress user display_name, or site name as fallback
- `author.url`: Author archive page or social profile URL
- No job titles, no "posted by", no honorifics in the `name` field
- Multiple authors: list each as separate Person object in an array

---

## 7. VALIDATION AND TESTING

- **Rich Results Test:** https://search.google.com/test/rich-results
- **Schema Markup Validator:** https://validator.schema.org
- **Google Search Console:** Rich result status reports after deployment
- **URL Inspection Tool:** Confirm Google found structured data on specific pages

---

## 8. IMPLEMENTATION NOTES

- JSON-LD is generated by `Schema_Generator::generate()` in `includes/Schema_Generator.php`
- Stored in `_seobetter_schema` post meta
- Output via `wp_head` hook (priority 1) — skipped if AIOSEO/Yoast/RankMath active
- Also appended as `wp:html` block in post_content at save time
- Multiple schemas per page are valid (e.g., Article + FAQPage + BreadcrumbList)
- Always include `@context: "https://schema.org"`
