# SEOBetter Testing Protocol

> **🛑 SUPERSEDED 2026-05-07 — read [`WORKFLOW.md`](WORKFLOW.md) instead.**
>
> WORKFLOW.md consolidates this file plus the per-article check tables (universal + per-content-type + country) plus the live iteration-state section. Everything below is preserved for historical reference but the canonical source is now WORKFLOW.md.
>
> ---
>
> **Read this BEFORE every test, every code edit, every audit. No exceptions from v62.94 onward.**
>
> This document encodes the testing discipline that keeps regressions from sneaking back in. Pre-this-document I shipped v62.83→v62.93 (11 fixes) with **0 new unit tests** — every bug found in audit was a bug that could have been caught in 1 second by a unit test, but instead required a 15-20 min real-article regeneration to surface. This protocol closes that gap.

---

## 1. The TDD discipline (mandatory for v62.94+)

Every code fix that touches a function with extractable logic must go through this loop:

1. **RED** — Write the unit test FIRST in `seobetter/tests/test-{feature}.php`. Use the existing `tests/test-anchor-unwrap.php` / `test-headline-sanitizer.php` / `test-references-builder.php` as templates. Mirror the production logic in the test file (PHP closure copy of the function under test) so it runs without WordPress bootstrap.
2. **Run the test on VPS** — `php tests/test-{feature}.php` via curl-pipe from GitHub raw OR through the cron `run-all.php` runner. Confirm the test FAILS with the bug as designed (red phase).
3. **GREEN** — Edit the production code in seobetter.php / includes/. Re-run the test. Confirm PASS (green phase).
4. **Cron auto-picks** — `tests/run-all.php` globs `test-*.php` so the new test runs every 60 seconds on VPS forever. No registration needed.
5. **Then ship** — only after both red and green phases verified, commit + push + zip + deploy.
6. **Then real-article verify** — generate one Bulk article end-to-end and run the full audit script (see §3 below). The unit test catches regressions; the article test catches AI-output bugs.

If a fix is purely declarative (config constant, version bump, comment) the unit-test step can be skipped via `touch seobetter/.tdd-skip` — but use sparingly. Real logic = real test.

---

## 2. Retroactive test backfill (v62.83-93 coverage gaps)

These v62.83-93 fixes shipped without unit tests. Backfill ASAP — each gap is a regression risk:

| Version | Function / behavior | Test file to write | Effort |
|---|---|---|---|
| v62.83 | `SEOBetter::sanitize_headline()` wired into Bulk_Generator | already covered by `tests/test-headline-sanitizer.php` (logic) — wiring itself needs integration test | 0.5d |
| v62.84 | `Async_Generator::start_job` accepts `keyword` not `primary_keyword` | `tests/test-async-start-keyword.php` — feed both field names, assert `keyword` works | 0.5d |
| v62.85 | dead `SEOBetter::get_instance()` removed | grep test only — assert function isn't called from Bulk_Generator | 0.25d |
| v62.86 | `Schema_Generator::get_post_description()` prefers post-meta over content extract | `tests/test-schema-description-source.php` — feed pool with `_seobetter_meta_description` set, assert that text wins over content extraction | 0.5d |
| v62.86 | Social_Meta_Generator `extract_clean_fallback()` strips badges/headings/Last Updated | `tests/test-social-meta-fallback.php` — feed content with badges, assert fallback strips them | 0.5d |
| v62.87 | Bulk_Generator schema regen after meta sync (timing race) | hard to unit-test (requires WP); leave as integration-only | n/a |
| v62.88 | `buying_guide` in `SPEAKABLE_TYPES` constant | `tests/test-speakable-types.php` — assert all expected types in constant | 0.25d |
| v62.89 | Bulk_Generator runs `validate_outbound_links` | hard to unit-test (requires WP); leave as integration-only | n/a |
| v62.90 | `buying_guide` prose template explicit per-product H2 + word count | `tests/test-prose-template-buying-guide.php` — assert template `sections` and `guidance` strings contain "5-7 products", "EACH product gets its OWN H2", "250-350 words" | 0.5d |
| v62.91 | Bulk_Generator featured image call + pool extension | hard to unit-test; leave as integration-only | n/a |
| v62.92 | Bulk_Generator second `Citation_Pool::append_references_section` call | hard to unit-test; leave as integration-only | n/a |
| v62.93 | `Schema_Generator::detect_image_schemas()` dedupes by contentUrl | `tests/test-image-object-dedup.php` — feed content with two `<img>` tags same src, assert ImageObject array length 1 | 0.5d |

Plus the **parenthetical-linkify bug** found via post 757 audit (parenthetical citations like `(Cats.com)` not wrapped in anchors): write `tests/test-linkify-parens.php` BEFORE the v62.94 fix — that's the first new test under TDD discipline.

Total backfill estimate: ~3-4 days.

---

## 3. The audit checklist (run AFTER every article generation)

Run **all** of these on every test article. **Show the user the audit output verbatim** before claiming pass — they'll catch any check I missed.

### Existing checks (per `pro-features-ideas.md` audit template)

- Title / SEO surface (H1 50-60 chars + keyword + no `…`/`·`/Publisher suffix + brand caps)
- Meta description (§8) — `<meta name="description">` 145-165 chars, og + twitter match, contains keyword
- Schema (§10.1) — @type per CONTENT_TYPE_MAP, description matches meta, FAQPage 3-5 Q&A, BreadcrumbList, Speakable cssSelector, no bogus Product/SoftwareApp, Person + Organization
- **No duplicate ImageObject (v62.93)** — dedupe by contentUrl
- **No duplicate singular @types** — Article/FAQPage/BreadcrumbList/Org/Person/ItemList expected once
- Body structure (§3.1 default or §3.1A genre overrides)
- Country localization (when ≠ US)
- Anchor hygiene (0 bracket-anchors / generics / raw markdown / empty refs / currency markers)
- Word count ≥ per-type floor

### Mandatory NEW checks (effective v62.94)

These were missed when claiming "T3 #8 verified" because they weren't in the script. Add to every audit:

**A. External Schema.org validator pass**
- Fetch the rendered article URL → extract `<script type="application/ld+json">` blocks
- POST to the Schema.org validator OR drive `validator.schema.org` via browse-cli
- Copy errors / warnings VERBATIM to user
- Flag any "ERROR" as fail-blocker. Warnings are noted but don't fail the audit.
- Do the same for Google Rich Results Test (`https://search.google.com/test/rich-results?url=...`)

**B. Visible-text scan via browse-cli**
- Load article URL in real headless browser via browse-cli (NOT just `curl`)
- `eval` JS to extract `document.body.innerText` (the rendered visible text)
- Grep for `\(([^)]{2,80})\)` parenthetical patterns NOT inside an `<a>` tag — fail if any match a known pool entry's title or source_name (those should be linkified)
- Grep for `\[([^]]{2,80})\]` bracket patterns NOT inside an `<a>` tag — fail if any (excluding CSS-selector pseudo-content)
- Take screenshot — save to `/tmp/post{ID}-screenshot.png` for inspection
- This catches what curl+regex misses: things like `(Cats.com)` that appear visibly in body text

**C. Show audit script output explicitly to user**
- Before declaring any post "verified" or "passes audit", print the FULL audit output (every check, pass/fail/warn) into the chat.
- User reviews the output and explicitly approves before marking BUILD_LOG verified.
- No more "32 PASS / 1 FAIL" summary without showing the 32 checks. The user must see what was actually checked so they can flag missed checks.

---

## 4. Process for every future test

1. **Read this file** (TESTING_PROTOCOL.md) before any test work
2. **Read BUILD_LOG.md** for current state
3. If fixing a bug:
   a. Write unit test first (red)
   b. Run on VPS, see fail
   c. Fix production code
   d. Run unit test, see green
   e. Ship + push + zip + deploy (ZIP rebuild → WP admin upload → confirm `plugin_version` flipped in `results.json`)
   f. Wait for opcache invalidation (forced by plugin upload)
   g. Loop back: regenerate one Bulk article end-to-end and re-run full audit
   h. **Invoke `Skill: verification-before-completion`** BEFORE making any "fixed" / "verified" / "passing" claim
4. If testing an article:
   a. Trigger Bulk Generate via browse-cli
   b. Poll for new post
   c. Run `full_audit.py` — capture output verbatim
   d. Run external Schema.org validator (§3A)
   e. Run browse-cli visible-text scan (§3B)
   f. Print FULL audit output to user (§3C)
   g. **Invoke `Skill: verification-before-completion`** to gate the sign-off claim
   h. Wait for user approval before marking verified
5. Update BUILD_LOG with verified status only after user explicitly approves

---

## 4A. The verification-before-completion gate (closing rule)

Every fix-flow and every article-test-flow ends with the same step: **invoke the `verification-before-completion` superpowers skill before any completion claim**. The skill is a forcing function that prevents silent drift back into "shipped ≠ verified" claims.

**Iron law from the skill:**

> NO COMPLETION CLAIMS WITHOUT FRESH VERIFICATION EVIDENCE
>
> If you haven't run the verification command IN THIS MESSAGE, you cannot claim it passes.

**Concrete application here:**

| About to claim | Required fresh evidence in same message |
|---|---|
| "v62.X test passes" | tail of `results.json` showing the test name + `pass: true` + plugin_version matching |
| "v62.X bug fixed" | full_audit.py output on a NEW post generated under v62.X |
| "T3 #N signed off" | full_audit.py + recipe_audit.py (or type-equivalent) + validator.schema.org clean + browse-cli visible-text scan + user explicit approval |
| "Schema.org validates" | curl POST to validator.schema.org → 0 errors in response THIS MESSAGE |
| "Build deploy succeeded" | `plugin_version` from results.json matches what I just shipped |

**Banned shortcuts:**

- "Should work now" → run it
- "Test passed earlier" → re-run if claiming current state
- "Audit ran, results were clean" → re-paste the output
- Reading the skill text alone ≠ invoking it. Invoke via `Skill: verification-before-completion` then act on it.

The skill is in `~/.claude/plugins/cache/superpowers-dev/superpowers/5.1.0/skills/verification-before-completion/SKILL.md`. It's part of the `superpowers-dev` marketplace plugin installed 2026-05-06.

---

---

## 5. Why this matters

The pattern of the last session:
- I claimed "T3 #4 How-To verified" → user found chimeric quote I missed
- I claimed "T3 #7 Comparison verified" → user found bsky URLs I missed
- I claimed "T3 #8 Buying Guide signed off" → user found duplicate ImageObject I missed
- I claimed "33 PASS / 0 FAIL on post 757" → user found unlinked parenthetical citations I missed

Each "verified" claim was structurally honest (matched my audit script) but missed real bugs the user could see in the rendered article. The script needs the user-found patterns added immediately, and the validator pass + visible-text scan needs to run BEFORE claiming pass.

**Verification means: my audit + user-visible checks + external validator + user explicit approval. Anything less is "code shipped" not "verified".**
