# External Links & Citation Policy

> **Single source of truth** for how SEOBetter handles outbound URLs in generated articles.
> If you change link behavior, UPDATE THIS FILE in the same commit.
>
> **Last updated:** v1.5.206b — 2026-04-23 (regional international citation domains SHIPPED in `get_trusted_domain_whitelist()`)

---

## 1. The rule in one sentence

**The only hyperlinks that reach a published article are URLs that were retrieved from a real keyword-targeted web search before the AI started writing, verified to contain keyword-relevant content, and cited by the AI with anchor text that matches the destination page.**

Plain-text attributions ("According to a 2026 RSPCA report") are always acceptable and count equally toward GEO visibility. The safe default for any claim is a plain-text attribution, not a hyperlink.

---

## 2. Why this policy exists

AI models hallucinate URLs constantly. Real examples produced by earlier versions of this plugin:

- `https://dog-facts-api.herokuapp.com/api/v1/resources/dogs?number=1` — dead Heroku app, API endpoint
- `https://dog-api.kinduff.com/api/facts` — developer API, never a citable source
- `https://mindiampets.com.au/wp-admin/Dog%20Facts%20API` — AI put literal text in the URL slot of a markdown link, WordPress resolved the "URL" as a relative admin path
- `https://www.rspca.org.au/` — bare homepage cited as the source for a specific statistic
- `[Dog Facts API](...)` — API/dataset name used as anchor text

Every one of these makes the article look unprofessional, hurts E-E-A-T, wastes the reader's click, and breaks AI citation crawlers. A hallucinated link is worse than no link at all. The policy is deliberately strict: **when in doubt, no link**.

---

## 3. Research foundation

The architecture is grounded in three recent papers. Together they define the "retrieve → ground → verify → filter" loop this plugin implements.

### Paper 1 — Joshi (2025): RAG is the #1 mitigation lever

> Joshi, S. (2025). *Comprehensive Review of AI Hallucinations: Impacts and Mitigation Strategies for Financial and Business Applications*. International Journal of Computer Applications Technology and Research, 14(06), 38–50. DOI:10.7753/IJCATR1406.1003.

Systematic review of 63+ sources. Key empirical finding (Table 7, Empirical Hallucination Rates by Approach):

| Mitigation strategy | Error reduction |
|---|---|
| RAG implementation | 58% |
| Multi-model consensus | 63% |
| Guardian agents | 72% |
| Temporal anchoring | 41% |

**Quote:** *"RAG systems combine LLMs with external knowledge retrieval, significantly reducing hallucinations by grounding responses in verified sources."* (Section 2.7.1)

**Application here:** Citations shouldn't be invented by the model and filtered afterwards. They should be **retrieved first** from real sources and injected into the model's context as a bounded pool of allowed URLs. The model can only cite URLs that were actually retrieved. This is the core of the Research Pool architecture.

### Paper 2 — Gosmar & Dahl (2025): measurable FGR metric

> Gosmar, D., Dahl, D. A. (2025). *Hallucination Mitigation using Agentic AI Natural Language-Based Frameworks*. arXiv:2501.13946.

Multi-agent pipeline achieving 2,800% hallucination-score improvement. Defines four measurable quality metrics:

| Metric | Meaning | How we use it |
|---|---|---|
| **FCD** Factual Claim Density | Factual claims per 100 words | How many citations the article contains |
| **FGR** Factual Grounding References | Links to real-world evidence | Count of pool-matched citations |
| **FDF** Fictional Disclaimer Frequency | Explicit uncertainty markers | Plain-text attributions ("according to…") |
| **ECS** Explicit Contextualization Score | Temporal/source disclaimers | "As of 2026…", "A recent report from…" |

Their Total Hallucination Score: `THS = [FCD − (FGR + FDF + ECS)] / NA`. Lower is better. An article with many claims but few grounded references scores badly. An article with claims matched to grounded references OR plain-text disclaimers scores well.

**Application here:** The validator maximizes FGR (every citation must resolve to a pool URL) while the prompt maximizes FDF (encourages plain-text attributions for any claim that isn't in the pool). The AI is told explicitly: *"3-6 pool citations plus plain-text attributions is a PASS. 5 hyperlinks to homepages or APIs is a FAIL."*

### Paper 3 — Yin et al. (2026): atomic knowledge unit verification

> Yin, T., Hu, H., Fan, Y., Chen, X., Wu, X., Deng, K., Zhang, K., Wang, F. (2026). *Mitigating Hallucination in Financial Retrieval-Augmented Generation via Fine-Grained Knowledge Verification (RLFKV)*. arXiv:2602.05723. Ant Group.

Decompose every LLM output into atomic knowledge units — minimal self-contained factual assertions — and verify each one independently against the retrieved source documents. Their quadruple structure `(entity, metric, value, timestamp)` captures financial claims; any missing element invalidates the assertion.

**Application here:** Each citation is treated as an atomic unit of the form `(anchor text, destination URL)`. The anchor text is the claim; the destination is the source document. For each surviving citation, the plugin fetches the destination and verifies that the anchor text's content words actually appear in the page content. Mismatches are stripped. This catches the failure mode where a URL is real and on-topic at the domain level but the specific page doesn't support the specific claim.

---

## 4. Architecture: Research Pool grounding

The system is a **retrieve → ground → verify → filter → build** pipeline. Each stage runs in a specific phase of the article lifecycle.

```
┌─────────────────────────────────────────────────────────────────┐
│  PHASE 1 — BEFORE AI GENERATION (done once per article)         │
│                                                                 │
│  Keyword → /api/research (DDG + Brave + Wikipedia)              │
│          → Citation_Pool::build()                               │
│              ↓                                                  │
│          [hygiene filter] [live check] [content verify]         │
│              ↓                                                  │
│          Citation Pool: up to 12 verified keyword-relevant URLs │
│              ↓                                                  │
│  Inject into every section prompt as AVAILABLE CITATIONS        │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  PHASE 2 — DURING AI GENERATION (every section sees the pool)   │
│                                                                 │
│  AI writes sections. Closed-menu rule: any hyperlink must be a  │
│  verbatim URL from the pool. AI is told NOT to write References │
│  (plugin builds it later).                                      │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  PHASE 3 — AT SAVE TIME (validate_outbound_links + References)  │
│                                                                 │
│  Pass 0: sanitize any References section the AI wrote anyway    │
│  Pass 1: strip malformed [text](not-a-url) markdown             │
│  Pass 2: filter inline links — pool membership → whitelist      │
│          fallback → hard-fail rules                             │
│  Pass 3: RLFKV fine-grained verification — fetch destination,   │
│          verify anchor text words appear in page content        │
│                                                                 │
│  Then: append_references_section() walks the cleaned body,     │
│  collects pool URLs the body cited, builds a numbered ##       │
│  References section from pool metadata titles.                  │
└─────────────────────────────────────────────────────────────────┘
```

### Why this is better than a static whitelist

The plugin pulls data from 100+ free research APIs (Reddit, Hacker News, Wikipedia, NASA, USGS, CoinGecko, openFDA, Nager.Date, and many more). A static allow-list of ~40 domains could never cover every legitimate publisher the research pipeline might surface.

| Static whitelist (old, v1.5.5–1.5.8) | Research Pool (v1.5.9) |
|---|---|
| Fixed ~40 domains | Any domain — if the research pipeline found a real article there |
| Rejects `petbarn.com.au` even if a perfect article exists | Accepts `petbarn.com.au/advice/calming-dog-bed` if it's in the pool |
| Can't cover 100+ research APIs | Automatically adapts per keyword |
| AI invents → plugin strips | Plugin retrieves → AI grounds in bounded pool |
| FGR (grounded references) is unbounded — plugin guesses | FGR is explicit — every cite traces to a pool entry |
| References section written by AI, then stripped | References section built programmatically from pool metadata |

The static whitelist still exists as a **fallback** for obscure keywords where the pool is empty — high-authority domains like `*.gov`, `wikipedia.org`, `rspca.org.au` remain citable even without pool membership, subject to the same Pass 3 content verification.

### Why this is better than letting the AI cite freely

| AI free-for-all | Research Pool |
|---|---|
| 15–20% hallucination rate (Joshi 2025) | Pool + content verification → near zero |
| No way to prove a citation is real | Every citation traces back to a retrieved document |
| References section can be fully fabricated | References section built from verified pool entries |
| FGR: unknown | FGR: every pool URL the body cited |

**Trade-off:** Articles may have fewer citations than an AI left to its own devices would generate — but every remaining citation is real. Per Joshi 2025, *"an article with zero fabricated citations is a pass. An article with 5 hallucinated citations is a fail."*

---

## 5. Phase 1: Pool construction

**Files:**
- `includes/Citation_Pool.php` — the pool builder
- `includes/Trend_Researcher.php` — wraps the research API
- `cloud-api/api/research.js` — Vercel endpoint that runs DDG, Brave, Wikipedia, Reddit, HN, and 100+ category APIs

### Pool sources (keyword-targeted only)

The pool ONLY draws from sources that perform a real **keyword search** — not from data APIs that return trending/random content. The distinction matters: `/api/research` returns both (a) a list of web search results for the keyword and (b) stats/facts from topic-adjacent data APIs. Only (a) goes into the pool.

| Source | Method | Why it's in the pool |
|---|---|---|
| **DuckDuckGo** | HTML scrape of `html.duckduckgo.com/html/?q={keyword}` | Direct keyword search, no API key needed. ~8 URLs/query. |
| **Brave Search** (Pro) | Brave Search API with user's key | Higher-quality web search. ~10 URLs/query. |
| **Wikipedia OpenSearch** | `api.wikipedia.org/...opensearch` | Returns direct article URLs for keyword. Always high authority. |
| **Reddit search** | Only when the post links out to an external article (not self-posts) | The linked article URL, not the Reddit thread |
| **Hacker News search** | Only when the submission links out to a real article | Same as Reddit |

Data APIs (NASA, CoinGecko, openFDA, Nager.Date, etc.) feed **statistics** into the prompt for grounding claims, but their URLs never enter the pool. Their data is cited in plain text only: *"According to NASA data, ..."*.

### Pool filters (applied to every candidate)

Each candidate URL must pass all of the following before admission to the pool:

1. Parseable `http(s)://` with a real host
2. Deep path — not `/`, not `/index.html`, not `/index.php`
3. Host does NOT match API patterns: `api.*`, `*-api.*`, `*.herokuapp.com`
4. Path does NOT match API patterns: `/api/`, `/v[1-9]/`, `/graphql`, `/rest/`, `/swagger`, `raw.githubusercontent.com`
5. HEAD request returns 200–399 (4-second timeout)
6. **Content verification:** `wp_remote_get` the URL, extract `<title>` + first 4000 chars of body, confirm at least one keyword content word (4+ chars, non-stopword) appears in the extracted text

Content verification is what catches scenarios like:
- DDG returning `joonapp.io` (a kids game company) for "calming pet bed" because of loose keyword matching → fetches the page, finds no "calming" / "pet" / "bed" content words → rejected
- A retailer category page that doesn't actually discuss the specific product → rejected
- Dead domains that happen to return 200 with a parked-page body → usually rejected

### Pool storage

Pool entries are stored as:

```php
[
    'url'         => 'https://publisher.example.com/article-slug',
    'title'       => 'Scraped or guessed article title',
    'source_name' => 'publisher.example.com',
    'verified_at' => 1744484400, // unix timestamp
]
```

Up to **12 entries** per pool. Cached as a transient (`seobetter_pool_{md5(keyword+country+domain)}`) for 6 hours, so regenerating the same article within that window reuses the pool.

During async generation, the pool is attached to the job state at `$job['results']['citation_pool']` during the `trends` step, making it available to every subsequent section.

---

## 6. Phase 2: AI grounding

**File:** `includes/Async_Generator.php` — `generate_section()` method, CITATION RULES block in system prompt

Before each section is generated, `Citation_Pool::format_for_prompt()` formats the pool as a prompt-injection block:

```
AVAILABLE CITATIONS (use ONLY these exact URLs for any hyperlinks you output):
- [How to Choose a Dog Bed](https://www.akc.org/expert-advice/home-living/how-to-choose-dog-bed/) — akc.org
- [Best Beds for Anxious Dogs](https://www.rspca.org.au/knowledgebase/anxious-dog-beds/) — rspca.org.au
- [Orthopedic Pet Beds Guide](https://www.petbarn.com.au/petspot/dog/orthopedic-beds/) — petbarn.com.au
...

RULES for these citations:
- Any hyperlink you output must be character-for-character identical to one of the URLs above
- Match the citation to the claim — don't cite a random pool URL just because it's available
- Use each URL at most once
- If a claim isn't supported by any URL in the pool, use a plain-text attribution instead (no link)
- DO NOT output a References section — the plugin builds it automatically
- Zero hyperlinks + good plain-text attributions is a PASS. Hallucinated URLs are a FAIL.
```

This block is appended to the existing trends/research data and passed to the section generator via the `$trends` parameter. Every section the AI writes sees it.

When the pool is empty, the block says instead:

```
AVAILABLE CITATIONS: None — the research pipeline found no keyword-relevant articles for this topic.
Use plain-text attributions only (e.g. 'According to a 2026 RSPCA report').
Do NOT output any [text](url) markdown links.
```

The References step in the async pipeline now returns an empty string — the AI never writes References. The plugin builds them programmatically after validation.

---

## 6B. URL deduplication (Pass 4 — added v1.5.18)

After the four whitelist/verification passes complete, `validate_outbound_links()` runs one final pass that walks every surviving link in document order and **strips the wrapper from any URL that has already appeared earlier in the article**. The anchor text is preserved as plain text — only the link wrapper is removed.

**Why:** the system prompt at `Async_Generator::get_system_prompt()` instructs the AI "use each pool URL at most once", but the AI sometimes ignores it. v1.5.17 saw test articles linking `en.wikipedia.org/Dog_food` to the keyword "dog food" three times in the same article — visually noisy, hurts the AI-citation signal (LLMs interpret repeated identical URLs as low-quality SEO-stuffing), and creates the Wikipedia-dependence anti-pattern Google penalizes.

**Normalization:** URLs are normalized before comparison so that `example.com/page`, `Example.com/page/`, and `EXAMPLE.com/page` all count as the same URL. The host is lowercased, the trailing slash is stripped from the path, and the query string is preserved as-is. The fragment is dropped.

**Applied to both:** markdown links `[text](url)` and HTML anchor tags `<a href="url">text</a>`. Image markdown `![alt](url)` is excluded via negative lookbehind `(?<!!)`.

**Source:** [seobetter.php::validate_outbound_links()](../seobetter.php) Pass 4 block, immediately before the function returns.

### v1.5.191 — Pipeline order relative to `linkify_bracketed_references()`

Pass 4 dedup only applies to links the AI wrote itself. `linkify_bracketed_references()` (which converts plain-text `(Source)` / `[Source]` mentions into pool-matched markdown links) now runs **after** `validate_outbound_links()`, not before. This means:

- **AI-written duplicate inline links** (`[dog food](wiki) ... [dog food](wiki) ... [dog food](wiki)`) → still stripped to 1 occurrence by Pass 4. The anti-spam intent is preserved.
- **Plain-text source-attribution parentheticals** (`(Wolters Kluwer)` repeated across 3 paragraphs for 3 different claims) → every occurrence gets linkified after dedup has already run, so every `(Source)` in the body ends up clickable.

Rationale: reader-attribution UX (`(Source)` after every claim) is different from inline citation spam. Readers *expect* every `(Source)` to link, and AI-citation crawlers *expect* each claim to be traceable — treating them both under one dedup rule stripped legitimate references. The v1.5.191 swap keeps Pass 4 for what it was actually designed to catch (AI spam) while letting linkify cover the attribution case.

**Pipeline order in `rest_save_draft` (seobetter.php ~1376–1404):**

```
cleanup_ai_markdown
  ↓
validate_outbound_links  ← Pass 0–4, including dedup of AI's own links
  ↓
linkify_bracketed_references  ← adds links to plain-text (Source) mentions (after dedup)
  ↓
append_references_section  ← builds ## References from pool URLs the body cited
```

---

## 7. Phase 3: Save-time validation

**File:** `seobetter.php` — `validate_outbound_links($markdown, $citation_pool)` method

Runs as four sequential passes:

### Pass 0 — References section sanitizer

`sanitize_references_section($markdown)` catches any `## References`, `## Sources`, `## Bibliography`, `## Further Reading`, or `## Citations` section the AI may have written despite being told not to. Walks each line, applies the full whitelist rules to every link, drops failing lines, removes the heading entirely if zero references survive.

This is now a safety net — after Layer 3b runs (see below), any remaining AI-written References section has already been deleted and rebuilt from scratch.

### Pass 1 — Malformed markdown stripper

Regex: `/\[([^\]]+)\]\(((?!https?:\/\/)[^)]*)\)/`

Catches cases where the AI puts literal text in the URL slot, like `[Dog Facts API](Dog Facts API)`. These are replaced with just the anchor text. Also catches relative-path attempts like `[Guide](/wp-admin/whatever)`.

### Pass 2 — Inline link filter

For every `[text](url)` and `<a href="url">text</a>` match, `filter_link($url, $text)` checks:

**Hard-fail rules (apply regardless of pool membership):**

| Check | Rule |
|---|---|
| Malformed URL | Host must parse |
| Anchor text | Must NOT contain `api`, `endpoint`, `dataset`, `sdk`, `webhook` |
| URL path | Must NOT contain `/api/`, `/v[1-9]/`, `/graphql`, `/rest/`, `/swagger`, `raw.githubusercontent.com` |
| URL host | Must NOT match `(^\|\.)api\.`, `-api\.`, `\.herokuapp\.com$` |
| Deep link | Path must not be empty, `/`, `index.html`, or `index.php` |

**Allow-list check (pool first, whitelist fallback):**

1. **Primary allow-list — pool membership:** If the URL is in this article's Citation Pool, accept it. URL comparison uses `Citation_Pool::normalize_url()` — compares scheme + host + path, ignoring query strings and trailing slashes. Pool membership is the main gate for the Research Pool architecture.

2. **Fallback allow-list — static whitelist:** If the URL is NOT in the pool, check the static trusted domain list. Used when the pool is empty or doesn't contain a specific URL. Includes `*.gov`, `*.edu`, `wikipedia.org`, major news outlets, `rspca.org.au`, `akc.org`, etc. See Section 8 for the full list. Domains here remain citable even without pool membership, subject to Pass 3 below.

Internal links (same host as the site) are always kept unchanged.

**When a link fails:** the anchor text is preserved as plain text, the link wrapper is removed.

### Pass 3 — Fine-grained knowledge verification (RLFKV)

**Method:** `verify_citation_atoms($markdown)`

For each link that survived Pass 2:

1. Tokenize the anchor text → extract content words (4+ chars, non-stopword)
2. If zero content words (e.g. `[here](...)`, `[learn more](...)`) → strip unconditionally
3. Check 24-hour transient cache keyed by `md5(url + anchor_text)` — if cached, use the cached verdict
4. `wp_remote_get($url, timeout=5)` → must return 200–399
5. Extract `<title>` + first 3000 chars of body (tags stripped, whitespace collapsed)
6. Count how many anchor content words appear in the extracted haystack (case-insensitive `strpos`)
7. Require `found / total >= 0.5` (50% content-word overlap)
8. Cache the verdict: 24h on success/content-mismatch, 1h on network error
9. Verified → keep the link. Failed → strip the link, preserve the anchor text as plain text

**Why this matters even with pool grounding:** The pool confirms a URL is a real keyword-relevant article. Pass 3 confirms the AI used it with anchor text that actually matches the destination. Example: pool contains `akc.org/expert-advice/dog-beds-guide`, but the AI wrote `[dog food nutrition study](akc.org/expert-advice/dog-beds-guide)`. Pool membership passes (URL is in pool), but Pass 3 fails (anchor text's words "food", "nutrition", "study" don't appear in a dog-beds page). Link stripped.

After Pass 3 runs, `sanitize_references_section` re-runs to remove any References heading whose entries were just stripped.

---

## 8. Phase 3b: Programmatic References section

**File:** `seobetter.php` — `append_references_section($markdown, $citation_pool)` method

Runs after all four validator passes are complete. The body now contains only verified, pool-matched or whitelisted + Pass-3-verified links.

**Algorithm:**

1. Strip any AI-written `## References` / `## Sources` / `## Bibliography` / `## Further Reading` / `## Citations` section from the body (belt-and-braces — the AI was told not to write one, but just in case)
2. Walk every surviving `[text](url)` markdown link in the body
3. For each URL, look it up in the pool via `Citation_Pool::get_entry()`
4. Collect the matching pool entries in order of first mention, deduplicated
5. If zero pool-matching citations → don't append anything (article ends at its last real section)
6. Otherwise, append:

```markdown
## References

1. [Pool Entry Title](https://pool-url.example.com/article) — publisher.com
2. [Another Pool Entry Title](https://another.example.com/page) — another.com
```

**Guarantees:**

- Titles come from the pool metadata (scraped at pool-build time), NOT from the AI
- Only pool entries the body actually cited appear — no orphan references
- Every entry corresponds to a real link in the body
- Zero fabrication surface — there is no path for a hallucinated entry to appear here

---

## 9. Phase 4: External audit (Claude skill)

**Skill:** `~/.claude/skills/check-citations/`
**Installed from:** `https://github.com/PHY041/claude-skill-citation-checker.git`
**Dependencies:** `requests` (Python)

This is an **auditing tool**, not a runtime validator. Used from Claude Code sessions to double-check generated articles against remote source state. Invoke by asking Claude to "run the check-citations skill on this article" and paste the markdown.

What it does (high level): extracts every URL from provided content, HEAD-requests each one, reports dead/redirected/suspicious links. Use this on any sample article before committing changes to the pool builder, the prompt, or the validator — it confirms the enforcement layers are working.

---

## 10. Static domain whitelist (fallback)

Used by Pass 2 when a URL is NOT in the citation pool. Allows high-authority domains to be cited even for obscure keywords where the pool builder returns nothing.

Defined in `seobetter.php` — `get_trusted_domain_whitelist()` method. Extensible via the `seobetter_trusted_domains` filter.

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

**Pet / animal authority** (relevant for the current mindiampets.com.au test site)

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

**Academic citation APIs (added v1.5.15)** — power the new Veterinary domain category and the existing Crossref fetchers in science/books/tech/education

- `crossref.org`, `api.crossref.org`, `doi.org`
- `europepmc.org`, `ebi.ac.uk`, `www.ebi.ac.uk`
- `openalex.org`, `api.openalex.org`

**Social discussion sources (added v1.5.16)** — always-on free fetchers that contribute trending discussions and citable posts to every article

- `bsky.app`, `bsky.social` — Bluesky public posts
- `mastodon.social` — Mastodon public statuses (largest instance)
- `dev.to` — DEV.to tech articles
- `lemmy.world` — Lemmy federated reddit-alternative posts

**OSM Places — anti-hallucination local business grounding (added v1.5.23, expanded to waterfall in v1.5.24)** — fetches real local businesses via a 5-tier provider waterfall (OSM → Wikidata → Foursquare → HERE → Google Places). Fixes the "fake Italian gelato shops" hallucination bug for any small city globally.

**v1.5.23 — Tier 1 (OSM, always on, no API key):**
- `openstreetmap.org`, `www.openstreetmap.org` — OSM page URLs returned by Overpass queries, used as citable sources in the References section
- `nominatim.openstreetmap.org` — Nominatim geocoding API (called server-side only)
- `overpass-api.de` — Overpass POI query API (called server-side only)

**v1.5.24 — Tier 2 (Wikidata, always on, no API key):**
- `wikidata.org`, `www.wikidata.org` — Wikidata entity pages used as citable sources
- `query.wikidata.org` — SPARQL endpoint (server-side only)

**v1.5.24 — Tier 3 (Foursquare, free 1K calls/day, user-provided API key):**
- `foursquare.com`, `www.foursquare.com`, `fsq.com` — Foursquare venue pages

**v1.5.24 — Tier 4 (HERE Places, free 1K/day, user-provided API key):**
- `here.com`, `www.here.com` — HERE place pages
- `discover.search.hereapi.com` — HERE Discover API (server-side only)

**v1.5.24 — Tier 5 (Google Places API New, paid with free credit, user-provided API key):**
- `maps.google.com`, `google.com/maps` — Google Maps place URLs
- `maps.googleapis.com`, `places.googleapis.com` — Google Places API endpoints (server-side only)

**v1.5.206b — Regional international citation domains (SHIPPED — unconditional additive)**

Added to `get_trusted_domain_whitelist()` in `seobetter.php` line **~3309-3378**, following the same always-trusted pattern as the existing UK/AU/US entries. Per-article-country gating is a future enhancement (v1.5.20X) — today these domains pass for any article, on the assumption that they are high-authority regardless of the article's target country (same as `theguardian.com` and `bbc.co.uk` being trusted even on US-only articles). See `international-optimization.md §6` for per-engine rationale.

*China (Baidu / Doubao / ERNIE / DeepSeek / Qwen / Kimi):*
- `baike.baidu.com`, `zhihu.com`, `jiandan.net`, `36kr.com`, `tmtpost.com`
- `people.com.cn`, `xinhuanet.com`, `chinadaily.com.cn`, `cctv.com`
- `zh.wikipedia.org`
- `*.gov.cn`, `*.edu.cn`

*Russia (Yandex / YandexGPT / GigaChat):*
- `ru.wikipedia.org`, `yandex.ru`, `kremlin.ru`
- `lenta.ru`, `ria.ru`, `tass.ru`, `rbc.ru`, `habr.com`
- `*.gov.ru`

*South Korea (Naver / HyperCLOVA X / Kanana):*
- `ko.wikipedia.org`
- `terms.naver.com`, `kin.naver.com`, `academic.naver.com`
- `yna.co.kr`, `chosun.com`, `donga.com`, `hani.co.kr`, `joongang.co.kr`
- `*.go.kr`, `*.ac.kr`

*Japan (Yahoo! Japan / Sakana AI / PLaMo / Rinna / ELYZA):*
- `ja.wikipedia.org`, `chiebukuro.yahoo.co.jp`, `kotobank.jp`
- `nhk.or.jp`, `asahi.com`, `mainichi.jp`, `nikkei.com`, `yomiuri.co.jp`
- `*.go.jp`, `*.ac.jp`

*Germany / DACH (Mistral / Aleph Alpha EU):*
- `de.wikipedia.org`
- `spiegel.de`, `faz.net`, `zeit.de`, `sueddeutsche.de`, `welt.de`, `tagesschau.de`
- `*.bund.de`, `*.gv.at`, `*.admin.ch`

*France:*
- `fr.wikipedia.org`
- `lemonde.fr`, `lefigaro.fr`, `liberation.fr`, `leparisien.fr`
- `*.gouv.fr`

*Spain / Latin America:*
- `es.wikipedia.org`
- `elpais.com`, `elmundo.es`, `clarin.com`, `lanacion.com.ar`, `reforma.com`
- `*.gob.es`, `*.gob.mx`, `*.gob.ar`

*Italy:*
- `it.wikipedia.org`, `corriere.it`, `repubblica.it`, `lastampa.it`
- `*.gov.it`

*Brazil / Portugal:*
- `pt.wikipedia.org`
- `globo.com`, `folha.uol.com.br`, `uol.com.br`, `estadao.com.br`
- `publico.pt`, `expresso.pt`
- `*.gov.br`, `*.gov.pt`

*Middle East (Arabic):*
- `ar.wikipedia.org`, `aljazeera.net`, `alarabiya.net`, `bbc.com/arabic`
- `*.gov.sa`, `*.gov.ae`

*India:*
- `hi.wikipedia.org`
- `thehindu.com`, `indianexpress.com`, `timesofindia.indiatimes.com`, `ndtv.com`
- `*.gov.in`, `*.ac.in`

### How matching works

`is_host_trusted()` supports:

1. **Exact match** — `rspca.org.au` matches `rspca.org.au` only
2. **Suffix match** — `rspca.org.au` also matches `www.rspca.org.au` and `subdomain.rspca.org.au`
3. **Wildcard** — `*.gov` matches any host ending in `.gov` (e.g. `cdc.gov`, `nih.gov`)

### Adding a domain

If a legitimate authoritative source is consistently getting stripped:

1. Verify it's actually authoritative (not a random blog)
2. Verify it has deep article pages, not just a homepage
3. Add to the `$default` array in `get_trusted_domain_whitelist()` in `seobetter.php`
4. For site-specific additions without code changes:

```php
add_filter( 'seobetter_trusted_domains', function ( $domains ) {
    $domains[] = 'my-authoritative-source.example.com';
    return $domains;
} );
```

**Prefer the pool over the whitelist.** If a publisher's articles keep surfacing in research but keep getting stripped, the right fix is usually to improve the research API (so their URLs make it into the pool automatically), not to hand-add domains.

---

## 11. Research APIs (where real URLs come from)

The plugin pulls data from many free APIs. Only **keyword-search sources** feed the citation pool. **Data APIs** feed the prompt context but never produce citable URLs.

### Keyword-search sources → feed the citation pool

| Source | File | Auth required | Role |
|---|---|---|---|
| DuckDuckGo HTML | `cloud-api/api/research.js` `searchDuckDuckGo()` | No | Primary — always on |
| Brave Search | `cloud-api/api/research.js` `searchBrave()` | User's API key (Pro) | Higher quality when available |
| Wikipedia OpenSearch | `cloud-api/api/research.js` `searchWikipedia()` | No | Deep article URLs on Wikipedia |
| Reddit search | `cloud-api/api/research.js` `searchReddit()` | No | Only when post links out |
| Hacker News search | `cloud-api/api/research.js` `searchHackerNews()` | No | Only when post links out |

### Data APIs → feed statistics only, URLs never cited

The Vercel research endpoint calls 100+ category-specific free APIs based on the article's domain (finance, health, crypto, science, weather, sports, etc.) — NASA, USGS, CoinGecko, openFDA, Nager.Date, MusicBrainz, Open Food Facts, Open Library, ArtInstitute, and many more. See `getCategorySearches()` in `research.js`.

These return real stats and facts that the AI uses as writing context ("According to NASA data, ...", "CoinGecko reports ..."). The API URLs themselves are explicitly **excluded** from the references pool — they're `api.*` or `*-api.*` hosts with `/v1/`, `/v2/` paths and fail the hard-fail rules in Section 7 anyway.

**Important:** URLs from these APIs are NEVER passed through to citations automatically. The AI reads the data, synthesizes a claim, and writes a plain-text attribution. This is intentional — an API endpoint is not a publication, it's a data pipeline, and shouldn't be cited even if the underlying stat is real.

### Legacy Content_Injector citation path

The "Add Citations" Fix Now button in `Content_Injector::inject_citations()` predates the Research Pool and uses its own simpler rule set: rejects API URLs, rejects generic titles, HEAD-checks every URL live, requires deep paths. It does NOT currently use the citation pool — instead, it calls `Trend_Researcher::research()` directly and filters the results.

This path is used less often now that the main generation flow handles citations automatically. Long-term we should converge it on the Research Pool too (TODO: v1.6.x).

---

## 12. Known failure modes

Behavior-driven tests — each FM describes a failure scenario, what detects it, and the expected result.

### FM-1: AI outputs homepage link

**Symptom:** Article contains `[RSPCA](https://www.rspca.org.au/)`
**Detection:** Layer 2 Pass 2 — path is empty → hard-fail (deep link required)
**Result:** Link stripped. Anchor text becomes plain text: `RSPCA`.

### FM-2: AI outputs API endpoint

**Symptom:** Article contains `[dog database](https://dog-api.kinduff.com/api/facts)`
**Detection:** Pass 2 — host matches `-api\.` pattern and path matches `/api/` pattern → hard-fail
**Result:** Link stripped. Becomes plain text `dog database`.

### FM-3: Malformed markdown (literal text in URL slot)

**Symptom:** Article contains `[Dog Facts API](Dog Facts API)` which WordPress resolves as `/wp-admin/Dog%20Facts%20API`
**Detection:** Pass 1 — URL doesn't start with `http://` or `https://`
**Result:** Link wrapper stripped, anchor text preserved: `Dog Facts API`.

### FM-4: References section with all-bad URLs

**Symptom:** Article ends with `## References` listing 5 fabricated sources (AI ignored prompt rule #8)
**Detection:** Pass 0 — every entry fails whitelist → heading also removed. Then Phase 3b builds a fresh References section from the body's actual citations.
**Result:** Old section deleted, new section built from scratch (or omitted if body has no pool citations).

### FM-5: Mixed References section

**Symptom:** AI-written `## References` has 1 real pool URL and 4 hallucinated ones
**Detection:** Pass 0 keeps the good line and drops the bad. Then Phase 3b replaces the whole section with a fresh one built from pool metadata.
**Result:** References section contains only the verified entries, with titles from the pool (not the AI's text).

### FM-6: Legitimate deep-path citation on whitelisted domain

**Symptom:** AI cites `https://www.rspca.org.au/knowledgebase/calming-dog-beds-guide`
**Detection:** Pass 2 — pool membership check (pass if in pool, otherwise fallback to static whitelist). Then Pass 3 fetches the page and confirms "calming", "dog", "beds" appear in the title/body.
**Result:** Link kept as-is. **This is the desired outcome.**

### FM-7: Misattributed citation — URL is real, content doesn't match

**Symptom:** Article contains `[dog nutrition study](https://www.rspca.org.au/about-us)` — URL is live, on a whitelisted domain, deep path, clean anchor text
**Detection:** Pass 3 fetches the page, extracts content words `dog`, `nutrition`, `study`, finds 0/3 in the "About Us" page body
**Result:** Link stripped (0% < 50% threshold). Anchor text preserved as plain text `dog nutrition study`. **This is the RLFKV scenario Pass 3 is designed to catch.**

### FM-8: Vague anchor text

**Symptom:** `[here](...)`, `[this article](...)`, `[learn more](...)`
**Detection:** Pass 3 tokenizes "here" → 1 word, <4 chars → zero content words after filtering
**Result:** Link stripped unconditionally. A link with no verifiable anchor text provides no citation value and harms readability.

### FM-9: AI cites a URL not in the pool

**Symptom:** Pool has 7 real keyword-relevant URLs, but the AI outputs an 8th URL it invented
**Detection:** Pass 2 — pool membership check fails → falls through to static whitelist → either passes whitelist + Pass 3 OR gets stripped
**Result:** If the invented URL happens to be on a whitelisted domain AND passes content verification, kept. Otherwise stripped. The pool is the primary gate; whitelist + Pass 3 is the backstop for exactly these edge cases.

### FM-10: Empty research pool (obscure keyword)

**Symptom:** Keyword is too niche — DDG / Brave / Wikipedia all return zero keyword-relevant results. Pool is empty after filtering.
**Detection:** Phase 1 builds pool → 0 entries → prompt says "AVAILABLE CITATIONS: None, use plain-text attributions only"
**Result:** AI outputs zero hyperlinks. Validator falls back to static whitelist if the AI cites something anyway. Phase 3b appends no References section. **This is correct behavior** — prefer zero links over hallucinated ones.

### FM-11: Pool candidate is live but irrelevant

**Symptom:** DDG search for "calming pet bed" returns `https://www.joonapp.io/` (kids-game company) because of loose keyword matching
**Detection:** Pool-build content verification — fetches `joonapp.io`, finds zero keyword content words, rejects the URL
**Result:** URL is never added to the pool. AI never sees it. Cannot be cited. **This is what the Research Pool architecture fixes.**

### FM-12: AI writes a References section despite being told not to

**Symptom:** Model ignores prompt rule #8 and writes `## References` with 5 entries
**Detection:** Pass 0 sanitizes each entry against the whitelist. Then Phase 3b removes any remaining `## References` heading entirely and builds its own.
**Result:** The AI's attempt is completely overwritten. The final References section is always programmatically generated from pool metadata.

### FM-13: Image markdown stripped, leaving stray `!`

**Symptom:** Article body contains `!Key takeaways and highlights for {keyword}` as a plain-text line just below `## Key Takeaways`, and the Key Takeaways list renders as a plain `<ul>` instead of the styled wp:html block with the purple left border.

**Detection (historical):** Pass 2's regex `/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/` had no negative lookbehind for `!`, so when `Stock_Image_Inserter` wrote `![alt](https://picsum.photos/...)` into the markdown, Pass 2 matched the inner `[alt](url)` portion. `picsum.photos` / `unsplash.com` / `images.pexels.com` are not in the citation pool or the static whitelist, so `filter_link()` returned `keep: false` and replaced the match with just the anchor text — leaving the leading `!` stranded.

The stranded `!alt text` line then became a paragraph section in `Content_Formatter::parse_markdown()`, because the image detection at `Content_Formatter.php:137` requires `^!\[` (not just `^!`). `format_hybrid`'s backward walk for Key Takeaways styling at `Content_Formatter.php:398-405` broke on the non-empty paragraph section before reaching the heading, so `$prev_heading` stayed empty and the list rendered as a plain `<!-- wp:list -->`.

**Result (v1.5.10+):** Fixed by two changes:

1. **Regex guard** — all 7 `[text](url)` patterns across `seobetter.php`, `Content_Formatter.php`, and `AI_Content_Generator.php` now use `(?<!!)` negative lookbehind. Image markdown is invisible to the link validator at every layer. Images render as proper `wp:image` blocks.
2. **Placement rule** — `Stock_Image_Inserter::insert_images()` now skips H2 headings that match `/key\s*takeaway|faq|frequently\s*asked|references|sources|bibliography|further\s*reading/i`. Images are placed at content-bearing H2s (`#2`, `#5`, `#8`), never adjacent to structural sections. This is a defense-in-depth improvement — even if the regex guard ever fails, images can't corrupt Key Takeaways styling.

---

## 13. Testing procedures

### Manual test (5 minutes)

1. Generate an article with a moderately-searchable keyword (e.g. "how to choose a calming pet bed"). Progress bar should say *"Researching real-time trends + building citation pool..."* during the first step.
2. Save Draft.
3. Open the post in the WordPress editor.
4. Scroll through the body — every `[text](url)` should resolve to a real article page that genuinely discusses the keyword.
5. Scroll to the bottom — either a `## References` section with 0–6 entries, OR no References section at all.
6. If References is present, click each link. Every destination must be a real page whose content matches the keyword.

### Edge-case tests

| Test | Keyword | Expected |
|---|---|---|
| **Normal** | `how to choose a calming pet bed` | 3–6 inline pool citations, References section with 3–6 real entries |
| **Rich research** | `orthopedic dog bed buying guide` | 4–8 inline citations to petbarn, akc, rspca, etc. |
| **Obscure** | `calming crystal therapy for anxious pugs` | Zero inline hyperlinks, no References section, many plain-text attributions |
| **Multilingual** | `cómo elegir una cama para perros` | Pool may be empty if research has no Spanish results → fall back to plain-text only |

### Bad signals to report back

- Any URL containing `/api/`, `.herokuapp.com`, `-api.`
- Any anchor text containing `API`, `endpoint`, `dataset`
- Any bare homepage link (`https://site.com/`)
- Any References entry that 404s when clicked
- Any References entry whose destination page has nothing to do with the keyword
- Any link whose anchor text doesn't match the destination page topic

### Good signals

- Inline citations point to real deep-path article pages
- References section (if present) lists 3–6 numbered entries the body actually cited
- Every linked page, when clicked, actually discusses the keyword
- Any claim without a link has a plain-text attribution nearby
- Articles with obscure keywords have zero hyperlinks but still read well
- The same keyword generated twice within 6 hours reuses the pool (faster second run)

### Automated audit (Claude skill)

From a Claude Code session:

```
Use the check-citations skill on the markdown of this article: <paste>
```

Reports live / dead / redirected / suspicious counts per URL.

---

## 14. Implementation notes & limitations

### Pass 3 (RLFKV) limitations

1. **No semantic verification** — only keyword overlap. A page mentioning `dog nutrition study` in passing would still pass even if it's mostly about something else. The RLFKV paper uses an evaluation LLM (Qwen3-32B) for true semantic verification; we can't afford that on every save.
2. **English stopword list only** — international articles using non-English anchor text may get false negatives. TODO: per-language stopword lists when we productionize i18n.
3. **24-hour transient cache** — a URL verified today stays verified for 24h even if the destination changes. Acceptable trade-off for latency.

### Pool builder limitations

1. **DDG HTML scraping is fragile** — if DuckDuckGo changes their HTML output format, the parser in `searchDuckDuckGo()` may break. Brave Search API is more stable for Pro users.
2. **Content verification is a lower bar than Pass 3** — pool-build verification requires at least one keyword content word; Pass 3 requires 50% anchor-word overlap. Some pool URLs may pass pool-build but fail Pass 3 at save time, and get stripped even though they were in the pool. This is correct behavior — the pool is a candidate list, not a commitment.
3. **Cache staleness** — 6-hour keyword pool cache means a source added to DDG after the first pool build won't appear until the cache expires.

### Latency cost

- Pool build: one HEAD + one GET per candidate (up to ~20 candidates) ≈ 10–30s added to the "trends" step
- Pass 3 validation: one GET per surviving citation at save time ≈ 5–25s added to save
- **Both are cached** — repeat generations of the same keyword within 6h reuse the pool; repeat citations of the same URL within 24h skip Pass 3

### What this still doesn't solve

- **Fully fabricated uncited claims:** If the AI invents a statistic but doesn't cite it, there's no URL to verify. The prompt addresses this by encouraging plain-text attributions, but there's no mechanical enforcement. TODO: atomic fact verification against research data (would need the RLFKV paper's approach with an evaluation LLM).
- **Pool URLs that technically match but are low-quality:** A blog post containing the keyword will pass content verification even if the blog is untrustworthy. Trust ranking is not implemented — we rely on DDG/Brave to prioritize quality in their search ranking.
- **Outdated destinations:** A pool URL verified yesterday may have changed today. Transient cache means we won't re-fetch for 24h.

---

## 15. When to update this file

Update this document in the **same commit** whenever you change:

- `Citation_Pool::build()` — pool builder logic
- `Citation_Pool::format_for_prompt()` — how the pool is injected into prompts
- `Citation_Pool::contains_url()` / `normalize_url()` — how pool membership is checked
- The CITATION RULES block in `Async_Generator.php::get_system_prompt()`
- `validate_outbound_links()` or any of its helpers (`sanitize_references_section`, `is_host_trusted`, `get_trusted_domain_whitelist`, `verify_citation_atoms`, `filter_link`)
- `append_references_section()` — programmatic References builder
- `Content_Injector::inject_citations()` — Fix Now button citation logic
- Research APIs in `cloud-api/api/research.js` that could surface new URL patterns
- Which Claude skills the workflow depends on
- **Any of the 7 regex patterns that match `[text](url)` markdown** in `seobetter.php`, `Content_Formatter.php`, or `AI_Content_Generator.php`. All 7 must include `(?<!!)` negative lookbehind to avoid FM-13. Run the regex sanity check from Section 13 before committing.
- **`Stock_Image_Inserter::insert_images()` placement rules.** Image placement is now part of the anti-FM-13 defense (images never placed adjacent to structural sections). If you change the placement logic, also update `article_design.md` Section 7.2.

Treat this file as the contract. If the code and this file disagree, the code wins — but that means this file is out of date and must be corrected immediately.

### Cross-references

- **[article_design.md](article_design.md)** — visual design spec, image placement rules (Section 7.2), References rendering, content-type schema matrix
- **[plugin_UX.md](plugin_UX.md)** — UI verification checklist
- **[plugin_functionality_wordpress.md](plugin_functionality_wordpress.md)** — complete technical reference
- **[SEO-GEO-AI-GUIDELINES.md](SEO-GEO-AI-GUIDELINES.md)** — SEO/GEO content generation rules

---

## 16. Version history

| Version | Date | Change |
|---|---|---|
| **1.5.11** | 2026-04-12 | **Guideline integration pass.** `Content_Formatter::inline_markdown()` now auto-adds `rel="noopener nofollow" target="_blank"` to all external links (internal links keep bare `<a>`). Added 3 new checks to GEO_Analyzer (keyword density 10%, humanizer 4%, CORE-EEAT lite 5%). Added `CORE_EEAT_Auditor` class for full 80-item rubric + VETO items. Added `Content_Ranking_Framework` scaffolding that wraps Async_Generator with explicit phase tracking. Added typography spec features to classic-mode CSS: system fonts, `clamp()` fluid sizing, `text-wrap: balance/pretty`. No link/citation behavior changed — this is purely additive to the guideline integration surface. |
| 1.5.10 | 2026-04-12 | **Image markdown fix (FM-13).** Added `(?<!!)` negative lookbehind to all 7 `[text](url)` regex patterns across `seobetter.php`, `Content_Formatter.php`, and `AI_Content_Generator.php` so image markdown `![alt](url)` is never matched as link markdown. Also: `Stock_Image_Inserter` now skips Key Takeaways / FAQ / References headings and places images on H2 #2, #5, #8 only. Fixes stray-`!` corruption and broken Key Takeaways styling detection. |
| 1.5.9 | 2026-04-12 | **Research Pool architecture.** New `Citation_Pool` class pre-fetches keyword-relevant URLs via DDG/Brave/Wikipedia at generation time, filters + content-verifies them, stores the pool as part of the job state, injects it into the AI prompt as `AVAILABLE CITATIONS`, and uses it as the primary allow-list in the validator (static whitelist becomes fallback). References section is built programmatically at save time from pool URLs the article body cited — never written by the AI. Based on Joshi 2025 (RAG = 58% hallucination reduction), Gosmar & Dahl 2501.13946 (FGR metric), and RLFKV 2602.05723 (Pass 3 atomic verification). |
| 1.5.8 | 2026-04-12 | `verify_citation_atoms()` added — Pass 3 fine-grained knowledge verification adapted from RLFKV. Fetches every surviving link's destination, verifies anchor-text key terms appear in the page content, rejects mismatches. Session + 24h transient caching. Also strips vague anchor text ("here", "learn more"). |
| 1.5.7 | 2026-04-12 | `sanitize_references_section()` added (Pass 0). Prompt told AI not to generate References at all. |
| 1.5.6 | 2026-04-12 | Anchor-text API check, deep-link requirement (no homepages), explicit API path/host patterns. |
| 1.5.5 | 2026-04-12 | First version of strict whitelist validator. Malformed-link pass. `check-citations` Claude skill installed. |
| 1.4.x | — | Original HEAD-request validator. Had a fatal flaw: fell back dead domains to "homepage" links that were also dead. Replaced in 1.5.5. |
