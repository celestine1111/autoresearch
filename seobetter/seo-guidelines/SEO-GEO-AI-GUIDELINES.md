# SEOBetter Master Guidelines — SEO, GEO & AI Search Optimization

> **Single source of truth** for all article generation, scoring, and optimization in the SEOBetter WordPress plugin. Every prompt, scorer, and formatter MUST reference this document.
>
> **Last updated:** April 2026
> **Sources:** KDD 2024 GEO Research, Princeton University, SE Ranking (129K domain study), Semrush, CORE-EEAT Benchmark, Google Search Quality Guidelines, Last30Days v2.9.6 (multi-source trend research), installed Claude Skills (ai-seo, geo-content-optimizer, seo-content-writer, content-quality-auditor, meta-tags-optimizer, serp-analysis, last30days)

---

## 1. GEO VISIBILITY BOOST METHODS (Research-Backed)

These are the proven methods from the KDD 2024 research paper on Generative Engine Optimization. Use these percentages in scoring and prioritization.

| Method | Visibility Boost | Priority | How to Apply |
|--------|:---:|:---:|---|
| Cite sources | **+40%** | CRITICAL | Inline citations in [Source, Year] format. 5+ per article. |
| Add statistics | **+37%** | CRITICAL | Specific numbers with (Source Name, Year). 3+ per 1000 words. |
| Add quotations | **+30%** | HIGH | Expert quotes with name, title, org. 2+ per article. |
| Authoritative tone | **+25%** | HIGH | Confident, decisive language. Active voice. |
| Improve clarity | **+20%** | HIGH | Grade 6-8 reading level. Short sentences. |
| Technical terms | **+18%** | MEDIUM | Domain-specific terminology where appropriate. |
| Unique vocabulary | **+15%** | MEDIUM | Varied word choice. Avoid repetition. |
| Fluency optimization | **+15-30%** | HIGH | Smooth transitions, natural flow. |
| **Keyword stuffing** | **-10%** | **BLOCKED** | **Actively hurts AI visibility. Never do this.** |

**Best combination:** Fluency + Statistics + Citations = Maximum boost
**Key insight:** Low-ranking sites benefit even more — up to 115% visibility increase with citations

---

## 2. AI PLATFORM RANKING FACTORS

### Google AI Overviews (45% of Google searches)
- Strong correlation with traditional rankings
- Cited sources = +132% visibility boost
- Authoritative tone = +89% visibility boost
- Schema markup = 30-40% visibility boost (biggest single lever)
- Only ~15% of AI Overview sources overlap with traditional Top 10
- Targets "what is" and "how to" query patterns most

### ChatGPT (with web search)
- **Content-answer fit = ~55%** of citation likelihood (most important)
- **Domain authority = ~40%** of citation decision
- Freshness: content updated within 30 days = **3.2x more citations**
- Wikipedia = 7.8% of all ChatGPT citations
- High referring domains (350K+) = 8.4 citations per response average

### Perplexity AI
- Uses time-decay algorithm (new publishers get a shot)
- FAQ Schema (JSON-LD) = noticeably higher citation rate
- Publishing velocity matters more than keyword targeting
- Prefers self-contained, semantically complete paragraphs
- Maintains curated lists of authoritative domains

### Microsoft Copilot (Bing-powered)
- Entirely relies on Bing index
- LinkedIn and GitHub mentions provide ranking boosts
- Page speed threshold: <2 seconds is clear cutoff
- Bing submission critical (many sites only submit to Google)

### Claude (Brave Search backend)
- Very selective about citations
- Data-rich content with specific numbers > general content
- Factual density is critical signal
- Accuracy prioritized over broad coverage
- Reasoning transparency rewarded (explain WHY, not just WHAT)

---

## 3. ARTICLE STRUCTURE PROTOCOL (v2026.4)

Every generated article MUST follow this structure. This is the foundation for GEO scoring.

### 3.1 Required Sections (in order)
1. **Last Updated: [Month Year]** — Freshness signal at the very top
2. **H1: [Article Title]** — Keyword front-loaded, 50-60 chars
3. **H2: Key Takeaways** — 3 bullet points, 15-25 words each
4. **H2: [Content Section 1]** — Question-format heading
5. **H2: [Content Section 2]** — Question-format heading
6. **H2: [Content Section 3+]** — As many as needed for word count
7. **H2: Frequently Asked Questions** — 3-5 Q&A pairs
8. **H2: References** — 5-8 cited sources with years

### 3.2 Section Opening Rule (40-60 Word Rule)
Every H2/H3 section MUST begin with a **40-60 word paragraph** that:
- Directly answers the heading question
- Functions as a standalone answer if extracted by AI
- Contains the primary or secondary keyword
- Never starts with a pronoun

### 3.3 Island Test (Context Independence)
- **NEVER** start paragraphs with: It, This, They, These, Those, He, She, We, Its
- Always use specific entity names instead
- Every paragraph must be semantically complete in isolation
- AI models extract individual paragraphs — each must stand alone

### 3.4 Paragraph Structure
- 3-5 sentences per paragraph (target 4)
- One main idea per paragraph
- Mix short (10 words) and medium (15-20 words) sentences
- No sentences over 25 words without a break
- Bold key terms on first mention

---

## 4. CONTENT EXTRACTABILITY BLOCKS

These are the specific content patterns that AI models extract and cite. Every article should contain multiple block types.

### 4.1 Definition Block — for "What is [X]?" queries
```
[Term] is [1-sentence definition]. [Expanded 1-2 sentence explanation]. [Brief context with source].
```
- 25-50 words, standalone, starting with the term

### 4.2 Step-by-Step Block — for "How to [X]" queries
```
1. **[Step Name]**: [Clear action description in 1-2 sentences]
2. **[Step Name]**: [Clear action description]
```
- Numbered lists, 5+ steps optimal

### 4.3 Comparison Table — for "[X] vs [Y]" queries
- Structured markdown tables with criteria rows
- Include "Best For" summary row
- Bottom-line recommendation row mandatory
- **Tables get cited 30-40% more than prose**

### 4.4 FAQ Block — for follow-up questions
- Questions phrased exactly as users search
- Direct answer in first sentence
- 50-100 word answers optimal
- Natural question language (not formal)

### 4.5 Statistic Citation Block
```
[Claim]. According to [Source/Organization], [specific statistic with number and timeframe] ([Source, Year]). [Context on why this matters].
```
- Always include: source name, specific number, timeframe

### 4.6 Expert Quote Block
```
"[Direct quote]," says [Expert Name], [Title/Role] at [Organization] ([Source, Year]). [1 sentence context].
```
- Full attribution required (name, title, org)

### 4.7 Self-Contained Answer Block
```
**[Topic/Question]**: [Complete answer in 2-3 sentences with specific details/numbers/examples].
```
- Must make sense without surrounding context

---

## 5. READABILITY REQUIREMENTS

### 5.1 Reading Level Targets
| Audience | Flesch-Kincaid Grade | Description |
|---|:---:|---|
| General audience | **6-8** | Smart 12-year-old can understand |
| Technical topics | 10-12 | Professional but accessible |
| Academic | 12-14 | Only when necessary |

### 5.2 Sentence Rules
- Average: 15-20 words per sentence
- Short sentences (under 10 words): 30-40% of content
- Medium sentences (15-25 words): 40-50%
- Long sentences (25+ words): maximum 10-20%

### 5.3 Word Choice Rules
- Simple > Complex: "buy" not "purchase", "help" not "facilitate", "use" not "utilize"
- Active voice > Passive voice
- Specific > Generic
- No filler phrases: "In this section we will explore..." is BANNED
- No academic jargon unless explaining technical concepts

---

## 5A. KEYWORD PLACEMENT & DENSITY RULES (CRITICAL FOR SEO PLUGINS)

These rules ensure articles pass AIOSEO, Yoast, and RankMath analysis on first publish. Every generated article MUST follow all of these.

### 5A.1 Mandatory Keyword Placements
The **exact primary keyword** (e.g., "reptile shop melbourne") MUST appear in ALL of these locations:

| Location | Requirement | Why |
|---|---|---|
| **Meta title** | Keyword front-loaded in first half | SERP display, ranking signal |
| **Meta description** | Keyword included naturally | Click-through rate, relevance |
| **First paragraph** | Keyword in first 1-2 sentences | Topic clarity, SEO plugins check this |
| **H1 title** | Keyword included | Primary ranking signal |
| **30%+ of H2/H3 headings** | Keyword or close variant | Topical relevance, SEO plugin check |
| **Last paragraph/conclusion** | Keyword mentioned | Content closure signal |
| **Image alt text** | At least 1 image with keyword | Image SEO |
| **URL slug** | Keyword in slug | URL relevance |

### 5A.2 Keyword Density
- **Target:** 0.5%-1.5% density (keyword appears every 100-200 words)
- **Minimum:** 0.5% (AIOSEO flags below this)
- **Maximum:** 2.0% (above this = keyword stuffing, -10% AI visibility)
- **For a 1000-word article:** keyword should appear 5-15 times
- **For a 2000-word article:** keyword should appear 10-30 times
- **Use exact match AND natural variations** (e.g., "reptile shop melbourne" + "melbourne reptile shop" + "reptile store in melbourne")

### 5A.3 Heading Keyword Rules
- At least **30% of H2 headings** must contain the primary keyword or a close variant
- The **first H2** after Key Takeaways SHOULD contain the keyword
- Remaining headings use secondary/LSI keywords
- Never force the keyword where it sounds unnatural

### 5A.4 First Paragraph Rule
The first paragraph of the article (after Key Takeaways) MUST:
- Contain the **exact primary keyword** in the first 1-2 sentences
- Be 40-60 words (GEO section opening rule)
- Directly answer what the article is about
- Example: "**Reptile shop Melbourne** owners need reliable supplies for their cold-blooded pets. Melbourne has over 15 specialist reptile stores offering..."

### 5A.5 Meta Description Keyword Rule
- Must contain the **exact primary keyword** naturally
- 150-160 characters
- Must read like compelling ad copy, not keyword-stuffed

---

## 6. GEO SCORING RUBRIC (0-100)

This is the scoring system used by `GEO_Analyzer.php`. Each check is weighted.

| Check | Weight | Score 100 | Score 0 |
|---|:---:|---|---|
| Readability | 12% | Grade 6-8 | Grade 14+ |
| BLUF Header | 10% | Key Takeaways present with 3 bullets | Missing |
| Section Openings | 10% | All sections have 40-60 word openers | None do |
| Island Test | 10% | No pronoun starts | 20%+ violate |
| Factual Density | 12% | 3+ stats per 1000 words | 0 stats |
| Citations | 12% | 5+ inline citations | 0 citations |
| Expert Quotes | 8% | 2+ attributed quotes | 0 quotes |
| Tables | 6% | 2+ comparison tables | 0 tables |
| Lists | 5% | 4+ lists | 0 lists |
| Freshness Signal | 7% | "Last Updated" present | Missing |
| Entity Usage | 8% | 5%+ named entity density | Under 1% |

### Grade Scale
- **A+ (90-100):** Publish immediately — optimized for AI citations
- **A (80-89):** Strong — minor improvements possible
- **B (70-79):** Good — address flagged issues before publishing
- **C (60-69):** Needs work — multiple optimization gaps
- **D (50-59):** Significant issues — major revision needed
- **F (below 50):** Not ready — requires complete rewrite

---

## 7. TITLE TAG OPTIMIZATION

### 7.1 Requirements
- **Length:** 50-60 characters (displays fully in SERP)
- **Keyword position:** Front-loaded (first 30 chars if possible)
- **Must include:** Primary keyword, compelling hook
- **Must be:** Unique across entire site

### 7.2 Title Formulas (ranked by CTR)
1. `[Number] Best [Keyword] for [Outcome] in [Year]` — Highest CTR
2. `How to [Keyword]: [Benefit] Guide` — How-to queries
3. `What Are the Best [Keyword]? Complete Guide` — Question format
4. `[Keyword]: [Power Word] Guide You Need` — Authority
5. `[Keyword] in [Year]: What You Must Know` — Freshness

### 7.3 Title Scoring (0-100)
| Factor | Points |
|---|:---:|
| Length 50-60 chars | 25 |
| Keyword front-loaded (first 5 chars) | 25 |
| Keyword present but not first | 15 |
| Contains number | 10 |
| Contains current year | 10 |
| Question format (What/How/Why) | 10 |
| Power word (Best/Ultimate/Guide/Expert) | 10 |
| Structured (colon/dash) | 5 |

---

## 8. META DESCRIPTION OPTIMIZATION

### 8.1 Requirements
- **Length:** 150-160 characters
- **Must include:** Primary keyword (naturally), call-to-action
- **Must be:** Compelling, specific, accurate to content
- **Formula:** `[What page offers] + [Benefit to user] + [Call-to-action]`

### 8.2 Meta Description Scoring (0-100)
| Factor | Points |
|---|:---:|
| Length 150-160 chars | 30 |
| Keyword included naturally | 25 |
| Contains number/statistic | 10 |
| CTA word (learn, discover, compare) | 15 |
| Proper ending punctuation | 10 |
| Power word (free, best, proven) | 10 |

---

## 9. HEADING HIERARCHY RULES

| Level | Requirements | Keyword Strategy |
|---|---|---|
| H1 | Exactly ONE per page. Contains primary keyword. | Primary keyword or close variant |
| H2 | 3-8 per article. Question format preferred. | Secondary keywords |
| H3 | 0-3 per H2. Sub-topics. | LSI/semantic terms |

**Rules:**
- No level skipping (H1->H3 is invalid; must be H1->H2->H3)
- Headings must match how people phrase search queries
- Use question format for featured snippet + PAA targeting
- Each H2 = self-contained section that works independently

---

## 10. SCHEMA MARKUP REQUIREMENTS

| Content Type | Required Schema | AI Visibility Boost |
|---|---|:---:|
| Blog Post | Article + FAQPage | 30-40% |
| How-To Guide | HowTo + FAQPage | Direct step extraction |
| Product Page | Product + Review | Pricing/features extraction |
| Comparison | ItemList + FAQPage | Structured comparison data |
| FAQ Page | FAQPage | +40% AI visibility |

**Implementation:**
- JSON-LD format only (not microdata)
- Single `<script type="application/ld+json">` tag
- Must match visible page content exactly
- Validate with Schema.org Validator

---

## 11. INTERNAL LINKING RULES

### Link Frequency
- Important pages: 10-15 internal links pointing to them
- Regular pages: 3-5 internal links
- Avoid: 50+ internal links on single page

### Anchor Text Distribution
- Exact match keyword: maximum 20% of anchors
- Partial match phrase: 30-40%
- Generic ("this guide", "learn more"): 40-50%
- Never "click here"

### Orphan Page Rule
- Every published page must have 2+ internal links pointing to it
- Pages with 0 internal links = orphan = invisible to search engines

---

## 12. IMAGE SEO REQUIREMENTS

| Check | Requirement |
|---|---|
| Alt text | Descriptive, includes keyword naturally. 8-125 chars. |
| File name | Descriptive with hyphens: `email-marketing-chart.jpg` |
| Format | WebP preferred, JPEG fallback |
| Size | 30-100 KB compressed |
| Dimensions | Width/height attributes set (prevents CLS) |
| Loading | `loading="lazy"` on below-fold images |
| First image | Near top of page |
| Frequency | 1+ image per section minimum |

---

## 13. CONTENT FRESHNESS STRATEGY

### When to Refresh
- Statistics older than 1 year
- Rankings declining for target keyword
- Competitor published better content
- Industry changes (new tools, regulations, trends)

### What to Update
- Replace outdated statistics with current data
- Add new expert quotes
- Expand thin sections
- Update broken links
- Add comparison tables if missing
- Refresh "Last Updated" date (with actual content changes)

### Freshness Signals
- "Last Updated: [Month Year]" prominently displayed
- `dateModified` in Article schema
- Actual content changes (not just date bumping)

---

## 14. AI BOT ACCESS (robots.txt)

**MUST ALLOW** (blocking = no citations possible):
```
User-agent: GPTBot        # ChatGPT search
User-agent: ChatGPT-User  # ChatGPT browsing
User-agent: PerplexityBot  # Perplexity AI
User-agent: ClaudeBot      # Claude
User-agent: Google-Extended # Gemini, AI Overviews
User-agent: Bingbot        # Microsoft Copilot
Allow: /
```

**Machine-readable files:**
- `/llms.txt` — Quick overview for AI crawlers (llmstxt.org standard)
- `/sitemap.xml` — Referenced in robots.txt

---

## 15. CORE-EEAT SCORING DIMENSIONS

### Content Body (CORE) — 40 items
| Dimension | Items | Key Checks |
|---|:---:|---|
| **C** Contextual Clarity | 10 | Intent alignment, direct answer in first 150 words, FAQ section |
| **O** Organization | 10 | Heading hierarchy, tables for data, schema markup, no filler |
| **R** Referenceability | 10 | 5+ precise numbers, 1 citation per 500 words, source quality |
| **E** Exclusivity | 10 | Original data, unique angle, case studies, proprietary details |

### Source Credibility (EEAT) — 40 items
| Dimension | Items | Key Checks |
|---|:---:|---|
| **Exp** Experience | 10 | First-hand experience, practical examples, mistakes acknowledged |
| **Ept** Expertise | 10 | Author credentials, topic depth, reasoning transparency |
| **A** Authority | 10 | Backlinks, brand recognition, cited by others |
| **T** Trust | 10 | Disclosures, balanced perspective, factual accuracy |

### Veto Items (publication blockers)
- **C01:** Title must match content (misleading = block)
- **R10:** No contradictions between claims (inconsistency = block)
- **T04:** Required disclosures must be present (missing = block)

---

## 16. CONTENT TYPES MOST CITED BY AI

| Content Type | Citation Share | Why AI Cites It |
|---|:---:|---|
| Comparison articles | ~33% | Structured, balanced, high-intent |
| Definitive guides | ~15% | Comprehensive, authoritative |
| Original research/data | ~12% | Unique, citable statistics |
| Best-of/listicles | ~10% | Clear structure, entity-rich |
| Product pages | ~10% | Specific details AI can extract |
| How-to guides | ~8% | Step-by-step structure |

### Content that DOESN'T get cited
- Generic blog posts without structure
- Thin content with marketing fluff
- Gated content (AI can't access)
- Content without dates or author attribution
- Content in images/PDFs that AI can't parse

---

## 17. DOMAIN-SPECIFIC GEO TACTICS

### Health/Veterinary Content
- Cite peer-reviewed studies with publication details
- Include expert credentials (DVM, MD, etc.)
- Note study limitations
- Add "last reviewed" dates prominently
- Use YMYL (Your Money Your Life) trust signals

### Ecommerce Content
- Include specific pricing with dates
- Comparison tables with specs
- User review summaries
- "Best for" recommendations
- Product schema with ratings

### Technology Content
- Version numbers and dates for software
- Reference official documentation
- Code examples where relevant
- Benchmark data with methodology

### Business/Finance Content
- Reference regulatory bodies
- Specific numbers with timeframes
- Case studies with measurable results
- Quote recognized industry analysts

---

## 18. PRE-PUBLISH CHECKLIST

### Content Completeness
- [ ] Title 50-60 chars, keyword front-loaded
- [ ] Meta description 150-160 chars with CTA
- [ ] First 150 words answer the query directly
- [ ] 3+ query variants covered
- [ ] "Last Updated: [Month Year]" visible
- [ ] Author bio with credentials

### GEO Optimization
- [ ] Key Takeaways section with 3 bullets
- [ ] 40-60 word section openers after every H2
- [ ] No paragraphs start with pronouns (Island Test)
- [ ] 3+ stats per 1000 words with (Source, Year)
- [ ] 2+ expert quotes with credentials
- [ ] 5+ inline citations in [Source, Year] format
- [ ] At least 1 comparison table
- [ ] FAQ section with 3-5 Q&A pairs
- [ ] References section with sources

### Structure
- [ ] Single H1 with primary keyword
- [ ] H2s in question format
- [ ] No heading levels skipped
- [ ] 3-5 sentences per paragraph
- [ ] Bold key terms on first mention
- [ ] Lists and tables used appropriately

### Technical
- [ ] Schema markup (Article + FAQPage minimum)
- [ ] 3+ internal links to related content
- [ ] 2-3 authoritative external links
- [ ] Images with descriptive alt text
- [ ] Mobile-friendly layout
- [ ] Page loads in <3 seconds

---

## 19. SCORING THRESHOLDS FOR PLUGIN

These thresholds are used across the plugin for pass/fail decisions:

| Metric | Target | Minimum | Fail |
|---|---|---|---|
| GEO Score | 80+ | 60 | Below 50 |
| Readability Grade | 6-8 | 10 | Above 14 |
| Word Count | 2000+ | 800 | Below 300 |
| Stats per 1000 words | 3+ | 1 | 0 |
| Citations | 5+ | 2 | 0 |
| Expert Quotes | 2+ | 1 | 0 |
| Tables | 1+ | 0 | N/A |
| Section Openers (40-60w) | 100% | 60% | Below 30% |
| Island Test Pass Rate | 100% | 80% | Below 60% |
| Title Length | 50-60 | 45-65 | Below 30 or above 70 |
| Meta Description Length | 150-160 | 120-170 | Below 100 or above 180 |

---

## 20. HOW TO USE THIS DOCUMENT

### For Article Generation (AI_Content_Generator, Async_Generator)
- System prompts MUST reference Section 3 (Structure Protocol) and Section 5 (Readability)
- Section prompts MUST reference Section 4 (Extractability Blocks)
- Word count enforcement from Section 19 (Scoring Thresholds)

### For GEO Analysis (GEO_Analyzer)
- Scoring weights from Section 6 (GEO Scoring Rubric)
- Grade scale from Section 6
- Check definitions from Sections 3-5

### For Content Briefs (Content_Brief_Generator)
- Structure from Section 3
- Required elements from Sections 4 and 6
- Title optimization from Section 7
- Meta optimization from Section 8

### For Technical Audits (Technical_SEO_Auditor)
- Heading rules from Section 9
- Schema requirements from Section 10
- Image requirements from Section 12
- robots.txt from Section 14

### For Featured Snippet Optimization (Featured_Snippet_Optimizer)
- Extractability blocks from Section 4
- FAQ structure from Section 4.4
- Paragraph snippet length: 40-60 words
- List snippet length: 4-8 items
- Table snippet: 3-4 columns, 4-6 rows

### For Content Freshness (Content_Freshness_Manager, Decay_Alert_Manager)
- Refresh triggers from Section 13
- Freshness signals from Section 13

---

## 21. REAL-TIME TREND RESEARCH (Last30Days Integration)

The Last30Days skill provides real-time research across 10+ sources to inject fresh, citable data into articles. This replaces AI-hallucinated "recent statistics" with actual web data.

### Data Sources Available
| Source | What It Provides | Use For |
|---|---|---|
| **Reddit** | Discussions, recommendations, opinions | User sentiment, real-world experiences, product comparisons |
| **X/Twitter** | Breaking news, expert takes, trending topics | Latest industry developments, expert quotes |
| **YouTube** | Video content, tutorials, reviews | Multimedia references, expert demonstrations |
| **TikTok** | Trending content, short-form takes | Consumer trends, viral topics |
| **Instagram** | Visual content, brand activity | Product launches, visual trends |
| **Hacker News** | Tech discussions, startup news | Technology articles, developer opinions |
| **Polymarket** | Prediction markets, probability data | Forward-looking data, market sentiment |
| **Web Search** | News articles, blog posts, research | Traditional citations, authoritative sources |
| **Bluesky** | Decentralized social discussions | Alternative social sentiment |

### How to Use in Article Generation
1. **Before writing:** Run Last30Days research on the primary keyword
2. **Extract from results:**
   - Recent statistics with dates (last 30 days)
   - Expert quotes from social media (with attribution)
   - Trending sub-topics to cover
   - Real user questions/pain points from Reddit
   - Current pricing/product data
3. **Inject into article sections:**
   - "Recent data" field in section prompts
   - Fresh statistics replace AI-generated placeholders
   - Real Reddit/X quotes as social proof

### Integration Points in Plugin
- `Async_Generator::process_step()` — the "trends" step should use Last30Days
- `AI_Content_Generator::fetch_recent_trends()` — replace AI-hallucinated trends with real data
- `Content_Brief_Generator::generate()` — competitor analysis section uses real web data
- `Content_Refresher::refresh()` — pulls fresh data when refreshing stale articles

### Freshness Advantage for GEO
- **ChatGPT cites content updated within 30 days 3.2x more** (SE Ranking study)
- Articles with verifiable recent data get cited over older content
- Last30Days provides data with actual dates and sources — not AI approximations
- Reddit/X quotes count as "experience" signals for E-E-A-T

### Required API Keys
- `SCRAPECREATORS_API_KEY` — required for Reddit/social media search
- `BRAVE_API_KEY` — optional, improves web search quality
- `OPENROUTER_API_KEY` — already configured in SEOBetter settings (reuse)

### Output Format
Last30Days returns structured research with:
- Source URL + date for every data point
- Relevance scoring
- Cross-source synthesis
- Saved to `~/Documents/Last30Days/` for reference

---

## 22. FUTURE: NOTEBOOKLM INTEGRATION (Planned)

Google NotebookLM integration for deep content enrichment. Requires Google OAuth.

### Planned Features
- Auto-create notebook per article with source material
- Pull AI-generated summaries and audio overviews
- Generate briefing documents for complex topics
- Embed audio podcast summaries in articles
- Create study guides from article content

### Requirements (Not Yet Built)
- Google Cloud project with NotebookLM API access
- OAuth 2.0 consent screen configuration
- WordPress OAuth flow in Settings page
- Media library integration for generated assets

---

*This document is the authoritative reference for all SEOBetter plugin optimization. When in doubt, follow these guidelines. Update this document when new research or algorithm changes are published.*
