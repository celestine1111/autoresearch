# SEOBetter Website Ideas (seobetter.com)

> **Status:** PLANNED — not yet built
> **Last updated:** 2026-04-17

---

## 1. Core Pages

### Homepage
- Hero: "The WordPress plugin that writes articles AI actually cites"
- Live demo GIF/video showing article generation + GEO score ring
- 3 key stats: "+41% visibility from quotes", "+30% from citations", "+40% from statistics"
- Social proof counter (install count from WP.org API once live)
- "Get started free" → WP.org download
- "Start Pro trial" → Freemius checkout

### Pricing Page
- Mirror pro-plan-pricing.md exactly
- 3 cards: Free / Pro ($29) / Agency ($99) with annual toggle
- Feature comparison table with checkmarks
- FAQ section (refund policy, BYOK explanation, trial terms)

### Features Page
- One section per major feature with screenshot/GIF
- GEO Score Analyzer, Optimize All, 21 Content Types, Citation Pool, Places Waterfall, AI Featured Image, Schema Generation, AIOSEO/Yoast/RankMath integration

### Documentation / Setup Guide
- Publish user-instructions.md as a web page
- Searchable, with anchor links per step
- Video walkthrough (screen recording of first article generation)

---

## 2. Changelog / What's New (Auto-Published from BUILD_LOG)

### Concept
Every plugin update should auto-publish a changelog entry on the website. The BUILD_LOG.md already has the data — version number, date, description, what changed.

### Implementation Ideas

**Option A: GitHub Action → Static Site**
- On push to main, a GitHub Action parses BUILD_LOG.md
- Extracts the latest version entry (everything between two `## v1.5.X` headers)
- Converts to HTML and pushes to the website's changelog page
- Could use Next.js/Astro static generation with markdown source

**Option B: WordPress REST API**
- seobetter.com runs WordPress
- GitHub Action on push calls a webhook on seobetter.com
- Webhook creates/updates a "Changelog" custom post type
- Each version = one post, auto-published

**Option C: Simple Markdown Rendering**
- Website has a `/changelog` page that fetches BUILD_LOG.md raw from GitHub
- Client-side markdown renderer (marked.js) displays it
- Zero maintenance — always in sync with the repo
- Downside: includes internal anchors/verify commands users don't need

**Recommended: Option A or C.** Option C is fastest to ship. Option A is cleaner (strips internal-only content).

### Changelog Page Design
- URL: seobetter.com/changelog
- Each version as a card with: version badge, date, one-line description, expandable details
- Filter by: All / Features / Bug Fixes / Improvements
- RSS feed so users can subscribe to updates
- "Subscribe to updates" email capture (ties into email-marketing.md strategy)

### What to Show vs. Hide from BUILD_LOG
| Include | Exclude |
|---|---|
| Version number + date | Commit SHA |
| One-line description | File:line anchors |
| Added/Changed/Fixed sections | Verify: grep commands |
| User-facing feature descriptions | Internal guideline update notes |
| | "Verified by user: UNTESTED" |

---

## 3. Feature Request Page

### Concept
Let users suggest and vote on features. Builds community, shows you listen, and gives you a public roadmap signal.

### Implementation Options

**Option A: Canny.io (Recommended)**
- Purpose-built feature request + voting tool
- Free plan: 1 board, unlimited voters
- Paid ($79/mo): multiple boards, changelog integration, roadmap view
- Embed on seobetter.com/feature-requests
- Users log in with email (captures emails — ties into marketing)
- You can mark items: Under Review → Planned → In Progress → Complete
- Integrates with changelog — when you ship a feature, notify voters

**Option B: GitHub Discussions**
- Free, already have GitHub repo
- Users need GitHub account (barrier for non-technical WordPress users)
- Less polished voting system
- Good for developer-focused plugins, not ideal for non-technical users

**Option C: WordPress plugin (Simple Feature Requests)**
- Runs on seobetter.com
- Free, self-hosted
- Less polished than Canny but no monthly cost
- Voting + status updates

**Option D: Notion Public Board**
- Free, clean UI
- No voting system (just comments)
- Easy to set up but limited engagement features

**Recommended: Canny.io free plan to start.** Switch to paid when you have 500+ users voting. The free plan gives you 1 board which is enough.

### Feature Request Page Design
- URL: seobetter.com/feature-requests (or embedded Canny board)
- Categories: Content Generation, Analyze & Improve, SEO Integration, UI/UX, New Content Types, API & Integrations
- Status labels: Under Review, Planned, In Progress, Shipped
- Top voted features visible without login
- Email required to submit/vote (email capture opportunity)
- Link from the plugin's admin page: "Have a feature idea? → seobetter.com/feature-requests"

### Seed the board with planned features from pro-features-ideas.md:
- X/Twitter social citation integration
- Content decay alerts
- AI citation tracker (does ChatGPT cite your articles?)
- Keyword cannibalization detector
- Custom prompt templates
- White-label branding for agencies
- Bulk CSV article generation
- Internal linking suggestions

---

## 4. Blog (Content Marketing — Dog-Fooding the Plugin)

### Strategy
Use SEOBetter to generate the blog posts about SEOBetter. Dog-food the product.

### Target Keywords
- "best AI content WordPress plugin 2026"
- "AI SEO content generator WordPress"
- "how to get AI to cite your content"
- "GEO optimization WordPress"
- "SEOBetter vs Yoast" / "SEOBetter vs RankMath"
- "AI content that ranks on Google"
- "how to optimize for ChatGPT citations"
- "Perplexity SEO optimization"

### Post Types
- Comparison articles (SEOBetter vs competitors)
- How-to guides (using the plugin)
- Case studies (real GEO scores before/after)
- Industry news (Google AI Overviews updates, Perplexity changes)
- Tutorial videos with transcripts

---

## 5. Additional Pages

### About
- Who built it, why, the problem it solves
- Link to the Princeton GEO research paper
- "Built for the AI search era"

### Support
- Knowledge base (searchable FAQ)
- Contact form
- Link to WordPress.org support forum (free users)
- Priority support form (Pro/Agency users — verified via Freemius license)

### Privacy Policy
- Required for GDPR
- What data the plugin collects (only with opt-in)
- Freemius data handling
- No third-party data sharing

### Terms of Service
- API usage terms
- Fair use policy for Cloud Credits
- Refund policy (Freemius handles)

### Affiliate Program Page (Phase 4+)
- 20-30% recurring commission
- Managed via Freemius affiliate module
- Creative assets (banners, comparison tables, review templates)

---

## 6. Technical Stack Ideas

| Option | Pros | Cons |
|---|---|---|
| **WordPress + Jesuspended theme** | Dog-food SEOBetter, familiar, plugins ecosystem | Slower, needs hosting |
| **Next.js + Vercel** | Fast, modern, already use Vercel for cloud-api | Separate tech stack from the plugin |
| **Astro + Vercel** | Static, fast, markdown-native (good for docs/changelog) | Less dynamic |
| **Ghost** | Clean blog platform, built-in email/membership | Separate hosting, no WP integration |

**Recommended: WordPress on WP Engine.** You're building a WordPress plugin — the website should run WordPress. Dog-food everything. Use SEOBetter to generate the marketing blog posts.

---

## 7. Launch Checklist

- [ ] Domain: seobetter.com (registered?)
- [ ] Hosting: WP Engine or similar
- [ ] SSL: Let's Encrypt or host-provided
- [ ] Pages: Home, Pricing, Features, Docs, Changelog, Feature Requests, Blog, About, Support, Privacy, Terms
- [ ] Freemius integration: checkout links, license verification, trial signup
- [ ] Analytics: GA4 or Plausible
- [ ] Email capture: Freemius activation + on-site forms
- [ ] Changelog: auto-sync from BUILD_LOG.md
- [ ] Feature requests: Canny.io board embedded
- [ ] Blog: 3 launch posts ready
- [ ] Social: Twitter/X, LinkedIn, maybe Bluesky
- [ ] WordPress.org listing: hero screenshot, description, FAQ
