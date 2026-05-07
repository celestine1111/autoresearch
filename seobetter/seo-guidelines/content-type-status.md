# SEOBetter Content-Type Status Tracker

> **Purpose:** Durable dashboard tracking the optimization state of every content type the plugin supports.
> Updated at **Phase 6 (Sign-off)** of the per-article-type workflow — see `SEO-GEO-AI-GUIDELINES.md` §3 + the `/seobetter` skill.
>
> **Last updated:** 2026-04-22 (v1.5.203 — tracker introduced)
>
> ---
>
> **How to read this file:**
>
> - **Last version** — BUILD_LOG version that last touched the type's code
> - **Verified** — ISO date Ben confirmed the type works end-to-end (blank = UNTESTED)
> - **Profile** — which §3.1 profile the type uses (DEFAULT or the §3.1A Genre Override)
> - **Layers 1-6** — coverage status per optimization vector
>   - ✅ = fully covered
>   - ⚠️ = partial (see Known Issues)
>   - ❌ = not covered yet (baseline only — still needs research pass)
> - **Known issues** — drift, partial coverage, or bugs tracked for a future pass
>
> ---
>
> ### The 5 layers + 6th international vector
>
> 1. **SEO** — keyword density, meta, headings, URL slug
> 2. **AI SEO** — Princeton §1 boosts (stats / quotes / citations)
> 3. **LLM citations** — Island Test, extractability, FAQ schema
> 4. **Schema** — JSON-LD, Google Rich Results
> 5. **Design** — distinctive CSS / visual differentiation
> 6. **International** — Baidu / Doubao / DeepSeek / Qwen / YandexGPT / GigaChat / HyperCLOVA X / Kanana / Mistral / Japanese LLMs

---

## The 21 content types

| # | Content type | Last version | Verified | Profile | 1 SEO | 2 AI SEO | 3 LLM | 4 Schema | 5 Design | 6 Intl | Known issues |
|---|---|:---:|:---:|---|:---:|:---:|:---:|:---:|:---:|:---:|---|
| 1 | `blog_post` | baseline | — | Default §3.1 | ✅ | ✅ | ✅ | ✅ | ⚠️ (baseline chrome) | ❌ | — |
| 2 | `news_article` | baseline | — | §3.1A Override (inverted pyramid) | ✅ | ⚠️ | ⚠️ | ✅ | ⚠️ (minimal — dateline + timestamp) | ❌ | Prose template still v1.5.11 default — has §3.1A row but no research-backed template |
| 3 | `opinion` | v1.5.196 | — (UNTESTED post-v1.5.196) | §3.1A Override (Hybrid — keeps KT+FAQ+Refs) | ✅ | ✅ | ✅ | ✅ | ✅ (red disclosure bar + dramatic pull quotes) | ❌ | Needs regeneration + Rich Results Test after v1.5.196 section-fix |
| 4 | `how_to` | baseline (+ v1.5.14 step boxes) | — | Default §3.1 | ✅ | ✅ | ✅ | ✅ | ✅ (numbered step circles) | ❌ | — |
| 5 | `listicle` | baseline (+ deep Places work v1.5.23-v1.5.33) | — | Default §3.1 | ✅ | ✅ | ✅ | ✅ | ✅ (oversized item numbers) | ❌ | — |
| 6 | `review` | v1.5.136 (smart itemReviewed) | — | Default §3.1 | ✅ | ✅ | ✅ | ✅ (rich per-type itemReviewed @types) | ✅ (score badge + pros/cons columns) | ❌ | Prose template still default; schema heavily iterated |
| 7 | `comparison` | baseline | — | Default §3.1 | ✅ | ✅ | ✅ | ✅ | ✅ (VS badge + two-column grid) | ❌ | — |
| 8 | `buying_guide` | v1.5.216.62.108 | **2026-05-08** | Default §3.1 | ✅ | ✅ | ✅ | ✅ | ✅ (Our Pick pills + product cards) | ❌ | Signed off across 2 GB keywords (gardening tools post 794, BBQ grills post 802); ItemList numbered-only, image dedup, UK locale, validator clean |
| 9 | `recipe` | v1.5.172 (extensively iterated) | — | §3.1A Override (Recipe card structure) | ✅ | ✅ | ✅ | ✅ (full Recipe + multi-recipe + HowToStep url per step) | ✅ (yellow recipe card + ingredient/step boxes) | ⚠️ (40-country cuisine + recipe domains) | Deep prior work; re-verify after v1.5.199 table-enforcer gate |
| 10 | `faq_page` | v1.5.171 (AI-citation FAQ) | — | Default §3.1 | ✅ | ✅ | ✅ | ✅ | ✅ (accordion Q&A) | ❌ | Prose tightened for AI citation in v1.5.171 |
| 11 | `news_article` | baseline | — | (duplicate of #2 — see above) | — | — | — | — | — | — | See #2 |
| 12 | `tech_article` | baseline | — | Default §3.1 | ✅ | ✅ | ✅ | ✅ (TechArticle @type) | ✅ (dark code blocks + traffic-light + language label) | ❌ | — |
| 13 | `white_paper` | v1.5.177 (Exec Summary + section numbering) | — | Default §3.1 | ✅ | ✅ | ✅ | ✅ | ✅ (Executive Summary box + section numbering) | ❌ | — |
| 14 | `scholarly_article` | baseline | — | Default §3.1 | ✅ | ✅ | ✅ | ✅ (ScholarlyArticle @type) | ✅ (abstract box + citation format) | ❌ | — |
| 15 | `live_blog` | baseline | — | §3.1A Override (timestamped) | ✅ | ⚠️ | ⚠️ | ✅ (LiveBlogPosting @type) | ✅ (timestamped entries + key moments) | ❌ | Prose template still default |
| 16 | `press_release` | v1.5.199 | — (UNTESTED post-v1.5.199) | §3.1A Override (Dateline + inverted pyramid) | ✅ | ✅ | ✅ | ✅ (NewsArticle + Press Release articleSection + citation + Organization sameAs) | ⚠️ (uses default news chrome — differentiation gap) | ❌ | Style differentiation from `news_article` is weak — re-test after v1.5.199 + consider v1.5.21X design pass |
| 17 | `personal_essay` | v1.5.201 | — (UNTESTED) | §3.1A Override (Literary narrative) | ✅ | ✅ | ✅ | ✅ (BlogPosting + Personal Essay articleSection + citation + backstory + speakable) | ✅ (narrow serif column + drop cap + italic centered pull quotes) | ❌ | Most distinctive per-type CSS in the plugin; regen + Rich Results Test pending |
| 18 | `glossary_definition` | baseline | — | Default §3.1 | ✅ | ✅ | ✅ | ✅ (DefinedTerm @type) | ✅ (definition highlight + See Also) | ❌ | — |
| 19 | `sponsored` | baseline | — | Default §3.1 | ✅ | ✅ | ✅ | ⚠️ (Article fallback — AdvertiserContent not recognised by Google) | ✅ (disclosure bar + sponsor area) | ❌ | — |
| 20 | `case_study` | baseline | — | Default §3.1 | ✅ | ✅ | ✅ | ✅ | ✅ (large stat numbers + Challenge/Solution/Results) | ❌ | — |
| 21 | `interview` | v1.5.166 (Q&A styling) | — | §3.1A Override (Q&A is the content) | ✅ | ✅ | ✅ | ✅ (ProfilePage secondary) | ✅ (Q/A cards green/gray) | ❌ | Prose template still default; styling done |
| 22 | `pillar_guide` | baseline | — | Default §3.1 | ✅ | ✅ | ✅ | ✅ | ✅ (chapter numbers + TOC progress) | ❌ | — |

---

## Aggregate status

- **Verified (✅):** 0 of 21 — per-type testing has not yet started under the formal 6-phase workflow
- **Research-backed templates shipped (awaiting verification):** opinion, press_release, personal_essay (3 of 21)
- **Distinctive Layer 5 CSS:** 19 of 21 (gaps: `press_release` falls back to news chrome, `blog_post` uses baseline by design)
- **International (Layer 6) coverage:** 0 of 21 — deferred to v1.5.205 `international-optimization.md` research + v1.5.206 critical international code

## Update protocol

When a content type is verified (Phase 6 Sign-off):
1. Flip the `Verified` column from `—` to today's ISO date (e.g. `2026-04-22`)
2. Update `Last version` to whichever BUILD_LOG version last touched that type
3. Update Layer status columns if any changed (e.g., International ❌ → ✅ when v1.5.206 ships)
4. Clear or update the Known Issues column
5. Commit in the same commit as the BUILD_LOG `UNTESTED` → `✅ Verified` flip

When a bug is found during Phase 5 (Test):
1. Move the relevant entry back to UNTESTED
2. Add the bug description to Known Issues
3. Loop back to the workflow Phase 3 (Propose) with the bug as new input
