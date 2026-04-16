# SEOBetter — New User Setup Guide

> **Purpose:** Step-by-step instructions for new users installing the plugin from the WordPress repository. Ensures the plugin works correctly and generates high-quality, non-hallucinated articles with real research data, images, and SEO optimization.
>
> **Audience:** Plugin users (not developers). Written for the Settings page onboarding flow and the seobetter.com documentation.
>
> **Last updated:** v1.5.83 — 2026-04-17

---

## Quick Start (3 minutes)

You need **one thing** to generate your first article: an AI provider API key. Everything else is optional but recommended.

| Step | Time | Required? | What it does |
|---|---|---|---|
| 1. Add AI Provider | 1 min | **YES** | Powers article writing |
| 2. Add Pexels Key | 1 min | Recommended | Real stock photos in articles |
| 3. Generate first article | 1 min | — | Test it works |

That's it for a working setup. Steps 4-7 below unlock better quality.

---

## Step 1: Add an AI Provider (REQUIRED)

**Location:** SEOBetter → Settings → AI Providers (Bring Your Own Key)

The plugin needs an AI model to write articles. You bring your own API key — the plugin supports 7 providers:

| Provider | Recommended model | Cost per article | Get a key |
|---|---|---|---|
| **OpenRouter** (recommended) | `anthropic/claude-sonnet-4` | ~$0.03-0.08 | [openrouter.ai/keys](https://openrouter.ai/keys) |
| Anthropic (direct) | `claude-sonnet-4-20250514` | ~$0.03-0.08 | [console.anthropic.com](https://console.anthropic.com) |
| OpenAI | `gpt-4o` | ~$0.03-0.10 | [platform.openai.com](https://platform.openai.com) |
| Google Gemini | `gemini-2.5-flash` | ~$0.01-0.03 | [aistudio.google.com](https://aistudio.google.com) |
| Groq | `llama-3.3-70b` | ~$0.001 (cheapest) | [console.groq.com](https://console.groq.com) |
| Ollama (local) | `llama3.3` | Free (runs on your machine) | [ollama.com](https://ollama.com) |
| Custom endpoint | Any OpenAI-compatible | Varies | Your URL |

### How to add:
1. Go to **SEOBetter → Settings**
2. Under "AI Providers", select your provider from the dropdown
3. Paste your API key
4. Select a model (green circle = best quality, amber = good, red = basic)
5. Click **Add Provider**
6. The provider appears in the "Active Providers" list with a green checkmark

### Which provider should I choose?

- **Best quality:** OpenRouter with `anthropic/claude-sonnet-4` — access to all models through one key
- **Cheapest paid:** Groq with Llama 3.3 — $0.001 per article but lower quality
- **Free:** Ollama with local Llama 3.3 — free but requires a decent computer (16GB+ RAM)
- **If you already have an API key:** use whatever provider you already pay for

**Without this step, the plugin cannot generate articles.** You'll see an error "No AI provider configured."

---

## Step 2: Add Pexels API Key (Recommended)

**Location:** SEOBetter → Settings → Pexels API Key

This gives your articles real, high-quality stock photos instead of generic placeholder images.

### How to add:
1. Go to [pexels.com/api/new](https://www.pexels.com/api/new/) and sign up (free)
2. Copy your API key
3. Paste it into **SEOBetter → Settings → Pexels API Key**
4. Click **Save Settings**

**Without this:** Articles get random placeholder images from Picsum (generic, not relevant to your topic). With Pexels, a "dog food" article gets real photos of dogs eating.

**Cost:** Free. Pexels API has no usage limits for this type of use.

---

## Step 3: Generate Your First Article

1. Go to **SEOBetter → Generate Article**
2. Enter a keyword (e.g. "best dog food for puppies 2026")
3. Select a content type (Blog Post is the default)
4. Select a category (e.g. "Veterinary & Pet Health (Research)")
5. Click **Generate Article**
6. Wait 1-3 minutes for the article to generate
7. Review the GEO score, content preview, and headlines
8. Click **Save Draft** to save to WordPress

**If the article generates successfully, your setup is working.** The GEO score should be 65-80 out of 100 on the first generation.

---

## Step 4: Click "Optimize All" (Pro Feature)

After generating an article, the **Analyze & Improve** panel shows a score breakdown and an **"⚡ Optimize All"** button. This:

- Adds real citations from Perplexity Sonar (live web search)
- Inserts real expert quotes with source URLs
- Adds a comparison table with real product data
- Simplifies readability to grade 7 (easy to read)
- Optimizes keyword density for SEO plugins

**One click, 30-60 seconds, typically adds +10 to +25 points to your GEO score.**

This feature uses server-side Perplexity Sonar research — it works regardless of which AI provider you chose in Step 1.

---

## Step 5: AI Featured Image with Branding (Optional)

**Location:** SEOBetter → Settings → Branding & AI Featured Image

Generate unique, branded featured images instead of stock photos. Four providers available:

| Provider | Cost | Quality | Setup |
|---|---|---|---|
| **Pollinations.ai** | Free | Good | No API key needed — just select it |
| Google Gemini 2.5 Flash Image | ~$0.04/image | Great | Requires Google AI Studio API key |
| OpenAI DALL-E 3 | ~$0.04-0.08/image | Great | Requires OpenAI API key |
| Black Forest Labs FLUX Pro | ~$0.055/image | Best | Requires fal.ai API key |

### How to set up:
1. Go to **SEOBetter → Settings → Branding & AI Featured Image**
2. Select a provider (start with **Pollinations** — it's free)
3. Enter your business name and a short description
4. Choose a style preset (Realistic, Illustration, Flat, etc.)
5. Pick your brand colors
6. Click **Save Branding Settings**

**Without this:** Featured images come from Pexels (Step 2) or generic placeholders. With AI branding, each article gets a unique, on-brand hero image.

---

## Step 6: Brave Search API (Optional — Better Citations)

**Location:** SEOBetter → Settings → Brave Search API Key

Adds Brave Search as an additional research source alongside DuckDuckGo, Reddit, and Wikipedia. Produces higher-quality citation URLs in the References section.

### How to add:
1. Go to [brave.com/search/api](https://brave.com/search/api/) and sign up
2. The free plan gives you 2,000 searches/month
3. Copy your API key and paste into Settings
4. Click **Save Settings**

**Without this:** Articles still get citations from DuckDuckGo, Reddit, HN, and Wikipedia. Brave just adds more and better sources.

---

## Step 7: Places Integrations (Optional — For Local Content)

**Location:** SEOBetter → Settings → Places Integrations

Only needed if you write about local businesses (e.g. "best restaurants in Melbourne", "pet shops near me"). Adds real business data so articles don't hallucinate fake business names.

| Provider | Cost | Setup |
|---|---|---|
| OpenStreetMap | Free | Always on, no setup |
| Foursquare | Free (1K/day) | [developer.foursquare.com](https://developer.foursquare.com) |
| HERE Places | Free (1K/day) | [developer.here.com](https://developer.here.com) |
| Google Places | Paid ($200/mo free credit) | [console.cloud.google.com](https://console.cloud.google.com) |

**Without this:** OpenStreetMap (free, always on) provides basic local business data. Adding Foursquare dramatically improves coverage for small cities.

---

## Minimum Requirements Summary

### Must have (plugin won't work without):
- **AI Provider API key** (Step 1)

### Strongly recommended (poor experience without):
- **Pexels API key** (Step 2) — real images vs. placeholders

### Nice to have (improves quality):
- AI Featured Image provider (Step 5) — branded hero images
- Brave Search API key (Step 6) — better citations

### Only if writing local content:
- Foursquare/HERE/Google Places keys (Step 7)

---

## Settings Checklist (Copy-Paste for Support)

Use this to diagnose a user's setup:

```
[ ] AI Provider configured: _____ (provider name)
[ ] Model selected: _____ (model name)
[ ] Pexels API key: Yes / No
[ ] Brave Search API key: Yes / No
[ ] AI Featured Image: Off / Pollinations / Gemini / DALL-E / FLUX
[ ] Places keys: OSM (always on) / Foursquare / HERE / Google
[ ] First article generated successfully: Yes / No
[ ] GEO score on first article: _____
```

---

## Troubleshooting

### "No AI provider configured"
You haven't added an API key in Step 1. Go to Settings → AI Providers.

### Article generates but has no images
Add a Pexels API key (Step 2). Without it, articles get placeholder images.

### Article has no external links or References section
The Citation Pool found no relevant sources for your keyword. Try:
- A broader keyword (e.g. "puppy food" instead of "XYZ brand puppy food 3kg")
- The "Veterinary & Pet Health (Research)" category for health topics
- Adding a Brave Search API key (Step 6) for more sources

### GEO score is below 70
Click "⚡ Optimize All" to automatically improve the article. This adds citations, expert quotes, statistics, and comparison tables from real web research.

### "Optimize All" button times out
The optimization runs multiple AI calls and can take 30-60 seconds. If it times out:
- Your WordPress host may have a short PHP execution limit (ask your host to increase to 120s)
- Try generating a shorter article (1000-1500 words instead of 2000+)

### Images are generic/placeholder
Add a Pexels API key for relevant stock photos, or set up AI Featured Image (Step 5) for branded hero images.

---

## What Happens Behind the Scenes

When you click "Generate Article":

1. **Research:** The plugin's cloud API searches DuckDuckGo, Reddit, Hacker News, Wikipedia, Perplexity Sonar, and 100+ category-specific APIs for real data about your keyword
2. **Outline:** Your AI model creates an article structure based on the research
3. **Writing:** Your AI model writes each section with real statistics, real citations, and proper keyword placement
4. **Formatting:** The plugin formats the article with styled headings, tables, callout boxes, and Key Takeaways
5. **Scoring:** The GEO Analyzer scores the article against 14 criteria (readability, citations, statistics, expert quotes, etc.)
6. **Images:** Pexels or AI generates topic-relevant images

All citation URLs are verified against real web search results. The plugin never invents links, statistics, or expert quotes — all factual data comes from the research pipeline.
