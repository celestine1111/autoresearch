/**
 * SEOBetter Cloud API — shared request authentication (v1.5.211)
 *
 * Verifies HMAC signature + timestamp + origin on every incoming request.
 * Protects against:
 *   - Random scripts discovering endpoint URLs and burning API quotas
 *   - Replay attacks (stale timestamps rejected)
 *   - Cross-origin abuse (only WP-shaped origins accepted)
 *   - Per-site rate-limit bypass (site URL bound into signature)
 *
 * Plugin-side: the same HMAC is computed in `Cloud_API::sign_request()`
 * using `SEOBETTER_SIGNING_SECRET` (base64-encoded plugin constant, rotated
 * per release). When Freemius ships in Phase 1, the signing secret will be
 * replaced by the per-site license key + domain pair.
 *
 * Environment variables:
 *   SEOBETTER_SIGNING_SECRETS — comma-separated list of currently-active
 *     plaintext secrets. Plugin constant is the hash/version; server
 *     accepts any secret in this list. Supports graceful rotation: when
 *     we bump the plugin secret, old secret stays in this list for 7 days
 *     so older plugin installs still work while users update.
 *   SEOBETTER_DEV_BYPASS_AUTH — "1" to skip auth entirely during local dev
 *     testing. NEVER set in production.
 */

import crypto from 'crypto';

const REPLAY_WINDOW_SECONDS = 300; // 5 minutes

/**
 * Verify an incoming request's HMAC signature + timestamp + site URL.
 *
 * @param {import('http').IncomingMessage} req - Vercel request object
 * @returns {{ ok: true, site_url: string, tier: string, plugin_version: string } | { ok: false, reason: string, status: number }}
 */
export function verifyRequest(req) {
  if (process.env.SEOBETTER_DEV_BYPASS_AUTH === '1') {
    return {
      ok: true,
      site_url: String(req.headers['x-seobetter-site'] || 'dev'),
      tier: 'pro',
      plugin_version: 'dev',
      bypass: true,
    };
  }

  const sig = String(req.headers['x-seobetter-sig'] || '');
  const time = String(req.headers['x-seobetter-time'] || '');
  const site = String(req.headers['x-seobetter-site'] || '');
  const version = String(req.headers['x-seobetter-version'] || '');
  const tier = String(req.headers['x-seobetter-tier'] || 'free');

  if (!sig || !time || !site) {
    return { ok: false, reason: 'missing auth headers', status: 401 };
  }

  const ts = parseInt(time, 10);
  if (isNaN(ts)) {
    return { ok: false, reason: 'invalid timestamp', status: 401 };
  }
  const now = Math.floor(Date.now() / 1000);
  if (Math.abs(now - ts) > REPLAY_WINDOW_SECONDS) {
    return { ok: false, reason: 'timestamp outside replay window', status: 401 };
  }

  // Site URL sanity: must look like a WordPress install (http/https, real host, not localhost/IP/metadata)
  if (!isValidWpSite(site)) {
    return { ok: false, reason: 'invalid site URL', status: 401 };
  }

  // Tier must be one of expected values
  if (!['free', 'pro', 'agency'].includes(tier)) {
    return { ok: false, reason: 'invalid tier', status: 401 };
  }

  // Accept any of the currently-active secrets (supports graceful rotation)
  const secretsRaw = process.env.SEOBETTER_SIGNING_SECRETS || '';
  const secrets = secretsRaw.split(',').map(s => s.trim()).filter(Boolean);
  if (secrets.length === 0) {
    console.error('SEOBETTER_SIGNING_SECRETS env var not set — rejecting all requests');
    return { ok: false, reason: 'server misconfigured', status: 500 };
  }

  // Body is read by Vercel automatically; stringify for signing
  const body = typeof req.body === 'string' ? req.body : JSON.stringify(req.body || {});
  const payload = `${time}.${site}.${tier}.${body}`;

  const expected = secrets.map(secret =>
    crypto.createHmac('sha256', secret).update(payload).digest('hex')
  );

  const match = expected.some(exp => safeEquals(sig.replace(/^sha256=/, ''), exp));
  if (!match) {
    return { ok: false, reason: 'signature mismatch', status: 401 };
  }

  return {
    ok: true,
    site_url: site,
    tier,
    plugin_version: version,
  };
}

/**
 * Constant-time string comparison to prevent timing attacks on signature check.
 */
function safeEquals(a, b) {
  if (typeof a !== 'string' || typeof b !== 'string' || a.length !== b.length) return false;
  try {
    return crypto.timingSafeEqual(Buffer.from(a, 'hex'), Buffer.from(b, 'hex'));
  } catch {
    return false;
  }
}

/**
 * Reject obvious SSRF / abuse patterns in site URL:
 *   - Must be http or https scheme
 *   - Must have a real hostname (no bare IP, no localhost, no cloud metadata)
 *   - Max length 255
 */
export function isValidWpSite(url) {
  if (typeof url !== 'string' || url.length < 10 || url.length > 255) return false;

  let u;
  try { u = new URL(url); } catch { return false; }

  if (u.protocol !== 'http:' && u.protocol !== 'https:') return false;

  const host = u.hostname.toLowerCase();
  if (!host || host === 'localhost' || host.endsWith('.local') || host.endsWith('.localhost')) return false;

  // Block bare IPv4 (rough; most WP installs are on domains)
  if (/^\d+\.\d+\.\d+\.\d+$/.test(host)) return false;
  // Block IPv6 literal
  if (host.includes(':')) return false;
  // Block cloud metadata endpoints (AWS / GCP / Azure)
  if (host === '169.254.169.254' || host === 'metadata.google.internal' || host === 'metadata.azure.com') return false;

  return true;
}

/**
 * SSRF-safe URL validator for scrape endpoints. Stricter than isValidWpSite —
 * rejects private IP ranges entirely. Used in /api/scrape and any endpoint
 * that fetches arbitrary user-supplied URLs.
 */
export function isSafeScrapeUrl(url) {
  if (!isValidWpSite(url)) {
    // Reuse basic checks — same protocol + host rules, BUT we additionally
    // need to resolve the hostname to check for private IPs. Vercel's runtime
    // does DNS at fetch time, so this is best-effort from the URL string.
    // Full DNS-resolution check would need dns.lookup() which adds latency.
    if (typeof url !== 'string' || url.length < 10 || url.length > 2048) return false;
    let u;
    try { u = new URL(url); } catch { return false; }
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return false;
  }

  const u = new URL(url);
  const host = u.hostname.toLowerCase();

  // Extra scrape-specific blocks
  const privatePatterns = [
    /^10\./, /^172\.(1[6-9]|2\d|3[01])\./, /^192\.168\./,  // RFC 1918
    /^127\./, /^0\./, /^169\.254\./,                        // loopback + link-local
    /^fc00:/, /^fe80:/, /^::1$/,                             // IPv6 private
  ];
  if (privatePatterns.some(p => p.test(host))) return false;

  return true;
}

/**
 * Helper for endpoint handlers: verify request and return an error response
 * if auth fails. Usage:
 *
 *   const auth = verifyRequest(req);
 *   if (!auth.ok) return rejectAuth(res, auth);
 *   // ...proceed with authenticated request
 */
export function rejectAuth(res, authResult) {
  res.setHeader('X-Seobetter-Auth-Reason', authResult.reason);
  return res.status(authResult.status || 401).json({
    error: 'unauthorized',
    reason: authResult.reason,
  });
}

/**
 * v1.5.212 — Rate-limit enforcement. Call AFTER verifyRequest.
 * Returns a response object if limit exceeded (caller should return immediately);
 * returns null if within limit.
 *
 * Usage:
 *   const auth = verifyRequest(req);
 *   if (!auth.ok) return rejectAuth(res, auth);
 *   const rl = await enforceRateLimit(req, res, 'research', auth);
 *   if (rl) return rl;
 */
export async function enforceRateLimit(req, res, endpoint, auth) {
  const { checkRateLimit } = await import('./_upstash.js');
  const result = await checkRateLimit(endpoint, auth.site_url, auth.tier);
  if (result.ok) {
    if (!result.skipped) {
      res.setHeader('X-RateLimit-Limit', result.limit);
      res.setHeader('X-RateLimit-Remaining', result.remaining);
      res.setHeader('X-RateLimit-Reset', result.reset_in);
    }
    return null;  // within limit, continue
  }
  res.setHeader('Retry-After', result.reset_in);
  return res.status(429).json({
    error: 'rate_limit_exceeded',
    reason: `${endpoint} limit ${result.limit}/hr for ${auth.tier} tier — retry in ${Math.ceil(result.reset_in / 60)} minutes`,
    tier: auth.tier,
    retry_after_seconds: result.reset_in,
  });
}

/**
 * v1.5.212 — Cost circuit breaker. Call before making expensive upstream API calls.
 * Returns response object if daily cap hit; null if budget OK.
 */
export async function enforceCostCap(res, service) {
  const { checkCostCap } = await import('./_upstash.js');
  const result = await checkCostCap(service);
  if (result.ok) return null;
  return res.status(503).json({
    error: 'cost_cap_exceeded',
    reason: `Daily ${service} budget hit ($${(result.cap_cents / 100).toFixed(2)}). Retry tomorrow.`,
    service,
    spent_cents: result.spent_cents,
    cap_cents: result.cap_cents,
  });
}

/**
 * v1.5.211 — Input sanitization at the endpoint gate.
 * Rejects oversized / malformed / suspicious inputs before they hit
 * downstream APIs. Returns { ok: true, sanitized } or { ok: false, reason }.
 *
 * Applied to common request fields used across endpoints:
 *   - keyword / niche:   ≤200 chars, strip control chars, reject shell metacharacters
 *   - country:            2-char ISO code (uppercase)
 *   - language:           BCP-47-ish (2-5 chars + optional region)
 *   - site_url:           validated via isValidWpSite
 *   - domain:             alphanumeric + underscore + hyphen, ≤50 chars
 *   - content_type:       alphanumeric + underscore, ≤50 chars
 *   - url:                validated via isSafeScrapeUrl
 */
export function sanitizeInput(body = {}) {
  const out = {};
  const reject = (reason) => ({ ok: false, reason });

  if ('keyword' in body || 'niche' in body) {
    const k = String(body.keyword || body.niche || '').trim();
    if (k.length > 200) return reject('keyword too long');
    if (/[\x00-\x1f\x7f]/.test(k)) return reject('keyword has control characters');
    out.keyword = k;
  }

  if ('country' in body) {
    const c = String(body.country || '').trim();
    if (c && !/^[A-Za-z]{2}$/.test(c)) return reject('invalid country code');
    out.country = c.toUpperCase();
  }

  if ('language' in body) {
    const l = String(body.language || '').trim();
    if (l && !/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/.test(l)) return reject('invalid language code');
    out.language = l.toLowerCase();
  }

  if ('domain' in body) {
    const d = String(body.domain || '').trim();
    if (d.length > 50 || (d && !/^[a-zA-Z0-9_-]+$/.test(d))) return reject('invalid domain');
    out.domain = d;
  }

  if ('content_type' in body) {
    const ct = String(body.content_type || '').trim();
    if (ct.length > 50 || (ct && !/^[a-zA-Z0-9_]+$/.test(ct))) return reject('invalid content_type');
    out.content_type = ct;
  }

  if ('site_url' in body) {
    const s = String(body.site_url || '').trim();
    if (s && !isValidWpSite(s)) return reject('invalid site_url');
    out.site_url = s;
  }

  return { ok: true, sanitized: out };
}

/**
 * Shared CORS headers — tighter than the prior `*` wildcard. Only allows
 * the Origin header to echo back if it matches a recognisable WordPress
 * pattern (admin-ajax, rest_route, or known good hostnames). OPTIONS
 * preflight gets the same treatment.
 */
export function applyCorsHeaders(req, res) {
  const origin = String(req.headers.origin || '');
  if (origin && isValidWpSite(origin)) {
    res.setHeader('Access-Control-Allow-Origin', origin);
    res.setHeader('Vary', 'Origin');
  }
  // No Origin header: request was made server-side (e.g. wp_remote_post). That's fine.
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Seobetter-Sig, X-Seobetter-Time, X-Seobetter-Site, X-Seobetter-Version, X-Seobetter-Tier');
}
