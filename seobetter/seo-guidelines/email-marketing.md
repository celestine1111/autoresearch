# SEOBetter Email Marketing Strategy

> **Purpose:** Capture free user emails and convert them to Pro via drip campaigns. Must not deter users or violate WordPress.org guidelines.
>
> **Status:** PLANNED — not yet implemented
>
> **Last updated:** 2026-04-17

---

## 1. The Rule: Never Block, Always Earn

**WordPress.org will REJECT your plugin if email is required to use it.** Their guidelines explicitly prohibit:
- Mandatory registration/email to access core features
- Popups that block the UI until the user opts in
- Pre-checked consent boxes
- Sending emails without explicit opt-in

**What works (and converts at 15-25%):**
- Soft opt-in during plugin activation (Freemius handles this)
- Value-first email capture (give something useful in exchange)
- Behavioral triggers (email based on what they DID, not time elapsed)

---

## 2. Email Capture Points (3 touchpoints)

### Touchpoint 1: Plugin Activation (Freemius Built-In)

**When:** First time the plugin is activated after install.

**What Freemius shows:** A non-blocking admin notice:
> "Allow SEOBetter to collect diagnostic data to help us improve the plugin? We'll also send you tips on getting higher GEO scores."
> 
> [Allow & Subscribe] [Skip]

**Conversion rate:** 40-60% opt-in (industry standard for Freemius activation).

**Why it works:** It's the moment of highest intent — they just installed the plugin. The "diagnostic data" framing gives them a reason beyond "we want to email you."

**What you collect:**
- Email address
- Site URL
- WordPress version
- PHP version
- Active theme
- Which SEO plugin they use (AIOSEO/Yoast/RankMath/none)

**CRITICAL:** If they click "Skip", the plugin works 100% normally. No nag screens. No reduced features. No repeated asks. One and done.

### Touchpoint 2: First Article Success (In-App)

**When:** User generates their first article and sees the GEO score.

**What appears:** A non-blocking banner below the score dashboard:
> "Your first article scored 74/100. Want weekly tips to hit 90+?"
> 
> [email input] [Get Tips] [No thanks]

**Why it works:** They just experienced value. The score gives them a concrete number to improve. The offer is specific ("hit 90+") not generic ("subscribe to our newsletter").

**Conversion rate:** 10-20% (lower than activation but higher quality — these are engaged users).

**Show only once.** If dismissed, never show again. Store in `seobetter_email_dismissed` option.

### Touchpoint 3: Score Improvement After Optimize All (In-App)

**When:** User clicks "Optimize All" and their score jumps (e.g. 72 → 87).

**What appears:** In the green success panel:
> "Score improved from 72 → 87. Pro users average 92. Want a weekly report of your published articles' GEO scores?"
> 
> [email input] [Send Me Reports] [Skip]

**Why it works:** They just felt the dopamine of improvement. The "weekly report" offer gives ongoing value, not just a sales pitch.

**Show only once per user.**

---

## 3. Drip Campaign Sequences

### Sequence A: Free User Onboarding (7 emails over 30 days)

**Goal:** Educate → demonstrate value → convert to Pro trial.

| Day | Subject | Content | CTA |
|---|---|---|---|
| 0 | "Your SEOBetter setup is complete" | Welcome + link to setup guide + what the plugin does | Generate your first article |
| 1 | "Your first article: what the GEO score means" | Explain the 14 scoring criteria, what 70 vs 90 means for AI citations | Check your article's score |
| 3 | "3 things that make AI cite your content" | Statistics (+40%), Citations (+30%), Tables (+30-40%) — research-backed | Try the Veterinary category |
| 7 | "How [Site Name] went from 68 to 94 in one click" | Case study of Optimize All (use real mindiampets data when available) | Start 7-day Pro trial |
| 14 | "Your articles vs. the competition" | Show how SEOBetter articles compare to manually written content for AI citations | Start Pro trial |
| 21 | "Are AI search engines finding your content?" | Explain how ChatGPT, Perplexity, Gemini choose sources — and how SEOBetter optimizes for all of them | Upgrade to Pro |
| 30 | "Last chance: $99 off annual Pro" | Time-limited discount for annual plan. Loss aversion: "Your 3 free content types generate good articles. Pro's 21 types + Optimize All generate great ones." | Claim discount |

**Unsubscribe on every email.** No tricks.

### Sequence B: Trial User Activation (5 emails over 7 days)

**Goal:** Get trial users to experience the "aha moment" before trial ends.

| Day | Subject | Content | CTA |
|---|---|---|---|
| 0 | "Your Pro trial is active — here's how to use it" | Top 3 Pro features to try first: Optimize All, AI Featured Image, all 21 content types | Generate a Comparison article |
| 1 | "Try this: generate a Buying Guide" | Walk through the Buying Guide content type — the format AI models cite most (33% of AI citations are comparisons) | Generate a Buying Guide |
| 3 | "Your Pro features expire in 4 days" | Summary of what they've used vs. haven't tried yet. Show score comparison: articles with vs. without Optimize All | Try Optimize All |
| 5 | "2 days left — here's what you'll lose" | List the specific features that go away. Show their best article score. "Keep generating articles like this." | Upgrade now |
| 7 | "Your trial ended — but your articles are still there" | Reassure them saved articles aren't affected. Offer: "Get 20% off if you upgrade today" | Upgrade with discount |

### Sequence C: Churned User Win-Back (3 emails over 60 days)

**Goal:** Re-engage users who installed but stopped using.

| Day | Subject | Content | CTA |
|---|---|---|---|
| 14 | "We noticed you haven't generated an article yet" | Quick reminder of what the plugin does. Link to 3-minute setup guide. | Generate your first article |
| 30 | "New in SEOBetter: [latest feature]" | Highlight a recent improvement (e.g. "Perplexity Sonar integration — real citations, not AI-generated") | Try it now |
| 60 | "Should we part ways?" | Honest: "If SEOBetter isn't for you, no hard feelings. But if you're still thinking about AI-optimized content, here's what's changed since you installed..." | One last try / Unsubscribe |

---

## 4. Behavioral Triggers (Not Time-Based)

These emails fire based on what the user DOES, not when they signed up:

| Trigger | Email | Why |
|---|---|---|
| Generated 5th article | "You've created 5 articles with SEOBetter. Here's how to make them work harder." | They're committed — ready for upsell |
| First article hits GEO 85+ | "Congratulations: your article is in the top 10% for AI citability" | Celebrate success, reinforce value |
| Used Optimize All for the first time | "You just boosted your score by X points. Pro users do this on every article." | They experienced the core Pro feature |
| Haven't used plugin in 14 days | "Your content is aging — here's how to keep it ranking" | Re-engagement with value |
| Published 10+ articles | "You're a power user. Have you considered the Agency plan?" | Upsell to higher tier |
| Article gets cited by AI (if tracking enabled) | "ChatGPT just cited your article about [keyword]!" | The ultimate proof of value |

---

## 5. Email Service Provider

### Recommended: Freemius Built-In Email

**Freemius includes email marketing** in their platform:
- Automatic opt-in capture during activation
- Segmentation by plan (free/trial/pro/agency)
- Behavioral triggers (install, activate, deactivate, upgrade, downgrade)
- Pre-built templates for WordPress plugins
- GDPR-compliant with proper consent tracking
- No additional cost — included in Freemius revenue share

**Why not Mailchimp/ConvertKit/etc.?**
- Extra integration work
- Extra monthly cost ($30-100/mo for 1000+ contacts)
- Freemius already has the user data (install date, plan, usage)
- One less system to manage

### If you outgrow Freemius email:
- **ConvertKit** ($29/mo for 1000 subscribers) — best for creator businesses
- **Loops.so** ($49/mo) — purpose-built for SaaS email, has product event triggers
- **Customer.io** ($100/mo) — enterprise-grade behavioral triggers

---

## 6. GDPR & Compliance

### Required (non-negotiable):
- **Explicit opt-in** — never pre-checked. User must actively click "Allow" or type their email
- **Unsubscribe link** in every email — one click, no confirmation page
- **Privacy policy link** during opt-in — link to seobetter.com/privacy
- **Data portability** — user can request their data via email
- **Data deletion** — user can request deletion via email
- **No third-party sharing** — emails only used for SEOBetter communication

### WordPress.org specific:
- **No tracking without consent** — diagnostic data only collected if user opts in
- **No required registration** — all free features work without email
- **No persistent admin notices** — activation notice shows once, dismissed permanently
- **No "rate us" nag screens** — against WP.org guidelines

---

## 7. Metrics to Track

| Metric | Target | How to measure |
|---|---|---|
| Activation opt-in rate | 40-60% | Freemius dashboard |
| Email open rate | 30-40% | Email provider |
| Email click rate | 5-10% | Email provider |
| Free → Trial conversion | 15-20% | Freemius dashboard |
| Trial → Paid conversion | 25-35% | Freemius dashboard |
| Email-attributed upgrades | 30%+ of all upgrades | UTM tracking on upgrade links |
| Unsubscribe rate | <2% per email | Email provider |
| Spam complaint rate | <0.1% | Email provider |

---

## 8. Is This a Deterrent? Honest Assessment

### What WILL deter users (avoid):
- Popup on install that blocks the UI
- Required email to generate articles
- Repeated nag screens asking for email
- Sending 3+ emails per week
- Generic "subscribe to our newsletter" asks
- Emails that are just "upgrade upgrade upgrade"

### What WILL convert users (do this):
- One non-blocking opt-in at activation (40-60% say yes)
- Context-specific asks after moments of value (score improvement)
- Emails that teach something useful (not just sell)
- Behavioral triggers that feel relevant ("your score improved!")
- Specific offers with real numbers ("$99 off" not "special discount")
- An unsubscribe that actually works, instantly

### The math:
- 1,000 installs/month from WordPress.org
- 50% opt-in at activation = 500 emails captured/month
- 8% free → trial conversion = 40 trials/month
- 30% trial → paid = 12 new Pro users/month
- 12 users × $29/mo = $348 new MRR/month
- After 12 months: ~$25,000 ARR from email alone

**Email is the #1 conversion channel for WordPress plugins.** The key is respect: capture once, deliver value, and let them leave easily.

---

## 9. Implementation Order

1. **Phase 0 (now):** No email capture. Focus on making the plugin work perfectly.
2. **Phase 1 (Freemius integration):** Activation opt-in comes free with Freemius SDK. Zero extra work.
3. **Phase 2 (post-launch):** Add the "first article success" email capture touchpoint.
4. **Phase 3 (1000+ users):** Build the 7-email onboarding drip sequence.
5. **Phase 4 (5000+ users):** Add behavioral triggers and trial activation sequence.

Don't build email infrastructure before you have users. Freemius gives you the foundation for free.
