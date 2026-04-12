# External Links & Citation Policy

> **Single source of truth** for how SEOBetter handles outbound URLs in generated articles.
> If you change link behavior, UPDATE THIS FILE in the same commit.
>
> **Last updated:** v1.5.8 — 2026-04-12

---

## The Rule (in one sentence)

**The only legitimate outbound link is one that points directly to the specific article, study, or page that is the source of a claim — on a trusted domain, with a real path (not a homepage), with non-API anchor text. Everything else is stripped.**

Plain-text citations ("According to a 2026 RSPCA report") are ALWAYS acceptable and count equally toward GEO visibility scoring.

---

## Why this policy exists

AI models hallucinate URLs constantly. Real examples from Test 1 (`how to choose a calming pet bed`):

- `https://dog-facts-api.herokuapp.com/api/v1/resources/dogs?number=1` — dead Heroku app
- `https://dog-api.kinduff.com/api/facts` — developer API endpoint, not a citable source
- `https://mindiampets.com.au/wp-admin/Dog%20Facts%20API` — AI put literal text in the URL slot of a markdown link (`[Dog Facts API](Dog Facts API)`), WordPress resolved the "URL" as a relative admin path
- `https://www.rspca.org.au/` — bare homepage used as a citation for a specific statistic (not a direct source)

Every one of these makes the article look unprofessional, hurts E-E-A-T, wastes the reader's click, and breaks AI citation crawlers. The fix is to be **radically strict** — when in doubt, no link.

---

## Research foundation: RLFKV (arxiv 2602.05723)

The verification approach in Layer 2 Pass 3 is adapted from:

> **Yin, T., Hu, H., Fan, Y., Chen, X., Wu, X., Deng, K., Zhang, K., Wang, F.** (2026). *Mitigating Hallucination in Financial Retrieval-Augmented Generation via Fine-Grained Knowledge Verification*. arXiv:2602.05723. Ant Group.

**The paper's core insight:** Coarse binary rewards (was the whole response right or wrong?) don't prevent hallucination in RAG systems. Instead, decompose every response into **atomic knowledge units** — minimal self-contained factual assertions — and verify each unit independently against the retrieved source documents. The paper uses a financial-domain quadruple structure `(entity, metric, value, timestamp)`, where missing any element invalidates the assertion:

> *"As of March 31, 2025, the company's earnings per share stood at 70.86 yuan"* → `(Kweichow Moutai, basic earnings per share, 70.86 yuan, As of March 31, 2025)`

Each unit is then verified against the retrieved documents. Units the source doesn't support are flagged as hallucinations and the model is penalized for generating them. This produces significantly higher faithfulness than binary rewards (Qwen3-8B: 90.2 → 93.3 on FDD-ANT, +3.1 points).

**How we adapt this for article citations:**

We don't have reinforcement learning or a fine-tuning loop. But we have the core structural idea: **treat each citation as an atomic unit, verify each one independently, reject failures**. For us:

| Paper concept | Our adaptation |
|---|---|
| Atomic knowledge unit | A single `[anchor text](url)` pair |
| Retrieved document | The destination page (fetched live via `wp_remote_get`) |
| Verification | Key terms from anchor text must appear in the destination's title or first 3000 chars |
| Reward signal | Binary: link kept if verified, stripped if not |
| Training | None — this runs at every article save as a filter |

**The verification rule:** Extract content words (4+ chars, non-stopword) from the anchor text. Fetch the destination page. Require at least 50% of those content words to appear in the destination's `<title>` or body prefix. Failure → link stripped, anchor text preserved as plain text.

**Example**:
- AI outputs: `[dog nutrition study](https://www.rspca.org.au/about-us)`
- Earlier layers all pass: rspca.org.au is whitelisted, path is non-empty, anchor text has no API keywords, URL returns 200
- Verification fetches `rspca.org.au/about-us` → finds no mention of "dog", "nutrition", or "study" in the page
- Link stripped → becomes plain text "dog nutrition study"

This catches the gap between "the URL is live and on a trusted domain" (what earlier layers check) and "the destination page actually says what we claim it says" (what the paper's atomic verification checks).

**Limitations of our adaptation:**

1. No semantic verification — we only check keyword overlap, not whether the page actually *supports* the specific claim. A page mentioning "dog nutrition study" in passing would still pass. The paper's approach uses an evaluation LLM (Qwen3-32B) to do true semantic verification; we can't afford that on every save.
2. English stopword list only — international articles using non-English anchor text may get false negatives. TODO: per-language stopword lists when we productionize i18n.
3. 24-hour transient cache — a URL verified today stays verified for 24 hours even if the destination changes. Acceptable trade-off for latency.
4. Latency cost — fetching 5 links per article adds ~5-10 seconds to save time. Mitigated by persistent cross-article caching.

**What this still doesn't solve:**

The paper's approach catches hallucinated facts by checking them against authoritative documents. Our adaptation catches misattributed citations. Neither stops the AI from *not* citing a claim at all — a completely fabricated statistic with no citation still slips through. That's why the prompt also requires plain-text attribution for every non-trivial claim.

---

## Enforcement layers (defense in depth)

There are **four layers** that each independently prevent hallucinated links from reaching published articles. Any single layer failing is not enough to produce a bad link.

### Layer 1 — System prompt instructions

**File:** `includes/Async_Generator.php` — `get_system_prompt()` method, "ABSOLUTE URL RULES" section

The AI is told, in zero-tolerance language:

1. One URL = one direct source (must be verbatim from research data AND the specific article/study/page)
2. DO NOT link to homepages
3. DO NOT link to APIs, endpoints, developer pages, raw data URLs (`/api/`, `/v1/`, `api.*`, `*-api.*`, `*.herokuapp.com`, `raw.githubusercontent.com`)
4. DO NOT use API/dataset/tool names as anchor text (`[Dog Facts API](...)`, `[Pexels API](...)`)
5. DO NOT invent URL paths
6. DO NOT use link text as the URL slot (malformed markdown)
7. Plain-text attributions are encouraged and count equally for GEO
8. When in doubt, OMIT the link
9. **"An article with zero external links is a PASS. An article with 5 hyperlinks to homepages or APIs is a FAIL."**
10. DO NOT output a `## References` / `## Sources` / `## Bibliography` / `## Further Reading` / `## Citations` section — these are processed automatically and stripped if hallucinated.

### Layer 2 — Plugin-side validator (post-generation)

**File:** `seobetter.php` — `validate_outbound_links()` method (line ~1260)

Runs after the AI generates the article, before it's saved or displayed. Four passes:

**Pass 0 — References section sanitizer** (`sanitize_references_section()`)
- Detects any `## References`, `## Sources`, `## Bibliography`, `## Further Reading`, `## Citations` heading
- Walks each line of the section
- For each line containing a markdown link, applies the full whitelist rules (anchor text, URL path, host, deep-link, domain)
- Drops any line that fails
- Drops list items with no link at all (a citation with no URL has no value)
- If zero references survive → removes the heading entirely

**Pass 1 — Malformed markdown**
- Regex `/\[([^\]]+)\]\(((?!https?:\/\/)[^)]*)\)/`
- Catches cases where AI puts literal text in the URL slot (e.g. `[Dog Facts API](Dog Facts API)`)
- Replaces with just the link text

**Pass 2 — Inline markdown links `[text](url)`**
- Runs `filter_link()` on each match

**Pass 3 — HTML anchor tags `<a href="...">text</a>`**
- Runs the same `filter_link()` on each match

**`filter_link()` rules (all must pass):**

| Check | Rule |
|---|---|
| Malformed URL | Host must parse. Unparseable → strip. |
| Internal link | Same host as site → keep unchanged. |
| Anchor text | Must NOT contain `api`, `endpoint`, `dataset`, `sdk`, `webhook`. |
| URL path | Must NOT contain `/api/`, `/v[1-9]/`, `/graphql`, `/rest/`, `/swagger`, `raw.githubusercontent.com`. |
| URL host | Must NOT match `(^\|\.)api\.`, `-api\.`, `\.herokuapp\.com$`. |
| Domain whitelist | Host must match a trusted pattern (see below). |
| Deep link | Path must not be empty, `/`, `index.html`, or `index.php`. Bare homepages are stripped. |

**When a link fails:** the anchor text is preserved as plain text, the link wrapper is removed.

**Pass 3 — Fine-grained knowledge verification** (`verify_citation_atoms()`)

Runs after all earlier passes, on links that survived the whitelist. Implements the atomic knowledge unit verification adapted from RLFKV (see Research Foundation above).

For each `[anchor text](url)` that's still in the markdown:

1. Tokenize the anchor text → extract content words (4+ chars, not in stopword list)
2. If zero content words → strip (anchors like "here", "this article", "learn more" are low-quality and unverifiable)
3. Check the 24-hour transient cache keyed by `md5(url + anchor_text)` — if cached result exists, use it
4. `wp_remote_get($url, timeout=5)` → must return 200-399
5. Extract destination `<title>` + first 3000 chars of body (tags stripped, whitespace collapsed)
6. Count how many anchor content words appear in the extracted haystack (case-insensitive `strpos`)
7. Require `found / total >= 0.5` (50% content-word overlap)
8. Cache the verdict in a transient (`ok` for 24h, `fail` for 24h if network ok, `fail` for 1h if network error)
9. Verified → keep the link. Failed → strip the link, keep the anchor text as plain text.

After Pass 3 runs, the sanitizer re-runs on any References section — if Pass 3 stripped all the links, the heading gets removed too.

**Performance:** 5 citations in a typical article = up to 5 `wp_remote_get()` calls = up to 25 seconds worst-case latency. Mitigated by:
- Persistent transient cache (24h) — repeat saves of the same article or across articles with shared citations are ~instant
- Session cache (per-save) — the same URL cited multiple times in one article only fetches once
- Short timeout (5s) — failed fetches don't block the save indefinitely

**Why this matters even though earlier layers exist:** The whitelist confirms the URL host is legitimate. The deep-path check confirms it's not a bare homepage. The API-pattern check confirms it's not a data endpoint. But none of those verify that the *specific page* at `https://www.rspca.org.au/knowledgebase/article-42` is actually about dog beds — the AI could cite it for any claim. Pass 3 closes that gap.

### Layer 3 — Content_Injector citations (Fix Now button)

**File:** `includes/Content_Injector.php` — `inject_citations()` method

Used by the "Add Citations" Fix Now button. Calls `Trend_Researcher::research()` to get source URLs, then:

1. Rejects sources with missing/generic titles (`Source`, `Article`, `Untitled`, <8 chars)
2. Rejects anchor text containing API/endpoint/dataset/SDK/webhook
3. Rejects URLs containing `/api/`, `/v[1-9]/`, `/graphql`, `/rest/`, `*.herokuapp.com`, `api.*`, `*-api.*`
4. Rejects bare homepages (no path)
5. **Live HEAD-checks** every surviving URL (4 second timeout, 3 redirects) — must return 200-399
6. If zero sources pass → fails with clear error: *"No direct article sources found for this keyword. Citations only link to real article pages, never to homepages or APIs — try a more specific keyword, or skip this fix."*

The inject-fix REST path ALSO runs `validate_outbound_links()` after injection, so even if this layer adds a link, Layer 2 rules still apply as a safety net.

### Layer 4 — External audit (Claude skill)

**Skill:** `~/.claude/skills/check-citations/`
**Installed from:** `https://github.com/PHY041/claude-skill-citation-checker.git`
**Dependencies:** `requests` (Python)

This is an **auditing tool**, not a runtime validator — used from Claude Code sessions to double-check generated articles against remote source state. Invoke by asking Claude to "run the check-citations skill on this article" and paste the markdown.

What it does (high level): extracts every URL from provided content, HEAD-requests each one, reports dead/redirected/suspicious links. Use this on any sample article before committing changes to the prompt or validator, to confirm the enforcement layers are actually working.

---

## Trusted domain whitelist

Defined in `seobetter.php` — `get_trusted_domain_whitelist()` method.

Extensible via the `seobetter_trusted_domains` filter for site owners who want to add their own authoritative sources.

### Categories

**Government / academic (wildcards)**
- `*.gov`, `*.edu`, `*.mil`
- `*.gov.au`, `*.gov.uk`, `*.gc.ca`, `*.gov.nz`
- `*.edu.au`, `*.ac.uk`, `*.ac.nz`

**Major news & reference**
- `wikipedia.org`
- `reuters.com`, `apnews.com`, `bbc.com`, `bbc.co.uk`
- `theguardian.com`, `nytimes.com`, `washingtonpost.com`, `ft.com`
- `bloomberg.com`, `cnbc.com`, `wsj.com`, `economist.com`

**Health & science**
- `who.int`, `cdc.gov`, `nih.gov`
- `nature.com`, `sciencedirect.com`, `pubmed.ncbi.nlm.nih.gov`
- `mayoclinic.org`, `clevelandclinic.org`, `webmd.com`, `healthline.com`
- `harvard.edu`, `ox.ac.uk`

**Pet / animal authority** (relevant for mindiampets.com.au test site)
- `rspca.org.au`, `rspca.org.uk`, `aspca.org`
- `akc.org`, `ukcdogs.com`, `avma.org`, `ava.com.au`
- `pedigree.com`, `royalcanin.com`
- `petmd.com`, `vcahospitals.com`
- `bluecross.org.uk`, `dogstrust.org.uk`

**Tech authority**
- `developer.mozilla.org`, `w3.org`, `schema.org`
- `google.com`, `support.google.com`, `developers.google.com`, `search.google.com`
- `github.com`, `stackoverflow.com`, `microsoft.com`, `apple.com`

**Research & data**
- `statista.com`, `pewresearch.org`, `ourworldindata.org`
- `researchgate.net`, `arxiv.org`, `ssrn.com`

### How matching works

`is_host_trusted()` supports:

1. **Exact match** — `rspca.org.au` matches `rspca.org.au` only
2. **Suffix match** — `rspca.org.au` also matches `www.rspca.org.au` and `subdomain.rspca.org.au`
3. **Wildcard** — `*.gov` matches any host ending in `.gov` (e.g. `cdc.gov`, `nih.gov`)

---

## Research APIs (where real URLs come from)

The plugin pulls real data from several free APIs, but the URLs in the research responses are **NOT** automatically trusted — they still have to pass the whitelist. This is deliberate: a Reddit URL or a HackerNews URL is a real page, but not the primary source of a factual claim, so we don't auto-cite them.

### `/api/topic-research` (Vercel)

**File:** `cloud-api/api/topic-research.js`
**Purpose:** Suggest 10 Topics button — finds what to write about
**Sources:** Google Suggest, Datamuse, Wikipedia OpenSearch, Reddit search
**Does it produce article citations?** No — topic ideas only. URLs are for the user to browse, not for the AI to cite.

### `/api/research` (Vercel)

**File:** `cloud-api/api/research.js`
**Purpose:** Provide real stats, quotes, Reddit posts, HN discussions as prompt context
**Sources:** Reddit, Hacker News, Wikipedia, Google Trends, Brave Search, several free single-topic APIs (Dog Facts, Cat Facts, Numbers API, Zoo Animals, etc.)
**Does it produce article citations?** Indirectly — the AI is given this research data as context, but the "ABSOLUTE URL RULES" in the prompt forbid the AI from linking to API endpoints. Even when data comes from `dogapi.dog/api/v2/facts`, the AI is not allowed to cite that URL. Plain-text attribution only ("Dog breed data from [organization name]").

**Important:** URLs in research responses are NEVER passed through to the article automatically. The AI reads the data, synthesizes a claim, and writes a plain-text attribution — or the validator strips the link.

### Layer 3 citation injector (Content_Injector)

When the user clicks "Add Citations" Fix Now button, `Content_Injector::inject_citations()` pulls sources from `Trend_Researcher::research()` and tries to build a `## References` section. But because of the strict rules above, in practice very few sources survive — most research data returns API endpoints, Reddit posts, or homepage URLs. This is **by design**: the button will fail loudly rather than inject dead citations.

If users frequently complain the button fails, the right fix is to improve research data quality (deeper article URLs from real publications), not to relax the citation rules.

---

## Known failure modes

### FM-1: AI outputs homepage link

**Symptom:** Article contains `[RSPCA](https://www.rspca.org.au/)`
**Detection:** Layer 2 Pass 2 catches it — path is empty → stripped
**Result:** Becomes plain text `RSPCA`

### FM-2: AI outputs API endpoint

**Symptom:** Article contains `[Dog breeds database](https://dog-api.kinduff.com/api/facts)`
**Detection:** Layer 2 Pass 2 catches it — URL matches `-api\.` pattern and `/api/` path pattern
**Result:** Becomes plain text `Dog breeds database`

### FM-3: AI outputs malformed link with literal text in URL slot

**Symptom:** Article contains `[Dog Facts API](Dog Facts API)` which WordPress resolves against current URL as `/wp-admin/Dog%20Facts%20API`
**Detection:** Layer 2 Pass 1 catches it — URL doesn't start with `http://` or `https://`
**Result:** Becomes plain text `Dog Facts API`

### FM-4: References section with all-bad URLs

**Symptom:** Article ends with `## References` block listing 5 fake sources
**Detection:** Layer 2 Pass 0 catches it — every line fails the whitelist → heading also removed
**Result:** Section removed entirely. Article ends at the previous H2.

### FM-5: References section with 1-2 good URLs among bad ones

**Symptom:** `## References` has 1 real Wikipedia link and 4 hallucinated ones
**Detection:** Layer 2 Pass 0 keeps the good line, drops the bad ones, keeps the heading
**Result:** References section remains but only contains verified entries

### FM-6: Research data URL is trusted but deep-path

**Symptom:** AI cites `https://www.rspca.org.au/knowledgebase/calming-dog-beds-guide`
**Detection:** All earlier checks pass — rspca.org.au is whitelisted, path is non-empty, not an API, anchor text is clean. Pass 3 then fetches the page and confirms "calming", "dog", "beds" appear in the title/body.
**Result:** Link kept as-is. **This is the desired outcome.**

### FM-7: Misattributed citation (URL is real, content doesn't match)

**Symptom:** AI outputs `[dog nutrition study](https://www.rspca.org.au/about-us)` — URL is live, on trusted domain, deep path, clean anchor text
**Detection:** Pass 3 fetches the page, extracts content words `dog`, `nutrition`, `study`, finds 0/3 in the "About Us" page body
**Result:** Link stripped (0% < 50% threshold). Becomes plain text `dog nutrition study`. **This is the scenario RLFKV Pass 3 is designed to catch.**

### FM-8: Vague anchor text ("here", "this article", "learn more")

**Symptom:** AI outputs `[here](https://rspca.org.au/knowledgebase/dog-beds)` or `[learn more](...)`
**Detection:** Pass 3 tokenizes "here" → 1 word, <4 chars → zero content words after filtering
**Result:** Link stripped unconditionally. A link with no verifiable anchor text provides no citation value and harms readability.

---

## Testing the policy

### Manual test (5 minutes)

1. Generate an article with a topic that tempts AI hallucination (e.g. "how to choose a dog bed")
2. Save Draft
3. Open the post in WordPress editor
4. Scroll through the content looking for `[text](url)` patterns
5. For each link:
   - Is the URL on the trusted whitelist? ✓
   - Is it a deep path (not `/` alone)? ✓
   - Is the anchor text NOT an API name? ✓
6. Check the bottom of the article for a References section. If present, every entry must be a real deep-path link.

### Automated audit (Claude skill)

```
# From a Claude Code session:
Use the check-citations skill on the markdown of this article: <paste>
```

Reports: live/dead/redirected/suspicious counts per URL.

### Adding to the whitelist

If a legitimate authoritative source is being stripped:

1. Verify it's actually authoritative (not a random blog)
2. Verify it has deep article pages, not just a homepage
3. Add to the `$default` array in `get_trusted_domain_whitelist()` in `seobetter.php`
4. Or, for site-specific additions, use the filter:

```php
add_filter( 'seobetter_trusted_domains', function ( $domains ) {
    $domains[] = 'my-authoritative-source.example.com';
    return $domains;
} );
```

---

## Version history

| Version | Date | Change |
|---|---|---|
| 1.5.8 | 2026-04-12 | `verify_citation_atoms()` added — fine-grained knowledge verification adapted from RLFKV (arxiv 2602.05723). Fetches every surviving link's destination, verifies anchor text's key terms appear in the page content, rejects mismatches. Session + 24h transient caching. Also strips vague anchor text ("here", "learn more"). |
| 1.5.7 | 2026-04-12 | `sanitize_references_section()` added. Prompt told AI not to generate References at all. |
| 1.5.6 | 2026-04-12 | Anchor-text API check, deep-link requirement (no homepages), explicit API path/host patterns. |
| 1.5.5 | 2026-04-12 | First version of strict whitelist validator. Malformed-link pass. `check-citations` skill installed. |
| 1.4.x | — | Original HEAD-request validator. Had fatal flaw: fell back dead domains to "homepage" links that were also dead. Replaced in 1.5.5. |

---

## When to update this file

Update this document in the same commit whenever you change:

- The system prompt URL rules in `Async_Generator.php`
- `validate_outbound_links()` or any of its helpers (`is_host_trusted`, `get_trusted_domain_whitelist`, `sanitize_references_section`)
- `Content_Injector::inject_citations()` citation logic
- Research API data sources that could surface new URL patterns
- The trusted domain whitelist
- Which Claude skills the workflow depends on

Treat this file as the contract. If the code and this file disagree, the code wins — but that means this file is out of date and must be corrected immediately.
