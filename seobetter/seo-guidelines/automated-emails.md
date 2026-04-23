# SEOBetter Automated Email System

> **Purpose:** Single source of truth for every email the plugin ever sends to a user — from transactional confirmations to product updates, milestone celebrations, and lifecycle nurture. Separate from `email-marketing.md` which covers OUTBOUND marketing campaigns (how we attract and convert).
>
> **Status:** PLANNED — one automated email existed (Decay Alerts) and was disabled 2026-04-23 (v1.5.206d-fix17) because it sent without explicit opt-in. No automated emails fire currently. Everything below is the designed pipeline, shipped in phases.
>
> **Last updated:** 2026-04-23
>
> **Related docs:**
> - [`email-marketing.md`](email-marketing.md) — outbound marketing strategy, drip campaigns, email capture touchpoints, Freemius integration
> - [`pro-features-ideas.md`](pro-features-ideas.md) — Pro-tier features that use email as a channel (AI Citation Tracker, Weekly Digest, etc.)
> - [`plugin_UX.md`](plugin_UX.md) — UI surfaces that present email opt-ins and preference management

---

## 1. The Core Principle — Plumbing vs. Marketing

Two email systems, two files, one pipeline:

| Layer | Purpose | Documented in |
|---|---|---|
| **Transactional / Operational** | Plugin → user emails that carry value the user expects (license keys, confirmations, opt-in-triggered alerts) | This file |
| **Marketing / Nurture** | Drip campaigns, behavioral triggers, launch announcements, conversion offers | `email-marketing.md` |

**They share the same Freemius email backend**, the same unsubscribe registry, the same consent tracking. One user toggle in Settings → Email Preferences controls participation in each category independently.

**The WordPress.org rule (same as `email-marketing.md §1`):** No email without explicit opt-in. No blocking UI to force opt-in. No persistent nag screens. Every email has a single-click unsubscribe.

---

## 2. Categories of Automated Emails

Every email the plugin will ever send falls into one of 8 categories. Each category has its own opt-in toggle in Settings → Email Preferences.

### Category 1 — Transactional (CORE, always on when triggered by user action)

User-initiated events that REQUIRE a confirmation email. Cannot be opted out of (standard GDPR carve-out for "emails necessary to fulfil a contract").

| # | Email | Trigger | Gated by |
|---|---|---|---|
| T1 | License key delivery | Freemius checkout succeeds | Purchase |
| T2 | License renewal confirmation | Freemius renewal succeeds | Purchase |
| T3 | License expired (functional) | License expiry date reached | License state |
| T4 | Password reset | User clicks "Forgot password" on seobetter.com | User action |
| T5 | Email change confirmation | User updates email in Freemius account | User action |
| T6 | Payment failure | Freemius retry cycle exhausted | Billing state |
| T7 | Refund processed | Freemius refund succeeds | Billing state |

**Source of truth:** Freemius handles all 7. Zero custom code required.

### Category 2 — Onboarding (opt-in at activation, one-time sequence)

Delivered once after first install. Users opt in via Freemius activation notice (40–60% opt-in per `email-marketing.md §2`). Triggered by install-date + user actions. Lives in the `email-marketing.md §3 Sequence A` drip but fires from the automation pipeline.

| # | Email | Trigger | Frequency |
|---|---|---|---|
| O1 | Welcome + setup checklist | Plugin activated | Day 0, once |
| O2 | "Your first article — what the GEO score means" | First article generated OR Day 1 if not | Once |
| O3 | "3 things that make AI cite your content" | Day 3 | Once |
| O4 | "How [site] went from 68 to 94 in one click" | Day 7 | Once |
| O5 | "Your articles vs the competition" | Day 14 | Once |
| O6 | "Are AI search engines finding your content?" | Day 21 | Once |
| O7 | "Last chance: $99 off annual Pro" | Day 30 | Once |

### Category 3 — Behavioral Milestones (opt-in, fires on user action)

Celebrates meaningful usage milestones. Each milestone sent ONCE per user. Reinforces value; drives upsells at moments of dopamine.

| # | Email | Trigger |
|---|---|---|
| B1 | "Your 1st article is live" | First article saved as a WordPress post |
| B2 | "5 articles generated — you're a pattern" | 5th article saved |
| B3 | "10 articles — you're a power user" | 10th article saved (opens Agency upsell pitch) |
| B4 | "You hit 90+" | First article scored ≥90 |
| B5 | "You maxed out 100" | First article scored 100 |
| B6 | "You used Optimize All" | First Optimize All completion |
| B7 | "Your article got cited by AI" | AI Citation Tracker detects a citation *(Pro feature)* |
| B8 | "Your first Recipe article is live" | First Recipe content-type article |

**Frequency cap:** 2 milestone emails per week max, per user. Oldest queued wins.

### Category 4 — Trial Lifecycle (opt-in, 5-email sequence during trial)

Gets trial users to the "aha moment" before trial expires. Lives in the `email-marketing.md §3 Sequence B` drip but fires from the automation pipeline.

| # | Email | Trigger |
|---|---|---|
| TR1 | "Your Pro trial is active" | Trial start |
| TR2 | "Try a Buying Guide — 33% of AI citations are comparisons" | Day 1 |
| TR3 | "4 days left" | Day 3 |
| TR4 | "2 days left — here's what you'll lose" | Day 5 |
| TR5 | "Your trial ended" | Day 7 + trial-end day |
| TR6 | "Your trial ended — 20% off if you upgrade today" | Day 8 |

### Category 5 — License Renewal (annual subscribers only)

Automatic, driven by license expiry date. Freemius sends these by default; the plugin's automation layer adds context-aware copy.

| # | Email | Trigger |
|---|---|---|
| R1 | "Your license renews in 30 days — here's what you got this year" | Expiry date − 30d |
| R2 | "Renewing in 7 days" | Expiry date − 7d |
| R3 | "Your license expired" | Expiry date − 0d |
| R4 | "Renew now for 20% off" | Expiry date + 7d (win-back) |

### Category 6 — Usage Digest (opt-in, recurring summary)

Single-toggle opt-in. Recipient chooses weekly or monthly cadence in Settings → Email Preferences. **This is the replacement for the old Decay Alert email** — it rolls up stale posts, score drops, AI citation events, and top-performing articles into one useful digest instead of a single-metric nag.

| # | Email | Trigger | Frequency |
|---|---|---|---|
| U1 | "Your SEOBetter weekly digest" | Weekly opt-in + cron | Once per week |
| U2 | "Your SEOBetter monthly digest" | Monthly opt-in + cron | Once per month |

**Digest contents (both cadences):**

1. Top 3 articles by GEO score this period
2. Articles that went stale (>6 months old, score dropped)
3. Articles that gained score (e.g. from Content Updater runs)
4. AI citation events detected (Pro feature)
5. New features shipped this period (one-line changelog)
6. Suggested next action (based on score/citation patterns)

Single preferences toggle + one-click unsubscribe. Never fires without opt-in.

### Category 7 — Product Updates (opt-in, irregular cadence)

Announcements of new major features, breaking changes, or plugin updates that affect user workflow. Maximum 1 email per month; silence months when nothing substantial shipped.

| # | Email | Trigger |
|---|---|---|
| P1 | "New in SEOBetter: [feature]" | Major-version release (e.g. v1.6.0) |
| P2 | "Action required: [change]" | Breaking change that affects user data (rare) |
| P3 | "WordPress compatibility update" | WordPress version released that affects plugin operation |

### Category 8 — Agency / Team Updates (Pro Agency plan only, opt-in)

For the Agency tier, emails covering client sites.

| # | Email | Trigger |
|---|---|---|
| A1 | "Client site ready: [site]" | Agency user adds a new client site |
| A2 | "Client site issue: [site]" | Client site encounters an error or score drop |
| A3 | "Monthly client report: [site]" | Monthly, per client site |

---

## 3. User Consent & Preferences

### Settings → Email Preferences (new UI, required for phase 2+)

A new card on the plugin Settings page with one toggle per category (except T = transactional, always on). User's choice persists in `seobetter_settings['email_preferences']` as a per-category array:

```php
'email_preferences' => [
    'onboarding' => true,
    'milestones' => true,
    'trial' => true,
    'renewal' => true,
    'digest' => false,      // default OFF
    'digest_cadence' => 'weekly',  // 'weekly' | 'monthly'
    'product_updates' => true,
    'agency' => false,
]
```

Every email contains a one-click link to this settings page (`/wp-admin/admin.php?page=seobetter-settings#email`) plus a category-specific unsubscribe link that flips that category's toggle to `false` without requiring login.

### Freemius Integration

Freemius already handles T1–T7 transactional emails. For the other 7 categories, we use Freemius's webhook hooks:
- `freemius_after_email_sent` — track which email actually delivered
- `freemius_email_preferences_changed` — mirror user preference changes back to `seobetter_settings`
- `freemius_unsubscribe` — one-click unsubscribe link that removes the user from all marketing + milestone + digest mail

### GDPR Compliance

Same non-negotiables as `email-marketing.md §6`:

- Explicit opt-in per category (unchecked by default for everything except T)
- One-click unsubscribe in every email
- Data portability — user can export their email event log
- Data deletion — user can request deletion via email
- No third-party sharing
- Privacy policy link in every email footer
- Transactional emails T1–T7 are the only category sent without explicit opt-in (GDPR contract-necessity carve-out)

---

## 4. Technical Architecture

### 4.1 Delivery Pipeline

```
┌─────────────────────────────────────────────────────────────┐
│  TRIGGER SOURCES                                             │
│  - WP cron (digest, renewal, trial day-marks)                │
│  - User action hooks (article saved, Optimize All clicked)   │
│  - Freemius webhooks (license purchase, trial state, etc.)   │
└──────────────┬──────────────────────────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────────────────────────┐
│  EMAIL ROUTER (new: includes/Email_Router.php)              │
│  - Determines category from trigger                          │
│  - Checks per-user preferences (opt-in toggles)              │
│  - Checks frequency caps (e.g. 2 milestones/week max)        │
│  - Deduplicates (one-time emails only fire once ever)        │
│  - Queues outgoing mail via Freemius API                     │
└──────────────┬──────────────────────────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────────────────────────┐
│  FREEMIUS SEND                                               │
│  - Template rendered with user-specific data                 │
│  - One-click unsubscribe token embedded                      │
│  - Event logged to seobetter_email_events option             │
└─────────────────────────────────────────────────────────────┘
```

### 4.2 Files Required (planned)

| File | Responsibility |
|---|---|
| `includes/Email_Router.php` | Single entry point for all automated emails. Enforces opt-in, caps, dedup |
| `includes/Email_Templates.php` | HTML + plain-text templates, one method per email ID |
| `includes/Email_Event_Log.php` | Records every send: `{ email_id, user_id, timestamp, status }` |
| `includes/Email_Preferences.php` | User preference read/write (reads from `seobetter_settings`, writes via Settings UI) |
| `admin/views/settings-email-preferences.php` | New card on Settings page for the 7 opt-in toggles |
| `cloud-api/api/email-unsubscribe.js` | Vercel endpoint for one-click unsubscribe tokens |

### 4.3 Preference Change Hooks

Every user preference change fires `do_action('seobetter_email_preferences_changed', $user_id, $old_prefs, $new_prefs)`. Lets Pro features (AI Citation Tracker, Content Decay alerts) react to opt-in/out without polling.

### 4.4 Email Event Log (admin-visible, GDPR-compliant)

`seobetter_email_events` option holds the last 100 email events per user:

```php
[
  [ 'email_id' => 'O3', 'sent_at' => '2026-04-23 10:15:32', 'delivered' => true ],
  [ 'email_id' => 'B1', 'sent_at' => '2026-04-21 18:03:47', 'delivered' => true ],
  [ 'email_id' => 'U1', 'sent_at' => '2026-04-14 06:00:00', 'delivered' => true ],
  ...
]
```

Admin view under Settings → Email Preferences: "Recent emails sent to you" list with timestamp + category. GDPR compliance baked in — user can see exactly what they've received.

---

## 5. The Killed Decay Alert Email — What Replaces It

The old `Decay_Alert_Manager` sent a **weekly `[SEOBetter] Content alert: N stale posts, M score drops`** email to every admin without explicit opt-in. That's what was reaching Ben (and every other user). It violated `email-marketing.md §1/§6` (non-consensual) and `WordPress.org plugin guidelines` (no emails without opt-in).

**Fix shipped in v1.5.206d-fix17:** default is now `false`. The weekly cron still schedules but the check returns immediately without sending anything.

**What replaces it:**

1. **Category 6 Usage Digest (U1/U2)** — optional weekly or monthly roll-up email that INCLUDES stale-post detection and score-drop detection as two signals out of many. Never fires without explicit opt-in. Actionable copy ("here are your top 3 articles, here's one that went stale, here's the quick fix") instead of the old "100 stale posts" nag.
2. **Pro feature: real-time AI Citation Alerts** — user opts in, gets an email the moment ChatGPT/Perplexity/Gemini is detected citing their article. Dopamine email, not nag email. Tied to the AI Citation Tracker Pro feature in `pro-features-ideas.md`.
3. **In-app indicator** — stale-post count appears as a badge in the plugin admin dashboard (not emailed). User sees it when they open the admin. No inbox pollution.

---

## 6. Email Capture — How Users End Up on These Lists

References `email-marketing.md §2` touchpoints but listed here for the automation-pipeline perspective:

### Capture Point 1 — Freemius Activation Opt-In
- Single non-blocking admin notice at first activation
- Checkbox: "Send me product updates and weekly tips"
- Captures email + opts user into Categories 2 (Onboarding) and 7 (Product Updates) by default
- Skippable with zero penalty — plugin works 100% normally if declined

### Capture Point 2 — Post-Article Success Banner
- Appears once in the results panel after user's first article
- Inline email input + "Get weekly GEO score tips" CTA
- Captures email + opts user into Category 6 (Usage Digest, weekly default)
- Dismissible permanently

### Capture Point 3 — Optimize All Success
- Appears once after user's first Optimize All run
- Inline email input + "Send me a weekly report of my articles' scores" CTA
- Captures email + opts user into Category 6 (Usage Digest)

### Capture Point 4 — Score Milestone (Pro)
- When user's article first hits 90+
- "Your article is in the top 10% for AI citability. Want alerts when AI engines cite it?"
- Captures email + opts user into Category 3 (Milestones — just the AI citation sub-category)
- Pro-only (requires AI Citation Tracker Pro feature)

### Capture Point 5 — Website — On-Site Form
- seobetter.com landing page or pricing page
- "Get the free GEO checklist" lead magnet (downloadable PDF)
- Captures email + opts user into Category 2 (Onboarding)
- Delivers the lead magnet in email O1 replacement

### Capture Point 6 — Feature Request Submission
- Canny.io board on seobetter.com/feature-requests
- Submitting/voting requires email
- Captures email + opts into Category 7 (Product Updates)

### Capture Point 7 — Documentation / Changelog Subscribe
- "Subscribe to updates" link in footer of docs + changelog pages
- Captures email + opts into Category 7 (Product Updates only)

### Capture Point 8 — Freemius Checkout
- Email required for purchase (Freemius handles)
- Captures email + opts into Categories 1 (transactional), 4 (trial lifecycle), 5 (renewal)
- Post-purchase: user sees "Also email me weekly digest? [toggle]"

### Capture Point 9 — Affiliate Program Sign-Up (later)
- seobetter.com/affiliates signup
- Captures email + opts into Category 8 variant (agency/partner updates)

---

## 7. Cross-reference — Email Behaviors Tied to Pro Features

Pro features that drive email automation (documented in `pro-features-ideas.md`):

| Pro feature | Emails it generates |
|---|---|
| **AI Citation Tracker** | B7 "Your article got cited by AI" + real-time alert sub-category under Category 3 |
| **Content Decay Alerts** | U1/U2 digest now contains decay signals (replaces old standalone email) |
| **Content Freshness Analyzer** | U1/U2 digest surfaces freshness scores; Pro digest also links to Content Updater for one-click refresh |
| **Keyword Cannibalization Detector** | U1/U2 digest includes cannibalization findings when detected |
| **Weekly / Monthly Digest (new, Pro-leaning)** | Category 6 U1/U2 — free tier gets monthly, Pro tier gets weekly + richer data |
| **AI Citation Tracker (real-time)** | Bumps B7 into "email within 5 minutes of citation detected" latency (free tier gets next-digest batch) |
| **White-label Email Branding (Agency)** | Category 8 emails re-branded with agency's logo + colors |
| **Team Member Invites (Agency)** | New sub-category — team invite emails, role change notifications |

Each Pro feature's section in `pro-features-ideas.md` links back here for its email surfaces.

---

## 8. Phased Rollout

Ship the pipeline incrementally. Don't build all 8 categories before launch.

### Phase 1 — v1.6.0 (first real release): Categories 1 + 7

- T1–T7 via Freemius (zero new code)
- P1–P3 via Freemius broadcast emails (ad hoc, no scheduling)
- No opt-in UI yet — Freemius handles Category 1 consent at activation
- No WordPress.org risk: both categories are consensual or user-initiated

### Phase 2 — v1.6.1 (~1 month post-launch): Category 2 Onboarding + Settings → Email Preferences UI

- Build `Email_Router.php`, `Email_Templates.php`, `Email_Event_Log.php`
- Ship the 7 onboarding emails O1–O7
- Ship the Settings → Email Preferences card with 3 toggles (onboarding, milestones, updates)
- Users can now control which emails they receive

### Phase 3 — v1.6.2 (~2–3 months post-launch): Categories 3 + 6 Milestones + Digest

- Behavioral triggers hooked into existing plugin events (article saved, Optimize All clicked, score threshold)
- Weekly/monthly digest cron + template
- Email digest REPLACES the killed Decay Alert as the stale-post surface
- Pro tier unlocks weekly cadence + richer digest contents

### Phase 4 — v1.7.0+: Categories 4 + 5 + 8 Trial + Renewal + Agency

- Trial lifecycle emails fire from Freemius trial-state webhooks
- Renewal nudges from Freemius expiry webhooks
- Agency emails ship when Agency tier exists

---

## 9. Metrics & Targets

Match `email-marketing.md §7` but focused on the automation layer:

| Metric | Target | Reasoning |
|---|---|---|
| Opt-in rate at activation | 40–60% | Freemius benchmark |
| Onboarding delivery completion (all 7 emails) | ≥85% | Good deliverability + low unsubscribe during onboarding |
| Unsubscribe rate per email | <1% | Healthy; >2% means the email is irritating |
| Digest open rate | 35–45% | Digests with useful content get opened |
| Milestone email open rate | 50–60% | These are "congratulations" emails — high open rate |
| Category-specific unsubscribe rate | <5% cumulative | Users using granular unsubscribe rather than blanket |
| Spam complaint rate | <0.1% | Industry standard |
| Average emails per user per month | ≤4 | Respectful cadence |

If any metric breaches target for 2 consecutive cycles, revisit content/frequency for that category.

---

## 10. What We're Explicitly NOT Doing

- ❌ Sending without explicit opt-in (except T1–T7 transactional)
- ❌ Daily digests (weekly/monthly only — daily = irritating)
- ❌ "Rate us" nag emails (banned by WP.org guidelines and low-value)
- ❌ Re-engagement emails to long-unsubscribed users (once gone, gone)
- ❌ Cross-selling other products (only SEOBetter features, only to SEOBetter users)
- ❌ Branded marketing emails from the plugin backend (marketing lives in Freemius or the website mailing list, never in the automation pipeline)
- ❌ Tracking pixels that fire without consent (only click tracking on user-initiated unsubscribe/preference links)

---

## 11. Cross-references

- [`email-marketing.md`](email-marketing.md) — outbound marketing strategy, Freemius integration details, WP.org compliance rationale
- [`pro-features-ideas.md`](pro-features-ideas.md) — Pro features that drive email automation (AI Citation Tracker, Content Freshness, etc.)
- [`website-ideas.md`](website-ideas.md) — on-site email capture points, landing pages per feature
- [`plugin_UX.md`](plugin_UX.md) — UI surfaces (Settings → Email Preferences card, opt-in banners)
- [`pro-plan-pricing.md`](pro-plan-pricing.md) — tier gating (digest cadence, white-label branding) ties to pricing

---

*This file is the designed automated-email pipeline. Update as categories ship. When a category goes live, mark status and add its BUILD_LOG commit anchor.*
