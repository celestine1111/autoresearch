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
