# SEOBetter Master Guidelines — SEO, GEO & AI Search Optimization

> **CODE WORD: When the user starts a prompt with `/seobetter` — READ ALL 5 .md files before making any changes:**
> 1. **plugin_UX.md** — UI elements that must never be removed (verification checklist)
> 2. **article_design.md** — Article HTML/styling specification
> 3. **plugin_functionality_wordpress.md** — Complete technical reference
> 4. **external-links-policy.md** — Outbound link / citation / whitelist rules
> 5. **This file (SEO-GEO-AI-GUIDELINES.md)** — SEO/GEO rules for content generation
> **After changes, verify the plugin_UX.md checklist. If anything is missing, restore it.**
>
> **Single source of truth** for all article generation, scoring, and optimization in the SEOBetter WordPress plugin. Every prompt, scorer, and formatter MUST reference this document.
>
> **Last updated:** v1.5.11 — 2026-04-12
>
> **v1.5.164 addition:**
> - §1 readability: PHP post-generation enforcement via `simplify_readability_php()` — complex word/phrase swaps + long sentence splitting. No AI calls. Drops FK grade by 2-4 points.
>
> **v1.5.11 integration additions:**
> - §5A keyword density: now enforced post-generation (`GEO_Analyzer::check_keyword_density()`, 10% weight)
> - §4B humanizer: banned-word scanner runs on every save (`check_humanizer()`, 4% weight)
> - §15B CORE-EEAT lite: 10-item rubric scored per save (`check_core_eeat()`, 5% weight)
> - §15B CORE-EEAT full: 80-item rubric + VETO items on demand (`CORE_EEAT_Auditor::audit()`, `GET /seobetter/v1/core-eeat/{post_id}`)
> - §28 5-Part Framework: phase tracking scaffolding (`Content_Ranking_Framework.php`, wired into Async_Generator)
> - §12B typography: system fonts, `clamp()` fluid sizes, `text-wrap: balance/pretty` now in Content_Formatter classic mode
> - §11 External links: `rel="noopener nofollow" target="_blank"` on all external `<a>` tags
> **Sources:** KDD 2024 GEO Research, Princeton University (arxiv.org/pdf/2311.09735), SE Ranking (129K domain study), Semrush, CORE-EEAT Benchmark, Google Search Quality Guidelines, Google Helpful Content Guidelines, Google Cloud NLP, 5-Part Content Ranking Framework, Last30Days v2.9.6 (multi-source trend research), installed Claude Skills (ai-seo, geo-content-optimizer, seo-content-writer, content-quality-auditor, meta-tags-optimizer, serp-analysis, last30days)

---

## 1. GEO VISIBILITY BOOST METHODS (Research-Backed)

These are the proven methods from the KDD 2024 research paper on Generative Engine Optimization. Use these percentages in scoring and prioritization.

| Method | Visibility Boost (Position-Adjusted) | Subjective Impression | Priority | How to Apply |
|--------|:---:|:---:|:---:|---|
| Add quotations | **+41%** | +28% | CRITICAL | Expert quotes with name, title, org. 2+ per article. |
| Add statistics | **+40%** | +23.7% | CRITICAL | Specific numbers with (Source Name, Year). 3+ per 1000 words. |
| Cite sources | **+30%** | +21.9% | CRITICAL | Inline citations in [Source, Year] format. 5+ per article. |
| Fluency optimization | **+25-30%** | +15-20% | HIGH | Smooth transitions, natural flow, polished writing. |
| Easy-to-understand | **+15-30%** | varies | HIGH | Grade 6-8 reading level. Short sentences. |
| Authoritative tone | **+12%** | +12% | HIGH | Confident, decisive language. Active voice. |
| Technical terms | **modest** | modest | MEDIUM | Domain-specific terminology where appropriate. |
| Unique vocabulary | **minimal** | minimal | LOW | Varied word choice — minimal impact on its own. |
| **Keyword stuffing** | **-9%** | negative | **BLOCKED** | **Actively hurts AI visibility. Never do this.** |

**Best combination:** Fluency + Statistics = +5.5% above single-method performance. Adding Citations as supplement = average +31.4% improvement.

**Key insight — Authority matters inversely:**
| Site Authority (SERP Rank) | Cite Sources Boost | Statistics Boost |
|---|:---:|:---:|
| Rank 5 (lowest authority) | **+115.1%** | +97.9% |
| Rank 3-4 (mid authority) | +40-60% | +30-50% |
| Rank 1 (highest authority) | **-30.3%** | modest |

**Low-authority sites benefit most from GEO.** Citation and statistics optimization can more than double visibility for sites that don't rank well traditionally. High-authority sites already get cited — aggressive optimization can actually reduce their visibility.

**Domain-specific effectiveness:**
| Method | Best For These Domains |
|---|---|
| Authoritative tone | Debate, History, Science |
| Fluency optimization | Business, Science, Health |
| Cite sources | Law & Government, Factual statements |
| Add quotations | People & Society, History, Explanations |
| Add statistics | Law & Government, Debate, Opinion |

Source: Princeton University GEO study (arxiv.org/pdf/2311.09735), KDD 2024, GEO-bench with 10,000 queries across 25 domains.

---

## 2. AI PLATFORM RANKING FACTORS

### International engines (v1.5.206c addendum — Layer 6)

When the user selects a non-Western target country (CN / JP / KR / RU / DE / FR / ES / IT / BR / PT / IN / SA / AE / MX / AR), `Async_Generator::get_system_prompt()` injects a `REGIONAL CONTEXT` block (via `Regional_Context::get_block()`) telling the AI which regional authority sources to prefer (matching the v1.5.206b whitelist), measurement units, currency, date format, decimal/thousand separator conventions, and editorial register (Japanese keigo, German Sie, French vous, Korean 존댓말, Argentine 'vos', etc.). This steers the generated prose toward citations and style conventions read by that region's audience — a prerequisite for Baidu / Yandex / Naver / regional LLM (Doubao / ERNIE / DeepSeek / Qwen / Kimi / YandexGPT / GigaChat / HyperCLOVA X / Kanana / Sakana AI / PLaMo / ELYZA / Mistral / Aleph Alpha) citation eligibility. Byte-identical prompt (no-op) for empty / US / GB / AU / CA / NZ / IE. Full per-country blocks live in `international-optimization.md §2` + code in `includes/Regional_Context.php`.

**Canonical translations block (v1.5.206d-fix6):** Additionally, for every non-English article, `Localized_Strings::canonical_translation_block( $language )` appends a table of EXACT canonical translations the plugin detects (Key Takeaways, References, Last Updated, FAQ, Introduction, Conclusion, Tip, Note, Warning, Pros, Cons). This prevents AI-invented synonyms (e.g. Korean `중요 포인트` instead of canonical `핵심 요약`) from breaking `Content_Formatter` detection and `GEO_Analyzer` BLUF/Freshness/References scoring. Language-agnostic by design — works for any language in the `Localized_Strings` table. Empty for English articles.

**NO ENGLISH HEADINGS absolute rule (v1.5.206d-fix7):** The LANGUAGE clause for non-English articles now appends an explicit ban on English H2/H3 headings — INCLUDING descriptive headings the AI invents outside the section list (e.g. "Why Trust Our Picks", "Seongsu's Best: 카페 오월", "The Bottom Line"). The rule instructs the AI: *if you cannot translate a heading, omit it entirely*. Fires automatically on every generation step via the system prompt. Complements fix6's canonical-translations table (which covers the 11 named anchors); fix7 covers the long-tail of invented headings the table doesn't enumerate. Neither affects the §3.1A Genre Override table or the §10 Content Type → Schema Mapping — both remain language-agnostic structural contracts.

**Language-aware headline generation (v1.5.206d-fix7):** `AI_Content_Generator::generate_headlines()` now accepts `$language` and produces headlines in the target language for non-English articles. Previously English templates ("How to Choose X: Expert Guide") wrapped a non-English keyword, producing mixed-language titles like "How to Find 서울 최고의 카페 2026: The Ultimate Insider Guide". Does not affect schema mapping or genre overrides.

**Centralized language-name table (v1.5.206d-fix7.1):** The BCP-47 → human-readable language name mapping (46 languages) previously lived in two places: `Async_Generator::get_system_prompt()` (46 entries) and `AI_Content_Generator::generate_headlines()` (35 entries). The two drifted — Swahili / Urdu / Sinhala / Nepali / Mongolian / Kazakh / Uzbek / Icelandic / Estonian / Latvian / Lithuanian were missing from the second and fell back to "English" in headline prompts. Extracted into `Localized_Strings::get_language_name( $lang )` as single source of truth. No effect on §3.1A Genre Overrides or §10 Schema Mapping — pure refactor of the lang-name lookup, no template or schema behaviour changed.

**Expanded canonical translations + anti-bilingual + freshness sanitizer (v1.5.206d-fix9):** Fix6's canonical-translations table covered 11 anchors; the 21 prose templates collectively use 82 unique section names. When a prose template showed "Why This Matters" / "Common Problems" / "What You Will Need" (and other uncovered anchors), non-English AI output compromised with bilingual `English: {lang} translation` colon-separated headings. Fix9 expands the canonical table by 17 high-frequency section names (`why_this_matters`, `common_problems`, `what_you_will_need`, `what_to_look_for`, `methodology`, `findings`, `executive_summary`, `abstract`, `prerequisites`, `further_reading`, `examples`, `related_terms`, `short_bio`, `overall_verdict`, `analysis`, `recommendations`, `how_we_chose`) × 15 priority languages. LANGUAGE clause now also includes: (a) an absolute ban on colon-separated bilingual headings, (b) a freshness-line translation rule. Plus a defensive post-generation regex sanitizer swaps any leaked `Last Updated: [English Month Year]` to the localized form. No effect on §3.1A Genre Overrides or §10 Schema Mapping — template/schema contracts unchanged; fix9 is purely LANGUAGE-rule additions + Localized_Strings table expansion + post-gen regex safety net.

**More canonical anchors + REQUIRED SECTIONS rule (v1.5.206d-fix11):** Ben's Russian how-to test on fix9 surfaced two residuals: (a) `Step-by-Step: Как выбрать...` colon-bilingual H2 (anchor `Numbered Steps` / `Step-by-Step` not in fix9 canonical table), and (b) AI omitted FAQ / Conclusion / References sections entirely → empty FAQPage schema. Fix11 adds 7 more canonical translation keys (`numbered_steps`, `step_by_step`, `quick_comparison_table`, `closing_thoughts`, `verdict_and_rating`, `table_of_contents`, `key_highlights`) × 15 languages, and appends a REQUIRED SECTIONS — DO NOT SKIP rule to the LANGUAGE clause: explicitly tells the AI every named section in the prose template's section list MUST appear as an H2 (no compression / collapse / skip). Calls out FAQ/References/Conclusion specifically with their structural requirements. No effect on §3.1A Genre Overrides or §10 Schema Mapping — pure LANGUAGE-rule strengthening + Localized_Strings table expansion. The §3.1 Required Sections list itself is the underlying contract; fix11 just enforces it harder at AI prompt time.

**Server-side heading-language guarantee (v1.5.212.2):** Fix7 / fix9 / fix11 are all PROMPT-LAYER rules — they instruct the AI but model adherence is statistical. Ben's JP-Japanese slow-cooker recipe test (2026-04-27) confirmed that ~10% of non-English articles still ship with one English H2 leaking through (the introduction-section heading `Why Winter Slow Cooker Recipes Matter in 2026 (Stats & Trends)` was the leak). Fix `Async_Generator::enforce_heading_language()` runs AFTER `Content_Formatter::format()` and BEFORE Phase 5 quality gate: walks every H2/H3 in the rendered HTML, detects any whose visible text contains zero target-language script characters (per a SCRIPT_MAP that mirrors topic-research.js SCRIPT_RANGES — 30 BCP-47 codes covering CJK, Cyrillic, Arabic, Hebrew, Thai/Lao, Devanagari, Greek, Armenian, Georgian, Bengali/Tamil/Telugu/Kannada/Malayalam/Gujarati/Punjabi/Sinhala), and sends the wrong-script set in one batched `/api/translate-headings` LLM call to be replaced in-place. Skipped silently for English / Latin-script targets where character-class matching cannot reliably distinguish English from German/French/etc. Fail-graceful — any error returns the HTML unchanged. Universal: works for all 21 content types and all 29 covered languages. No effect on §3.1A Genre Overrides or §10 Schema Mapping — pure post-generation safety net that backstops the prompt-layer rules.

**Cross-script research auto-translation (v1.5.212.2):** Independent of the heading guard, `topic-research.js::translateKeywordToTargetLanguage()` solves a related non-English failure mode: when the user enters an English keyword (`best slow cooker recipes for winter 2026`) but selects a non-English target language (Japanese), Google Suggest / Serper / Wikipedia all see the raw English string and return English-dominant secondary + LSI keywords, even though `hl=ja` / `gl=jp` / `ja.wikipedia.org` are wired correctly. Fix: when input has zero target-language script chars AND target language is non-English, call OpenRouter once to translate the keyword, then route all data sources through the translated form. Response payload exposes both forms (`niche` + `researched_as`) so the UI can show "researched as: 冬の人気スロークッカーレシピ" under the keyword field. Fail-open — translation errors fall back to the original English keyword, so English-only customers see zero behaviour change.

**v1.5.213 schema coverage release:** Comprehensive expansion of the Layer 4 (schema) implementation across the 21 content types, driven by a research delta matrix between current emission and Google + Schema.org best practices. Specifically: (a) **Recipe articles co-emit `Article` wrapper alongside `Recipe[]`** so the page gets two rich-result lanes (Recipe card + Article snippet + Speakable voice readout) instead of one. The Article wrapper has `articleSection: "Recipe"`, `speakable.cssSelector: ['h1', '.key-takeaways', 'h2 + p']`, and @id refs to the top-level Person/Organization. (b) **`SPEAKABLE_TYPES` expanded 7 → 10** to include recipe, personal_essay, press_release — all three commonly appear in voice search results for their respective intents (recipe queries, opinion pieces, brand news). (c) **Author/Publisher @id-ref indirection across builders** — `build_article()`, `build_recipe()`, `build_review()` now emit minimal `{@type, @id, name}` refs to the canonical Person + Organization nodes at the @graph root. Pre-fix every Recipe in a multi-recipe article inlined a 13-field Person object (~500 bytes × 4 recipes = 2KB of duplicated identity per page). With @id refs the per-Recipe author shrinks to ~80 bytes and consumers join to one canonical Person. (d) **`keywords` field on Recipe schema translates** when article language is non-English — pre-fix Japanese articles shipped `keywords: "Best Slow Cooker Recipes for Winter 2026"` (English) which contradicted the Japanese article body. (e) Dead `case 'HowTo'` branch removed (Google deprecated HowTo rich result Sept 2023 — Speakable on how_to compensates). (f) `ImageObject` nodes now populate `name`/`description`/`caption` with the alt text (was empty, triggered "incomplete entity" warnings on Schema.org Validator). Deferred to v1.5.213.1+: FAQ section in Recipe template, glossary multi-term DefinedTermSet, pillar_guide hasPart cluster graph, scholarly_article isPartOf Periodical + DOI.

**Aggressive Latin-word heading detection (v1.5.212.5):** v1.5.212.3's ratio check (`latin_chars >= native_chars`) was designed to flag mixed headings where Latin dominates, but it under-flagged short English prefixes — Ben's test article shipped `Recipe 1: アイリスオーヤマ…` because 6 Latin chars vs 30+ Japanese chars failed the dominance test. Even one English word at the start of an otherwise-native heading is a leak. Fix: replaced the ratio check with "any 4+ letter Latin word triggers translation". Brand acronyms (CNN, BMW, JP, EU, NSW) are 1-3 letters and skip naturally; brand names ≥4 letters (iPhone, Tesla, Toyota) DO trigger but the translator's system prompt preserves proper nouns, so the LLM returns the brand string unchanged. Over-flagging is harmless; under-flagging ships leaks. Choose the safer side.

**Save-path heading guard (v1.5.212.4):** v1.5.212.2 wired the heading guard into `Async_Generator::run_step()` after the `'classic'`-mode formatter (preview path). v1.5.212.3 made the guard catch H1 + colon-bilingual mixed headings. Ben's re-test showed the preview was clean but the published post still leaked — root cause: `seobetter.php::rest_save_post()` line 1530 calls the formatter again with mode `'hybrid'` to produce the actual saved post_content, and that second pipeline had no guard. Schema_Generator then read the leaked H2s from the saved post_content into `Recipe.name` schema, propagating the leak into structured data. Fix: `Async_Generator::enforce_heading_language()` promoted private → public, called from the save path immediately after `format($markdown, 'hybrid', ...)` and before `wp_insert_post()`. Now both formatter paths run the same guarantee.

**Headline + meta-tag + H1 language coverage (v1.5.212.3):** Ben's first re-test of v1.5.212.2 surfaced three residual leaks: (a) v1.5.212.2's heading guard regex was `<(h[23])\b...>` so AI-emitted body H1 elements (in addition to the WP theme's post_title H1) were never inspected; (b) the "has at least one native char → skip" gate let `Best Slow Cooker Recipes for Winter 2026: アイリスオーヤマ編` through (50 Latin chars + 7 Japanese chars at the tail), which is the exact colon-bilingual pattern fix9 forbids; (c) `AI_Content_Generator::generate_headlines()` plugged the raw English keyword into a Japanese template producing mixed-language post_titles, and `generate_meta_tags()` had no `$language` parameter at all so AIOSEO meta tags were always English regardless of article body. Fix: (a) regex extended to `<(h[1-3])\b...>`; (b) ratio-based detection — count Latin word-runs of 4+ letters and native-script chars, flag for translation when Latin equals or exceeds native (brand acronyms 1-3 letters don't trigger; the translator preserves proper nouns so over-flagging is safe); (c) both `generate_headlines` and `generate_meta_tags` now translate the keyword once via `Cloud_API::translate_strings_batch()` before threading it through the prompt + keyword-presence filter + fallbacks. New `Cloud_API::translate_strings_batch()` is the shared helper for the headings guard, headline pipeline, and meta-tag pipeline. No effect on §3.1A Genre Overrides or §10 Schema Mapping — pure expansion of the prompt-layer + post-generation language guarantees to cover the remaining input vectors that fix7/9/11 + v1.5.212.2 didn't reach.

### Google AI Overviews (45% of Google searches)
- Strong correlation with traditional rankings
- Cited sources = +132% visibility boost
- Authoritative tone = +89% visibility boost
- Schema markup = 30-40% visibility boost (biggest single lever)
- Only ~15% of AI Overview sources overlap with traditional Top 10
- Targets "what is" and "how to" query patterns most

### ChatGPT (with web search)
- **Content-answer fit = ~55%** of citation likelihood (most important)
- **Domain authority = ~40%** of citation decision
- Freshness: content updated within 30 days = **3.2x more citations**
- Wikipedia = 7.8% of all ChatGPT citations
- High referring domains (350K+) = 8.4 citations per response average

### Perplexity AI
- Uses time-decay algorithm (new publishers get a shot)
- FAQ Schema (JSON-LD) = noticeably higher citation rate
- Publishing velocity matters more than keyword targeting
- Prefers self-contained, semantically complete paragraphs
- Maintains curated lists of authoritative domains

### Microsoft Copilot (Bing-powered)
- Entirely relies on Bing index
- LinkedIn and GitHub mentions provide ranking boosts
- Page speed threshold: <2 seconds is clear cutoff
- Bing submission critical (many sites only submit to Google)

### Claude (Brave Search backend)
- Very selective about citations
- Data-rich content with specific numbers > general content
- Factual density is critical signal
- Accuracy prioritized over broad coverage
- Reasoning transparency rewarded (explain WHY, not just WHAT)

---

## 3. ARTICLE STRUCTURE PROTOCOL (v2026.4 — **default profile**; v1.5.202: genre overrides documented in §3.1A)

The plugin ships TWO structural systems:

**A) Default profile** (§3.1 below) — applies to 14 of 21 content types. Standard scannable-informational-content layout with Last Updated / Key Takeaways / Content / FAQ / References. This is what most SEO plugins check for and what GEO_Analyzer scores against.

**B) Genre-override profiles** (§3.1A below) — 7 content types whose real-world publisher conventions OVERRIDE the default because forcing §3.1 onto them would break genre authenticity (a personal essay with "Key Takeaways" at the top feels like a blog post in disguise; a press release with "Key Takeaways" is not what media outlets pick up). These types follow per-genre structures documented in `article_design.md` §11 and per-type BUILD_LOG entries.

The §1 Princeton-backed boosts (quotations, statistics, citations, fluency, readability) are UNIVERSAL — they apply to every content type — but the *form* the boost takes varies by genre (see §3.1B). This is the foundation for how GEO scoring works per-type (see §6 note on per-type scoring).

### 3.1 Required Sections — DEFAULT profile

**Applies to:** `blog_post, how_to, listicle, review, comparison, buying_guide, pillar_guide, tech_article, white_paper, scholarly_article, case_study, faq_page, glossary_definition, sponsored` (14 of 21 types).

1. **Last Updated: [Month Year]** — Freshness signal at the very top
2. **H1: [Article Title]** — Keyword front-loaded, 50-60 chars
3. **H2: Key Takeaways** — 3 bullet points, 15-25 words each
4. **H2: [Content Section 1]** — Question-format heading
5. **H2: [Content Section 2]** — Question-format heading
6. **H2: [Content Section 3+]** — As many as needed for word count
7. **H2: Frequently Asked Questions** — 3-5 Q&A pairs
8. **H2: References** — 5-8 cited sources with years

### 3.1A Genre Overrides (v1.5.202)

**Applies to:** 7 content types whose real-world publisher conventions override the default. For these types, §3.1 does NOT apply — they follow documented per-genre structures. All §s below link to the research sources backing each override.

| Content Type | Override profile | Source of truth |
|---|---|---|
| `news_article` | Inverted pyramid: Lede (5 Ws, who/what/when/where/why in first 25 words) → Nut Graf → Details → Background → Closing. No standalone Key Takeaways. | AP Stylebook + journalism convention |
| `opinion` (v1.5.192) | **Hybrid** — keeps §3.1's Key Takeaways + FAQ + References, but inserts the Hook & Thesis / Arg 1 / Arg 2 / Arg 3 / The Objection / What This Means / Conclusion + CTA structure. Compliant with both §3.1 AND opinion-genre best practices. | NYT/WaPo/OpEd Project style guides, Purdue OWL, Poynter/Nieman nut-graf research, Princeton GEO (arXiv 2311.09735). See `article_design.md` §11 + BUILD_LOG v1.5.192 |
| `press_release` (v1.5.199) | Dateline + Lede (first 25 words, 5 Ws) → Body (inverted pyramid) → Key Highlights (bullet list with stats) → quotes inline in body → About the Company → Media Contact → FAQ → References. No Key Takeaways (would fail journalist pickup — see v1.5.195 research). 400 words target. | Muck Rack State of Journalism 2025, Cision journalist survey, Empathy First Media 400-word sweet spot, Google 2025 PR guidelines. See BUILD_LOG v1.5.195 + v1.5.199 |
| `personal_essay` (v1.5.201) | Literary narrative: Opening Scene (in media res) → The Central Event → Scenes and Sensory Detail → Reflection → Resolution or Lesson. No Key Takeaways or FAQ — would shatter the literary feel. Three sensory data points per scene, named places/dates/people, attributed dialogue, transformation required. 1500 words target. | NYT Modern Love, Longreads, MasterClass, Jane Friedman, Project Write Now, Google 2025 E-E-A-T Experience. See BUILD_LOG v1.5.201 |
| `live_blog` | Timestamped updates in reverse-chronological order. Coverage intro at top, then `HH:MM — [update]` entries. No Key Takeaways, FAQ, or static References. | LiveBlogPosting schema spec; live-coverage convention |
| `interview` | Intro → Bio → Q&A (the Q&A pairs ARE the content) → Closing. No separate FAQ (the whole piece is Q&A). Keeps References. | Interview journalism convention |
| `recipe` | Recipe-card structure: Story → Tips → Ingredients (list) → Instructions (numbered steps) → Notes. No Key Takeaways or FAQ — structured recipe-card fields replace them. | Recipe schema + Google Search recipe rich-result spec |

### 3.1B Princeton §1 boosts are UNIVERSAL — the form varies by genre

§1 says statistics +40%, quotations +41%, citations +30% visibility boost. These apply to EVERY content type. The *form* varies:

- **blog_post / how_to / listicle / review / comparison / buying_guide**: explicit `(Source Name, Year)` inline citations, `"quote" — Name, Title, Org` quote blocks, numeric stats.
- **opinion**: 4-8 pool-matched citations per 1000 words, steelman'd counter-argument quotes, statistics in argument sections.
- **press_release**: 1-2 named-executive quotes inline, Key Highlights bullet with stats, 1-2 outbound links max (Google post-2025).
- **news_article**: lede contains 5 Ws + primary source, body cites officials/reports.
- **personal_essay**: **dated specifics replace stats** ("$60 a week", "four months in", "October 2019"). Named people + places + dialogue replace expert quotes. Same purpose (+factual density, +Experience signal), different genre form.
- **recipe**: ingredient measurements + cooking times ARE the stats.
- **interview**: the interviewee's quotes ARE the attributed quotations.

GEO_Analyzer checks factual density, quote count, and citation count on the rendered HTML regardless of content type — the universal boosts still score. Only the §3.1 structural presence checks (Key Takeaways box, FAQ H2, References H2) are skipped for §3.1A override types (see §6).

### 3.1C When to update §3.1A

Whenever a new content type is added or an existing type's structure is redesigned based on publisher research, update §3.1A in the same commit as the code change. This table is the single source of truth for "which types do NOT follow §3.1". If a type is missing from the table, GEO_Analyzer assumes it follows the default.

### 3.2 Focus Keyword in First Paragraph (CRITICAL — SEO plugins check this)

SEO plugins scan the **first `<p>` tag** in `post_content` for the focus keyword. In SEOBetter articles, the first content is the Key Takeaways section. Therefore:

**Rule 1: Key Takeaways MUST contain the keyword**
The **first bullet** of Key Takeaways MUST include the exact primary keyword. This is the very first text content SEO plugins encounter.

**Rule 2: First content section MUST contain the keyword in the first sentence**
The introduction paragraph (first H2 section after Key Takeaways) must contain the **exact primary keyword** in the **first sentence**. This makes the topic clear immediately.

**Combined effect:** The keyword appears in both the Key Takeaways (first `<li>`) AND the introduction paragraph (first `<p>` after first content H2). Both locations are checked by different SEO plugins.

**Rules for the introduction paragraph:**
- Bold the keyword on first mention: `**keyword phrase**`
- Directly state what the article is about
- Do NOT start with "[Keyword] is..." or "[Keyword] refers to..." — those are AI patterns
- Jump into a specific fact, opinion, or context that includes the keyword naturally

**Example:**
> **Key Takeaways:**
> - **Reptile shop Melbourne** stocks vary widely — the best ones carry vet-grade supplies alongside basics
>
> **First section:**
> **Reptile shop Melbourne** owners know the difference between a good heat lamp and a cheap one.

**Implementation:** In Async_Generator.php:
- Key Takeaways prompt: "The FIRST bullet MUST contain the exact keyword"
- First content section (index === 1): INTRODUCTION RULE applied with keyword-in-first-sentence requirement
- Key Takeaways is index 0, first content is index 1 — intro rule applies to index 1 only

### 3.2b Section Opening Rule
Every H2/H3 section MUST begin with a paragraph that:
- Directly answers the heading question (do not restate the heading)
- Functions as a standalone answer if extracted by AI
- Contains the primary or secondary keyword
- Never starts with a pronoun
- Varies in length — not every opening needs to be exactly 40-60 words. Some can be shorter (25-35), some longer (50-70). The key is answering the heading directly.

### 3.3 Island Test (Context Independence)
- **NEVER** start paragraphs with: It, This, They, These, Those, He, She, We, Its
- Always use specific entity names instead
- Every paragraph must be semantically complete in isolation
- AI models extract individual paragraphs — each must stand alone

### 3.4 Paragraph Structure
- Vary paragraph length — some 2-3 sentences, some 4-5. Do not make every paragraph the same size.
- One main idea per paragraph
- Mix sentence lengths: short punchy (under 8 words), medium (15-20), occasional longer (25-30)
- No more than 3 consecutive sentences of similar length (burstiness signal)
- Start some sentences with "But", "And", "So" — humans do this naturally
- Use occasional fragments. They work.
- Bold the primary keyword once in the introduction only — no other bold

---

## 4. CONTENT EXTRACTABILITY BLOCKS

These are the specific content patterns that AI models extract and cite. Every article should contain multiple block types.

### 4.1 Definition Block — for "What is [X]?" queries
```
[Term] is [1-sentence definition]. [Expanded 1-2 sentence explanation]. [Brief context with source].
```
- 25-50 words, standalone, starting with the term

### 4.2 Step-by-Step Block — for "How to [X]" queries
```
1. **[Step Name]**: [Clear action description in 1-2 sentences]
2. **[Step Name]**: [Clear action description]
```
- Numbered lists, 5+ steps optimal

### 4.3 Comparison Table — for "[X] vs [Y]" queries
- Structured markdown tables with criteria rows
- Include "Best For" summary row
- Bottom-line recommendation row mandatory
- **Tables get cited 30-40% more than prose**

### 4.4 FAQ Block — for follow-up questions
- Questions phrased exactly as users search
- Direct answer in first sentence
- 50-100 word answers optimal
- Natural question language (not formal)

### 4.5 Statistic Citation Block
```
[Claim]. According to [Source/Organization], [specific statistic with number and timeframe] ([Source, Year]). [Context on why this matters].
```
- Always include: source name, specific number, timeframe

### 4.6 Expert Quote Block
```
"[Direct quote]," says [Expert Name], [Title/Role] at [Organization] ([Source, Year]). [1 sentence context].
```
- Full attribution required (name, title, org)

### 4.7 Self-Contained Answer Block
```
**[Topic/Question]**: [Complete answer in 2-3 sentences with specific details/numbers/examples].
```
- Must make sense without surrounding context

### 4.7B PLACES RULES — Anti-Hallucination for Local Businesses (`v1.5.23+`)

For any article whose keyword triggers local intent (e.g. "best gelato shops in Lucignano Italy"), the AI system prompt now includes a **PLACES RULES** block that enforces closed-menu grounding for business names — identical pattern to the existing CITATION RULES for URLs.

**What it does:**
- The cloud-api research pipeline fetches real businesses from OpenStreetMap (Nominatim + Overpass) before the article is written and injects them into the prompt as a `REAL LOCAL PLACES` section.
- The system prompt tells the AI: "These are the ONLY businesses you may name. If it's not on the list, you can't serve it."
- 7 rules enforce: exact character-matching of names, real addresses from the list, one-use-per-place, explicit ban on inventing "plausible-sounding" businesses, fallback behavior when zero places returned (write a general article + disclaimer instead of a fake listicle).

**Why:** Before v1.5.23 the plugin had ZERO place data in its research sources. Small towns + specific business types produced 100% hallucinated listicles that got user Ben caught testing `best gelato shops in Lucignano Italy 2026` — every shop listed was invented.

**Post-generation safety:** [GEO_Analyzer::check_local_places_grounding()](../includes/GEO_Analyzer.php) runs a sentinel check after generation. For local-intent listicle/buying_guide/review/comparison content, if the article has no map URLs AND no specific addresses, the sentinel fires and floors `geo_score` at 40 (F grade). The user sees the red flag immediately and regenerates with the grounding data.

**Source locations:**
- Prompt rules: [Async_Generator.php::get_system_prompt()](../includes/Async_Generator.php#L601) — PLACES RULES block (after CITATION RULES)
- Research fetcher: [cloud-api/api/research.js::fetchPlacesWaterfall()](../cloud-api/api/research.js) (v1.5.24 — was `fetchOSMPlaces` in v1.5.23)
- Sentinel: [GEO_Analyzer.php::check_local_places_grounding()](../includes/GEO_Analyzer.php) + `generate_suggestions()` → emits `local_places` high-priority suggestion

**v1.5.24 — 5-tier waterfall (upgrade from v1.5.23 OSM-only):**

The Places grounding pipeline now fetches from 5 providers in sequence, stopping at the first tier returning ≥3 verified places:

1. **OpenStreetMap** (free, always on) — Tier 1
2. **Wikidata SPARQL** (free, always on) — Tier 2 adds named landmarks and historical businesses
3. **Foursquare Places** (free 1K calls/day, optional user API key) — Tier 3 best for small cities via user check-ins
4. **HERE Places** (free 1K/day, optional user API key) — Tier 4 strong for EU/Asian tier-2 cities
5. **Google Places API (New)** (paid with $200/mo free credit, optional user API key) — Tier 5 covers remote villages

Users configure API keys in [Settings → Places Integrations](../admin/views/settings.php). Tiers with no configured key are skipped. Free baseline (OSM only, v1.5.26+) works out of the box with no setup.

The PLACES RULES system prompt block is provider-agnostic — it doesn't care which tier produced the places, just that the AI uses only names from the injected list. Hard refuse fallback (write a general informational article with disclaimer) is triggered when all configured tiers return <3 places combined.

**v1.5.26 — Layer 3 structural guarantee (`Places_Validator`):** the prompt rule + closed-menu injection is Layer 1 + 2 of the anti-hallucination architecture. LLMs sometimes ignore closed-menu instructions when the structural pressure to produce an N-item listicle is stronger than the rule. Layer 3 is a post-generation validator ([includes/Places_Validator.php](../includes/Places_Validator.php)) that walks the finished HTML, extracts business-name candidates from every H2/H3 section, compares them against the verified Places Pool using normalization + Levenshtein fuzzy match, and **deletes any section whose business name is not in the pool**. Mirrors `validate_outbound_links()` for URLs. This is the structural floor — even if the model ignores PLACES RULES entirely, the fabricated sections are removed before the article ships. If more than 50% of sections are stripped, the result is flagged `force_informational` and the user sees a critical warning in the suggestions panel. Wikidata was also removed from the active waterfall in v1.5.26 because it returned wrong-type entities (churches/hamlets) for the Lucignano test and short-circuited the tier progression.

**v1.5.27 — Pre-generation structural switch (new Layer 0):** v1.5.26 testing against Lucignano with zero OSM coverage and no configured Foursquare key proved that Places_Validator's post-generation deletion is insufficient on its own — with an empty pool, the validator's original implementation exited early and did nothing. More importantly, by the time the validator runs the model has already produced a complete 2,000-word listicle full of fabricated business names, wasting tokens and producing a gutted article. v1.5.27 adds a **pre-generation switch** in [Async_Generator::process_step()](../includes/Async_Generator.php) at the research step: when `is_local_intent === true && places_count < 2`, a `places_insufficient` flag is set on `$job['options']`. Both `generate_outline()` and `generate_section()` check this flag and inject HARD rules into their prompts that forbid business-name-shaped headings and require informational-article structure instead (history, cultural context, what to look for, regional variations, FAQ). Paired with the Places_Validator empty-pool backstop (also new in v1.5.27) that runs even with an empty pool when `is_local_intent=true` to strip any business-name sections that slip through the prompt-level forbidding. The UI then shows a high-priority `places_insufficient` suggestion explaining to the user why they got an informational article instead of the listicle they requested, and pointing them at the Foursquare signup for real listicles in future generations.

### 4.8 Auto-styled Rich Formatting Triggers (`v1.5.14+`)

The plugin's `format_hybrid()` formatter auto-detects 12 different content patterns and renders them as colored `wp:html` boxes in the saved draft. The system prompt (`Async_Generator::get_system_prompt()`) now instructs the AI to use the trigger words/structures naturally so the boxes fire reliably. **These patterns are not optional formatting suggestions — they're how the plugin produces visually rich articles.**

Trigger → styled box mapping:
- `Tip:` → blue Tip callout (max 2/article)
- `Note:` → amber Note callout (max 2/article)
- `Warning:` → red Warning callout (only when relevant)
- `Did you know?` → yellow Did-You-Know box (max 1/article)
- `**Term**: definition` → gray Definition box with accent term
- `**Whole sentence is bold.**` (own paragraph) → highlighted accent-bordered sentence (1-2/article)
- `"Quote text" — Name, Title` → italic blockquote with attribution footer
- `78%` / `3 out of 5` / etc. (anywhere in a paragraph) → stat callout with pulled-out number
- H2 `Key Takeaways` / `Key Insights` / `What to Know` / `TL;DR` / `The Bottom Line` → followed list becomes Key Takeaways box
- H2 `Pros` / `Pros and Cons` / `Benefits` / `Upsides` → followed list becomes green Pros box
- H2 `Cons` / `Drawbacks` / `Downsides` / `Limitations` / `Trade-offs` → followed list becomes red Cons box
- H2 `Ingredients` / `Materials` / `Tools` / `What You'll Need` / `Prerequisites` → followed list becomes amber Ingredients box
- For `content_type === 'how_to'`: numbered ordered lists become Step Boxes with circular numbered badges
- **Social media citation** (v1.5.17) — markdown blockquote starting with `[platform @handle]` (e.g. `> [bluesky @alice] Quote text`, optionally followed by `> https://...`) → dedicated review-before-publish card with red warning banner. Valid platforms: bluesky, mastodon, reddit, hn, dev.to, lemmy, twitter/x. **REQUIRED** for any claim sourced from social media — social content must never be woven into regular prose paragraphs so the user can review and delete each citation before publishing.

Reference: [article_design.md §5.9-5.17](article_design.md#L155). Code: [Content_Formatter.php::format_hybrid()](../includes/Content_Formatter.php#L335).

---

## 4B. HUMANIZER — ANTI-AI WRITING PATTERNS (CRITICAL)

Sources: [Wikipedia Signs of AI Writing](https://en.wikipedia.org/wiki/Wikipedia:Signs_of_AI_writing), Humanizer Skill v2.5.1, humanize-writing Skill v2.0.0 (8-pass editing), HumanizerAI agent-skills (burstiness/perplexity metrics)

AI-generated articles that "sound AI" get lower engagement, lower trust, and lower E-E-A-T scores. Google's helpful content guidelines penalize content that reads as mass-produced. Every generated article MUST avoid these patterns.

**Post-generation enforcement (v1.5.11+):** `GEO_Analyzer::check_humanizer()` scans the generated text for Tier-1 and Tier-2 banned words plus 8 banned patterns (`not only / but also`, `at its core`, `in today's world`, `delve into`, `let's dive in`, `future looks bright`, `serves as`, `ever-evolving`). Contributes **4% weight** to the final GEO score:

- Start at 100
- −15 per Tier-1 word
- −10 per Tier-2 word beyond the 2 allowed
- −10 per banned pattern
- Floor at 0

High-priority suggestion appears in the Analyze & Improve panel when score < 70, listing the specific Tier-1 words found.

### Banned Words (Never Use)
| Category | Words to Avoid | Use Instead |
|---|---|---|
| **Significance inflation** | testament, pivotal, crucial, vital, cornerstone, paradigm, game-changer | important, useful, notable, key |
| **Promotional** | vibrant, groundbreaking, renowned, breathtaking, nestled, stunning, seamless, robust, cutting-edge | well-known, effective, popular, strong |
| **AI vocabulary** | delve, foster, garner, underscore, showcase, encompass, interplay, intricate, enduring, tapestry, landscape (abstract) | explore, support, earn, show, include, connection, complex, lasting |
| **Filler** | additionally, furthermore, it is important to note, in order to, at the end of the day, in today's world, when it comes to | also, to, now, for |
| **Signposting** | let's dive in, let's explore, here's what you need to know, without further ado | *(just start the content)* |
| **Hedging** | it could potentially possibly be argued that, it is worth noting | *(state the claim directly)* |

### Banned Patterns
| Pattern | Example (Bad) | Fix (Good) |
|---|---|---|
| Copula avoidance | "serves as a reminder" | "is a reminder" |
| Em dash overuse | "the tool — which is free — works well" | "the tool, which is free, works well" |
| Rule of three | "innovation, inspiration, and industry insights" | "talks and panels" |
| -ing phrase padding | "highlighting the importance of..." | *(delete the phrase)* |
| Negative parallelism | "It's not just X; it's Y" | *(state Y directly)* |
| False ranges | "from X to Y, from A to B" | list the items simply |
| Generic endings | "the future looks bright" | state a specific next step |
| Challenges formula | "Despite challenges... continues to thrive" | state the specific challenge and response |
| Synonym cycling | "protagonist / main character / central figure / hero" | pick one term and reuse it |
| Excessive bold | "**every** **key** **term** bolded" | bold primary keyword once only |
| Fragmented headers | heading followed by one-line restatement | start with real content |

### How to Write Naturally
- Use "is", "are", "has" — simple verbs, not elaborate substitutes
- Vary sentence length: mix short (5-8 words) with medium (15-20 words)
- Be specific: "3,200 units sold in March" not "significant sales growth"
- Have a point of view — don't neutrally list everything
- Acknowledge tradeoffs: "works well for X but less suited for Y"
- Write how you would explain it to a colleague
- Occasionally use first person: "here is what stands out"

### Implementation
The `get_system_prompt()` in Async_Generator.php includes banned words (Tier 1 + Tier 2), banned patterns, rhythm rules, transition rules, and human texture guidance. Every section is generated with these constraints active.

### Sentence Rhythm (Burstiness)
AI detection tools measure "burstiness" — how much sentence length varies. AI scores low (metronomic cadence). Human writing scores high (varied rhythm).
- Target: no more than 3 consecutive sentences of similar length
- Mix short sentences (under 8 words) with medium (15-20) and occasional longer ones
- Start some sentences with "But", "And", "So"
- Use occasional fragments

### Transition Overuse
AI's favorite transitions are also its biggest tells: "Moreover", "Furthermore", "Additionally", "That said", "Moving forward", "When it comes to". Often you don't need a transition. Just start the next thought. Use actual logical connectors: "because", "so", "but".

### The "Read It Aloud" Test
After generation, every article should pass this test: read it out loud and flag anything that:
- Sounds like a press release
- No human would actually say in conversation
- Could have been written about any topic by swapping a few nouns
- Feels like it's trying too hard to sound smart

### 8-Pass Editing Framework (Reference)
Based on humanize-writing skill v2.0.0:
1. **Structure** — kill formulaic section patterns, vary lengths
2. **Inflation** — strip significance and promotional language
3. **Vocabulary** — replace Tier 1 AI words, check Tier 2 clusters
4. **Grammar** — fix copula avoidance, -ing phrases, parallelisms
5. **Rhythm** — vary sentence length, add short punchy lines
6. **Hedging** — cut filler, vague attributions, excessive qualifiers
7. **Transitions** — replace generic connectors or remove entirely
8. **Soul** — add human texture, opinions, specific reactions

---

## 5. READABILITY REQUIREMENTS

### 5.1 Reading Level Targets
| Audience | Flesch-Kincaid Grade | Description |
|---|:---:|---|
| General audience | **6-8** | Smart 12-year-old can understand |
| Technical topics | 10-12 | Professional but accessible |
| Academic | 12-14 | Only when necessary |

### 5.2 Sentence Rules (Burstiness)
AI detection tools measure sentence length variation ("burstiness"). Low burstiness = AI. High burstiness = human.
- Average: 15-20 words per sentence
- Short sentences (under 8 words): 20-30% of content — "That changed things." "It works."
- Medium sentences (10-20 words): 50-60%
- Longer sentences (20-30 words): 10-20% — let some ideas breathe
- **Critical:** No more than 3 consecutive sentences of similar length
- Start some sentences with conjunctions: "But", "And", "So", "Still"
- Use occasional fragments in non-academic writing

### 5.3 Word Choice Rules
- Simple > Complex: "buy" not "purchase", "help" not "facilitate", "use" not "utilize"
- Active voice > Passive voice
- Specific > Generic: "3,200 units sold in March" not "significant sales growth"
- Use "is"/"are"/"has" — not "serves as"/"stands as"/"boasts"
- All Tier 1 and Tier 2 banned words from Section 4B are enforced in the system prompt
- No filler phrases (see Section 4B for complete list)
- No academic jargon unless explaining technical concepts
- No signposting ("let's dive in", "here's what you need to know")
- Write like explaining to a colleague, not writing a press release

---

## 5A. KEYWORD PLACEMENT & DENSITY RULES (CRITICAL FOR SEO PLUGINS)

These rules ensure articles pass AIOSEO, Yoast, and RankMath analysis on first publish. Every generated article MUST follow all of these.

**Post-generation enforcement (v1.5.11+):** The `GEO_Analyzer::check_keyword_density()` method measures all three sub-checks (density, H2 coverage, intro placement) and contributes **10% weight** to the final GEO score. If the score falls below 60, a high-priority suggestion appears in the Analyze & Improve panel. Before v1.5.11 these rules were only in the prompt — now they're verified after the AI writes.

### 5A.1 Mandatory Keyword Placements
The **exact primary keyword** (e.g., "reptile shop melbourne") MUST appear in ALL of these locations:

| Location | Requirement | Why |
|---|---|---|
| **Meta title** | Keyword front-loaded in first half | SERP display, ranking signal |
| **Meta description** | Keyword included naturally | Click-through rate, relevance |
| **First paragraph** | Keyword in first 1-2 sentences | Topic clarity, SEO plugins check this |
| **H1 title** | Keyword included | Primary ranking signal |
| **30%+ of H2/H3 headings** | Keyword or close variant | Topical relevance, SEO plugin check |
| **Last paragraph/conclusion** | Keyword mentioned | Content closure signal |
| **Image alt text** | At least 1 image with keyword | Image SEO |
| **URL slug** | Keyword in slug | URL relevance |

### 5A.2 Keyword Density
- **Target:** 0.5%-1.5% density (keyword appears every 100-200 words)
- **Minimum:** 0.5% (AIOSEO flags below this — confirmed in live testing 2026-04-15)
- **Maximum:** 2.0% (above this = keyword stuffing, -10% AI visibility)
- **For a 1000-word article:** keyword should appear 5-15 times
- **For a 2000-word article:** keyword should appear 10-30 times
- **Use exact match AND natural variations** (e.g., "reptile shop melbourne" + "melbourne reptile shop" + "reptile store in melbourne")
- **Per-section cap formula (v1.5.60):** each section should contain `max(2, round(section_words / 250))` minimum and `max(3, round(section_words / 150))` maximum exact-match mentions. For a 400-word section: 2-3 mentions. For 600-word: 2-4 mentions. Prevents both stuffing (>2.5%) and starvation (<0.5%) failure modes.

### 5A.3 Heading Keyword Rules
- At least **30% of H2 headings** must contain the primary keyword or a close variant
- The **first H2** after Key Takeaways SHOULD contain the keyword
- Remaining headings use secondary/LSI keywords
- Never force the keyword where it sounds unnatural

### 5A.4 First Paragraph / Introduction Rule (SEO PLUGIN CRITICAL)
**This is the most commonly failed SEO check.** AIOSEO, Yoast, and RankMath all check that the focus keyword appears in the first paragraph (`<p>` tag) of the article.

The introduction paragraph MUST:
- Contain the **exact primary keyword in the FIRST SENTENCE** — not the second, not buried later
- Bold the keyword: `**keyword phrase**`
- Be 40-60 words (GEO section opening rule)
- Directly state what the article covers
- The keyword must appear as a continuous phrase (e.g., "reptile shop melbourne" not "reptile...shop...melbourne" split across sentences)

**Correct example:**
> **Reptile shop Melbourne** offers a wide range of supplies for reptile owners across Victoria. Choosing the best reptile shop Melbourne has available requires comparing prices, product range, and expert advice from experienced herpetologists.

**Wrong — keyword not in first sentence:**
> Finding the right supplies for your pet can be challenging. There are many options available. A reptile shop Melbourne residents trust will offer...

**Wrong — keyword split across sentences:**
> Looking for a reptile shop? Melbourne has several options...

**Implementation note for AI prompts:** The system prompt and first section prompt MUST explicitly say: "The FIRST SENTENCE of the article must contain the exact phrase '{keyword}'".

### 5A.5 Meta Description Keyword Rule
- Must contain the **exact primary keyword** naturally
- 150-160 characters
- Must read like compelling ad copy, not keyword-stuffed

---

## 6. GEO SCORING RUBRIC (0-100)

This is the scoring system used by `GEO_Analyzer.php`. Each check is weighted. **Updated in v1.5.11** — three new checks added (keyword density, humanizer, CORE-EEAT lite). Existing weights reduced proportionally to keep the total at 100.

| Check | Weight | Score 100 | Score 0 |
|---|:---:|---|---|
| Readability | 10% | Grade 6-8 | Grade 14+ |
| BLUF Header | 8% | Key Takeaways present with 3 bullets | Missing |
| Section Openings | 8% | All sections have 40-60 word openers | None do |
| Island Test | 8% | No pronoun starts | 20%+ violate |
| Factual Density | 10% | 3+ stats per 1000 words | 0 stats |
| Citations | 10% | 5+ real `<a href>` links in the rendered HTML (v1.5.72 — now receives raw HTML, not stripped text; v1.5.68-71 were broken: always scored 0) | 0 real links |
| Expert Quotes | 6% | 2+ attributed quotes | 0 quotes |
| Tables | 5% | 2+ comparison tables | 0 tables |
| Lists | 4% | 4+ lists | 0 lists |
| Freshness Signal | 6% | "Last Updated" present | Missing |
| Entity Usage | 6% | 5%+ named entity density | Under 1% |
| **Keyword Density** ⭐ | **10%** | 0.5-1.5% density, ≥30% H2 coverage, keyword in first 150 chars | No keyword or 0% density or >2.5% stuffing |
| **Humanizer** ⭐ | **4%** | Zero Tier-1 AI words, ≤2 Tier-2, no banned patterns | 4+ Tier-1 words |
| **CORE-EEAT Lite** ⭐ | **5%** | 10/10 items pass (see §15B rubric) | 0/10 |
| **International Signals** 🌐 | **6%** *(only when country ≠ US/GB/AU/CA/NZ/IE)* | Language matches country's primary language + localized freshness label present + at least one regional citation | All three signals missing |

⭐ = added in v1.5.11 (guideline §5A, §4B, §15B integration).
🌐 = added in v1.5.206d (Layer 6 scoring — country-gated 15th check).

### Language-aware scoring (v1.5.206d — Layer 6)

Several of the 14 existing checks were English-biased and produced artificially low scores on non-English articles (the "Japanese article scoring 31 despite being well-formed" bug reported 2026-04-23). v1.5.206d fixes these:

| Check | Language fix |
|---|---|
| `word_count` (used by many checks) | `count_words_lang()` — CJK (ja/zh/ko/th) use character-count ÷ 2 heuristic instead of `str_word_count()` returning 0 |
| `bluf_header` | Accepts the localized Key Takeaways label (e.g. `重要なポイント`) in addition to English patterns |
| `freshness_signal` | Accepts the localized "Last Updated" label (e.g. `最終更新日`) in addition to English regex |
| `section_openings` | 40-60 word threshold now uses language-aware word count |

**International Signals (15th check)** — only added to the rubric when the target country is set AND not in `[US, GB, AU, CA, NZ, IE]`. Western-default articles are byte-identical to the v1.5.204 rubric (total still sums to 100). Non-Western articles absorb the 15th check at 6% weight (total becomes 106 for those articles; the weighted-score loop handles normalisation via `array_sum($weights)` as the divisor).

### Per-type scoring gating (v1.5.204 — implemented)

The 14 checks above are conceptually universal, but three of them (BLUF Header, Freshness Signal "Last Updated", and Section Openings' 40-60 word direct-answer rule) were designed against the §3.1 DEFAULT profile. Per §3.1A, seven content types follow genre-override profiles that legitimately skip these structural elements by design.

**Implemented in `GEO_Analyzer::analyze()` (v1.5.204):** the three structural checks now skip by content_type when the type's §3.1A profile does not include the corresponding element. Skipped checks return score **100** with a detail string explaining why — the type is NOT penalised; its structure is correctly genre-appropriate.

**Per-check skip lists (from `GEO_Analyzer::analyze()`):**

| Check | Skipped for content types | Reason |
|---|---|---|
| `bluf_header` | news_article, press_release, personal_essay, live_blog, interview, recipe | No Key Takeaways by design |
| `section_openings` | recipe, faq_page, live_blog, interview, glossary_definition, personal_essay | Section form doesn't fit the 40-60 word direct-answer pattern (recipes have ingredient/step boxes, FAQ IS Q&A, live blogs are timestamped updates, interviews are Q&A, glossaries are definition-first, personal essays are literary narrative) |
| `freshness_signal` | news_article, press_release, personal_essay, live_blog, interview, recipe | Dateline (news/PR), `datePublished` in schema (recipe), or no static freshness signal by genre (essay/interview/live) |

**Opinion is NOT in any skip list** — per §3.1A it is a HYBRID profile that keeps Key Takeaways + FAQ + References alongside its argumentative structure. Default scoring applies.

**Universal checks still apply to every type:** Readability, Factual Density, Citations, Expert Quotes, Entity Usage, Humanizer, Keyword Density, Tables, Lists, Island Test, CORE-EEAT. These are where genre overrides earn their score via the per-genre form of each boost (see §3.1B — e.g. personal essay's dated specifics ARE the factual-density signal).

**Effect on recently-shipped §3.1A types** (observed before vs expected after v1.5.204):
- `personal_essay` v1.5.201 — BLUF/Freshness/Openings were zeroing: 3 × 0-point checks on 8+8+6 = 22% of the rubric → artificially ≤78 cap. Now: 3 × 100-point skips → full 22% credited for correctly not having those sections.
- `press_release` v1.5.195/199 — same 22% cap. Now fair.
- `news_article` baseline — had same issue; now fair even without research-backed template.

Source: [`GEO_Analyzer.php::analyze()`](../includes/GEO_Analyzer.php) `$skip_bluf_types` / `$skip_opener_types` / `$skip_freshness_types` arrays. BUILD_LOG v1.5.204.

### Grade Scale
- **A+ (90-100):** Publish immediately — optimized for AI citations
- **A (80-89):** Strong — minor improvements possible
- **B (70-79):** Good — address flagged issues before publishing
- **C (60-69):** Needs work — multiple optimization gaps
- **D (50-59):** Significant issues — major revision needed
- **F (below 50):** Not ready — requires complete rewrite

---

## 7. TITLE TAG OPTIMIZATION

### 7.1 Requirements
- **Length:** 50-60 characters (displays fully in SERP)
- **Keyword position:** Front-loaded (first 30 chars if possible)
- **Must include:** Primary keyword, compelling hook
- **Must be:** Unique across entire site

### 7.2 Title Formulas (ranked by CTR)
1. `[Number] Best [Keyword] for [Outcome] in [Year]` — Highest CTR
2. `How to [Keyword]: [Benefit] Guide` — How-to queries
3. `What Are the Best [Keyword]? Complete Guide` — Question format
4. `[Keyword]: [Power Word] Guide You Need` — Authority
5. `[Keyword] in [Year]: What You Must Know` — Freshness

### 7.3 Title Scoring (0-100)
| Factor | Points |
|---|:---:|
| Length 50-60 chars | 25 |
| Keyword front-loaded (first 5 chars) | 25 |
| Keyword present but not first | 15 |
| Contains number | 10 |
| Contains current year | 10 |
| Question format (What/How/Why) | 10 |
| Power word (Best/Ultimate/Guide/Expert) | 10 |
| Structured (colon/dash) | 5 |

---

## 8. META DESCRIPTION OPTIMIZATION

### 8.1 Requirements
- **Length:** 150-160 characters
- **Must include:** Primary keyword (naturally), call-to-action
- **Must be:** Compelling, specific, accurate to content
- **Formula:** `[What page offers] + [Benefit to user] + [Call-to-action]`

### 8.2 Meta Description Scoring (0-100)
| Factor | Points |
|---|:---:|
| Length 150-160 chars | 30 |
| Keyword included naturally | 25 |
| Contains number/statistic | 10 |
| CTA word (learn, discover, compare) | 15 |
| Proper ending punctuation | 10 |
| Power word (free, best, proven) | 10 |

---

## 9. HEADING HIERARCHY RULES

| Level | Requirements | Keyword Strategy |
|---|---|---|
| H1 | Exactly ONE per page. Contains primary keyword. | Primary keyword or close variant |
| H2 | 3-8 per article. Question format preferred. | Secondary keywords |
| H3 | 0-3 per H2. Sub-topics. | LSI/semantic terms |

**Rules:**
- No level skipping (H1->H3 is invalid; must be H1->H2->H3)
- Headings must match how people phrase search queries
- Use question format for featured snippet + PAA targeting
- Each H2 = self-contained section that works independently

---

## 10. SCHEMA MARKUP REQUIREMENTS

### 10.1 Content Types and Schema Mapping

The plugin supports 21 content types. Each uses a different schema.org @type and prose structure:

| Content Type | Schema @type | Prose Structure | Word Range |
|---|---|---|---|
| Blog Post | BlogPosting + FAQPage | Hook → Intro → Body → Conclusion → CTA | 800-2000 |
| News Article | NewsArticle | Lede → Nut Graf → Details → Background → Closing | 400-1200 |
| Opinion / Op-Ed | OpinionNewsArticle | **v1.5.192 research-backed structure:** Key Takeaways → Hook+Thesis (nut graf by para 3) → Arg1 (strongest) → Arg2 → Arg3 (optional if >1000w) → The Objection (steelman then refute) → What This Means → FAQ → Conclusion+CTA → References. First-person encouraged, avoid "I think/I feel" hedges. Steelman the opposing view before refuting. Qualified claims beat absolutism. Sources: NYT Opinion/WaPo/OpEd Project guides; Purdue OWL; Poynter/Nieman nut-graf; Princeton GEO (arXiv 2311.09735); Ahrefs AI SEO stats; Claude/Perplexity citation studies Q3 2025; Google E-E-A-T 2025. | 800-1400 (sweet spot 900-1100) |
| How-To Guide | HowTo + Article | Why → Prerequisites → Steps → Troubleshooting → Conclusion | 800-2500 |
| Listicle | Article + ItemList | Intro → Numbered Items → Conclusion | 1000-3000 |
| Product Review | Review + Article | Intro → Specs → Experience → Pros/Cons → Verdict | 800-2000 |
| Comparison | Article + FAQPage | Overview Table → Criterion Sections → Verdict → Recommendation | 1200-2500 |
| Buying Guide | Article + ItemList | Quick Picks → Mini-Reviews → Buying Advice → FAQ | 2000-5000 |
| Ultimate Guide | Article + FAQPage | TOC → Chapters (5-10) → Summary → Resources | 3000-10000 |
| Case Study | Article | Summary → Challenge → Solution → Results → Quote | 800-2000 |
| Interview | Article | Intro → Bio → Q&A → Closing | 1000-3000 |
| FAQ Page | FAQPage | Intro → 10-15 Q&A Pairs | 500-2000 |
| Recipe | Recipe + HowTo | Story → Tips → Ingredients → Instructions → Notes | 500-1500 |
| Technical Article | TechArticle | What to Build → Prerequisites → Setup → Walkthrough → Testing | 1000-3500 |
| White Paper | Report | Executive Summary → Problem → Methodology → Findings → Recommendations | 2500-8000 |
| Scholarly Article | ScholarlyArticle | Abstract → Intro → Lit Review → Methods → Results → Discussion | 3000-8000 |
| Live Blog | LiveBlogPosting | Coverage Intro → Timestamped Updates | 500-5000 |
| Press Release | NewsArticle (`articleSection: "Press Release"`) + Organization | **v1.5.195 (research-backed):** Headline (≤70 chars, active verb, no cliché words) → Subheadline → Dateline + 5-Ws Lede (first 25 words) → Body (inverted pyramid, 2-3 sentence paragraphs) → Key Facts (3-5 bullet list for AI snippets) → Quotes (1-2 named-exec quotes) → FAQ (2-3 Q&A) → About/Boilerplate (50-100 words) → Media Contact → References. Sources: Muck Rack 2025, Cision journalist survey, Empathy First Media, pr.co LLM-native template, Google 2025 PR guidelines. Pickup-rate levers: quotes +40%, multimedia up to 9.7× engagement, Tuesday/Wednesday +30%. | 400 target, 500 max |
| Personal Essay | BlogPosting (`articleSection: "Personal Essay"`) + `citation[]` + `backstory` + `speakable` | **v1.5.201 (research-backed):** Opening Scene (in media res) → The Central Event → Scenes and Sensory Detail (3 sensory points + named places/dates/people) → Reflection → Resolution or Lesson. First-person required. Transformation required. E-E-A-T Experience signals baked into prompt (dated specifics, named people/places, sensory triplets, attributed dialogue). BAN LIST on generic openings and vague placeholders. Distinctive literary CSS: serif body, narrow column, drop cap, italic centered pull-quotes. Sources: Modern Love/Longreads/MasterClass/Jane Friedman/Google E-E-A-T 2025. | 1200-2500 (target 1500) |
| Glossary | Article + FAQPage | Definition → Explanation → Examples → Related Terms | 400-1200 |
| Sponsored | BlogPosting (`articleSection: "Sponsored"` + `backstory` + `citation[]` + optional `sponsor` Organization — v1.5.209) | Disclosure → Intro → Body → Sponsor CTA | 600-1500 |

### 10.2 Schema Implementation (v1.5.118)
- JSON-LD format only (not microdata)
- Single `<script type="application/ld+json">` with `@graph` array containing all schemas
- Must match visible page content exactly — NEVER hardcode values (times, ratings, yields)
- Validate with Google Rich Results Test: https://search.google.com/test/rich-results
- Content type selection determines which schemas are generated
- Multi-schema stacking: each article gets primary + secondary schemas automatically

### 10.3 Schema Stacking per Content Type (v1.5.210 — full 21-type matrix with universal citation[] rollout)

Complete matrix showing primary @type + secondary schemas + v1.5.192-210 per-type enrichments. **This table is the master spec — structured-data.md §4 and article_design.md §11 mirror this.** When Schema_Generator changes, update this table first.

| Content Type | Primary | Secondary Schemas | Per-type Enrichments |
|---|---|---|---|
| Blog Post | BlogPosting | FAQPage, Speakable, BreadcrumbList | — |
| How-To | Article | FAQPage, BreadcrumbList, **Speakable (v1.5.210)** | **`citation[]` (v1.5.210)**, Speakable `cssSelector: [h1, .key-takeaways, h2 + p]` |
| Listicle | Article | ItemList, FAQPage, BreadcrumbList | — |
| Review | Review (with smart `itemReviewed` @type — v1.5.136) | FAQPage, BreadcrumbList | `positiveNotes` / `negativeNotes` from Pros/Cons; country-aware currency; **`citation[]` (v1.5.210)** |
| Comparison | Article | FAQPage, BreadcrumbList | **`citation[]` (v1.5.210)** |
| Buying Guide | Article | ItemList, FAQPage, BreadcrumbList | **`citation[]` (v1.5.210)** |
| Recipe | Recipe × N | ItemList (carousel when ≥3 recipes), FAQPage, BreadcrumbList | `recipeCuisine` country-mapped (40+ countries); 3-image array (1:1, 4:3, 16:9); `HowToStep` per instruction |
| FAQ Page | FAQPage | BreadcrumbList, **Speakable (v1.5.210)** | **Speakable `cssSelector: [h1, h2 + p, h3 + p]` (v1.5.210)** — voice-native Q&A read-aloud |
| News Article | NewsArticle | Speakable, FAQPage, BreadcrumbList | `articleSection: "News"` |
| Opinion | OpinionNewsArticle (v1.5.192) | Speakable, FAQPage, BreadcrumbList | `citation[]`, `backstory: "Opinion piece..."`, `speakable.cssSelector: [h1, .key-takeaways, h2 + p]`; ClaimReview explicitly excluded |
| Press Release | NewsArticle (v1.5.195) | Organization (enriched), FAQPage, BreadcrumbList | `articleSection: "Press Release"`, `citation[]`, `speakable.cssSelector: [h1, h2 + p, .seobetter-author-bio]` |
| Personal Essay | BlogPosting (v1.5.201) | BreadcrumbList | `articleSection: "Personal Essay"`, `citation[]`, `backstory: "Personal essay..."`, `speakable.cssSelector: [h1, h2 + p, .seobetter-author-bio]` |
| Sponsored | BlogPosting (v1.5.209) | Organization (enriched), BreadcrumbList | `articleSection: "Sponsored"`, `citation[]`, `backstory: "Sponsored content..."`, optional `sponsor` Organization. **Speakable deliberately NOT added** — Google policy |
| Tech Article | TechArticle | FAQPage, BreadcrumbList | **`citation[]` (v1.5.210)** |
| White Paper | Article | FAQPage, BreadcrumbList | **`citation[]` (v1.5.210)** |
| Scholarly Article | ScholarlyArticle | FAQPage, BreadcrumbList | **`citation[]` (v1.5.210)** |
| Case Study | Article | Organization (enriched), FAQPage, BreadcrumbList | **`citation[]` (v1.5.210)** |
| Interview | Article | Organization (enriched), QAPage, FAQPage, BreadcrumbList, **Speakable (v1.5.210)** | **`citation[]` (v1.5.210)**, Speakable `cssSelector: [h1, .key-takeaways, h2 + p]` |
| Pillar Guide | Article | ItemList, FAQPage, Speakable, BreadcrumbList | **`citation[]` (v1.5.210)** |
| Live Blog | LiveBlogPosting | BreadcrumbList | — |
| Glossary | DefinedTerm | FAQPage, BreadcrumbList | — |
| Places articles (any type with local businesses) | + LocalBusiness per business | Auto-detected from addresses in content | — |

**Universal fields on every top-level schema** (v1.5.206a Layer 6):
- `inLanguage` (BCP-47) on every @type in `Schema_Generator::INLANGUAGE_ACCEPTED_TYPES` — gated per Schema.org (CreativeWork + Event descendants only; skipped for BreadcrumbList / ItemList / DefinedTerm / LocalBusiness / Organization / Product / Person / PostalAddress)
- `@context: "https://schema.org"`, `headline`, `image` (3-ratio array), `datePublished`, `dateModified`, `author` (Person), `publisher` (Organization), `mainEntityOfPage`, `description` (via `build_clean_description()`)

**Enrichment pattern** (for future content types): match the v1.5.192+ template of `articleSection` (disambiguation) + `citation[]` (outbound source graph) + `backstory` (plain-English label for AI engines) + `speakable.cssSelector` (voice-assistant selectors, where appropriate).

**Gaps (known, parked):** Remaining enrichments logged for future release, each requires code + §10 + structured-data.md sync:
- ~~Universal `citation[]` rollout to 10 more types~~ **SHIPPED v1.5.210**
- ~~Speakable for how_to / faq_page / interview~~ **SHIPPED v1.5.210**
- Scholarly `abstract` / `keywords[]` / `funder` — for AcademicGPT / Consensus / Elicit LLM engines
- Interview → ProfilePage + Person with `sameAs` — Knowledge Graph entity grounding for interviewees
- Live_blog `liveBlogUpdate[]` — timestamped item structure
- Image licensing (`ImageObject.creator`, `copyrightNotice`, `license`) — Google Images / AI Overviews licensing compliance
- Optional `citation[]` rollout to blog_post + listicle (not in v1.5.210 sign-off scope; add in follow-up if desired)

### 10.4 Schema Notes
- **HowTo** rich results DEPRECATED by Google (Sept 2023) — mapped to Article instead
- **FAQPage** rich results restricted to government/health sites — still emitted for AI/semantic value
- **Review** ratings extracted from content only — NEVER hardcoded (Google policy violation)
- **Recipe** times/yield extracted from content only — omitted if not stated
- **Product** pros/cons (positiveNotes/negativeNotes) extracted from Pros/Cons sections
- **LocalBusiness** auto-detected when article contains street addresses
- **Speakable** enables Google Assistant to read article aloud (US English)
- **`inLanguage` (v1.5.206a)** — BCP-47 language tag injected by `Schema_Generator::generate()` and the legacy `populate_aioseo()` path into top-level schemas whose `@type` accepts it per Schema.org (CreativeWork + Event descendants: `Article`, `BlogPosting`, `NewsArticle`, `OpinionNewsArticle`, `ScholarlyArticle`, `TechArticle`, `LiveBlogPosting`, `HowTo`, `Recipe`, `Review`, `ClaimReview`, `FAQPage`, `QAPage`, `ProfilePage`, `ImageObject`, `VideoObject`, `SoftwareApplication`, `Dataset`, `Course`, `Book`, `Movie`, `Event`, etc.). **Skipped for `BreadcrumbList`, `ItemList`, `DefinedTerm`, `LocalBusiness`, `Organization`, `Product`, `JobPosting`, `VacationRental`** — injecting there triggers schema.org validator warnings. Source of truth: `_seobetter_language` post meta; fallback: meta → `get_locale()` (with `_` → `-`) → `'en'`. Required for Layer 6 LLM retrieval — Baidu, Yandex, Naver, regional LLMs key off `inLanguage` to decide regional relevance. Full whitelist in `Schema_Generator::INLANGUAGE_ACCEPTED_TYPES`; see `international-optimization.md §4.1` and `structured-data.md §4` for full spec.

---

## 11. INTERNAL LINKING RULES

### Link Frequency
- Important pages: 10-15 internal links pointing to them
- Regular pages: 3-5 internal links
- Avoid: 50+ internal links on single page

### Anchor Text Distribution
- Exact match keyword: maximum 20% of anchors
- Partial match phrase: 30-40%
- Generic ("this guide", "learn more"): 40-50%
- Never "click here"

### External Link Attributes (v1.5.11+)
- All **external** links get `rel="noopener nofollow" target="_blank"` automatically
- `target="_blank"` opens in new tab (keeps user on the article)
- `rel="noopener"` prevents the new tab from accessing `window.opener` (security)
- `rel="nofollow"` tells search engines not to pass link equity (required by Google for AI-generated citations in many cases)
- **Internal** links (same host as the site) keep bare `<a>` tags with no `rel`/`target`
- Implemented in `Content_Formatter::inline_markdown()` via `preg_replace_callback`

### Orphan Page Rule
- Every published page must have 2+ internal links pointing to it
- Pages with 0 internal links = orphan = invisible to search engines

---

## 12. IMAGE SEO REQUIREMENTS

| Check | Requirement |
|---|---|
| Alt text | Descriptive, includes keyword naturally. 8-125 chars. |
| File name | Descriptive with hyphens: `email-marketing-chart.jpg` |
| Format | WebP preferred, JPEG fallback |
| Size | 30-100 KB compressed |
| Dimensions | Width/height attributes set (prevents CLS) |
| Loading | `loading="lazy"` on below-fold images |
| First image | Near top of page |
| Frequency | 1+ image per section minimum |

---

## 12B. ARTICLE HTML FORMAT (Self-Contained)

### Structure
- Output: single `<article class="sb-[uid]">` with scoped `<style>` at top
- Every CSS selector prefixed with the unique scoping class to prevent CMS collisions
- No Tailwind, Bootstrap, or framework classes
- No `<link>`, `@import`, or `<script>` tags
- Works on any CMS: WordPress, Shopify, Magento, Ghost

### Typography (implemented in v1.5.11)
- System font stacks: `ui-serif, Georgia, 'Times New Roman', serif` for headings, `ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif` for body
- `text-wrap: balance` on H1/H2/H3/H4 headings
- `text-wrap: pretty` on paragraphs
- `clamp(1.8em, 4vw, 2.4em)` for H1, `clamp(1.3em, 3vw, 1.6em)` for H2 (fluid sizing)
- `line-height: 1.7`, `max-width: 65ch` for body copy
- `::first-letter` drop cap on opening paragraph after each H2
- Implemented in `Content_Formatter::format_classic()` scoped CSS (line ~526)

### Color System
CSS custom properties at article scope level:
- `--accent` (user's accent color)
- Dark mode via `@media (prefers-color-scheme: dark)` scoped to article wrapper

### Icons in Articles — Strict Rules
- NEVER place icons next to headings, list items, or inline with sentences
- NEVER use emoji as icons in body copy
- No checkmarks before list items, no icons before H2s
- Icons permitted ONLY in: callout box corners (inline SVG, 1em, currentColor), key takeaways box header, author byline
- Default: zero icons in article body

### Images
- `loading="lazy"` and `decoding="async"` on all images
- `aspect-ratio` inline to prevent layout shift (CLS)
- `max-width: 100%; height: auto` for responsive sizing

### Performance
- Single `<style>` block (no external CSS)
- No JavaScript in article output
- Lazy-loaded images
- Minimal DOM nodes
- Scoped CSS prevents style recalculation cascading to host page

### Research Sources and Citation Rules
- DuckDuckGo web search provides real URLs for all articles
- All inline citations MUST be clickable Markdown links to real web pages
- ALWAYS link to the **specific page URL**, not the homepage (e.g., `thekitchn.com/sourdough-guide-224367` not `thekitchn.com/`)
- References section at article footer MUST contain all cited sources with clickable links
- No hallucinated citations — only cite sources from research data
- If no source exists for a claim, state the claim without any citation
- Every article MUST have a References section with 5-10 real links

---

## 13. CONTENT FRESHNESS STRATEGY

### When to Refresh
- Statistics older than 1 year
- Rankings declining for target keyword
- Competitor published better content
- Industry changes (new tools, regulations, trends)

### What to Update
- Replace outdated statistics with current data
- Add new expert quotes
- Expand thin sections
- Update broken links
- Add comparison tables if missing
- Refresh "Last Updated" date (with actual content changes)

### Freshness Signals
- "Last Updated: [Month Year]" prominently displayed
- `dateModified` in Article schema
- Actual content changes (not just date bumping)

---

## 14. AI BOT ACCESS (robots.txt)

**MUST ALLOW** (blocking = no citations possible):
```
User-agent: GPTBot        # ChatGPT search
User-agent: ChatGPT-User  # ChatGPT browsing
User-agent: PerplexityBot  # Perplexity AI
User-agent: ClaudeBot      # Claude
User-agent: Google-Extended # Gemini, AI Overviews
User-agent: Bingbot        # Microsoft Copilot
Allow: /
```

**Machine-readable files:**
- `/llms.txt` — Quick overview for AI crawlers (llmstxt.org standard)
- `/sitemap.xml` — Referenced in robots.txt

---

## 15. GOOGLE HELPFUL CONTENT REQUIREMENTS (E-E-A-T)

Source: [Google Creating Helpful Content](https://developers.google.com/search/docs/fundamentals/creating-helpful-content)

### Trust Is The Most Important Signal
Google states: "Of these aspects, trust is most important. The others contribute to trust, but content doesn't necessarily have to demonstrate all of them."

### Google's Self-Assessment Questions (MUST pass all)
Every generated article should pass these checks:
- [ ] Would you bookmark or share this with a friend?
- [ ] Does the content provide original information, reporting, or analysis?
- [ ] Does it go beyond the obvious — is the analysis insightful?
- [ ] After reading, will someone feel they learned enough to achieve their goal?
- [ ] Does it demonstrate first-hand expertise or experience?
- [ ] Is it free of easily-verified factual errors?
- [ ] Is the headline accurate and non-exaggerated?

### What Google Considers Unhelpful (AVOID)
- Content primarily made to attract search engine visits (not people)
- Mass-produced content across many topics without depth
- Targeting specific word counts (Google says no preferred length exists)
- Entering niche areas without real expertise
- Promising answers to unanswerable questions
- Creating need for user to search again for better info

### AI Content Disclosure
Google requires: "Make AI use self-evident to visitors through disclosures." When AI generates content, disclose it. Using AI to manipulate rankings violates spam policies.

### YMYL (Your Money Your Life) Content
Health, finance, safety, legal content requires STRONGER E-E-A-T. Google gives "even more weight to content that aligns with strong E-E-A-T" for YMYL topics.

### NLP Entity Optimization (Google Cloud Natural Language)
Google's NLP API evaluates content on these dimensions. Optimizing for them improves how Google understands and classifies content:

| NLP Dimension | What Google Measures | How to Optimize |
|---|---|---|
| **Entity Recognition** | Named entities (people, orgs, places, products) with salience scores | Use specific proper nouns frequently. "Dr. Sarah Chen at MIT" > "an expert". Target 5%+ entity density. |
| **Entity Salience** | How important each entity is to the overall text | Mention primary entities early and often. First mention = highest salience. |
| **Sentiment** | Score (-1 to +1) and magnitude (0 to ∞) | Commercial content: balanced (magnitude high, score near 0). Informational: neutral-positive. |
| **Content Classification** | Hierarchical categories (e.g., /Science/Astronomy) | Stay focused on one topic. Clear topical focus triggers specific (not generic) classification. |
| **Syntax Analysis** | Sentence structure, parts of speech, dependency trees | Clear simple sentences. Active voice. Explicit subjects (no pronoun starts). |

Reference: [Google Cloud Natural Language](https://cloud.google.com/natural-language)

---

## 15B. CORE-EEAT SCORING DIMENSIONS

**v1.5.11 implementation:** Two levels of enforcement now exist:

1. **Lite version (10 items)** in `GEO_Analyzer::check_core_eeat()` — runs on every save, contributes 5% to the GEO score.
2. **Full 80-item rubric** in `CORE_EEAT_Auditor::audit()` — runs on demand via `GET /seobetter/v1/core-eeat/{post_id}`. Includes VETO items that can block publication.

### Lite rubric (10 items — 1 point each, scored by GEO_Analyzer)
- **C1** Direct answer in first 150 words
- **C2** FAQ section present
- **O1** Heading hierarchy (no skipped levels, ≥3 headings)
- **O2** At least one table
- **R1** ≥5 specific numbers (%, dollars, quantities, years)
- **R2** ≥1 citation per 500 words
- **E1** First-hand language ("we tested", "in our review", "I've used")
- **Exp1** Practical examples ("for example", "such as", "e.g.")
- **A1** ≥3 named experts or organizations (proper-noun phrases)
- **T1** Acknowledges tradeoffs ("however", "while", "drawback")

### Full Content Body (CORE) — 40 items
| Dimension | Items | Key Checks |
|---|:---:|---|
| **C** Contextual Clarity | 10 | Intent alignment, direct answer in first 150 words, FAQ section |
| **O** Organization | 10 | Heading hierarchy, tables for data, schema markup, no filler |
| **R** Referenceability | 10 | 5+ precise numbers, 1 citation per 500 words, source quality |
| **E** Exclusivity | 10 | Original data, unique angle, case studies, proprietary details |

### Full Source Credibility (EEAT) — 40 items
| Dimension | Items | Key Checks |
|---|:---:|---|
| **Exp** Experience | 10 | First-hand experience, practical examples, mistakes acknowledged |
| **Ept** Expertise | 10 | Author credentials, topic depth, reasoning transparency |
| **A** Authority | 10 | Backlinks, brand recognition, cited by others |
| **T** Trust | 10 | Disclosures, balanced perspective, factual accuracy |

### Veto Items (publication blockers)
- **C01:** Title must match content (keyword absent from first 1500 chars = block)
- **R10:** No contradictions between claims (inconsistency = block — placeholder, needs LLM)
- **T04:** Required disclosures must be present (affiliate/sponsored content without disclosure = block)

When any veto item triggers, the audit returns `veto: true` and the `Content_Ranking_Framework::quality_gate()` method returns `passed: false` regardless of the raw score. The normalized score is capped at 40 when vetoed. Access the full audit at `GET /seobetter/v1/core-eeat/{post_id}`.

---

## 16. CONTENT TYPES MOST CITED BY AI

| Content Type | Citation Share | Why AI Cites It |
|---|:---:|---|
| Comparison articles | ~33% | Structured, balanced, high-intent |
| Definitive guides | ~15% | Comprehensive, authoritative |
| Original research/data | ~12% | Unique, citable statistics |
| Best-of/listicles | ~10% | Clear structure, entity-rich |
| Product pages | ~10% | Specific details AI can extract |
| How-to guides | ~8% | Step-by-step structure |

### Content that DOESN'T get cited
- Generic blog posts without structure
- Thin content with marketing fluff
- Gated content (AI can't access)
- Content without dates or author attribution
- Content in images/PDFs that AI can't parse

---

## 17. DOMAIN-SPECIFIC GEO TACTICS

### Health/Veterinary Content
- Cite peer-reviewed studies with publication details
- Include expert credentials (DVM, MD, etc.)
- Note study limitations
- Add "last reviewed" dates prominently
- Use YMYL (Your Money Your Life) trust signals

### Ecommerce Content
- Include specific pricing with dates
- Comparison tables with specs
- User review summaries
- "Best for" recommendations
- Product schema with ratings

### Technology Content
- Version numbers and dates for software
- Reference official documentation
- Code examples where relevant
- Benchmark data with methodology

### Business/Finance Content
- Reference regulatory bodies
- Specific numbers with timeframes
- Case studies with measurable results
- Quote recognized industry analysts

---

## 18. PRE-PUBLISH CHECKLIST

### Content Completeness
- [ ] Title 50-60 chars, keyword front-loaded
- [ ] Meta description 150-160 chars with CTA
- [ ] First 150 words answer the query directly
- [ ] 3+ query variants covered
- [ ] "Last Updated: [Month Year]" visible
- [ ] Author bio with credentials

### GEO Optimization
- [ ] Key Takeaways section with 3 bullets
- [ ] 40-60 word section openers after every H2
- [ ] No paragraphs start with pronouns (Island Test)
- [ ] 3+ stats per 1000 words with (Source, Year)
- [ ] 2+ expert quotes with credentials
- [ ] 5+ inline citations in [Source, Year] format
- [ ] At least 1 comparison table
- [ ] FAQ section with 3-5 Q&A pairs
- [ ] References section with sources

### Structure
- [ ] Single H1 with primary keyword
- [ ] H2s in question format
- [ ] No heading levels skipped
- [ ] 3-5 sentences per paragraph
- [ ] Bold key terms on first mention
- [ ] Lists and tables used appropriately

### Technical
- [ ] Schema markup (Article + FAQPage minimum)
- [ ] 3+ internal links to related content
- [ ] 2-3 authoritative external links
- [ ] Images with descriptive alt text
- [ ] Mobile-friendly layout
- [ ] Page loads in <3 seconds

---

## 19. SCORING THRESHOLDS FOR PLUGIN

These thresholds are used across the plugin for pass/fail decisions:

| Metric | Target | Minimum | Fail |
|---|---|---|---|
| GEO Score | 80+ | 60 | Below 50 |
| Readability Grade | 6-8 | 10 | Above 14 |
| Word Count | 2000+ | 800 | Below 300 |
| Stats per 1000 words | 3+ | 1 | 0 |
| Citations | 5+ | 2 | 0 |
| Expert Quotes | 2+ | 1 | 0 |
| Tables | 1+ | 0 | N/A |
| Section Openers (40-60w) | 100% | 60% | Below 30% |
| Island Test Pass Rate | 100% | 80% | Below 60% |
| Title Length | 50-60 | 45-65 | Below 30 or above 70 |
| Meta Description Length | 150-160 | 120-170 | Below 100 or above 180 |

---

## 20. HOW TO USE THIS DOCUMENT

### For Article Generation (AI_Content_Generator, Async_Generator)
- System prompts MUST reference Section 3 (Structure Protocol) and Section 5 (Readability)
- Section prompts MUST reference Section 4 (Extractability Blocks)
- Word count enforcement from Section 19 (Scoring Thresholds)

### For GEO Analysis (GEO_Analyzer)
- Scoring weights from Section 6 (GEO Scoring Rubric)
- Grade scale from Section 6
- Check definitions from Sections 3-5

### For Content Briefs (Content_Brief_Generator)
- Structure from Section 3
- Required elements from Sections 4 and 6
- Title optimization from Section 7
- Meta optimization from Section 8

### For Technical Audits (Technical_SEO_Auditor)
- Heading rules from Section 9
- Schema requirements from Section 10
- Image requirements from Section 12
- robots.txt from Section 14

### For Featured Snippet Optimization (Featured_Snippet_Optimizer)
- Extractability blocks from Section 4
- FAQ structure from Section 4.4
- Paragraph snippet length: 40-60 words
- List snippet length: 4-8 items
- Table snippet: 3-4 columns, 4-6 rows

### For Content Freshness (Content_Freshness_Manager, Decay_Alert_Manager)
- Refresh triggers from Section 13
- Freshness signals from Section 13

---

## 21. REAL-TIME TREND RESEARCH (Last30Days Integration)

The Last30Days skill provides real-time research across 10+ sources to inject fresh, citable data into articles. This replaces AI-hallucinated "recent statistics" with actual web data.

### Data Sources Available
| Source | What It Provides | Use For |
|---|---|---|
| **Reddit** | Discussions, recommendations, opinions | User sentiment, real-world experiences, product comparisons |
| **X/Twitter** | Breaking news, expert takes, trending topics | Latest industry developments, expert quotes |
| **YouTube** | Video content, tutorials, reviews | Multimedia references, expert demonstrations |
| **TikTok** | Trending content, short-form takes | Consumer trends, viral topics |
| **Instagram** | Visual content, brand activity | Product launches, visual trends |
| **Hacker News** | Tech discussions, startup news | Technology articles, developer opinions |
| **Polymarket** | Prediction markets, probability data | Forward-looking data, market sentiment |
| **Web Search** | News articles, blog posts, research | Traditional citations, authoritative sources |
| **Bluesky** | Decentralized social discussions | Alternative social sentiment |

### How to Use in Article Generation
1. **Before writing:** Run Last30Days research on the primary keyword
2. **Extract from results:**
   - Recent statistics with dates (last 30 days)
   - Expert quotes from social media (with attribution)
   - Trending sub-topics to cover
   - Real user questions/pain points from Reddit
   - Current pricing/product data
3. **Inject into article sections:**
   - "Recent data" field in section prompts
   - Fresh statistics replace AI-generated placeholders
   - Real Reddit/X quotes as social proof

### Integration Points in Plugin
- `Async_Generator::process_step()` — the "trends" step should use Last30Days
- `AI_Content_Generator::fetch_recent_trends()` — replace AI-hallucinated trends with real data
- `Content_Brief_Generator::generate()` — competitor analysis section uses real web data
- `Content_Refresher::refresh()` — pulls fresh data when refreshing stale articles

### Freshness Advantage for GEO
- **ChatGPT cites content updated within 30 days 3.2x more** (SE Ranking study)
- Articles with verifiable recent data get cited over older content
- Last30Days provides data with actual dates and sources — not AI approximations
- Reddit/X quotes count as "experience" signals for E-E-A-T

### Required API Keys
- `SCRAPECREATORS_API_KEY` — required for Reddit/social media search
- `BRAVE_API_KEY` — optional, improves web search quality
- `OPENROUTER_API_KEY` — already configured in SEOBetter settings (reuse)

### Output Format
Last30Days returns structured research with:
- Source URL + date for every data point
- Relevance scoring
- Cross-source synthesis
- Saved to `~/Documents/Last30Days/` for reference

---

## 22. FUTURE: NOTEBOOKLM INTEGRATION (Planned)

Google NotebookLM integration for deep content enrichment. Requires Google OAuth.

### Planned Features
- Auto-create notebook per article with source material
- Pull AI-generated summaries and audio overviews
- Generate briefing documents for complex topics
- Embed audio podcast summaries in articles
- Create study guides from article content

### Requirements (Not Yet Built)
- Google Cloud project with NotebookLM API access
- OAuth 2.0 consent screen configuration
- WordPress OAuth flow in Settings page
- Media library integration for generated assets

---

## 23. GOOGLE DISCOVER OPTIMIZATION

Google Discover shows content to 800M+ users based on their interests. Articles that appear here get massive traffic spikes.

### Requirements
- **Image:** Minimum **1200px wide**, landscape, 16:9 ratio, high-quality photo (not logos or text-heavy graphics)
- **Meta tag required:** `<meta name="robots" content="max-image-preview:large">` — without this, articles are ineligible
- **Content:** Timely content, compelling storytelling, or unique insights
- **E-E-A-T:** Strong author credentials, original reporting, unique data
- **Page experience:** Good Core Web Vitals (LCP < 2.5s, CLS < 0.1)

### What Gets Articles Into Discover
- Large, high-quality featured images (1200x630+ px)
- Compelling headlines (not clickbait)
- Fresh, timely content on topics users follow
- Strong topic authority (multiple articles on same subject)
- No sensationalism, misleading titles, or withheld information

### Plugin Implementation
- `set_featured_image()` downloads 1200x630 images ✓
- Schema_Generator outputs Article schema ✓
- Social_Meta_Generator outputs OG image tags ✓
- **TODO:** Add `max-image-preview:large` to `<head>` output

---

## 24. AI SEARCH FEATURES OPTIMIZATION

### Google AI Overviews & AI Mode
- **No special markup required** — Google explicitly states there are no AI-specific optimization requirements
- Content selection based on: relevance, indexability, existing search eligibility
- AI Overviews use "query fan-out" — issuing multiple sub-queries — which surfaces content from **more diverse sources** than traditional search
- Clicks from AI Overviews are **higher quality** (users spend more time on site)
- To appear: ensure content is indexed, has snippets enabled, follows standard SEO

### How to Maximize AI Overview Inclusion
1. **Answer questions directly** in first 40-60 words (Section 3.2)
2. **Use structured data** — FAQPage, HowTo, Article schema
3. **Provide factual, citable content** — statistics, expert quotes, tables
4. **Don't block snippets** — avoid `nosnippet` meta tag
5. **Match query intent exactly** — title and H1 should mirror the search query

### Control Mechanisms
- `nosnippet` — prevents content from appearing in AI features
- `max-snippet:[number]` — limits snippet length
- `data-nosnippet` — excludes specific HTML elements from snippets
- `Google-Extended` user-agent — blocks AI training (but NOT search/snippets)

---

## 25. AI VOICE SEARCH & ASSISTANT OPTIMIZATION

### Speakable Schema Markup
Add `speakable` property to Article schema to mark content as voice-assistant-friendly:

```json
{
  "@context": "https://schema.org",
  "@type": "Article",
  "headline": "Article Title",
  "speakable": {
    "@type": "SpeakableSpecification",
    "cssSelector": [".key-takeaways", ".faq-answer", "h2 + p"]
  }
}
```

### Voice Search Content Rules
- **Answer in 40-60 words** — voice assistants read short, direct answers
- **Use question-format headings** — "What is X?", "How does X work?"
- **FAQ sections are critical** — voice assistants pull from FAQ schema
- **Conversational tone** — write how people speak, not how they type
- **Local keywords** — voice searches are 3x more likely to be local ("near me", city names)

### Content Blocks Optimized for Voice
1. **Definition blocks** — "X is [definition]." → perfect for "What is X?" queries
2. **Step-by-step blocks** — numbered lists → perfect for "How do I X?" queries
3. **FAQ blocks** — question + 60-80 word answer with data point in first sentence → directly cited by AI search engines (v1.5.171: optimized for Google AI Overviews, Perplexity, ChatGPT extraction)
4. **Key Takeaways** — bullet summaries → read as quick answers

---

## 26. INTERNATIONAL SEARCH ENGINE OPTIMIZATION

### Yandex (Russia — 60% market share)
- Supports Schema.org structured data
- Prefers content in Russian with proper Cyrillic encoding
- `hreflang` tags for multilingual content
- Yandex Webmaster Tools submission
- Quality factors: text uniqueness, behavioral factors (time on page, bounce rate)

### Baidu (China — 75% market share)
- Requires ICP filing for .cn domains
- Prefers Simplified Chinese content
- Meta keywords tag still used (unlike Google)
- Baidu Webmaster Tools submission
- Fast server response time critical (< 1s)

### Naver (South Korea — 60% market share)
- Naver Blog integration preferred
- Structured data via Naver Search Advisor
- Korean language content prioritized

### Mercado Libre / Google LATAM (South America)
- Spanish/Portuguese content with local variations
- `hreflang` tags: `es-AR`, `es-MX`, `es-CO`, `pt-BR`
- Google dominates but local directory listings matter
- Mobile-first critical (80%+ mobile usage in LATAM)

### Universal International SEO Rules
- `hreflang` tags for each language/region version
- `<html lang="xx">` attribute set correctly
- Canonical URLs pointing to correct language version
- Server location or CDN for target region
- Content in target language (not just translated — localized)

---

## 27. AI SNIPPET OPTIMIZATION (FOR LLM CITATIONS)

### How LLMs Select Content to Cite
AI models (ChatGPT, Gemini, Claude, Perplexity, Copilot) select citations based on:

1. **Self-contained answer blocks** — paragraphs that make sense in isolation
2. **Factual density** — specific numbers, dates, names > vague claims
3. **Attribution chains** — content that cites other sources is seen as more authoritative
4. **Structured data** — FAQPage schema content is extracted directly
5. **Freshness** — recently updated content (within 30 days) cited 3.2x more

### AI Snippet Content Patterns (implement in every article)

**Pattern 1: Direct Answer Block**
```
[Question as H2]
[40-60 word paragraph directly answering the question with a specific fact/number]
```
→ This exact block gets extracted by AI as a snippet

**Pattern 2: Definition + Context Block**
```
**[Term]** is [1-sentence definition]. [Supporting fact with source]. [Why it matters].
```
→ Cited for "What is X?" queries

**Pattern 3: Comparison Table**
```
| Feature | Option A | Option B |
|---------|----------|----------|
| Price   | $X       | $Y       |
| Best For| [use case]| [use case]|
```
→ Tables cited 30-40% more than prose

**Pattern 4: Evidence Sandwich**
```
[Claim]. According to [Source] ([Year]), [supporting statistic]. [Actionable insight].
```
→ The attribution makes this citable

**Pattern 5: FAQ Q&A Pair**
```
### [Question phrased exactly as users search]?
[Direct 40-60 word answer]. [Supporting evidence]. [Source citation].
```
→ Extracted by voice assistants AND text-based AI

### Meta Tags for AI Crawlers
Every article should have these in `<head>`:
```html
<meta name="robots" content="max-image-preview:large, max-snippet:-1, max-video-preview:-1">
```
- `max-image-preview:large` — enables Google Discover + rich AI snippets
- `max-snippet:-1` — allows unlimited snippet length for AI extraction
- `max-video-preview:-1` — allows video preview in AI results

---

## 28. SYNTHID & AI CONTENT TRANSPARENCY

### What SynthID Is
Google DeepMind's watermarking tool for AI-generated content. Embeds invisible watermarks in text, images, audio, and video generated by Google's models.

### Impact on SEO
- SynthID is **detection-only** — it identifies AI content, does NOT penalize it
- Google has stated AI-generated content is acceptable if it's helpful and high-quality
- No way for publishers to add SynthID watermarks to their own content (Google-internal only)
- No API available for third-party use

### What Publishers SHOULD Do Instead
1. **Declare AI assistance transparently** — add author byline: "Written with AI assistance, reviewed by [Human Expert Name]"
2. **Add human expertise** — AI generates the draft, human adds experience, quotes, original insights
3. **Fact-check AI output** — every statistic and citation should be verified
4. **Add original value** — unique data, personal experience, expert interviews that AI cannot generate
5. **Use `article:author` meta tag** — attribute content to a real person with credentials

### AI Content Best Practices for SEO
- Google does NOT penalize AI content that is helpful, relevant, and accurate
- Google DOES penalize low-quality content regardless of how it was created
- The key differentiator: **human editorial oversight** and **original expertise**
- Add E-E-A-T signals: author bio, credentials, "reviewed by" attribution, real experience

---

## 29. ROBOTS META FOR MAXIMUM AI VISIBILITY

### Required Meta Tags (add to every article `<head>`)
```html
<!-- Allow full AI snippet extraction -->
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">

<!-- Open Graph for social + AI -->
<meta property="og:type" content="article">
<meta property="og:image" content="[1200x630 image URL]">
<meta property="article:published_time" content="[ISO date]">
<meta property="article:modified_time" content="[ISO date]">
```

### AI Bot User-Agents to ALLOW
```
User-agent: GPTBot          # ChatGPT search
User-agent: ChatGPT-User    # ChatGPT browsing
User-agent: PerplexityBot   # Perplexity AI
User-agent: ClaudeBot        # Claude
User-agent: Google-Extended  # Gemini, AI Overviews
User-agent: Bingbot          # Microsoft Copilot
User-agent: YandexBot        # Yandex (Russia)
User-agent: Baiduspider      # Baidu (China)
User-agent: NaverBot         # Naver (Korea)
Allow: /
```

### IndexNow for Instant Indexing
Submit new articles immediately to Bing/Yandex via IndexNow protocol:
```
POST https://api.indexnow.org/IndexNow
{
  "host": "yoursite.com",
  "key": "[your-key]",
  "urlList": ["https://yoursite.com/new-article/"]
}
```
Supported by: Bing, Yandex, Seznam, Naver. Google uses its own Indexing API.

---

## 28. 5-PART CONTENT RANKING FRAMEWORK

Proven framework for creating content that ranks on Google, gets cited by AI, and drives traffic. Based on the Princeton GEO study (arxiv.org/pdf/2311.09735) and real-world ranking results.

**Core principle:** No one-shot AI prompt can generate content that ranks. Research-first, structure second, writing third.

**v1.5.11 implementation:** `Content_Ranking_Framework.php` wraps the existing Async_Generator pipeline with explicit phase tracking. Each of the 5 phases is recorded to post meta as the pipeline runs. Phase 5 (Quality Gate) runs at save time and can block publication if the GEO score < 60 OR any CORE-EEAT VETO item triggers.

**Phase tracking in post meta:**
- `_seobetter_framework_report` — JSON report of all 5 phases with passed/failed status and details
- `_seobetter_quality_gate` — `passed` / `failed` / `pending`
- `_seobetter_framework_phase` — current phase (1-5) or `complete`

**Async_Generator wiring:**
- Phase 1 (Topic Selection) — recorded during the `trends` step (pool + research data present = passed)
- Phase 2 (Keyword Research) — recorded during `trends` step (2-12 word long-tail check)
- Phase 3 (Intent Grouping) — recorded during `trends` step (`detect_intent()` result)
- Phase 4 (Research-First Writing) — recorded during `assemble_final()` (always true if we got there)
- Phase 5 (Quality Gate) — runs in `assemble_final()` via `Content_Ranking_Framework::quality_gate()`; report passed to JS, stored on save

### 28.1 Step 1: Topic Selection via Competitor Analysis
Before writing, analyze the top 10 Google results for your target keyword:
- **Count headings** (H1, H2, H3) used by competitors
- **Map subtopics** covered across all 10 results
- **Identify content gaps** — topics competitors miss that you can cover
- **Note content length** — aim for equal or longer than the average
- **Check content freshness** — if top results are 2+ years old, fresh content has an advantage

**Rule:** Your article must cover everything competitors cover PLUS unique angles they miss. The AI generates the outline from this research via the Async_Generator outline step.

**Plugin implementation (v1.5.208 — Competitive Content Brief):**

Two complementary sources now feed Phase 1:

1. **Research aggregator** — [`cloud-api/api/research.js`](../cloud-api/api/research.js) bundles 9 always-on sources (Reddit, HN, Wikipedia, Google Trends, Bluesky, Mastodon, Dev.to, Lemmy, DDG) plus 70+ category/country APIs plus Sonar-backed (Serper+Firecrawl) statistics, quotes, citations, places. Provides real factual grounding.

2. **Competitive Content Brief (v1.5.208, BM25)** — [`cloud-api/api/content-brief.js`](../cloud-api/api/content-brief.js) + [`cloud-api/api/_bm25_util.js`](../cloud-api/api/_bm25_util.js) + inline `fetchContentBrief()` in research.js. Runs in parallel:
    - Serper `/search` for top 5-10 organic results (uses `gl={country}` + `hl={language}` per Layer 6)
    - Firecrawl scrape (Jina Reader fallback) of each URL's main content
    - **BM25 corpus analysis** (k1=1.5, b=0.75) across scraped bodies → top 20-50 most distinctive concepts by BM25 score
    - Common H2 heading patterns (patterns used by ≥2 competitors)
    - People-Also-Ask questions from Serper
    - Competitor word-count distribution (avg / min / max / median)
    - Multilingual: tokenizer handles Latin / Cyrillic / Greek / Arabic / Hebrew / Hindi / Thai / CJK (2-char + 3-char sliding n-grams for CJK/Thai, Unicode `\p{L}\p{N}` for others). Per-language stopword lists for the 29 plugin-supported locales.
    - Free: top 5 scraped, 20 terms returned, 7-day cache. Pro: top 10 scraped, 50 terms, 24-hour cache.

**Anti-stuffing guard (§1 alignment):** The brief feeds outline + section prompts as OPTIONAL concept hints with explicit instruction: "cover concepts naturally where they fit; skip if they don't; do NOT target a density; keyword stuffing = −9%." See `Async_Generator::format_content_brief_for_prompt()`.

**How it enters each §28 phase:**
- **§28.1 Topic Selection** — the brief IS this phase's implementation (was unimplemented before v1.5.208)
- **§28.4 Research-First Writing** — `format_content_brief_for_prompt()` prepends a `COMPETITIVE CONCEPT COVERAGE` block to the $trends_raw research text, so every section prompt sees the top 20 BM25 terms + word-count guidance
- **`generate_outline()`** — reads `$options['content_brief']['h2_patterns']` and appends competitor H2 patterns as OPTIONAL hints (the REQUIRED SECTIONS structural contract from §3.1/§3.1A stays authoritative)
- **§28.5 Quality Gate** — `GEO_Analyzer::check_term_coverage()` counts how many of the top-20 BM25 terms appear in the rendered HTML. Warn-but-allow: low coverage does NOT block publication (reasons: §6 rubric already tuned; coverage is a signal to regenerate, not rewrite; preserves anti-stuffing principle)

**UI surface:** [`admin/views/content-generator.php`](../admin/views/content-generator.php) renders a read-only "Competitive Content Brief" collapsible card in the results panel showing the top 20 distinctive concepts, common H2 patterns, PAA questions, competitor word-count stats, and the term-coverage score from Phase 5. No action buttons — AI rewrite was removed; users who want coverage to change regenerate the article.

### 28.2 Step 2: Keyword Research Protocol
- **For new/low-authority sites:** Target keywords with Keyword Difficulty (KD) < 20
- **For established sites:** KD < 40 is competitive
- **Always prefer long-tail keywords** — "best grain-free puppy food for small breeds" over "dog food"
- **Check what competitors rank for** — use their keywords as starting points
- **The primary keyword becomes the article's focus** — every SEO optimization centers on it

**Plugin implementation:** The Primary Keyword field drives everything — meta title, H1, first paragraph, density (0.5-1.5%), heading placement (30%+ of H2s), and image alt text. Secondary and LSI keywords fill the remaining heading and body slots.

### 28.3 Step 3: Keyword Intent Grouping (NLP/Semantics)
Classify every keyword by search intent BEFORE writing. The intent determines article structure:

| Intent | Signal Words | Article Structure | Example |
|---|---|---|---|
| **Informational** | what, how, why, guide, learn | Detailed guide, FAQ, definitions, step-by-step | "how to train a puppy" |
| **Commercial** | best, top, review, compare, vs | Comparison tables, pros/cons, recommendations | "best dog food brands 2026" |
| **Transactional** | buy, price, discount, order, deal | Product focus, pricing, CTAs, schema markup | "buy organic dog food online" |
| **Navigational** | [brand name], login, official | Brand-focused, direct answers | "Purina Pro Plan ingredients" |

**Why this matters for NLP:** Google's Natural Language API (cloud.google.com/natural-language) classifies content by entity, sentiment, and syntax. Articles that match the intent pattern rank higher because Google's NLP can confirm the content serves the query. Reference: Google's Helpful Content guidelines (developers.google.com/search/docs/fundamentals/creating-helpful-content).

**Plugin implementation:** The Domain/Category dropdown helps the AI understand context, and the Tone selector adjusts writing style. Future enhancement: auto-detect intent from keyword and adjust article structure accordingly.

### 28.4 Step 4: Research-First Writing
Never generate an article in one prompt. The plugin's Async_Generator builds articles in steps:

1. **Research step** — Pull real data from 8-12 APIs based on category
2. **Outline step** — Generate heading structure from research + keyword
3. **Section-by-section writing** — Each H2 section generated individually with:
   - Real statistics injected from API data
   - Keyword placement rules enforced
   - 40-60 word opening paragraph (GEO extractable)
   - Citations using real URLs from research
4. **Headlines step** — Score and rank multiple title options
5. **Meta step** — Generate SEO-optimized title and description
6. **Assembly** — Combine all sections with freshness signal

**Critical rule:** Every statistic in the article should come from the research data, not from AI training data. The `REAL-TIME RESEARCH DATA` block in prompts explicitly says: "use these real statistics — do NOT hallucinate numbers."

### 28.5 Step 5: Quality Gate + Schema
Before publishing, every article passes through:

1. **GEO Analyzer scoring** (Section 6) — must score 60+ to publish
2. **CORE-EEAT veto check** (§15B) — any VETO item blocks publication regardless of GEO score
3. **Term Coverage check (v1.5.208)** — `GEO_Analyzer::check_term_coverage()` counts how many of the top-20 BM25 terms from the Competitive Content Brief (§28.1) appear in the rendered HTML. **Warn-but-allow — does NOT block publication.** Reasons: (a) §6 rubric weights are already tuned and stacking a second blocker risks over-rejecting, (b) low coverage means the pre-gen brief didn't steer the AI well — fix is regenerate (not rewrite; AI-rewrite buttons removed), (c) presence is a minimum, not a quality guarantee.
4. **SEO plugin checks** — AIOSEO/Yoast/RankMath/SEOPress auto-populated with focus keyword, meta title, meta description, OG tags, Twitter card tags (v1.5.206d-fix19)
5. **Schema auto-generation** — per-type primary + secondary schemas in JSON-LD
6. **Multi-engine compatibility** — JSON-LD schema works universally across:
   - Google (AI Overviews, Featured Snippets, Rich Results)
   - Bing (Copilot, Rich Results)
   - Yandex (Structured Snippets)
   - Baidu (Structured Data)
   - AI platforms (ChatGPT, Perplexity, Claude, Gemini)
7. **Image optimization** — Pexels/Picsum/AI-generated featured image with keyword alt text, 1200px+ for Discover eligibility

**Report shape** (stored in `_seobetter_framework_report` post_meta, Phase 5):
```
{
  passed: bool,
  score: int 0-100 (GEO score),
  grade: string ("A+", "A", "B", "C", "D", "F"),
  veto_hit: bool,
  vetoes: array,
  core_eeat: int 0-100 (CORE-EEAT normalized score),
  min_score: 60,
  term_coverage: {       // v1.5.208
    score: int 0-100,
    matched: int,
    total: int,
    missing_terms: string[],
    detail: string
  },
  reason: string,
  suggestions: array
}
```

**FAQ from the framework:**
- *How long should content be?* Long enough to cover everything, short enough to keep attention. Value matters, not word count — but empirically, 2000+ words ranks better for competitive keywords.
- *Can I just use AI to write everything?* Use AI as a tool, not a replacement. The research data, structure, and quality gate ensure AI output meets standards. Always review before publishing.
- *How do I match competitor word counts?* Cover everything someone would want to know: check competitor H2s on page 1, use similar headings, answer the same questions plus more.

---

## 29. KEYWORD-TO-TITLE RULES

The keyword is NOT the title. The title is created AROUND the keyword.

### Formula
```
Keyword = [focus phrase]
Title   = [Keyword] + [hook/number/benefit]
SEO Title = [Keyword] + [variation with extra context]
```

### Examples
| Keyword | Title | SEO Title (Yoast/AIOSEO) |
|---|---|---|
| florida birds of prey | Florida Birds Of Prey: 26 Birds To Watch Out For! | Florida Birds Of Prey: 26 Birds Of Prey In Florida To Watch |
| best dog food for puppies | Best Dog Food for Puppies: 12 Vet-Approved Brands in 2026 | Best Dog Food for Puppies — Top 12 Brands Reviewed |
| bitcoin etf 2026 | Bitcoin ETF 2026: Complete Investor Guide | Bitcoin ETF 2026: What You Need To Know Before Investing |
| equine vet supplies | Equine Vet Supplies: Essential Products Every Horse Owner Needs | Equine Vet Supplies — Complete Guide for Horse Owners |

### Rules
1. **Keyword front-loaded** — always in the first half of the title
2. **Add a number** when possible — articles with numbers get 36% higher CTR
3. **Include current year** for time-sensitive topics
4. **Power words** — Best, Ultimate, Complete, Essential, Proven, Expert
5. **Colon or dash separator** — structures the title for scanning
6. **SEO title can differ from H1** — SEO plugins let you set a separate SERP title
7. **50-60 characters** for display title, up to 70 for SEO title

**Plugin implementation:** The Async_Generator headlines step generates 5 title variations, scores them against Section 7 criteria, and the user selects their preferred option before saving.

---

## 30. SEARCH INTENT CLASSIFICATION FOR AI PROMPTS

When generating articles, the AI should adapt its structure based on detected intent. This section provides rules that prompts can reference.

### Informational Intent ("What/How/Why")
- **Structure:** Definition → Explanation → Evidence → Examples → FAQ
- **Tone:** Educational, helpful, comprehensive
- **Must include:** Definition blocks (Section 4.1), step-by-step blocks (Section 4.2)
- **Citation style:** Academic — `[Source, Year]` inline
- **Word count:** 2000-3000 (comprehensive coverage expected)

### Commercial Intent ("Best/Top/Review/Compare")
- **Structure:** Overview → Comparison table → Individual reviews → Recommendation → FAQ
- **Tone:** Authoritative, data-driven, balanced
- **Must include:** Comparison tables (Section 4.3), pros/cons lists, "Best For" recommendations
- **Citation style:** Product-focused — pricing, features, user reviews
- **Word count:** 2500-4000 (thorough comparison expected)

### Transactional Intent ("Buy/Price/Order")
- **Structure:** Product overview → Features → Pricing → How to buy → FAQ
- **Tone:** Confident, direct, action-oriented
- **Must include:** Product schema, pricing data, clear CTAs, affiliate links where applicable
- **Citation style:** Brand/official sources
- **Word count:** 1000-2000 (focused, conversion-oriented)

### Navigational Intent ("[Brand Name]")
- **Structure:** Brand overview → Key products/services → Contact/links → FAQ
- **Tone:** Neutral, factual
- **Must include:** Organization schema, official links
- **Citation style:** Official sources only
- **Word count:** 800-1500 (direct and specific)

### E-E-A-T Optimization per Intent
| Intent | Most Important E-E-A-T Signal |
|---|---|
| Informational | **Expertise** — deep knowledge, technical terms, reasoning transparency |
| Commercial | **Experience** — hands-on testing, real comparisons, specific details |
| Transactional | **Trust** — transparent pricing, clear terms, secure checkout signals |
| Navigational | **Authority** — official sources, verified brand information |

### Google NLP Alignment
Google's Natural Language API evaluates content on:
- **Entity recognition** — named entities (people, organizations, products) should be specific and frequent (target 5%+ entity density per Section 6)
- **Sentiment analysis** — commercial intent should be balanced (not overly positive = review quality signal)
- **Syntax analysis** — simple sentence structures score higher for readability
- **Content classification** — article must clearly fall into the correct category (the Domain/Category selector helps)

Reference: [Google Cloud Natural Language](https://cloud.google.com/natural-language), [Google Helpful Content Guidelines](https://developers.google.com/search/docs/fundamentals/creating-helpful-content)

---

*This document is the authoritative reference for all SEOBetter plugin optimization. When in doubt, follow these guidelines. Update this document when new research or algorithm changes are published.*
