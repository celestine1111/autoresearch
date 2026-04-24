# SEOBetter Security Architecture

> **Purpose:** master reference for how SEOBetter protects API quotas, resists plugin cracking, and keeps user data safe.
>
> **Status:** Layer 1 shipped in v1.5.211. Layer 2 deferred to Freemius Phase 1. Layer 3 ships with WP.org submission. Layer 4 post-launch.
>
> **Last updated:** 2026-04-24 (v1.5.211)

---

## The hard truth about PHP plugin security

**PHP plugins cannot be 100% protected from reverse engineering.** PHP is interpreted, source is visible, WordPress.org plugin directory bans obfuscated code in the free version. Determined attackers with AI assistance can always read and modify the plugin.

**This isn't the goal.** The goal is:

> Cracking the PHP gives zero Pro features — because all Pro-gated work happens server-side with license verification on every request.

Industry peers (Yoast Premium, RankMath Pro, AIOSEO Pro, WPForms Pro, Gravity Forms) all ship this architecture. Free tier on WP.org is unobfuscated (required). Pro features depend on server-side endpoints that check license on every request. Cracking the PHP doesn't help.

This document covers the layered defences.

---

## Layer 1 — Vercel endpoint hardening (shipped v1.5.211)

### 1a. HMAC request signing — SHIPPED

Every request from the plugin to cloud-api includes:
- `X-Seobetter-Site` — declared home_url()
- `X-Seobetter-Time` — unix timestamp
- `X-Seobetter-Tier` — free / pro / agency
- `X-Seobetter-Version` — plugin version
- `X-Seobetter-Sig` — `sha256=HEX` of HMAC-SHA256(`time.site.tier.body`, SIGNING_SECRET)

Server verifies:
- Signature matches one of currently-accepted secrets (`SEOBETTER_SIGNING_SECRETS` env var, comma-separated, supports graceful rotation)
- Timestamp within 300s (5 min) of now — prevents replay attacks
- Site URL is a valid WP-shaped URL (http/https, real hostname, not localhost / IP / cloud metadata endpoint)
- Tier is one of `free`, `pro`, `agency`

**Signing secret rotation:** When we bump the plugin's constant, keep the old secret in `SEOBETTER_SIGNING_SECRETS` for 7 days so older plugin installs still work while users update. After 7 days, remove old secret → cracked copies using leaked old secrets stop working.

**Implementation:**
- Plugin: [`Cloud_API::sign_request()`](../includes/Cloud_API.php) + `Cloud_API::signed_post()` wrapper
- Vercel: [`cloud-api/api/_auth.js::verifyRequest()`](../cloud-api/api/_auth.js)

**Honest limits:**
- The signing secret lives in PHP source — an attacker who reads the plugin code extracts it trivially. HMAC here is NOT cryptographic security against a determined attacker.
- What it IS: stops random scripts that discover endpoint URLs from burning API quotas. Creates per-installation signal. Rotates per release.
- When Freemius ships (Phase 1), signing secret is replaced by the per-site license key + domain pair, which IS cryptographic.

### 1b. Origin validation — SHIPPED

Replaces prior `Access-Control-Allow-Origin: *` with explicit WP-shaped origin check. Requests must come from a valid WP host (via the same `isValidWpSite()` check). Server-side requests (no Origin header) pass through — `wp_remote_post` doesn't set Origin.

Implementation: [`_auth.js::applyCorsHeaders()`](../cloud-api/api/_auth.js)

### 1c. SSRF prevention in scrape endpoints — SHIPPED

`/api/scrape` now validates input URLs via `isSafeScrapeUrl()`:
- Rejects non-http/https
- Rejects private IPv4 ranges: `10.x`, `172.16-31.x`, `192.168.x`, `127.x`, `169.254.x` (link-local + AWS metadata), `0.x`
- Rejects IPv6 private ranges: `fc00:`, `fe80:`, `::1`
- Rejects cloud metadata endpoints: `169.254.169.254`, `metadata.google.internal`, `metadata.azure.com`
- Rejects localhost, `.local`, `.localhost`
- Max URL length 2048

Implementation: [`_auth.js::isSafeScrapeUrl()`](../cloud-api/api/_auth.js)

### 1d. Input sanitization — SHIPPED

`sanitizeInput()` helper in `_auth.js` validates:
- `keyword` / `niche`: ≤200 chars, no control characters
- `country`: 2-char ISO code regex
- `language`: BCP-47-ish regex
- `domain`: alphanumeric + `_-`, ≤50 chars
- `content_type`: alphanumeric + `_`, ≤50 chars
- `site_url`: `isValidWpSite()`

Endpoints can opt-in per field. Still incremental rollout as we add fields per-endpoint.

### 1e. Rate limiting — DEFERRED to v1.5.212

Current state: in-memory `rateLimitStore = new Map()` per endpoint, resets on Vercel cold start. Attacker can wait ~15 min for cold start to get fresh quota.

v1.5.212 plan: Upstash Redis (free tier 10K commands/day, 256MB) persistent rate limits.
- Key format: `rl:{site_url}:{endpoint}:{YYYY-MM-DD-HH}`
- Free tier: 10 req/hr/endpoint
- Pro tier: 100 req/hr
- Agency tier: unlimited (soft cap via cost circuit breaker)
- Survives serverless cold starts

### 1f. Cost circuit breaker — DEFERRED to v1.5.212

Ships with Upstash Redis since it shares the infrastructure.

Daily $ caps per external API:
- Serper: $20/day
- Firecrawl: $20/day
- Pexels: free tier 20K req/month (tracked)
- OpenRouter: $50/day

Hit cap → endpoint returns 503 with `Retry-After: tomorrow`. Vercel spend alerts at 50% / 80% / 95%.

### 1g. Environment variable hygiene

**Policy:** Every third-party API key (OpenRouter / Anthropic / Groq / Serper / Firecrawl / Pexels), every internal secret (`SEOBETTER_SIGNING_SECRETS`, `SEOBETTER_PRO_KEYS`), and every new env var going forward MUST be created in Vercel with the **Sensitive** flag. Never logged to stdout, never interpolated into responses, never included in error messages.

**Why:** Vercel's default behaviour is to show env var plaintext to anyone with project dashboard access. The Sensitive flag masks the value after first save (`••••••`) and forces delete-and-recreate to change — removing casual exposure via screen shares, team member view, browser history, etc.

**Audit status:**

| Date | Who | Finding | Action |
|---|---|---|---|
| 2026-04-24 | Vercel automated scan | `OPENROUTER_KEY` saved without Sensitive flag — plaintext value visible in dashboard. Pre-existing issue; not introduced by v1.5.211. | Ben rotated key at OpenRouter (revoked old, generated new), deleted+recreated in Vercel with Sensitive flag, redeployed. Plus audited all other env vars and recreated any without Sensitive flag. |

**Procedure when adding a new env var:**
1. Generate / rotate the secret at source
2. Vercel dashboard → Project → Settings → Environment Variables → **Add New**
3. Paste value → ✅ **check Sensitive** → apply to Production (+ Preview/Development if needed)
4. Redeploy
5. Never commit the plaintext value to source — only references like `process.env.MY_KEY`

**Procedure when rotating an exposed secret** (values that were unmasked at any point):
1. Revoke / delete the old key at the provider (OpenRouter dashboard, Serper dashboard, etc.) — not just rename, fully kill it
2. Generate new key → store in password manager
3. In Vercel: delete the existing env var → re-add with Sensitive flag → paste new value
4. Redeploy
5. If the secret is mirrored in plugin source (e.g. `SIGNING_SECRET` in `Cloud_API.php`), bump the plugin constant, release a version update, and follow the 7-day graceful rotation window documented in §1a.

**Limitation for `SEOBETTER_SIGNING_SECRETS`:** this value is mirrored in `Cloud_API.php::SIGNING_SECRET` as a base64 constant. WP.org rules mean the free plugin code must be readable — so the plaintext signing secret is inherently visible to anyone reading the plugin source regardless of the Vercel Sensitive flag. The Sensitive flag still removes the secondary leakage path (Vercel dashboard / screen shares). True cryptographic per-license-key signing ships with Freemius Phase 1 per §2.

---

## Layer 2 — Pro gating architecture (ships with Freemius Phase 1)

**The real anti-crack defence.** Per pro-plan-pricing.md §7, Phase 1 ships Freemius SDK integration AFTER the pre-launch testing mandate passes.

### Rule 1 — Pro features must execute server-side

Anything Pro-only that can be moved to Vercel, should be:

**For the 5 Schema Blocks (v1.5.213):**
- Block edit UI can show locally (free users see locked block in inserter)
- Block render_callback PHP checks `License_Manager::is_pro()` before emitting schema JSON-LD
- Cracking the PHP to bypass `is_pro()` still doesn't help because the cloud-api endpoints verify license via Freemius

**For existing Pro features** (Analyze & Improve inject buttons, Places Pro tiers, AIOSEO auto-pop, etc.):
- Server-side logic lives behind license-verified endpoints (Freemius Phase 1 work)
- UI buttons call the endpoint
- Endpoint returns the fix only if license valid

### Rule 2 — License verification via Freemius

Per [Freemius SDK](https://freemius.com/wordpress/):
- License key format: `sk_live_xxx` tied to site URL (domain-locked)
- Domain-locked: same key won't work on other domains
- Every cloud-api request sent through the new HMAC signing pipeline includes the license key; server calls Freemius verification API (cached 24h)
- Revoked / refunded licenses stop working within 24h grace

---

## Layer 3 — Plugin split architecture (ships with WP.org submission, Phase 2)

**Standard pattern** for paid WP plugins:

### Free plugin `seobetter` — WordPress.org

- Unobfuscated PHP (WP.org rule)
- Free tier features only: Article + FAQ schema, Auto-Suggest, Basic GEO Analyzer, Pexels server-side images, Picsum fallback
- Contains hook stubs for Pro features: `do_action('seobetter_pro_inject_citation', ...)`, etc.
- **No Pro logic** — even if cracked, no Pro capability unlocked

### Pro add-on `seobetter-pro` — Freemius distribution

- Distributed via Freemius downloads only (NOT WP.org — WP.org rules prohibit commercial distribution)
- Can be obfuscated (optional — Freemius recommends against; complicates support without meaningful security benefit)
- Contains all Pro-only PHP: 5 Schema Blocks render_callbacks, Analyze & Improve fix logic, AIOSEO auto-pop, Places Pro tiers (Foursquare / HERE / Google Places), AI Featured Image Pro providers (DALL-E 3 / FLUX Pro)
- Hooks into free plugin's `do_action` stubs
- Freemius Bundle Generator handles the split automatically

### User flow

1. Install free plugin from WP.org → gets free tier
2. Buy Pro via Freemius → Freemius emails download link for `seobetter-pro.zip`
3. Upload Pro add-on → free plugin detects it → Pro features activate

---

## Layer 4 — Anti-tamper + fingerprinting (post-launch hardening)

### Install fingerprinting

- On plugin activation, generate a UUID stored in `wp_options`
- Freemius tracks: UUID + site URL + license key + activation timestamp
- Duplicate UUIDs across different licenses = flag for review
- License used on > allowed sites = auto-downgrade

### Plugin self-hash + tamper detection

- Plugin computes SHA256 of its own PHP files on load
- Hash sent with every cloud-api request (new `X-Seobetter-Hash` header)
- Server compares against known-good hashes per plugin version
- Mismatch = log incident + rate-limit that installation harder
- Doesn't prevent modification, but gives Ben intel on crack attempts

### Runtime license pings

- Every 24h the plugin pings Freemius to confirm license still active
- 48h grace period for offline installs
- Revoked license → UI downgrades to free within 48h

---

## What each environment variable is for

| Var | Required | Purpose |
|---|---|---|
| `SEOBETTER_SIGNING_SECRETS` | YES | Comma-separated list of HMAC signing secrets plugin-to-server auth trusts. Supports rotation (keep old secret for 7 days after rotating). |
| `SEOBETTER_DEV_BYPASS_AUTH` | NO (dev only) | Set to `1` to skip HMAC verification entirely during local dev. **NEVER set in production.** |
| `SERPER_API_KEY` | YES | Google SERP search (content brief + research pipeline) |
| `FIRECRAWL_API_KEY` | Optional | Firecrawl scrape (falls back to Jina Reader without) |
| `PEXELS_API_KEY` | Optional (v1.5.212+) | Server-side Pexels for free-tier featured images |
| `OPENROUTER_API_KEY` | Optional | AI extraction / fallback generation |
| `ANTHROPIC_API_KEY` | Optional | Claude fallback for /api/generate |
| `GROQ_API_KEY` | Optional | Free Llama 3.3 70B for /api/generate |
| `SEOBETTER_PRO_KEYS` | NO (pre-Freemius) | Comma-separated allowlist of Pro license keys before Freemius. Retired in Phase 1. |
| `UPSTASH_REDIS_REST_URL` | v1.5.212+ | Persistent rate limiting + cost circuit breaker |
| `UPSTASH_REDIS_REST_TOKEN` | v1.5.212+ | Auth for Upstash |

---

## What each endpoint does + what auth it requires

| Endpoint | Auth | Input sanitization | SSRF protection |
|---|---|---|---|
| `/api/research` | HMAC + origin | keyword, country, language, domain, content_type | N/A (internal fetches) |
| `/api/content-brief` | HMAC + origin | keyword, country, language | N/A |
| `/api/topic-research` | HMAC + origin | niche, country, language | N/A |
| `/api/generate` | HMAC + origin | (prompt/system_prompt accepted as-is from Pro users) | N/A |
| `/api/validate` | HMAC + origin | license_key (exact match against env allowlist) | N/A |
| `/api/scrape` | HMAC + origin | URL validation via `isSafeScrapeUrl()` | **YES** — private IP + metadata endpoints blocked |
| `/api/pexels` (v1.5.212+) | HMAC + origin | keyword | N/A (Pexels-only URL resolution) |

---

## Incident response (if a key leaks)

### If `SEOBETTER_SIGNING_SECRETS` leaks:
1. Rotate: generate new secret, add to env var BEFORE the leaked one (comma-separated; server accepts both)
2. Bump plugin constant to new secret
3. Release plugin update
4. After 7 days, remove leaked secret from env var → old copies stop working

### If an upstream API key leaks (Serper / Firecrawl / Pexels / OpenRouter):
1. Revoke the leaked key in the provider's dashboard
2. Generate new key
3. Update Vercel env var
4. Redeploy (env var changes require deploy)

### If a crack ships in the wild:
- Rotate `SEOBETTER_SIGNING_SECRETS` (forces all users to update for the old secret to stop working in 7 days)
- Investigate how: which plugin version was modified? Was the signing secret extracted? Are Pro features actually accessible server-side?
- If Freemius is live (Phase 1+), revoke the license if identifiable

---

## Cross-references

- [pro-plan-pricing.md §7 Launch Phases](pro-plan-pricing.md) — when Layer 2 + Layer 3 ship
- [BUILD_LOG v1.5.211](BUILD_LOG.md) — Layer 1 commit record
- [Cloud_API.php](../includes/Cloud_API.php) — plugin-side signing
- [cloud-api/api/_auth.js](../cloud-api/api/_auth.js) — server-side verification

---

*This file is the authoritative spec for SEOBetter security. When shipping new endpoints or security features, update this doc in the same commit.*
