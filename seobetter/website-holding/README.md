# seobetter.com — holding pages

Static HTML pages to drop in at `seobetter.com` BEFORE the full marketing site is built.

Goal: replace whatever's currently at the domain (the Rollbit casino referral page that surfaced during the v1.5.216.62 GSC OAuth proxy work) with brand-coherent SEOBetter pages so:

1. Visitors who land on the domain see a real product page, not a casino referral
2. Google OAuth verification can use `https://seobetter.com/privacy` and `https://seobetter.com/terms` as the official URLs (Google's verifier hits these — they must resolve to coherent privacy + terms content)
3. The waitlist captures email signups via the existing Google Form (no backend needed)

## Files

| File | Path on seobetter.com | Purpose |
|---|---|---|
| `index.html` | `/` | Holding page — coming-soon hero, data-strip, waitlist CTA, /privacy + /terms links |
| `privacy.html` | `/privacy` (rename or route accordingly) | Privacy Policy mirroring the Cloud API version verbatim |
| `terms.html` | `/terms` | Terms of Service mirroring the Cloud API version verbatim |

Each file is fully self-contained — inline CSS, no external assets, no JS-required rendering. Drop into any static host (Cloudflare Pages, Netlify, Vercel, GitHub Pages, plain old shared hosting). Google's OAuth verifier crawls without JavaScript so static HTML is required.

## Deploy options

### A. Hostinger (you already use it for staging)

Upload via SFTP / hPanel File Manager to `public_html/` of the seobetter.com hosting account. Rename:
- `index.html` stays as `index.html`
- `privacy.html` → either keep as `privacy.html` (URL becomes `seobetter.com/privacy.html`) OR put in `privacy/index.html` so the URL is `seobetter.com/privacy/`. The OAuth consent screen URL must match exactly whichever you choose.
- Same for `terms.html`

For URLs without trailing `.html` (cleaner, recommended), use the `privacy/index.html` + `terms/index.html` directory pattern.

### B. Cloudflare Pages (free, faster than shared hosting)

```bash
cd seobetter/website-holding
# Rename so URLs are clean:
mkdir -p privacy terms
mv privacy.html privacy/index.html
mv terms.html terms/index.html
# Push to a new Cloudflare Pages project, point seobetter.com DNS at it
```

### C. Vercel (already using it for cloud-api)

Same approach as Cloudflare Pages — create a separate Vercel project for the static site, point seobetter.com at it. The Cloud API project (`seobetter-cloud.vercel.app`) and the marketing site are separate projects sharing the same Vercel account.

## URL stability — critical for Google verification

Once Google verifies the SEOBetter OAuth app, the privacy + terms URLs are baked into Google's records. **Do not change them later** without re-submitting verification (~7-30 day wait). When the full marketing site replaces these pages:

- `/privacy` and `/terms` must remain at the same URLs forever
- The full site can update everything else freely
- Reserve the routes; never repurpose for marketing content

## When to retire these pages

When the full SEOBetter marketing site launches, these get replaced by their full-site equivalents. The URLs `/privacy` and `/terms` should keep serving the same canonical content (you can absolutely improve the design / formatting — but the substance must stay aligned with what Google verified).

## Editing notes

If the privacy or terms content needs to change for a new feature or scope, edit BOTH:
1. `cloud-api/api/privacy.js` (the Cloud API mirror — same content, served at the cloud-api domain as a fallback)
2. `website-holding/privacy.html` (this file)

Keep them byte-equivalent in substance. Out-of-sync versions across the two URLs will trigger Google verification re-review.
