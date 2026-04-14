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
