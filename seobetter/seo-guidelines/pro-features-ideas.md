# SEOBetter Pro Features — Ideas & Planning

> **Purpose:** Brainstorm and track ideas for Pro-only features.
> Everything is currently free for testing. Once validated, features will be gated behind a license key.
>
> **Last updated:** April 2026

---

## FREE TIER (Always Available)

### Content Generation
- [ ] Generate articles (3/month via Cloud, unlimited with BYOK)
- [ ] Async generation with progress bar
- [ ] 5 headline variations with scoring
- [ ] Auto-generated meta title & description
- [ ] Auto-suggest secondary & LSI keywords
- [ ] Stock images (Picsum fallback — generic)
- [ ] GEO Score analysis (score + grade + suggestions)
- [ ] Save as Post or Page
- [ ] 1 AI provider connection (BYOK)

### Editor Integration
- [ ] GEO Score in Post sidebar panel
- [ ] Toolbar score badge
- [ ] Headline Analyzer
- [ ] Re-analyze button

### Technical SEO
- [ ] Schema markup (Article + FAQ auto-detected)
- [ ] Social meta (OG + Twitter cards)
- [ ] llms.txt generator
- [ ] AI bot meta tags (max-image-preview, max-snippet)
- [ ] Speakable schema for voice search

---

## PRO TIER IDEAS (To Gate Behind License Key)

### Content Generation Pro
- [ ] Unlimited Cloud generation (remove 3/month limit)
- [ ] Bulk CSV import (50+ keywords → batch generate)
- [ ] Content Brief Generator
- [ ] Content type templates (21 types with auto-adjusted prompts)
- [ ] Country & language targeting (90+ countries with local APIs)
- [ ] Topic-relevant images via Pexels (vs generic Picsum)
- [ ] Featured image auto-download to media library

### Analyze & Improve Pro
- [ ] "Add now" inject buttons (citations, quotes, table, stats, freshness)
- [ ] Real-time research data (Vercel: Reddit, HN, Wikipedia, DuckDuckGo)
- [ ] Brave Search integration (real web statistics)
- [ ] One-click Content Refresh for stale articles

### SEO Plugin Integration Pro
- [ ] AIOSEO auto-population (title, desc, OG, Twitter, schema, keywords)
- [ ] Yoast auto-population
- [ ] RankMath auto-population
- [ ] Schema type auto-detection (Article, FAQ, HowTo, Review, Product)

### Analytics & Monitoring Pro
- [ ] AI Citation Tracker (check if AI engines cite your content)
- [ ] Content Decay Alerts (email when posts go stale or scores drop)
- [ ] Keyword Cannibalization Detector
- [ ] Internal Link Suggestions
- [ ] GEO Score column in Posts list (sortable)

### Advanced Pro
- [ ] Unlimited AI provider connections
- [ ] Social content generator (Twitter, LinkedIn, Instagram)
- [ ] Content Exporter (HTML, Markdown, Plain Text)
- [ ] Affiliate link auto-linking + CTA buttons
- [ ] White-label mode

---

## CONVERSION STRATEGY IDEAS

### Free → Pro Triggers
1. **Usage limit** — 3 articles/month free, unlimited Pro
2. **Score gate** — generate article free, but "Add now" inject fixes require Pro
3. **Preview gate** — show the improved score after fix, but require Pro to actually apply it
4. **Feature teaser** — show Citation Tracker results but blur/hide details without Pro
5. **Time-limited trial** — 7-day Pro trial on first install, then reverts to free

### Recommended Split (based on testing)
- **Free:** Generate articles, see GEO score, see suggestions, headline analyzer
- **Pro:** Fix buttons (inject), research data, Pexels images, AIOSEO integration, bulk generate, citation tracker
- **Upsell moment:** After generation, show score and fixes needed → "Add now" buttons require Pro

### Pricing Ideas
- $59/year single site
- $99/year 3 sites
- $199/year unlimited sites
- Lifetime deal: $299

### Key Metric to Track
- Free → Pro conversion rate (target: 5-10%)
- Articles generated per user per month
- "Add now" button clicks (shows intent to upgrade)

---

## SECURITY CONSIDERATIONS

### License Key Architecture
- Server-validated keys (Vercel `/api/validate`)
- Domain-locked (each key tied to site URL)
- 24-hour cache with 48-hour grace period on network errors
- Development key: `SEOBETTER-DEV-PRO` (only works with WP_DEBUG)

### Anti-Piracy
- Pro features that depend on server-side processing (research API, Brave search) can't be unlocked by editing PHP
- Client-side gating (UI buttons) can be bypassed but the actual processing requires a valid key
- Consider moving more logic to the Vercel cloud API for Pro features

---

*Add new ideas to this file as they come up. Move items to "implemented" when built and tested.*
