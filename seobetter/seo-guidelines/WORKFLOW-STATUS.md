# SEOBetter — Live Iteration State

> **This is the file the agent updates each loop step. WORKFLOW.md is the contract (locked); this file is the receipt (writable). When the agent says "ready for review", read this file.**
>
> See `WORKFLOW.md` for §1 Iron Law, §2 The Loop, §3 Skills, §4-§6 audit checklists.

---

## Last updated: 2026-05-07 (EOD)

## Current ship: v1.5.216.62.100 — DEPLOYED, awaiting user sign-off

### What v62.100 fixed (verified evidence in chat transcript)

| Bug | Post 774 (v62.99) | Post 777 (v62.100) | Source |
|---|---|---|---|
| 2 H1 tags in body | 2 | **1** ✅ | `Content_Formatter.php` line 509-512 + 910-914 — body h1 demoted to h2 |
| recipeCuisine missing | empty | **'British'** ✅ | `Schema_Generator.php` line ~1276-1300 — body-keyword fallback |
| recipeCategory missing | empty | **'Pastry'** ✅ | `Schema_Generator.php` line ~1453+ — pasty/pasties/scone/etc. specific match |
| Audit script: meta desc regex | false negative | fixed | `tests/full_audit.py` — handles unquoted attr |
| Audit script: SpeakableSpec | false negative | fixed | `tests/full_audit.py` — accepts nested `speakable` property of Article |
| Audit script: Recipe x2 dup | false fail | excluded | `tests/full_audit.py` — Recipe legitimately multi-instance |
| Audit script: prose paren | false pos | filtered | `tests/full_audit.py` — em-dash sentences excluded |

### Test on VPS (`tests/run-all.php` cron-fed `results.json`)

```
plugin_version: 1.5.216.62.100
summary: 8/8 pass, 0 fail
recipe-misc-extraction: pass=True (18/18 cases — h1 6 + cuisine 6 + category 6)
+ all 7 prior tests still green
```

### Real-article verification on post 777

```
Article: https://srv1608940.hstgr.cloud/5-steps-for-a-homemade-cornish-pasty-recipe-that-delivers/
full_audit.py:   21 pass / 1 fail (1 inline bold remaining)
recipe_audit.py: 12 pass / 5 fail (carry-overs below)
validator.schema.org: clean (0 errors)
browse-cli scan: 0 unlinked source citations
```

### Sign-off status

🟡 **AWAITING USER APPROVAL.**

Per WORKFLOW.md §1 Iron Law + §2.A step h: the agent cannot flip BUILD_LOG `UNTESTED → ✅ Verified` until user explicitly approves. Three options were offered:

- **(A)** Sign off v62.100 contract (h1+cuisine+category). Move on to v62.101 (inline-bold strip).
- **(B)** Hold sign-off; tackle v62.101 inline-bold strip first; single sign-off after.
- **(C)** Tackle ALL remaining bugs (inline bold + AI-prompt fixes for prepTime/totalTime/yield + UK spelling) before any sign-off.

User has not yet replied. EOD reached.

---

## Backlog (separate ships, not in v62.100 scope)

### v62.101 candidate — Content_Formatter inline-bold strip

- **Bug:** post 777 has 1 `<strong>` tag in body (was 10 on post 774; reduced naturally but still non-zero)
- **Fix:** `Content_Formatter::format_*()` strip `<strong>` and `<b>` tags from body content; convert to plain text
- **TDD test:** `tests/test-content-formatter-bold-strip.php`
- **Effort:** ~15 min (TDD red → fix → green → deploy → audit)

### v62.102 candidate — Async_Generator prose template (recipe content type)

- **Bugs (3):**
  - prepTime/totalTime/recipeYield not in body — Schema_Generator can't extract what AI didn't write
  - UK English spelling on Recipe[1] — AI sources from US recipe sites (allrecipes/foodnetwork)
- **Fix:** Update `Async_Generator::get_system_prompt()` recipe template to enforce:
  - "Prep Time: X minutes" / "Cook Time: Y minutes" / "Total Time: Z minutes" / "Servings: N" markers in EACH recipe section
  - For country=GB: cite UK recipe sites (BBC Good Food, Mary Berry, Jamie Oliver) preferentially; use UK English spelling
- **Doc sync:** `SEO-GEO-AI-GUIDELINES.md` §3.1A Recipe + `plugin_functionality_wordpress.md` §2
- **TDD test:** template-string assertions on the prose-template constant
- **Effort:** ~30 min

### v62.103+ candidate — recipe_audit U5 false positive cleanup (audit-only)

- (already partly fixed by U5 hits=['www.bbcgoodfood.com'] — but `cornishpastyassociation.co.uk` should also be in the UK authority list)

---

## Carry-overs from v62.100 still in-flight

- 1 inline bold (v62.101 above)
- prepTime / totalTime / recipeYield extraction misses (v62.102)
- UK English spelling on AI-sourced recipes (v62.102)
- nutrition.calories sometimes missing (intermittent — AI variance)

None of these BLOCK Google Rich Results display for Recipe rich card.

---

## How to resume tomorrow

Type one of these (copy-paste-ready):

**To approve today's work (Option A from chat):**
> sign off v62.100 (option A) — flip BUILD_LOG and move on to v62.101 inline bold strip

**To pick a different option:**
> hold v62.100 sign-off; ship v62.101 inline-bold strip first then sign off both together (Option B)

> tackle v62.102 AI prompt fixes too before any sign-off (Option C — inline bold + prepTime + totalTime + recipeYield + UK spelling)

**To resume the test flow with a fresh content type:**
> next T3 type: review (or listicle, comparison, how-to, etc.) — keyword [your-keyword] / country [XX] / wc [N]

**To check current status without acting:**
> read seobetter/seo-guidelines/WORKFLOW-STATUS.md and tell me where we are

---

## Persistence note

- Audit scripts are now persistent in repo: `seobetter/tests/full_audit.py`, `seobetter/tests/recipe_audit.py` (was previously in `/tmp`, lost on shutdown)
- Test cron picks these up via the `tests/test-*.php` glob (Python files run separately when invoked)
- WORKFLOW.md is locked (Finder ⌘I → Locked). Agent cannot modify. WORKFLOW-STATUS.md (this file) is unlocked and the agent updates it each loop.
- Next session, the auto-load-workflow.sh hook will inject WORKFLOW.md §1 + §7 into the agent's context on relevant prompts. Note: if you want WORKFLOW-STATUS.md to also auto-inject, edit `auto-load-workflow.sh` to extract from this file too. (Easy 1-line change once unlocked.)

---

## v62 ship train summary (today)

| Version | Title | VPS green | Sign-off |
|---|---|---|---|
| v62.96 | Bulk pipeline order: validate before linkify | ✅ | ✅ Verified post 767 |
| v62.97 | TDD RED for recipe ingredient extraction | ✅ (intentional fail) | superseded |
| v62.98 | GREEN: nutrition skip + Servings Per Recipe yield | ✅ (33/34) | superseded |
| v62.99 | GREEN patch: plural-form coverage (Total Sugars) | ✅ (34/34 / 7/7) | ✅ Verified post 774 (recipeIngredient fix) |
| v62.100 | h1 demote + cuisine body-fallback + pasty category | ✅ (8/8) | 🟡 awaiting (post 777) |

Previous ships before today: see BUILD_LOG.md
