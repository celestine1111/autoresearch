# SEOBetter Build Log

> **Source of truth for what has actually shipped in the code.**
>
> Every entry anchors to an exact file:line. Claims without anchors are banned.
>
> **Before citing this log as "done", ALWAYS grep the file:line to verify the code still matches.**
> Line numbers drift as files are edited — the method name is the stable anchor, the line number is a hint.
>
> **Last updated:** 2026-04-13
>
> **How to read this log:**
> - `✅ Verified by user` means the user has run the feature and confirmed it works in production
> - `UNTESTED` means the code exists but hasn't been tested by the user yet
> - `❌ Broken` means the user reported it broken and it's awaiting fix

---

## v1.5.12 — Restore full result UI + kill legacy sync POST fallback

**Date:** 2026-04-13
**Commit:** `[pending]`

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
