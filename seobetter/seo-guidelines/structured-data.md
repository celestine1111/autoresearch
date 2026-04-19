# SEOBetter Structured Data Reference

> **Purpose:** Google-compliant JSON-LD schema generation for all 21 article types.
> Based on Google's official documentation (fetched April 2026).
>
> **Sources:**
> - https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data
> - https://developers.google.com/search/docs/appearance/structured-data/sd-policies
> - https://developers.google.com/search/docs/appearance/enriched-search-results
>
> **Last updated:** 2026-04-19
> **Code:** `includes/Schema_Generator.php`

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

## 3. RICH RESULT STATUS (as of April 2026)

| Schema Type | Google Status | Used by SEOBetter |
|---|---|---|
| **Article / BlogPosting / NewsArticle** | ACTIVE | Yes — most article types |
| **Recipe** | ACTIVE (enriched) | Yes — recipe type |
| **Review snippet** | ACTIVE | Yes — review type |
| **FAQPage** | RESTRICTED (gov/health only) | Yes — but no rich results for most sites |
| **HowTo** | DEPRECATED (Sept 2023) | Yes but SHOULD NOT — no rich results |
| **LocalBusiness** | ACTIVE | NOT YET — needed for places articles |
| **ItemList** | ACTIVE | Yes — listicle type |
| **BreadcrumbList** | ACTIVE | Yes — all types |
| **DefinedTerm** | ACTIVE | Yes — glossary type |

---

## 4. REQUIRED AND RECOMMENDED FIELDS PER TYPE

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

### Recipe (v1.5.121 — Google-exact format)

**Required:** `name`, `image`

**Implemented fields (all extracted from content, never hardcoded):**

| Field | Source | Example |
|---|---|---|
| `name` | H2 heading of each recipe section | "Crunchy PB Pup Biscuits" |
| `image` | Featured image (1st recipe) or section `<img>` (others) | Array of URLs |
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

**BANNED:** Hardcoded times/yields/ratings/cuisine. If the content doesn't state a value, the field is OMITTED.

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

### Review
**Required:** `author`, `itemReviewed`, `itemReviewed.name`, `reviewRating`
**Recommended:**
- `datePublished`
- `reviewRating.ratingValue` — MUST be derived from content (e.g., verdict section)
- `reviewRating.bestRating`, `worstRating`

**BANNED:** Hardcoded ratingValue. If the article doesn't contain a rating, use Article schema instead.

### FAQPage
**Required:** `mainEntity` (array of Question/Answer)
**Note:** Rich results only for government/health authority sites. Schema still valid for semantics.

### HowTo — DEPRECATED
**Status:** Google removed HowTo rich results September 14, 2023.
**Action:** Use Article/BlogPosting schema instead. HowTo markup has zero Google benefit.
**Keeping for:** Non-Google search engines (Bing, Yandex) may still use it.

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

---

## 5. CONTENT TYPE → SCHEMA TYPE MAPPING

| Content Type | Primary @type | Secondary @type(s) | Notes |
|---|---|---|---|
| blog_post | BlogPosting | FAQPage (if FAQ section exists) | Standard blog article |
| how_to | Article | FAQPage (if FAQ exists) | NOT HowTo (deprecated by Google) |
| listicle | Article | ItemList, FAQPage | ItemList for numbered items |
| review | Review OR Article | FAQPage | Review ONLY if rating extractable, else Article |
| comparison | Article | FAQPage | No special schema |
| buying_guide | Article | ItemList, FAQPage | ItemList for product list |
| recipe | Recipe | FAQPage | Required: name + image. No hardcoded times. |
| faq_page | FAQPage | — | Restricted to gov/health for rich results |
| news_article | NewsArticle | FAQPage | News-specific fields |
| opinion | OpinionNewsArticle | FAQPage | Opinion variant |
| tech_article | TechArticle | FAQPage | Technical article |
| white_paper | Article | FAQPage | Report format |
| scholarly | ScholarlyArticle | FAQPage | Academic format |
| live_blog | LiveBlogPosting | — | Live event coverage |
| press_release | NewsArticle | — | Corporate announcement |
| personal_essay | BlogPosting | — | Personal narrative |
| glossary_definition | DefinedTerm | — | Single term definition |
| sponsored | Article | — | Note: AdvertiserContentArticle not recognized by Google |
| case_study | Article | FAQPage | Business results |
| interview | Article | FAQPage | Q&A format |
| pillar_guide | Article | FAQPage, ItemList | Comprehensive guide |
| LOCAL (places) | LocalBusiness | ItemList | When article lists real businesses |

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
