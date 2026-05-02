/**
 * SEOBetter Cloud OAuth proxy — shared helpers (v1.5.216.62)
 *
 * Centralized GSC OAuth proxy. Replaces per-install Google Cloud Console
 * setup. The plugin redirects users here; we hold the verified app
 * credentials and forward back signed tokens.
 *
 * Why this exists: Google requires a verified app for OAuth without the
 * "unverified app" warning. Per-install verification is impossible at
 * scale. Centralizing one verified app fixes that.
 *
 * Security model:
 *   - HMAC-signed state binds (return_url + plugin_csrf + timestamp)
 *   - return_url validated against allowlist of WordPress REST callback paths
 *   - Tokens never exposed in URL params — pickup-token pattern with 5-min
 *     single-use Redis storage retrieves them via authenticated POST
 *   - Refresh endpoint requires the refresh_token itself (which only the
 *     specific install possesses)
 */

import crypto from 'crypto';

const HMAC_SECRET = process.env.GSC_OAUTH_HMAC_SECRET || '';

/**
 * Validate a return_url submitted by the plugin's start request.
 *
 * Must be:
 *   - HTTPS (or http://localhost for dev)
 *   - End in /wp-json/seobetter/v1/gsc/oauth-callback (the plugin's REST route)
 *
 * This prevents the proxy from being abused as an open redirect.
 */
export function isAllowedReturnUrl(url) {
  if (!url || typeof url !== 'string') return false;
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    return false;
  }
  // Allow https everywhere; allow http only for localhost (dev)
  if (parsed.protocol !== 'https:' && !(parsed.protocol === 'http:' && /^localhost(:\d+)?$/.test(parsed.host))) {
    return false;
  }
  // Path must match the plugin's REST callback exactly
  return /\/wp-json\/seobetter\/v1\/gsc\/oauth-callback\/?$/.test(parsed.pathname);
}

/**
 * Sign a state token. Encodes (return_url + pstate + timestamp) into a
 * tamper-evident token Google can echo back to us.
 *
 * Format: <base64url(payload)>.<base64url(hmac)>
 */
export function signState(returnUrl, pstate, timestamp) {
  if (!HMAC_SECRET) {
    throw new Error('GSC_OAUTH_HMAC_SECRET not configured');
  }
  const payload = `${returnUrl}|${pstate}|${timestamp}`;
  const sig = crypto.createHmac('sha256', HMAC_SECRET).update(payload).digest('base64url');
  return `${Buffer.from(payload).toString('base64url')}.${sig}`;
}

/**
 * Verify and decode a state token. Returns null on failure.
 *
 * Default max-age 10 min — matches the OAuth flow window.
 */
export function verifyState(token, maxAgeMs = 10 * 60 * 1000) {
  if (!token || typeof token !== 'string' || !HMAC_SECRET) return null;
  const parts = token.split('.');
  if (parts.length !== 2) return null;
  const [payloadB64, sig] = parts;
  let payload;
  try {
    payload = Buffer.from(payloadB64, 'base64url').toString('utf-8');
  } catch {
    return null;
  }
  const expectedSig = crypto.createHmac('sha256', HMAC_SECRET).update(payload).digest('base64url');
  // Constant-time comparison
  if (sig.length !== expectedSig.length) return null;
  if (!crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expectedSig))) return null;
  const [returnUrl, pstate, timestampStr] = payload.split('|');
  const timestamp = parseInt(timestampStr, 10);
  if (!Number.isFinite(timestamp) || Date.now() - timestamp > maxAgeMs) return null;
  return { returnUrl, pstate, timestamp };
}

/**
 * Standard CORS handling. Returns true if the request was an OPTIONS
 * preflight that the helper has already responded to.
 */
export function handleCors(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') {
    res.status(204).end();
    return true;
  }
  return false;
}

/**
 * Upstash Redis REST helpers — copy of cloud-api/api/_upstash.js helpers
 * scoped to the pickup-token storage. Single import keeps gsc-oauth/
 * self-contained.
 */
const UPSTASH_URL = process.env.UPSTASH_REDIS_REST_URL || '';
const UPSTASH_TOKEN = process.env.UPSTASH_REDIS_REST_TOKEN || '';

export async function redisSet(key, value, ttlSeconds) {
  if (!UPSTASH_URL || !UPSTASH_TOKEN) return false;
  try {
    const args = ['SET', key, value, 'EX', String(ttlSeconds)];
    const resp = await fetch(`${UPSTASH_URL}/${args.map(encodeURIComponent).join('/')}`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${UPSTASH_TOKEN}` },
      signal: AbortSignal.timeout(2000),
    });
    return resp.ok;
  } catch {
    return false;
  }
}

export async function redisGetDel(key) {
  if (!UPSTASH_URL || !UPSTASH_TOKEN) return null;
  try {
    // GETDEL is atomic — single round-trip read+delete
    const resp = await fetch(`${UPSTASH_URL}/getdel/${encodeURIComponent(key)}`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${UPSTASH_TOKEN}` },
      signal: AbortSignal.timeout(2000),
    });
    if (!resp.ok) return null;
    const j = await resp.json();
    return j.result;
  } catch {
    return null;
  }
}
