# Research Note: Firecrawl vs Perplexity Sonar for Article Research

> **Status:** Research only — not implemented
> **Date:** 2026-04-17
> **Context:** Evaluating cheaper/better alternatives to Perplexity Sonar for the server-side research pipeline in `fetchSonarResearch()` (cloud-api/api/research.js)

---

## Current Architecture (v1.5.81+)

One Perplexity Sonar call per article via OpenRouter:
- Cost: $0.008 (sonar) or $0.06 (sonar-pro) per article
- Returns: citations (URLs), quotes, statistics, table_data as structured JSON
- Runs server-side on Vercel using Ben's OPENROUTER_KEY
- Limitation: Sonar is a black box — you can't verify if the URLs it returns actually contain the content it claims

---

## Alternative: Serper + Firecrawl (Two-Step Pipeline)

### Step 1: Serper — find relevant URLs ($0.001 per search)
- API: serper.dev
- Returns: Google SERP results (titles, URLs, snippets) for the keyword
- 8-10 real URLs per search
- Significantly cheaper than Sonar for the "find pages" step

### Step 2: Firecrawl — scrape pages into clean markdown ($0.00083 per page)
- API: firecrawl.dev (also has open-source self-hosted option via Docker)
- Takes a URL → returns clean markdown/JSON of the page content
- Strips navigation, ads, footers — just the article content
- Perfect for RAG (Retrieval-Augmented Generation)

### Step 3: AI extraction — structured data from scraped content
- Use the user's AI model or a cheap model (Groq Llama) to extract:
  - Expert quotes with attribution
  - Statistics with source names
  - Product comparison data for tables
- The AI reads ACTUAL page content, not training data — zero hallucination

### Cost comparison per article

| Approach | Search | Scrape | Extract | Total |
|---|---|---|---|---|
| **Sonar (current)** | included | included | included | $0.008-0.06 |
| **Sonar Pro (current)** | included | included | included | $0.06 |
| **Serper + Firecrawl + Groq** | $0.001 | $0.004 (5 pages) | $0.001 | **$0.006** |
| **Serper + Firecrawl (self-hosted)** | $0.001 | $0 | $0.001 | **$0.002** |
| **Tavily + Firecrawl** | $0.005 | $0.004 | $0.001 | **$0.010** |

### At scale (10,000 articles/month)

| Approach | Monthly cost |
|---|---|
| Sonar | $80-600 |
| Sonar Pro | $600 |
| Serper + Firecrawl | $60 |
| Serper + self-hosted Firecrawl | $10 |

---

## Advantages of Serper + Firecrawl over Sonar

1. **Verifiable content** — you have the actual page markdown, so RLFKV Pass 3 can verify citations against real content (not just trust Sonar's claims)
2. **60-90% cheaper** at scale
3. **Self-hostable** — Firecrawl is open-source, can run in Docker for $0 scraping cost
4. **More citation URLs** — Serper returns 8-10 Google results; Sonar returns 5-8
5. **Full page content** for the AI to quote from — real sentences from real pages, not Sonar's summaries
6. **Predictable pricing** — flat rate per page, no token-based surprises

## Advantages of Sonar (why we're keeping it for now)

1. **One call** — simpler architecture, fewer failure points
2. **Already deployed** — working in production as of v1.5.81
3. **Includes synthesis** — returns structured JSON directly, no extraction step needed
4. **Good enough** for <1000 users — cost is manageable
5. **Includes quotes and stats** — Serper only returns SERP snippets, not deep page content (need Firecrawl for that)

---

## Other Alternatives Noted

| Tool | Use | Cost | Notes |
|---|---|---|---|
| **Tavily** | AI-agent-optimized search | ~$0.005/search | Built for AI agents, returns relevance-scored snippets |
| **Serper** | Raw Google SERP data | $0.001/search | Cheapest search option, just titles + URLs + snippets |
| **Brave Search API** | Web search | Already integrated (Pro feature) | Currently used in the research pipeline alongside DDG |
| **Jina Reader** | URL → markdown | $0.001/page | Alternative to Firecrawl, simpler API |
| **Self-hosted Firecrawl** | Docker container | $0/page | Open source, requires server to run |

---

## Implementation Plan (When Ready)

### Where to change: `cloud-api/api/research.js::fetchSonarResearch()`

Replace the single Sonar call with:
```
1. Serper search (keyword) → 8 URLs
2. Firecrawl scrape (top 5 URLs) → 5 markdown documents
3. Groq/cheap LLM extract (from 5 documents) → {citations, quotes, stats, table_data}
```

### What stays the same:
- Return object shape (sonar_citations, sonar_quotes, sonar_statistics, sonar_table_data)
- PHP pipeline (Citation_Pool, Async_Generator, Content_Injector)
- Frontend (sonar_data passthrough)
- The only change is inside `fetchSonarResearch()` — everything downstream is identical

### Env vars needed:
- `SERPER_API_KEY` (replace or alongside OPENROUTER_KEY)
- `FIRECRAWL_API_KEY` (or self-hosted URL)

### Migration strategy:
- Add Serper + Firecrawl as an alternative path inside `fetchSonarResearch()`
- If Serper/Firecrawl keys are set → use the new pipeline
- If only OPENROUTER_KEY is set → fall back to Sonar (current behavior)
- Gradual rollout: test with Ben's articles first, then enable for all users

---

## Decision

**IMPLEMENTED in v1.5.133 (2026-04-19).** Serper + Firecrawl is now the primary research pipeline. Sonar kept as automatic fallback if Serper/Firecrawl keys are not set.

Implementation: `cloud-api/api/research.js::fetchSerperFirecrawlResearch()` + `cloud-api/api/scrape.js` (new endpoint for PHP recipe pipeline).

Env vars on Vercel: `SERPER_API_KEY`, `FIRECRAWL_API_KEY`, `EXTRACTION_MODEL` (optional).
