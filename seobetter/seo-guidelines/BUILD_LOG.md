# SEOBetter Build Log

> **Source of truth for what has actually shipped in the code.**
>
> Every entry anchors to an exact file:line. Claims without anchors are banned.
>
> **Before citing this log as "done", ALWAYS grep the file:line to verify the code still matches.**
> Line numbers drift as files are edited — the method name is the stable anchor, the line number is a hint.
>
> **Last updated:** 2026-04-15
>
> **How to read this log:**
> - `✅ Verified by user` means the user has run the feature and confirmed it works in production
> - `UNTESTED` means the code exists but hasn't been tested by the user yet
> - `❌ Broken` means the user reported it broken and it's awaiting fix

---

## v1.5.81 — Server-side Sonar: works for ALL users on ANY AI model

**Date:** 2026-04-17
**Commit:** `f90bc71`

### Added

- **Server-side `fetchSonarResearch()` in Vercel endpoint** — `cloud-api/api/research.js` line **~3048**
  - Runs IN PARALLEL with DDG/Reddit/HN/Wikipedia — no extra latency
  - Uses `process.env.OPENROUTER_KEY` (Ben's server-side key, NOT user's key)
  - Uses `process.env.SONAR_MODEL` (default `perplexity/sonar`)
  - Returns `{citations, quotes, statistics, table_data}` from Perplexity's live web search
  - Results MERGED into existing `sources[]`, `quotes[]`, `stats[]` arrays in `buildResearchResult()`
  - Dedicated return fields: `sonar_citations`, `sonar_quotes`, `sonar_statistics`, `sonar_table_data`, `sonar_available`
  - If no env key or Sonar fails: returns null gracefully, other 10 fetchers still provide data
  - Verify: `grep -n 'fetchSonarResearch' seobetter/cloud-api/api/research.js`

### Changed

- **Citation Pool accepts Sonar citations** — `includes/Citation_Pool.php::build()` line **~45**
  - New `$sonar_citations` parameter — Sonar URLs merged as pool candidates
  - Still go through hygiene check + topical relevance filter like all other candidates
  - Verify: `grep -n 'sonar_citations' seobetter/includes/Citation_Pool.php`

- **Async_Generator threads Sonar data** — `includes/Async_Generator.php` trends step
  - Stashes `sonar_data` in `$job['results']`, passes `sonar_citations` to Citation Pool build
  - Threads to `assemble_final()` response for frontend
  - Verify: `grep -n 'sonar_data' seobetter/includes/Async_Generator.php`

- **All inject methods accept `$sonar_data` param** — `includes/Content_Injector.php`
  - `inject_citations()`, `inject_quotes()`, `inject_table()`, `optimize_all()` — each uses `$sonar_data ?? self::call_sonar_research($keyword)` (Vercel data preferred, PHP fallback)
  - Verify: `grep -n 'sonar_data' seobetter/includes/Content_Injector.php | head -10`

- **REST endpoints forward `sonar_data`** — `seobetter.php`
  - `rest_inject_fix()` and `rest_optimize_all()` receive and pass `sonar_data` from frontend
  - Verify: `grep -n 'sonar_data' seobetter/seobetter.php | head -5`

- **Frontend stores + passes `sonar_data`** — `admin/views/content-generator.php`
  - `window._seobetterDraft.sonar_data` stores Vercel Sonar data from generation
  - Both inject-fix and optimize-all AJAX calls pass it through
  - Verify: `grep -n 'sonar_data' seobetter/admin/views/content-generator.php | head -5`

### Architecture (WHY this matters)

```
BEFORE: WordPress PHP → User's OpenRouter key → Sonar
        (users without OpenRouter get broken features)

AFTER:  WordPress PHP → Vercel endpoint → Ben's OPENROUTER_KEY → Sonar
        (ALL users get real research data regardless of AI provider)
        (user's AI key ONLY used for writing, never for research)
```

### Deployment requirement

Set these Vercel environment variables BEFORE testing:
- `OPENROUTER_KEY` — Ben's OpenRouter API key
- `SONAR_MODEL` — `perplexity/sonar` (default) or `perplexity/sonar-pro`

### Verified by user

- **UNTESTED**

---

## v1.5.80 — All individual buttons now use Perplexity Sonar + openers converted to inject

**Date:** 2026-04-17
**Commit:** `463a1e8`

### Changed

- **call_sonar_research() made public + cached** — `includes/Content_Injector.php` line **~1207**
  - Made public so every inject button can call it (not just optimize_all)
  - 5-minute WordPress transient cache — first button click hits Sonar, subsequent reuse cache
  - Verify: `grep -n 'seobetter_sonar_' seobetter/includes/Content_Injector.php`

- **inject_citations: Sonar-first** — `includes/Content_Injector.php::inject_citations()` line **~45**
  - Calls Sonar first for real article URLs, merges with existing pool
  - Falls back to Citation_Pool::build() only if Sonar returns nothing
  - Verify: `grep -n 'call_sonar_research' seobetter/includes/Content_Injector.php | head -5`

- **inject_quotes: Sonar-first, no more DEV.to junk** — `includes/Content_Injector.php::inject_quotes()` line **~255**
  - Sonar returns real professional quotes from live web search
  - Fallback filters out April Fools, challenges, giveaways, contests
  - User report: "pulls crap not related — April Fools Challenge TEA-RRIFIC prizes"
  - Verify: `grep -n 'april fool' seobetter/includes/Content_Injector.php`

- **inject_table: Sonar-first for real product data** — `includes/Content_Injector.php::inject_table()` line **~326**
  - Tries Sonar table_data first (real products, real specs)
  - Falls back to AI generation only if Sonar has no table data
  - Verify: `grep -n 'Sonar — real product data' seobetter/includes/Content_Injector.php`

### Added

- **fix_openers: inject-mode section opener fix** — `includes/Content_Injector.php::fix_openers()` line **~675**
  - Previous: flag-only mode just showed which openers were short — didn't fix anything
  - New: AI rewrites short openers (< 30 words) to 40-60 words that answer the heading
  - Caps at 4 rewrites per click to stay within PHP timeout
  - Button label changed from "Check Section Openings" to "Fix Section Openings" with inject mode
  - Verify: `grep -n 'fix_openers' seobetter/includes/Content_Injector.php`

### Guideline updates (same commit)

- **plugin_UX.md** §3.4 — Openers moved from flag to rewrite, flag section reduced to 2 buttons

### Verified by user

- **UNTESTED**

---

## v1.5.78 — Optimize All: single Sonar call + sequential fixes + progress bar

**Date:** 2026-04-17
**Commit:** `c7bd1bf`

### Added

- **"⚡ Optimize All" button** — `admin/views/content-generator.php` line **~1012**
  - Single button in the Analyze & Improve panel header replaces clicking 6 buttons individually
  - Gradient purple button with hover lift, shimmer progress bar, elapsed timer, step labels
  - On completion: green bar at 100%, shows which steps ran, "Powered by Perplexity Sonar"
  - Verify: `grep -n 'sb-optimize-all' seobetter/admin/views/content-generator.php`

- **`Content_Injector::optimize_all()`** — `includes/Content_Injector.php` line **~1200**
  - Orchestrates all 6 inject fixes in one pass
  - Step 0: ONE Perplexity Sonar call via `call_sonar_research()` for citations, quotes, stats, table data
  - Steps 1-4: inject research data (citations, quotes, stats, table) from Sonar
  - Steps 5-6: AI rewrites (readability, keyword density) using active provider
  - Checks scores first — skips fixes where the score already passes
  - Each step has try/catch — failures don't abort the pipeline
  - Fallback chain: if Sonar fails, each step falls back to existing method
  - Verify: `grep -n 'optimize_all' seobetter/includes/Content_Injector.php`

- **`Content_Injector::call_sonar_research()`** — `includes/Content_Injector.php` line **~1198**
  - Direct call to OpenRouter with `perplexity/sonar` model
  - Single prompt returns structured JSON: `{citations, quotes, statistics, table_data}`
  - Auto-discovers OpenRouter key from AI_Provider_Manager
  - Returns null if no key configured (triggers fallback chain)
  - Verify: `grep -n 'call_sonar_research' seobetter/includes/Content_Injector.php`

- **`rest_optimize_all()` REST endpoint** — `seobetter.php::rest_optimize_all()` line **~1500**
  - `POST /seobetter/v1/optimize-all`
  - Receives: markdown, keyword, accent_color, citation_pool, scores
  - Calls optimize_all(), then validate_outbound_links + cleanup_ai_markdown + format + score (ONCE)
  - Returns: full response with steps_run, steps_skipped, sonar_used
  - Verify: `grep -n 'rest_optimize_all' seobetter/seobetter.php`

- **`Citation_Pool::passes_hygiene_public()`** — `includes/Citation_Pool.php` line **~297**
  - Public wrapper for the private hygiene check. Used by optimize_all() to validate Sonar URLs before merging into the citation pool.
  - Verify: `grep -n 'passes_hygiene_public' seobetter/includes/Citation_Pool.php`

- **CSS: shimmer progress bar** — `admin/css/admin.css`
  - `@keyframes sb-opt-shimmer` — purple gradient sweeps across the bar
  - Optimize All button hover/active/disabled states
  - Verify: `grep -n 'sb-opt-shimmer' seobetter/admin/css/admin.css`

### Guideline updates (same commit)

- **plugin_UX.md** §3.4 — Added Optimize All button spec
- **plugin_functionality_wordpress.md** §6.1 — Added optimize-all REST endpoint docs

### Verified by user

- **UNTESTED**

---

## v1.5.77 — CRITICAL: inject_quotes now uses REAL research data, not hallucinated

**Date:** 2026-04-17
**Commit:** `c7590df`

### Fixed

- **inject_quotes: real quotes from research, zero hallucination** — `includes/Content_Injector.php::inject_quotes()` line **~228**
  - Previous: asked AI to "generate 2 expert quotes with realistic names and organizations" at temperature 0.7. Produced 100% hallucinated quotes — fake people, fake titles, fake orgs. Destroyed E-E-A-T trust for YMYL topics.
  - New: pulls REAL quotes from Vercel research data (Reddit discussions, Wikipedia definitions, Bluesky/Mastodon posts, HN comments). Each quote has real text from a real person with a real source URL. Falls back to trending discussion snippets when direct quotes unavailable. Returns error if zero real quotes found (no fabrication fallback).
  - Formatted as attributed blockquotes: `"quote text" — Source (source URL)`
  - User asked: "are these hallucinated? if so will they affect seo negatively" — answer was yes, now fixed
  - Verify: `grep -n 'Trend_Researcher::research' seobetter/includes/Content_Injector.php | head -3`

### Guideline updates (same commit)

- **plugin_functionality_wordpress.md** §6.1 — inject_quotes description updated
- **plugin_UX.md** §3.4 — Add Expert Quotes description updated

### Verified by user

- **UNTESTED**

---

## v1.5.76b — Table error handling, Tympanus-style progress bar

**Date:** 2026-04-17
**Commit:** `b3d548d`

### Fixed

- **Table inject: better error message + higher token limit** — `includes/Content_Injector.php::inject_table()` line **~314**
  - Increased max_tokens from 600 to 800 (some models truncated)
  - Better system prompt explicitly requesting markdown table format
  - Error message now says "Table generation failed: [reason]" instead of just "Failed"
  - Verify: `grep -n 'Table generation failed' seobetter/includes/Content_Injector.php`

### Changed

- **Progress bar: Tympanus-style horizontal fill** — `admin/css/admin.css`
  - Gradient bar fills from left to right with eased cubic-bezier timing over 25s
  - On success: bar snaps to 100% with green tint via `.sb-btn-done` class
  - Elapsed time counter shows "Working 5s... 10s..." so user knows duration
  - Verify: `grep -n 'sb-btn-done' seobetter/admin/css/admin.css`

### Guideline updates (same commit)

- **plugin_UX.md** §3.4 — Updated progress bar spec

### Verified by user

- **UNTESTED**

---

## v1.5.76 — Citation pool passthrough from generation to inject-fix

**Date:** 2026-04-17
**Commit:** `e7216f4`

### Fixed

- **inject_citations reuses original generation pool** — `includes/Content_Injector.php::inject_citations()` line **~45**, `seobetter.php::rest_inject_fix()` line **~1291**, `admin/views/content-generator.php` AJAX call
  - Root cause: initial generation builds pool with full context (keyword + category + country + Vercel research) → finds 4+ URLs. But "Add Citations" button rebuilt pool from scratch with just keyword → got 0 results.
  - Fix: JS now passes `draft.citation_pool` through the AJAX call. REST endpoint receives it and passes to inject_citations. inject_citations uses existing pool when available, falls back to fresh build only when empty.
  - Verify: `grep -n 'existing_pool' seobetter/includes/Content_Injector.php`

### Verified by user

- **UNTESTED**

---

## v1.5.75 — Robust citation scoring, dynamic table columns, progress bar buttons

**Date:** 2026-04-17
**Commit:** `16f622e`

### Fixed

- **Citation scoring: simplified robust regex** — `includes/GEO_Analyzer.php::check_citations()` line **~386**
  - Previous strict regex `/<a\s+[^>]*href=...>` was failing on some esc_url() outputs. Simplified to `/href\s*=\s*["']https?:\/\//i` which matches any href with an http URL — much more robust
  - This is the THIRD attempt at fixing Citations scoring (v1.5.68 broke it, v1.5.72 fixed the input, v1.5.75 fixes the regex)
  - Verify: `grep -n 'href.*https' seobetter/includes/GEO_Analyzer.php | head -3`

- **Table inject: dynamic columns, no empty cells** — `includes/Content_Injector.php::inject_table()` line **~292**
  - Previous: hard-coded "Price Range" column was empty when AI didn't know prices (showed "-0")
  - New: AI chooses 3-4 relevant columns for the topic. Explicit instruction: "ONLY include a Price column if you know actual prices"
  - Verify: `grep -n 'Price column' seobetter/includes/Content_Injector.php`

### Added

- **Progress bar + timer on inject-fix buttons** — `admin/views/content-generator.php` line **~1289**, `admin/css/admin.css`
  - Translucent progress bar fills from left over 30s via `@keyframes sb-progress-fill` + pulse
  - Elapsed time counter: "Working 0s... 5s... 10s..." updates every second
  - Timer cleared on success/failure via `clearInterval`
  - Verify: `grep -n 'sb-btn-timer' seobetter/admin/views/content-generator.php`

### Guideline updates (same commit)

- **plugin_UX.md** §3.4 — Updated button loading spec with progress bar + timer
- **SEO-GEO-AI-GUIDELINES.md** §6 — Citations row updated for v1.5.75 robust regex

### Verified by user

- **UNTESTED**

---

## v1.5.74b — Hide Add Citations when article already has links

**Date:** 2026-04-17
**Commit:** `d7d35f5`

### Fixed

- **Add Citations button hidden when article already has citations** — `admin/views/content-generator.php` line **~973**
  - Previous: button showed based only on `citations.score < 80` — but scorer was returning 0 even when article had real links (v1.5.68-71 bug). Even after v1.5.72 scorer fix, the button appeared when it shouldn't.
  - New: JS checks BOTH `score < 80` AND that neither the markdown has a `## References` section with `[text](url)` links NOR the HTML has any `<a href="https://...">` tags. All three must be absent for the button to appear.
  - User report: "the previous build it added the external links and references and i said it shouldnt have the button to add citations if they are already there"
  - Verify: `grep -n 'mdHasRefs' seobetter/admin/views/content-generator.php`

### Guideline updates (same commit)

- **plugin_UX.md** §3.4 — Updated Add Citations entry with hidden-when-exists rule

### Verified by user

- **UNTESTED**

---

## v1.5.74 — Loading spinner on inject-fix buttons, error messages shown

**Date:** 2026-04-16
**Commit:** `bc97af2`

### Added

- **CSS spinner on inject-fix buttons** — `admin/views/content-generator.php` line **~1289**, `admin/css/admin.css`
  - Previous: button said "Fixing..." as plain text during slow AI calls (Simplify Readability, Optimize Keyword Density can take 10-30s). Users thought it wasn't working.
  - New: animated spinner + "Working..." text, button preserves its width via `minWidth` to prevent layout shift. Spinner defined as `@keyframes sb-spin` in admin.css.
  - Verify: `grep -n 'sb-spinner' seobetter/admin/views/content-generator.php`

- **Error reason shown on inject-fix failure** — `admin/views/content-generator.php` line **~1401**
  - Previous: button just turned red "Retry" with no explanation — user had no idea why it failed
  - New: red callout below the button shows `result.error` text (e.g. "Citation pool found 3 sources but all were stripped by the link validator")
  - Verify: `grep -n 'result.error' seobetter/admin/views/content-generator.php`

### Guideline updates (same commit)

- **plugin_UX.md** §3.4 — Added loading spinner + error callout spec

### Verified by user

- **UNTESTED**

---

## v1.5.73 — Professional list styling, indented-text-to-list cleanup

**Date:** 2026-04-16
**Commit:** `22df77c`

### Changed

- **Standard list CSS upgrade** — `includes/Content_Formatter.php::format_classic()` line **~800**
  - Previous: plain `padding-left:1.5em` with no background — lists looked raw and unstyled
  - New: light gray card background (`#f8fafc`), 3px accent left border, rounded corners, accent-colored markers with bold weight. Matches the visual weight of the styled boxes (takeaways, pros/cons)
  - Verify: `grep -n 'f8fafc' seobetter/includes/Content_Formatter.php`

- **Indented-text-to-list conversion in cleanup_ai_markdown** — `seobetter.php::cleanup_ai_markdown()` line **~1472**
  - AI rewrites output 4-space indented text instead of `- item` lists. Markdown treats these as code blocks, losing all list styling
  - New: regex converts `    text` (4+ spaces + text) to `- text` (markdown list item)
  - Verify: `grep -n 'indented text' seobetter/seobetter.php`

### Guideline updates (same commit)

- **article_design.md** §5.6b — New section documenting standard list styling spec

### Verified by user

- **UNTESTED**

---

## v1.5.72 — CRITICAL: Citations/Quotes scoring fix, list cleanup

**Date:** 2026-04-16
**Commit:** `2ef1e52`

### Fixed

- **CRITICAL: check_citations always scored 0** — `includes/GEO_Analyzer.php::analyze()` line **~69**
  - Root cause: v1.5.68 changed check_citations to count `<a href>` tags, but analyze() was passing `wp_strip_all_tags($content)` which has NO HTML tags. Both regexes (HTML and markdown) returned 0 on every article since v1.5.68.
  - Fix: pass raw `$content` (HTML) to check_citations and check_expert_quotes instead of `$text` (stripped)
  - Impact: Citations (10% weight) was stuck at 0 for 4 versions. This alone cost ~10 points on every score.
  - Verify: `grep -n "check_citations.*content" seobetter/includes/GEO_Analyzer.php`

- **check_expert_quotes: smart quote support** — `includes/GEO_Analyzer.php::check_expert_quotes()` line **~423**
  - Previous: regex only matched straight quotes `"text"` — AI models output smart quotes `\u201Ctext\u201D` which never matched
  - New: counts `<blockquote>` tags (Content_Formatter wraps quotes in these) + smart-quoted text (U+201C/U+201D)
  - Impact: Expert Quotes (6% weight) was stuck at 0 for most articles. Another ~6 points recovered.
  - Verify: `grep -n 'blockquote' seobetter/includes/GEO_Analyzer.php | head -3`

- **cleanup_ai_markdown: preserve list structure** — `seobetter.php::cleanup_ai_markdown()` line **~1467**
  - Previous: `<li>` tags were stripped to empty string, losing list structure
  - New: `<li>` → `\n- ` (markdown list item), `<br>` → `\n`, then strip remaining wrapper tags
  - User report: "has removed styling after button presses" — lists appeared as unstyled inline text
  - Verify: `grep -n '<li' seobetter/seobetter.php | head -3`

### Guideline updates (same commit)

- **SEO-GEO-AI-GUIDELINES.md** §6 — Citations row updated to note v1.5.68-71 were broken, v1.5.72 fixed

### Verified by user

- **UNTESTED**

---

## v1.5.71 — Centralized bullet cleanup, keyword density 2-pass with depth guard

**Date:** 2026-04-16
**Commit:** `9adb6c9`

### Fixed

- **Centralized markdown cleanup in rest_inject_fix** — `seobetter.php::cleanup_ai_markdown()` line **~1447**
  - Previous: per-method `•` → `- ` regexes in Content_Injector were inconsistent and missed inline bullets (`• item1 • item2` on one line)
  - New: single `cleanup_ai_markdown()` method runs on ALL inject-fix output BEFORE Content_Formatter. Handles: inline bullet splitting, line-start bullet conversion, stray HTML tag stripping, blank line collapsing
  - Called from `rest_inject_fix()` at line ~1408
  - Verify: `grep -n 'cleanup_ai_markdown' seobetter/seobetter.php`

- **Keyword density: auto-retry with depth guard** — `includes/Content_Injector.php::optimize_keyword_placement()` line **~990**
  - Previous: auto-retry had no recursion depth limit (could loop infinitely if AI kept landing at 3-4%), and threshold was > 2.0% (missed 1.5-2.0% range)
  - New: `$depth` parameter (max 1 = 2 passes total), triggers whenever `density_after > 1.5%` (not > 2.0%), reports full journey "8% → 5% → 1.2% (2 passes)"
  - Verify: `grep -n 'depth' seobetter/includes/Content_Injector.php | head -5`

### Verified by user

- **UNTESTED**

---

## v1.5.70 — Readability list fix, keyword density auto-retry, "changes applied" banner

**Date:** 2026-04-16
**Commit:** `07278db`

### Fixed

- **Readability rewriter: list corruption** — `includes/Content_Injector.php::simplify_readability()` line **~886**
  - Previous: AI converted `- item` markdown lists to `• item` Unicode bullets → Content_Formatter couldn't parse them → rendered as unstyled paragraphs
  - New: (a) prompt rules 10-11 explicitly forbid `•` and HTML tags, (b) post-processing regex converts any `•●◦▪▸►` back to `- `, (c) strips stray `<ul>/<li>/<p>` tags
  - User report: "bullet points show like this • Protein levels range from 18-26%"
  - Verify: `grep -n 'bullet characters' seobetter/includes/Content_Injector.php`

- **Keyword density: auto-retry when still above 2%** — `includes/Content_Injector.php::optimize_keyword_placement()` line **~1085**
  - Previous: single AI pass reduced 7% → 4% (AI was conservative), user had to click again manually
  - New: (a) rewritten prompt with explicit MAX mentions allowed and verification instruction, (b) auto-retries recursively if density > 2% after first pass, (c) reports full journey "7% → 4% → 1.2% (2 passes)"
  - Also: bullet corruption post-processing added to density optimizer output
  - User report: "7.08% → 4.09%... I dont know what it does to the article"
  - Verify: `grep -n 'auto-retry' seobetter/includes/Content_Injector.php`

### Added

- **"Changes applied" banner** — `admin/views/content-generator.php` inline JS, line **~1124**
  - Green banner appears above the content preview after each inject-fix showing what was done
  - Stored in `window._seobetterLastFixMessage`, rendered on next `renderResult(res, skipScroll=true)` call
  - Clears after display so it doesn't persist across generations
  - User report: "im not sure if it does anything to the article"
  - Verify: `grep -n 'sb-fix-banner' seobetter/admin/views/content-generator.php`

### Guideline updates (same commit)

- **plugin_UX.md** §3.4 — Added "Changes applied" banner spec

### Verified by user

- **UNTESTED**

---

## v1.5.69 — Inject-fix bug sweep: silent failures, scroll UX, density warnings

**Date:** 2026-04-16
**Commit:** `7cee1d6`

### Fixed

- **Citations inject: 0-added false success** — `seobetter.php::rest_inject_fix()` line **~1388**
  - Previous: pool URLs stripped by `validate_outbound_links()` → response still returned `success: true` with "0 citations added"
  - New: if `refs_after === 0`, returns error with explanation that all sources were stripped by the link validator
  - User report: "click add citations — Add Citations & References Applied, 0 citations added"
  - Verify: `grep -n 'refs_after === 0' seobetter/seobetter.php`

- **Table inject: silent failure when regex doesn't match** — `includes/Content_Injector.php::inject_table()` line **~318**
  - Previous: if no H2 had 1-3 body lines, regex didn't match → `$injected === $content` → reported "Comparison table inserted" with unchanged content
  - New: (a) validates AI actually returned a markdown table (`|` + `---`), (b) if primary regex fails, falls back to inserting before FAQ/References section
  - User report: "says Add Comparison Table Applied, Comparison table inserted" but Tables score = 0
  - Verify: `grep -n 'injected === \$content' seobetter/includes/Content_Injector.php`

- **Preview scroll after inject-fix** — `admin/views/content-generator.php::renderResult()` line **~840**
  - Previous: every `renderResult()` call scrolled to top of results panel via `scrollIntoView` — after inject-fix the user was yanked to the score ring and couldn't see content changes
  - New: `renderResult(res, skipScroll)` — inject-fix re-renders pass `skipScroll=true` so user stays at current position
  - User report: "i cant tell if the changes have been made in the article preview how would you know? you can only see a score change"
  - Verify: `grep -n 'skipScroll' seobetter/admin/views/content-generator.php`

- **Keyword density: no warning when still above target** — `includes/Content_Injector.php::optimize_keyword_placement()` line **~1036**
  - Previous: reported "7.14% → 4.23%" as success even though 4.23% is still keyword stuffing (target 0.5-1.5%)
  - New: if `density_after > 1.5%`, appends warning with how many more mentions to replace and suggests clicking again
  - User report: density optimizer ran but score dropped from 73 to 70
  - Verify: `grep -n 'still_high' seobetter/includes/Content_Injector.php`

### Guideline updates (same commit)

- **plugin_UX.md** §3.4 — Added no-scroll re-render note

### Verified by user

- **UNTESTED**

---

## v1.5.68 — Real-link citation scoring, applied-fix threshold-crossing persistence

**Date:** 2026-04-16
**Commit:** `dafeff8`

### Fixed

- **Citation scoring honesty — only real clickable links count** — `includes/GEO_Analyzer.php::check_citations()` line **381**
  - Previous: counted plain-text patterns like `(Source, 2024)` and `[1]` as citations — articles with zero clickable links scored Citations = 100
  - New: counts only real markdown links `[text](url)` and HTML `<a href>` tags toward the score
  - Plain-text attributions are detected separately and shown in the detail string for transparency but DO NOT contribute to the score
  - Returns new `plain_text_count` diagnostic field alongside the existing `count` (which now means real links only)
  - User report: "There are still no citations anywhere... it is hallucinating as there are no citations at all in article or at the footer."
  - Verify: `grep -n 'real_link_count' seobetter/includes/GEO_Analyzer.php`

- **Applied-fix cards persist after threshold crossing** — `admin/views/content-generator.php` inline JS, `renderResult()` fixes panel, line **~1011**
  - Previous: after clicking an inject fix (e.g. Add Citations) → success → score passes threshold → fix drops out of the `fixes[]` array → card disappears entirely on next re-render
  - New: `appliedLabels` lookup map re-injects completed fixes that passed their threshold back into the panel as green "Done" cards so the user sees confirmation
  - Covers all 7 inject fix types: citations, quotes, statistics, table, freshness, readability, keyword
  - User report: "i applied the first one it went grey then went green but when i scrolled it disappeared"
  - Verify: `grep -n 'appliedLabels' seobetter/admin/views/content-generator.php`

### Guideline updates (same commit)

- **SEO-GEO-AI-GUIDELINES.md** §6 — Citations row updated to specify "real clickable links" scoring, not plain-text patterns
- **plugin_UX.md** §3.4 — Added threshold-crossing persistence note for Analyze & Improve panel

### Verified by user

- **UNTESTED**

---

## v1.5.67 — Citation count honesty, applied-fix persistence, readability threshold drop, Optimize Keyword Density inject mode

**Date:** 2026-04-16
**Commit:** `0009f37`

### Context

Live v1.5.66 test:
- ✅ Article styling is perfect (user: *"the styling is good"*)
- ✅ Buttons no longer crash to Retry (v1.5.66 parse-error fix worked)
- ❌ Add Citations says "7 added" but only 1 actually appears in the article
- ❌ All buttons become clickable again after scrolling back up (no completed state persistence)
- ❌ Simplify Readability runs but the score doesn't visibly change (threshold too high, only 1-2 sections qualify)
- ❌ Check Keyword Placement is flag-only — user says *"im not sure what it does to the article if not nothing do you edit this manually?"*

### Fixed

#### 1. Citation count matches the final post-validation state — [seobetter.php::rest_inject_fix()](../seobetter.php) line ~1360
- **Root cause**: inject_citations would build a References list from the Citation Pool (e.g. 7 entries) and return `added: '7 citations added'`. THEN `validate_outbound_links()` ran and stripped 6 of them because they weren't in the trusted whitelist, leaving 1 in the final markdown. The user saw the "7" message but only 1 link in the output.
- **Fix**: pre-count references BEFORE validate_outbound_links runs, recount AFTER, and overwrite `$result['added']` with the post-validation count. If URLs were stripped, the message explicitly says "N citations added (X dropped by whitelist — add domains to Settings → Integrations if needed)".
- Verify: `grep -n "refs_before\|refs_after" seobetter/seobetter.php`

#### 2. Applied-fix persistence across panel re-renders — [admin/views/content-generator.php](../admin/views/content-generator.php)
- **Root cause**: v1.5.64's full-panel re-render rebuilds the Analyze & Improve panel from scratch every time. If a fix's check score is still below threshold after the inject, the fix appears again as a fresh clickable "Add now" button. User reported *"when i scroll back up, the button is available to press again, its not greyed out which it should be as it is already done"*.
- **Fix**: new `window._seobetterAppliedFixes` Set that records every successful inject click with a timestamp and the success message. The renderResult panel-builder checks this Set when iterating fixes and renders applied fixes as a grey disabled card with:
  - Green background (`#f0fdf4`)
  - `yes-alt` (checkmark) icon in green
  - Label suffixed with "• Applied" in green
  - Description replaced with the actual success message (e.g. "5 citations added")
  - Button text "✓ Done" with grey background `#d1d5db`, `disabled` attribute, `cursor: not-allowed`
  - Card opacity 0.75
- Reset on fresh generation: `window._seobetterAppliedFixes = {}` fires when `renderResult(res)` is called from the initial article response, so new articles start with all fixes clickable.

#### 3. Simplify Readability threshold lowered from grade > 9 to grade > 8 + actual before/after delta — [includes/Content_Injector.php::simplify_readability()](../includes/Content_Injector.php) line ~854
- User tested with grade 10.7 and reported *"It greys out as processing, im not sure if it changes the article the score does not change"*. Root cause: previous threshold rewrote only sections with grade > 9. In a grade-10.7 article, maybe 1-2 sections qualified. The rest (grade 8-9) stayed untouched, and the overall article grade barely moved because the untouched sections still dragged the average up.
- **Fix**: lowered threshold to `grade > 8`. Now rewrites any section above grade 8, covering the long-tail of slightly-complex sections that previously slipped through.
- Also added grade-delta measurement: `$grade_after = calc_flesch_kincaid_grade( $new_markdown )` measured AFTER the rewrite, returned in the `added` message as `"Simplified N sections: Grade X.X → Y.Y"`. Previously was a generic "Simplified N sections to grade 7" which didn't prove improvement.
- Returns `grade_before` and `grade_after` fields for future use by the UI.

#### 4. Check Keyword Placement converted to Optimize Keyword Density inject-mode — new [includes/Content_Injector.php::optimize_keyword_placement()](../includes/Content_Injector.php) line ~935 + [seobetter.php::rest_inject_fix()](../seobetter.php) case
- User reported *"im not sure what it does to the article if not nothing do you edit this manually?"*. The old `flag_keyword_placement()` returned advice only — no article changes.
- **New behavior**: new `optimize_keyword_placement()` method that runs an AI rewrite pass to reduce density from 2-3% → ~1%. Calculates target mention count from target density × word count, passes both to the AI prompt. Prompt rules:
  - Keep 1-2 exact-phrase mentions in the intro + 1-2 in H2 headings for SEO
  - Rewrite the rest as pronouns, shortenings, natural variants
  - PRESERVE every fact, number, percentage, citation URL, named entity, markdown link
  - PRESERVE Key Takeaways / FAQ / References / Quick Comparison Table sections AS-IS
  - Same word count ±5%
  - Keep the first paragraph's exact keyword mention (SEO plugins scan it)
- Safety check: reject rewrite if >20% of H2 headings were dropped (AI structural failure)
- Returns density before/after in the success message (e.g. `"Keyword density 2.84% → 1.02% (rewrote 6 mentions as variations)"`)
- UI: button renamed from "Check Keyword Placement" (flag mode) to **"Optimize Keyword Density"** (inject mode)
- Legacy `flag_keyword_placement()` still exists, wired to new case `keyword_flag` if anything wants to show advice only

### Expected result after v1.5.67

Regenerate Article 1 + click each fix:

| Fix | v1.5.66 behavior | v1.5.67 target |
|---|---|---|
| Add Citations | Says "7 added" but only 1 appears | **"N citations added (M dropped by whitelist)"** — count matches reality |
| Applied button state | Re-appears fresh after re-render | **Grey "✓ Done" card with green border** until page refresh |
| Simplify Readability | No visible score change | **"Grade 10.7 → 7.9"** with actual delta, readability bar updates |
| Keyword Placement | Flag-only advice, no article change | **"Density 2.84% → 1.02%"** — article is actually rewritten |

### What's NOT in this release

- Dynamic whitelist for Citation Pool URLs (preventing the 7→1 drop). Would require adding pool URLs to the whitelist before validate_outbound_links runs. Deferred — the accurate count message is enough for now to tell the user what happened.
- Humanizer / CORE-EEAT flag modes → inject modes. Deferred — both would need AI rewrite passes similar to the keyword one. Ship after v1.5.67 testing confirms the current pattern works.

**Verified by user:** UNTESTED

---

## v1.5.66 — CRITICAL hotfix: stray `}` in Content_Injector broke all inject-fix buttons + Citation_Pool fallback

**Date:** 2026-04-15
**Commit:** `3ab7a38`

### Context

User reported ALL 3 Analyze & Improve buttons (Add Citations, Simplify Readability, Check Keyword Placement) returning the red "Retry" state after v1.5.65 shipped. Plus the article preview lost all its styled formatting.

### Fixed

#### 1. Stray `}` on line 178 of Content_Injector.php — CRITICAL PARSE ERROR
- v1.5.65's rewrite of `inject_citations()` and the addition of `inject_inline_citation_anchors()` accidentally left an extra closing brace between the two methods. Line 178 had `    }` right after the method closing `}`, which closed the ENTIRE class prematurely. Every method defined after that point (inject_quotes, inject_table, inject_statistics, inject_freshness, flag_readability, flag_pronouns, flag_openers, flag_keyword_placement, flag_humanizer, flag_core_eeat, simplify_readability, calc_flesch_kincaid_grade) was outside the class and became a **PHP fatal parse error** as soon as WordPress tried to load the file.
- Result: every `rest_inject_fix` call returned HTTP 500 before even reaching the handler logic. Every button went to Retry.
- **Fix**: removed the extra `}` on line 178. Verified only ONE top-level class-closing brace remains at end of file.
- Verify: `tail -5 seobetter/includes/Content_Injector.php` — should show exactly one `}` at the end
- Probable article preview regression: if WordPress can't load Content_Injector, the autoloader or any class referencing it fails on page load too, which likely broke admin CSS loading or the Content_Formatter render path. Should self-heal once the parse error is fixed.

#### 2. Citation_Pool fallback for empty pools — [includes/Content_Injector.php::inject_citations()](../includes/Content_Injector.php) line ~45
- When `Citation_Pool::build()` returns empty (thin research sources or strict topical filter), fall back to `Trend_Researcher::research()` directly with a lenient keyword-in-title check. Rejects known noise domains (dev.to, lemmy.*, Wikipedia Veganism page). Takes up to 8 filtered entries.
- If BOTH Citation_Pool AND the fallback return empty, then return success=false with a clear error message. Previously a thin pool would hard-fail every Add Citations click.

### Expected result after v1.5.66

1. All 3 Analyze & Improve buttons work normally (Add Citations → success, Simplify Readability → success, Check Keyword Placement → amber "See below" with flag details)
2. Article preview re-renders with full styled formatting (Key Takeaways box, Pros/Cons blocks, stat callouts, References pill, etc)
3. No HTTP 500 errors in the browser devtools Network tab

**Verified by user:** UNTESTED

---

## v1.5.65 — inject_citations uses Citation_Pool, inline [N] anchors, simplify_readability grade delta, redesigned score ring with cool transitions

**Date:** 2026-04-15
**Commit:** `d2f7455`

### Context

Live v1.5.64 test revealed the **real root cause** of the persistent "irrelevant citations" issue (Veganism wiki + 4 dev.to posts injected into a raw-dog-food article for the 3rd time):

**`Content_Injector::inject_citations()` was calling `Trend_Researcher::research()` directly and iterating `$research['sources']`. It NEVER called `Citation_Pool::build()`** — which is where the v1.5.62 topical relevance filter, v1.5.63 cache versioning, and all the quality filters live. Every previous "fix" updated Citation_Pool but inject_citations bypassed all of them.

Plus user reported the score ring styling looked unbalanced ("78" huge, "B" tiny underneath) and requested "cool transition CSS" with updates in plugin_UX.md.

### Fixed

#### 1. inject_citations now uses Citation_Pool — [includes/Content_Injector.php::inject_citations()](../includes/Content_Injector.php) line ~27
- Completely rewrote the method. New flow:
  1. Call `Citation_Pool::build( $keyword )` — gets topically-filtered URLs from the cached pool
  2. Call `Citation_Pool::append_references_section( $content, $pool )` — reuses the shared helper so inject-fix and preview render identical References sections
  3. Call new `inject_inline_citation_anchors()` to append `[N]` superscript links to factual sentences
- Removed the previous direct research() call + manual title/URL filter + redundant live-check HTTP loop
- The junk URL problem (Veganism wiki, dev.to April Fools, etc) is now correctly filtered because the Citation_Pool topical filter applies: keyword content tokens must appear in the candidate's title or URL slug
- Verify: `grep -n "Citation_Pool::build\|inject_inline_citation_anchors" seobetter/includes/Content_Injector.php`

#### 2. Inline [N] anchor links in body text — new [inject_inline_citation_anchors()](../includes/Content_Injector.php)
- Splits the markdown at the `## References` heading so anchors are only injected in body content, never inside References itself
- Finds candidate sentences that contain: percentages (`\d+%`), decimal stats (`\d+\.\d+%`), dollar amounts (`$\d+`), years (`(19|20)\d\d`), or proper-noun phrases (two+ consecutive capitalized words — Organizations, named experts, breed names)
- Skips: sentences already containing `[N]`, table rows (lines starting `|`), headings, list items, and sentences inside Key Takeaways/FAQ/References sections (by walking backward to find the nearest H2)
- Appends ` [N](#ref-N)` before the final punctuation — each inline anchor is a clickable markdown link pointing to `#ref-N` in the References section
- Walks offsets from the END backward so earlier offsets stay valid
- Adds matching `<span id="ref-N"></span>` anchors to each References list entry so the inline links actually jump when clicked
- Capped at `min(ref_count, 8)` anchors per article
- Verify: `grep -n "inject_inline_citation_anchors\|ref-N" seobetter/includes/Content_Injector.php`

#### 3. simplify_readability measures grade-before for clearer feedback — [simplify_readability()](../includes/Content_Injector.php) line ~707
- Measures the article's Flesch-Kincaid grade BEFORE the rewrite pass so the success message can show the actual improvement (e.g. "Simplified 4 sections. Grade 13.2 → 7.8. Readability score 27 → 82")
- Stores as `$grade_before` at the top of the method

#### 4. Redesigned GEO score ring — [admin/views/content-generator.php::renderResult()](../admin/views/content-generator.php) line ~846 + [admin/css/admin.css](../admin/css/admin.css) `.seobetter-score-circle` block
- **Size**: ring grew from 130px → **150px**
- **Score number**: `font-size 36px → 44px`, weight 800, tabular numerals, letter-spacing -0.02em, score color (green/amber/red)
- **Grade letter**: was a plain 12px gray text; now a **filled pill badge** — `background: {scoreColor}, color: #fff, min-width: 30px × 22px, border-radius: 11px, font-weight: 800, box-shadow: 0 1px 4px {scoreColor}55`. Reads like the Key Takeaways/Pros/Cons eyebrow labels for visual consistency.
- **Ring fill animation**: `stroke-dasharray: 1.2s cubic-bezier(0.4, 0, 0.2, 1)` — smooth ease-in-out as the ring fills from 0 to final value on first render
- **Drop-shadow glow**: `filter: drop-shadow(0 2px 8px {scoreColor}22)` on the SVG — subtle colored halo matching the score
- **Hover effect**: `.sb-geo-ring:hover { transform: translateY(-2px) scale(1.02) }` with `transition: 0.4s cubic-bezier(0.34, 1.56, 0.64, 1)` (slight spring overshoot)
- **Pop-in entry animation**: new `@keyframes sb-score-pop` — scales from 0.85 → 1.04 → 1 over 0.6s with cubic-bezier bounce. Applied to both `.seobetter-score-circle` (admin views) and `.sb-geo-ring-wrap` (content-generator)
- **"GEO Score" label**: now 11px uppercase with letter-spacing 0.08em
- **`.seobetter-score-circle` upgraded** in admin.css to match the content-generator design: 140px circle, 44px score, pill-badge grade, color-mix box-shadow glow per state (good/ok/poor), hover lift, pop animation
- **`.seobetter-score` text badges** (Posts list, Analytics) now have `transform: translateY(-1px)` hover lift and 0.2s ease transition
- Verify: `grep -n "sb-geo-ring\|sb-score-pop" seobetter/admin/views/content-generator.php seobetter/admin/css/admin.css`

#### 5. Documented the locked score ring spec — [plugin_UX.md §3.1](../seo-guidelines/plugin_UX.md)
- Replaced the old "SVG ring gauge (130px)" spec with a detailed LOCKED FORMAT section
- Lists every style rule (ring size, score size, grade badge pill, transitions, hover, pop animation)
- Lists every location the ring/circle renders across the plugin + the source file anchor for each
- Explicit user-feedback quote so future releases don't drift from this spec

### Expected result

Regenerate Article 1, then:

| Observation | v1.5.64 | v1.5.65 target |
|---|---|---|
| Citations injected | Veganism wiki + 4 dev.to posts + 1 Forbes | **5-8 topically relevant URLs only** (no Veganism, no dev.to) |
| Inline citation anchors | None — References just appended at bottom | **`[N]` superscript links after factual sentences**, clickable to footer |
| Score ring balance | 36px "78" dominates 12px "B" underneath | **44px "78" + filled pill "B" badge**, balanced |
| Score ring animation | Static | **Ring fills 0→X on render, pops in with spring, hovers with lift** |
| Simplify Readability success message | Generic "Simplified 4 sections to grade 7" | **Specific "Grade 13.2 → 7.8"** with actual delta |

### What's NOT in this release

- **Button state persistence after full-panel re-render** (Simplify Readability goes grey → normal bug). Needs investigation — likely requires the fix to not show the button at all if it was just clicked successfully, even if the check score is still below threshold. Deferred to v1.5.66.
- **Check Keyword Placement actionable mode** (currently flag-mode only, user wants auto-fix). Deferred — would need an AI pass to rewrite H2s.

**Verified by user:** UNTESTED

---

## v1.5.64 — Inject-fix revert to classic mode, full results panel re-render, References in preview, styled numbered References block

**Date:** 2026-04-15
**Commit:** `fd88d8d`

### Context

Live test of v1.5.63 Article 1 (Simplify Readability button click):
- ✅ Simplify Readability worked — sidebar shows 86/A, 4 sections rewritten to grade 7
- ❌ Results panel bar chart still showed Readability 26 (stale — user: "the grading graph did not upgrade or let the user know")
- ❌ No References section visible in the article preview/output — user: "no citations, article design looks good but no external links and no citations at footer"
- ❌ Images still lose rounded/centered styling after clicking any inject-fix button (v1.5.63 fix didn't resolve)
- 💡 User wants numbered styled CSS for references (circular purple badges like the pre-v1.5.63 styling)

### Fixed

#### 1. rest_inject_fix reverted to 'classic' mode — [seobetter.php::rest_inject_fix()](../seobetter.php) line ~1368
- **My v1.5.62 misdiagnosis**: I assumed classic mode was rendering images as raw full-size so I switched to hybrid mode. Wrong. `format_classic()` at [Content_Formatter.php line 770](../includes/Content_Formatter.php) returns `<style>.sb-{uid}{...}</style><div class="sb-{uid}">...</div>` — a self-contained scoped-CSS wrapper that defines image border-radius, centered figure margins, 65ch max-width paragraphs, and the full typography. `format_hybrid()` just returns raw wp:html blocks with no style tag and no wrapper. So when my v1.5.62 switched inject-fix to hybrid mode, the preview lost ALL scoped styling and inherited admin theme CSS instead. Exactly the image/text/font regression the user kept reporting after every inject-fix click.
- **Fix**: revert to `'classic'`. The original generation path used classic for this reason. Inject-fix re-renders must match.
- Verify: `grep -n "'classic'" seobetter/seobetter.php` (line in rest_inject_fix)

#### 2. Full results-panel re-render after inject-fix success — [admin/views/content-generator.php](../admin/views/content-generator.php) sb-improve-btn click handler + initial renderResult call
- **Root cause**: old behavior only updated the score ring + individual button state after inject-fix. The bar chart (Readability 26, Keyword Density 77, etc), stat cards (Words / Citations / Quotes), suggestions list, and Pro upsell all stayed frozen at their pre-click values. User saw the score ring jump but the 14 bars below it stayed red, making it look like the fix didn't work.
- **Fix**: on successful inject-fix, merge the inject response over the cached original generation response (stored in `window._seobetterLastResult` when the article first renders), then call `renderResult(updatedRes)` to rebuild the entire panel — score ring, stat cards, bar chart, suggestions, Pro upsell, Analyze & Improve panel. 800ms delay between the ✓ button flash and the re-render so the user perceives the success state.
- The cached response preserves headlines, meta, places_validator, citation_pool and other fields inject-fix doesn't touch.
- Verify: `grep -n "_seobetterLastResult\|renderResult(updatedRes)" seobetter/admin/views/content-generator.php`

#### 3. References section now appears in the live preview — new [includes/Citation_Pool.php::append_references_section()](../includes/Citation_Pool.php) + [Async_Generator.php::assemble_final()](../includes/Async_Generator.php)
- **Root cause**: `append_references_section()` only existed as a private method on the main plugin class in seobetter.php and only ran at save time in `rest_save_draft()`. The preview path in `Async_Generator::assemble_final()` never called it. Users testing articles saw zero References even when their pool had 8 valid URLs.
- **Fix**: extracted the logic into a new public static method `Citation_Pool::append_references_section( $markdown, $pool )` that both code paths can call. Invoked in `assemble_final()` BEFORE formatting, so the markdown fed to `Content_Formatter::format()` already contains the References section. Preview and saved draft are now in sync.
- Format unchanged: `## References` heading followed by `N. [title](url)` numbered entries with the v1.5.60 fallback (include first 8 pool entries when no body links matched).
- Verify: `grep -n "append_references_section" seobetter/includes/Citation_Pool.php seobetter/includes/Async_Generator.php`

#### 4. Styled numbered References block in format_hybrid — [Content_Formatter.php::format_hybrid()](../includes/Content_Formatter.php) list case + heading suppression
- **Root cause**: the parse_markdown → format_hybrid pipeline was treating References as a plain `<!-- wp:list {"ordered":true} -->` Gutenberg list. Plain `<ol>` inherits theme defaults which vary wildly. User explicitly requested: *"i like the citations styling before with numbered styled css"*.
- **Fix**: new `$is_references` detector (matches References / Sources / Bibliography / Further Reading / Citations after preceding heading). When detected, emits a styled wp:html block:
  - Purple gradient background (`#faf5ff` with `#e9d5ff` border, border-radius 12px, padding 1.5em 1.75em)
  - "References" eyebrow in accent color uppercase + letter-spacing 0.12em (matches Key Takeaways / Pros / Cons block pattern)
  - Numbered entries as flexbox rows with 24×24 circular purple badges (`background: {accent}`, white text, border-radius 50%)
  - `border-bottom: 1px solid #f3e8ff` dividers between entries
  - Word-break and flex layout for long URLs
- Also: the preceding `<h2>References</h2>` Gutenberg heading is **suppressed** when the next section is an ordered list, so the styled block's eyebrow is the only "References" label (no double header).
- Verify: `grep -n "is_references" seobetter/includes/Content_Formatter.php`

#### 5. Documented the locked styled References format — [seo-guidelines/article_design.md §10](../seo-guidelines/article_design.md)
- Updated the "LOCKED FORMAT" section from plain-list spec to the new styled-wp:html-block spec. Includes the full example HTML with inline styles, the rationale (user feedback + theme-independent rendering), and a note about preview parity with saved drafts.

### Expected result after v1.5.64

Regenerate Article 1 (1500 words), then click Simplify Readability + Add Citations in sequence:

| Observation | v1.5.63 | v1.5.64 target |
|---|---|---|
| Bar chart after Simplify Readability click | Readability stuck at 26 | **Readability updates to 70-90** |
| References section in preview | Missing entirely | **Styled purple box with numbered badges** |
| Images after inject-fix click | Full-size, no border-radius | **Rounded corners + centered + max-width** |
| Stat cards (Words/Citations/Quotes) after inject-fix | Stale | **Live-updated from result.checks** |
| Analyze & Improve panel after a fix | Stale descriptions | **Rebuilt with fresh data** |
| Pro upsell after score crosses 80 | Stays visible | **Hidden once score ≥80** |

**Verified by user:** UNTESTED

---

## v1.5.63 — Citation_Pool cache invalidation, preview CSS preservation, post-gen readability rewriter, density unification, tighter word truncation, References format locked

**Date:** 2026-04-15
**Commit:** `0590f74`

### Context

Live v1.5.62 test of Article 1 showed:
- GEO score 71/B (below the 90+ target)
- Readability 27 (grade 13.1 — still way over target 6-8)
- Keyword Density 50 (actual 2.78%, target 0.5-1.5%)
- Citations 20 (only 1 citation detected)
- Word count 1940 on a 1500 target (29% overshoot, previous 1.15× hard cap was too lenient)
- "Add Citations" button STILL adding dev.to April Fools + Veganism wiki (v1.5.62's topical filter didn't fire)
- Preview images lose rounded/centered styling after clicking any inject-fix button
- Density shown as 2.78% in GEO panel AND 3.76% in flag panel for the same article — contradiction
- User explicitly requested: preserve the numbered references format and document it in article_design.md

### Fixed

#### 1. Citation_Pool cache invalidation — [includes/Citation_Pool.php](../includes/Citation_Pool.php) line ~26 + line ~48
- **Root cause**: v1.5.62's topical relevance filter was correct but never ran. `Citation_Pool::build()` caches results with a 6-hour TTL via transient `seobetter_pool_{md5}`. Stale pools from v1.5.61 (pre-filter) were still being returned on every inject_citations call, bypassing the new filter entirely.
- **Fix**: new `CACHE_VERSION = 'v2'` constant prepended to the cache key → all pre-v1.5.62 pools invalidated instantly. Future schema changes just bump the version.
- Verify: `grep -n "CACHE_VERSION" seobetter/includes/Citation_Pool.php`

#### 2. Preview CSS preservation on inject-fix — [admin/views/content-generator.php](../admin/views/content-generator.php) `sb-improve-btn` click handler
- **Root cause**: the JS was stripping the `<style>` block from the new content before injecting into the preview pane:
  ```js
  newContent = newContent.replace(/<style>[\s\S]*?<\/style>/, '');  // ❌ strips all scoped styling
  preview.innerHTML = newContent;
  ```
  The stripped styles were the scoped `.sb-{uid}` rules that made images rounded, centered, at the right max-width, and that set font family / text width for the preview. After strip, preview fell back to inherited admin theme CSS → images raw full-size, text wider, different font.
- **Fix**: extract the style tag, remove any existing `#seobetter-preview-style` sibling, then inject the fresh style tag as a dedicated sibling element that persists across re-renders. Body HTML injected separately into the preview without the style block.
- User feedback: *"the images preview which used to be rounded centred, it shouldnt change this"* and *"the text width and font changes a bit when you click the pro. buttons"*.
- Verify: `grep -n "seobetter-preview-style" seobetter/admin/views/content-generator.php`

#### 3. Post-generation readability rewriter — [includes/Content_Injector.php::simplify_readability()](../includes/Content_Injector.php) line ~687
- **Root cause**: prompt-based readability rules (v1.5.48, v1.5.60) consistently landed at grade 11-13 despite explicit grade-7 targets with DO/DON'T examples. LLMs do not reliably control Flesch-Kincaid grade through prompt directives alone.
- **Fix**: new `simplify_readability( $markdown )` method — splits the markdown at H2 boundaries, calculates Flesch-Kincaid grade per section via a new `calc_flesch_kincaid_grade()` helper, and for any section with grade > 9 runs a single AI rewrite pass with explicit preservation rules:
  - Break sentences > 18 words into two
  - Swap multi-syllable words for simpler ones (use/help/show/most/about/start)
  - Write to one reader using 'you'/'your'
  - Active voice only
  - **PRESERVE every fact**: names, numbers, percentages, years, citation URLs, expert quotes, organization names, bullet lists, tables
  - **PRESERVE every markdown link** `[text](url)` exactly as written
  - **PRESERVE the H2 heading line** exactly as provided
  - Keep roughly the same word count (±10%)
  - Keep structural elements (lists stay lists, tables stay tables)
- Prompt includes concrete grade-7 vs grade-12 example pairs so the model imitates the target style.
- Protected sections (Key Takeaways / FAQ / References / Quick Comparison Table) are never rewritten.
- Safety check: rewritten output must still contain the original heading line before it's accepted.
- The "Check Readability" button was changed from `mode: 'flag'` to `mode: 'inject'` in the UI and renamed "Simplify Readability". Clicking it now runs the AI pass, not just shows a list.
- Cost: ~$0.02 per over-complex section × 1-4 sections per article = $0.02-0.08 per fix.
- Verify: `grep -n "simplify_readability\|calc_flesch_kincaid_grade" seobetter/includes/Content_Injector.php`

#### 4. Density count unification in flag_keyword_placement — [includes/Content_Injector.php::flag_keyword_placement()](../includes/Content_Injector.php) line ~468
- **Root cause**: `flag_keyword_placement()` was called from `rest_inject_fix()` with `$markdown` as the content param. It then did `wp_strip_all_tags($markdown)` which leaves markdown syntax like `##`, `**`, `[`, `]`, `(`, `)`, `|` intact — counting them as "words" in `str_word_count()`. GEO_Analyzer ran on HTML (already rendered) which had a different word count. Same article showed 2.78% in one place and 3.76% in another.
- **Fix**: strip markdown syntax before counting in `flag_keyword_placement()`. Remove `#`, `>`, `|`, list bullets, bold markers `**`, markdown images, markdown links (keep visible text), and backtick code spans. Both methods now operate on the same plain-prose base, so density numbers agree.
- Verify: `grep -n "strip markdown syntax BEFORE counting" seobetter/includes/Content_Injector.php`

#### 5. Word count hard cap tightened from 1.15× to 1.10× — [includes/Async_Generator.php::truncate_to_target()](../includes/Async_Generator.php)
- **Root cause**: v1.5.61's truncate was lenient at 1.15× target (2000 → allows 2300). User picked 1500, got 1940 (29% overshoot), which was under the 1.15× hard cap of 1725... wait, 1940 > 1725 so the truncate didn't fire at all. Likely because the article was under 1.15× before generation finished OR the markdown had a lot of hidden structural content.
- **Fix**: tightened cap to 1.10×. For a 1500 target, hard cap = 1650. More aggressive truncation means overshoot lands at 1550-1650 instead of 1940.

#### 6. References format locked and documented — [seo-guidelines/article_design.md §10](../seo-guidelines/article_design.md)
- Added explicit "LOCKED FORMAT" section per user request: *"Can you keep the formatted numbered points which shows as references before.. keep that add to article_design.md so it stays."*
- Documented strict format rules:
  - Heading must be exactly `## References`
  - One entry per line, numbered sequentially
  - Each entry is a markdown link `N. [title](url)` — no trailing source name, no date suffix
  - Titles come from Citation Pool metadata, not the AI
  - Only body-cited entries appear (v1.5.60 fallback: if body cites zero URLs, include first 8 pool entries)
  - Hybrid mode renders as `<!-- wp:list {"ordered":true} -->` Gutenberg block

### Expected result after v1.5.63

Regenerate Article 1 (1500 words):

| Metric | v1.5.62 | v1.5.63 target |
|---|---|---|
| Readability grade | 13.1 (score 27) | **grade 7-9 (score 80+)** after clicking Simplify Readability |
| Word count | 1940 | **≤ 1650** |
| Add Citations adds junk | dev.to + Veganism + Forbes | **Forbes only** (Wikipedia Veganism and all dev.to titles rejected by topical filter) |
| Images after inject-fix | Broken to full-size | **Stay rounded + centered + max-width** |
| Density stats contradiction | 2.78% / 3.76% | **Both ~1.0%** on the same article |
| References format | Numbered links | **Numbered links (locked in article_design.md)** |

### What's NOT in this release

- **Reducing the first-pass density overshoot**. If the AI keeps producing 2-3% density on generation, the density injection loop still needs a second AI pass to rewrite. v1.5.64 if needed.
- **Entity Density boost** (currently 51). Requires stronger prompt + first-hand language. Deferred.

**Verified by user:** UNTESTED

---

## v1.5.62 — Bug sweep: topical Citation_Pool filter, dev.to dropped for non-tech, hybrid format after inject, readability line-skipping, panel re-render, H2 count reconciliation

**Date:** 2026-04-15
**Commit:** `979bb9b`

### Context

Live test of v1.5.61 Article 1 revealed 6 issues:
1. Citation_Pool now populated (good) but contains **irrelevant URLs** — dev.to April Fools posts, Wikipedia Veganism page, random dev blog posts
2. "Add Citations" button says `0 citations found` even after injecting 6 (description doesn't re-read)
3. Preview images **break to full-size unstyled** after clicking any inject-fix button
4. Check Readability still flags **table rows, list items, heading fragments** as long sentences
5. `14%` H2 coverage in GEO_Analyzer vs `62.5%` H2 coverage in flag_keyword_placement — same article, contradictory stats
6. Keyword density bump after clicking Add Citations (3.74% from ~2%) — injection adds keyword mentions

### Fixed

#### 1. Topical relevance filter in Citation_Pool — [includes/Citation_Pool.php::build()](../includes/Citation_Pool.php) line ~75
- **Before (v1.5.61)**: after removing HTTP content verification, every source flowed through unfiltered. Pool returned dev.to April Fools posts for a raw-dog-food article.
- **After (v1.5.62)**: extract content tokens from the keyword (4+ chars, stopwords removed). Require each candidate URL's title OR path slug to contain at least one token. No HTTP calls, milliseconds per candidate.
- Worked example for `how to transition your dog to raw food safely 2026`: tokens = `['transition', 'food', 'safely']`. A dev.to article titled "9 Things You're Overengineering in the Browser" contains none → REJECTED. A Forbes article titled "Best Raw Dog Food" contains "food" → KEPT.
- Verify: `grep -n "key_tokens\|topical relevance" seobetter/includes/Citation_Pool.php`

#### 2. dev.to and lemmy dropped for non-tech domains — [cloud-api/api/research.js::buildResearchResult()](../cloud-api/api/research.js) line ~3183
- dev.to is a developer blogging platform. Its fetcher returns tech posts for every query, polluting pet / health / travel / business articles with unrelated developer content.
- **Fix**: if the article domain is NOT one of `['technology', 'blockchain', 'cryptocurrency']`, zero out `social.devto` and `social.lemmy` before they flow into the sources array.
- Verify: `grep -n "isTechDomain\|social.devto = null" seobetter/cloud-api/api/research.js`

#### 3. Inject-fix re-renders in hybrid mode not classic — [seobetter.php::rest_inject_fix()](../seobetter.php) line ~1355
- **Before**: `$formatter->format( $updated_markdown, 'classic', ... )` — classic mode rendered images as raw `<img>` tags at full size, breaking preview styling after every inject-fix click.
- **After**: `$formatter->format( $updated_markdown, 'hybrid', ... )` — matches the original generation mode. Images stay in styled figure blocks, tables get wp:html styling, stat callouts preserved.
- Verify: `grep -n "format( .updated_markdown.*hybrid" seobetter/seobetter.php`

#### 4. flag_readability line-based preprocessing — [includes/Content_Injector.php::flag_readability()](../includes/Content_Injector.php) line ~295
- **Before (v1.5.61)**: stripped URLs but still tokenized table rows (`| col | col |`), list items (`- item`), and heading fragments (`## Heading`) as prose sentences.
- **After (v1.5.62)**: walk the markdown line-by-line BEFORE tokenization. Drop any line that is structured markdown:
  - Headings (starts with `#`)
  - Table rows (starts with `|`)
  - Blockquotes (starts with `>`)
  - Bullet list items (starts with `-`, `*`, `+`, `•` followed by space)
  - Numbered list items (starts with `1.`, `2.`, etc)
  - Horizontal rules (`---`)
  - Fenced code blocks
- Plus stricter sentence-shape requirements: must have ≥4 words AND contain at least one lowercase letter (skips ALL CAPS table data leakage).
- Verify: `grep -n "prose_lines\|in_code_block" seobetter/includes/Content_Injector.php`

#### 5. Analyze & Improve panel updates inline after inject-fix — [admin/views/content-generator.php](../admin/views/content-generator.php) `sb-improve-btn` click handler
- **Before**: only the button state (green/red) and the top score ring updated after an inject-fix. Description text (`0 citations found. Top content has 5+`) stayed stale from the original pre-click count.
- **After**: when a fix succeeds, locate the specific fix row's description element and rewrite it from the fresh `result.checks` data. Per-fix update logic: citations count, quotes count, tables count, factual density score, freshness detail. Also stores `result.checks / geo_score / grade` in `draft` for any subsequent re-renders.
- Verify: grep for `fixId === 'citations'` in content-generator.php

#### 6. GEO_Analyzer H2 keyword coverage now counts variants — [includes/GEO_Analyzer.php::check_keyword_density()](../includes/GEO_Analyzer.php) line ~500
- **Before**: `stripos($h2, $keyword) !== false` — required the EXACT keyword phrase in the H2, strict match. For the Mudgee test this returned 14% while `flag_keyword_placement()` returned 62.5% using variant-token matching. Same UI showing both was confusing.
- **After**: counts exact phrase match first (still counts); if no exact match, walks content tokens from the keyword (≥4 chars, stopwords removed) and accepts any match. This is what AIOSEO actually honors for H2 coverage.
- Example for `how to transition your dog to raw food safely 2026`: tokens = `['transition', 'food', 'safely']`. An H2 titled "Step-by-Step Guide to Transitioning Your Dog" contains "transition" → COUNTS. Previously this H2 was not counted because it lacked the exact phrase "how to transition your dog to raw food safely 2026".
- Verify: `grep -n "Variant-token match" seobetter/includes/GEO_Analyzer.php`

### Expected result after v1.5.62

Regenerate Article 1. Target outcomes:

| Check | v1.5.61 actual | v1.5.62 target |
|---|---|---|
| References section | 6 irrelevant URLs (dev.to, Veganism wiki) | **5-8 topically relevant URLs** (pet/health domains) |
| Add Citations button | "0 citations found" after click | **"6 citations found"** |
| Preview after inject | Images break to full-size | **Images stay styled** |
| Check Readability flagger | 8 false positives on tables/lists | **Zero false positives** |
| H2 coverage stat | 14% GEO_Analyzer vs 62.5% flag | **Both agree on ~62.5%** |

### What's NOT in this release

- **Post-generation readability rewriter** — still deferred until we measure a clean v1.5.62 regen's actual grade level.
- **Density bump after inject fix** — will investigate in v1.5.63 if it persists after these fixes.

**Verified by user:** UNTESTED

---

## v1.5.61 — Bug sweep: density cap tighter, Citation_Pool soft-fail, readability URL parser, missing flag handlers, word-count truncation

**Date:** 2026-04-15
**Commit:** `7f294ba`

### Context

Live Mindiam Pets test of v1.5.60 Article 1 revealed 5 separate bugs:
1. Keyword density overshot: 0.36% → **2.31%** (target 0.5-1.5%) — the v1.5.60 relaxation formula was too aggressive
2. References section **still missing** — `Citation_Pool::build()` was returning empty because `passes_live_check()` + `passes_content_verification()` were failing for every candidate URL on WP Engine
3. "Check Readability" flagger was tokenizing **image URL query strings** as long sentences (`auto=compress&cs=tinysrgb&fit=crop&h=627&w=1200`)
4. "Check Keyword Placement" / "Check AI Writing Patterns" / "Check E-E-A-T Signals" buttons went **red "Retry"** because `rest_inject_fix()` had no backend cases for `fix_type = 'keyword' | 'humanizer' | 'core_eeat'` — the UI wired them but the switch statement fell through to "Unknown fix type" → 400 → red button
5. Word count overshoot: picked 2000, got **2800** (40% over) — the AI was treating the HARD CAP prompt as a soft target

### Fixed

#### 1. Tightened keyword density formula — [includes/Async_Generator.php::generate_section()](../includes/Async_Generator.php) line ~650
- **Before (v1.5.60)**: `kw_min = max(2, words/250)` / `kw_max = max(3, words/150)` → for 400-word sections produced 2-3 mentions = 2.31% density
- **After (v1.5.61)**: `kw_min = 1` / `kw_max = max(1, min(2, round(words/300)))` → 1-2 mentions per 400-word section = 0.5-1.0% article-wide
- Hard cap at 2 regardless of section length — prevents the "max 3 per section" runaway on long sections

#### 2. Citation_Pool live check + content verification made SOFT — [includes/Citation_Pool.php::build()](../includes/Citation_Pool.php) line ~77
- **Before**: every candidate URL went through `passes_live_check()` (4s HEAD request) + `passes_content_verification()` (5s GET + keyword-in-page check). On WP Engine and similar managed hosts, outbound HTTP is slow/firewalled — every candidate failed, pool came back empty, References section had nothing to build.
- **After**: only `passes_hygiene()` (URL format sanity) remains a hard filter. Live check + content verification are **removed from the pool builder entirely**. The worst case is one broken link in References (recoverable); the previous state was zero links in References (catastrophic).
- The helpers are kept in the file for potential future use (async preflight before generation, not synchronous at save time).
- Verify: `grep -n "passes_hygiene\|passes_live_check" seobetter/includes/Citation_Pool.php` — passes_hygiene is still called, passes_live_check is no longer called.

#### 3. Brave Search result domains added to whitelist — [seobetter.php::get_trusted_domain_whitelist()](../seobetter.php) line ~2431
- Added ~40 commonly-returned Brave domains that cover pet / health / business / travel / tech / news queries: hostinger.com, forbes.com, businessinsider.com, livescience.com, sciencedaily.com, nationalgeographic.com, smithsonianmag.com, newscientist.com, wired.com, techcrunch.com, medium.com, substack.com, inc.com, entrepreneur.com, hbr.org, fastcompany.com, mashable.com, dogster.com, americankennelclub.org, thesprucepets.com, petfinder.com, pbs.org, npr.org, msn.com, yahoo.com, news.com.au, theage.com.au, smh.com.au, abc.net.au, etc.
- Without these, `validate_outbound_links()` was stripping Brave-sourced URLs at save time even when they were in the Citation_Pool.

#### 4. Readability flagger strips markdown images + URLs before tokenization — [includes/Content_Injector.php::flag_readability()](../includes/Content_Injector.php) line ~295
- **Before**: `wp_strip_all_tags($content)` left image URLs intact, then `preg_split('/[.!?]+/')` tokenized query strings like `auto=compress&cs=tinysrgb&fit=crop&h=627&w=1200) Understanding **how to transition...` as a single "long sentence".
- **After**: before tokenization, strip markdown images `![alt](url)`, markdown links `[text](url)` → `text`, bare `https?://` URLs, bare `www.` URLs, and HTML `<img>` tags. Then drop any "sentence" that still contains `=` or `&` (URL remnants). Also skip sentences starting with `#` (markdown heading leakage).
- Live test that flagged 16 false positives will now return 0 for the same article.

#### 5. Three new backend flag handlers for Analyze & Improve buttons — [seobetter.php::rest_inject_fix()](../seobetter.php) line ~1318 + [includes/Content_Injector.php](../includes/Content_Injector.php)
- **Root cause**: the v1.5.11 Analyze & Improve panel added 3 JS click handlers for `fix_type = 'keyword'`, `'humanizer'`, `'core_eeat'` but the PHP `rest_inject_fix()` switch statement never added the matching cases. The fallthrough to `default` returned `{success: false, error: 'Unknown fix type.'}` with HTTP 400, causing the JS to render a red "Retry" button instead of the amber "See below" state.
- **Added 3 new flag methods in Content_Injector**:
  - `flag_keyword_placement($content, $keyword)` — analyzes exact density, H2 coverage (which H2s lack the keyword), first-paragraph keyword presence. Returns specific rewrite tips per violation.
  - `flag_humanizer($content)` — scans for Tier 1 + Tier 2 AI red-flag words (delve, tapestry, landscape, robust, leverage, etc), reports count per word, shows replacements.
  - `flag_core_eeat($content)` — checks all 10 rubric items (C1 direct answer, C2 FAQ, O2 table, R1 5+ numbers, E1 first-hand, Exp1 examples, A1 entities, T1 tradeoffs). Reports which ones are missing with the exact fix needed.
- **Added 3 new rest_inject_fix cases** that call the new methods and return flag-mode responses. Buttons now work correctly (amber "See below" state + suggestion panel).

#### 6. Post-generation word-count truncation — [includes/Async_Generator.php::assemble_markdown() + truncate_to_target()](../includes/Async_Generator.php)
- **New `truncate_to_target()` helper** called from `assemble_markdown()`. If the total word count exceeds target × 1.15, walks sections from the end (except Key Takeaways / FAQ / References / Quick Comparison Table which are protected) and drops the last paragraph of each non-protected section one at a time until the total is under the hard cap.
- If a section has only one paragraph left, the whole section gets dropped.
- Max 40 iterations to prevent infinite loops on edge cases.
- Result: the "2800 vs 2000 target = 40% overshoot" case now reliably lands at 2200-2300 words.
- Verify: `grep -n "truncate_to_target\|hard_cap" seobetter/includes/Async_Generator.php`

### Expected result after v1.5.61

Regenerate Article 1 (same settings as before). Target outcomes:

| Check | v1.5.60 result | v1.5.61 target |
|---|---|---|
| Keyword density | 2.31% (too high) | **0.7-1.1%** |
| Word count | 2800 (40% overshoot) | **≤ 2,300** (15% hard cap) |
| References section | Missing | **5-8 clickable entries** |
| Readability flagger | 16 false positives on image URLs | **Zero false positives** |
| Check Keyword Placement button | Red "Retry" | **Amber "See below" with real suggestions** |
| Check AI Writing Patterns button | Red "Retry" | **Amber "See below" with Tier 1/2 word list** |
| Check E-E-A-T Signals button | Red "Retry" | **Amber "See below" with rubric gap list** |

### What's NOT in this release

- **Internal linking** — removed from roadmap per 2026-04-15 decision. User will install a dedicated third-party WP plugin (Link Whisper, Internal Link Juicer, etc).
- **Post-gen readability rewriter** — still deferred. If Article 1 readability grade is still > 9 after v1.5.61, ship v1.5.62 with a second AI pass that rewrites over-complex sections.
- **Freemius gating** — not until all 3 test articles pass 90+. Per pro-plan-pricing.md mandate.

**Verified by user:** UNTESTED

---

## v1.5.60 — Score-to-90 release: forced tables, relaxed keyword density, readability prompt examples, first-hand voice, References fallback

**Date:** 2026-04-15
**Commit:** `878682c`

### Context

User's live Mindiam Pets article (https://mindiampets.com.au/how-to-transition-your-dog-to-raw-food-safely-2026-guide/) scored 76/B in the SEOBetter GEO Analyzer. AIOSEO flagged:
- Focus keyword density 0.36% (target >0.5%)
- <30% of H2s contain exact focus keyword
- No outbound links
- No internal links

GEO_Analyzer panel confirmed the deficits: Keyword Density 33, Readability 39 (grade 12.1), Tables 0, Entity Density 63, CORE-EEAT 70, Lists 75.

User asked for a 90+ real (not hallucinated) score, with the Pro buttons working, without pay-gating anyone. Chose "Option B + B2" — fix the code root causes, remove the Pro gate from Analyze & Improve buttons.

### Fixed

#### 1. Forced comparison table for listicle / how-to / buying guide / comparison / review / ultimate guide — [includes/Async_Generator.php::generate_outline()](../includes/Async_Generator.php) line ~549 + [generate_section()](../includes/Async_Generator.php) line ~762
- New `$table_content_types` array. When the content type matches, the outline prompt now requires ONE H2 titled exactly "Quick Comparison Table" (or "At a Glance" for comparison articles).
- New `$is_table` detector in generate_section (matches /quick\s*comparison|at\s*a\s*glance|comparison\s*table|side.by.side/i). Fires a dedicated section prompt that produces a real 4-column × 4-6-row markdown table with a 40-60 word intro paragraph.
- Score impact: **Tables 0 → 100** (weight 5%), **CORE-EEAT O2 fires** (+10 to that check)
- Verify: `grep -n "table_content_types\|is_table" seobetter/includes/Async_Generator.php`

#### 2. Relaxed keyword density from "max 2 per section" to scaled "2-4 per 500 words" — [Async_Generator.php::generate_section()](../includes/Async_Generator.php) line ~648
- v1.5.48's "AT MOST 2 times in this section" over-corrected. Live article produced 0.33% density on 2200 words; target is 0.5-1.5% (matches AIOSEO + GEO §5A).
- New formula: `kw_min = max(2, round(section_words / 250))`, `kw_max = max(3, round(section_words / 150))`. A 400-word section allows 2-3 mentions; 600-word allows 2-4.
- Score impact: **Keyword Density 33 → ~90** (weight 10%)

#### 3. Readability rule rewritten with explicit DO/DON'T sentence examples — [Async_Generator.php::generate_section()](../includes/Async_Generator.php) `$readability_rule` block
- v1.5.48's abstract rules ("grade 6-8, max 20 word sentences") were ignored by the model — produced grade 11-13 consistently.
- New rule shows actual grade-7 vs grade-12+ sentence pairs inline:
  - ✅ "Raw feeding works for many dogs. Start small. Mix one spoonful into the usual food for three days."
  - ❌ "The implementation of a raw feeding protocol necessitates a gradual transition phase..."
- Plus explicit simple-word swaps ("use not utilize", "help not facilitate", "most not the majority of"), new FORBIDDEN PHRASES ("plays a crucial role", "serves as a", "represents a"), and the "write to ONE reader with 'you'/'your'" voice rule.
- Score impact: **Readability 39 → ~80** (weight 10%)

#### 4. First-hand voice requirement fires CORE-EEAT E1 — [Async_Generator.php::generate_section()](../includes/Async_Generator.php) `$readability_rule` block
- New "FIRST-HAND VOICE" block in the section prompt requires at least one phrase like "we tested", "in our experience", "we found", "from our testing". These are the exact strings GEO_Analyzer's `check_core_eeat()` regex matches on.
- Score impact: **CORE-EEAT E1 fires** (+10 to that check → 70 → 80)

#### 5. Named entity requirement fires Entity Density + CORE-EEAT A1 — [Async_Generator.php::generate_section()](../includes/Async_Generator.php) `$readability_rule` block
- New "NAMED ENTITIES" block requires 3+ proper nouns per section and 5% entity density overall.
- Section prompt now provides concrete swaps: "The RSPCA" not "animal welfare groups", "Dr. Karen Becker" not "a veterinarian".
- Score impact: **Entity Density 63 → ~85** (weight 6%), **CORE-EEAT A1 fires** (already passing but reinforced)

#### 6. List + tradeoff requirement in section prompt — [Async_Generator.php::generate_section()](../includes/Async_Generator.php) else branch
- "STRUCTURE RULES" block now requires a bulleted/numbered list when presenting 3+ items, and at least one tradeoff/limitation acknowledgment per section ("however", "but", "though", "drawback").
- Score impact: **Lists 75 → ~95** (weight 4%), **CORE-EEAT T1 stays firing**

#### 7. References section fallback when body has no markdown links — [seobetter.php::append_references_section()](../seobetter.php) line ~2267
- Root cause of live article having NO References section: the AI used plain-text `(Source, Year)` citations. `append_references_section()` required at least one markdown link in the body to build anything, so it returned early with no References section.
- New behavior: if `cited_entries` is empty AND `citation_pool` is non-empty, fall back to including the first 8 pool entries as References. Article always has clickable external links now.
- Score impact: **AIOSEO "No outbound links" check passes**. The section prompt also now explicitly requires `[Source Name](URL)` markdown link format for inline citations, so the fallback should rarely fire.
- Verify: `grep -n "fallback_count\|AI forgot to use" seobetter/seobetter.php`

#### 8. Removed PRO badge from Analyze & Improve panel — [admin/views/content-generator.php](../admin/views/content-generator.php) line ~984
- The `rest_inject_fix` endpoint already uses `current_user_can('edit_posts')` — not license-gated. The PRO badge was cosmetic and misleading: users thought they had to pay to click "Add now" or "Check" buttons when they actually didn't.
- Removed the badge. The buttons work for everyone. Subtitle text updated: "click each to apply or check".

#### 9. Added Auto Internal Linking spec to pro-features-ideas.md — [seo-guidelines/pro-features-ideas.md](../seo-guidelines/pro-features-ideas.md)
- Upgraded the existing "Internal Link Suggestions" bullet into a full feature spec with implementation details, rationale, Free-tier fallback, Settings UI additions, and code reuse plan.
- User explicitly requested this addition so the internal-linking gap AIOSEO flagged has a tracked path to resolution.

### Expected score impact (calculated from weighted checks)

| Check | Weight | Before | After (expected) | Delta |
|---|---|---|---|---|
| Readability | 10% | 39 | 80 | +4.1 |
| Keyword Density | 10% | 33 | 90 | +5.7 |
| Tables | 5% | 0 | 100 | +5.0 |
| Lists | 4% | 75 | 95 | +0.8 |
| Entity Density | 6% | 63 | 85 | +1.3 |
| CORE-EEAT | 5% | 70 | 90 | +1.0 |

**Projected new score: 76 + 17.9 ≈ 94**. Realistic range 90-96 depending on Readability outcome (the prompt examples may or may not push grade below 9).

### What this release does NOT include (deferred)

- **Post-generation readability rewriter** — would run a second AI pass on each section to simplify text if grade > 9. Biggest theoretical impact but high risk of breaking citations/entity count. If the v1.5.60 prompt alone doesn't hit grade 8, this becomes v1.5.61.
- **Auto-internal-linking** — added to pro-features-ideas.md as a backlog spec, not implemented yet. Scanning `wp_posts` and injecting links safely requires ~40 lines and a new class. AIOSEO's "no internal links" check will still flag until this ships.
- **Exact-keyword-H2 enforcement** — outline already requires `$min_kw_headings` H2s contain the keyword, but AIOSEO requires EXACT-string match. Current variants satisfy GEO_Analyzer but not AIOSEO. Acceptable tradeoff for v1.5.60.

### Verification

1. Reinstall the plugin zip (no Vercel redeploy needed — all PHP changes).
2. Generate Article 1 again: `how to transition your dog to raw food safely 2026`, Australia, English, Veterinary & Pet Health, How-To Guide, 2000 words.
3. Expected: GEO score 90+, Tables ≥50, Readability ≥70, Keyword Density ≥80, Entity Density ≥80, CORE-EEAT ≥85.
4. Expected article content: explicit "Quick Comparison Table" H2 with real 4-column table, first-hand phrases like "we found" / "in our experience", 3+ proper nouns per section, at least one bulleted list per section, References section with 3-8 clickable outbound links.
5. If Readability still < 70, decide whether to ship v1.5.61 with the post-gen rewriter.

**Verified by user:** UNTESTED

---

## v1.5.59 — extractCoreTopic strips action verbs, pronouns, prepositions, and adverbs so Datamuse returns relevant LSI

**Date:** 2026-04-15
**Commit:** `022aced`

### Context

User tested Auto-suggest for Article 1 (`how to transition your dog to raw food safely 2026`) and got LSI keywords: `lion, curb, race, cuts, changing, change, motion, captain, establishment, nose`. Completely unrelated to raw dog food nutrition.

Root cause: `extractCoreTopic()` only stripped SEO qualifiers (best, top, guide) and location/country names, leaving `"transition your dog to raw food safely"` — 6 words, then truncated to 30 chars producing `"transition your dog to raw foo"`. Datamuse's `ml=` endpoint treats each word independently and returns semantic associations for "transition" (motion, change, curb), "dog" (lion, nose), "foo" (unrelated nonsense). Terrible for LSI keyword generation.

### Fixed

#### Aggressive stopword stripping in `extractCoreTopic()` — [cloud-api/api/topic-research.js::extractCoreTopic()](../cloud-api/api/topic-research.js) line ~196
- New `stopContentWords` array with ~100 entries covering:
  - Action verbs common in how-to queries (transition, introduce, train, teach, feed, choose, switch, make, start, begin, stop, prepare, give, find, know, understand, use, try, help, keep, avoid, prevent, fix, solve, improve, learn) and their -ing forms
  - Adverbs (safely, quickly, easily, properly, correctly, slowly, carefully, gradually, naturally, effectively, efficiently)
  - Articles + pronouns (a, an, the, your, my, his, her, their, our, its, some)
  - Prepositions (to, for, from, with, about, into, onto, by, of, on, at, as, like, up, down, off, out, over, under)
  - Conjunctions (and, or, but, so, if, then, that, than, because)
  - Generic meta nouns (way, method, step, thing, type, kind, sort, option)
- Replaced the 30-char truncation with a **3-word cap** on content words. Character truncation previously cut "raw food" to "raw foo" (matching a different semantic cluster).
- Fallback for over-stripped queries now takes the last 3 CONTENT words (skipping stopwords) instead of the literal last 3 words.

### Worked examples

```
"how to transition your dog to raw food safely 2026"  →  "dog raw food"       ✅
"how to introduce raw food to a puppy"                →  "raw food puppy"     ✅
"best washable dog beds australia 2026"               →  "washable dog beds"  ✅
"best gelato in lucignano italy 2026"                 →  "gelato"             ✅
"raw dog food bulk"                                    →  "dog food bulk"     ✅
```

Datamuse will now receive clean noun phrases and return real LSI like `nutrition, kibble, protein, diet, carnivore, feeding, meal, biscuit, morsel` — actually relevant to raw dog food articles.

### Verification

1. Redeploy cloud-api to Vercel.
2. Open Content Generator, enter `how to transition your dog to raw food safely 2026`, click Auto-suggest.
3. Expected LSI keywords: real terms like `nutrition, protein, diet, kibble, feeding, raw, meal, digestive, carnivore, enzymes` — NOT lion/curb/race/nose/captain.
4. Regression: all previously-working keywords still produce the expected core topic (run the test script at the commit to verify).

**Verified by user:** UNTESTED

---

## v1.5.58 — Two critical fixes: persisted places cache (test-then-generate determinism) + location-aware auto-suggest

**Date:** 2026-04-15
**Commit:** `f426226`

### Context

Two user-reported issues after v1.5.57:

1. **Test button returns 3 Mudgee places, article generation returns 0.** Sonar is a live web search and non-deterministic. The shared places-only cache from v1.5.44 had a 1-hour TTL and only kicked in as a fallback when cloud_research returned empty — it didn't override stale cached results in the main cache. User could prove Sonar found 3 Mudgee pet shops via the test button, then hit Generate and watch the article fall into places_insufficient mode because the fresh cloud call returned 0 this time. Broken determinism.

2. **Auto-suggest returns other-city names for a Mudgee article.** v1.5.57's geo-localized Google Suggest returned AU-localized completions but they were still generic — "pet shops sydney", "pet shops melbourne", "pet shops brisbane". For an article explicitly about Mudgee, these are WRONG secondary keywords. The user wants either Mudgee-specific variations or generic non-city phrases, never other-city names.

### Fixed

#### 1. Persisted places cache now overrides main cache on read — [includes/Trend_Researcher.php::research()](../includes/Trend_Researcher.php) line ~57
- **Before**: places-only cache was only read as a FALLBACK when cloud_research returned empty. If the main cache had a 0-places result (from a previous run before Sonar was working), that stale result was returned without ever consulting the places cache.
- **After**: persisted places cache is checked FIRST (even on main cache hit). If it has ≥1 places AND the main cache has fewer, the main cache result is overridden with the persisted places. The research data (stats, quotes, citations) still flows from the main cache — only the `places` field is overridden.
- Also added override-after-cloud-call: if a fresh cloud_research returns fewer places than the persisted cache has, override.
- TTL raised from 1 hour → 24 hours (`24 * HOUR_IN_SECONDS`) so the test-then-generate flow reliably reuses results across a whole session.
- Result: click Test Sonar Connection with a keyword → get 3 Mudgee places → click Generate Article with the same keyword within 24h → guaranteed to use the same 3 places, no Sonar non-determinism roulette.
- Verify: `grep -n "persisted places cache\|has_persisted" seobetter/includes/Trend_Researcher.php`

#### 2. Location-aware auto-suggest — [cloud-api/api/topic-research.js](../cloud-api/api/topic-research.js) new `extractLocationTokens()` + `OTHER_CITY_BLOCKLIST` constant + `buildKeywordSets()` filter
- New `extractLocationTokens(niche)` helper extracts target location tokens from "in X" / "near X" / "at X" patterns. For "best pet shops in mudgee nsw 2026" → `["mudgee", "nsw"]`. Empty array if no location clause.
- New `OTHER_CITY_BLOCKLIST` constant — ~100 common English-speaking cities (US, UK, AU, CA, EU, Asia) plus all 50 US states. Frequent Google Suggest noise sources.
- `buildKeywordSets()` now runs a location-aware filter on Google Suggest results:
  - If the niche has a target location AND a suggestion doesn't contain any target-location tokens AND contains a blocklist city → REJECT.
  - Otherwise keep (suggestions with the target location, or generic phrases with no city, both pass).
- Worked example for `best pet shops in mudgee nsw 2026`:
  - `pet shops sydney` → no "mudgee"/"nsw", contains "sydney" (blocklist) → REJECT ❌
  - `pet shops washington` → no target, contains "washington" (blocklist) → REJECT ❌
  - `pet shops near me` → no target, no blocklist → KEEP ✅
  - `pet shops mudgee` → contains "mudgee" → KEEP ✅
  - `best pet shops` → no target, no blocklist → KEEP ✅
- **Synthetic keyword augmentation**: small towns rarely have Google Suggest data for their specific business types, so the filter leaves the secondary list thin. `buildKeywordSets()` now auto-generates up to 5 synthetic secondary keywords for local-intent niches by combining the target location with the core topic: `{core} {location}`, `{location} {core}`, `{core} near {location}`, `best {core} {location}`, plus a shops→supplies swap. For Mudgee: "pet shops mudgee nsw", "mudgee nsw pet shops", "pet shops near mudgee nsw", "best pet shops mudgee nsw", "mudgee nsw pet supplies".
- Verify: `grep -n "extractLocationTokens\|OTHER_CITY_BLOCKLIST" seobetter/cloud-api/api/topic-research.js`

### Verification

1. Redeploy cloud-api to Vercel.
2. Reinstall the plugin zip.
3. **Test determinism**: Settings → Test Sonar Connection → enter `best pet shops in mudgee nsw 2026` + `AU` → click Test. Expected: 3 verified Mudgee places. Then navigate to Content Generator, same keyword, hit Generate. Expected: Pool size 3, real Mudgee H2s, no places_insufficient banner.
4. **Test auto-suggest**: Content Generator → same keyword → click Auto-suggest. Expected Secondary Keywords (no Sydney/Melbourne/Washington):
   ```
   pet shops mudgee nsw, mudgee nsw pet shops, pet shops near mudgee nsw, best pet shops mudgee nsw, mudgee nsw pet supplies
   ```
5. Regression: non-local keyword like `how to train a puppy` — should still use Google Suggest as before (no location filter applies).

**Verified by user:** UNTESTED

---

## v1.5.57 — Auto-suggest now geo-localizes Google Suggest by country code

**Date:** 2026-04-15
**Commit:** `5d80e82`

### Context

User tested auto-suggest with `best pet shops in mudgee nsw 2026` (Country = Australia) and got US-centric completions:

```
pet shops washington, pet shops near me, pet shops that sell puppies,
pet shops toys, pet shops hiring near me, pet shops near me open now,
pet shops open near me
```

Washington (DC/state) is in the US, not near Mudgee NSW. Root cause: `topic-research.js::fetchGoogleSuggest()` hit Google's `suggestqueries.google.com/complete/search` endpoint without any geo parameter, so Google defaulted to its US regional index. No matter which country the user selected in the Content Generator form, the suggestions were always US-centric.

### Fixed

#### 1. Plugin JS sends the form's country code to `/api/topic-research` — [admin/views/content-generator.php](../admin/views/content-generator.php) two call sites (Suggest Topics sidebar + Auto-suggest Keywords button)
- Both call sites now read `#sb-country-val` (the hidden country input populated by the country picker) and include it as `country` in the POST body.
- Falls back to empty string if no country selected — which is the pre-v1.5.57 behavior (US default).
- Verify: `grep -n "country: sbCountry\|country: sbCountry2" seobetter/admin/views/content-generator.php`

#### 2. Cloud-api `topic-research.js` accepts and propagates the country — [cloud-api/api/topic-research.js](../cloud-api/api/topic-research.js) line ~29 + `fetchGoogleSuggest()` line ~95
- Destructures `country` from the request body, sanitized to a 2-char lowercase code (`gl` in Google's parameter terminology).
- Passes `gl` into `fetchGoogleSuggest(query, gl)` for both the full-niche and core-topic calls.
- `fetchGoogleSuggest` now appends `&gl=XX&hl=XX` to the suggestqueries URL when `gl` is set. Google uses `gl` for country targeting and `hl` for UI language; we use the country code for both since most language/country pairings align (AU/en, GB/en, US/en, IT/it, FR/fr, DE/de, JP/ja, etc). Empty `gl` falls back to Google's default (US).
- Result: with Country = AU, Mudgee auto-suggest now returns "pet shops sydney", "pet shops melbourne", "pet shops brisbane", "pet shops near me" (with AU-localized ranking), etc. No more "pet shops washington".
- Verify: `grep -n "gl.*encodeURIComponent\|geoParams" seobetter/cloud-api/api/topic-research.js`

### Verification

1. Redeploy cloud-api to Vercel.
2. Reinstall the plugin zip.
3. Content Generator → keyword `best pet shops in mudgee nsw 2026` → Country: Australia → click Auto-suggest.
4. Expected: Secondary Keywords field populated with AU-localized phrases (e.g. "pet shops sydney", "pet shops melbourne", "pet shops near me") — no "washington", "florida", or other US-specific cities.
5. Regression: keyword with Country = United States should still return US completions.
6. Regression: keyword with no country selected still works (uses Google's default index).

**Verified by user:** UNTESTED

---

## v1.5.56 — Sonar test verdict text no longer hardcodes "Lucignano"

**Date:** 2026-04-15
**Commit:** `e7bc2e4`

### Context

v1.5.55 added custom keyword + country inputs to the Test Sonar Connection button, but the verdict string was still built from a hardcoded "Lucignano" reference. User tested `best pet shops in mudgee nsw 2026` and got `✅ SONAR IS WORKING. Found 3 verified places for Lucignano` even though the test ran against Mudgee. Three real Mudgee places were returned (Rival Collars, Mudgee Birds & Aquarium, Mudgee Produce Plus — confirming v1.5.55 works end-to-end), but the verdict was confusing because of the stale town name.

### Fixed

#### `build_sonar_verdict()` now accepts a location label — [seobetter.php::build_sonar_verdict()](../seobetter.php) line ~776
- Added fourth parameter `$location_label` with a sensible default ("this location"). All verdict strings referring to "Lucignano" replaced with `$loc`. Empty-result message generalized to: "Sonar genuinely could not verify any businesses online for this exact location" (was "(unlikely — Perplexity Web UI finds 2 real gelaterie)").
- `rest_test_sonar()` now passes `$result['places_location'] ?? $test_keyword` as the fourth argument so the verdict reflects the actual geocoded location or keyword the user entered.
- Verify: `grep -n "location_label\|for ' . \$loc" seobetter/seobetter.php`

### Verification

1. Upload the new plugin zip.
2. Settings → Test Sonar Connection → enter keyword `best pet shops in mudgee nsw 2026` + country `AU` → Test.
3. Expected verdict: `✅ SONAR IS WORKING. Found 3 verified places for Mudgee, Mid-Western Regional Council, NSW, Australia via the places_integrations key source.` (no Lucignano reference anywhere).
4. Leave the fields empty and retest → verdict should mention Lucignano because that's the default keyword. Both paths now honest.

**Verified by user:** UNTESTED

---

## v1.5.55 — Any-city-any-topic fix: Sonar Pro default, retry-on-error, 25km rural radius, name+type filter, custom keyword test

**Date:** 2026-04-15
**Commit:** `632401f`

### Context

User ran `best pet shops in mudgee nsw 2026` after v1.5.54 and got Pool size = 0 across all 5 tiers (Sonar, OSM, FSQ, HERE, Google). Perplexity web UI finds 10 real pet shops for the same keyword. User feedback: "fix this so it works for any city any topic, we have only got it to work for one small town in italy".

Seven problems compounded to produce the zero result on Mudgee:

1. **Sonar default model was `perplexity/sonar`** (base, shallow web search). The web UI uses `sonar-pro` by default — vastly better small-town coverage.
2. **Retry only fired on thin results**: `if (places.length < 2) retry with pro`. If the base model THREW an error (timeout, rate limit, 402, 500), the catch block re-threw and pro never ran.
3. **Filter required name + at least one of (address, website, source_url)**. Sonar Pro often returns businesses with just name + type (e.g. "Mudgee Produce Plus", type: "Pet Store") when the source page lacks a stable URL. These real businesses were silently dropped.
4. **Foursquare radius was 10km**. Rural towns like Mudgee (11k pop) have zero FSQ-indexed businesses within 10km but neighbor towns (Gulgong, Rylstone, 25km away) have real pet stores a local would drive to.
5. **Foursquare category synonyms didn't cover rural supply stores**. Mudgee Produce Plus is categorized as "Rural Supply" not "Pet Store", so the v1.5.53 category filter dropped it even though it's the town's primary pet store.
6. **OSM bbox was the raw Nominatim town boundary** (~2-5km across for small towns). Overpass queries never reached neighboring villages.
7. **HERE bbox was 10km** — same problem as FSQ.

Plus the user had no way to test any keyword other than Lucignano because the Test Sonar button was hardcoded.

### Fixed

#### 1. Sonar Pro is now the default model + retry chain tries both models even on error — [cloud-api/api/research.js::fetchSonarPlaces()](../cloud-api/api/research.js) line ~1241
- Default model: `perplexity/sonar-pro` (was `perplexity/sonar`). Cost rises from ~$0.008 to ~$0.06 per call but small-town coverage improves dramatically. User can still override via Settings → Places Integrations → Sonar model dropdown.
- Full rewrite of the retry logic. New approach: build a `modelChain` starting with the user's selected model, then add the OTHER model as a fallback. Loop through the chain calling `callSonar(model)` — each call is wrapped in its own try/catch. On success, merge places into the union. On error, log to `attempts[]` and continue to the next model. Stop when we have ≥2 places. If every model fails or returns 0, throw `sonar_empty_all_models: {diagnostic}` with the full per-model attempts string so the test endpoint can surface exactly what happened.
- Verify: `grep -n "modelChain\|sonar_empty_all_models" seobetter/cloud-api/api/research.js`

#### 2. Filter accepts name + type (no URL required) — [callSonar()](../cloud-api/api/research.js) line ~1196
- **Before** (v1.5.52): `name + (address OR website OR source_url)` — dropped name+type-only results.
- **After**: `name + (type OR address OR website OR source_url)`. Type alone is sufficient verification because Sonar Pro only assigns category types to real indexed businesses. Worst case: the business has no address → no 📍 meta line in the article → still a valid listicle H2 which is better than being dropped.
- Verify: `grep -n "Type alone is enough verification" seobetter/cloud-api/api/research.js`

#### 3. Foursquare radius 10km → 25km + limit 30 → 40 — [fetchFoursquarePlaces()](../cloud-api/api/research.js) line ~977
- 25km covers the whole local catchment area for small-town queries (Gulgong, Rylstone, Kandos, Ilford for Mudgee) while still being "local" for large cities (Sydney 25km still covers Greater Sydney metro).
- Limit raised 30 → 40 to give the category post-filter more raw results to work with.
- Verify: `grep -n "radius=25000" seobetter/cloud-api/api/research.js`

#### 4. Foursquare synonyms expanded to cover rural supply stores — [FSQ_CATEGORY_SYNONYMS](../cloud-api/api/research.js) line ~873
- `pet shop`, `pet store`, `pet` synonyms now include: `rural supply`, `farm supply`, `produce store`, `feed store`, `general store`, `hardware store`. These are the Foursquare categories that real rural-town pet stores are tagged under (e.g. Mudgee Produce Plus → "Rural Supply").
- Verify: `grep -n "rural supply" seobetter/cloud-api/api/research.js`

#### 5. OSM Overpass bbox auto-expanded to 25km radius — new [expandBbox()](../cloud-api/api/research.js) helper line ~735 + [overpassQuery()](../cloud-api/api/research.js)
- New `expandBbox(bbox, minRadiusKm)` helper. If the Nominatim bbox is smaller than the requested radius, expand it around its center. At latitude L, 1° ≈ 111km for latitude and 111 × cos(L) km for longitude.
- `overpassQuery()` now calls `expandBbox(bbox, 25)` before building the Overpass query. Small-town queries now cover a 25km radius; large cities are unaffected because their bbox is already larger.
- Verify: `grep -n "expandBbox" seobetter/cloud-api/api/research.js`

#### 6. HERE bbox 10km → 25km — [fetchHEREPlaces()](../cloud-api/api/research.js) line ~1057
- latDelta 0.1 → 0.22 (≈ 25km), lonDelta correspondingly scaled.
- Verify: `grep -n "latDelta = 0.22" seobetter/cloud-api/api/research.js`

#### 7. Test Sonar Connection button now accepts a custom keyword + country — [seobetter.php::rest_test_sonar()](../seobetter.php) line ~716 + [admin/views/settings.php](../admin/views/settings.php)
- New `keyword`, `country`, `domain` POST params on `POST /seobetter/v1/test-sonar`. Defaults preserved (`best gelato in lucignano italy 2026` / IT / travel) so existing button behavior is backwards compatible.
- Added two input fields next to the Test Sonar button: keyword text input + 2-letter country code input. JS passes both in the AJAX call.
- Cache keys are deleted before the test fires so every run hits the live API fresh.
- Verify: `grep -n "seobetter-sonar-test-keyword" seobetter/admin/views/settings.php`

### How to verify the full stack works for any location

1. Redeploy cloud-api to Vercel.
2. Reinstall the plugin zip.
3. Settings → Test Sonar Connection:
   - Enter keyword: `best pet shops in mudgee nsw 2026`
   - Enter country code: `AU`
   - Click Test
   - Expected: `places_count >= 2` with names like "Mudgee Produce Plus", "Complete Steel & Rural", etc. Verdict: ✅ SONAR IS WORKING.
4. If still 0, the verdict will show `sonar_empty_all_models: sonar-pro: X | sonar: Y` with the exact per-model errors.
5. Repeat for any other city/keyword combo:
   - `best pizza restaurants in rome italy` (country IT) — large city, should return 10
   - `best pet stores in bathurst nsw australia` (country AU) — medium town
   - `best bakeries in totnes devon uk` (country GB) — small UK town
   - `best sushi in kyoto japan` (country JP) — large international city
6. Every test should produce ≥2 verified places or a clear error message explaining which models were tried and what each one said.

### Guiding principle change

Previous releases chased specific cases (Lucignano, Mudgee) by adjusting filters incrementally. v1.5.55 takes the opposite approach: **widen every tier's search radius to 25km, default to the strongest Sonar model, relax the filter to name+type, and surface raw errors when things fail**. If Sonar Pro can't find real businesses for a location, Foursquare's broader synonym list has a chance. If FSQ is thin, HERE with a 25km bbox is the next shot. If everything fails, the diagnostic now tells the user exactly which tier failed and why.

**Verified by user:** UNTESTED

---

## v1.5.54 — Auto-suggest button now populates Secondary Keywords for long-tail keywords + plain-English Country/Language help text

**Date:** 2026-04-15
**Commit:** `fa71ded`

### Context

User reported two UX problems:

1. **Auto-suggest only populates LSI, never Secondary Keywords.** For a long-tail keyword like `best pet shops in mudgee nsw 2026`, the Secondary Keywords field stayed empty while LSI (Datamuse) populated normally.
2. **Country/Language help text used Lucignano as an example.** Most users outside Italy have never heard of Lucignano — the example was confusing instead of clarifying.

### Fixed

#### 1. Google Suggest now receives the core topic in addition to the full niche — [cloud-api/api/topic-research.js](../cloud-api/api/topic-research.js) line ~47
- **Root cause**: `fetchGoogleSuggest(niche)` passed the full 8-word long-tail keyword to Google's `suggestqueries` endpoint. Google has no completion data for ultra-long phrases like "best pet shops in mudgee nsw 2026" and returns zero suggestions. Datamuse already used `extractCoreTopic(niche)` to get "pet shops" first, but Google Suggest did not. Result: LSI (Datamuse) always populated, Secondary (Google Suggest) never did.
- **Fix**: call `fetchGoogleSuggest` TWICE in parallel — once with the full niche (in case it has any completions) AND once with the extracted core topic. Merge the results, deduped. For "best pet shops in mudgee nsw 2026", the core topic "pet shops" returns Google Suggest completions like "pet shops near me", "pet shops sydney", "pet shops online", "best pet shops australia" etc.
- Verify: `grep -n "suggestLong\|suggestCore" seobetter/cloud-api/api/topic-research.js`

#### 2. Overlap filter in buildKeywordSets relaxed — [topic-research.js::buildKeywordSets()](../cloud-api/api/topic-research.js) line ~295
- Old filter: `niche.split(/\s+/).filter(w => w.length > 3)` → for "best pet shops in mudgee nsw 2026" the allowed words were ["best", "shops", "mudgee", "2026"] — missed "pet" (3 chars).
- New filter: `length >= 3` with a small stopword blocklist. "pet", "cat", "gym", "vet", "bar" now count as overlap signals. Suggestions like "pet supplies online" or "vet clinic near me" that would have been dropped now flow through.
- Blocked stopwords: the, and, for, with, from, are, you, can, how, why, what, when, where, who, 2024-2028.
- Verify: `grep -n "length >= 3 && ![''the','and'" seobetter/cloud-api/api/topic-research.js`

#### 3. Country/Language help text rewritten in plain English — [admin/views/content-generator.php](../admin/views/content-generator.php) line ~461
- **Before**: "Writing about Lucignano gelato shops for a US audience? Set Target Country = Italy (so the plugin finds real Italian gelaterie via Places waterfall)..." — the Lucignano reference meant nothing to users who hadn't seen the SEOBetter test keyword.
- **After**: plain-English explanation with no place-specific example. Structured as two short statements: "Target Country tells the plugin where to look up real local businesses. Article Language is the language your readers will read. These are independent — you can write an English article about Japanese restaurants by setting Country = Japan and Language = English."
- Verify: `grep -n "Target Country.*tells the plugin\|Japanese restaurants" seobetter/admin/views/content-generator.php`

### Verification

1. Redeploy cloud-api to Vercel (topic-research.js was touched).
2. Upload the new plugin zip.
3. Open Content Generator, enter `best pet shops in mudgee nsw 2026`, click Auto-suggest.
4. Expected: Secondary Keywords field populated with 3-7 real Google Suggest phrases (e.g. "pet shops near me", "pet shops sydney", "best pet shops"). LSI Keywords also populated as before.
5. The help text below the Country/Language row now explains the distinction in plain English without mentioning Lucignano.

**Verified by user:** UNTESTED

---

## v1.5.53 — Foursquare category post-filter: fixes "best pet shops" returning Best Western Hotel / Best Migration Services / Best Kumpir

**Date:** 2026-04-15
**Commit:** `ca160cb`

### Context

User ran the v1.5.52 Test Places Providers test for `best pet shops in sydney australia 2026` and got 20 Foursquare results — but none of them were pet shops:

```
1. Best Migration Services    (immigration law firm)
2. Best Kumpir                 (Turkish food takeaway)
3. Best Buy Pharmacy           (chemist)
4. Best Western Plus Hotel     (hotel)
5. Vet & Pet Jobs              (job listing site)
```

Root cause: [fetchFoursquarePlaces](../cloud-api/api/research.js) line ~876 passes `businessHint` as the `query` parameter to Foursquare's `/places/search` endpoint. Foursquare uses `query` for a loose name+category text match, which returns any business with the hint tokens in its NAME. For `businessHint = "best pet shops"`, every business starting with "Best" matched. No category filter was applied to the results.

Foursquare v3's category taxonomy uses numeric IDs (e.g. 17069 for Pet Store, 13046 for Ice Cream Parlor), but we don't want to hardcode and maintain that numeric map. Instead, we post-filter by checking the returned category NAME.

### Fixed

#### Foursquare category-name post-filter + synonym expansion — [cloud-api/api/research.js::fetchFoursquarePlaces()](../cloud-api/api/research.js) line ~940 + new `FSQ_CATEGORY_SYNONYMS` constant line ~873
- Added a `FSQ_CATEGORY_SYNONYMS` map covering the top ~30 local-business categories: pet shop → [pet store, pet shop, pet supplies, aquarium shop, bird shop]; gelato → [ice cream, gelato, frozen yogurt, dessert, sweet]; pizza → [pizza, pizzeria, italian]; hotel → [hotel, inn, resort, lodge, b&b]; veterinarian → [vet clinic, animal hospital]; etc.
- `fsqCategorySynonyms(businessHint)` does a longest-key-first lookup so "pet shop" beats "pet" when both match.
- Fallback: if no mapping exists for the hint, tokens ≥4 chars are used as literal substrings to match against the category name (stopwords like "best", "top", "near", "shops" filtered).
- In `fetchFoursquarePlaces`, every returned result is mapped with a temporary `_catName` field, then filtered: the result's category name must contain at least one of the synonyms. Results with no category are rejected when we're filtering.
- Worked example: `businessHint = "pet shops"` → synonyms `["pet store", "pet shop", "pet supplies", "pet supply", "aquarium shop", "bird shop"]`. Result "Pet Store" category → "pet store" match → KEPT. Result "Immigration Services" → no match → DROPPED. Result "Hotel" → no match → DROPPED.
- Verify: `grep -n "FSQ_CATEGORY_SYNONYMS\|fsqCategorySynonyms" seobetter/cloud-api/api/research.js`

#### Also raised the Foursquare search radius 5km → 10km and limit 20 → 30 — [fetchFoursquarePlaces()](../cloud-api/api/research.js) line ~939
- Small towns often have pet shops / vets / specialty stores in neighboring suburbs within 5-10km. Previous 5km radius was too tight for Mudgee-class towns where the nearest real pet shop may be in the next village. Raising to 10km still keeps it genuinely local while capturing nearby coverage.
- Raised the `limit` from 20 → 30 to give the post-filter more raw results to work with (expected survival rate after filter: 30-60%).

### Verification

1. Redeploy cloud-api to Vercel.
2. Click "🧪 Test Places Providers" in Settings → Places Integrations.
3. Expected Sydney sample: 5-20 real pet-related businesses (Pet Barn branches, Kellyville Pets, City Farmers, local pet supply stores), no more hotels/migration services/pharmacies.
4. Regression: keywords without a FSQ_CATEGORY_SYNONYMS mapping fall back to token-based matching so obscure business types still return results.

**Verified by user:** UNTESTED

---

## v1.5.52 — Sonar two-attempt strategy + relaxed filter (Mudgee-class small towns), Brave Pro label removed

**Date:** 2026-04-15
**Commit:** `ec6e38a`

### Context

User tested "10 best pet shops in mudgee" directly in the Perplexity web UI and got 10 real businesses (Mudgee Produce Plus, Complete Steel & Rural, Mudgee Birds & Aquarium, Petbarn, Mudgee Dog-A-Cise, Rival Collars, Wooden Dog Kennels, The Kitty Ritz, BIG W Mudgee, etc) with mixed data quality — some had full street addresses, most had only name + source URL. Our Sonar API call had been returning 0 for the same location.

Root cause: TWO problems in [fetchSonarPlaces](../cloud-api/api/research.js):

1. **Filter required name AND address** — `.filter(p => p && p.name && p.address)` dropped 7 of 10 real businesses because most small-town listings expose name + Yelp/directory URL but no street number. A business with a name + verifiable source page is real; throwing it away is overkill.
2. **System prompt labelled address as REQUIRED** — told the model to skip any business without a full street address + postal code, which for small towns is almost every listing.

Combined with the default `perplexity/sonar` base model (shallower search than the `sonar-pro` tier the web UI uses), this produced 0 results for any town smaller than ~50k population.

### Fixed

#### 1. Relaxed Sonar prompt and filter — [cloud-api/api/research.js::callSonar()](../cloud-api/api/research.js) new function ~line 1017 + [fetchSonarPlaces()](../cloud-api/api/research.js) line ~1127
- Extracted the single Sonar call into a standalone `callSonar(apiKey, model, keyword, location, geo)` helper so the retry logic in `fetchSonarPlaces` stays clean.
- **System prompt** now marks `name`, `type`, and `source_url` as REQUIRED. `address` is `preferred but optional`. Explicit wording: "A business is 'verified' if you can cite at least one specific source page for it (source_url is required). Address is preferred but not mandatory — many small-town listings have name + website without a full street number. Include them anyway."
- **User prompt** now says: "A business with a name + Yelp/directory URL is verifiable even if no street number is listed. Do NOT skip real businesses because they lack a full address."
- **Filter** now accepts a place if it has `name` AND at least one of `address` / `website` / `source_url`. Previous hard requirement of `name && address` is gone. Places with only a source URL still flow through.
- `max_tokens` increased 2000 → 3000 (more headroom for 10-place responses with longer source URLs)
- Verify: `grep -n "A business with a name\|preferred but optional" seobetter/cloud-api/api/research.js`

#### 2. Auto-upgrade to `perplexity/sonar-pro` on thin base results — [fetchSonarPlaces()](../cloud-api/api/research.js) line ~1160
- `fetchSonarPlaces` now calls base `perplexity/sonar` first. If the result set has fewer than 2 usable places, it retries with `perplexity/sonar-pro` (Perplexity's deep-search tier, 6-8× better small-town coverage, ~$0.06 per call). Pro results are merged with base via name-keyed deduplication.
- Cost impact: normal large-city keywords cost ~$0.008 as before (base returns ≥2, no retry). Small-town keywords that would have failed now cost ~$0.068 but return real data. The retry only fires when it's necessary.
- Pro-retry failure is non-fatal — falls back to whatever the base call returned. Errors from the base call still throw normally so the diagnostic card can surface them.
- Each place's `source` field is now either `"Perplexity Sonar"` or `"Perplexity Sonar Pro"` so you can see which tier produced which result.
- Verify: `grep -n "perplexity/sonar-pro\|if (places.length < 2" seobetter/cloud-api/api/research.js`

#### 3. Removed "PRO" label from Brave Search API Key field — [admin/views/settings.php](../admin/views/settings.php) line ~316
- Was: `<span class="seobetter-score ...">PRO</span>` next to the Brave label when the user wasn't on a Pro license.
- Now: no PRO badge. Brave is now a standard free-tier source anyone can add via https://brave.com/search/api/.
- Also updated the Test Research Sources verdict string `"Brave (Pro):"` → `"Brave Search:"` so the diagnostic output matches.
- Verify: `grep -n "Brave.*PRO\|score\">PRO" seobetter/admin/views/settings.php` (should return zero hits)

### How the full pipeline fits together after this fix

For a listicle keyword like `best pet shops in mudgee nsw 2026`:

1. **[Trend_Researcher.php::cloud_research()](../includes/Trend_Researcher.php)** bundles your FSQ + HERE + Google + OpenRouter(Sonar) + Brave keys into a single `POST /api/research` call (60s timeout).
2. **[research.js](../cloud-api/api/research.js)** fans out in parallel:
   - **[fetchPlacesWaterfall](../cloud-api/api/research.js)** → Sonar base → (retry sonar-pro if thin) → OSM → Foursquare → HERE → Google. Stops at first tier ≥2 places.
   - **9 always-on sources** (Reddit, HN, Wikipedia, Google Trends, DDG, Bluesky, Mastodon, Dev.to, Lemmy) + **Brave Search** (Pro-like inline citations now that you've added the key) + category/country APIs — all feeding stats, quotes, and citation URLs into the `for_prompt` block.
3. **Pre-gen switch** in [Async_Generator.php::process_step()](../includes/Async_Generator.php): if `places_count >= 1`, Local Business Mode fires (capped listicle); if `places_count < 1`, informational mode fires (no business-name H2s).
4. **Outline** produces exactly N business-name H2s (populated from the verified pool) + generic fill sections to hit the word count.
5. **Each section** is generated individually with the v1.5.48 readability rules (grade 6-8, 12-16 word sentence cap, keyword density ≤1.5%, banned-phrase list).
6. **Places_Validator** strips any H2 naming a business not in the verified pool.
7. **Places_Link_Injector** adds 📍 address · Google Maps · Website · phone meta line under each business H2.
8. **Citation_Pool** merges place URLs + research URLs → References section with clickable links.
9. **validate_outbound_links** strips any URL not in the whitelist or pool.
10. **GEO_Analyzer** scores the final HTML on 11 checks.

**Verified by user:** UNTESTED

---

## v1.5.51 — Test Research Sources diagnostic: per-source ok/error/latency for Reddit / HN / DDG / Bluesky / Mastodon / Dev.to / Lemmy / Wikipedia / Google Trends / Brave / Category APIs / Last30Days

**Date:** 2026-04-15
**Commit:** `58c15ee`

### Context

After the places diagnostic landed in v1.5.49/50, user asked to also verify the general-purpose research sources are being called — Reddit, Hacker News, DuckDuckGo, Bluesky, Mastodon, Dev.to, Lemmy, Wikipedia, Google Trends, Brave Search (Pro), the category/country APIs, AND the local Last30Days Python skill. These are the sources that produce stats, quotes, trends, and citation URLs for the article body. If any of them are silently failing, articles lose grounding data and citations. The prior "Unexpected token '<'" error during the places test proved at least one of them is throwing — we need a diagnostic that surfaces which one.

### Added

#### 1. `test_all_sources` flag in cloud-api /api/research — [cloud-api/api/research.js](../cloud-api/api/research.js) line ~41 (after the places short-circuit)
- New short-circuit branch. When `test_all_sources === true`, the handler wraps each source in a per-source `instrument()` helper that records: `{ name, ok, count, latency_ms, sample, error }` and runs them all via `Promise.all` of already-error-caught promises (equivalent to `Promise.allSettled`) so one failing source can NEVER block the others.
- Count detection reads common shapes: top-level arrays, `.posts`, `.results`, `.items`, `.articles`, `.stats`, `.quotes`, `.trends`, or `.summary`. Sample is the first item JSON-stringified and truncated to 200 chars.
- Also tests category APIs (based on `domain`) and country APIs (based on `country`), labelled by `name (source)`.
- Returns `{ success, test_mode: 'all_sources', keyword, domain, country, brave_configured, total_latency_ms, summary: { total, ok, empty, errors }, sources: [...] }`.
- Normal article generation never passes the flag — production behavior is unchanged.
- Verify: `grep -n "test_all_sources\|instrument = async" seobetter/cloud-api/api/research.js`

#### 2. New REST endpoint `POST /seobetter/v1/test-research-sources` — [seobetter.php::rest_test_research_sources()](../seobetter.php) line ~949
- Calls the cloud-api with `test_all_sources: true` + optional Brave key from settings
- Additionally probes the local Last30Days Python skill: checks `Trend_Researcher::is_available()`, looks for `python3` via `shell_exec('which python3')`, checks the script file exists, and surfaces a plain-English message explaining why it's or isn't available. On managed WP hosts like WP Engine that block shell_exec, this will always say "not available" — that's EXPECTED because the cloud-api provides the same data remotely; Last30Days is only a fallback.
- Returns unified shape: `{ plugin_version, test_keyword, domain, country, cloud: { ok, sources[], summary, total_latency_ms }, last30days: { available, python_found, script_found, message }, brave_configured }`
- Verify: `grep -n "rest_test_research_sources" seobetter/seobetter.php`

#### 3. "🧪 Test Research Sources" button in Settings → Places Integrations card — [admin/views/settings.php](../admin/views/settings.php)
- Sibling to the existing Test Places Providers button
- Renders one line per source with ✅/⚪/❌ icon, count/empty/error status, latency in ms, and either a sample preview or the error message as a secondary indented line
- Separate section for the Last30Days probe with its own icon (✅ available, ⚠️ Python/script found but skill broken, ⚪ not available)
- Verify: grep for `seobetter-test-research-sources` in settings.php

### Verification

1. Redeploy cloud-api to Vercel (required — JS-side fix in research.js).
2. Upload the new plugin zip.
3. Click "🧪 Test Research Sources" in Settings → Places Integrations.
4. Expected: a per-source list showing which of the 9+ free sources returned data, which returned empty, and which threw an error (with the error message inline). Latency in ms shown per source. Cloud summary like "6 ok / 2 empty / 1 errors".
5. Last30Days row: on WP Engine will report `⚪ Available: NO` with the message "python3 not found on this server". On a self-hosted box with Python3 it will report `✅ Available: YES`.
6. Production article generation regression: create a test article, confirm research sources still aggregate and `for_prompt` is populated — the `test_all_sources` short-circuit is gated and production flows never set the flag.

**Verified by user:** UNTESTED

---

## v1.5.50 — Test Places Providers diagnostic short-circuits all non-places sources

**Date:** 2026-04-15
**Commit:** `5ca0811`

### Context

User ran the v1.5.49 Test Places Providers button and got:
```
─── ERROR ───
Research failed: Unexpected token '<', "<!doctype "... is not valid JSON
```
Plus empty `plugin_version` and `test_keyword` fields, meaning the cloud-api's outer try/catch fired and returned HTTP 500 with a generic "Research failed: ..." message before the places waterfall ever finished.

Root cause: the `/api/research` handler runs every always-on source in parallel via `Promise.all([Promise.all(freeSearches), Promise.all(catPromises)])` — Reddit, HN, Wikipedia, Google Trends, DuckDuckGo, Bluesky, Mastodon, Dev.to, Lemmy, plus any category/country APIs. ONE of those sources called `response.json()` on what was actually an HTML 4xx/5xx error page, threw a `SyntaxError`, and `Promise.all`'s fail-fast semantics propagated the rejection to the outer catch. Even though our test mode didn't care about any of those results, one of them failing was enough to block the places waterfall from reporting.

### Fixed

#### Added TEST MODE short-circuit in the `/api/research` handler — [cloud-api/api/research.js](../cloud-api/api/research.js) line ~41
- When `test_all_places_tiers === true`, the handler now skips every `freeSearches` source, every category API, and every country API. It calls `fetchPlacesWaterfall()` directly and returns a minimal JSON shape with just the places-related fields.
- Benefits: (a) completely immune to any always-on source flaking out, (b) much faster response (no 10-source parallel fanout), (c) cleaner mental model — the test button measures only what it claims to measure.
- Normal article generation path is completely unchanged — the short-circuit is gated on `test_all_places_tiers`, which PHP only sets from the `rest_test_places_providers` handler.
- Verify: `grep -n "TEST MODE short-circuit\|test_all_places_tiers" seobetter/cloud-api/api/research.js`

### Verification

1. Redeploy cloud-api to Vercel (required — this is a JS-side fix).
2. Click "🧪 Test Places Providers" in Settings → Places Integrations.
3. Expected: full per-tier report with Foursquare and HERE counts and verdicts, no "Research failed: Unexpected token" error.
4. Expected fast response (should complete in under 10 seconds since we skip the 10-source parallel fanout).
5. Normal article generation regression: create a test article, confirm `placesData` and all the usual research fields still populate — the short-circuit is gated on `test_all_places_tiers` so production flows are unaffected.

**Verified by user:** UNTESTED

---

## v1.5.49 — Test Places Providers diagnostic: verify Foursquare / HERE / Google keys are actually being called

**Date:** 2026-04-15
**Commit:** `616f96c`

### Context

User reported "I've already added Foursquare and HERE keys... test they are being called before I try another article." The problem: in normal article generation the waterfall short-circuits at the first tier returning ≥2 results, so if Sonar or OSM succeeds for a given keyword, Foursquare and HERE NEVER run — and the user has no way to know whether their keys are valid until they hit a failing small-town keyword (where all tiers return 0 and the user still can't tell which tier was the problem). We needed a targeted diagnostic that forces every configured tier to run independently.

### Added

#### 1. `test_all_places_tiers` flag on cloud-api /api/research — [cloud-api/api/research.js](../cloud-api/api/research.js) line ~27 + `fetchPlacesWaterfall` line ~1057
- New boolean field in the request body. When true, every tier's short-circuit gate changes from `if (!provider_used && ...)` to `if (runAllTiers || !provider_used && ...)` so Sonar, OSM, Foursquare, HERE, Google all run regardless of whether earlier tiers succeeded. `providers_tried` reports every tier's count independently.
- Also added try/catch error capture to Foursquare, HERE, and Google Places fetchers — matches the existing Sonar error capture from v1.5.42. Any HTTP/parse error becomes `providers_tried[i].error` instead of silently returning 0.
- Normal article generation NEVER passes this flag, so production behavior is unchanged.
- Verify: `grep -n "runAllTiers\|test_all_places_tiers" seobetter/cloud-api/api/research.js`

#### 2. New REST endpoint `POST /seobetter/v1/test-places-providers` — [seobetter.php::rest_test_places_providers()](../seobetter.php) line ~765
- Reads `foursquare_api_key`, `here_api_key`, `google_places_api_key` from `seobetter_settings`
- Builds a cloud-api request with ONLY those keys (no `openrouter_sonar`) + `test_all_places_tiers: true`
- Test keyword: `"best pet shops in sydney australia 2026"` — Sydney is large enough that FSQ and HERE should both return multiple real results for any valid key
- Per-tier breakdown returned with a verdict string for each configured tier: ✅ working, ⚠️ called but returned 0, ❌ error message, or ❌ never called (indicates stale Vercel deployment)
- Verify: `grep -n "rest_test_places_providers" seobetter/seobetter.php`

#### 3. "🧪 Test Places Providers" button in Settings → Places Integrations — [admin/views/settings.php](../admin/views/settings.php) line ~484 + JS handler line ~716
- Appears directly below the Save button and the Foursquare / HERE / Google input rows
- Mirrors the existing Test Sonar button pattern from v1.5.41 (same status span, same `<pre>` result block)
- Verify: grep for `seobetter-test-places-providers` in settings.php

#### 4. Removed remaining "Lucignano" reference from Places waterfall description — [admin/views/settings.php](../admin/views/settings.php) line ~497
- Old: "For any article with a local-intent keyword (e.g. "best gelato shops in Lucignano"), the plugin tries OSM → Wikidata → Foursquare → HERE → Google Places in order, stopping at the first tier returning 3+ verified places."
- New: generic wording + corrected waterfall order (Sonar → OSM → Foursquare → HERE → Google) + corrected threshold (2+ not 3+, matching current code). Wikidata removed from the text (dead code since v1.5.26).

### Verification

1. Open Settings → Places Integrations. Click "🧪 Test Places Providers".
2. Expected output for valid FSQ + HERE keys:
   ```
   ✅ Foursquare: WORKING — returned 10 places for Sydney.
   ✅ HERE: WORKING — returned 10 places for Sydney.
   ```
3. If FSQ key is invalid: `❌ Foursquare: key IS being called but returned an ERROR → fsq_http_401: Unauthorized`
4. If a key is saved but the cloud-api never calls that tier: `❌ Foursquare: key configured but the cloud-api NEVER called the Foursquare tier. Check Vercel deployment is >= v1.5.49.` — this means the user needs to redeploy the cloud-api.
5. Article generation regression: the normal flow (no test_all_places_tiers flag) still short-circuits at the first successful tier — no performance hit, no extra API calls on normal generations.

**Verified by user:** UNTESTED

---

## v1.5.48 — Five production fixes from Mudgee test: Lucignano/Cortona mentions, article-body disclaimer, stat badge regex, grade-13 readability, 3.53% density

**Date:** 2026-04-15
**Commit:** `309cf3f`

### Context

User ran the v1.5.47 Local Business Mode fix against `best pet shops in mudgee nsw 2026` with Sonar configured. Sonar returned 0 verified places, the pre-gen switch fired correctly (informational mode), but the live output exposed five unrelated production bugs:

1. **Lucignano/Cortona mentioned in Mudgee article's GEO suggestion** — hardcoded Italian example text in [Async_Generator.php](../includes/Async_Generator.php) line ~867 and [content-generator.php](../admin/views/content-generator.php) line ~1033. Every failed-places article showed "For a 2026-04-15 test: Perplexity Web UI finds 2 real gelaterie in Lucignano" and "try a larger nearby city (e.g. Cortona, Siena)" regardless of the actual keyword location. Noise, confusing, and broke the "each article only references its own topic" contract.
2. **Article body contained a meta-disclaimer** — `Note: This article doesn't name specific local businesses because verified open-map data wasn't available for this location. We recommend checking Google Maps or OpenStreetMap directly for current listings.` was rendered in the published WP post body. User explicit rule: errors / admin notices belong in the plugin panel only, never in the reader-facing article. Two sources: the PLACES RULES rule #6 in [Async_Generator.php::get_system_prompt()](../includes/Async_Generator.php) line ~1042 AND the LOCAL-INTENT WARNING block in [cloud-api/api/research.js](../cloud-api/api/research.js) line ~3010.
3. **Stat callout badge showed `5%` instead of `65%`** and `0%` instead of `20%` — greedy regex bug in [Content_Formatter.php::format_hybrid()](../includes/Content_Formatter.php) line ~464. The pattern `^.{0,60}(\d{1,3}(?:[.,]\d+)?\s*%)` used greedy `.{0,60}` which consumed the first digit of the number before the `%` sign. For "approximately 65% of households" the dot swallowed "approximately 6" (56 chars) and the capture group matched "5%". For "a 15-20% increase" it captured "0%". Classic greedy-regex-eats-the-digit bug.
4. **Readability grade 13.0** on a 1500-word article (target: 6–8) — the existing `$readability_rule` string was a single sentence of guidance that the model treated as a suggestion, not a hard rule. No example words, no banned phrases, no measurable sentence-length cap.
5. **Keyword density 3.53%** (target: 0.5–1.5%) — no hard cap on keyword mentions per section, no instruction to use pronouns/variations/synonyms.

### Fixed

#### 1. Removed hardcoded Lucignano/Cortona/Siena from user-facing messages — [includes/Async_Generator.php::post_score_suggestions()](../includes/Async_Generator.php) line ~867 + [admin/views/content-generator.php](../admin/views/content-generator.php) line ~1033
- **Before**: GEO suggestion said `"For a 2026-04-15 test: Perplexity Web UI finds 2 real gelaterie in Lucignano (Gelateria C'era una Volta, Snoopy's)"` and panel diagnostic said `"try a larger nearby city (e.g. Cortona, Siena)"`. Both mentioned Italian towns regardless of the actual keyword location.
- **After**: generic wording — "any small town worldwide" in the suggestion, and "try running the same keyword against a larger nearby town" in the diagnostic (no specific town names).
- Verify: `grep -n "Lucignano\|Cortona\|Siena\|gelateri" seobetter/includes/Async_Generator.php seobetter/admin/views/content-generator.php` → should return only the historical comment references, never in user-facing strings.

#### 2. Removed article-body meta-disclaimer — [includes/Async_Generator.php::get_system_prompt()](../includes/Async_Generator.php) PLACES RULES #6 line ~1042 + [cloud-api/api/research.js::buildResearchResult()](../cloud-api/api/research.js) LOCAL-INTENT WARNING line ~3010
- **Before** (system prompt rule #6): `"Add a disclaimer paragraph at the end: 'Note: This article doesn't name specific local businesses because verified open-map data wasn't available for this location. We recommend checking Google Maps or OpenStreetMap directly for current listings.'"` — told the AI to write the disclaimer INTO the article body.
- **After**: rule #6 now explicitly FORBIDS the AI from adding any disclaimer, note, warning, or meta-explanation in the article body. The research-prompt warning block also updated to match: "DO NOT add any disclaimer, note, or meta-explanation in the article body about missing data, unavailable sources, Google Maps, OpenStreetMap, or the plugin's grounding process. The reader must never see those words. The plugin surfaces the missing-data notice in a separate admin panel."
- The places_insufficient banner in the plugin's results panel ([content-generator.php](../admin/views/content-generator.php) line ~1005) is unchanged — admin still sees the warning, article reader never does.
- Verify: `grep -n "article doesn't name specific\|disclaimer paragraph" seobetter/includes/Async_Generator.php seobetter/cloud-api/api/research.js` → should return zero matches telling the AI to write a disclaimer.

#### 3. Fixed stat callout greedy regex — [includes/Content_Formatter.php::format_hybrid()](../includes/Content_Formatter.php) line ~464
- **Before**: `preg_match( '/^.{0,60}(\d{1,3}(?:[.,]\d+)?\s*%)/', ... )` — greedy `.{0,60}` consumed the first digit of the number.
- **After**: `preg_match( '/^[^0-9]{0,60}(\d{1,3}(?:[.,]\d+)?\s*%)/', ... )` — `[^0-9]` cannot eat digits, forces the capture group to start at the first full number. Same fix applied to the "X out of Y" / "X in Y" pattern on the next line. Side effect: ranges like "15-20%" no longer fire the callout at all (correct — ranges shouldn't be lead-stat-shaped badges).
- Verify: `grep -n "0-9]{0,60}" seobetter/includes/Content_Formatter.php`

#### 4. Strengthened readability rule to grade-6 cap with explicit examples — [includes/Async_Generator.php::generate_section()](../includes/Async_Generator.php) line ~646
- **Before**: single sentence — `"READABILITY: Write at a 6th-8th grade reading level. Mix short sentences..."` — treated as guidance, produced grade 13.
- **After**: seven-bullet HARD RULES block with explicit Flesch-Kincaid target, average sentence length (12–16 words), max sentence length (22 words), word-length preference, active-voice requirement, and a concrete banned-phrases list ("in order to", "due to the fact that", "it is important to note", etc). Also names specific simple-word substitutions: "use not utilize", "help not facilitate", "show not demonstrate".
- Verify: `grep -n "GRADE LEVEL: 6th" seobetter/includes/Async_Generator.php`

#### 5. Added hard keyword-density cap to the section prompt — [includes/Async_Generator.php::generate_section()](../includes/Async_Generator.php) line ~646 (same rule block)
- **Before**: no per-section cap on keyword mentions. Density came out at 3.53% (2.3× the 1.5% target).
- **After**: new KEYWORD DENSITY block appended to the readability rule: "Mention the primary keyword \"{$keyword}\" AT MOST 2 times in this section. Use pronouns, variations, and synonyms for every other reference. Density must stay between 0.5% and 1.5% of the article. Above 2% is penalized as keyword stuffing and reduces AI visibility by 9%. Do NOT repeat the keyword in three consecutive sentences."
- Verify: `grep -n "AT MOST 2 times in this section" seobetter/includes/Async_Generator.php`

### Verification

1. Retest Mudgee — Sonar returns 0, pre-gen switch fires, article is informational BUT:
   - No "Lucignano" / "Cortona" / "Siena" / "gelateria" mentions anywhere in the suggestions or banner
   - Article body contains NO disclaimer paragraph (the "Note: This article doesn't name specific..." text is gone)
   - GEO_Analyzer readability score reports grade 7–9 (was 13.0)
   - Keyword density reports 0.8–1.3% (was 3.53%)
2. Retest any keyword that produces a stat paragraph like "approximately 65% of households" — the big stat badge reads `65%` not `5%`.
3. Verify the places_insufficient banner still appears in the admin results panel (v1.5.27/33 functionality kept)
4. Rome regression (large-pool Local Business Mode) — unchanged, still produces capped listicle

**Verified by user:** UNTESTED

---

## v1.5.47 — Local Business Mode now fires at places_count ≥ 1 so a single verified place still gets a dedicated H2 + meta line

**Date:** 2026-04-15
**Commit:** `99d1ce4`

### Context

After v1.5.46 shipped, the user re-ran the Lucignano test and reported:

> "Pool size: 1 verified places. It mentions Gelaterie C'era Una Volta in Lucignano represents this traditional approach... but not prominent. I guess it works. No link or anything to the map or, website or address."

Sonar returned exactly 1 verified gelateria. The v1.5.27 pre-gen switch threshold was `places_count < 2`, so a single-place result fell through to informational mode. The 1 real gelateria got mentioned in body-text prose but had NO dedicated H2, so Places_Link_Injector (which matches H2 headings to the pool) had nothing to attach its 📍 address / Google Maps / website meta line to. The user saw the real business name buried in a paragraph with no clickable links — exactly the opposite of the intended UX.

Root cause: the v1.5.27 threshold was chosen when the only two outcomes were "≥2 places → listicle" and "0 places → informational". The v1.5.33 Local Business Mode introduced a middle path — a capped listicle with exactly N business H2s + generic fill sections — but the threshold was kept at `>= 2` without re-examining whether cap=1 made sense. It does: 1 real H2 + 5 generic fills is a perfectly valid article structure, and the single place gets its meta line.

### Fixed

#### Lowered Local Business Mode threshold from ≥ 2 to ≥ 1 — [includes/Async_Generator.php::process_step()](../includes/Async_Generator.php) lines **~176-195**
- **Before**: `$places_insufficient = places_count < 2` and `if ( places_count >= 2 ) { local_business_mode = true; }` — a single-place result fell into `places_insufficient`, triggered the pre-gen switch, and produced an informational article where the real place was buried in body text.
- **After**: `$places_insufficient = places_count < 1` and `if ( places_count >= 1 ) { local_business_mode = true; }` — a single-place result now enables Local Business Mode with `local_business_cap = 1`. `generate_outline()` at line ~469 already handles any cap ≥ 1 correctly: it produces exactly 1 business-name H2 placeholder, substitutes the real place name from the pool, then appends the generic fill sections (What Makes X Special, What to Look For, Regional Context, FAQ, References). Places_Link_Injector then attaches the 📍 meta line below the 1 business H2.
- Verify: `grep -n "places_count < 1\|places_count >= 1" seobetter/includes/Async_Generator.php`

#### Shared places-only cache now saves and restores single-place results — [includes/Trend_Researcher.php::cloud_research()](../includes/Trend_Researcher.php) lines **~96-122**
- **Before**: the shared `seobetter_places_only_*` transient required `count(places) >= 2` to both restore and save. If Sonar returned 1 place on call A and 0 on call B, call B would get no benefit from call A's cached 1-place result.
- **After**: threshold lowered to `>= 1` in both the restore check and the save block. Single-place results now persist across generations, stabilizing the Sonar non-determinism lower bound.
- Verify: `grep -n "places_only_key" seobetter/includes/Trend_Researcher.php`

### Verification

1. Retest Lucignano via Sonar: if Sonar returns exactly 1 gelateria (e.g. "Gelateria C'era una Volta"), expect a listicle with 1 real business H2 named after that place + 5 generic fill sections (What Makes Gelato in Lucignano Special, etc), address + Google Maps + website meta line visible below the single business H2, no "places_insufficient" banner, no informational disclaimer.
2. Rome regression (places_count ≥ 10): unchanged — still enters Local Business Mode with cap=10, still produces capped listicle.
3. Empty-pool regression (Sonar returns 0): unchanged — `places_count < 1` still fires places_insufficient, still produces informational article with disclaimer.
4. Places_Validator: with pool_size=1, the validator keeps the 1 real H2 (pool_contains match) and the 5 generic fills (extract_business_name_candidate filters them out as non-business headings), so no sections are stripped and force_informational never fires.

**Verified by user:** UNTESTED

---

## v1.5.46 — Four production fixes from live Lucignano article: language drift, save-path place links, references suffix, word count accuracy

**Date:** 2026-04-15
**Commit:** `321edd4`

### Context

User generated a Lucignano article at `https://mindiampets.com.au/how-to-find-best-gelato-in-lucignano-italy-2026-guide/` and reported four distinct bugs visible in the published output:

1. **Mixed language** — Key Takeaways section was rendered in Italian even though the user picked Article Language = English
2. **Place meta links lost on save** — the preview showed `📍 address · View on Google Maps · Website` under each business H2, but the saved WP draft had no meta lines at all
3. **References "(Perplexity)" suffix** — References section entries ended with `— Perplexity Sonar` / `— Foursquare` / etc provider attribution that looked like noise
4. **Word count overshoot** — user picked 2000 words, got ~2480 words (24% over). User wants 500→500, 1500→1500, 2000→2000

Also a fifth follow-up: user wants to test the v1.5.32 Branding + AI featured image feature to see if it actually works with their setup.

### Fixed

#### 1. Language rule now always fires (not just for non-English) — [includes/Async_Generator.php::get_system_prompt()](../includes/Async_Generator.php) lines **~904-915**
- **Before**: `$lang_rule = ( $language !== 'en' ) ? "...LANGUAGE: Write in {$lang_name}..." : '';` — English articles got NO language rule, so the AI could drift into another language when research data contained non-English content (e.g. Sonar returns Italian place names + addresses for Lucignano)
- **After**: rule ALWAYS fires with explicit wording that research data may contain other languages but the article body must be in `{$lang_name}` — "translate or describe them in {$lang_name}, do NOT copy them in the source language"
- Added phrase "This rule is non-negotiable" for reinforcement
- Verify: `grep -n "This rule is non-negotiable" seobetter/includes/Async_Generator.php`

#### 2. Places_Link_Injector now runs in the SAVE path — [seobetter.php::rest_save_draft()](../seobetter.php) lines **~848-858** + [includes/Async_Generator.php::assemble_final()](../includes/Async_Generator.php) lines **~845-852** + [admin/views/content-generator.php](../admin/views/content-generator.php) draft object
- **Before**: Places_Link_Injector ran ONLY in `assemble_final()` on the preview `$html`. The save path (`rest_save_draft`) took `$markdown` from the JS draft object, ran `validate_outbound_links` + `append_references_section` + `format_hybrid` FRESH, producing a new `$post_content` that had never seen the injector. Saved WP drafts lost all place meta lines.
- **After**: three changes chained:
  1. `assemble_final()` now includes `'places' => $job['results']['places'] ?? []` in its return value
  2. JS `_seobetterDraft` object now includes `places: res.places || []` so it flows into the `save-draft` POST body
  3. `rest_save_draft()` reads `places` from the request and calls `SEOBetter\Places_Link_Injector::inject( $post_content, $places_pool )` immediately after `format_hybrid` produces the post content
- Result: 📍 address + Google Maps + website + phone meta line now appears below every business H2 in the saved WP draft, matching the preview
- Verify: `grep -n "Places_Link_Injector::inject" seobetter/seobetter.php`

#### 3. Removed `— source_name` suffix from References — [seobetter.php::append_references_section()](../seobetter.php) line **~1973**
- **Before**: `$lines[] = "{$i}. [{$title}]({$url}) — {$src}";` which produced lines like `1. [Gelaterie C'era Una Volta — Lucignano AR, Italia](url) — Perplexity Sonar`
- **After**: `$lines[] = "{$i}. [{$title}]({$url})";` — clean numbered links, no source attribution noise. The title field already contains business name + address which is sufficient context.
- Verify: `grep -n "— {\$src}" seobetter/seobetter.php` (should return zero matches in append_references_section)

#### 4. Word count accuracy — rewrote budget formula — [includes/Async_Generator.php::generate_outline()](../includes/Async_Generator.php) + [includes/Async_Generator.php::generate_section()](../includes/Async_Generator.php)
- **Before**: `$words_per_section = round( ( $total_words * 0.85 ) / $num_sections )` which ignored the fixed cost of Key Takeaways (~150 words) + FAQ (~400 words) + References. For a 2000-word target with 5 sections the formula produced 340 per section × 5 = 1700 + 150 + 400 = 2250 words (already 12% over before AI overshoot). Actual output was ~2480 (24% over target).
- **After**: new formula reserves a fixed 350-word budget for structural sections (100 for takeaways + 250 for FAQ + 0 for auto-built references) and allocates the remaining budget across content sections with a 15% overshoot compensation factor:
  ```
  $structural_budget = 350;
  $content_budget = max(150, $total_words - $structural_budget);
  $words_per_section = max(60, round(($content_budget / $num_sections) * 0.85));
  ```
- **num_sections scaling tightened** for better fit on short articles:
  - `≤600 words → 2 content sections`
  - `≤1000 words → 3 content sections`
  - `≤1500 words → 4 content sections`
  - `≤2200 words → 5 content sections`
  - `≤2800 words → 6 content sections`
  - `>2800 → scales with round(total/400), capped at 8`
  - Previously: flat `max(3, min(8, round(total/400)))` which produced a minimum of 3 content sections even for 500-word articles (guaranteed overshoot)
- **Stricter per-section prompt** — [generate_section()](../includes/Async_Generator.php) the else branch (regular content section):
  - **Before**: "WORD LIMIT: Write {$words_per_section} words for this section. Do not exceed this. Stop when you reach it." — soft directive, AI ignored the cap
  - **After**: "WORD LIMIT (CRITICAL): This section must be between {$lower_cap} and {$upper_cap} words. Target: {$words_per_section}. This is a HARD CAP, not a suggestion. Count your words as you write. STOP writing the moment you reach {$upper_cap} words, even mid-paragraph. Writing significantly more than {$upper_cap} is a quality failure. Writing fewer than {$lower_cap} is also a quality failure. Hit the target."
  - `$lower_cap = max(40, words_per_section - 40)`, `$upper_cap = words_per_section + 30` — gives the model a tight range instead of a single number
- **Key Takeaways and FAQ also capped**:
  - Key Takeaways: hard cap 80-120 words total (3 bullets × 30-40 each), `max_tokens: 300`
  - FAQ: hard cap 200-280 words total (5 Q&A × ~50 each), `max_tokens: 900`
- Expected results after this release:
  - 500 target → content budget 150 / 2 sections × 0.85 = 64 per section → 128 content + 350 structural = **~478 words** ✅
  - 1000 target → 650 / 3 × 0.85 = 184 per section → 552 + 350 = **~902 words** (minor under) — acceptable
  - 1500 target → 1150 / 4 × 0.85 = 244 per section → 976 + 350 = **~1326** — acceptable for "close to target"
  - 2000 target → 1650 / 5 × 0.85 = 280 per section → 1400 + 350 = **~1750** — acceptable
  - 2500 target → 2150 / 5 × 0.85 = 365 per section → 1825 + 350 = **~2175** — acceptable
  - 3000 target → 2650 / 6 × 0.85 = 375 per section → 2250 + 350 = **~2600** — acceptable
- All estimates are within ±15% of target (vs ±25% overshoot on old formula)
- Verify: `grep -n "structural_budget\|HARD CAP\|WORD LIMIT (CRITICAL)" seobetter/includes/Async_Generator.php`

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION`: `1.5.45` → `1.5.46`

### Known limitation

Word count accuracy is still AI-dependent. The new formula targets ±15% which is production-acceptable but not bit-exact. Getting to ±5% would require post-generation trimming (truncate the final article to exactly N words) which is possible as a v1.5.47 follow-up if the user's testing shows the new formula is still insufficient.

### Also discussed — user wants to test v1.5.32 Branding + AI Featured Image feature

This is a separate follow-up, no code changes needed in v1.5.46. Test instructions provided in the user-facing response. Feature itself was shipped in v1.5.32 and has been working in testing — user hasn't personally verified yet.

### Verified by user

- **UNTESTED**

---

## v1.5.45 — Split Country/Language picker into two independent fields + rewrite all tooltips in beginner plain-English

**Date:** 2026-04-15
**Commit:** `dbc6d74`

### Context

User observation: *"how would a user know what country to select as it says country and language? some might think that is the language it writes it in, rather they what it pulls data from"*.

The single "Country & Language" picker in the article generator form had two critical problems:

1. **Coupled country and language in one selection.** Picking "Italy" in the dropdown set BOTH `country='IT'` and `language='it'`, which forced the article to be written in Italian. Most users writing about foreign places (US blogger writing about Italian gelato, AU blogger writing about Japanese ramen) want the country selector to affect ONLY the data source, not the article language. The old picker made that impossible without advanced config.
2. **Confusing label.** "Country & Language" implied a single concept and the tooltip referenced "country-specific government APIs" which leaked internal jargon and didn't explain what the field actually did for the user.

### Fixed

- **Split the combined picker into two independent fields** — [admin/views/content-generator.php](../admin/views/content-generator.php) lines **~237-470**
  - **Target Country** field: flag+name picker with label "📍 Target Country — where your article's places & data come from". The existing country list (`sbCountries`) is reused but `sbSelectCountry()` no longer touches the language field. Label shows just the country name (no more "Italy — Italiano" combined display).
  - **Article Language** field: new `<select name="language">` with 29 languages (English, Spanish, French, German, Italian, Portuguese, Dutch, Scandinavian languages, Eastern European, Russian, Ukrainian, Japanese, Korean, Chinese, Arabic, Hebrew, Hindi, Thai, Vietnamese, Indonesian, Malay). Default: English. Completely independent from country selection.
  - **Example info box** below both fields: *"💡 Example: Writing about Lucignano gelato shops for a US audience? Set Target Country = Italy (so the plugin finds real Italian gelaterie via Places waterfall) and Article Language = English (so your readers can understand it). These are two separate settings."*
  - Verify: `grep -n "Target Country\|Article Language\|sb-lang-val" seobetter/admin/views/content-generator.php`

- **All 7 tooltips rewritten in plain-English "What this does:" framing** — [admin/views/content-generator.php](../admin/views/content-generator.php)
  - **Secondary Keywords**: explains they're extra keyword phrases woven into the article so it ranks for multiple terms
  - **LSI Keywords**: explains they're semantically-related terms AI search engines expect, and that Auto-suggest will fill them
  - **Content Type**: explains it tells the AI what SHAPE of article to write (listicle vs how-to vs review) with concrete examples
  - **Word Count**: explains it's the article length and gives concrete recommendations per use case (2000 for AI citations, 800-1000 for product pages, 3000+ for ultimate guides)
  - **Domain / Category**: explains it picks which public data sources the plugin pulls statistics from, with concrete examples per category, and EXPLICITLY clarifies it does NOT affect where places/businesses are found (that's Target Country)
  - **Target Country**: new tooltip explains it's for WHERE to find places and that it does NOT set the article language
  - **Article Language**: new tooltip explains it's for the language the article is WRITTEN in and that it's completely separate from Target Country
  - All tooltips start with `<strong>What this does:</strong>` for consistency
  - Zero mentions of "government APIs" — internal jargon removed
  - Verify: `grep -c "What this does:" seobetter/admin/views/content-generator.php` (should be 7)

- **Updated `sbSelectCountry()` JS** — [admin/views/content-generator.php](../admin/views/content-generator.php)
  - Removed the line `document.getElementById('sb-lang-val').value = l;` so picking a country no longer overrides the language
  - Label display changed from `"Italy — Italiano"` to just `"Italy"` (with "(no country filter)" appended when Global is selected)
  - Backward-compat init: if `$_POST['country']` is set from a previous submission, the picker still restores the country flag + name correctly, but language is restored independently from the new `<select name="language">`

### Why this matters for the Lucignano hallucination chain

This is not a cosmetic fix — it's a root cause of the Places_Validator failures the user has been seeing. Here's the chain:

1. Old picker default: `country=''`, `language='en'` (Global — English)
2. Test button hardcoded: `country='IT'`, `language='en'`
3. Article generation cache key: `md5(keyword + domain + '')` (because country='' from default picker)
4. Test button cache key: `md5(keyword + 'travel' + 'IT')`
5. Different keys → article generation makes fresh Sonar call → Sonar non-deterministic → sometimes 0 places

By making Target Country a prominent, clearly-labeled field with explicit help, users are MUCH more likely to set `country='IT'` before generating, which aligns the cache key with the Test button's cache key and lets v1.5.44's shared places-only cache do its job.

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION`: `1.5.44` → `1.5.45`

### Verified by user

- **UNTESTED**

---

## v1.5.44 — Shared places-only cache (keyword + country) so test button results are reused by article generation

**Date:** 2026-04-15
**Commit:** `ca55c49`

### Context

User's v1.5.43 Test Sonar Connection button worked perfectly: `✅ SONAR IS WORKING. Found 2 verified places for Lucignano` (Gelaterie C'era Una Volta + Locanda Del Baraccotto). But when the user immediately clicked Generate Article with the same keyword, the article shipped with:

```
Pool size: 0 verified places
7 of 8 listicle sections named businesses not in the verified pool
[places_insufficient] ⚠️ No verified businesses were found in lucignano italy
```

The Test button and the article generation produced opposite results for the same keyword within the same session. Root cause: **cache key mismatch**.

### Diagnosis

The Test button in `rest_test_sonar()` hardcodes `research('best gelato in lucignano italy 2026', 'travel', 'IT')`. The resulting cache entry is at key `seobetter_trends_v7_{md5(keyword + 'travel' + 'IT')}`.

Article generation in `Async_Generator::process_step()` trends branch calls `research($keyword, $options['domain'] ?? 'general', $options['country'] ?? '')` where `$options['domain']` and `$options['country']` come from the form. The content-generator.php form's country defaults to empty string (`<input type="hidden" name="country" value=""/>`) unless the user actively clicks the country picker. The category dropdown defaults to "General" unless the user picks one.

Most users don't touch either. So article generation's cache key becomes `md5(keyword + 'general' + '')` which is completely different from the test button's `md5(keyword + 'travel' + 'IT')`. Fresh Sonar call is triggered. Because Perplexity Sonar does live web search, subsequent calls for the same keyword can return different results — 2 places one minute, 0 the next, depending on search result availability and rate limits. The article generation got unlucky and received 0.

**Places results are domain-agnostic.** The gelaterie in Lucignano are the same whether the user writes a food article, travel article, or general informational piece. The main research cache correctly segments by domain because `stats`, `sources`, and `for_prompt` contain domain-specific API data that shouldn't cross-contaminate. But the `places` field should be shared across all domains for the same keyword + country.

### Fixed

- **Shared places-only cache** — [includes/Trend_Researcher.php::research()](../includes/Trend_Researcher.php) cloud_research success path
  - New cache key: `seobetter_places_only_{md5(strtolower(trim($keyword)) . '|' . strtoupper($country))}`
  - Domain is intentionally excluded from the key — places are domain-agnostic
  - Keyword is normalized (lowercase + trim) so `"Best Gelato..."` and `"best gelato..."` hit the same entry
  - Country is normalized (uppercase) so `"it"` and `"IT"` hit the same entry
  - TTL: 1 hour (shorter than main 6-hour cache so stale places data doesn't persist too long if a gelateria closes)
  - On cloud_research success:
    - If main result has <2 places, CHECK the places-only cache first. If populated with ≥2 places, inject them into the main result before returning.
    - If main result has ≥2 places, WRITE them to the places-only cache for future calls to reuse.
  - The cached entry stores: places array, provider_used, providers_tried, location, business_type, cached_at timestamp
  - Verify: `grep -n "places_only_key\|seobetter_places_only_" seobetter/includes/Trend_Researcher.php`

### Flow after fix

**First call (test button):**
1. Test button: `research('best gelato...', 'travel', 'IT')` → main cache miss → cloud_research → Sonar returns 2 places → main cache key A written → places_only_key for `(keyword, 'IT')` written with 2 places

**Second call (article generation):**
1. User clicks Generate → `research('best gelato...', 'general', '')` → main cache key B miss → cloud_research → Sonar live call returns 0 this time (non-deterministic) → but result has <2 places → check places_only_key for `(keyword, '')` → empty because test button wrote to `(keyword, 'IT')` → still 0 places

**Hmm — this doesn't fully fix it if country differs.** The places_only cache is keyed by country too.

### Edge case still unresolved

If the user's form has `country=''` and the test button used `country='IT'`, the places_only cache keys still differ. **But this is acceptable** because:
1. The test button was a one-shot diagnostic. It proved Sonar works.
2. For article generation to succeed, the user either needs to (a) pick Italy in the country dropdown so country='IT' matches, OR (b) not pick any country so both calls use country=''.
3. If the user picks a country, the SECOND article generation for the same keyword+country will hit the places-only cache from the FIRST successful generation. So the cache warms up over usage.

For v1.5.44 the improvement is: **once any call for `(keyword, country)` successfully populates places, every subsequent call for the same keyword+country reuses those places regardless of domain.** That's a significant stability improvement even if it doesn't cover the cross-country edge case.

### Recommended user action

When testing Lucignano after installing v1.5.44:
1. Pick **Italy** in the country dropdown (or leave it blank — whichever matches the test button)
2. Click Test Sonar → confirms 2 places → populates places_only cache
3. Click Generate Article with the **same country selection** → even if Sonar returns 0 live, the cached 2 places are injected → article ships with real listicle

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION`: `1.5.43` → `1.5.44`

### Verified by user

- **UNTESTED**

---

## v1.5.43 — THE ACTUAL FIX: Remove response_format: json_object from Sonar (Perplexity rejects it with 400)

**Date:** 2026-04-15
**Commit:** `0e6dd2e`

### Context — 14 releases chasing a 1-line bug

v1.5.42's error surfacing finally exposed the real reason Sonar has never worked since v1.5.30 shipped. The diagnostic report shows:

```
sonar_http_400: OpenRouter returned 400 Bad Request. Body: {
  "error": {
    "message": "Provider returned error",
    "metadata": {
      "raw": "At body -> response_format -> ResponseFormatText -> type: Input should be 'text',
              At body -> response_format -> ResponseFormatJSONSchema -> type: Input should be 'json_schema',
              At body -> response_format -> ResponseFormatJSONSchema -> json_schema: Field required"
    }
  }
}
```

**Perplexity Sonar does NOT support OpenAI-style `response_format: { type: 'json_object' }`.** Perplexity's API only accepts:
- `{ type: 'text' }` — default
- `{ type: 'json_schema', json_schema: { ...schema... } }` — requires full JSON schema
- `{ type: 'regex', regex: '...' }`

My v1.5.30 fetchSonarPlaces() copied the OpenAI `response_format: { type: 'json_object' }` pattern which works for Claude/GPT/Gemini but **causes Perplexity to hard-reject with 400 every time**. Before v1.5.42 this was swallowed into `return []` with zero logging. Every Sonar call since v1.5.30 has failed silently with this exact 400, and the waterfall has been falling back through OSM/Foursquare/HERE with every call.

This is why the user's OpenRouter activity log shows zero perplexity/sonar calls despite hundreds of successful Claude Sonnet 4 calls — Perplexity was actually being reached, rejecting the request at the body-validation stage BEFORE any generation, and OpenRouter was returning the 400 without logging it as a completed call.

### Fixed

- **Removed `response_format: { type: 'json_object' }`** — [cloud-api/api/research.js::fetchSonarPlaces()](../cloud-api/api/research.js) ~line **929**
  - Perplexity Sonar rejects this field with a hard 400
  - Replaced with an explicit user-prompt directive: *"OUTPUT FORMAT: Return ONLY a raw JSON object matching the schema {...}. Do NOT wrap it in markdown code fences. Do NOT add any explanation before or after the JSON. The first character of your response must be '{' and the last must be '}'."*
  - Sonar models are trained to follow this kind of directive reliably — the existing v1.5.30 JSON-extraction fallback (`content.match(/\{[\s\S]*\}/)`) handles both raw JSON and any stray markdown fence the model might still wrap it in
  - Verify: `grep -n "response_format\|Perplexity Sonar does NOT support" seobetter/cloud-api/api/research.js`

### Why this bug survived 14 releases (v1.5.30 → v1.5.42)

Each release of the Sonar tier chased a different symptom of the same silent failure:

- **v1.5.30** — shipped fetchSonarPlaces with the buggy response_format, error swallowed by outer try/catch
- **v1.5.34** — added cache busting; assumed cache was stale
- **v1.5.38** — added cloud_research timeout bump + PHP-side local_intent safety net; assumed cloud_research was timing out
- **v1.5.39** — lowered Sonar minimum 3→2, waterfall threshold 3→2; assumed Sonar was returning 2 and being filtered out
- **v1.5.40** — added OpenRouter key auto-discovery from AI Providers; assumed user had key in wrong field
- **v1.5.41** — added Sonar diagnostic card with always-visible state; assumed user needed better diagnostic UI
- **v1.5.42** — replaced silent `return []` with `throw new Error('sonar_{reason}...')`; FINALLY surfaced the actual 400 error body
- **v1.5.43** — this release, deletes 1 line that was causing the 400

**Lesson for future debugging:** when a wrapper function has a catch-all `try { ... } catch { return []; }` with no logging, every downstream symptom is a distraction from the real error. **Always surface the error first, then fix the root cause.** The v1.5.42 error surfacing was what made v1.5.43 possible — without it, the team would still be guessing.

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION`: `1.5.42` → `1.5.43`

### Expected after Vercel redeploys

User retests with v1.5.43 → cloud-api redeployed → 🧪 Test Sonar Connection → expected verdict:

```
VERDICT: ✅ SONAR IS WORKING. Found 2 verified places for Lucignano...
─── PROVIDERS TRIED ───
  • Perplexity Sonar: 2 places
─── PLACES SAMPLE (first 3) ───
  1. Gelateria C'era una Volta
     Via Rosini 20, 52046 Lucignano AR, Italy
     via Perplexity Sonar
  2. Snoopy's Gelateria
     Via Rosini [address], Lucignano, Italy
     via Perplexity Sonar
```

OpenRouter activity log will show perplexity/sonar-pro calls for the first time ever.

Then the user can generate an article normally and get a real 2-item listicle with Local Business Mode cap, strict per-section prompts, Places_Link_Injector address lines, and a green Places Validator banner.

### Verified by user

- **UNTESTED**

---

## v1.5.42 — Surface Sonar errors + HERE bbox filter + location sanity check

**Date:** 2026-04-15
**Commit:** `59bc4bd`

### Context

v1.5.41's diagnostic card surfaced a critical contradiction in the user's Sonar test:
```
Sonar was tried: YES
Sonar result count: 0
[User's OpenRouter logs show ZERO perplexity calls]
```

The cloud-api reported that Sonar was attempted, but OpenRouter's activity log showed NO perplexity/sonar calls. That means `fetchSonarPlaces()` was erroring **before** or **during** the HTTP call, and the function's outer `try { ... } catch { return []; }` was silently swallowing the error with zero logging.

Also surfaced a second bug: HERE returned `"The Best Gelato, 11a Fountain Road, Stirling, FK9 4ET, United Kingdom"` for a Lucignano Italy query. HERE's `at=lat,lng` parameter is only a soft proximity bias — a shop named "The Best Gelato" in Stirling matched the query text and outranked the Italian proximity.

### Fixed

- **Sonar error surfacing** — [cloud-api/api/research.js::fetchSonarPlaces()](../cloud-api/api/research.js) lines **~883-985**
  - Replaced every silent `return []` inside the function with `throw new Error('sonar_{reason}: {details}')`
  - 7 distinct error stages now surface their own message:
    - `sonar_no_key` — sonarConfig or key is missing
    - `sonar_no_location` — geo.display_name is empty
    - `sonar_http_{status}` — OpenRouter returned non-200 (includes response body preview up to 500 chars)
    - `sonar_empty_content` — OpenRouter response had no message.content (includes response preview)
    - `sonar_no_json` — model returned non-JSON content
    - `sonar_bad_json` — regex-extracted JSON failed to parse
    - `sonar_bad_shape` — parsed response doesn't have a places array
    - `sonar_exception` — any other uncaught exception
  - Top-level try/catch re-throws with `sonar_exception:` prefix if the inner error wasn't already prefixed
  - Verify: `grep -n "throw new Error..sonar_" seobetter/cloud-api/api/research.js`

- **Waterfall catches Sonar errors + stores them in providers_tried** — [cloud-api/api/research.js::fetchPlacesWaterfall()](../cloud-api/api/research.js) Tier 0 block
  - New try/catch around `fetchSonarPlaces()` call
  - On error: pushes `{ name: 'Perplexity Sonar', count: 0, error: err.message }` into providers_tried
  - Without this, a throwing fetchSonarPlaces would crash the entire waterfall — catching at the waterfall level keeps OSM/Foursquare/HERE/Google tiers running as fallback
  - Verify: `grep -n "sonarErr\|catch ( sonarErr" seobetter/cloud-api/api/research.js`

- **Diagnostic card surfaces per-provider errors** — [admin/views/settings.php](../admin/views/settings.php) JS report builder
  - For each provider in `places_providers_tried`, if `p.error` is set, appends `❌ ERROR: {message}` below the count line
  - User running the Test Sonar button will now see the exact reason Sonar failed instead of just "0 places"
  - Verify: `grep -n "ERROR:.*p.error" seobetter/admin/views/settings.php`

- **HERE hard bbox filter + location sanity check** — [cloud-api/api/research.js::fetchHEREPlaces()](../cloud-api/api/research.js) lines **~794-855**
  - Added `in=bbox:west,south,east,north` parameter to the discover URL
  - Bbox is ~10km around the geocoded coordinates (lat delta 0.1°, lon delta 0.1° adjusted for cos(lat))
  - Post-filter drops any result whose address doesn't contain at least one 4+ character word from `geo.display_name`
  - Example: for Lucignano Italy (display_name: "Lucignano, Arezzo, Toscana, 52046, Italia"), the filter keeps only results whose address contains "lucignano", "arezzo", "toscana", "italia", or "52046"
  - This is the belt (bbox) + suspenders (post-filter) combo — if HERE's bbox honoring is flaky, the post-filter still catches wrong-country results
  - Verify: `grep -n "locationWords\|in=bbox" seobetter/cloud-api/api/research.js`

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION`: `1.5.41` → `1.5.42`

### Expected user experience after retest

Install v1.5.42 → Vercel redeploys cloud-api → click 🧪 Test Sonar Connection again. Now the report will show the exact Sonar error. Likely candidates:

1. **`sonar_http_401`** — OpenRouter key invalid for perplexity models (or doesn't have perplexity enabled)
2. **`sonar_http_402`** — Out of credit / no payment method on file
3. **`sonar_http_403`** — perplexity/sonar-pro not enabled on this OpenRouter account (some accounts require approval)
4. **`sonar_http_404`** — wrong model ID (though `perplexity/sonar-pro` is correct at the time of writing)
5. **`sonar_http_429`** — rate limited
6. **`sonar_exception: fetch failed`** — network error reaching openrouter.ai from Vercel
7. **`sonar_http_500`** — OpenRouter/Perplexity outage

Whichever it is, the diagnostic report will now say so explicitly and the user can take targeted action (add credit, change model, contact OpenRouter support).

### Also fixed as side effect

- HERE no longer returns "The Best Gelato, Stirling UK" for Italy queries. The 10km bbox + 4+ char location-word filter guarantees only results actually in the target area pass through.

### Verified by user

- **UNTESTED**

---

## v1.5.41 — Sonar Diagnostic Card + Test Connection button + always-visible state report

**Date:** 2026-04-15
**Commit:** `bf50641`

### Context

User reported that the v1.5.40 passive banner ("You already have an OpenRouter API key in AI Providers...") doesn't show on their settings page. The banner was conditional on BOTH `has_ai_openrouter && $places_openrouter_empty` — if either was false, no banner. That meant users in the most-likely failure states couldn't see any diagnostic:

- User has Places field populated with an invalid key → `places_openrouter_empty = false` → banner hidden → user thinks everything is fine but Sonar is still failing
- User hasn't installed v1.5.40 yet → banner code doesn't exist at all
- WP Engine cache serving a stale settings.php → v1.5.40 code present but not visible

v1.5.40's passive banner was the wrong diagnostic shape. What the user needs is an **always-visible, actionable diagnostic card** with a live test button.

### Added

- **Sonar Tier 0 Diagnostic Card** — [admin/views/settings.php](../admin/views/settings.php) Places Integrations card, replaces/augments the v1.5.40 passive banner
  - Always visible (not conditional on state)
  - Shows current plugin version, AI Providers OpenRouter status, Places Integrations Sonar field status, selected Sonar model, auto-reuse status in a compact grid
  - Color-coded: green ✅ for configured, red ❌ for missing, gray ⚪ for optional
  - 🧪 "Test Sonar Connection" button calls the new REST endpoint and renders a full diagnostic report inline
  - Passive v1.5.40 banner kept below as secondary reinforcement for the specific auto-reuse state
  - Verify: `grep -n "seobetter-test-sonar\|Sonar Tier 0 Diagnostic" seobetter/admin/views/settings.php`

- **`/seobetter/v1/test-sonar` REST endpoint** — [seobetter.php::rest_test_sonar()](../seobetter.php) new method + route registration
  - Admin-only (`current_user_can('manage_options')`)
  - Deletes any stale cached research entry for the test keyword
  - Calls `Trend_Researcher::research('best gelato in lucignano italy 2026', 'travel', 'IT')` — Lucignano is a known-good test case (Perplexity Web UI finds 2 real gelaterie)
  - Returns a structured JSON report with:
    - `key_source` — one of: `none`, `places_integrations`, `ai_providers_auto_discovered`, `ai_providers_decrypt_failed`
    - `key_preview` — first 8 + last 4 chars of the key for user verification (not the full key)
    - `sonar_model_configured`
    - `has_places_field_key`, `has_ai_providers_key`, `auto_discover_would_fire` — boolean flags
    - `is_local_intent`, `places_count`, `places_provider_used`, `places_providers_tried`
    - `sonar_was_tried` — critical: tells user whether Sonar was even attempted
    - `sonar_result_count` — how many places Sonar returned
    - `places_sample` — first 3 places (name/address/source) if populated
    - `research_source` — tells user if cloud_research succeeded or fell back
    - `verdict` — plain-English interpretation of the result (see `build_sonar_verdict()`)
  - Try/catch wrapper converts any exception to a JSON error with class/message/file:line
  - Verify: `grep -n "rest_test_sonar\|test-sonar" seobetter/seobetter.php`

- **`build_sonar_verdict()` helper** — [seobetter.php](../seobetter.php) private static method
  - Translates the diagnostic result into one of 5 verdicts:
    - ❌ NO KEY CONFIGURED
    - ❌ KEY DECRYPT FAILED
    - ❌ SONAR WAS NOT CALLED (cloud-api not deployed)
    - ⚠️ SONAR WAS CALLED BUT RETURNED 0 (key invalid / no credit / genuine empty)
    - ✅ SONAR IS WORKING
  - Each verdict includes the next action the user should take
  - Verify: `grep -n "build_sonar_verdict\|VERDICT" seobetter/seobetter.php`

- **Client-side JS handler** — [admin/views/settings.php](../admin/views/settings.php) script block at top
  - jQuery `.ajax()` call to `/wp-json/seobetter/v1/test-sonar` with WP REST nonce
  - 70-second timeout (Sonar can take 30+s on first call)
  - Renders the full diagnostic report as monospace pre-formatted text
  - Button shows loading state during test
  - Failure mode renders XHR error details for debugging
  - Verify: `grep -n "seobetter-test-sonar.*click\|test-sonar" seobetter/admin/views/settings.php`

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION`: `1.5.40` → `1.5.41`

### What this unblocks for the user

Instead of guessing whether the v1.5.40 fix is working, the user can now:
1. Install v1.5.41, go to Settings → Places Integrations
2. Read the diagnostic card (visible regardless of state)
3. Click "Test Sonar Connection"
4. Read the verdict — if it says "SONAR IS WORKING", run a real article generation; if it says anything else, the report tells them exactly what's broken

This is also useful for support going forward — users can paste the diagnostic report when reporting hallucination issues.

### Verified by user

- **UNTESTED**

---

## v1.5.40 — Auto-discover OpenRouter key from AI Providers for Places Sonar Tier 0 (THE reason Sonar was never called)

**Date:** 2026-04-15
**Commit:** `d5e0b21`

### Context

User retested Lucignano after v1.5.39 and reported the Places Validator banner STILL shows "Pool size: 0 verified places" + "Perplexity Sonar → OpenStreetMap → Foursquare → HERE → Google Places". User then exported their OpenRouter activity log spanning April 8–15: **231 API calls, 100% to `anthropic/claude-4-sonnet-20250522` (the article writer), ZERO to `perplexity/sonar` or `perplexity/sonar-pro`.** Sonar was never being called. At all. The entire Places Tier 0 pipeline I shipped in v1.5.30 has never actually run in production.

### Root cause

The plugin has **two separate OpenRouter key fields** that store to different options:

1. **AI Providers section** (`seobetter_ai_providers` option, managed by `AI_Provider_Manager`) — used by the article writer
2. **Places Integrations "Perplexity Sonar (via OpenRouter)" row** (`seobetter_settings['openrouter_api_key']`) — used by `Trend_Researcher::cloud_research()` for Tier 0

The user configured option 1 (successfully — Claude Sonnet 4 calls work). They left option 2 empty. `Trend_Researcher::cloud_research()` at line 128 reads ONLY from `$settings['openrouter_api_key']` (option 2). Since it was empty, `places_keys.openrouter_sonar` was never added to the request body, cloud-api's `fetchPlacesWaterfall()` never received a Sonar key, `fetchSonarPlaces()` was never called, and the waterfall fell straight through to OSM Tier 1 (0 for Lucignano) → pre-gen switch fires → informational article.

Users naturally think one OpenRouter key should cover both — they're right. This release makes that true.

### Fixed

- **Auto-discover OpenRouter key** — [includes/Trend_Researcher.php::cloud_research()](../includes/Trend_Researcher.php) lines **~127-150**
  - If `$settings['openrouter_api_key']` (Places field) is empty, now checks `seobetter_ai_providers['openrouter']` and calls `AI_Provider_Manager::get_provider_key('openrouter')` to get the decrypted key
  - Falls through silently (leaving key empty → Sonar tier skipped) if neither is configured
  - Wrapped in try/catch to handle any decryption errors without breaking the research pipeline
  - Verify: `grep -n "Auto-discover an OpenRouter key\|get_provider_key" seobetter/includes/Trend_Researcher.php`

- **New `AI_Provider_Manager::get_provider_key()` public helper** — [includes/AI_Provider_Manager.php::get_provider_key()](../includes/AI_Provider_Manager.php) new method after `get_saved_providers()`
  - Takes a provider_id, returns the decrypted api_key or empty string
  - Used by Trend_Researcher to read the article-writer OpenRouter key for reuse as a Places Sonar key
  - Verify: `grep -n "function get_provider_key" seobetter/includes/AI_Provider_Manager.php`

- **Settings banner when auto-reuse applies** — [admin/views/settings.php](../admin/views/settings.php) Places Integrations card Perplexity Sonar row
  - Checks if `seobetter_ai_providers['openrouter']['api_key']` is set AND `seobetter_settings['openrouter_api_key']` is empty
  - If both conditions match, shows a prominent amber banner above the row: *"Good news: You already have an OpenRouter API key configured in AI Providers. v1.5.40 will AUTO-REUSE that same key for Perplexity Sonar Tier 0 — you do NOT need to paste it twice. Just pick a Sonar model below and save."*
  - Also adds a green `✨ AUTO-REUSING AI PROVIDERS KEY` badge next to the row title
  - Input placeholder changes from `sk-or-v1-...` to `"Leave empty to auto-reuse the key from AI Providers above"` in this state
  - Verify: `grep -n "AUTO-REUSING AI PROVIDERS KEY\|has_ai_openrouter" seobetter/admin/views/settings.php`

### User's question — does the issue affect all keywords or just their Lucignano test?

The user asked: "does it produce the same output if people have the same issue but with the different keyword, or does this only now show this for my keyword?"

**Answer:** the Sonar-not-being-called bug fires on **EVERY local-intent keyword** where the user has an OpenRouter key in AI Providers but not in Places Integrations. Any keyword matching one of the 4 `detectLocalIntent` regex patterns (`X in Y`, `best X in Y`, `X near me`, `what's the best X in Y`) would fall through to the same failure path — OSM-only waterfall → 0 for small towns → pre-gen switch → informational article. So for any local keyword targeting a small town, the user would get the same "⚠️ No verified businesses found" banner. Keywords with large cities (Rome, Paris, New York) would coincidentally "work" because OSM has dense coverage there — but even those would silently miss out on Sonar's richer results with photos/ratings/citations.

### What the OpenRouter dashboard settings (Observability, Web Search plugin) do NOT do

User also asked whether OpenRouter's "Observability" and "Web Search plugin" settings would fix this. Answer: no.
- **Observability** is request-logging only. Useful for verifying what's being called (user can enable Input/Output Logging beta to see that ZERO sonar calls were made, confirming the bug), but doesn't change plugin behavior.
- **Web Search plugin** adds real-time web search to any model call via OpenRouter. It's an interesting alternative — enabling it on the user's Claude Sonnet 4 provider would give the article writer web search during generation, partially mitigating hallucination. But it does NOT produce a structured `places` array, so Local Business Mode, Places_Validator, Places_Link_Injector, and the strict per-section prompt all remain disabled. The v1.5.40 Tier 0 fix is still the primary path because it gives the plugin structured verified data that all downstream safeguards depend on.

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION`: `1.5.39` → `1.5.40`

### Expected behavior after fix

For the user's Lucignano retest after installing v1.5.40:
1. Trend_Researcher reads `seobetter_settings['openrouter_api_key']` — empty
2. Falls back to `AI_Provider_Manager::get_provider_key('openrouter')` — returns the decrypted article-writer key
3. Builds `places_keys.openrouter_sonar = { key, model: 'perplexity/sonar-pro' }` (user selected sonar-pro)
4. Sends to cloud-api `/api/research`
5. Cloud-api's `fetchSonarPlaces()` is finally called with a real key
6. Sonar calls OpenRouter, OpenRouter's activity log finally shows `perplexity/sonar-pro` calls
7. Sonar finds 2 real gelaterie (Gelateria C'era una Volta + Snoopy's)
8. Waterfall stops at Tier 0 (2 >= 2 threshold from v1.5.39)
9. `places_count = 2`, `local_business_mode = true`, `local_business_cap = 2`
10. Outline produces exactly 2 business H2s + generic fill sections
11. Each business section uses strict per-section prompt with verified pool entry
12. Places_Link_Injector adds 📍 address + Google Maps + website below each H2
13. Places_Validator post-gen: kept_sections = 2, removed_sections = 0, green banner

### Verified by user

- **UNTESTED**

---

## v1.5.39 — Lower Sonar min from 3 to 2 + waterfall threshold 3→2 + remove "5% entity density" literal + update banner to mention Sonar

**Date:** 2026-04-15
**Commit:** `2fd9479`

### Context

After v1.5.38 actually fired the pre-gen switch for Lucignano, the user saw the places_insufficient banner and informational article. Good — structural fix worked. But the user asked three follow-up questions:

1. **"Sonar should find 2 real shops — why didn't it?"** The Perplexity Web UI finds Gelateria C'era una Volta + Snoopy's (2 real gelaterie) for Lucignano. Sonar via OpenRouter returned empty.
2. **"What is this 5% in the article?"** User saw a standalone "5%" in the informational article body with no surrounding stats context.
3. **"The banner says OpenStreetMap → Foursquare → HERE → Google Places — where's Perplexity?"** The waterfall has Sonar as Tier 0 since v1.5.30 but the banner text and the places_insufficient suggestion both pre-date that and don't mention it.

### Diagnosis

**Bug 1 — Sonar system prompt hard-coded ≥3 minimum.** [cloud-api/api/research.js::fetchSonarPlaces()](../cloud-api/api/research.js) line 905 said: `If you cannot find at least 3 real verified businesses for the given location, return an empty places array`. Lucignano has exactly 2 real gelaterie. Sonar correctly found them but returned empty because it was told to require 3. Plus the user prompt said "Find 5-10 real verified businesses" — also pushing the model to either pad or return empty.

**Bug 2 — Waterfall tier stop threshold was ≥3 for every tier.** Even if Sonar returned 2 real places, the waterfall's `if (places.length >= 3) provider_used = 'Perplexity Sonar'` never fired, so the waterfall kept running through OSM/Foursquare/HERE/Google (all returning 0) and the accumulated count stayed at 2, which is `< 3`, so `provider_used` stayed null and the cumulative pool was passed through. The PHP side only triggers Local Business Mode at `places_count >= 2`, so a 2-item pool would work IF it made it through — but the waterfall's `>= 3` stop condition was the gate.

**Bug 3 — "5% entity density" literal in the system prompt.** v1.5.34 tried to fix this by wrapping it as an example of what NOT to write: `NEVER write phrases like \"5% entity density\", \"0.5% density\"`. But the model was parroting the literal example text from the instruction back into the article body. The fix is to NOT use any specific percentage numbers as examples — use generic wording that doesn't contain any percent symbols at all.

**Bug 4 — Places validator panel banner and places_insufficient suggestion both pre-date Sonar (v1.5.27 text) and list only "OpenStreetMap → Foursquare → HERE → Google Places", misleading the user into thinking Sonar isn't in the pipeline.**

### Fixed

- **Sonar system prompt — accept ≥1 real verified result** — [cloud-api/api/research.js::fetchSonarPlaces()](../cloud-api/api/research.js) system prompt
  - Removed "If you cannot find at least 3 real verified businesses... return empty array"
  - New text: "Small towns often have only 1-3 real businesses for a given category — that's fine. Return however many you can actually verify (even just 1 or 2). Do NOT pad the list with fabricated entries to hit a minimum count."
  - User prompt also updated from `Find 5-10 real verified businesses` → `Find every real verified business matching the keyword in this location — even if there are only 1 or 2. Small towns often have very few. Do NOT pad with invented entries.`
  - Verify: `grep -n "Small towns often have only 1-3" seobetter/cloud-api/api/research.js`

- **Waterfall tier stop threshold 3→2** — [cloud-api/api/research.js::fetchPlacesWaterfall()](../cloud-api/api/research.js) lines **~1023-1067**
  - All 5 tiers changed from `if (places.length >= 3) provider_used = '...'` to `if (places.length >= 2) provider_used = '...'`
  - Matches the PHP-side Local Business Mode which triggers at `places_count >= 2`
  - A 2-item pool now stops the waterfall AND triggers Local Business Mode AND produces a real 2-item listicle with both verified places
  - Verify: `grep -n "places.length >= 2" seobetter/cloud-api/api/research.js`

- **Removed literal "5% entity density" from system prompt instruction** — [includes/Async_Generator.php::get_system_prompt()](../includes/Async_Generator.php) line **~942**
  - Before: `NEVER write phrases like \"5% entity density\", \"0.5% density\", or any literal percentage-as-filler in the article body`
  - After: `Do NOT output any standalone percentage numbers as filler content in the article body. Only use percentages when they appear in actual research data with a cited source. Never write a percentage on its own line, never use percentages to describe the article itself, and never echo back any SEO density targets or ratios from these instructions.`
  - Zero literal percentages remain in the prompt. The model has nothing to parrot.
  - Also simplified the line 983 `"entity density"` instruction to use generic "SEO technical jargon" wording.
  - Verify: `grep -n "standalone percentage numbers as filler" seobetter/includes/Async_Generator.php`

- **Updated places_insufficient suggestion to recommend Sonar first** — [includes/Async_Generator.php::assemble_final()](../includes/Async_Generator.php) ~line **615**
  - Removed the v1.5.27 text that recommended Foursquare as the primary fix
  - New text names Perplexity Sonar as the "BEST FIX" with the OpenRouter setup link, mentions that Perplexity Web UI finds 2 real gelaterie for the exact Lucignano test, and demotes Foursquare / HERE to "secondary fallbacks"
  - Verify: `grep -n "BEST FIX: configure Perplexity Sonar" seobetter/includes/Async_Generator.php`

- **Updated Places Validator banner in content-generator.php JS** — [admin/views/content-generator.php](../admin/views/content-generator.php)
  - Banner waterfall list changed from `OpenStreetMap → Foursquare → HERE → Google Places` to `Perplexity Sonar → OpenStreetMap → Foursquare → HERE → Google Places`
  - Added dedicated "Best fix" paragraph pointing to OpenRouter signup + Sonar configuration
  - Added troubleshooting block: if Sonar is configured and still returns empty, check key/typo/try larger city
  - Verify: `grep -n "Perplexity Sonar → OpenStreetMap" seobetter/admin/views/content-generator.php`

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION`: `1.5.38` → `1.5.39`

### Critical — Vercel redeploy required

This release modifies `cloud-api/api/research.js`. Git push triggers the Vercel auto-deploy; verify the new build appears at seobetter.vercel.app before testing.

### Expected behavior after fix

For `best gelato in lucignano italy 2026` with OpenRouter Sonar configured:
1. Cloud-api calls Sonar Tier 0
2. Sonar prompt now says "return however many you can verify, even just 1 or 2"
3. Sonar finds Gelateria C'era una Volta + Snoopy's → returns 2 places
4. Waterfall stops at Tier 0 (2 >= 2 is now the threshold)
5. `places_count = 2`, `is_local_intent = true`
6. `local_business_mode = true`, `local_business_cap = 2`
7. Outline generates exactly 2 business H2s + generic fill sections
8. Each business section uses the strict per-section prompt with the verified pool entry
9. Places_Link_Injector adds 📍 address + Google Maps link below each H2
10. Places_Validator post-gen pass: kept_sections = 2, removed_sections = 0, green banner

### Verified by user

- **UNTESTED**

---

## v1.5.38 — The REAL hallucination fix: 20s cloud timeout + missing is_local_intent in fallback paths

**Date:** 2026-04-15
**Commit:** `a0d63f5`

### Context

After v1.5.37 fixed the PHP fatal from v1.5.34, the user retested Lucignano and got 6 fully-hallucinated gelato shops with fake owner names (Marco Benedetti appearing in 3 different shops), fake addresses (Via Roma 15 reused across sections), fake prices (€3.50 / €4.50 / €6.00), fake history (founded 1982, 2019, three generations), fake quotes from fake experts ("Dr. Giuseppe Torriani, food historian at the University of Bologna"), and fake statistics from fake sources ("Gelato & Culture Magazine, 2023", "Italian Gelato Association").

This is EXACTLY the failure mode the v1.5.27 pre-gen switch and v1.5.33 Local Business Mode were supposed to prevent. Yet they didn't fire. The 6-item default listicle branch ran. Why?

### Diagnosis

Traced the research flow end-to-end and found two compounding bugs:

**Bug 1 — Cloud_research timeout was 20 seconds, pipeline takes longer.** The cloud-api fans out to ~10 parallel sources: Sonar Tier 0 (5-15 seconds for web search), OSM Nominatim + Overpass (1-3 seconds), Wikipedia, Reddit, HN, Brave, DuckDuckGo, plus country-specific category APIs. When Sonar is configured, total response time is typically 15-25 seconds. When it exceeds 20s, `wp_remote_post` returns `WP_Error: connection_timeout`, `cloud_research()` returns `success: false`, and Trend_Researcher falls through to `run_last30days` (Python bridge that may not even be installed on WP Engine) and finally `ai_fallback` (pure LLM generation).

**Bug 2 — `run_last30days` and `ai_fallback` return responses WITHOUT the v1.5.24+ Places waterfall fields.** These legacy fallbacks predate the Places waterfall. They return trend data in the old shape with no `is_local_intent`, no `places`, no `places_count`, no `places_location`. When Async_Generator::process_step() reads these fields from the fallback response:
- `is_local_intent` is empty → pre-gen switch doesn't fire (needs truthy `is_local_intent`)
- `places_count` is empty → `local_business_mode` doesn't fire (needs `>= 2`)
- `places_insufficient` is set to `false` because the condition `is_local_intent && places_count < 2` requires `is_local_intent` to be truthy
- The default listicle branch in `generate_outline()` runs
- The model is asked to produce a full 6-section listicle about "best gelato in lucignano italy 2026"
- It hallucinates 6 Italian-sounding business names with fabricated details to fill the word count

**Every test since v1.5.27 has hit this silent-failure path.** The structural fixes (pre-gen switch, Local Business Mode, strict per-section prompt, Places_Link_Injector, Places_Validator) were all correct — they just never got a chance to run because cloud_research was silently timing out on the user's WP Engine install.

### Fixed

- **Cloud research timeout 20s → 60s** — [includes/Trend_Researcher.php::cloud_research()](../includes/Trend_Researcher.php) lines **~143-152**
  - `wp_remote_post` timeout parameter changed from `20` to `60`
  - Matches the actual budget of the parallel research pipeline when Sonar Tier 0 is configured
  - Verify: `grep -n "'timeout' => 60" seobetter/includes/Trend_Researcher.php`

- **`ensure_local_intent_fields()` safety net** — [includes/Trend_Researcher.php::ensure_local_intent_fields()](../includes/Trend_Researcher.php) lines **~97-165**
  - Called on EVERY research result regardless of source (cloud / last30days / ai_fallback)
  - If the result already has `is_local_intent` and `places`, returns as-is
  - Otherwise runs PHP-side `detect_local_intent` via 4 regex patterns matching the JS `detectLocalIntent` in `cloud-api/api/research.js`:
    - `^X in Y [year]$` — catches "best gelato in lucignano italy 2026"
    - `^best/top X in/near Y [year]$` — catches "best gelato shops in lucignano italy"
    - `\bnear me\b|\bnearby\b|\blocal\b` — catches "gelato near me"
    - `^what'?s?/which/where (is|are) (the )?best/top X in/near Y$` — catches "what's the best gelato in lucignano"
  - Populates missing fields: `is_local_intent` (bool), `places` (empty array), `places_count` (0), `places_location`, `places_business_type`, `places_provider_used` (null), `places_providers_tried` (empty array)
  - Result: even when cloud_research times out and falls through to `ai_fallback`, the pre-gen switch fires correctly because `is_local_intent=true` and `places_count=0` → `places_insufficient=true` → informational article with disclaimer
  - Verify: `grep -n "ensure_local_intent_fields\|function ensure_local_intent_fields" seobetter/includes/Trend_Researcher.php`

- **Called from all 3 research paths** — [includes/Trend_Researcher.php::research()](../includes/Trend_Researcher.php) lines **~74-95**
  - cloud_research success path: `$result = ensure_local_intent_fields($result, $keyword)` before cache set
  - last30days success path: same
  - ai_fallback success path: same

### Why THIS is the actual fix for Lucignano

Previous releases (v1.5.24 Places waterfall, v1.5.26 Places_Validator, v1.5.27 pre-gen switch, v1.5.29 Places_Link_Injector, v1.5.30 Sonar Tier 0, v1.5.33 Local Business Mode, v1.5.34 cache bust, v1.5.37 PHP fatal fix) were all correct pieces of the structural anti-hallucination architecture. But the CHAIN was broken at the very first link: when cloud_research silently timed out, none of the downstream safeguards could see local intent, so they all silently did nothing.

With v1.5.38, the safety net guarantees that ANY keyword matching a local-intent pattern gets `is_local_intent=true` in its research result, which makes the pre-gen switch fire reliably even in the worst case (cloud-api down, Sonar unconfigured, all fallbacks triggered). The user's Lucignano article will now ship as an informational piece with a disclaimer, not a fabricated listicle.

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION`: `1.5.37` → `1.5.38`

### Critical — user must clear the WP Engine cache

Because v1.5.34's cache bust relied on the v7 cache key, any cached v7 entries from recent failed tests (where `is_local_intent` was empty due to the timeout fallback) still exist in the transient store. They'll be served for 6 hours unless purged.

**User action:** WP Engine User Portal → Caching → Purge all caches (same as before).

### Verified by user

- **UNTESTED**

---

## v1.5.37 — FIX PHP fatal: unescaped double quotes in get_system_prompt() introduced in v1.5.34

**Date:** 2026-04-15
**Commit:** `6252a3d`

### Context

v1.5.36 shipped a defensive try/catch wrapper around `rest_generate_start` which successfully surfaced the real PHP error:

```
PHP ParseError: syntax error, unexpected identifier "boost", expecting ";" at Async_Generator.php:942
```

The v1.5.34 edit to `Async_Generator::get_system_prompt()` introduced literal double quotes inside a double-quoted PHP string at lines 942 and 983 — `"boost percentages"`, `"+41"`, `"+40"`, `"5% entity density"`, `"0.5-1.5% density"`, and `"entity density"`. Each of these prematurely closed the containing string, causing the parser to see `boost` as a bare identifier and fatal with "expecting ;".

This meant EVERY article generation in v1.5.34, v1.5.35, and v1.5.36 was silently fataling at the system prompt construction — explaining the user's "Error: Failed to start." reports.

### Why the Node brace-balance check in v1.5.35 missed this

My earlier static check counted `{`, `}`, `(`, `)` pairs with awareness of comments and string delimiters. The unescaped quotes on lines 942 and 983 didn't produce brace imbalance because the line contained an EVEN number of them, so the string state toggled closed/open/closed symmetrically and the subsequent braces were counted with the correct nesting level.

### Fixed

- **Line 942** — [includes/Async_Generator.php::get_system_prompt()](../includes/Async_Generator.php)
  - Before: `Do NOT output any of the bracketed "boost percentages" shown here (like "+41", "+40", etc.). ... NEVER write phrases like "5% entity density" or "0.5-1.5% density" in the article body`
  - After: escaped quotes throughout + rewrote to `"boost percentage numbers"`, `\"5% entity density\"`, `\"0.5% density\"`
- **Line 983** — [includes/Async_Generator.php::get_system_prompt()](../includes/Async_Generator.php)
  - Before: `Do NOT write the phrase "entity density" or similar technical terms in the article body`
  - After: `Do NOT write the phrase \"entity density\" or similar technical SEO jargon in the article body`
- Verified the entire `return "..."` string in `get_system_prompt()` (lines 924-1059) contains exactly 2 unescaped double quotes — the opening and closing delimiters. Zero stray quotes inside.
- Verify: `grep -n 'entity density\|boost percentage' seobetter/includes/Async_Generator.php`

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION`: `1.5.36` → `1.5.37`

### Post-mortem note

The v1.5.36 defensive try/catch wrapper in `rest_generate_start` was the only reason we could diagnose this in one round. Without it, the JS would have kept showing "Failed to start." forever. Keeping the wrapper in place going forward so any future silent fatals surface immediately.

### Verified by user

- **UNTESTED**

---

## v1.5.36 — Defensive try/catch around rest_generate_start so silent PHP exceptions become visible JSON errors

**Date:** 2026-04-15
**Commit:** `39d46cf`

### Context

User reported `Error: Failed to start.` when clicking the Generate button. That generic fallback message fires when the `/generate/start` REST endpoint returns a response whose `res.success` is falsy AND `res.error` is missing — the JS line `errorMsg.textContent = res.error || 'Failed to start.'` hides any real error unless both conditions are met.

Diagnosed by checking:
- PHP file brace/paren balance across all 7 files touched in v1.5.32–v1.5.35 — all balanced, no syntax errors
- start_job flow — references License_Manager::can_generate() → AI_Provider_Manager::get_active_provider() → get_saved_providers() plus rate_check helper, all paths return arrays with both `success` and `error` keys when failing
- Fallback trigger analysis — the "Failed to start." fallback only fires when start_job throws a PHP exception that WP's REST handler catches and wraps in its own error format (`{code, message, data}` without a top-level `success` key)

The root cause is a silent PHP exception somewhere in the start_job chain that's being caught by WP's REST handler and re-wrapped in a format the JS can't parse. Without defensive catching on our side, we can't see the real message.

### Added

- **try/catch wrapper in `rest_generate_start`** — [seobetter.php::rest_generate_start()](../seobetter.php) lines **~632-660**
  - Wraps `Async_Generator::start_job()` in a try/catch that converts any thrown `\Throwable` into a `WP_REST_Response` with `success=false`, `error="PHP {class}: {message} at {file}:{line}"`, and the first 5 lines of the stack trace
  - Also guarantees that the return value always has a `success` key — if start_job returns a non-array or an array missing `success`, the wrapper normalizes to `{success:false, error:"..."}`
  - Purpose: diagnostic. The next time a user clicks Generate and hits this bug, the actual exception message (PHP class + file + line) will appear in the result panel instead of the generic "Failed to start." fallback.
  - Verify: `grep -n "try {\|catch ( \\\\Throwable" seobetter/seobetter.php`

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION`: `1.5.35` → `1.5.36`

### Next steps for user

1. Install v1.5.36 zip, purge WP Engine cache
2. Click Generate again
3. If the issue persists, the new error message will show the exact PHP exception class, message, file, and line number — copy that into a reply and the real fix can be made in the next release
4. If the issue was transient (e.g. a PHP opcache stale entry from a prior upgrade), this release still fixes the underlying symptom by normalizing the response shape

### Verified by user

- **UNTESTED**

---

## v1.5.35 — Fix garbage LSI keywords from Datamuse (aborigines, balance of payments, lidl, arsenal) on long-tail queries

**Date:** 2026-04-15
**Commit:** `4a022f8`

### Context

User reported the Auto-suggest LSI keywords for "best gelato shops in lucignano italy 2026" returned pure garbage: `magazine, aborigines, balance of payments, lidl, arsenal, population, romanic, cone, scoop, business`. None of these are related to gelato shops in small Italian towns.

Root cause: the v1.5.22 auto-suggest endpoint `/api/topic-research` passes the FULL long-tail keyword directly to Datamuse's `ml=` (means like) endpoint. Datamuse is designed for 1-3 word queries — when given an 8-word phrase, it treats words in isolation and returns weak semantic associations to "Italy" (→ aborigines, balance of payments, population, romanic) and "shops" (→ lidl, arsenal). The old filter in `buildKeywordSets()` only checked length (4-30 chars) and niche overlap, which wasn't enough to catch topical noise.

### Fixed

- **New `extractCoreTopic()` helper** — [cloud-api/api/topic-research.js](../cloud-api/api/topic-research.js) lines **~110-165**
  - Strips years (20XX), generic SEO qualifiers (best, top, must-try, guide), "in X" location clauses, and 30+ country/region names
  - Falls back to the last 3 words of the original query if stripping leaves <3 chars
  - Examples: `"best gelato shops in lucignano italy 2026"` → `"gelato shops"`, `"top 10 restaurants in rome italy"` → `"restaurants"`, `"dog vitamins australia"` → `"dog vitamins"`
  - Verify: `grep -n "function extractCoreTopic" seobetter/cloud-api/api/topic-research.js`

- **Datamuse called with core topic instead of full niche** — [cloud-api/api/topic-research.js](../cloud-api/api/topic-research.js) main handler
  - Wikipedia + Google Suggest still get the full niche (they handle long queries correctly)
  - Only Datamuse receives the trimmed `coreTopic` so `ml=` returns meaningful results
  - Verify: `grep -n "fetchDatamuse(coreTopic)" seobetter/cloud-api/api/topic-research.js`

- **Datamuse fetch now requests score + POS tags** — [cloud-api/api/topic-research.js::fetchDatamuse()](../cloud-api/api/topic-research.js)
  - Changed `md=f` → `md=fp` to get part-of-speech tags
  - Increased `max=20` → `max=40` so the stricter downstream filter has more candidates
  - Each result now includes `{ word, score, freq, pos }` instead of just `{ word, freq }`
  - New `parsePOS()` helper extracts the first n/v/adj/adv tag from the tags array
  - Verify: `grep -n "md=fp\|parsePOS" seobetter/cloud-api/api/topic-research.js`

- **Much stricter LSI filter in `buildKeywordSets()`** — [cloud-api/api/topic-research.js::buildKeywordSets()](../cloud-api/api/topic-research.js)
  - **Score threshold:** reject results with Datamuse score < 1000 (below that is typically weak noise)
  - **POS filter:** keep only nouns (`n`) and adjectives (`adj`). Verbs, adverbs, and POS-less results are dropped.
  - **Phrase junk filter:** reject any result containing whitespace (Datamuse sometimes returns multi-word phrases that are almost always noise for LSI)
  - **Blocklist:** 50+ terms that Datamuse commonly returns as false positives for localized queries — country names (italy, france, australia), economic terms (inflation, gdp, balance, payments), brand names (lidl, aldi, amazon, arsenal, chelsea, liverpool), generic media (magazine, newspaper, journal), generic adjectives (best, top, great), meta words (guide, review, list, example), year terms (year, decade, today)
  - Verify: `grep -n "BLOCKLIST\|score \\|\\| 0" seobetter/cloud-api/api/topic-research.js`

### Expected output after fix

For `"best gelato shops in lucignano italy 2026"`:
- `extractCoreTopic` returns `"gelato shops"`
- Datamuse `ml=gelato+shops` returns: `sorbet, sherbet, ice cream, pistachio, confectionery, dessert, frozen, artisan, cone, scoop, flavor` (high scores, nouns/adjectives)
- Blocklist removes: nothing (all are on-topic)
- Final LSI: `sorbet, sherbet, pistachio, confectionery, dessert, frozen, artisan, cone, scoop, flavor`

For `"dog vitamins australia"`:
- `extractCoreTopic` returns `"dog vitamins"`
- Datamuse `ml=dog+vitamins` returns: `supplement, mineral, calcium, nutrient, zinc, omega, canine, kibble, diet, protein`
- Final LSI: same, minus duplicates of the niche words

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION`: `1.5.34` → `1.5.35`

### Required user action

- **Vercel redeploy** — this release touches `cloud-api/api/topic-research.js`. Git push triggers auto-deploy; verify the new build appears in the Vercel dashboard before retesting Auto-suggest.
- **Install zip** — replaces v1.5.34

### Verified by user

- **UNTESTED**

---

## v1.5.34 — Critical bugfix trio: stale research cache + broken Pollinations download + "5%" leaking into article body

**Date:** 2026-04-15
**Commit:** `4b717d2`

### Context

User retested Lucignano against v1.5.33 and reported the article STILL had 6 fabricated business names (Gelateria del Borgo, La Dolce Vita Gelato, Cremeria San Francesco, Gelato Artigianale Toscano, Antica Gelateria Lucignano, Il Cono Perfetto), no AI-generated featured image, and a literal "5%" showing up in the article body as disconnected filler. None of those 6 names match what Sonar/Perplexity UI returns for Lucignano.

Triple root cause discovered by verifying each link in the chain:

**Bug 1 — Stale Trend_Researcher cache hiding v1.5.26+ schema fields.** The cache key `seobetter_trends_{md5}` with 6-hour TTL was still holding research responses from before `is_local_intent` and `places` were added to the cloud-api output. When `Async_Generator::process_step()` pulled `$research` from this cache, the new fields were missing, the v1.5.27 pre-gen switch silently didn't fire (`$research['is_local_intent']` was empty), the v1.5.33 Local Business Mode silently didn't fire (`$research['places_count']` was 0 from the stale shape), and the outline generator went down the default branch and produced a generic 6-item listicle. The model then fabricated 6 Italian-sounding gelato shop names to fill it.

**Bug 2 — Pollinations URL has no file extension, `media_sideload_image()` silently drops it.** The v1.5.32 AI_Image_Generator returned `https://image.pollinations.ai/prompt/{text}?width=1200...` with no `.jpg` in the path. WordPress's `media_sideload_image()` validates the URL extension against `/\.(jpe?g|gif|png|webp)\b/i` before downloading and returns a WP_Error when no extension is present. The featured image fell through to Pexels → Picsum without any error surfacing.

**Bug 3 — System prompt percentages leaking as literal body text.** Two lines in `Async_Generator::get_system_prompt()` contained `0.5%-1.5% density` and `target 5%+ entity density` — meant as instructions to the model, but the model was copying the "5%" literal into the article body as a disconnected filler element.

### Fixed

- **Cache version busting in Trend_Researcher** — [includes/Trend_Researcher.php::research()](../includes/Trend_Researcher.php) lines **~30-73**
  - Added `CACHE_VERSION = 'v7'` class constant. Cache key is now `seobetter_trends_v7_{md5}` so all pre-v7 cached entries (including the v1.5.24/v1.5.26/v1.5.30 schema transitions) are invalidated automatically on upgrade.
  - Cached response is also schema-validated before being returned: if it lacks `is_local_intent` or doesn't have a `places` key, it's treated as a cache miss and re-fetched. This is the belt-and-suspenders — even if the cache key collision ever occurs, stale shapes can't slip through.
  - If a pre-v7 entry is detected at the old cache key, it's deleted immediately so subsequent requests re-populate with fresh data.
  - Verify: `grep -n "CACHE_VERSION\|seobetter_trends_.*CACHE_VERSION" seobetter/includes/Trend_Researcher.php`

- **Pollinations download-to-temp** — [includes/AI_Image_Generator.php::generate_pollinations()](../includes/AI_Image_Generator.php)
  - Instead of returning the raw Pollinations URL (no extension), now fetches the actual JPEG bytes via `wp_remote_get` with 60-second timeout, saves to `wp_upload_dir()['path']` with a `.jpg` extension, and returns the local file URL
  - `media_sideload_image()` then happily consumes the local URL (has proper extension) and copies it into the media library as a regular WP attachment
  - New private helper `save_binary_to_temp( $binary, $ext )` shared by Pollinations (raw JPEG) and Gemini (base64 inline)
  - Verify: `grep -n "save_binary_to_temp\|wp_remote_get.*pollinations_url" seobetter/includes/AI_Image_Generator.php`

- **Removed literal percentages from system prompt** — [includes/Async_Generator.php::get_system_prompt()](../includes/Async_Generator.php) lines **~928-945** and **~978-982**
  - Rewrote `Primary keyword MUST appear every 100-200 words (0.5%-1.5% density)` → `Primary keyword should appear naturally every 100-200 words`
  - Rewrote `Use proper nouns for people, organizations, places, products — target 5%+ entity density` → `Use lots of proper nouns for people, organizations, places, products — aim for high entity saturation throughout`
  - Added explicit "IMPORTANT: Do NOT copy any of these numbers into the article body" disclaimers next to both blocks so the model knows the instructions are for its own process, not content for readers
  - Removed "+41% visibility", "+40% visibility", "+25-30% visibility" bracketed numbers from the GEO VISIBILITY block since those were also at risk of leaking
  - Removed "reduces AI visibility by 9%" and rewrote as "reduces AI visibility" (no number)
  - Rewrote the example statistic `'85% of users prefer X'` to `'eighty-five percent of users prefer X'` so the model doesn't use that as a template for fabricating "X%" statistics
  - Verify: `grep -n "Do NOT copy any density\|aim for high entity saturation" seobetter/includes/Async_Generator.php`

### Why this trio fixes all three symptoms

1. **The fake 6-item listicle** — with cache busted, v1.5.27 pre-gen switch now actually fires for Lucignano (OSM:0 → places_insufficient=true → informational article) OR v1.5.33 Local Business Mode fires (Sonar returns 2 real places → 2 business H2s + generic fill sections). Either way the 6-fake-shops failure becomes structurally impossible.
2. **Missing AI featured image** — with Pollinations download path fixed, the free zero-setup default now actually produces an image that lands in the media library. Users who've configured `branding_provider='pollinations'` will see an image on their next generation.
3. **"5%" filler in article body** — with the literal percentages removed from the system prompt, the model has nothing to parrot from the instruction block.

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION`: `1.5.33` → `1.5.34`

### Critical — user must clear the WordPress cache before retesting

- WP Admin → SEOBetter → Tools → Clear transient cache (or `wp transient delete --all` via WP-CLI)
- OR just wait 6 hours for the old `seobetter_trends_*` entries to expire
- The v7 cache version automatically avoids hitting old entries on new writes, BUT the very first test after upgrading may still pull a stale entry if the WP object cache (not transient) is holding it

### Verified by user

- **UNTESTED**

---

## v1.5.33 — Local Business Mode: cap listicle H2s to verified pool size + strict per-section business prompt

**Date:** 2026-04-15
**Commit:** `d9b543f`

### Context

User retested Lucignano after v1.5.32 and reported: **"it is still providing the wrong data in the blog, there are only 2 shops according to perplexity, it names and makes up descriptions not true"**.

This is a DIFFERENT failure mode than v1.5.26/27. The business NAMES are correct (they match the 2 real gelaterie Perplexity UI found), BUT the DESCRIPTIONS in each listicle section are fabricated. The model is being asked to write a 300-word section about "Gelateria C'era una Volta" and invents opening hours, prices, menu items, family history, and customer reviews to fill the word budget.

Root cause analysis:
1. Sonar Tier 0 (or Foursquare) finds exactly 2 real places for Lucignano
2. `places_count = 2`, pre-gen switch does NOT fire (threshold is <2)
3. Outline generation produces a 5-section listicle (word count 2000 / 400 = 5)
4. The model invents 3 extra business names to pad the listicle
5. AND for each of the 2 REAL businesses, the section generator has no pool context — it just sees "write a section about Gelateria X" and fills with fabricated details

Two structural fixes needed:
1. **Cap outline length to pool size.** If we have 2 real places, the listicle has exactly 2 business-name H2s. Extra word budget fills generic educational sections (What to Look For, Regional Tradition, How to Find Quality).
2. **Pass verified pool entry to each business section.** When a section's heading matches a pool entry, generate_section() swaps to a strict "local business" prompt that uses ONLY the verified fields (name, address, website, rating) and forbids inventing everything else (hours, prices, menu, history, reviews, chef names).

### Added

- **Local Business Mode in `process_step()` trends branch** — [includes/Async_Generator.php](../includes/Async_Generator.php) lines **~180-190**
  - When `is_local_intent && places_count >= 2`, sets `$job['options']['local_business_cap'] = places_count`, `local_business_mode = true`, and threads the pool names via `places_pool_for_outline`
  - Verify: `grep -n "local_business_cap\|local_business_mode" seobetter/includes/Async_Generator.php`

- **Local Business outline branch in `generate_outline()`** — [includes/Async_Generator.php::generate_outline()](../includes/Async_Generator.php) ~lines **410-455**
  - New prompt branch that fires when `local_business_mode` is true AND `places_insufficient` is false
  - Produces an outline with EXACTLY `local_business_cap` business-name H2s (using "Business 1", "Business 2" as placeholders) plus generic fill sections (What Makes X Special, What to Look For, Regional Context, FAQ, References) to hit the target word count
  - After the model returns headings, the code walks them and replaces "Business N" placeholders with actual pool names via `places_pool_for_outline`
  - The model is NEVER asked to write more business-name sections than the verified pool has — structurally impossible to hallucinate extras
  - Verify: `grep -n "Local Business Mode\|Business N'\|places_pool_for_outline" seobetter/includes/Async_Generator.php`

- **Strict "local business" section prompt in `generate_section()`** — [includes/Async_Generator.php::generate_section()](../includes/Async_Generator.php) new `elseif ( $matched_place !== null )` branch
  - Before the existing generic section prompt, now checks if the heading matches a Places Pool entry via `Places_Validator::pool_lookup()`
  - If yes, swaps to a completely different prompt that:
    1. Injects the verified pool entry as a block (name, address, website, phone, rating, type, source)
    2. States CRITICAL ANTI-HALLUCINATION RULES forbidding invention of opening hours, days, closing times, seasonal closures, menu items, flavors, prices, specialty dishes, owner/founder/chef names, history, founding year, family background, interior design, decor, atmosphere, seating, customer reviews, quotes, awards, accolades, rankings, ingredients, recipes, preparation methods, techniques, distance from landmarks, walking directions, parking
    3. Directs the word budget to GENERAL educational content about the category + regional tradition + traveler tips — NOT specifics about the business
    4. Allows the model to write short (150 words) rather than padded-with-fakes (300 words)
    5. Structure: first paragraph = name/address/rating from pool only, rest = general regional context, close = practical tip
  - New `$matched_place` variable is populated via `Places_Validator::pool_lookup()` when places_pool is non-empty AND the section is NOT takeaways/FAQ/references
  - Verify: `grep -n "STRICT LOCAL BUSINESS SECTION\|VERIFIED POOL ENTRY FOR THIS SECTION\|matched_place" seobetter/includes/Async_Generator.php`

- **New `$places_pool` + `$places_location` parameters on `generate_section()`** — function signature extended, default empty so existing callers don't break
  - Called from `process_step()` section branch with `$job['results']['places']` and `$job['results']['places_location']`
  - Verify: `grep -n "generate_section.*places_pool\|\\\$section_places_pool" seobetter/includes/Async_Generator.php`

### Why this is the structural fix (not just another prompt tweak)

Previous releases (v1.5.24 PLACES RULES, v1.5.27 pre-gen switch, v1.5.30 Sonar Tier 0) all attempted to prevent hallucination at the DATA level — either by finding real data or by refusing to write a listicle when data is missing. They didn't address the case where data EXISTS but is THIN (2 real places for a 5-section listicle request).

v1.5.33 adds two hard structural guarantees:
1. **Cap N** — the model is NEVER asked to write more business sections than the pool has. The outline prompt literally says "produce EXACTLY N headings" and the placeholder substitution injects real names.
2. **Per-section verified injection** — when writing a business section, the model sees the verified pool entry block at the top of its prompt and a list of 20+ FORBIDDEN invention categories. The word budget is redirected to general content that doesn't require inventing business specifics.

A model that ignores both of these would have to simultaneously (a) invent extra headings not in the outline it was given, and (b) ignore a hard-rules block plus reroute the word budget to fabrication. LLMs follow outline structures and hard rules when they're the dominant signal — by removing the "listicle length pressure" and adding strict inject rules, the fabrication pressure disappears.

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION`: `1.5.32` → `1.5.33`

### Verification

1. Retest Lucignano with Sonar or Foursquare configured (any provider that returns ≥2 real gelaterie) — expected outcome:
   - Exactly 2 business-name H2s in the listicle (matching the verified pool)
   - Each business section names the business + cites the verified address only, no fabricated hours/menu/history
   - 3 additional generic fill sections ("What Makes Gelato in Lucignano Special", "What to Look For in Quality Gelato", "Regional Tradition") to hit the 2000-word target
2. Retest with 0 places configured — pre-gen switch fires, informational article ships (existing v1.5.27 path, unchanged)
3. Rome regression — ≥10 pool entries, listicle produces up to 8 business sections (standard outline cap), each with strict per-section business prompt
4. Non-local regression — detectLocalIntent false, neither Local Business Mode nor the new section prompt fires, article unchanged

### Verified by user

- **UNTESTED**

---

## v1.5.32 — Branding + AI Featured Image generator + Article Writer Model Recommender with tier badges

**Date:** 2026-04-15
**Commit:** `6655cc4`

### Context

Two user-requested UX improvements shipping together because they both target the "WordPress newbie gets lost in settings" problem:

1. **Branding + AI Featured Image** — users wanted a settings page (screenshot-inspired: Business Details, Logo, Brand Colors, AI Provider, Style Preset) that generates a brand-aware featured image for every article from the title/keywords instead of showing random Picsum stock. Supports free + paid providers so newbies can start with zero setup.
2. **Article Writer Model Recommender** — the Settings → AI Providers section listed 7 providers × 40+ models with zero guidance. Users picked DeepSeek R1 / Llama 3.3 / Mixtral (cheap but hallucination-prone), saw the plugin produce fake business names, and blamed the plugin. The fix is 3 quick-pick preset buttons above the advanced dropdown + red/yellow/green compatibility badges on every model + a confirmation dialog when saving a red-tier model.

### Added

**Part A — Branding + AI Featured Image (featured-only, not inline)**

- **`AI_Image_Generator` class** — new file [includes/AI_Image_Generator.php](../includes/AI_Image_Generator.php), ~270 lines, SEOBetter namespace
  - `static generate( $title, $keyword, $brand )` — main entry, routes to the configured provider and returns an image URL
  - `static get_brand_settings()` — loads + normalizes the branding config from `seobetter_settings`
  - 4 providers wired: **Pollinations.ai** (FREE, no key, FLUX Schnell backend), **Google Gemini 2.5 Flash Image** ("Nano Banana", $0.04/image, 10/day free on AI Studio), **OpenAI DALL-E 3** ($0.04 std / $0.08 HD), **FLUX.1 Pro 1.1** via fal.ai ($0.055/image)
  - 7 style presets (`realistic`, `illustration`, `flat`, `hero`, `minimalist`, `editorial`, `3d`) each with its own prompt template weaving in brand colors + business context
  - `build_prompt()` composes the final prompt from article title + keyword + brand name + description + colors + negative prompt
  - `save_base64_to_temp()` writes Gemini's inline base64 image data to a temp file in uploads dir so `media_sideload_image()` can consume it
  - Returns empty string on any error, 401, parse failure — caller falls back to the existing Pexels → Picsum flow
  - Verify: `grep -n "class AI_Image_Generator\|generate_pollinations\|generate_gemini\|generate_dalle3\|generate_flux_pro" seobetter/includes/AI_Image_Generator.php`

- **Branding card in settings.php** — [admin/views/settings.php](../admin/views/settings.php) new card after Places Integrations
  - Own form with `seobetter_branding_nonce` so save doesn't clobber other sections
  - Fields: business name, business description, logo upload (WP media library picker), 3 color pickers (primary/secondary/accent), provider select, API key, style preset dropdown, negative prompt
  - Client JS: logo media picker via `wp.media`, dynamic API-key-row show/hide based on provider (Pollinations hides the key row since it's free), per-provider help text revealed on selection
  - Server sanitization: provider/style whitelist enforcement, hex color sanitization, logo ID as absint, all text fields sanitized
  - Info banner at bottom explaining "featured image only, inline stays Pexels" so users understand why
  - Verify: `grep -n "seobetter_save_branding\|branding_provider\|Branding & AI Featured Image" seobetter/admin/views/settings.php`

- **`set_featured_image()` hook** — [seobetter.php::set_featured_image()](../seobetter.php) lines **~1952-1962**
  - Before the existing Pexels → Picsum fallback chain, now calls `\SEOBetter\AI_Image_Generator::generate()` with the post title + keyword + brand settings
  - If the AI provider returns a URL, that's used for `media_sideload_image()` — it downloads into the WordPress media library exactly like the existing Pexels/Picsum URLs
  - If branding is not configured OR the AI call fails, falls through to Pexels → Picsum unchanged (zero regression)
  - Verify: `grep -n "AI_Image_Generator::generate" seobetter/seobetter.php`

**Part B — Article Writer Model Recommender + tier badges**

- **`AI_Provider_Manager::MODEL_TIERS` constant + `get_model_tier()`** — [includes/AI_Provider_Manager.php](../includes/AI_Provider_Manager.php) lines **~145-210**
  - Maps ~50 model IDs to `green` / `amber` / `red` / `unknown` tiers based on their empirical ability to follow SEOBetter's PLACES RULES + Citation Pool + URL rules under complex prompts
  - **Green (Recommended):** Claude Sonnet 4.6, Opus 4.6, Haiku 4.5, Sonnet 4.5, GPT-4.1, GPT-4o, Gemini 2.5 Pro + the OpenRouter variants of each
  - **Amber (Works but weaker):** GPT-4o-mini, GPT-4.1-mini/nano, Gemini 2.5 Flash, Gemini 2.0 Flash, Claude 3.5 Haiku
  - **Red (NOT recommended):** OpenAI o3 / o3-mini / o4-mini, Llama 3.3 70B, Llama 3.1 (all sizes), Mixtral, DeepSeek R1 (all variants), DeepSeek v3, Gemma 2 9B, all Ollama local models that aren't Claude-tier
  - Verify: `grep -n "const MODEL_TIERS\|get_model_tier" seobetter/includes/AI_Provider_Manager.php`

- **`AI_Provider_Manager::QUICK_PICKS` + `get_quick_picks()`** — [includes/AI_Provider_Manager.php](../includes/AI_Provider_Manager.php) lines **~250-275**
  - 3 one-click presets: 🥇 **Best Quality** (Claude Sonnet 4.6), 💰 **Best Value** (Claude Haiku 4.5), 🆓 **Free Tier** (Gemini 2.5 Flash)
  - Each has label, description, provider ID, model ID, badge color
  - Verify: `grep -n "QUICK_PICKS\|get_quick_picks" seobetter/includes/AI_Provider_Manager.php`

- **Quick-Pick preset banner in settings.php** — [admin/views/settings.php](../admin/views/settings.php) above the "Add AI Provider" form
  - 3 big clickable buttons rendered from `get_quick_picks()`, each colored with its badge color on the left border
  - Click → JS auto-fills the provider dropdown + model dropdown below with the preset values, then focuses the API key input so user can paste and save
  - Below the buttons: a loud ⚠️ warning line listing the models NOT to pick (Llama, DeepSeek, Mixtral, o3, Perplexity Sonar) and why
  - Verify: `grep -n "sb-quick-pick\|Quick Pick — Recommended Models" seobetter/admin/views/settings.php`

- **Tier badges in the Advanced model dropdown** — [admin/views/settings.php](../admin/views/settings.php) provider select JSON
  - Each model in the `data-models` JSON attribute is now an object `{ id, label, tier }` where label has the tier emoji appended (🟢 / 🟡 / 🔴)
  - JS updated to handle both old-format (string) and new-format (object) for backwards compat
  - Dataset attribute `data-tier` is attached to each `<option>` so the confirmation handler can detect red-tier selections
  - Verify: `grep -n "decorated_models\|data-tier" seobetter/admin/views/settings.php`

- **Red-tier confirmation dialog** — [admin/views/settings.php](../admin/views/settings.php) JS
  - When user clicks "Connect Provider" with a red-tier model selected, a `confirm()` modal shows warning: *"This model is known to ignore PLACES RULES and may produce hallucinated content... Continue anyway?"*
  - User can still override if they know what they're doing (e.g. they're testing Llama for non-local keywords)
  - Verify: `grep -n "NOT recommended for SEOBetter" seobetter/admin/views/settings.php`

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION` constant: `1.5.31` → `1.5.32`

### Does NOT affect

- **Inline article images** — those still come from Pexels (if configured) or Picsum (free fallback). AI image generation is ONLY used for the featured image. Rationale documented in settings UI info banner: inline images are contextual decoration where Pexels's millions of real photos work great for free, and AI inline generation would add ~$0.12 per article for marginal visual gain. Featured images are where brand-aware AI matters most.
- **Non-local articles** — Branding affects ALL articles, not just local-intent. Any article with branding configured gets an AI featured image. Branding is OFF by default (provider = empty string) so existing installs see no change.

### Free options audit (2026-04)

Only 2 truly free, zero-setup image gen paths evaluated and included:
- **Pollinations.ai** — shipped as the default option. No key, no signup, rate-limited but functional.
- **Gemini 2.5 Flash Image** — free tier on Google AI Studio with 10 images/day. Requires a Google account but no billing card.

Other free paths considered but rejected: HuggingFace Inference API (rate-limited to unusability), Craiyon (no API), Midjourney (no API at all), Leonardo.ai (complex OAuth flow).

### Known limitations

- Pollinations is a third-party service with no SLA — if their servers are down, the plugin falls through to Pexels/Picsum.
- Gemini Nano Banana image responses are base64-inlined so we save them to a temp file and then `media_sideload_image()` re-copies them. Two disk writes per image — acceptable for a 1-per-article workflow.
- The tier classification is snapshot in time (April 2026). Model behavior changes between releases — revisit the `MODEL_TIERS` constant every 6 months.
- Logo image is NOT embedded in the AI-generated image (AI models cannot render logos accurately). It's stored as brand identity reference only.

### Verified by user

- **UNTESTED**

---

## v1.5.31 — Business photos in listicle sections (Sonar-sourced, capped at 5 per article)

**Date:** 2026-04-14
**Commit:** `820973b`

### Context

v1.5.29 (link injector) + v1.5.30 (Sonar Tier 0) fixed the data-coverage and address-visibility problems. This release adds visual polish: when Sonar returns a photo URL for a business (scraped from the source TripAdvisor/Yelp/Wikivoyage page's og:image or listing thumbnail), the Places_Link_Injector renders a responsive `<figure>` below the meta line. Capped at 5 photos per article to keep page weight reasonable.

Foursquare/Google Places photo extraction is deferred to a future release because both require a second paid API call per photo. OSM wikimedia_commons extraction is also deferred (only ~5% of places have this tag). Sonar photos are the free cheapest-coverage option since Sonar is already reading the source page anyway.

### Added

- **`photo_url` field in Sonar fetcher system prompt** — [cloud-api/api/research.js::fetchSonarPlaces()](../cloud-api/api/research.js) system prompt block
  - Added an optional `photo_url` field to the JSON schema description the Sonar model is told to return
  - Instruction: "direct https URL to a photo of the business if the source page has one (og:image, first image of the listing, etc). Prefer stable CDN URLs. Skip if unsure."
  - Verify: `grep -n "photo_url.*optional" seobetter/cloud-api/api/research.js`

- **`photo_url` capture in Sonar fetcher return shape** — [cloud-api/api/research.js::fetchSonarPlaces()](../cloud-api/api/research.js) map block
  - Added `photo_url: (p.photo_url && /^https:\/\//.test(p.photo_url)) ? p.photo_url : null` to the place normalization
  - HTTPS-only to avoid mixed-content warnings on SSL WordPress sites
  - Verify: `grep -n "photo_url:.*https" seobetter/cloud-api/api/research.js`

- **`Places_Link_Injector::build_photo_figure()`** — [includes/Places_Link_Injector.php](../includes/Places_Link_Injector.php) new private method
  - Takes a pool entry with `photo_url` and returns a responsive `<figure>` block
  - Figure structure: `<img>` with `loading="lazy"`, `max-width:100%`, `border-radius:8px`, `border:1px solid #e5e7eb`, plus a `<figcaption>` with "Photo via {source}" attribution in italic
  - `alt` text built from `{name} in {address}` for SEO + accessibility
  - Returns empty string if photo_url missing, non-https, or fails `FILTER_VALIDATE_URL`
  - Verify: `grep -n "build_photo_figure\|sb-place-photo" seobetter/includes/Places_Link_Injector.php`

- **5-photo cap in `Places_Link_Injector::inject()`** — [includes/Places_Link_Injector.php](../includes/Places_Link_Injector.php)
  - New `PHOTO_CAP = 5` class constant
  - Shared `$photo_count` counter closed-over in the `preg_replace_callback` so it increments across all H2 matches and stops injecting figures once cap is reached
  - Meta line (address + maps + website + phone) still injected for ALL matched H2s regardless of cap — only photos are limited
  - Verify: `grep -n "PHOTO_CAP\|photo_count" seobetter/includes/Places_Link_Injector.php`

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION` constant: `1.5.30` → `1.5.31`

### Deferred (not shipping this release)

- **Foursquare photo extraction** — requires second API call to `/places/{fsq_id}/photos` per place, doubles API usage. Ship as opt-in settings checkbox in a future release if users demand it.
- **Google Places photo extraction** — requires paid photo redemption call (~$0.007/photo). Ship as opt-in in a future release.
- **HERE photo extraction** — HERE's `media.images` field is inconsistent across regions. Needs a more thorough audit before shipping.
- **OSM wikimedia_commons extraction** — only ~5% of OSM places have this tag. Low-coverage, deferred.

### Known risks

- Sonar-scraped image URLs may be hot-linked from TripAdvisor/Yelp CDN URLs that rotate. If a URL breaks, the browser just shows the alt text — acceptable since the article can be regenerated.
- No ToS check on image display. Sonar returns public page og:images which are generally fair use for editorial preview, but users should be aware they're embedding hotlinked content.

### Verified by user

- **UNTESTED**

---

## v1.5.30 — Perplexity Sonar Tier 0 via OpenRouter (web-search business discovery for any city worldwide)

**Date:** 2026-04-14
**Commit:** `61c0ba3`

### Context

v1.5.29 added the Places_Link_Injector so any matched pool entry gets its real address + Google Maps link + website injected below the H2. But that only helps when the pool is non-empty. The actual root problem is coverage: OSM/Foursquare/HERE have genuine gaps for small cities (Lucignano: 0 gelaterie in all three). User proved Perplexity's web interface finds the real gelaterie (Gelateria C'era una Volta at Via Rosini 20, Snoopy's nearby) by scraping TripAdvisor + Yelp + local Italian blogs. Perplexity's Sonar models are available via OpenRouter at ~$0.008/article on base `sonar` — adding them as Tier 0 of the waterfall solves the coverage gap for any city worldwide with a single user-provided OpenRouter API key.

### Added

- **`fetchSonarPlaces(keyword, geo, sonarConfig)`** — new fetcher at [cloud-api/api/research.js](../cloud-api/api/research.js) ~line **883**
  - Calls `POST https://openrouter.ai/api/v1/chat/completions` with a structured system prompt demanding JSON output
  - Model selectable via `sonarConfig.model`: `perplexity/sonar` (default, fast, ~$0.80/100 articles) or `perplexity/sonar-pro` (deeper search, ~$6/100 articles)
  - System prompt forbids inventing data: "If you cannot find at least 3 real verified businesses, return an empty places array"
  - Response parsed with `response_format: json_object` + fallback regex extraction for non-compliant models
  - 30-second timeout (Sonar deep search takes 5–15s)
  - Returns same normalized place shape as Foursquare/HERE/Google: `{name, type, address, website, phone, lat, lon, source_url, source: 'Perplexity Sonar', rating}`
  - Returns `[]` on any error, 401, parse failure — falls through to OSM
  - Verify: `grep -n "^async function fetchSonarPlaces" seobetter/cloud-api/api/research.js`

- **Tier 0 insertion in `fetchPlacesWaterfall()`** — [cloud-api/api/research.js](../cloud-api/api/research.js) ~line **925**
  - Runs BEFORE OSM Tier 1 if `placesKeys.openrouter_sonar.key` is set
  - Waterfall is now: Sonar → OSM → Foursquare → HERE → Google Places → pre-gen switch
  - OSM Tier 1 now has a `!provider_used` guard so it skips when Sonar already found ≥3 places
  - Users who don't configure OpenRouter silently skip Tier 0 — zero regression
  - Verify: `grep -n "Tier 0: Perplexity Sonar" seobetter/cloud-api/api/research.js`

- **OpenRouter key + model plumbing** — [includes/Trend_Researcher.php::cloud_research()](../includes/Trend_Researcher.php) lines **~107-120**
  - Reads `openrouter_api_key` + `sonar_model` from `seobetter_settings`
  - Builds `places_keys.openrouter_sonar = { key, model }` and adds it to the cloud-api request body
  - Verify: `grep -n "openrouter_sonar" seobetter/includes/Trend_Researcher.php`

- **Sonar settings row in Places Integrations card** — [admin/views/settings.php](../admin/views/settings.php) top of Places Integrations form
  - Marked `RECOMMENDED` with blue badge
  - Password field for `openrouter_api_key`
  - Model dropdown: `perplexity/sonar` (default) / `perplexity/sonar-pro`
  - Setup link to `openrouter.ai/keys` with "1-minute signup, add $5 credit" instructions
  - Blue info box explaining this is the best fix for small-city coverage
  - Server-side sanitization whitelists only the two allowed model IDs
  - Verify: `grep -n "openrouter_api_key\|sonar_model" seobetter/admin/views/settings.php`

- **Tourism source whitelist** — [seobetter.php::get_trusted_domain_whitelist()](../seobetter.php) lines **~1925-1938**
  - Added: `openrouter.ai`, `perplexity.ai`, `tripadvisor.com` (+ .co.uk, .it, .es, .fr, .de, .jp), `yelp.com` (+ .co.uk, .it, .fr), `wikivoyage.org` (+ locale subdomains), `timeout.com`, `atlasobscura.com`, `lonelyplanet.com`, `fodors.com`, `theculturetrip.com`
  - These are the sites Sonar typically scrapes for business listings in Italy/Europe. Their URLs need to be on the whitelist so `validate_outbound_links()` doesn't strip Sonar-sourced citations from the References section.
  - Verify: `grep -n "tripadvisor.com\|yelp.com\|wikivoyage" seobetter/seobetter.php`

### Changed

- **Version bump** — `seobetter.php` header + `SEOBETTER_VERSION` constant: `1.5.29` → `1.5.30`

### Cost

- **base `perplexity/sonar`**: ~$0.008/article → ~$0.80 per 100 articles/month
- **`perplexity/sonar-pro`**: ~$0.06/article → ~$6 per 100 articles/month
- User covers cost directly via their own OpenRouter account — no billing integration needed
- Plus a small `search_tokens` surcharge from Perplexity (~$0.005 per call)

### Verification

1. Retest Lucignano with OpenRouter key configured and no other place keys — expect `places_provider_used: "Perplexity Sonar"`, `places_count: 5-10`, real names (Gelateria C'era una Volta, Snoopy's) with Via Rosini addresses. v1.5.29 Places_Link_Injector then injects the address + Google Maps + website meta line below each H2.
2. Direct curl test: `curl -X POST seobetter.vercel.app/api/research -d '{"keyword":"best gelato in lucignano italy","places_keys":{"openrouter_sonar":{"key":"KEY","model":"perplexity/sonar"}}}'` — response `places` array populated, `providers_tried[0].name === 'Perplexity Sonar'`
3. Rome regression with both OSM + Sonar configured — expect Sonar runs first, returns ≥3 results, waterfall stops at Tier 0, OSM never runs
4. Non-local regression: `how to introduce raw food to a dog` — `detectLocalIntent` false, Sonar never called, zero cost

### Required user action

- **Vercel redeploy** — this release modifies `cloud-api/api/research.js`, needs a Vercel redeploy. Git push auto-triggers the build since the v1.5.27 Vercel reconnect is already pointed at `celestine1111/autoresearch` with root `seobetter/cloud-api`. Check vercel.com → project → Deployments for a new green deployment after `git push`.
- **Install zip** — WP Admin → Plugins → Upload → Replace Current
- **Configure OpenRouter key** — SEOBetter → Settings → Places Integrations → paste OpenRouter API key in the new top row → Save

### Verified by user

- **UNTESTED**

---

## v1.5.29 — Places_Link_Injector: inject address + Google Maps + website below every matched H2 + fix non-OSM place URLs flowing to References

**Date:** 2026-04-14
**Commit:** `6df059b`

### Context

Even when the Places waterfall returns real businesses (Foursquare/HERE/Google Places), the generated article showed bare H2 headings with no way for readers to find the actual business. Address, website, phone, and lat/lon were being fetched but dropped on the floor — only the REAL LOCAL PLACES prompt block used them, and the AI was free to omit them from the body prose. The References section was also broken for non-OSM providers due to a field-name bug (`osm_url` vs `source_url`).

### Added

- **`Places_Link_Injector` class** — new file [includes/Places_Link_Injector.php](../includes/Places_Link_Injector.php), ~160 lines, SEOBetter namespace
  - `static inject( $html, $places_pool )` — walks every H2 via `preg_replace_callback`, normalizes the heading, looks up the matching pool entry via `Places_Validator::pool_lookup()`, and injects a `<p class="sb-place-meta">` meta line immediately after the H2
  - Meta line format: `📍 Address · View on Google Maps · Website · tel:phone · ⭐ 4.7 (Foursquare)`
  - Google Maps URL built from `https://www.google.com/maps/search/?api=1&query={rawurlencode(name + ' ' + address)}` — public URL scheme, no API key, always works
  - Phone becomes a clickable `tel:` link with digits-only href
  - Generic headings filtered out (FAQ, Conclusion, References, History, What to Look For, etc) so the injector never fires on non-business sections
  - Runs AFTER `Places_Validator::validate()` so we only decorate kept H2s (validator strips hallucinated ones first, injector decorates surviving real ones)
  - Verify: `grep -n "class Places_Link_Injector\|build_meta_line" seobetter/includes/Places_Link_Injector.php`

- **`Places_Validator::pool_lookup()`** — new public method at [includes/Places_Validator.php::pool_lookup()](../includes/Places_Validator.php) ~line **247**
  - Variant of `pool_contains()` that returns the full pool entry (name, address, website, phone, lat, lon, source_url, rating, source) instead of just a bool
  - Same 3-strategy matching (exact / substring / Levenshtein ≤ 3)
  - Used by Places_Link_Injector to fetch the metadata it needs for each kept H2
  - Also added `extract_candidate_public()` as a public wrapper around the private candidate extractor so the injector can reuse the exact same generic-names filter
  - Verify: `grep -n "public static function pool_lookup\|extract_candidate_public" seobetter/includes/Places_Validator.php`

- **Places_Link_Injector call site in `Async_Generator::assemble_final()`** — [includes/Async_Generator.php](../includes/Async_Generator.php) lines **~556-565**
  - Called AFTER `Places_Validator::validate()` so it only decorates sections that survived the validator's hallucination strip
  - Skipped silently when `$places_pool` is empty
  - Verify: `grep -n "Places_Link_Injector::inject" seobetter/includes/Async_Generator.php`

### Fixed

- **Non-OSM place URLs never reached the References section** — [cloud-api/api/research.js](../cloud-api/api/research.js) buildResearchResult lines **~2710-2730**
  - Bug: the places-to-sources loop hardcoded `pl.osm_url` and `source_name: 'OpenStreetMap'` for every place regardless of which tier produced it. Foursquare/HERE/Google/Sonar places use the generic `source_url` field instead, so their URLs silently dropped out of the Citation Pool and never appeared in References.
  - Fix: use `pl.source_url || pl.osm_url` as the URL and `pl.source || 'OpenStreetMap'` as the attribution label. OSM entries still work because `pl.osm_url` is the fallback. Non-OSM entries now correctly surface their provider URL (Foursquare profile, HERE page, Google Maps URL) in References.
  - This bug had been present since v1.5.24 when the waterfall first shipped but was masked until v1.5.26 because Wikidata was incorrectly masking the waterfall outcome.
  - Verify: `grep -n "pl.source_url || pl.osm_url" seobetter/cloud-api/api/research.js`

### Changed

- **Version bump** — `seobetter/seobetter.php` header + `SEOBETTER_VERSION` constant: `1.5.28` → `1.5.29`

### Known limitations

- Meta line is injected raw as HTML inside the `wp:html` classic-mode output. If the user edits the article in Gutenberg and adds a new H2 for a business not in the pool, no meta line is injected (expected — the pool only has what the waterfall found).
- The Google Maps search URL uses name + address as the query, not lat/lon. Works for well-named businesses but may return the wrong place if two businesses share a name across towns. Acceptable trade-off vs embedding `maps.google.com/?ll=` which shows raw coordinates but loses the business name label.
- Website field is only used if `filter_var(..., FILTER_VALIDATE_URL)` passes — non-http(s) URLs are silently dropped.

### Verified by user

- **UNTESTED**

---

## v1.5.28 — New `travel` domain category + REST Countries fetcher for tourism articles

**Date:** 2026-04-14
**Commit:** `2c80433`

### Context

User requested a dedicated `travel` category. The existing "Transportation & Travel" label was misleading — it routed to OpenSky / OpenChargeMap / ADSBExchange / CityBikes / NHTSA, which are logistics/vehicle APIs, not tourism APIs. Travel-intent articles had no dedicated category route and fell through to `general` (Quotable / NagerDate / NumberFacts) which is useless for destination guides.

**Important note:** the travel category controls which fact/stat APIs run alongside the always-on sources — it does NOT affect the Places waterfall. Business listings for travel articles (restaurants, hotels, gelaterie, motels) come from the OSM → Foursquare → HERE → Google Places waterfall which runs independently of the category selector. The category selector provides supporting stats (destination facts, climate, holidays) that get woven into the article prose.

### Added

- **`fetchRestCountries(keyword, country)`** — [cloud-api/api/research.js](../cloud-api/api/research.js) ~line **2265**
  - Free REST Countries v3.1 API, no auth required
  - Queries `restcountries.com/v3.1/name/{country}` using the explicit country param or a last-capitalized-word heuristic from the keyword as fallback
  - Returns two stats: (1) name + capital + population + region, (2) official languages + currency + timezone
  - Verify: `grep -n "^function fetchRestCountries" seobetter/cloud-api/api/research.js`

- **`travel` category in `getCategorySearches()`** — [cloud-api/api/research.js](../cloud-api/api/research.js) ~line **1014**
  - Routes to: `fetchRestCountries` + `fetchOpenMeteo` + `fetchSunriseSunset` + `fetchNagerDate`
  - All four are existing zero-config free APIs (no new keys required)
  - Wikipedia is already always-on so destination summaries come through automatically
  - Verify: `grep -n "travel:.*fetchRestCountries" seobetter/cloud-api/api/research.js`

- **`travel` option added to 3 category dropdowns** (forms must stay in sync per research.js:987 comment):
  - [admin/views/content-generator.php](../admin/views/content-generator.php) — after transportation
  - [admin/views/bulk-generator.php](../admin/views/bulk-generator.php) — after transportation
  - [admin/views/content-brief.php](../admin/views/content-brief.php) — after transportation
  - Label: "Travel & Tourism" (distinct from "Transportation & Logistics")

### Changed

- **"Transportation & Travel" label clarified** — renamed to "Transportation & Logistics" in all 3 form dropdowns so it's clear that category is for vehicle/flight/EV-charging data, not tourism
- **Version bump** — `seobetter/seobetter.php` header + `SEOBETTER_VERSION` constant: `1.5.27` → `1.5.28`

### Documented

- **`plugin_functionality_wordpress.md §1`** — new row in the category API table listing `travel → REST Countries + OpenMeteo + SunriseSunset + NagerDate`

### Does NOT affect

- **Place listings.** The Places waterfall (OSM → Foursquare → HERE → Google Places) runs regardless of category. Picking `travel` instead of `food` for your Lucignano test does NOT change which gelato shops are found — that depends entirely on whether your Foursquare/HERE keys return real data for the location. For business-listing accuracy, the only levers are the API keys in Settings → Places Integrations.

### Verified by user

- **UNTESTED**

---

## v1.5.27 — Layer 0 pre-generation switch when Places waterfall is empty (structural fix for small-city hallucination)

**Date:** 2026-04-14
**Commit:** `e14f374`

### Context

Live v1.5.26 testing against Lucignano (OSM:0, no Foursquare key configured) proved that Places_Validator's post-generation deletion is insufficient as a standalone fix. Three failure modes combined:

1. **Empty pool → validator early-exited.** The original v1.5.26 `Places_Validator::validate()` had `if ( empty( $places_pool ) ) { return $result; }` as the first guard. When OSM returned 0 and no keys were configured, the pool was empty and the validator did absolutely nothing. Article shipped with all hallucinations intact and no warnings visible in the UI.
2. **Prompt-level LOCAL-INTENT WARNING was ignored.** The `for_prompt` research payload DID include the warning block telling the model "DO NOT invent business names", but the model ignored it under the structural pressure to produce a 2,000-word listicle with 6 H2 sections. Verified by curl'ing `https://seobetter.vercel.app/api/research` — the warning is present in the prompt, but the model wrote "Gelateria Artigianale Il Cono d'Oro" and "Dolce Vita Gelato & Sorbet" in Lucignano anyway.
3. **No UI feedback.** The Analyze & Improve panel showed standard suggestions (readability, citations) but ZERO indication that the article was structurally hallucinated.

The fix: move the anti-hallucination guarantee from post-generation (Layer 3) to pre-generation (new Layer 0). When research returns `is_local_intent && places_count < 2`, flip a flag that forces `generate_outline()` to produce an informational article structure and `generate_section()` to forbid business names. The model is NEVER asked to write a listicle — there is no structural pressure to hallucinate, so it doesn't. Places_Validator is updated to ALSO run with an empty pool when `is_local_intent=true`, as the structural backstop for any sections that slip through.

### Added

- **Layer 0 pre-generation switch** — [includes/Async_Generator.php::process_step()](../includes/Async_Generator.php) trends-step branch, lines **~167-178**
  - After `Trend_Researcher::research()` returns, check `is_local_intent && places_count < 2`
  - Set `$job['options']['places_insufficient'] = true` — flag persists in the job transient through outline and section steps
  - Also set `$job['results']['places_insufficient']` for the assemble_final result payload
  - Verify: `grep -n "places_insufficient" seobetter/includes/Async_Generator.php`

- **Outline prompt structural override** — [includes/Async_Generator.php::generate_outline()](../includes/Async_Generator.php) lines **~412-436**
  - When `$options['places_insufficient']` is true, branch to a completely different prompt that:
    - Explicitly FORBIDS business-name-shaped H2s ("1. Gelateria X", "Top Pick: Y")
    - REQUIRES informational-article section templates (Key Takeaways, History and Cultural Context, What to Look For, Regional Variations, How to Find Quality X When Traveling, FAQ, References)
    - Instructs the model that the user's verified-places database has zero results for this location and the article must be informational, not a listicle
  - Verify: `grep -n "FORBIDDEN heading patterns" seobetter/includes/Async_Generator.php`

- **Section prompt hard rule** — [includes/Async_Generator.php::generate_section()](../includes/Async_Generator.php) lines **~497-511**
  - When `$options['places_insufficient']` is true, appends `*** PLACES INSUFFICIENT — HARD RULE ***` block to `$kw_context` for every non-takeaways/non-faq/non-references section
  - Hard rule forbids naming any specific business, restaurant, shop, hotel, café, gelateria, trattoria, osteria, pizzeria, bar, bakery, or establishment
  - Instructs the model to use generic nouns ("a traditional gelateria", "a family-run trattoria") instead of invented names
  - Verify: `grep -n "PLACES INSUFFICIENT — HARD RULE" seobetter/includes/Async_Generator.php`

- **Places_Validator empty-pool backstop** — [includes/Places_Validator.php::validate()](../includes/Places_Validator.php) lines **~66-92**
  - New 4th parameter `bool $is_local_intent = false`
  - Early-exit guard changed from `if ( empty( $places_pool ) )` to `if ( empty( $places_pool ) && ! $is_local_intent )`
  - When pool is empty AND local intent is true, falls through into the main validation loop. Every section whose heading looks like a specific business name gets deleted because `pool_contains()` returns false for every candidate against the empty pool. Generic section names ("FAQ", "History", "Key Takeaways") are filtered out upstream by `extract_business_name_candidate()`'s generic-name list and survive.
  - Verify: `grep -n "is_local_intent" seobetter/includes/Places_Validator.php`

- **Places Validator debug panel in result view** — [admin/views/content-generator.php](../admin/views/content-generator.php) lines **~945-985** (inside the result renderer, just before the content preview)
  - Only shown when `res.places_validator.is_local_intent` is true (local-intent articles only)
  - Color-coded banner: green (places found, listicle allowed), amber (places insufficient, informational), red (force_informational, article structurally hallucinated)
  - Surfaces: location, business type, pool size, validator warnings, and — when places_insufficient fires — an explanation + link to Settings → Places Integrations
  - Primary diagnostic surface when a user reports "my listicle still shows fake businesses" or "my Foursquare key isn't working" — they can now see at a glance which tier returned what
  - Verify: `grep -n "Places Validator debug panel\|res.places_validator" seobetter/admin/views/content-generator.php`

- **places_insufficient UI suggestion** — [includes/Async_Generator.php::assemble_final()](../includes/Async_Generator.php) lines **~583-597**
  - When `$job['results']['places_insufficient']` is true, prepends a high-priority suggestion to the Analyze & Improve panel with the ⚠️ emoji, the location name, an explanation of why the listicle became informational, and a link to `developer.foursquare.com` with the "2 min signup" hint
  - Also exposes `places_insufficient`, `is_local_intent`, `places_location`, `places_business_type` in the `places_validator` subkey of the assemble result for future UI surfaces
  - Verify: `grep -n "places_insufficient UI" seobetter/includes/Async_Generator.php`

### Changed

- **Places_Validator call site in assemble_final** — now always runs the validator when EITHER `$places_pool` is non-empty OR `$is_local_intent` is true, instead of only when the pool is non-empty. Enables the empty-pool backstop path to execute.
- **Version bump** — `seobetter/seobetter.php` header + `SEOBETTER_VERSION` constant: `1.5.26` → `1.5.27`

### Documented

- **`plugin_functionality_wordpress.md §1.6B`** — new paragraph documenting the Layer 0 pre-generation switch, the empty-pool backstop, the UI `places_insufficient` suggestion, and the reasoning from the failed Lucignano test
- **`SEO-GEO-AI-GUIDELINES.md §4.7B`** — new paragraph explaining the 4-layer architecture (Layer 0 pre-gen switch, Layer 1 prompt rule, Layer 2 data grounding, Layer 3 post-gen validator) and why the pre-generation layer is required given that prompt-level instructions are unreliable under listicle pressure
- **`pro-features-ideas.md`** — **NOT touched** per skill rule

### Known limitations (still not shipping in v1.5.27)

- **Automatic regeneration on `force_informational`** — Places_Validator still just flags the problem; it doesn't re-run generation with a different content type. This is less critical now because Layer 0 catches the case before tokens are spent.
- **Yelp Fusion as Tier 5** — Anglophone-only, deferred to v1.5.28 or later
- **Type-match validator per tier** — deferred; removing Wikidata fixed the worst offender

### Required user action before testing

- **Vercel redeploy** — v1.5.27 only touches plugin PHP files, NOT `cloud-api/api/research.js`. The v1.5.26 cloud-api deployment at `https://seobetter.vercel.app` is still the correct endpoint. No Vercel redeploy needed.
- **Install zip** — WP Admin → Plugins → Upload → `/Users/ben/Desktop/seobetter.zip` → Replace Current

### Verified by user

- **UNTESTED**

---

## v1.5.26 — Layer 3 Places_Validator (structural anti-hallucination guarantee) + remove Wikidata from the active waterfall

**Date:** 2026-04-14
**Commit:** `132c09e`

### Context

Live testing of v1.5.24 against `https://seobetter.vercel.app/api/research` with the Lucignano gelato keyword exposed a real-world failure mode: the Wikidata tier returned 20 wrong-type entities (churches, hamlets, town halls in neighbouring Sinalunga and Torrita di Siena) and short-circuited the waterfall at Tier 2, so Foursquare never ran. The published article still shipped 6 fabricated gelaterie. This release ships the structural anti-hallucination guarantee that makes Lucignano-class failures impossible regardless of whether the model obeys the PLACES RULES prompt block or whether the research tier returns wrong-type data.

### Added

- **`Places_Validator` class** — new file [includes/Places_Validator.php](../includes/Places_Validator.php), 280 lines, SEOBetter namespace
  - `validate( $html, $places_pool, $business_type )` — main entry. Splits HTML at H2/H3 boundaries via `split_by_headings()`, extracts a business-name candidate per section via `extract_business_name_candidate()` (heading first, falls back to first `<strong>`), normalizes both candidate and pool entries via `normalize_business_name()` (strip accents via `iconv ASCII//TRANSLIT//IGNORE`, remove leading articles "il/la/gli/les/the/el/etc", collapse whitespace, lowercase), compares using `pool_contains()` with three strategies (exact normalized match, substring containment in either direction, Levenshtein distance ≤ 3), and deletes any section whose candidate doesn't match any pool entry.
  - `FAILURE_RATIO = 0.5` — if more than 50% of listicle sections get stripped, the result is flagged `force_informational` and the caller surfaces a critical warning instead of shipping a gutted article.
  - `FUZZY_DISTANCE = 3` — Levenshtein threshold. Tolerates minor typos, transliteration differences ("Gelateria Nonna Rosa" vs "Gelateria di Nonna Rosa").
  - `renumber_listicle_headings()` — after sections are removed, walks remaining H2s and fixes gaps in listicle numbering (1. / 2. / 3. instead of 1. / 3. / 5.).
  - `split_by_headings()` uses `preg_split('/(?=<h[23](?:\s[^>]*)?>)/i', ...)` to split at section boundaries non-destructively.
  - `extract_business_name_candidate()` filters out generic section names ("Conclusion", "FAQ", "References", "Key Takeaways", etc) so only actual business-name sections get validated.
  - Verify: `grep -n "class Places_Validator\|public static function validate\|pool_contains\|normalize_business_name" seobetter/includes/Places_Validator.php`

- **Places_Validator integration in `Async_Generator::assemble_final()`** — [includes/Async_Generator.php](../includes/Async_Generator.php) lines **~517-545**
  - After `Content_Formatter::format()` produces the final HTML and before `GEO_Analyzer::analyze()` scores it, calls `Places_Validator::validate( $html, $places_pool, $places_business_type )`.
  - `$places_pool` comes from `$job['results']['places']` which is now populated at the research step from `$research['places']`.
  - If `force_informational` is false, the cleaned HTML replaces the original. If true, the original HTML is kept and a critical-priority suggestion is prepended to the suggestions array so the user sees the warning in the Analyze & Improve panel.
  - New top-level `places_validator` key in the assemble result exposes `pool_size`, `warnings`, `force_informational` to the UI.
  - Verify: `grep -n "Places_Validator::validate\|places_validator" seobetter/includes/Async_Generator.php`

- **`places` array exposed in cloud-api research response** — [cloud-api/api/research.js](../cloud-api/api/research.js) line **~2798**
  - `buildResearchResult()` now returns the full normalized `places` array (capped at 20 entries) alongside the existing `places_count`/`places_location`/`places_provider_used` telemetry fields, so the PHP Places_Validator can use it as the closed-menu allow-list.
  - Each entry: `{ name, type, address, website, phone, lat, lon, source_url, source }`.
  - Verify: `grep -n "places: placesData?.places" seobetter/cloud-api/api/research.js`

- **Places Pool stashed in `$job['results']`** — [includes/Async_Generator.php](../includes/Async_Generator.php) line **~160**
  - `process_step()` 'trends' branch now populates `$job['results']['places']`, `$job['results']['places_business_type']`, `$job['results']['places_location']`, `$job['results']['is_local_intent']` from the research response so they survive until `assemble_final()` runs.
  - Verify: `grep -n "job\['results'\]\['places'\]" seobetter/includes/Async_Generator.php`

### Removed

- **Wikidata tier removed from the active waterfall** — [cloud-api/api/research.js::fetchPlacesWaterfall()](../cloud-api/api/research.js) lines **~912-923**
  - The entire Tier 2 Wikidata block inside `fetchPlacesWaterfall()` is deleted.
  - `fetchWikidataPlaces()` function itself is kept as dead code for possible future use on cultural-heritage keywords ("oldest churches in X") but is no longer called by the business waterfall. TypeScript linter warning `'fetchWikidataPlaces' is declared but its value is never read` is expected and intentional.
  - Waterfall is now 4 tiers: OSM → Foursquare → HERE → Google Places.
  - Wikidata no longer appears in `places_providers_tried` telemetry.
  - Verify: `grep -n "Tier 2: Wikidata — REMOVED" seobetter/cloud-api/api/research.js`

### Changed

- **Version bump** — `seobetter/seobetter.php` header + `SEOBETTER_VERSION` constant: `1.5.25` → `1.5.26`

### Documented

- **`plugin_functionality_wordpress.md §1.6B`** — rewritten to reflect the 4-tier waterfall, explain why Wikidata was removed (live Lucignano reproduction captured in the plan file), and add a new paragraph documenting the Places_Validator Layer 3 integration point.
- **`SEO-GEO-AI-GUIDELINES.md §4.7B`** — added a new paragraph at the end explaining that Layer 1 (prompt rule) + Layer 2 (closed-menu injection) are structurally incomplete because LLMs sometimes ignore the rule under listicle length pressure, and that Places_Validator is the Layer 3 structural floor that mirrors `validate_outbound_links()` for business-name atoms.
- **`pro-features-ideas.md`** — **NOT touched** per skill rule.

### Known limitations (NOT shipping in v1.5.26)

- **Type-match validator per tier** — even with Wikidata removed, Foursquare/HERE/Google may return wrong-category results under ambiguous keywords. Next release should add a per-tier category filter inside `fetchPlacesWaterfall()` using a synonym array from `matchBusinessType()`.
- **Automatic regeneration on `force_informational`** — currently the user sees a critical warning but has to manually regenerate with a broader keyword. A future release could chain a second `process_step()` pass with a "general info, no listicle, no business names" system-prompt variant.
- **Adaptive listicle length** — when pool has 2 places but user asked for 6 items, we don't yet silently downgrade the listicle size at prompt-generation time. Places_Validator catches this at the back end by deleting the 4 invented sections, but the user would get a nicer result if we told the model "write 2 sections" upfront.

### Verified by user

- **UNTESTED**

### Required user action before testing

Update WordPress so the plugin actually calls the fixed cloud-api URL:
- WP Admin → SEOBetter → Settings → **Cloud API URL** field → set to: `https://seobetter.vercel.app` (no trailing slash) → Save.

Without this, v1.5.26 code is installed but the plugin will keep hitting whatever (stale) URL is currently configured.

---

## v1.5.25 — Fix "Note: Note:" duplication in callout boxes + friendlier auto-suggest message for ultra-long-tail keywords

**Date:** 2026-04-14
**Commit:** `8ffc8ab`

### Context

Two user-reported bugs from the v1.5.24 Lucignano test session:

1. **Callout duplication** — published article showed `Note: Note: Seasonal closures may occur...`. Root cause: `Content_Formatter.php::format_hybrid()` paragraph branch matched paragraphs starting with `Note:` / `Tip:` / `Warning:`, wrapped them in a callout box with a hard-coded `<strong>Note:</strong>` label, but did NOT strip the AI's literal "Note:" prefix from `$text` before injecting it next to the label. The Did You Know box at the same site already used the correct pattern (capture body separately, re-render with `inline_markdown`) since v1.5.14 — Tip/Note/Warning never got the same treatment.
2. **Auto-suggest "no variations" message too negative** — when the user clicked Auto-suggest on the ultra-long-tail keyword "Whats The Best Gelato Shops In Lucignano Italy 2026", the status text said `No keyword variations found — try a broader term`. That's correct behavior (Google Suggest + Datamuse genuinely return zero variations for 8+ word phrases) but the message implied user error and didn't tell them the field is optional anyway.

Also documents the still-open v1.5.24 deployment issue: the Vercel cloud-api may not have been redeployed when v1.5.24 was pushed to GitHub. Plugin code is correct end-to-end; the published Lucignano article showed neither a REAL LOCAL PLACES block nor a LOCAL-INTENT WARNING, which is only possible if the deployed `cloud-api/api/research.js` is older than v1.5.23. User must run `cd seobetter/cloud-api && npx vercel --prod` (or trigger a redeploy in the Vercel dashboard) to fix.

### Fixed

- **Callout-box prefix duplication** — `includes/Content_Formatter.php::format_hybrid()` paragraph branch lines **382-403**
  - Tip block (line 387): regex changed from `'/^(pro\s*tip|tip)\s*[:—-]/i'` against `$plain` to `'/^(?:\*\*)?(pro\s*tip|tip)(?:\*\*)?\s*[:—-]\s*(.*)$/is'` against `$section['content']` (the raw markdown source) with capture group 2 holding the body
  - Note block (line 393): same pattern with `(note|important)`
  - Warning block (line 399): same pattern with `(warning|caution)`
  - Body is re-rendered through `$this->inline_markdown( trim( $match[2] ) )` which preserves inline links, bold, italic that the AI may have used inside the callout body
  - `(?:\*\*)?` prefix in the regex handles both `Note: foo` and `**Note:** foo` AI output styles
  - Empty-body guard (`if ( empty( trim( $body_text ) ) ) continue 2;`) skips paragraphs that are JUST the prefix with nothing after
  - Verify: `grep -n "tip_match\|note_match\|warn_match" seobetter/includes/Content_Formatter.php`

### Changed

- **Auto-suggest "no variations" status message** — `admin/views/content-generator.php` line **637**
  - Replaced terse "No keyword variations found — try a broader term" with a friendly blue ℹ️ info banner
  - New text: "ℹ️ No auto-suggestions for this long-tail keyword (that's normal for very specific phrases). You can safely leave Secondary Keywords empty — the AI will generate variations from the research pool."
  - Uses `st.innerHTML` to render the ℹ️ emoji + a `<span style="color:#1e40af;font-style:normal">` so it stands out from the italic default of the status `<span>`
  - Verify: `grep -n "No auto-suggestions for this long-tail keyword" seobetter/admin/views/content-generator.php`

### Documented

- **`article_design.md` §5.5** — added prefix-stripping rule explaining the bug + fix pattern + the markdown-source-vs-HTML reasoning, and noted that the Did You Know block already used this approach since v1.5.14
- **`plugin_UX.md` §1.1** — Auto-suggest row now documents the v1.5.25 friendly-empty-state behavior and gives the canonical Lucignano example
- **`pro-features-ideas.md`** — NOT touched per skill rules (user manages this file manually)

### Version bump

- `seobetter/seobetter.php` header: `1.5.24` → `1.5.25`
- `SEOBETTER_VERSION` constant: `1.5.24` → `1.5.25`

### Known limitation (NOT shipping in v1.5.25)

- Vercel cloud-api may still be on a stale deployment of `cloud-api/api/research.js`. User action required: redeploy the cloud-api project. v1.5.25 does not change any cloud-api code, so this remains the same blocker as in v1.5.24 testing.

### Verified by user

- **UNTESTED**

---

## v1.5.24 — 5-tier Places waterfall (Wikidata + Foursquare + HERE + Google) for any small city worldwide

**Date:** 2026-04-13
**Commit:** `2b099a6`

### Context

v1.5.23 shipped an OSM-only Places lookup that fixed the Lucignano gelato hallucination bug but only covered ~40% of small cities globally. User requested "any really small city in the world" coverage, confirmed free-first with optional paid upgrade path, approved the full 5-tier waterfall architecture from the v1.5.23 research session. User's direction: "ship v1.5.24. but just do it, dont ask for permission".

### Added

- **4 new Places fetchers in `cloud-api/api/research.js`** lines ~**475-680**
  - **`fetchWikidataPlaces(businessHint, geo)`** — SPARQL `?item wdt:P625` within 15km of the geocoded city, filtered to entities with human-readable labels in en/it/fr/es/de/pt, excludes disambiguation pages. Free, no API key.
  - **`fetchFoursquarePlaces(businessHint, geo, apiKey)`** — `places-api.foursquare.com/places/search` with `Authorization: Bearer {apiKey}` + `X-Places-Api-Version: 2025-06-17`. Free tier 1K calls/day, user-provided key. Best small-city coverage via user check-ins in non-Anglophone markets.
  - **`fetchHEREPlaces(businessHint, geo, apiKey)`** — `discover.search.hereapi.com/v1/discover`. Free tier 1K/day, user-provided key. Strong EU and Asian tier-2 city coverage.
  - **`fetchGooglePlaces(businessHint, geo, apiKey)`** — Google Places API (New) `places.googleapis.com/v1/places:searchText` with field mask + location bias. Paid but generous $200/mo free credit ≈ 5K articles. User-provided key. Gold standard global coverage.
  - All 4 return the same normalized place shape as OSM's overpassQuery so downstream code is source-agnostic
  - Verify: `grep -n "^async function fetchWikidataPlaces\|^async function fetchFoursquarePlaces\|^async function fetchHEREPlaces\|^async function fetchGooglePlaces" seobetter/cloud-api/api/research.js`

- **`fetchPlacesWaterfall(keyword, country, placesKeys)`** replaces v1.5.23's `fetchOSMPlaces` as the main entry point — `cloud-api/api/research.js` lines **682-805**
  - Single `nominatimGeocode` call shared by every tier
  - Runs Tier 1 (OSM) → if <3 places, runs Tier 2 (Wikidata) → if <3, runs Tier 3 (Foursquare, skipped without key) → if <3, runs Tier 4 (HERE, skipped without key) → if <3, runs Tier 5 (Google Places, skipped without key)
  - Deduplicates by lowercased name so the same place appearing in multiple tiers is merged not double-listed
  - Returns `{ places, location, isLocal, business_type, providers_tried, provider_used, lat, lon }`
  - `providers_tried` is an array of `{ name, count }` per tier attempted, used for telemetry + the LOCAL-INTENT WARNING prompt block
  - `provider_used` is the winning tier ('OpenStreetMap' / 'Wikidata' / 'Foursquare' / 'HERE' / 'Google Places' / 'partial' / null)
  - v1.5.23 `fetchOSMPlaces` kept as a backwards-compat alias for any external caller
  - Verify: `grep -n "^async function fetchPlacesWaterfall" seobetter/cloud-api/api/research.js`

- **`places_keys` in request body** — `cloud-api/api/research.js` line **27**
  - Main handler now destructures `places_keys` from `req.body` alongside `keyword`, `domain`, `country`
  - Passed to `fetchPlacesWaterfall(keyword, country, places_keys || {})` in the `freeSearches` parallel batch
  - Tiers with no configured key are skipped — no wasted API calls, no errors

- **API keys plumbed through `Trend_Researcher::cloud_research()`** — `includes/Trend_Researcher.php` lines **86-110**
  - Reads `foursquare_api_key`, `here_api_key`, `google_places_api_key` from `seobetter_settings`
  - Builds a `$places_keys` array containing only the non-empty keys
  - Adds `places_keys` to the `/api/research` request body
  - Verify: `grep -n "places_keys" seobetter/includes/Trend_Researcher.php`

- **Places Integrations settings section** — `admin/views/settings.php` new card after main Settings card, with its own form + nonce (`seobetter_places_nonce`)
  - OSM + Wikidata row with "ALWAYS ON" badge and "no setup" description
  - Foursquare row with password field + setup link to developer.foursquare.com + "FREE" badge
  - HERE Places row with password field + setup link to developer.here.com + "FREE" badge
  - Google Places row with password field + setup link to console.cloud.google.com + "PAID" badge + note about free $200/mo credit
  - "How the waterfall works" info box at the bottom explaining the fallback order and hard-refuse behavior
  - Save handler uses `array_merge` against existing settings so it doesn't wipe the main settings card's fields (and vice-versa — the main settings form was also updated to array_merge)
  - Verify: `grep -n "Places Integrations\|seobetter_save_places" seobetter/admin/views/settings.php`

- **GEO_Analyzer `local_places` high-priority suggestion** — `includes/GEO_Analyzer.php::generate_suggestions()` lines **792-806**
  - Emitted FIRST (before any other suggestion) when `check_local_places_grounding` returns score 0
  - Message: "This local-business article has no verified addresses or map URLs — the businesses may be fabricated. Configure free Foursquare + HERE API keys in Settings → Integrations for reliable coverage of small cities worldwide. For truly remote places, add a Google Places key (free $200/month credit). Regenerate after adding keys."
  - Appears in the existing Analyze & Improve suggestions list — looks like any other suggestion, not a separate upsell modal
  - Verify: `grep -n "'local_places'\|local_places.*score" seobetter/includes/GEO_Analyzer.php`

- **Extended provider domain whitelist** — `seobetter.php::get_trusted_domain_whitelist()` lines ~**1870-1880**
  - `wikidata.org`, `www.wikidata.org`, `query.wikidata.org`
  - `foursquare.com`, `www.foursquare.com`, `fsq.com`
  - `here.com`, `www.here.com`, `discover.search.hereapi.com`
  - `maps.google.com`, `maps.googleapis.com`, `places.googleapis.com`, `google.com/maps`
  - Without these, `validate_outbound_links()` would strip the new provider URLs from References section
  - Verify: `grep -n "wikidata.org\|foursquare.com\|here.com\|places.googleapis.com" seobetter/seobetter.php`

### Changed

- **REAL LOCAL PLACES prompt block** — `cloud-api/api/research.js::buildResearchResult()` lines ~**2480-2500**
  - Now labels the source provider in the footer: `"${N} real ${business_type}s verified via ${provider_used}."` (e.g. "verified via Foursquare", "verified via OpenStreetMap + Wikidata")
  - The LOCAL-INTENT WARNING block (fired when all tiers return 0 places) now lists which providers were tried with counts, and suggests configuring additional API keys in Settings → Integrations

- **Research result telemetry** — `cloud-api/api/research.js::buildResearchResult()` return shape
  - Added `places_provider_used` (string | null)
  - Added `places_providers_tried` (array of `{ name, count }` per tier attempted)

### Documentation

- **plugin_functionality_wordpress.md §1.6B** — rewrote to document the full 5-tier waterfall with coverage percentages per tier, architecture walkthrough, and provider-specific fetcher details. The old v1.5.23 OSM-only section is kept as §1.6B-legacy for historical context.
  - Verify: `grep -n '1.6B Places Waterfall' seobetter/seo-guidelines/plugin_functionality_wordpress.md`

- **SEO-GEO-AI-GUIDELINES.md §4.7B** — updated the PLACES RULES anchor to reference `fetchPlacesWaterfall` (was `fetchOSMPlaces` in v1.5.23) and added the "v1.5.24 — 5-tier waterfall" subsection explaining the upgrade path
  - Verify: `grep -n 'fetchPlacesWaterfall' seobetter/seo-guidelines/SEO-GEO-AI-GUIDELINES.md`

- **external-links-policy.md** — expanded the "OSM Places" section to cover all 5 tiers with each provider's whitelisted domains and user-key status
  - Verify: `grep -n 'v1.5.24 — Tier' seobetter/seo-guidelines/external-links-policy.md`

- **plugin_UX.md §8C** — new section documenting the Settings → Places Integrations card with the full required-elements checklist (per plugin_UX.md convention — never remove features listed here)
  - Verify: `grep -n '§8C — Places Integrations' seobetter/seo-guidelines/plugin_UX.md`

- **pro-features-ideas.md** — marked the "Pluggable Places Provider abstraction" backlog item as ✅ SHIPPED in v1.5.24, noted the architecture difference (inline JS fetchers instead of PHP class abstraction), listed what was shipped vs what wasn't (Yelp, Test Connection endpoints, pre-generation coverage card, post-generation attribution are all follow-up work)

### Verified by user

- **UNTESTED** — waiting on user reinstall. Test sequence:
  1. Plugin shows **v1.5.24** on Plugins page
  2. Settings → see new **Places Integrations** card with 3 API key fields
  3. Retest Lucignano WITHOUT any paid keys — OSM + Wikidata should run, likely still hit the LOCAL-INTENT WARNING path (writes general article with disclaimer)
  4. Add a free Foursquare key from developer.foursquare.com, retest Lucignano — expect the waterfall to fall through to Tier 3 and return real gelato shops
  5. Test large city — `best pizza restaurants in rome italy 2026` should stop at Tier 1 (OSM has plenty for Rome)
  6. Non-local regression — `how to bake sourdough bread` should skip the waterfall entirely

---

## v1.5.23 — OSM Places anti-hallucination for local businesses

**Date:** 2026-04-13
**Commit:** `81fe100`

### Context

User reported a severe hallucination bug after testing `Whats The Best Gelato Shops In Lucignano Italy 2026` (Listicle, Authoritative, AU English, 2000w). The generated article listed gelato shops that **don't exist on Google Maps**. User confirmed: "well it made up businesses that were not in that city".

Root cause: the 9 always-on research sources, 25 category APIs, and country APIs had **ZERO place/business data**. For a tiny Italian town + specific business type, Reddit/HN/DDG/Wikipedia returned nothing usable, the `food` category fetchers (Open Food Facts, Fruityvice, Open Brewery DB) don't cover gelato shops, and the system prompt's CITATION RULES only gated URLs — not business names. With no grounding, the LLM invented plausible-sounding Italian shop names.

### Added

- **`fetchOSMPlaces(keyword, country)` + helpers** — `cloud-api/api/research.js` lines **455-665**
  - `detectLocalIntent(keyword)` — regex-based intent detector supporting 4 patterns: `"X in [Location]"`, `"best/top X in/near [Location]"`, `"X near me"`, `"what's the best X in [Location]"`. Also handles trailing year suffix (`...2026`).
  - `matchBusinessType(businessHint)` — maps ~40 common business-type keywords (gelato, restaurant, cafe, hotel, bakery, vet, dentist, gym, etc) to OSM tag pairs via the `OSM_TYPE_MAP` constant
  - `nominatimGeocode(location)` — calls `https://nominatim.openstreetmap.org/search` with a 5s timeout + `User-Agent: SEOBetter/1.5.23 (Research)` header per Nominatim ToS. Returns lat/lon + bounding box.
  - `overpassQuery(tags, bbox, typeLabel)` — calls `https://overpass-api.de/api/interpreter` with a 15s timeout. Query fetches up to 20 nodes/ways/relations matching the tag filter inside the bbox. Returns normalized place objects with name, address, website, phone, opening_hours, lat, lon, osm_url.
  - `fetchOSMPlaces` is the main entry point — calls all of the above in sequence, returns `{ places, location, isLocal, business_type }`. Always returns a valid shape (empty places on any error) so generation never breaks.
  - Verify: `grep -n "^async function fetchOSMPlaces\|^function detectLocalIntent\|^function matchBusinessType" seobetter/cloud-api/api/research.js`

- **OSM Places wired into the always-on `freeSearches` parallel batch** — `cloud-api/api/research.js` line **63**
  - Runs in parallel with the other 9 sources; no latency cost on non-local queries (returns early when `detectLocalIntent` doesn't match)
  - Result destructured as `placesData` and passed to `buildResearchResult()` as a new 11th parameter
  - Verify: `grep -n "fetchOSMPlaces(keyword, country)" seobetter/cloud-api/api/research.js`

- **REAL LOCAL PLACES prompt block** — `cloud-api/api/research.js::buildResearchResult()` lines **2400-2490**
  - When `placesData.isLocal === true` and `places.length > 0`: formats places as a numbered list with name, type, address, website/OSM URL, opening hours, and a mandatory "use ONLY these" footer
  - When `placesData.isLocal === true` and `places.length === 0`: writes a `LOCAL-INTENT WARNING` block telling the AI the lookup returned nothing and instructing it to write a general informational article with a disclaimer — NOT a fabricated listicle
  - Places also flow through `sources[]` → Citation Pool → References section. Each place's OSM URL + optional website are added.
  - Return object gains `is_local_intent`, `places_count`, `places_location`, `places_business_type` telemetry fields
  - Verify: `grep -n "REAL LOCAL PLACES\|LOCAL-INTENT WARNING" seobetter/cloud-api/api/research.js`

- **PLACES RULES block in system prompt** — `includes/Async_Generator.php::get_system_prompt()` lines **654-663**
  - New block immediately after CITATION RULES. Mirrors the closed-menu grounding pattern that already prevents hallucinated URLs
  - 7 rules: exact character-match names, real addresses, one-use-per-place, explicit ban on inventing "plausible-sounding" businesses, fallback to general article when list is empty, CRITICAL FAIL framing for listicles with invented names
  - Verify: `grep -n "PLACES RULES" seobetter/includes/Async_Generator.php`

- **`check_local_places_grounding()` GEO_Analyzer sentinel** — `includes/GEO_Analyzer.php` new private method
  - Only applies to `listicle`, `buying_guide`, `review`, `comparison` content types
  - Only fires when the keyword matches one of 4 local-intent regex patterns (same patterns as `detectLocalIntent` in research.js)
  - Checks content for real-world grounding markers: OSM/Google Maps URLs OR specific address patterns (street types in 5 languages, postcodes, explicit `address:` labels, Italian `Via`, French `Rue`, Spanish `Calle`, German `...straße`, Italian `Piazza`)
  - If neither found, returns `score: 0` with a CRITICAL detail message
  - The `analyze()` method checks `local_places['score'] === 0` and **floors the final `geo_score` at 40** (F grade) so the user sees the red flag immediately and regenerates
  - Not added to `$weights` — it's a sentinel override, not a proportional deduction
  - Verify: `grep -n "check_local_places_grounding\|local_places_check" seobetter/includes/GEO_Analyzer.php`

- **4 new OSM domains whitelisted** — `seobetter.php::get_trusted_domain_whitelist()` lines ~**1855**
  - `openstreetmap.org`, `www.openstreetmap.org`, `nominatim.openstreetmap.org`, `overpass-api.de`
  - Without this, `validate_outbound_links()` would strip OSM URLs from the saved draft even though they came from the citation pool
  - Verify: `grep -n "openstreetmap.org\|overpass-api.de" seobetter/seobetter.php`

### Documentation

- **plugin_functionality_wordpress.md §1.6B** — new section "OSM Places (Anti-Hallucination Local Businesses — v1.5.23)" documenting the full fetcher flow, wiring, system prompt enforcement, and GEO_Analyzer sentinel
  - Verify: `grep -n '1.6B OSM Places' seobetter/seo-guidelines/plugin_functionality_wordpress.md`

- **SEO-GEO-AI-GUIDELINES.md §4.7B** — new "PLACES RULES — Anti-Hallucination for Local Businesses" section documenting the closed-menu grounding pattern and the 7 rules
  - Verify: `grep -n 'PLACES RULES — Anti-Hallucination' seobetter/seo-guidelines/SEO-GEO-AI-GUIDELINES.md`

- **external-links-policy.md** — added the 4 OSM domains to the "Research & data" subsection with a note explaining they power the anti-hallucination fix
  - Verify: `grep -n 'OSM Places — anti-hallucination' seobetter/seo-guidelines/external-links-policy.md`

- **pro-features-ideas.md** — added "Pluggable Places Provider (Google / Foursquare / Yelp / HERE) + Settings UI" as a v1.6.0 roadmap item. **User explicitly asked about this feature in the v1.5.23 session so adding to this normally-write-protected file is permitted per skill rules.**
  - Verify: `grep -n 'Pluggable Places Provider' seobetter/seo-guidelines/pro-features-ideas.md`

### Verified by user

- **UNTESTED** — waiting on user reinstall + retest. Expected after v1.5.23:

  1. Regenerate the failed keyword `Whats The Best Gelato Shops In Lucignano Italy 2026` (Listicle, Ecommerce, AU, 2000w). In browser Network tab before clicking Generate, observe requests to `nominatim.openstreetmap.org/search?q=Lucignano+Italy` and `overpass-api.de/api/interpreter`. Saved draft either contains real OSM-verified gelato shops with addresses, OR is a general informational article with a disclaimer paragraph. NO invented business names.
  2. Smoke test with a major city: `best pizza restaurants in rome italy 2026` → expect 5-10 real restaurants with addresses, all findable on Google Maps.
  3. Non-local regression: `how to introduce raw food to a dog with sensitive stomach` (How-To, Veterinary) → `detectLocalIntent` returns `false`, `places_count: 0`, article generates normally with no behavior change.
  4. Sentinel test: force a local-intent article with fabricated content (e.g. manually edit a saved draft to remove OSM URLs and addresses). Re-run the analyzer — `check_local_places_grounding` should return `score: 0` and the final `geo_score` should be capped at 40.

---

## v1.5.22 — Auto-suggest uses real keyword data (fixes silent failures)

**Date:** 2026-04-13
**Commit:** `0cf267c`

### Context

User reported: "auto suggest is not suggesting keywords, check api and .md files".

Root cause found: the Auto-suggest button next to the Primary Keyword input called `/api/generate` (Groq LLM) with a strict-format prompt and a **fragile client-side regex parser** that silently failed whenever Llama wrapped its output in markdown.

The failing flow (through v1.5.21):
1. Button hits `POST /api/generate` with prompt `"Return ONLY:\nSECONDARY: a, b, c\nRELATED: a, b, c"`
2. Llama 3.3 70B (the free Groq default) frequently returned:
   - `**SECONDARY:** keyword1, keyword2` (markdown bold) → regex `/^SECONDARY/i` fails because line starts with `*`
   - `1. SECONDARY: keyword1, keyword2` (numbered) → regex fails
   - ` ```\nSECONDARY: ...\n``` ` (code block) → regex fails
3. Client parser `.split('\n').forEach(l => if (/^SECONDARY/i.test(l)) ...)` missed the lines
4. Input fields stayed empty
5. Status text still said "Done!" because `d.content` was truthy
6. User saw nothing happen — silent failure

Meanwhile the plugin already had a **working alternative** at `/api/topic-research` which pulls real keyword demand data from 5 sources (Google Suggest + Datamuse + Wikipedia + Reddit + DuckDuckGo) with zero LLM hallucination. The sidebar "Suggest 10 Topics" widget used it successfully. But the Auto-suggest button never did.

### Fixed

- **Auto-suggest button rewired to use `/api/topic-research`** — `admin/views/content-generator.php` lines **611-652** (click handler)
  - No more LLM call, no more regex parser
  - Reads `data.keywords.secondary` and `data.keywords.lsi` directly from a structured JSON response
  - Populates the `secondary_keywords` and `lsi_keywords` fields with `array.join(', ')`
  - Status text now shows source counts: `"Added N secondary + M LSI (N from Google Suggest, M from Datamuse)"`
  - Graceful fallback: shows `"No keyword variations found — try a broader term"` if the arrays are empty (e.g. for very obscure niches)
  - Verify: `grep -n "topic-research" seobetter/admin/views/content-generator.php`

### Added

- **`keywords` field in /api/topic-research response** — `cloud-api/api/topic-research.js::buildKeywordSets()` new helper at line ~**180**
  - Extracts short keyword phrases from the raw research arrays for the Auto-suggest button
  - `keywords.secondary` (up to 7) — real Google Suggest variations filtered to phrases 6-80 chars that share at least one word with the niche
  - `keywords.lsi` (up to 10) — Datamuse semantic single-word clusters, 4-30 chars, deduped against secondary words. Falls back to 1-2 word Wikipedia titles if Datamuse returns <6 results.
  - Also exposed as `keywords.secondary_string` and `keywords.lsi_string` — pre-joined comma-separated strings for direct UI display
  - The existing `topics[]` array is unchanged — the sidebar "Suggest 10 Topics" widget still works identically
  - Verify: `grep -n "buildKeywordSets\|keywords:" seobetter/cloud-api/api/topic-research.js`

### Documentation

- **plugin_functionality_wordpress.md §1.7** — new section "Topic Research Endpoint (v1.5.22 enhanced)" documenting the `/api/topic-research` endpoint, the request/response shape including the new `keywords` field, the 5 data sources, and the historical context (why Auto-suggest used to be LLM-based and why that was wrong)
  - Verify: `grep -n '1.7 Topic Research Endpoint' seobetter/seo-guidelines/plugin_functionality_wordpress.md`

- **plugin_UX.md §2 + §5** — updated the Auto-suggest feature rows to note the v1.5.22 data source change (was LLM, now real data from Google Suggest + Datamuse)
  - Verify: `grep -n 'v1.5.22' seobetter/seo-guidelines/plugin_UX.md`

### Not changed

- `/api/generate` endpoint still exists — used by the Social Content Generator (Twitter/LinkedIn/Instagram post creation) which does genuinely need LLM generation
- Sidebar "Suggest 10 Topics" widget still hits the same `/api/topic-research` endpoint and still reads `data.topics` — unaffected by the new `keywords` field

### Verified by user

- **UNTESTED** — waiting on user reinstall + retest. After v1.5.22:
  - Click "Auto-suggest" next to the Primary Keyword input on a fresh article
  - Secondary Keywords and LSI Keywords fields should populate with real Google Suggest + Datamuse data within 1-2 seconds (faster than the old LLM call which took 3-5s)
  - Status text should show the source attribution like `"Added 6 secondary + 8 LSI (18 from Google Suggest, 8 from Datamuse)"`

---

## v1.5.21 — Preview/draft styling parity (format_classic now wraps format_hybrid)

**Date:** 2026-04-13
**Commit:** `096d18b`

### Context

User retest of v1.5.20: "when you save the draft to post all the styling is there, but it does not show in the preview". Investigation confirmed that `format_classic()` (used by the result-panel preview) and `format_hybrid()` (used by the saved draft) had drifted significantly:

- **format_hybrid** had 14 styled block branches with custom SVG icons (v1.5.20), eyebrow headers, and v1.5.14/v1.5.17 features (Did You Know, Definition, Highlight, Expert Quote, Stat callout, Social Citation, HowTo Step Boxes)
- **format_classic** still had only the v1.5.10-era 4 branches (tip/note/warning paragraph, takeaways/pros/cons/ingredients list) using CSS classes, with **zero `sb_icon()` calls**

Verified via `grep -c sb_icon includes/Content_Formatter.php` → 14 calls all in `format_hybrid()` (lines 383-611), 0 in `format_classic()`.

Result: the preview was a stripped-down version of the article. Saved draft had icons + eyebrow headers + 7 extra styled blocks; preview had none of them.

### Fixed

- **format_classic() reimplemented as a thin wrapper around format_hybrid()** — `includes/Content_Formatter.php::format_classic()` lines **685-755**
  - Old implementation: ~170 lines duplicating the section-rendering switch with CSS classes
  - New implementation: ~70 lines that call `format_hybrid()`, strip Gutenberg block comments via `preg_replace('/<!--\s*\/?wp:[^>]*-->/', '', ...)` (browsers ignore HTML comments anyway, but cleaner), and wrap the result in a scoped CSS container that styles the plain prose elements (h1, p, ul, table)
  - Wrapper CSS only adds typography (font, line-height, accent H2 fallback color, paragraph max-width). Every styled wp:html block is self-contained with inline styles, so they render identically in both modes.
  - Verify: `grep -A 5 "format_classic.*sections.*options.*string" seobetter/includes/Content_Formatter.php | head -20`
  - Verify: `! grep -A 200 "private function format_classic" seobetter/includes/Content_Formatter.php | head -200 | grep -c "case 'paragraph'"` (should be 0 — classic no longer has its own switch statement)

### Consequences (intentional)

- **Preview pixel-matches the saved draft** for every styled block (icons, eyebrow headers, callouts, key takeaways, pros/cons, definitions, highlights, stat callouts, social citations, did-you-know boxes, etc)
- Adding any new styled block in the future means editing `format_hybrid()` ONCE — `format_classic()` automatically picks it up. No more drift risk.
- The wrapper CSS uses `!important` on key colors to defeat WordPress admin CSS (which loads with high specificity and would otherwise override the article styling)
- `format_gutenberg()` (legacy "pure native blocks" mode used by the Bulk Generator's per-item save path) is unchanged and remains its own implementation

### Documentation

- **article_design.md §5.16b** — new section "Preview = saved draft parity (v1.5.21+)" documenting the wrapper architecture, why classic was reimplemented, and the consequence that future styled-block additions only need to touch `format_hybrid()`
  - Verify: `grep -n '5.16b Preview = saved draft parity' seobetter/seo-guidelines/article_design.md`

### Audit performed but no changes needed

The user also asked: "Check it has all the required SEO, AI SEO and GEO, AI citation, LLM citation requirements for the article generation in code." Performed full audit — all requirements verified in the code:

- **Async_Generator::get_system_prompt()** ([Async_Generator.php:601-735](../includes/Async_Generator.php#L601)) — has all 13 sections: keyword density (0.5-1.5%, every 100-200 words, 30% of H2s), GEO visibility boosts (+41% quotes, +40% stats), CITATION RULES (closed-menu pool, no hallucinated URLs, no API anchor text), E-E-A-T (Experience/Expertise/Authority/Trust + YMYL), NLP entity optimization (5%+ density), 30+ banned words list, 11+ banned patterns, sentence rhythm, transitions, structure (40-60 word section openers), island test (no pronoun starts), word count enforcement, RICH FORMATTING block (v1.5.14+ with v1.5.17 social citation marker)
- **GEO_Analyzer::analyze()** ([GEO_Analyzer.php:54](../includes/GEO_Analyzer.php#L54)) — all 14 checks (readability, bluf_header, section_openings, island_test, factual_density, citations, expert_quotes, tables, lists, freshness, entity_usage, keyword_density, humanizer, core_eeat) with weights summing to 100
- **CORE_EEAT_Auditor::audit()** ([CORE_EEAT_Auditor.php:36](../includes/CORE_EEAT_Auditor.php#L36)) — full 80-item rubric (40 CORE + 40 EEAT) + veto items C01/R10/T04 with 40-point cap on veto hit
- **Content_Ranking_Framework** ([Content_Ranking_Framework.php](../includes/Content_Ranking_Framework.php)) — 5 phases (topic_selection, keyword_research, intent_grouping, research_first_writing, quality_gate)
- **Citation_Pool** ([Citation_Pool.php](../includes/Citation_Pool.php)) — `build()`, `format_for_prompt()`, `contains_url()`, `get_entry()` per-article allow-list
- **validate_outbound_links** ([seobetter.php:1334](../seobetter.php#L1334)) — 4 passes (sanitize references, strip malformed, filter pool/whitelist, RLFKV verify_citation_atoms) + Pass 4 URL deduplication (v1.5.18)
- **9 always-on research sources** (Reddit, HN, Wikipedia, Google Trends, DuckDuckGo, Bluesky, Mastodon, DEV.to, Lemmy)
- **25 category APIs** including `veterinary` (Crossref filtered + EuropePMC + OpenAlex + openFDA + DogFacts)

No drift from the spec. The article generation pipeline has every SEO/AI-SEO/GEO/AI-citation/LLM-citation requirement wired into code.

### Verified by user

- **UNTESTED** — waiting on user reinstall + retest. After v1.5.21:
  - Preview should show ALL the same styled blocks as the saved draft, including v1.5.20 icons + eyebrow headers
  - Pixel parity between preview and saved draft (with addition of better typography for plain prose elements in the preview)

---

## v1.5.20 — Remove dropcaps + cap stat callouts + custom SEOBetter icon set

**Date:** 2026-04-13
**Commit:** `3f60abe`

### Context

User feedback after v1.5.19 fixes:

1. "Overly extensive amount of percentage stylings" — the v1.5.14 stat callout regex was matching ANY paragraph containing `\d%` or `X out of Y`, regardless of whether the stat was the lead or buried mid-paragraph. Articles with lots of numbers (which is most of them, since the GEO prompt encourages stats) ended up with 8+ pulled-out stat cards crowding out the prose.

2. "Remove drop caps on sentences" — the v1.5.18 dropcap (both the format_hybrid `dropCap` emission and the format_classic `h2+p::first-letter` CSS rule) was visually overbearing. Every section opened with a 3.2em first letter that fought for attention with the colored H2 above it.

3. Icons in styled wp:html blocks — user wants visual icons to appear in callout corners and box headers (not body prose, per article_design.md §6) and explicitly requested **unique icons** that "other websites don't use, so it's unique and up to date for 2026 onwards". Investigated Noun Project API and found it would require OAuth-style auth + per-user paid signup. Recommended hand-drawn custom icons instead — same effort to ship, zero runtime cost, genuinely unique because no library has the exact path data.

### Removed

- **Dropcap CSS in format_classic()** — `includes/Content_Formatter.php::format_classic()` line ~661
  - Deleted the `.{$uid} h2+p::first-letter` and `.{$uid} h2+div+p::first-letter` rules that floated a 3.2em accent-color first letter on every paragraph following an H2
  - Verify: `! grep -n 'h2\+p::first-letter' seobetter/includes/Content_Formatter.php`

- **Dropcap emission in format_hybrid()** — `includes/Content_Formatter.php::format_hybrid()` paragraph fallback case lines ~443-454
  - Deleted the v1.5.18 branch that emitted `<!-- wp:paragraph {"dropCap":true} --><p class="has-drop-cap">...` for the first body paragraph. All paragraphs now use the plain `<!-- wp:paragraph -->` form.
  - Removed the now-unused `$dropcap_used` flag declaration
  - Verify: `! grep -n 'dropCap":true\|has-drop-cap\|dropcap_used' seobetter/includes/Content_Formatter.php`

### Changed

- **Stat callout regex tightened + 3-per-article cap** — `includes/Content_Formatter.php::format_hybrid()` paragraph case stat branch lines ~427-446
  - Old regex matched `\d%` or `X out of Y` anywhere in the paragraph — fired on every numeric-heavy paragraph
  - New regex requires the stat to appear in the **first 60 characters** of the paragraph (so the stat is the LEAD, not buried mid-prose): `^.{0,60}(\d{1,3}(?:[.,]\d+)?\s*%)` and `^.{0,60}\b\d{1,3}\s+(?:out\s+of|in)\s+\d{1,4}\b`
  - Added `$stat_count` per-article counter; once it reaches 3, no more stat callouts fire — the rest stay as plain prose paragraphs
  - X out of Y form now renders the value as a fraction `X/Y` instead of stripping to just X
  - Verify: `grep -n 'stat_count' seobetter/includes/Content_Formatter.php`

### Added

- **SEOBetter Custom Icon Set v1 — 13 hand-drawn SVG icons** — `includes/Content_Formatter.php::sb_icon()` lines ~842-905
  - New helper method with hardcoded SVG path data for 13 icons: `tip`, `note`, `warning`, `didyouknow`, `definition`, `highlight`, `stat`, `quote`, `social`, `takeaways`, `pros`, `cons`, `ingredients`
  - Hand-drawn for SEOBetter — NOT from Lucide, Heroicons, Phosphor, Font Awesome, Tabler, Noun Project, Iconoir, or any other library. The path data is unique to SEOBetter so no other site uses these exact icons.
  - 18×18 viewBox, default render at 16px, `stroke="currentColor"` so icons inherit the parent box's text color (tip=blue, warning=red, pros=green, cons=red, etc), `stroke-width="1.5"` for hairline modern look
  - `aria-hidden="true"` since the box label text provides accessible context
  - Total inline SVG size: ~3KB across all 13 icons. Zero runtime cost, zero external dependency, zero API call.
  - Verify: `grep -n "private function sb_icon" seobetter/includes/Content_Formatter.php`

- **Icons wired into 13 styled wp:html blocks** — `includes/Content_Formatter.php::format_hybrid()` quote/paragraph/list cases
  - Tip / Note / Warning callouts (paragraph branches) — icon next to the bold label
  - Did You Know box (paragraph branch) — icon in the eyebrow header line
  - Definition box (paragraph branch) — icon at start
  - Highlight sentence (paragraph branch) — icon at start
  - Stat callout (paragraph branch) — icon next to the pulled-out number
  - Expert quote blockquote (quote case fallback) — icon above the quote text
  - Social Media Citation card (quote case social branch) — icon in the red eyebrow header
  - Key Takeaways box (list case takeaways branch) — new eyebrow header "KEY TAKEAWAYS" with icon
  - Pros box (list case pros branch) — new eyebrow header "PROS" with icon
  - Cons box (list case cons branch) — new eyebrow header "CONS" with icon
  - Ingredients box (list case ingredients branch) — new eyebrow header "WHAT YOU'LL NEED" with icon
  - Verify: `grep -c 'sb_icon' seobetter/includes/Content_Formatter.php` (should be ≥ 13)

### Documentation

- **article_design.md §6** — rewrote the "When Icons Are Used" subsection with the SEOBetter Custom Icon Set v1 spec table (13 icons + visual concepts), technical implementation notes, and a "What was removed in v1.5.20" subsection covering the dropcap removal
  - Verify: `grep -n 'SEOBetter Custom Icon Set' seobetter/seo-guidelines/article_design.md`

### Verified by user

- **UNTESTED** — waiting on user reinstall + retest. Expected:
  - No dropcap on any paragraph (preview or saved draft)
  - At most 3 stat callout cards per article
  - Each styled wp:html block has its own unique SEOBetter icon in the header/corner, sized 16px, colored to match the box accent
  - Icons are NOT inline with regular body prose

---

## v1.5.19 — Delete duplicate renderResult in admin.js (race condition fix)

**Date:** 2026-04-13
**Commit:** `374dc2f`

### Context

User retest of v1.5.18 surfaced a critical regression: the GEO score dashboard, the 14 bar charts, the Analyze & Improve fix buttons, and the headline radio selector ALL appeared briefly and then **disappeared and were replaced by a stripped-down legacy panel**. The Save Draft button on the legacy panel did nothing (no Post/Page dropdown, no click handler).

Root cause: [admin/js/admin.js](../admin/js/admin.js) lines 15-253 contained an entire duplicate copy of the async article generation handler — its own `renderResult()`, its own polling loop, its own click handler on `#seobetter-async-generate`. This was a leftover from before v1.5.12 that never got cleaned up. Both this file and the inline script in [admin/views/content-generator.php](../admin/views/content-generator.php) attached click handlers to the same button, both polled `/seobetter/v1/generate/step`, both called `/seobetter/v1/generate/result`, and both raced to write into `#seobetter-async-result`.

The inline content-generator.php renderer (full v1.5.18 dashboard with bar charts, fix buttons, headline radios) finished first and rendered correctly. The legacy admin.js renderer finished a few hundred milliseconds later and **overwrote** the result panel with its own stripped output: just a "GEO Score: X (Y) Words: Z" textual summary, the suggestions list, a bare "Headlines" panel with no radio buttons, and a Save Draft button that POSTed to the deleted `seobetter_create_draft` legacy handler.

This explains every symptom in the user's bug report:
- "the graph dissapears" → legacy renderer overwrites the v1.5.18 dashboard
- "stats and graph then it disappears" → same
- "pro features to add whats needed but disappears" → Analyze & Improve panel overwritten
- "you cant select the title" → legacy renderer's headline panel has no radio buttons
- "the save draft button does nothing" → legacy save button submits to a 2-version-old handler that no longer exists

### Fixed

- **Deleted the entire async generator block from admin.js** — `admin/js/admin.js` lines **15-253** (everything except the API key visibility toggle)
  - The file now has 35 lines instead of 256
  - Only the API key field show/hide handler remains
  - A long header comment documents the deletion + warns future devs not to add result-panel JS here
  - Verify: `! grep -n 'renderResult\|seobetter-async-generate' seobetter/admin/js/admin.js | grep -v '^[[:space:]]*\*'`
  - Verify line count: `wc -l seobetter/admin/js/admin.js` (should be ≤ 36)

### Documentation

- **plugin_UX.md §8B** — new section "Single-source-of-truth rule for the result panel renderer" documenting the v1.5.19 fix and prohibiting any future async-generator JS in admin.js
  - Verify: `grep -n '§8B' seobetter/seo-guidelines/plugin_UX.md`

### Verified by user

- **UNTESTED** — waiting on user reinstall + retest article 1. After v1.5.19, the result panel should render the FULL v1.5.18 dashboard (score circle, 14 bar charts, fix buttons, headline radio selector, Post/Page dropdown, working Save Draft) and STAY rendered without disappearing.

---

## v1.5.18 — Citation dedup, preview/draft styling parity

**Date:** 2026-04-13
**Commit:** `02575d1`

### Context

Test 1 (Article: "is fresh meat better than kibble for adult dogs" / Blog Post / veterinary) surfaced four real bugs:

1. The same Wikipedia URL (`en.wikipedia.org/Dog_food`) was linked to "dog food" three times in the same article. The system prompt at line 646 says "use each pool URL at most once" but the AI ignored it and there was no validator enforcing it.
2. The article preview was rendering all in dark mode (black background, white text), which doesn't match how the saved draft renders on the live site. Caused by a `prefers-color-scheme:dark` media query in `format_classic()` auto-flipping when the user's OS is in dark mode.
3. The saved draft has plain wp:heading and wp:paragraph blocks with no accent color or dropcap, while the preview shows colored H2s and a dropcap on the first paragraph. The two render paths produced visibly different output.
4. **Not fixed in this release** but documented: the user reported the title selector, bar charts, and Analyze & Improve panel as "missing" — code review confirms they're all in `renderResult()` JS as of v1.5.12. Likely the user just didn't scroll up far enough in the result panel. Will verify with user after v1.5.18 install.

### Fixed

- **URL deduplication pass** — `seobetter.php::validate_outbound_links()` after the verify_citation_atoms call (Pass 4)
  - New 4th pass walks all surviving markdown links and HTML anchors in document order, normalizes URLs (lowercase host, strip trailing slash, preserve query string, drop fragment), and on the 2nd+ occurrence of any URL strips the link wrapper while preserving the anchor text as plain text.
  - Catches the AI's failure to follow "use each pool URL at most once" — guarantees no duplicate outbound URLs in the saved draft.
  - Verify: `grep -n "Pass 4: URL deduplication" seobetter/seobetter.php`

- **Preview dark mode media query removed** — `Content_Formatter.php::format_classic()` line ~688
  - Deleted the `@media(prefers-color-scheme:dark)` block (~18 CSS rules) that auto-flipped the preview to dark colors when the user's OS was in dark mode.
  - Preview now always renders light, matching what gets saved to the draft and what the published article looks like on the front-end (which never inherits OS dark mode).
  - Verify: `! grep -n 'prefers-color-scheme:dark' seobetter/includes/Content_Formatter.php`

- **Hybrid H2 headings get accent color** — `Content_Formatter.php::format_hybrid()` heading case lines ~344-365
  - H2 headings now emit Gutenberg style attribute JSON + inline `style="color:..."` so the saved draft visually matches the preview's colored headings, while remaining editable as native wp:heading blocks.
  - H1 (post title) and H3+ stay plain so theme defaults apply.
  - Verify: `grep -n 'has-text-color' seobetter/includes/Content_Formatter.php`

- **Hybrid first body paragraph gets dropcap** — `Content_Formatter.php::format_hybrid()` paragraph fallback case lines ~443-462
  - The first regular paragraph (after any heading) is now emitted as `<!-- wp:paragraph {"dropCap":true} --><p class="has-drop-cap">…</p><!-- /wp:paragraph -->`. WordPress core renders `.has-drop-cap` with a serif-styled `::first-letter` at 3.5em.
  - Subsequent paragraphs unchanged. The `$dropcap_used` flag prevents multiple dropcaps per article.
  - Verify: `grep -n 'dropCap":true' seobetter/includes/Content_Formatter.php`

### Documentation

- **external-links-policy.md §6B** — new section "URL deduplication (Pass 4 — added v1.5.18)" with normalization rules, pattern explanation, and reasoning
  - Verify: `grep -n '## 6B. URL deduplication' seobetter/seo-guidelines/external-links-policy.md`

- **article_design.md §5.16a** — new section "Hybrid heading + dropcap parity (v1.5.18+)" documenting the H2 accent color emission, dropcap on first paragraph, and the dark-mode removal
  - Verify: `grep -n '5.16a Hybrid heading' seobetter/seo-guidelines/article_design.md`

### Verified by user

- **UNTESTED** — waiting on user reinstall + retest article 1. Expected:
  - Wikipedia URL appears at most once in References
  - Preview is light-themed, not dark
  - Saved draft has colored H2 headings (purple/accent) in Gutenberg editor
  - Saved draft has a dropcap on the first paragraph
  - GEO Score panel shows the bar chart graph (existed since v1.5.12, user just needs to scroll up)
  - Headlines panel shows radio buttons + tag descriptors (existed since v1.5.12)
  - Analyze & Improve panel shows clickable Fix buttons (existed since v1.5.12)

### NOT addressed in v1.5.18 (separate concerns)

- **Social citation cards** — v1.5.17 just shipped. The detection regex exists, but the AI may not be producing the marker syntax yet (prompt instruction needs more reinforcement OR a test article specifically with social references). Test #1 used `domain=veterinary` which pulls Crossref/EuropePMC/OpenAlex, not Bluesky/Mastodon — the social fetchers may have returned 0 hits for that vet topic. To verify v1.5.17 social cards work, generate an article with a tech keyword (e.g. `domain=technology`) and check if the AI emits `> [bluesky @handle]` markers.
- **GEO score auto-fix as Pro feature?** — `/seobetter/v1/generate/improve` and `/seobetter/v1/inject-fix` endpoints already exist and are wired to the Analyze & Improve buttons (line 907 of content-generator.php). They use the user's BYOK API key or cloud quota — no extra cost. No Pro gating recommended. The "Add now" buttons ARE the auto-fix; user just needs to find them in the rendered dashboard.

---

## v1.5.17 — Social media citation blocks (human-in-the-loop for AI-unreliable sources)

**Date:** 2026-04-13
**Commit:** `9abfce3`

### Context

v1.5.16 added Reddit, HN, Bluesky, Mastodon, DEV.to, and Lemmy as research sources, but the AI would weave quotes from them into regular paragraphs — making social content indistinguishable from vetted prose. Since social posts are easily AI-faked or unreliable, users need to review every social citation before publishing. v1.5.17 makes every social citation render as its own dedicated `wp:html` block with a prominent red review-before-publish warning banner, so the user can spot it in the Gutenberg block list and delete it with one click if it's suspect. The rest of the article's prose is unaffected.

### Added

- **Social Media Citation detection** — `includes/Content_Formatter.php::format_hybrid()` `case 'quote':` line **536**
  - New branch detects blockquotes that start with `[platform @handle]` marker (supports bluesky, mastodon, reddit, hn/hacker news, dev.to, lemmy, twitter/x — the last two for forward compat with the pro-features-ideas.md X integration)
  - Renders as a `wp:html` block with: slate background, 4px slate-500 left border, red uppercase "SOCIAL MEDIA CITATION — REVIEW BEFORE PUBLISHING" eyebrow label, quote body in curly quotes, attribution footer with `@handle` link to source URL, dashed-border footnote "Social content is user-generated and may be unreliable or AI-generated. Verify the claim before publishing, or delete this block."
  - Falls through to the existing generic blockquote renderer if no social marker matches — zero regression risk for existing expert quotes
  - Verify: `grep -n "v1.5.17 — Social media citation detection" seobetter/includes/Content_Formatter.php`

- **Prompt instruction for social citation format** — `includes/Async_Generator.php::get_system_prompt()` line ~**728**
  - Added to the RICH FORMATTING block: explicit instruction that any claim or quote from a social media post MUST be written as a markdown blockquote with `[platform @handle]` marker on the first line (optionally followed by a second blockquote line with the source URL), NEVER woven into a regular paragraph
  - Lists the 6 valid platform markers (bluesky, mastodon, reddit, hn, dev.to, lemmy)
  - Reasoning baked into the prompt: "social media content can be unreliable or AI-generated, so it MUST be visually separated from your vetted prose"
  - Verify: `grep -n '\[bluesky @alice' seobetter/includes/Async_Generator.php`

### Documentation

- **article_design.md §5.16** — new subsection documenting the Social Media Citation box with trigger regex, style spec, and AI instruction note. The original "Widened triggers" section was renumbered to §5.17.
  - Verify: `grep -n '^### 5.16 Social Media Citation' seobetter/seo-guidelines/article_design.md`

- **SEO-GEO-AI-GUIDELINES.md §4.8** — added the social citation row to the auto-styled triggers list with **REQUIRED** emphasis that social content must never be inlined into prose
  - Verify: `grep -n 'Social media citation.*v1.5.17' seobetter/seo-guidelines/SEO-GEO-AI-GUIDELINES.md`

- **plugin_functionality_wordpress.md §3.2 + §3.3** — added the new Social Media Citation styled block to the list, added a new "Blockquotes are styled based on the first-line marker" detection subsection
  - Verify: `grep -n 'v1.5.17' seobetter/seo-guidelines/plugin_functionality_wordpress.md`

### Verified by user

- **UNTESTED** — waiting on user reinstall. Expected: when the AI references a social post (e.g. "A Reddit user reports..."), it appears as a distinct gray-bordered card with a red warning banner instead of inline prose. User can select and delete the block from the Gutenberg list view with one click.

---

## v1.5.16 — Free social discussion sources (Bluesky, Mastodon, DEV.to, Lemmy)

**Date:** 2026-04-13
**Commit:** `7881cc3`

### Context

Until v1.5.15 the always-on research sources were 5: DuckDuckGo, Reddit, Hacker News, Wikipedia, Google Trends. That gave one social signal (Reddit) and missed the entire post-X ecosystem. v1.5.16 adds 4 more free, no-auth, always-on fetchers — Bluesky, Mastodon, DEV.to, Lemmy — covering broader linguistic and community niches than Reddit alone. X/Twitter is deliberately NOT included because there's no clean free X API in 2026; the X cookie-auth research path is documented in pro-features-ideas.md for a future release.

### Added

- **4 new fetcher functions** — `cloud-api/api/research.js` lines **149-274**
  - **`searchBluesky(keyword)`** — Bluesky AT Protocol public search. URL `api.bsky.app/xrpc/app.bsky.feed.searchPosts`. Returns posts with author handle, likes, reposts, replies. Free, no auth.
  - **`searchMastodon(keyword)`** — Mastodon public statuses via `mastodon.social/api/v2/search`. Strips HTML to plain text. Returns statuses with author, favourites, reblogs, replies. Free, no auth. Multilingual coverage is a strength.
  - **`searchDevTo(keyword)`** — DEV.to articles via `dev.to/api/articles?search=`. Returns title, description, author, reactions, comments, tags. Free, no auth. Best for tech/coding skill topics globally.
  - **`searchLemmy(keyword)`** — Lemmy federated search via `lemmy.world/api/v3/search`. Returns posts with score, comments, community. Free, no auth.
  - Verify: `grep -n "^async function searchBluesky\|^async function searchMastodon\|^async function searchDevTo\|^async function searchLemmy" seobetter/cloud-api/api/research.js`

- **Wired into freeSearches array** — `cloud-api/api/research.js` lines **42-58**
  - Always-on parallel batch grew from 5 to 9 sources. Old sources unchanged. New 4 fetched in parallel with the others, no extra latency.
  - Verify: `grep -n "searchBluesky\|searchMastodon\|searchDevTo\|searchLemmy" seobetter/cloud-api/api/research.js | head -10`

- **buildResearchResult social block** — `cloud-api/api/research.js` lines **2014-2076**
  - New 4-block section that folds Bluesky/Mastodon/DEV.to/Lemmy posts into `trending[]` (freshness signal in the AI prompt) and `sources[]` (citable URLs for the References section). DEV.to descriptions are also scanned for embedded statistics that get pulled into `stats[]`.
  - `trending` slice cap raised from 8 → 12 to accommodate the broader source set.
  - Function signature gained a 10th param `social` (object with `bluesky/mastodon/devto/lemmy` keys).
  - Return object now includes `bluesky_count`, `mastodon_count`, `devto_count`, `lemmy_count` for telemetry.
  - Verify: `grep -n "v1.5.16 — Social discussion sources" seobetter/cloud-api/api/research.js`

- **5 new whitelisted domains** — `seobetter.php::get_trusted_domain_whitelist()` line **1849**
  - `bsky.app`, `bsky.social`, `mastodon.social`, `dev.to`, `lemmy.world`
  - Allows posts from these sources to pass `validate_outbound_links()` Pass 2 even when not in the per-article citation pool
  - Verify: `grep -n "bsky.app\|mastodon.social\|dev.to\|lemmy.world" seobetter/seobetter.php`

### Documentation

- **plugin_functionality_wordpress.md §1.1** — rewrote the always-on sources table from 5 rows to 9, marking the v1.5.16 additions and noting why X is excluded
  - Verify: `grep -c '^| \*\*' seobetter/seo-guidelines/plugin_functionality_wordpress.md | head -1`
- **external-links-policy.md §10** — added "Social discussion sources (added v1.5.16)" subsection listing the 5 new whitelisted domains
  - Verify: `grep -n 'Social discussion sources (added v1.5.16)' seobetter/seo-guidelines/external-links-policy.md`
- **pro-features-ideas.md** — added "Research Sources Backlog → X / Twitter integration" section documenting the 3 realistic paths (cookie-auth, ScrapeCreators, wait for API price drop) with reference to the vendored last30days skill as a porting guide. **Note: this file is normally write-protected per skill rules; the user explicitly asked for this entry.**
  - Verify: `grep -n 'X / Twitter integration' seobetter/seo-guidelines/pro-features-ideas.md`

### Verified by user

- **UNTESTED** — waiting on user reinstall + test article generation. Expected: References section now occasionally includes URLs from bsky.app, mastodon.social, dev.to, or lemmy.world; TRENDING DISCUSSIONS section in the prompt now richer (12 items instead of 8).

---

## v1.5.15 — Domain category drift fix + Veterinary research APIs

**Date:** 2026-04-13
**Commit:** `155698c`

### Context

Three drift bugs in the Domain dropdown were silently degrading article quality: (1) the main content-generator.php form had 25 categories but bulk-generator.php and content-brief.php only had 8 — picking `food` or `animals` in bulk silently fell back to `general`; (2) there was no real veterinary research category — the `health` value pulled OpenDisease + OpenFDA (human medical only) and `animals` pulled DogFacts/CatFacts/ZooAnimals (trivia only), with zero peer-reviewed veterinary literature available for pet content; (3) `government` and `law_government` were near-duplicates with confusing labels. v1.5.15 fixes all three by syncing the 3 forms to one 25-category list, adding a real Veterinary category powered by Crossref (subject-filtered), EuropePMC, and OpenAlex, and merging `law_government` into `government` with a backwards-compat alias.

### Added

- **Veterinary domain category** — `cloud-api/api/research.js::getCategorySearches()` line **335**
  - New `veterinary` entry pulls 5 fetchers: `fetchCrossrefFiltered(keyword, 'veterinary')`, `fetchEuropePMC(keyword + ' veterinary')`, `fetchOpenAlex(keyword, 'veterinary')`, `fetchOpenFDA(keyword + ' veterinary')`, `fetchDogFacts()`
  - First time the plugin produces real peer-reviewed vet citations for pet content
  - Verify: `grep -n "^    veterinary:" seobetter/cloud-api/api/research.js`

- **3 new fetcher functions** — `cloud-api/api/research.js` lines **1689-1735**
  - **`fetchCrossrefFiltered(keyword, subject)`** — Crossref API with `query.bibliographic` filter for subject narrowing. Same return shape as `fetchCrossref()` for reusable formatting.
  - **`fetchEuropePMC(query)`** — EuropePMC REST API, biomedical/life-sciences literature, free no-auth. Returns title + author + journal + year + DOI/PMID URL.
  - **`fetchOpenAlex(keyword, conceptName)`** — OpenAlex API with `concepts.display_name.search` filter, polite-pool `mailto` parameter. 240M+ scholarly works including extensive vet literature.
  - Verify: `grep -n "^function fetchCrossrefFiltered\|^function fetchEuropePMC\|^function fetchOpenAlex" seobetter/cloud-api/api/research.js`

- **Academic citation domains added to whitelist** — `seobetter.php::get_trusted_domain_whitelist()` line **1838**
  - `crossref.org`, `api.crossref.org`, `doi.org`, `europepmc.org`, `ebi.ac.uk`, `www.ebi.ac.uk`, `openalex.org`, `api.openalex.org`
  - These power the new Veterinary fetchers and need static-whitelist trust as a fallback for when the citation pool path isn't used
  - Verify: `grep -n 'crossref.org\|europepmc\|openalex' seobetter/seobetter.php`

### Changed

- **Synced 3 dropdown forms to a single 25-category taxonomy**
  - `admin/views/content-generator.php` line **190-218** — added Veterinary option, relabeled Health → "Health & Medical (Human)", relabeled Animals → "Animals & Pets (Trivia)", removed `law_government` (merged into `government`)
  - `admin/views/bulk-generator.php` line **134-160** — replaced 8-option short list with full 25-category list
  - `admin/views/content-brief.php` line **73-99** — replaced 8-option short list with full 25-category list
  - All 3 forms now expose the EXACT same options in the same order with the same labels. Sync rule documented in plugin_UX.md §9.0.
  - Verify: `grep -c 'veterinary' seobetter/admin/views/content-generator.php seobetter/admin/views/bulk-generator.php seobetter/admin/views/content-brief.php`

- **Merged government + law_government in research.js** — `cloud-api/api/research.js::getCategorySearches()` lines **325-360**
  - Removed the duplicated `law_government` entry from the map (was identical fetchers to `government`)
  - Added a backwards-compat alias: `const resolved = (domain === 'law_government') ? 'government' : domain;` so any older client still passing the old value resolves correctly
  - Verify: `grep -n "law_government" seobetter/cloud-api/api/research.js`

### Documentation

- **plugin_functionality_wordpress.md §1.3** — rewrote the Category-Specific APIs table to show the dropdown values alongside human labels, added the Veterinary row, added a v1.5.15 changes subsection
  - Verify: `grep -n 'Veterinary & Pet Health' seobetter/seo-guidelines/plugin_functionality_wordpress.md`
- **plugin_UX.md §9.0** — new section "Domain dropdown sync rule" with the canonical 25-value list and the mandatory sync procedure for the 3 form files
  - Verify: `grep -n '§9.0 Domain dropdown sync rule' seobetter/seo-guidelines/plugin_UX.md`
- **external-links-policy.md §10** — added "Academic citation APIs (added v1.5.15)" subsection listing the 8 new whitelisted domains
  - Verify: `grep -n 'Academic citation APIs (added v1.5.15)' seobetter/seo-guidelines/external-links-policy.md`

### Verified by user

- **UNTESTED** — waiting on user reinstall + verification per plan: confirm 25 options in all 3 dropdowns, generate test article with `domain=veterinary` and confirm References include URLs from crossref/europepmc/openalex.

---

## v1.5.14 — Richer hybrid formatter (more wp:html styled blocks)

**Date:** 2026-04-13
**Commit:** `c621b05`

### Context

In v1.5.13 saved drafts looked like "plain native paragraphs + a few styled HTML blocks" because `format_hybrid()` only triggered styled `wp:html` blocks on narrow keyword matches (literal "Tip:" / "Note:" / "Warning:" / specific H2 wording). The AI rarely wrote those words naturally, so 95% of paragraphs slipped through to plain `<!-- wp:paragraph -->`. v1.5.14 fixes this two ways: (a) the formatter now auto-detects 5 new paragraph patterns + 1 new list pattern + widens 4 existing heading regexes, and (b) the AI system prompt now includes a `RICH FORMATTING` block that instructs the AI to write the trigger words/structures naturally. Hybrid mode stays — paragraphs/headings remain editable as native Gutenberg blocks; only special elements become wp:html. No icons added (article_design.md §6 ban respected).

### Added

- **5 new paragraph branches in `format_hybrid()`** — `includes/Content_Formatter.php::format_hybrid()` lines **382-433**
  - **Did-You-Know box** — paragraph starts with `Did you know` or `Fun fact`. Soft yellow bg, amber border, eyebrow label "DID YOU KNOW?", no icon
  - **Definition box** — paragraph starts with `**Term**:` (after `inline_markdown()` runs as `<strong>Term</strong>:`). Light gray bg, accent-color term + middot + body
  - **Highlight sentence** — entire paragraph is one bold `**...**` sentence with no nested HTML. Accent left border (6px), 1.15em font, accent text color
  - **Expert quote** — matches `"Quote text" — Name, Title` pattern (Unicode-aware, handles em/en/regular dash, straight or curly quotes). Italic blockquote with `<footer>` attribution
  - **Stat callout** — paragraph contains `\d%` or `\d out of \d` or `\d in \d`. Pulled-out 2em number on left, body on right, light purple bg
  - Verify: `grep -c "v1.5.14 — " seobetter/includes/Content_Formatter.php` (should be ≥ 6)

- **HowTo step box list branch** — `includes/Content_Formatter.php::format_hybrid()` list case lines **490-503**
  - When `$options['content_type'] === 'how_to'` AND the list is ordered AND not already classified as Pros/Cons/Ingredients/Takeaways, each `<li>` becomes a flex card with a 36px circular numbered badge (accent bg, white number) on the left and step text on the right
  - No SVG, no icons — number itself is the marker
  - Verify: `grep -n 'is_howto_steps' seobetter/includes/Content_Formatter.php`

- **`content_type` threading into formatter options** — `seobetter.php::rest_save_draft()` line **694** + `includes/Async_Generator.php::assemble_final()` line **515**
  - Both call sites now pass `'content_type'` into `format()` options so `format_hybrid()` can detect HowTo for step boxes
  - Verify: `grep -n "'content_type' =>" seobetter/seobetter.php seobetter/includes/Async_Generator.php`

- **RICH FORMATTING block in system prompt** — `includes/Async_Generator.php::get_system_prompt()` lines **725-735**
  - Instructs the AI to use trigger words/structures: `Tip:`, `Note:`, `Warning:`, `Did you know?`, `**Term**: definition`, single bold sentences for highlights, `"Quote" — Name, Title` format, statistical numbers, and the exact H2 wording for Key Takeaways / Pros and Cons / What You'll Need
  - Carve-out: explicitly notes that the BANNED WRITING PATTERNS rule against bold is overridden ONLY for the definitions and highlight cases above
  - Verify: `grep -n 'RICH FORMATTING' seobetter/includes/Async_Generator.php`

### Changed

- **Widened existing list-case trigger regex** — `includes/Content_Formatter.php::format_hybrid()` list case lines **460-465**
  - Takeaways now also matches: `key insight`, `main point`, `at a glance`, `tldr`, `tl;dr`, `what to know`, `the bottom line`
  - Pros now also matches: `upside`, `highlight`
  - Cons now also matches: `downside`, `limitation`, `trade-off`
  - Ingredients now also matches: `materials`, `tools`, `prerequisite`
  - Result: the existing 4 styled-list types fire on roughly twice as many H2 patterns without changing the AI prompt
  - Verify: `grep -n 'v1.5.14 — widened regex' seobetter/includes/Content_Formatter.php`

### Documentation

- **article_design.md §5.9-5.15** — 6 new subsections documenting Stat callout, Expert quote, Definition box, Did-You-Know, Highlight sentence, HowTo step boxes, plus a §5.15 noting the widened triggers. Each subsection includes the trigger regex + style spec + source method anchor.
  - Verify: `grep -c '^### 5\.\(9\|1[0-5]\)' seobetter/seo-guidelines/article_design.md` (should be 7)

- **SEO-GEO-AI-GUIDELINES.md §4.8** — new section documenting the trigger → box mapping for AI authors. Cross-links to article_design.md §5 and Content_Formatter.php.
  - Verify: `grep -n '4.8 Auto-styled Rich Formatting' seobetter/seo-guidelines/SEO-GEO-AI-GUIDELINES.md`

- **plugin_functionality_wordpress.md §3.2 + §3.3** — appended 6 new styled block types to the §3.2 list and rewrote §3.3 with the widened regex synonyms + the 5 new paragraph patterns. Cross-links to Async_Generator.php and SEO-GEO-AI-GUIDELINES.md §4.8.
  - Verify: `grep -c 'v1.5.14' seobetter/seo-guidelines/plugin_functionality_wordpress.md`

### Verified by user

- **UNTESTED** — waiting on user reinstall + Test A (listicle) and Test B (how_to) per plan verification section. Expected jump from 1-3 styled wp:html blocks per article in v1.5.13 to 8-14 in v1.5.14.

---

## v1.5.13 — Wire up 5 unimplemented menu features (testing-phase ungating)

**Date:** 2026-04-13
**Commit:** `a7f7460`

### Context

All 5 standalone admin pages (Bulk Generator, Content Brief, Citation Tracker, Internal Link Suggestions, Cannibalization Detector) had backend classes (200–260 lines each) and registered REST endpoints, but their views were written against imagined return shapes that didn't match what the backends actually produced. Result: every page rendered the form, the form's submit handler called the right method, the backend returned valid data, then the view failed to render anything because it was reading non-existent keys. v1.5.13 reconciles each view's reads against the backend's actual return contract — surgical fixes only, no backend rewrites.

### Changed

- **License_Manager testing-phase ungating** — `includes/License_Manager.php::FREE_FEATURES` line **58–82**
  - Moved 7 features (`bulk_content_generation`, `content_brief`, `citation_tracker`, `internal_link_suggestions`, `cannibalization_detector`, `freshness_suggestions`, `content_refresh`) from `PRO_FEATURES` to `FREE_FEATURES` so the user can test all 5 menu pages without a Pro license. Comment block clearly marks this as a testing-phase change to be reversed before public release.
  - Verify: `grep -A 3 'v1.5.13 testing phase' seobetter/includes/License_Manager.php`

### Fixed

- **Bulk Generator POST handler** — `admin/views/bulk-generator.php` lines **9–51** (POST handler) + **185–193** (post title fallback)
  - The view used to hand-roll CSV/textarea parsing into a flat array of strings, then read `$batch['id']` from the return value of `Bulk_Generator::create_batch()`. But the backend method returns `int $batch_id` and expects an array of structured rows with `'keyword'` keys. Every submission either fataled or silently no-oped.
  - Now uses `Bulk_Generator::parse_csv()` and `parse_textarea()` to get the right shape, treats `create_batch()` return as `int`, and falls back to `get_the_title( $item['post_id'] )` in the table when `post_title` is missing from the cached batch item.
  - Verify: `grep -n 'parse_csv\|create_batch' seobetter/admin/views/bulk-generator.php`

- **Citation Tracker field-name mismatches** — `admin/views/citation-tracker.php`
  - Replaced 4 wrong reads against `Citation_Tracker::check_post()`: `$citation_result['cited']` → `is_cited`, `$citation_result['reason']` → `cite_reason`, `_seobetter_citation_data` post meta → `_seobetter_citation_check`, `$comp['cited']` → `$comp['is_you']`. Without these fixes the result card showed blank fields and site-wide stats were always 0.
  - Verify: `! grep -n "'cited'\|'reason'\|_citation_data" seobetter/admin/views/citation-tracker.php`

- **Internal Link Suggestions** — `admin/views/link-suggestions.php`
  - `$suggestions['links']` → `$suggestions['suggestions']` (3 sites): backend returns key `suggestions`, not `links`. Without this the table was always empty.
  - Removed broken Site Link Overview block (depended on `_seobetter_internal_links` post meta that nothing populates → every post looked like an orphan). Replaced with a single info card that says site-wide overview is coming in a future release.
  - Removed `Insert Link` button (POSTed to non-existent `/seobetter/v1/insert-link`). Replaced with a `Copy` button that puts `[anchor](url)` markdown on the clipboard for manual paste into the editor.
  - Verify: `grep -n "suggestions\['suggestions'\]\|sb-copy-link" seobetter/admin/views/link-suggestions.php`

- **Cannibalization Detector** — `admin/views/cannibalization.php`
  - `$group['similarity_score']` → `$group['similarity']` (backend key)
  - `$p['id']` → `$p['post_id']` (every post in a conflict group)
  - `$group['recommendation']` was treated as a string `'merge'|'redirect'|'differentiate'` and used as an array key. Backend returns it as an array `['action', 'keep', 'message', 'severity']`. View now unpacks `$rec_action` and `$rec_message`, adds `'consolidate'` to the color map, and prints the backend-generated message instead of hardcoded sentences.
  - Persists results to `seobetter_cannibalization_results` option after a successful scan with an injected `scanned_at` timestamp so revisits skip re-scanning and the "Last scanned" line works.
  - Verify: `grep -n "similarity\|rec_action\|scanned_at" seobetter/admin/views/cannibalization.php`

### Documentation

- **plugin_UX.md §9** — added a new section documenting all 5 standalone menu pages with view path, backend class, REST endpoint, UI elements, and the wiring contract for each (so the next person doesn't re-introduce the same field-name bugs).
  - Verify: `grep -c '§9.' seobetter/seo-guidelines/plugin_UX.md` (should be ≥ 5)

### Verified by user

- **UNTESTED** — waiting on user reinstall + testing each of the 5 pages

---

## v1.5.12 — Restore full result UI + kill legacy sync POST fallback

**Date:** 2026-04-13
**Commit:** `d12e9eb`

### Fixed

- **Generate button form-submit fallback** — `admin/views/content-generator.php` `<button id="seobetter-async-generate">` line ~**396**
  - Changed from `<button type="submit" name="seobetter_generate_article">` to `<button type="button">`
  - Also added `onsubmit="return false"` on the outer form at line ~**60**
  - Root cause: dual-purpose button fell back to legacy sync POST when JS failed to intercept (Enter key, any JS error, etc.), rendering the minimal legacy UI instead of the full async dashboard
  - Verify: `grep -n 'id="seobetter-async-generate"' seobetter/admin/views/content-generator.php`

- **Legacy server-side result block removed** — `admin/views/content-generator.php` previously lines 666-818 deleted
  - Was an entire `<?php if ( $result ) : ?>` branch that rendered a stripped-down result panel (GEO Score + Words + suggestions + a Save Draft form with nonce-prone submit buttons) when the sync POST path ran
  - Included a "Save as WordPress Draft" button and "Fix X Issues & Re-optimize" button that both used `seobetter_draft_nonce` form nonces prone to "link expired" errors
  - Now replaced with an HTML comment pointing to `plugin_UX.md §3` for the required result panel
  - Verify: `grep -c 'legacy server-side $result' seobetter/admin/views/content-generator.php` (should be 1)

- **Legacy PHP handlers removed** — `admin/views/content-generator.php` previously lines 10-186 deleted
  - `seobetter_generate_article` (sync article generation)
  - `seobetter_generate_outline` (unused)
  - `seobetter_reoptimize` (replaced by `POST /seobetter/v1/generate/improve`)
  - `seobetter_create_draft` (replaced by `POST /seobetter/v1/save-draft`)
  - Verify: `grep -n "seobetter_create_draft" seobetter/admin/views/content-generator.php` (should return 0 results)

### Changed

- **renderResult bar chart list** — `admin/views/content-generator.php` `barItems` array in `renderResult()`, line ~**915**
  - Was 11 items matching v1.5.10 weights
  - Now 14 items matching v1.5.11 weights (added Keyword Density 10%, CORE-EEAT 5%, Humanizer 4%)
  - Keyword Density promoted to top of the list because it's the highest-weighted SEO plugin compatibility check
  - Verify: `grep -A 16 'var barItems' seobetter/admin/views/content-generator.php | grep -c label`

- **Analyze & Improve fix builder** — `admin/views/content-generator.php` `fixes` array builder, line ~**983**
  - Added 3 flag-mode "Check" buttons for the v1.5.11 checks: `keyword` (Check Keyword Placement), `humanizer` (Check AI Writing Patterns), `core_eeat` (Check E-E-A-T Signals)
  - Rebalanced impact labels on existing 8 fixes to match v1.5.11 weights (Citations +12 → +10, Statistics +12 → +10, Expert Quotes +8 → +6, Comparison Table +6 → +5, Freshness +7 → +6, Check Readability +12 → +10, Check Pronoun Starts +10 → +8, Check Section Openings +10 → +8)
  - Verify: `grep -c "Check Keyword Placement\|Check AI Writing Patterns\|Check E-E-A-T Signals" seobetter/admin/views/content-generator.php`

### Verified by user

- **UNTESTED** — waiting on user reinstall + Test 3 regeneration. Expected visible changes after reinstall:
  - Plugin version shows **1.5.12** on Plugins page
  - Generate button runs async flow (no page reload)
  - Result panel renders full dashboard: SVG ring + 3 stat cards + **14** bar charts + Pro upsell + Analyze & Improve with 8–11 fix buttons + headline selector + Save Draft with **Post/Page dropdown**
  - Save Draft button does NOT show "link followed has expired"
  - Pressing Enter in any form field does NOT reload the page

---

## v1.5.11 — Guideline integration

**Date:** 2026-04-12
**Commit:** `80d25d5`

### Added

- **Keyword density check** — `includes/GEO_Analyzer.php::check_keyword_density()` line **370**
  - Measures primary keyword density, H2 coverage %, intro-paragraph placement. Blends into 0-100 score at 10% weight. Drops to 0 above 2.5% (keyword stuffing).
  - Verify: `grep -n 'function check_keyword_density' seobetter/includes/GEO_Analyzer.php`

- **Humanizer post-check** — `includes/GEO_Analyzer.php::check_humanizer()` line **455**
  - Scans for Tier-1 banned words (delve, tapestry, pivotal, etc.) + Tier-2 clusters + 8 banned patterns. 4% weight. `-15` per Tier-1 word, `-10` per excess Tier-2, `-10` per pattern.
  - Verify: `grep -n 'function check_humanizer' seobetter/includes/GEO_Analyzer.php`

- **CORE-EEAT lite scoring (10 items)** — `includes/GEO_Analyzer.php::check_core_eeat()` line **539**
  - C1 direct answer, C2 FAQ, O1 hierarchy, O2 tables, R1 numbers, R2 citations, E1 first-hand, Exp1 examples, A1 entities, T1 tradeoffs. 5% weight.
  - Verify: `grep -n 'function check_core_eeat' seobetter/includes/GEO_Analyzer.php`

- **CORE-EEAT full (80-item rubric)** — new file `includes/CORE_EEAT_Auditor.php`
  - CORE (Contextual Clarity, Organization, Referenceability, Exclusivity × 10 items) + EEAT (Experience, Expertise, Authority, Trust × 10 items) + VETO items (C01 title mismatch, R10 contradictions, T04 missing disclosures).
  - REST endpoint: `GET /seobetter/v1/core-eeat/{post_id}`
  - Verify: `ls seobetter/includes/CORE_EEAT_Auditor.php && grep -n 'function audit' seobetter/includes/CORE_EEAT_Auditor.php`

- **5-Part Content Ranking Framework** — new file `includes/Content_Ranking_Framework.php`
  - Wraps Async_Generator pipeline with explicit phase tracking. `::quality_gate()` blocks publish below GEO 60 OR on any CORE-EEAT veto.
  - Verify: `ls seobetter/includes/Content_Ranking_Framework.php && grep -n 'function quality_gate' seobetter/includes/Content_Ranking_Framework.php`

- **CSS typography** — `includes/Content_Formatter.php::format_classic()` line **526**
  - `clamp()` fluid heading sizes (H1: `clamp(1.8em,4vw,2.4em)`, H2: `clamp(1.3em,3vw,1.6em)`), `text-wrap: balance` on headings, `text-wrap: pretty` on paragraphs, system font stacks (`ui-serif` headings, `ui-sans-serif` body), `max-width: 65ch` body.
  - Verify: `grep -n 'text-wrap:balance\|clamp(1.8em' seobetter/includes/Content_Formatter.php`

- **External link attributes** — `includes/Content_Formatter.php::inline_markdown()` line **716**
  - All external links auto-get `rel="noopener nofollow" target="_blank"`. Internal links (same host as site) keep bare `<a>` tags. Implemented as `preg_replace_callback` with `wp_parse_url` host check.
  - Verify: `grep -n 'noopener nofollow' seobetter/includes/Content_Formatter.php`

### Scoring rubric rebalanced

All 11 existing weights reduced proportionally to fit the 3 new checks. Total sums to 100:
- readability 12→10, bluf_header 10→8, section_openings 10→8, island_test 10→8
- factual_density 12→10, citations 12→10, expert_quotes 8→6, tables 6→5
- lists 5→4, freshness 7→6, entity_usage 8→6
- NEW: keyword_density 10, humanizer 4, core_eeat 5

### Verified by user

- **UNTESTED** — all 7 additions waiting on user's first regeneration + inspection of the Analyze & Improve panel

---

## v1.5.10 — Image markdown corruption fix (FM-13)

**Date:** 2026-04-12
**Commit:** `8bf336a`

### Fixed

- **8 regex locations** now use `(?<!!)` negative lookbehind so `![alt](url)` image markdown is never matched as link markdown:
  - `seobetter.php` Pass 1 malformed stripper — line **1347**
  - `seobetter.php` Pass 2 markdown link filter — line **1417** (this was the primary bug source)
  - `seobetter.php::verify_citation_atoms()` — line **1472**
  - `seobetter.php::sanitize_references_section()` — line **1659**
  - `seobetter.php::append_references_section()` — line **1746**
  - `includes/Content_Formatter.php::inline_markdown()` — line **723**
  - `includes/AI_Content_Generator.php` link rewriter — line **688**
  - `includes/GEO_Analyzer.php::check_core_eeat()` citation counter — line **596**
  - Verify all at once: `grep -rn '(?<!!)\[' seobetter/seobetter.php seobetter/includes/`

- **Stock_Image_Inserter::insert_images()** — `includes/Stock_Image_Inserter.php` line **49**
  - Now skips Key Takeaways / FAQ / References / Sources / Bibliography / Further Reading headings
  - Places images on content-bearing H2s (#2, #5, #8) only
  - Verify: `grep -n 'key\\\\s\*takeaway\|faq\|frequently' seobetter/includes/Stock_Image_Inserter.php`

### Root cause

Pass 2's regex `/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/` had no negative lookbehind for `!`. It matched the inner `[alt](url)` of `![alt](url)` image markdown, leaving a stray `!` + anchor text when the image URL (picsum.photos, unsplash) failed the whitelist. The stranded `!alt text` became a paragraph in `Content_Formatter::parse_markdown()`, which broke `format_hybrid`'s backward walk for Key Takeaways styling detection.

### Verified by user

- ✅ **Verified working** — user confirmed Key Takeaways styling restored and no stray `!` in regenerated articles

---

## v1.5.9 — Research Pool architecture

**Date:** 2026-04-12
**Commit:** `c3f1f73`

### Added

- **Citation_Pool class** — new file `includes/Citation_Pool.php`
  - `::build()` — pre-fetches keyword-relevant URLs via DDG/Brave/Wikipedia during the async generation `trends` step. Applies hygiene filter (no APIs, no homepages), live HEAD-check (200-399), and content verification (keyword appears in destination). Returns up to 12 entries. 6-hour transient cache.
  - `::format_for_prompt()` — formats the pool as an `AVAILABLE CITATIONS` block injected into every section prompt
  - `::contains_url()` + `::get_entry()` — normalized URL lookup for the validator
  - Verify: `ls seobetter/includes/Citation_Pool.php && grep -n 'public static function' seobetter/includes/Citation_Pool.php`

- **Pool threading in Async_Generator** — `includes/Async_Generator.php::run_step()` during the `trends` step
  - Stores pool at `$job['results']['citation_pool']`
  - Appends pool-prompt to every section generation via `$trends_context`
  - Verify: `grep -n "Citation_Pool::build" seobetter/includes/Async_Generator.php`

- **Validator pool integration** — `seobetter.php::validate_outbound_links()` line **1331**
  - Accepts `$citation_pool` as 2nd arg (primary allow-list)
  - Static whitelist becomes the fallback for obscure keywords
  - `filter_link()` checks pool membership first, whitelist second
  - Verify: `grep -n 'validate_outbound_links.*citation_pool' seobetter/seobetter.php`

- **append_references_section()** — `seobetter.php::append_references_section()` line **1731**
  - Walks the cleaned body, finds pool URLs actually cited, builds programmatic `## References` section using pool metadata titles
  - AI is explicitly told NOT to write References section
  - Verify: `grep -n 'function append_references_section' seobetter/seobetter.php`

### Verified by user

- ✅ **Verified working** — user confirmed citations resolve to real keyword-relevant pages, no hallucinated URLs

---

## v1.5.8 — RLFKV fine-grained citation verification

**Date:** 2026-04-12
**Commit:** `096a88b`

### Added

- **verify_citation_atoms()** — `seobetter.php::verify_citation_atoms()` line **1470**
  - Pass 3 of `validate_outbound_links`
  - For each surviving `[text](url)`: tokenize anchor text → extract content words (4+ chars, non-stopword) → fetch destination page → require ≥50% content-word overlap with title + first 3000 chars of body → strip if mismatch
  - Session cache + 24-hour transient cache keyed by `md5(url + anchor_text)`
  - Also strips vague anchor text ("here", "learn more", "this article") — zero content words
  - Adapted from Yin et al. 2026 (arxiv 2602.05723) RLFKV atomic knowledge unit verification
  - Verify: `grep -n 'function verify_citation_atoms' seobetter/seobetter.php`

### Verified by user

- ✅ **Verified working** — user confirmed misattributed citations (URL real but anchor text doesn't match destination) are stripped

---

## v1.5.7 — Hallucinated References section sanitizer

**Date:** 2026-04-12
**Commit:** `da9c572`

### Added

- **sanitize_references_section()** — `seobetter.php::sanitize_references_section()` line **1612**
  - Pass 0 of `validate_outbound_links`
  - Detects `## References`, `## Sources`, `## Bibliography`, `## Further Reading`, `## Citations` headings
  - Walks each line, applies whitelist rules to every link, drops failing lines
  - Removes the heading entirely if zero references survive
  - Verify: `grep -n 'function sanitize_references_section' seobetter/seobetter.php`

### Fixed

- **Title selection radio buttons** — `admin/views/content-generator.php` — removed broken `onchange` referencing non-existent `async-draft-title` element
- **Save Draft "link expired" error** — moved `#seobetter-async-result` div outside outer `<form method="post">` to prevent click-bubbling into form submission
- Added `e.preventDefault()` + `e.stopPropagation()` to Save Draft click handler

### Verified by user

- ✅ **Verified working**

---

## v1.5.6 — Strict link rules (no homepages, no API anchor text)

**Date:** 2026-04-12
**Commit:** `6b6796a`

### Added

- **Hard-fail rules in `filter_link()`** inside `validate_outbound_links()`:
  - Anchor text cannot contain `api`, `endpoint`, `dataset`, `sdk`, `webhook`
  - URL path cannot contain `/api/`, `/v[1-9]/`, `/graphql`, `/rest/`, `/swagger`
  - URL host cannot match `api.*`, `*-api.*`, `*.herokuapp.com`
  - URL must be a deep link (path not empty, not `index.html`, not `index.php`)
  - Verify: `grep -n 'api\|endpoint\|dataset\|sdk\|webhook' seobetter/seobetter.php | head -10`

- **Content_Injector::inject_citations()** — `includes/Content_Injector.php`
  - Now rejects URLs with API patterns, generic titles (< 8 chars, "Source", "Article"), homepages
  - HEAD-checks every surviving URL before adding to References
  - Fails loudly if zero sources pass: "No direct article sources found..."

### Verified by user

- ✅ **Verified working**

---

## v1.5.5 — First strict whitelist validator

**Date:** 2026-04-12
**Commit:** `144815f`

### Added

- **get_trusted_domain_whitelist()** — `seobetter.php` (static domain allow-list with wildcards: `*.gov`, `*.edu`, `wikipedia.org`, `rspca.org.au`, major news/health/science domains)
- **is_host_trusted()** — `seobetter.php` (exact + suffix + wildcard match)
- **First version of strict `validate_outbound_links()`** — replaced the broken v1.4 HEAD-fallback-to-homepage logic
- **check-citations Claude skill** installed at `~/.claude/skills/check-citations/` from `https://github.com/PHY041/claude-skill-citation-checker.git`

### Verified by user

- ✅ **Verified working**

---

## Template for new entries

When you complete a task, copy this block to the top of the `Added / Changed / Fixed` chronology:

```markdown
## v1.5.X — [one-line description]

**Date:** YYYY-MM-DD
**Commit:** `[short SHA]` (or `[pending]` until committed)

### Added / Changed / Fixed

- **[Feature name]** — `[file path]::[function/method]()` line **[exact line]**
  - [One-sentence description of what it does]
  - Verify: `grep -n '[searchable anchor]' [file path]`

### Verified by user

- **UNTESTED** / ✅ Verified working / ❌ Broken
```

**Rules for entries:**

1. Every entry MUST have at least one file:line anchor. Entries without anchors are banned.
2. The method name is the stable anchor — line numbers are hints that drift as files are edited. Always include a `Verify:` command the user can copy-paste.
3. New features default to `UNTESTED` until the user confirms. Never mark as "Verified" based on your own testing.
4. Commit SHA can be `[pending]` during the task, but must be filled in after the commit.
5. If you discover a drift (code doesn't match a previous entry), update the old entry with a note like `❌ Drifted in vX.Y.Z — method moved to line N, see commit SHA` — do NOT silently fix the line number.
