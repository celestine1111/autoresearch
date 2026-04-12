# External Links & Citation Policy

> **Single source of truth** for how SEOBetter handles outbound URLs in generated articles.
> If you change link behavior, UPDATE THIS FILE in the same commit.
>
> **Last updated:** v1.5.9 — 2026-04-12

---

## The Rule (in one sentence)

**The only legitimate outbound link is one that points to a real, keyword-relevant article page on the web, where "real" is proved by fetching the page and verifying the destination content actually discusses the keyword, and where "keyword-relevant" is proved by the URL coming from a pre-generation web search for that specific keyword — not invented by the AI.**

This means the allowed set of links is **discovered per-article at generation time**, not fixed in a static whitelist. Any domain is citable if the research pipeline surfaced a real article about the keyword on that domain, and the destination page verifies.

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

## Research foundation

The architecture is grounded in three recent papers that each address hallucination in LLM-generated content from a different angle. Together they define the "retrieve → verify → filter" loop this plugin implements.

### Paper 1 — RLFKV: atomic knowledge units

> **Yin, T., Hu, H., Fan, Y., Chen, X., Wu, X., Deng, K., Zhang, K., Wang, F.** (2026). *Mitigating Hallucination in Financial Retrieval-Augmented Generation via Fine-Grained Knowledge Verification*. arXiv:2602.05723. Ant Group.

**Core insight:** Decompose every model output into minimal factual assertions — atomic knowledge units — and verify each one independently against the retrieved source documents. Units the source doesn't support are hallucinations.

Their quadruple structure `(entity, metric, value, timestamp)` captures financial claims. For citations, we use a simpler analog: `(anchor text, destination URL)`. The anchor text is the "claim"; the destination is the "source document". The verification step is the same — does the source actually contain content supporting the claim?

**Result in paper:** 90.2 → 93.3 faithfulness on FDD-ANT (Qwen3-8B), +3.1 points, stable optimization.

**How we use it:** Pass 3 of the validator. See Layer 2.

### Paper 2 — Comprehensive review of AI hallucinations

> **Joshi, S.** (2025). *Comprehensive Review of AI Hallucinations: Impacts and Mitigation Strategies for Financial and Business Applications*. International Journal of Computer Applications Technology and Research, 14(06), 38-50. DOI:10.7753/IJCATR1406.1003.

A systematic review of 63+ sources on hallucination mitigation. Key empirical findings from the paper's Table 7 (Empirical Hallucination Rates by Approach):

| Mitigation strategy | Error reduction |
|---|---|
| RAG implementation | 58% |
| Multi-model consensus | 63% |
| Guardian agents | 72% |
| Temporal anchoring | 41% |

**Core insight for us:** The paper's single most important conclusion is that **Retrieval-Augmented Generation with real-time data grounding is the #1 proven mitigation** (42-58% reduction). Citations shouldn't be invented by the model — they should be retrieved from verified sources and then passed to the model as bounded context.

The paper also documents "Factual Hallucinations (fake citations)" as the #1 hallucination category, and notes that legal AI systems hallucinate citations in 16.7% of queries. This is precisely the problem we're solving.

**Quote:** *"RAG systems combine LLMs with external knowledge retrieval, significantly reducing hallucinations by grounding responses in verified sources."* (Section 2.7.1)

**How we use it:** The research-pool architecture (below). Instead of letting the AI invent URLs, we pre-fetch real keyword-relevant URLs from the research pipeline and inject them as a bounded "AVAILABLE CITATIONS" list in the prompt. The AI can only cite URLs that were actually retrieved.

### Paper 3 — Agentic multi-layer hallucination mitigation

> **Gosmar, D., Dahl, D. A.** (2025). *Hallucination Mitigation using Agentic AI Natural Language-Based Frameworks*. arXiv:2501.13946. XCALLY / OVON Initiative.

**Core insight:** A multi-agent pipeline where each agent reviews and refines the previous agent's output can reduce hallucination metrics by 2,800% across 310 test prompts. The paper proposes four measurable metrics:

| Metric | What it measures | Our mapping |
|---|---|---|
| **FCD** (Factual Claim Density) | Claims per 100 words | How many citations the article contains |
| **FGR** (Factual Grounding References) | Links to real-world evidence | How many citations point to a verified pool URL |
| **FDF** (Fictional Disclaimer Frequency) | Explicit uncertainty markers | Plain-text "according to..." attributions |
| **ECS** (Explicit Contextualization Score) | Disclaimers per 100 words | "As of 2026..." temporal anchors |

Their hallucination score: `THS = [FCD − (FGR + FDF + ECS)] / NA`. Lower is better. An article with many claims but few grounded references scores high (bad). An article with many claims AND matching grounded references OR temporal/source disclaimers scores low (good).

**How we use it:** Our validator enforces that every outputted citation must resolve to a pool URL (maximizes FGR), while the prompt encourages plain-text attributions (maximizes FDF) for any claim that can't be grounded. The Pass 3 verifier is the "second-level reviewer" agent in their terminology.

---

## Synthesis: Research Pool grounding

Combining the three papers produces this architecture for our specific problem (citation hallucination in WordPress article generation):

**Step 1 — Retrieve:** Before generation, run a real keyword-targeted web search (not a topic-adjacent API query) and collect 10-30 candidate article URLs that actually discuss the keyword. These become the **Citation Pool** for this article.

**Step 2 — Filter:** Apply static hygiene rules to the pool (no APIs, no homepages, no malformed URLs, no API-name anchor text). Drop everything that fails.

**Step 3 — Verify:** For each surviving pool URL, fetch the destination page and confirm keyword content overlap (Pass 3 atomic verification from RLFKV). URLs that fail content verification are dropped from the pool.

**Step 4 — Ground the AI:** Inject the verified pool into the system prompt as `AVAILABLE CITATIONS` — the AI may ONLY output link URLs from this list. Any other URL it tries to output will be stripped.

**Step 5 — Post-generate validation:** Final pass through the validator. Links matching a pool URL → kept. Links on the static whitelist (fallback) → content-verified and kept if passing. Everything else → stripped, anchor text preserved.

**Step 6 — Build References automatically:** The AI does not output a References section. The plugin programmatically appends a References section at save time, composed of the pool URLs the article body actually cited. This guarantees the references match the body and can't contain hallucinations.

**Why this is better than a static whitelist:**

| Old (static whitelist) | New (research pool) |
|---|---|
| Fixed ~40 domains | Any domain, if research found a real article there |
| Rejects `petbarn.com.au` even if article exists | Accepts `petbarn.com.au/advice/calming-dog-bed` if it's in the pool |
| Can't cover 100+ research APIs | Automatically adapts per keyword |
| AI invents → plugin strips | Plugin retrieves → AI grounds in bounded pool |
| FGR is unbounded (plugin guesses) | FGR is explicit (every cite is in the pool) |

**Why this is better than letting the AI cite freely:**

| AI free-for-all | Research pool |
|---|---|
| 15-20% hallucination rate (Joshi 2025) | Pool membership + content verification → near zero |
| No way to prove a citation is real | Every citation traced back to a retrieved document |
| References section can be fully fabricated | References section built programmatically from pool |

**Trade-off:** Articles may have fewer citations than an AI left to its own devices would generate — but every remaining citation is real. Per the Joshi review, *"an article with zero fabricated citations is a pass. An article with 5 hallucinated citations is a FAIL."*

---

## Implementation notes for Pass 3 (RLFKV adaptation)

**Limitations of our adaptation:**

1. **No semantic verification** — we only check keyword overlap, not whether the page actually *supports* the specific claim. A page mentioning "dog nutrition study" in passing would still pass. The RLFKV paper uses an evaluation LLM (Qwen3-32B) to do true semantic verification; we can't afford that on every save.
2. **English stopword list only** — international articles using non-English anchor text may get false negatives. TODO: per-language stopword lists when we productionize i18n.
3. **24-hour transient cache** — a URL verified today stays verified for 24 hours even if the destination changes. Acceptable trade-off for latency.
4. **Latency cost** — fetching 5-10 links per article adds ~5-25 seconds worst case to save time. Mitigated by persistent cross-article caching.

**What this still doesn't solve:**

The RLFKV approach catches hallucinated facts by checking them against authoritative documents. Our adaptation catches misattributed citations. Neither stops the AI from *not* citing a claim at all — a completely fabricated statistic with no citation still slips through. That's why the prompt also requires plain-text attribution for every non-trivial claim (Gosmar & Dahl's FDF metric).

---

## Enforcement layers (defense in depth)

There are **five layers** that each independently prevent hallucinated links from reaching published articles. Any single layer failing is not enough to produce a bad link.

### Layer 0 — Citation Pool retrieval (NEW in v1.5.9)

**Files:** `includes/Citation_Pool.php` + `cloud-api/api/research.js` (citation_candidates field)

Before generation starts, the plugin builds a per-article **Citation Pool** of real keyword-relevant article URLs. This is the "retrieve" step of RAG — the pool is what the AI is grounded in.

**Pool sources (in order of authority):**

1. **DuckDuckGo web search** — scraped HTML results for `{keyword}`. Returns up to 8 URLs per query. Most direct keyword-relevance signal available without an API key.
2. **Brave Search** (Pro users) — higher quality web search results via API. Returns up to 10 URLs per query.
3. **Wikipedia OpenSearch** — 1-2 direct Wikipedia article URLs for the keyword. Always high authority.
4. **Reddit/HN search results** — only when the post actually links out to an external article URL (not self-posts). The linked URL, not the Reddit thread, becomes the candidate.

**Pool filters (applied to every candidate before admission):**

- Must be `http(s)://` with parseable host
- Must have a deep path (not `/`, not `index.html`)
- Host must NOT match API patterns (`api.*`, `*-api.*`, `*.herokuapp.com`)
- Path must NOT match API patterns (`/api/`, `/v[1-9]/`, `/graphql`, `/rest/`, `/swagger`)
- HEAD request must return 200-399 (live check, 4s timeout)
- Content-verify: fetch page, extract title + first 3000 chars, confirm at least one keyword content word appears (Pass 3 rule, lower threshold at this stage because we're building the pool)

Pool entries that pass all filters are stored as: `{ url, title, source_name, verified: true, verified_at }`.

**Pool is cached** per keyword for 6 hours via transient (`seobetter_pool_{md5(keyword+country)}`). Running the generator twice on the same keyword within 6 hours reuses the pool.

**Pool is stored with the job** during async generation (step 0, before outline), so all subsequent steps — section generation, meta, headlines — have access to it.

**Pool is threaded into save** via a new `_seobetter_citation_pool` post meta, so `validate_outbound_links()` can accept the pool as an allowed-URL list.

---

### Layer 1 — System prompt instructions

**File:** `includes/Async_Generator.php` — `get_system_prompt()` method, "ABSOLUTE URL RULES" section

The prompt now injects the **Citation Pool** built by Layer 0 as an `AVAILABLE CITATIONS` block directly into the system prompt. The AI is told:

1. **Use ONLY the URLs in the AVAILABLE CITATIONS list.** Any hyperlink you output must be one of these URLs, character-for-character. URLs not in the list will be stripped.
2. **Match the citation to the claim.** A citation URL should point to a page that directly supports the nearby claim. Don't cite a page about dog beds to support a claim about dog food.
3. DO NOT invent URL paths — if you're tempted to output a URL, check: is it in the pool? If not, use plain text.
4. DO NOT use API/dataset/tool names as anchor text (`[Dog Facts API](...)`, `[Pexels API](...)`).
5. DO NOT link to homepages, APIs, endpoints, or raw data URLs.
6. DO NOT output a `## References`, `## Sources`, `## Bibliography`, `## Further Reading`, or `## Citations` section. **The plugin will build the References section programmatically** from the citations you actually used in the body. Don't pre-empt it.
7. Plain-text attributions are encouraged for any claim that isn't in the citation pool. "According to a 2026 RSPCA report" counts equally for GEO visibility — no link required.
8. **"An article that uses 3 real pool citations and 5 plain-text attributions is a PASS. An article with 5 hyperlinks to homepages, APIs, or hallucinated URLs is a FAIL."**

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
| **Pool membership OR whitelist** | URL must be in **this article's Citation Pool** OR on the static domain whitelist. Pool membership is the primary gate; whitelist is the fallback. |
| Deep link | Path must not be empty, `/`, `index.html`, or `index.php`. Bare homepages are stripped. |

**Pool membership check** (added in v1.5.9):

If the URL appears verbatim in the `_seobetter_citation_pool` post meta OR matches a pool URL after URL normalization (scheme+host+path, query string agnostic), the link passes the whitelist requirement regardless of whether the domain is statically whitelisted. This is how the system supports citations from any publisher domain — as long as the research pipeline retrieved the exact URL from a keyword search, the link is considered legitimate.

If the URL is NOT in the pool, the legacy static whitelist applies as a fallback. Links to `*.gov`, `*.edu`, `wikipedia.org`, `rspca.org.au`, etc. are always allowed even when the pool is empty — this prevents total citation failure on obscure keywords where the research pipeline returns nothing.

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

### Layer 3b — Automatic References section

**File:** `seobetter.php` — `build_references_section()` method, called by `rest_save_draft()` after Layer 2 validation.

Once the validator has finished stripping bad links, the plugin walks the cleaned markdown, collects every markdown link that survived, and appends a programmatic References section:

```markdown
## References

1. [Article title from pool metadata](https://exact-pool-url.example.com/article-slug) — publisher.com
2. [Another article title](https://another-publisher.com/page) — another-publisher.com
```

**Rules for the auto-References section:**

1. Only URLs that appear as markdown links in the article body are included (not every pool URL — only the ones the article actually cited)
2. Titles come from the pool metadata (the title scraped at pool-build time, not from the AI)
3. Entries are numbered and deduplicated by URL
4. If the article body contains zero valid citations, no References section is appended (rather than an empty heading)
5. Appended as a proper `<!-- wp:heading -->` block so it's editable in Gutenberg

This guarantees the References section can NEVER contain hallucinations — it's mechanically generated from verified pool entries, not written by the AI. The AI is explicitly told not to write References (Layer 1 rule #6).

---

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

### FM-9: AI cites a URL not in the pool

**Symptom:** Research pool has 7 real keyword-relevant URLs, but the AI outputs an 8th URL it invented
**Detection:** Layer 2 checks pool membership → not in pool → falls through to static whitelist → either passes static whitelist + Pass 3 OR gets stripped
**Result:** If the invented URL happens to be a real article on `rspca.org.au` and passes content verification, it's kept (rare but possible). Otherwise stripped. The pool is the primary gate — whitelist is the fallback for exactly these edge cases.

### FM-10: Empty research pool (obscure keyword)

**Symptom:** Keyword is too niche — DDG and Brave return zero keyword-relevant results. Pool is empty after verification.
**Detection:** Layer 0 builds pool → 0 entries → `_seobetter_citation_pool` meta stores empty array
**Result:** The AI is told there are no available citations and should use plain-text attributions only. Layer 2 falls back to the static whitelist — so the AI can still cite `wikipedia.org`, `*.gov`, `rspca.org.au` etc. if it finds something there, subject to Pass 3. But typically the article ends up with few or zero external links and many plain-text attributions. **This is the correct behavior** — we prefer zero links over hallucinated ones.

### FM-11: Pool URL is real but irrelevant

**Symptom:** DDG search for "calming pet bed" returns a top result like `joonapp.io` (a kids game company) because the keyword was loosely matched
**Detection:** Pool-build Pass 3 content verification — fetches `joonapp.io`, finds zero keyword content words, rejects the URL
**Result:** URL is not added to the pool. AI never sees it. Cannot be cited. **This is what v1.5.9 fixes.**

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
| 1.5.9 | 2026-04-12 | **Research Pool architecture.** New `Citation_Pool` class pre-fetches keyword-relevant URLs via DDG/Brave/Wikipedia at generation time, filters + content-verifies them, stores the pool in a post meta, injects it into the AI prompt as `AVAILABLE CITATIONS`, and uses it as the primary allow-list in the validator (whitelist becomes fallback). References section is now built programmatically at save time from pool URLs the article body cited — never written by the AI. Based on Joshi 2025 (RAG = 58% hallucination reduction) and Gosmar & Dahl 2501.13946 (multi-agent FGR verification). |
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
