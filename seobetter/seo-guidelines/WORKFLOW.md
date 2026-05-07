# SEOBetter WORKFLOW — Single Source of Truth

> **🛑 READ THIS BEFORE ANY TEST, CODE EDIT, OR AUDIT. NO EXCEPTIONS.**
>
> This file replaces fragmented references to TESTING_PROTOCOL.md + pro-features-ideas.md + content-type-status.md + audit scripts. Everything you (or future-Claude) needs to test SEOBetter articles is in this one file.
>
> When the agent claims "ready for review" — the user reads §7 (Current Iteration State) of THIS file. That is the ONLY source for sign-off review.
>
> **Last updated:** 2026-05-07
>
> **Scope:**
> - §1 Iron Law (the rule the rest of the file enforces)
> - §2 The Loop (RED → GREEN → DEPLOY → AUDIT → VERIFY → REVIEW)
> - §3 Skills to invoke at each step
> - §4 Universal article checks (every type, every country)
> - §5 Per-content-type checks (21 types)
> - §6 Country-localisation checks (40 countries)
> - §7 Current iteration state (LIVE — agent updates each step)
> - §8 Past iterations (append-only log)
> - §9 Why this file exists

---

## §1 — Iron Law

```
NO COMPLETION CLAIMS WITHOUT FRESH VERIFICATION EVIDENCE
```

If the agent has not run the verification command IN THIS MESSAGE, it cannot claim "verified", "fixed", "passing", "signed off", or any synonym. This applies to:
- Agent → user "v62.X is verified" → must paste the audit output that proves it
- Agent → BUILD_LOG.md `UNTESTED → ✅ Verified` → must reference an audit-output marker AND a validator-pass file
- Agent → next-task → must run the iron-law gate (invoke `verification-before-completion` skill) BEFORE moving on

**Banned shortcuts:** "should work now", "test passed earlier", "audit was clean", "I'm confident", "just this once". Each one is a lie if no evidence is in the same message.

**Hooks that enforce this:**
- `.claude/hooks/check-buildlog.sh §1c` — blocks `git commit` whose message contains "verified"/"signed off" unless an `AUDIT:` marker or `pass: N / fail: N` line is in the commit body
- `.claude/hooks/check-buildlog.sh §1d` — blocks the same commits unless `tests/.validator-pass-{POST_ID}` exists OR commit body contains `validator: clean`/`validator: warnings-only`

---

## §2 — The Loop

Every fix and every article-test follows this loop in order. Skipping a step = lying. The agent updates §7 (Current Iteration State) at each transition.

### 2.A — Bug fix loop

| Phase | What happens | Allowed tools | Must produce |
|---|---|---|---|
| **RED**     | Write a unit test in `seobetter/tests/test-{feature}.php` that exercises the bug. Mirror production logic (extract via regex from seobetter.php so the test runs WordPress-free). | Read, Write, Edit (test files only), Bash (php tests/) | A test file with at least one assertion that demonstrates the bug |
| **RED-VERIFY** | Run the test on VPS via cron-fed `tests/run-all.php`. Confirm `pass: false`. If it passes immediately the test is wrong. | Bash (curl results.json) | Verbatim VPS test output showing `pass: false` |
| **GREEN**   | Edit production code in `seobetter.php` / `includes/`. The TDD gate hook denies any prod edit unless a test file is staged (no skip token allowed any more). | Edit (prod files OK once test staged), Read | Diff that resolves the bug |
| **GREEN-VERIFY** | Run the test on VPS again. Confirm `pass: true`. | Bash | Verbatim VPS test output showing `pass: true` |
| **DEPLOY** | `rm -f /Users/ben/Desktop/seobetter.zip && zip -rq …` → upload via browse-cli to wp-admin/plugin-install.php?tab=upload → confirm `plugin_version` in `results.json` flipped to the new version. | Bash, browse-cli | `plugin_version: 1.5.216.62.X` matching what was just shipped |
| **AUDIT** | Pick the LATEST post id (or regenerate one). Run `python3 tests/full_audit.py POST_ID 'KEYWORD' COUNTRY WORDS` AND any per-type audit (`tests/recipe_audit.py` for recipes, etc.). Run validator.schema.org. Run browse-cli visible-text scan. | Bash | Full verbatim output of every audit + validator response + browse-cli scan |
| **VERIFY-BEFORE-CLAIM** | Invoke `Skill: verification-before-completion`. Iron law: "no claim without fresh evidence in same message". | Skill | Skill loaded; iron law re-stated |
| **REVIEW** | Update §7 with all the verbatim outputs above. Tell user: "read §7 of WORKFLOW.md". Wait for user thumbs-up. | Read, Edit (this file's §7 only) | §7 updated; user response awaited |
| **SIGN-OFF** | Only after explicit user "verified" / "approved": flip BUILD_LOG.md `UNTESTED → ✅ Verified DATE`, write `tests/.validator-pass-{POST_ID}`, append §8 entry. | Edit, Write | BUILD_LOG flipped; validator-pass file written; §8 row added |

### 2.B — Article-test loop (no code change, just verifying an article generation)

```
1. Submit Bulk batch via browse-cli (admin form fill + click Start)
2. Force AS worker tick: fetch /wp-json/seobetter/v1/bulk-process/{BATCH}
3. Poll for new post id > previous-latest
4. Run all 3 audits: full_audit.py, {type}_audit.py, validator.schema.org, browse-cli visible-text scan
5. Invoke Skill: verification-before-completion
6. Update §7 with VERBATIM output of every check
7. Wait for user approval before §8 entry
```

---

## §3 — Skills invoked at each step

In order (Process skills before implementation skills, per `using-superpowers`):

| When | Skill | Purpose |
|---|---|---|
| Bug discovered, picking approach | `Skill: brainstorming` | Surface options + tradeoffs before committing |
| About to write the test | `Skill: test-driven-development` | Formal red-green-refactor walkthrough |
| About to write/edit prod code | `Skill: test-driven-development` | Same — keeps GREEN strict |
| Hard-to-isolate bug, need to debug | `Skill: systematic-debugging` | Structured hypothesis testing |
| About to claim "fixed"/"passes"/"verified"/"signed off" | `Skill: verification-before-completion` | The iron-law gate (§1) |
| Done with branch, packaging for ship | `Skill: finishing-a-development-branch` | Final pre-merge checklist |

The agent MUST invoke these via the `Skill` tool — reading the skill file is not the same as invoking it.

---

## §4 — Universal article checks (run on EVERY article)

These run regardless of content_type or country. Implemented in `tests/full_audit.py`. **Each check must show its result line-by-line in §7 — no summary-only.**

| # | Check | Pass criteria | Source of truth |
|---|---|---|---|
| 1 | **Word count** | ≥ 0.85 × target word count | full_audit.py |
| 2 | **Single H1** | Rendered page has exactly 1 `<h1>` tag | full_audit.py |
| 3 | **H2 count ≥ 5** | Body has at least 5 `<h2>` headings | full_audit.py |
| 4 | **Keyword in title** | First 1-2 keyword tokens appear in `<title>` | full_audit.py |
| 5 | **References section present** | Body contains a "References" or "Sources" heading | full_audit.py |
| 6 | **JSON-LD scripts present** | `<script type="application/ld+json">` ≥ 1 block | full_audit.py |
| 7 | **JSON-LD parses cleanly** | All blocks are valid JSON; @graph extracted | full_audit.py |
| 8 | **Has Article/Product/Recipe @type** | At least one rich-result eligible top-level @type | full_audit.py |
| 9 | **ImageObject — no duplicate URLs (v62.93)** | Each ImageObject node has a unique contentUrl/url | full_audit.py + Schema_Generator dedup |
| 10 | **No duplicate singular @types** | Article/FAQPage/BreadcrumbList/Org/Person should appear once each (Recipe MAY appear multiple times — multi-recipe article is intentional) | full_audit.py |
| 11 | **FAQ section ↔ FAQPage schema** | If body has "Frequently Asked Questions" / "FAQ" heading, FAQPage @type must be in @graph | full_audit.py |
| 12 | **BreadcrumbList in JSON-LD** | BreadcrumbList @type present | full_audit.py |
| 13 | **Meta description present** | `<meta name="description" content="…">` non-empty | full_audit.py |
| 14 | **Meta description length 50–160** | `len(content) ∈ [50,160]` | full_audit.py |
| 15 | **0 unlinked source-host parens (v62.94+96)** | No bare `(Cats.com)` `(palnests.com)` etc. in body — each must be wrapped in an anchor pointing to the matching pool URL | full_audit.py |
| 16 | **0 unlinked `[Source]` brackets** | No `[Source]` / `[Brand]` patterns inside `<p>`/`<li>` body that aren't wrapped in `<a>` | full_audit.py |
| 17 | **≥ 2 images** | At least 2 `<img>` tags in body | full_audit.py |
| 18 | **≥ 3 outbound links** | Body has ≥ 3 external `<a>` tags | full_audit.py |
| 19 | **No banned-host citations** | None of: bsky.app, bsky.social, mastodon.*, lemmy.*, news.ycombinator.com, quora.com | full_audit.py + is_low_quality_source() |
| 20 | **SpeakableSpecification node** | At least one node with `@type: SpeakableSpecification` (for voice-readout) | full_audit.py |
| 21 | **Author Person node** | `@type: Person` node in @graph | full_audit.py |
| 22 | **Organization publisher** | `@type: Organization` node in @graph | full_audit.py |
| 23 | **Zero inline bolds in body** | No `<strong>` / `<b>` tags inside body content | full_audit.py + Content_Formatter strip |
| 24 | **Schema.org validator pass** (NEW v62.94 §3A) | POST URL to validator.schema.org → 0 errors in `tripleGroups[].nodes[].properties[].errors[]` | curl + JSON parse |
| 25 | **Browse-cli visible-text scan** (NEW v62.94 §3B) | Load article in real headless browser (NOT curl). Strip `<a>` blocks. Find parenthetical patterns matching pool entries — fail if any. Find bracket patterns — fail if any (excluding CSS pseudo-selectors and footnote markers) | browse-cli + JS eval |

---

## §5 — Per-content-type checks (21 types)

These run IN ADDITION to §4 universal checks. Each row = additional checks for that type. Implemented as separate audit scripts (`tests/{type}_audit.py`).

### 5.1 — `blog_post`
- Default §3.1 profile (no override)
- @type: BlogPosting OR Article
- No additional checks beyond §4

### 5.2 — `news_article`
- §3.1A genre override (inverted pyramid)
- @type: NewsArticle
- Has `dateline` near top of body
- Has `datePublished` AND `dateModified` in schema (within 30 days for news)

### 5.3 — `opinion`
- §3.1A genre override (Hybrid — keeps KT+FAQ+Refs)
- @type: OpinionNewsArticle (or Article + articleSection: "Opinion")
- Red disclosure bar visible at top
- Devil's-Advocate frame block centred on "The Objection" H2
- Zero citations from bsky/HN/Quora/Mastodon/Lemmy

### 5.4 — `how_to`
- @type: HowTo (NOT Article-only)
- step boxes with circle counters in rendered HTML
- HowToStep array, each with `name` + `text` + `url` anchor
- `totalTime` ISO 8601

### 5.5 — `listicle`
- @type: ItemList (with optional Article wrapper)
- numberOfItems matches H2 count of items
- Oversized item-number CSS visible
- Each item has H2 with leading number

### 5.6 — `review`
- @type: Review (with smart itemReviewed @type — Product / SoftwareApplication / Restaurant / Movie / Book based on subject)
- score badge visible
- pros/cons two-column grid
- aggregateRating if multiple sub-reviews

### 5.7 — `comparison`
- @type: Article (no comparison-specific @type yet)
- "VS" badge visible between two columns
- Two-column grid with matched rows
- Pool ≥ 2 outbound URLs per side

### 5.8 — `buying_guide`
- @type: ItemList + Article
- "Our Pick" pills near top
- 5–7 product H2s, each ≥ 250–350 words (v62.90)
- Each product H2 has its OWN section
- ItemList.itemListElement matches product H2 count

### 5.9 — `recipe` (most schema-heavy)
- @type: Recipe (NOT Article-only)
- recipeIngredient: non-empty array, **NO nutrition-fact pollution** (no "Calories: X", "Total Fat: Yg", "Servings Per Recipe", "(Nutrition for one serving)")
- recipeInstructions: HowToStep array, each with `name` + `text` + `url` anchor
- prepTime: ISO 8601 (e.g. `PT15M`)
- cookTime: ISO 8601
- totalTime: ISO 8601
- recipeYield: present (e.g. "6 servings")
- recipeCuisine: matches country (see §6)
- recipeCategory: present
- nutrition.calories: present
- image: ≥ 1
- Yellow recipe card visible
- Multi-recipe articles MAY have multiple Recipe nodes — that's intentional, not a duplicate-@type bug

### 5.10 — `faq_page`
- @type: FAQPage (top-level, not just sub-node)
- mainEntity: array of Question with acceptedAnswer
- Accordion Q&A visible

### 5.11 — `tech_article`
- @type: TechArticle
- Dark code blocks with traffic-light buttons
- Language label on each code block
- proficiencyLevel field (Beginner/Intermediate/Expert)

### 5.12 — `white_paper`
- @type: TechArticle / Article
- Executive Summary box at top
- Section numbering (1.1, 1.2, 2.1, …)

### 5.13 — `scholarly_article`
- @type: ScholarlyArticle
- Abstract box at top
- Citation format with author, year, journal

### 5.14 — `live_blog`
- @type: LiveBlogPosting
- Timestamped entries (visible in body)
- coverageStartTime / coverageEndTime

### 5.15 — `press_release`
- §3.1A genre override (Dateline + inverted pyramid)
- @type: NewsArticle + articleSection: "Press Release"
- Dateline block at top (CITY, Date)
- Organization sameAs in publisher

### 5.16 — `personal_essay`
- §3.1A genre override (Literary narrative)
- @type: BlogPosting + articleSection: "Personal Essay"
- Narrow serif column visible
- Italic centred pull quotes
- Speakable cssSelector includes pull quotes
- backstory block

### 5.17 — `glossary_definition`
- @type: DefinedTerm
- Definition highlight box
- "See Also" related-terms block

### 5.18 — `sponsored`
- @type: AdvertiserContentArticle (Article fallback if Google doesn't recognise)
- Disclosure bar visible at top
- Sponsor area with Brand mention

### 5.19 — `case_study`
- @type: Article
- Large stat numbers visible
- Challenge / Solution / Results structure (3 H2s required)

### 5.20 — `interview`
- §3.1A genre override (Q&A is the content)
- @type: Article + secondary ProfilePage for interviewee
- Q/A cards alternating green/gray
- mainEntity: Person (interviewee)

### 5.21 — `pillar_guide`
- @type: Article
- Chapter numbers visible
- TOC with progress indicator
- ≥ 8 H2s (chapters)

---

## §6 — Country-localisation checks

Run if `country` ≠ US. Implemented in per-type audit scripts.

| Country | Currency | Units | Spelling | Authority citations (≥ 2 required) |
|---|---|---|---|---|
| **GB** | £ (NEVER $) | °C, grams, ml, kg | UK English (flavour, colour, organise) | bbcgoodfood.com, deliciousmagazine.co.uk, jamieoliver.com, maryberry.co.uk, olivemagazine.com, greatbritishchefs.com, food.gov.uk, nhs.uk |
| **AU** | A$ / AUD | °C, grams, ml | UK English | abc.net.au, choice.com.au, smh.com.au, theage.com.au, food.com.au |
| **NZ** | NZ$ / NZD | °C, grams, ml | UK English | stuff.co.nz, rnz.co.nz, foodstuffs.co.nz |
| **CA** | C$ / CAD | °C, grams (some imperial) | Mostly UK + some US | cbc.ca, theglobeandmail.com, cookingchanneltv.ca |
| **US** | $ | °F, cups, oz, lb | US English | nytimes.com, allrecipes.com, seriouseats.com, bonappetit.com, americastestkitchen.com |
| **IE** | € | °C, grams | UK English | rte.ie, irishtimes.com, bordbia.ie |
| **IN** | ₹ / INR | °C, grams | UK English (with Indian English variants) | timesofindia.indiatimes.com, hindustantimes.com, ndtv.com |
| **DE** | € | °C, grams | German | tagesschau.de, spiegel.de, sueddeutsche.de |
| **FR** | € | °C, grams | French | lemonde.fr, lefigaro.fr, marmiton.org |
| **JP** | ¥ / JPY | °C, grams | Japanese | nhk.or.jp, asahi.com, cookpad.com |
| **CN** | ¥ / CNY / RMB | °C, grams | Chinese (Simplified) | xinhuanet.com, people.com.cn, xiachufang.com |
| **KR** | ₩ / KRW | °C, grams | Korean | korea.kr, chosun.com, mangoplate.com |
| **MX** | $ MX | °C, grams | Spanish | eluniversal.com.mx, milenio.com, recetasdecocina.com |
| **BR** | R$ / BRL | °C, grams | Portuguese (BR) | g1.globo.com, folha.uol.com.br, panelinha.com.br |
| **(other 26 countries)** | — | — | — | See `country_apis.md` for the 40-country full table |

**Per-country checks the audit must run:**

For country=`COUNTRY`:
- (U1) Currency symbol matches the row above (count `£`/`€`/`¥`/`$`/etc in body)
- (U2) Temperature unit matches (count `°C` vs `°F` references)
- (U3) Weight units match (count grams/ml vs cups/oz/lb)
- (U4) Spelling matches (count UK-spelling words vs US-spelling words)
- (U5) ≥ 2 outbound links to country-authority hosts from the row

---

## §7 — Current Iteration State (LIVE)

> The agent updates this section every step of §2.A or §2.B. The user reads ONLY this section to approve sign-off. Everything above is the contract; this section is the receipt.

### Currently working on

**v62.97 — Recipe schema extraction bugs** (found via post 769 audit)

Bugs being fixed:
1. `Schema_Generator::build_recipe()` — recipeIngredient array contains nutrition facts ("Calories: 558", "Total Carbohydrate: 51g (19%)", "(Nutrition for one serving)") instead of real ingredients (post 769 Recipe[0])
2. `Schema_Generator::build_recipe()` — `prepTime` not extracted (post 769)
3. `Schema_Generator::build_recipe()` — `totalTime` not extracted (post 769)
4. `Schema_Generator::build_recipe()` — `recipeYield` not extracted despite "Servings Per Recipe: 6" present (post 769)

### Current phase

**RED — pending** (to be entered after WORKFLOW.md is committed and escape-valves stripped)

### Last test run on VPS

(Not yet — RED phase will produce this.)

### Last audit on real article

```
POST 769 — full_audit.py output (last run 2026-05-07 21:45 UTC)
URL: https://srv1608940.hstgr.cloud/5-reasons-this-homemade-cornish-pasty-recipe-beats-takeout/
KW:  homemade cornish pasty recipe | Country: GB | Target words: 1500
Result: 15 pass / 7 fail

[FAIL] Single H1 in rendered page (2 H1)                       ← carry-over (theme)
[FAIL] No duplicate singular @types (Recipe x2)                 ← intentional (multi-recipe)
[FAIL] Meta description present (len=0)                         ← carry-over
[FAIL] Meta description length 50-160 (len=0)                   ← carry-over
[FAIL] 0 unlinked source-looking parentheticals (2 = 'Office for National Statistics', 'ONS Statistics')  ← pool gap
[FAIL] SpeakableSpecification node                              ← carry-over
[FAIL] Zero inline bolds in body (10)                           ← carry-over

POST 769 — recipe_audit.py output: 13 pass / 4 fail
[FAIL] (R5a) prepTime in ISO 8601 (MISSING on both Recipe nodes)
[FAIL] (R5c) totalTime in ISO 8601 (MISSING on both)
[FAIL] (R6) recipeYield (MISSING despite "Servings Per Recipe: 6")
[FAIL] (U5) UK authority citations >=2 (audit-script gap; cornishpastyassociation.co.uk wasn't in U5 list)
PLUS Recipe[0].recipeIngredient = [
  '(Nutrition for one serving)',
  'Servings Per Recipe: 6',
  'Calories: 558',
  'Total Carbohydrate: 51g (19%)',
  …
]  ← THIS is the primary v62.97 bug

validator.schema.org: clean (0 errors, 7 nodes parsed)
browse-cli visible-text scan: 1 real unlinked paren ('Office for National Statistics')
```

### Verdict for user

**🟡 NOT READY FOR SIGN-OFF.** v62.97 fixes have not been written yet. RED phase pending.

---

## §8 — Past Iterations (append-only)

| Date | Version | Title | Verdict | Evidence | Notes |
|---|---|---|---|---|---|
| 2026-05-07 | v62.96 | Bulk pipeline order: validate before linkify | ✅ Verified post 767 | `tests/.validator-pass-767`, BUILD_LOG SHA `383d827` | Source-citation linkify on Bulk path. (Cats.com)/(palnests.com) etc — 0 unlinked (was 5 on post 762). validator.schema.org clean. 6/6 unit tests green incl. new bulk-pipeline-order canary. |
| 2026-05-07 | v62.95 | TDD test: production-mirror linkify | superseded by v62.96 | — | Test-only ship to confirm v62.94 wasn't actually fixing the problem. Real bug was downstream Pass 4 dedup. |
| 2026-05-07 | v62.94 | Linkify regex cap 150→250 | superseded by v62.96 | — | Regex change was correct but invisible because v62.89's order bug was masking everything. |
| 2026-05-07 | v62.93 | ImageObject contentUrl dedup | ✅ Verified post 753 (prior session) | — | Schema_Generator deduplicates ImageObject nodes by contentUrl. |
| (older) | … | … | … | See `BUILD_LOG.md` for the full chronological log | — |

---

## §9 — Why this file exists

Last session I claimed "verified" 4 separate times and missed user-visible bugs each time:
1. Claimed "T3 #4 How-To verified" → user found a chimeric quote
2. Claimed "T3 #7 Comparison verified" → user found bsky URLs that should have been blocked
3. Claimed "T3 #8 Buying Guide signed off" → user found duplicate ImageObject
4. Claimed "33 PASS / 0 FAIL on post 757" → user found unlinked parenthetical citations

Each claim was structurally honest (matched my audit script) but missed real bugs the user could see in the rendered article. The pattern was: I'd run a partial audit, summarize the result, claim done, and move on without showing the user the per-check output or running external validators.

This file is the fix:
- §1 makes "no claim without fresh evidence" the iron law
- §2 makes the loop explicit, with no hidden steps
- §3 makes the skills mandatory at each transition
- §4–§6 list every check by content_type and country so I can't "forget one"
- §7 is the single place the user reads to approve — no fragmentation
- §8 keeps the history append-only so we can grep when things broke

Combined with the harness gates (`.claude/hooks/check-buildlog.sh` audit-output marker + validator-pass evidence + TDD test required, all without `[skip-tdd]` override), this is the wall.

The only programmatic mechanism that can be stricter than this is YOU — refusing to approve §7 until the evidence holds. Anything I write into this file or into a hook I can also unwrite. The user is the only enforcement that I cannot edit.

---

> **Cross-references kept for compatibility:**
> - `TESTING_PROTOCOL.md` — predecessor doc; this file is the new canonical source
> - `BUILD_LOG.md` — chronological version log with file:line anchors (still maintained)
> - `pro-features-ideas.md` — roadmap (NEVER edited by agent unless user explicitly asks)
> - `content-type-status.md` — verified-date matrix (still maintained, but §5 above has the per-type checks)
