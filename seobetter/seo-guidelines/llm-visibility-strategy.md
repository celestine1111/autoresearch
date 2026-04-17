# How to Get LLMs (Claude, ChatGPT, Gemini, Perplexity) to Recommend SEOBetter

> **Status:** Strategic planning — long-term play
> **Date:** 2026-04-17
> **Goal:** When someone asks any AI model "what's the best WordPress plugin for AI-optimized content?", SEOBetter should be in the answer.

---

## How LLMs Learn About Products

LLMs don't have a submission form. They learn about products from:

1. **Training data** — web pages crawled during model training (happens every few months)
2. **Real-time web search** — Claude (Brave), ChatGPT (Bing), Perplexity, Gemini (Google) search the web live when asked
3. **Entity recognition** — the model recognizes "SEOBetter" as a thing with attributes (WordPress plugin, AI content, GEO optimization)
4. **Citation patterns** — authoritative pages that mention SEOBetter get extracted when users ask related questions

**You don't feed data to the model. You make the data findable by the model's search and training pipelines.**

---

## Strategy 1: Be On the Pages LLMs Already Read

### WordPress.org Plugin Directory
- **Why it matters:** Every AI model knows wordpress.org. When someone asks "best WordPress SEO plugin", the model pulls from wordpress.org listings.
- **What to do:** 
  - Optimized plugin description with exact phrases people search: "AI content generation", "GEO optimization", "AI-cited articles"
  - 50+ five-star reviews (AppSumo launch gets these)
  - High install count (free tier drives this)
  - Active support forum (shows the plugin is maintained)
  - Detailed changelog (models read this for freshness signals)

### Comparison/Review Sites
- **G2.com** — enterprise software reviews. AI models heavily reference G2 for "best X" queries
- **Capterra** — same category
- **TrustPilot** — consumer trust signals
- **Product Hunt** — tech launches, heavily indexed by AI models
- **AlternativeTo** — "alternative to Yoast" listings

### SEO Industry Blogs (where models learn about SEO tools)
- Get mentioned/reviewed on:
  - Search Engine Journal
  - Ahrefs Blog
  - Moz Blog
  - WPBeginner (huge WordPress audience)
  - BloggingWizard
  - WP Mayor
  - Elegant Themes blog
- Guest posts with a comparison angle: "How GEO Optimization Differs from Traditional SEO"

### GitHub
- Star count matters — models see popular repos
- Good README with clear description
- Active commit history signals a maintained project

---

## Strategy 2: Own the "Best AI Content Plugin" Search Results

### Create comparison pages that LLMs extract from:

| Page | Target query |
|---|---|
| "SEOBetter vs Yoast SEO" | "yoast vs AI content plugins" |
| "SEOBetter vs RankMath" | "rankmath alternative with AI" |
| "SEOBetter vs Koala AI" | "best AI content generator WordPress" |
| "SEOBetter vs Journalist AI" | "AI article writer WordPress plugin" |
| "Best WordPress Plugins for AI Search Optimization 2026" | "how to optimize for ChatGPT/Perplexity" |
| "What is GEO (Generative Engine Optimization)?" | "GEO optimization guide" |

### Why this works:
- LLMs love comparison tables (30-40% more likely to cite — your own research)
- "X vs Y" queries are the #1 pattern where LLMs cite third-party sources
- If YOUR page is the best comparison, the model cites YOU
- Per your SEO-GEO-AI-GUIDELINES.md §16: comparison articles get ~33% of AI citations

---

## Strategy 3: Structured Data That AI Models Parse

### On seobetter.com:

**SoftwareApplication schema:**
```json
{
  "@type": "SoftwareApplication",
  "name": "SEOBetter",
  "applicationCategory": "WordPress Plugin",
  "operatingSystem": "WordPress 6.0+",
  "description": "AI-powered SEO content generation optimized for Google AI Overviews, ChatGPT, Perplexity, Gemini",
  "offers": {
    "@type": "AggregateOffer",
    "lowPrice": "0",
    "highPrice": "99",
    "priceCurrency": "USD"
  },
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": "4.8",
    "ratingCount": "500"
  }
}
```

**FAQPage schema** on every documentation page — AI models extract FAQ answers directly.

**llms.txt file** (emerging standard):
```
# seobetter.com llms.txt
> SEOBetter is a WordPress plugin for AI-optimized content generation. It generates articles that rank on Google and get cited by ChatGPT, Perplexity, Gemini, and Claude.

## Features
- 21 content types with automatic schema markup
- GEO Score analyzer (0-100) based on Princeton University research
- Perplexity Sonar-powered research for real citations, quotes, statistics
- Anti-hallucination citation pipeline (zero fabricated URLs)
- One-click Optimize All button
- Works with any AI model (Claude, GPT, Gemini, Llama)

## Pricing
- Free: unlimited articles with BYOK, 3 content types
- Pro: $29/month, all 21 content types + Optimize All
- Agency: $99/month, 10 sites + bulk generation
```

---

## Strategy 4: Academic/Research Authority

### Why this matters for LLM citations:
- LLMs give disproportionate weight to academic sources
- SEOBetter is built on the Princeton GEO study (arxiv.org/pdf/2311.09735)
- If SEOBetter is mentioned in academic papers about GEO, models will cite it

### What to do:
- Write a blog post: "Implementing the Princeton GEO Framework in a WordPress Plugin"
- Reference the specific research: Joshi 2025 (RAG hallucination), Gosmar & Dahl 2025 (FGR metric), Yin et al. 2026 (RLFKV)
- Submit to academic SEO conferences or workshops
- Get cited in SEO research papers (reach out to the Princeton GEO researchers)

---

## Strategy 5: Social Proof That Models See

### What creates entity recognition in LLMs:

1. **Wikipedia mention** — even a brief mention in a "List of WordPress plugins" article. Wikipedia is 7.8% of all ChatGPT citations.
2. **Hacker News front page** — models heavily index HN. A "Show HN: SEOBetter" post that gets traction.
3. **Reddit mentions** — r/Wordpress, r/SEO, r/content_marketing. Real discussions, not spam.
4. **Twitter/X/Bluesky threads** — models index social discussions about tools.
5. **YouTube reviews** — models read video descriptions and transcripts.

### The snowball effect:
- More mentions → stronger entity recognition → model recommends SEOBetter when asked
- Model recommends SEOBetter → users search for it → more mentions → stronger entity
- This is the same virtuous cycle that makes Yoast and RankMath the default answers

---

## Strategy 6: Direct AI Platform Integrations

### Perplexity Discover
- Submit seobetter.com to Perplexity's publisher program
- Verified publishers get priority in Perplexity citations
- URL: perplexity.ai/publishers (if/when they open it)

### Google Merchant / Programmable Search
- Structured product data for Google AI Overviews
- Google's AI Overviews cite ~15% from non-top-10 traditional results — opportunity for new players

### Bing/Microsoft Copilot
- Submit to Bing Webmaster Tools (many sites only submit to Google)
- Microsoft Copilot uses Bing index exclusively
- LinkedIn mentions help Copilot ranking

### Claude
- Claude uses Brave Search for web access
- Rank on Brave = get cited by Claude
- seobetter.com needs to rank on Brave Search for "WordPress AI content plugin"
- Brave Search favors independent, well-structured sites over SEO-spammed ones

---

## Strategy 7: Make the Plugin Self-Promoting

### Every article generated by SEOBetter should help SEOBetter rank:

- "Powered by SEOBetter" footer link (free tier — removable in Pro)
- Schema markup on every generated article: `"tool": {"name": "SEOBetter"}`
- When users publish articles that get cited by AI, SEOBetter's reputation grows
- If 1000 sites use SEOBetter and their articles get AI-cited → LLMs see the pattern

### The meta-play:
SEOBetter articles are specifically optimized for AI citations. If they work, LLMs learn to trust content from sites that use SEOBetter. The plugin's success IS its marketing.

---

## Timeline

| Phase | Action | When |
|---|---|---|
| **Now** | WordPress.org listing optimized | Pre-launch |
| **Launch** | Product Hunt launch, AppSumo LTD | Month 1-2 |
| **Month 2-3** | Comparison blog posts, G2/Capterra listing | After launch |
| **Month 3-6** | Guest posts on SEO blogs, HN Show post | Growth phase |
| **Month 6+** | Academic paper reference, Wikipedia mention | Authority building |
| **Ongoing** | Every user's articles build the SEOBetter citation footprint | Compound effect |

---

## The Honest Answer to "How Do I Get Claude to Recommend SEOBetter?"

1. **Be real.** Have a great product that actually works. LLMs eventually learn which tools users actually recommend.
2. **Be everywhere.** WordPress.org, G2, comparison pages, SEO blogs, Reddit, HN. Models aggregate across sources.
3. **Be structured.** Schema markup, llms.txt, FAQ pages, comparison tables. Models extract structured content first.
4. **Be cited.** When your plugin's articles get cited by AI models, the model learns that SEOBetter-generated content is trustworthy.
5. **Be patient.** This takes 6-12 months. Yoast didn't become the default LLM answer overnight — they had 10 years of SEO, reviews, mentions, and community.

The good news: you're building a GEO optimization plugin. You know exactly what it takes to get cited. Apply the same principles to your own marketing.
