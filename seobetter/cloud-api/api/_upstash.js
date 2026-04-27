/**
 * SEOBetter Cloud API — Upstash Redis client (v1.5.212)
 *
 * Persistent rate limiting + cost circuit breaker. Replaces the in-memory
 * `Map()` rate limits (which reset on Vercel cold start — attacker just
 * waits ~15 min for fresh quota).
 *
 * Why Upstash:
 *   - HTTP-based API (no persistent TCP connection needed — works in
 *     serverless without connection pooling)
 *   - Free tier: 10K commands/day, 256MB storage — plenty for rate limiting
 *   - Survives Vercel cold starts
 *   - Zero ops
 *
 * Environment variables:
 *   UPSTASH_REDIS_REST_URL    — https://xxxx.upstash.io
 *   UPSTASH_REDIS_REST_TOKEN  — Upstash REST API token
 *
 * Fail-open: if Upstash is down or not configured, functions return
 * `{ ok: true, skipped: true }` so the plugin keeps working. In-memory
 * fallback is gone but endpoints shouldn't hard-fail — Upstash outage
 * shouldn't 500 the user's article generation.
 */

const UPSTASH_URL = process.env.UPSTASH_REDIS_REST_URL || '';
const UPSTASH_TOKEN = process.env.UPSTASH_REDIS_REST_TOKEN || '';

function isConfigured() {
  return UPSTASH_URL && UPSTASH_TOKEN;
}

/**
 * Execute a Redis command via Upstash REST API.
 * @param {string[]} args - Redis command + arguments, e.g. ['INCR', 'rl:key']
 * @returns {Promise<any>} - Redis result, or null on error
 */
async function redis(args) {
  if (!isConfigured()) return null;
  try {
    const resp = await fetch(`${UPSTASH_URL}/${args.map(encodeURIComponent).join('/')}`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${UPSTASH_TOKEN}` },
      signal: AbortSignal.timeout(2000),
    });
    if (!resp.ok) {
      console.error(`upstash ${args[0]} failed: ${resp.status}`);
      return null;
    }
    const j = await resp.json();
    return j.result;
  } catch (e) {
    console.error(`upstash ${args[0]} error:`, e.message || e);
    return null;
  }
}

/**
 * Pipeline multiple Redis commands in one HTTP call. Used for INCR+EXPIRE
 * atomically on rate-limit key creation.
 * @param {string[][]} commands - Array of command arrays
 * @returns {Promise<any[]|null>}
 */
async function redisPipeline(commands) {
  if (!isConfigured()) return null;
  try {
    const resp = await fetch(`${UPSTASH_URL}/pipeline`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${UPSTASH_TOKEN}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(commands),
      signal: AbortSignal.timeout(2000),
    });
    if (!resp.ok) {
      console.error(`upstash pipeline failed: ${resp.status}`);
      return null;
    }
    const j = await resp.json();
    return Array.isArray(j) ? j.map(r => r.result) : null;
  } catch (e) {
    console.error('upstash pipeline error:', e.message || e);
    return null;
  }
}

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------

/**
 * Per-site-URL + endpoint + hour rate limiting.
 *
 * Key format: `rl:{site_hash}:{endpoint}:{YYYY-MM-DD-HH}`
 * Uses site_hash (sha-style) to bound key size vs putting full URL in key.
 *
 * Tiers (hourly limits):
 *   free:    modest quotas for unpaid installs + anyone hitting without HMAC
 *   pro:     10× free
 *   agency:  unlimited (soft cap via cost circuit breaker, not count)
 *
 * @param {string} endpoint - 'research' | 'content-brief' | 'topic-research' | 'scrape' | 'generate' | 'pexels'
 * @param {string} siteUrl - Verified site URL from HMAC auth
 * @param {string} tier - 'free' | 'pro' | 'agency'
 * @returns {Promise<{ok: boolean, remaining: number, reset_in: number, skipped?: boolean}>}
 */
export async function checkRateLimit(endpoint, siteUrl, tier) {
  const limits = RATE_LIMITS[endpoint] || RATE_LIMITS._default;
  const hourlyLimit = limits[tier] ?? limits.free;

  // Agency tier has no hourly count cap (cost breaker still applies)
  if (hourlyLimit === Infinity) {
    return { ok: true, remaining: Infinity, reset_in: 0 };
  }

  if (!isConfigured()) {
    // Fail-open: upstash not configured → skip rate limit, keep plugin working
    return { ok: true, remaining: hourlyLimit, reset_in: 0, skipped: true };
  }

  const siteHash = hashString(siteUrl).slice(0, 16);
  const hourKey = new Date().toISOString().slice(0, 13);  // YYYY-MM-DDTHH
  const key = `rl:${siteHash}:${endpoint}:${hourKey}`;

  // INCR + EXPIRE pipelined (only sets TTL on first increment)
  const results = await redisPipeline([
    ['INCR', key],
    ['EXPIRE', key, '3660'],  // 61 min — ensures key lives through full hour
  ]);

  if (!results || typeof results[0] !== 'number') {
    // Upstash error — fail-open, log and let request through
    return { ok: true, remaining: hourlyLimit, reset_in: 0, skipped: true };
  }

  const count = results[0];
  const remaining = Math.max(0, hourlyLimit - count);
  const reset_in = 3660 - Math.floor((Date.now() / 1000) % 3600);

  return {
    ok: count <= hourlyLimit,
    remaining,
    reset_in,
    count,
    limit: hourlyLimit,
  };
}

// Rate limits per endpoint per tier (requests per hour)
const RATE_LIMITS = {
  research:          { free: 10,  pro: 100,  agency: Infinity },
  'content-brief':   { free: 10,  pro: 100,  agency: Infinity },
  'topic-research':  { free: 20,  pro: 200,  agency: Infinity },
  'translate-headings': { free: 30, pro: 300, agency: Infinity },  // v1.5.212.2 — post-gen heading guard, single LLM call per article
  generate:          { free: 5,   pro: 100,  agency: Infinity },  // /api/generate is expensive (LLM calls)
  scrape:            { free: 30,  pro: 300,  agency: Infinity },
  pexels:            { free: 100, pro: 500,  agency: Infinity },  // free tier of Pexels is 200/hr — we stay under
  validate:          { free: 60,  pro: 60,   agency: 60 },        // license check — uniform, frequent
  _default:          { free: 10,  pro: 100,  agency: Infinity },
};

// ---------------------------------------------------------------------------
// Cost circuit breaker
// ---------------------------------------------------------------------------

/**
 * Daily $ caps per upstream API. Prevents cost explosion from attacker
 * scripts or bugs (e.g. infinite-loop research calls).
 *
 * Key format: `cost:{service}:{YYYY-MM-DD}`
 * Value: cumulative cents spent today
 *
 * @param {string} service - 'serper' | 'firecrawl' | 'openrouter' | 'pexels' | 'anthropic' | 'groq'
 * @returns {Promise<{ok: boolean, spent_cents: number, cap_cents: number}>}
 */
export async function checkCostCap(service) {
  const capCents = DAILY_CAPS_CENTS[service];
  if (!capCents) return { ok: true, spent_cents: 0, cap_cents: Infinity };
  if (!isConfigured()) return { ok: true, spent_cents: 0, cap_cents: capCents, skipped: true };

  const dayKey = new Date().toISOString().slice(0, 10);
  const key = `cost:${service}:${dayKey}`;
  const spent = await redis(['GET', key]);
  const spentCents = parseInt(spent || '0', 10);

  return {
    ok: spentCents < capCents,
    spent_cents: spentCents,
    cap_cents: capCents,
    percent: Math.round((spentCents / capCents) * 100),
  };
}

/**
 * Record cost after a successful upstream API call.
 *
 * @param {string} service - upstream service name
 * @param {number} costCents - cost in cents (e.g. 0.1 for $0.001)
 */
export async function recordCost(service, costCents) {
  if (!isConfigured() || !costCents) return;
  const dayKey = new Date().toISOString().slice(0, 10);
  const key = `cost:${service}:${dayKey}`;
  await redisPipeline([
    ['INCRBYFLOAT', key, String(costCents)],
    ['EXPIRE', key, '172800'],  // 48h — keep yesterday's data for alerts
  ]);
}

// Daily caps in CENTS. Tune per Ben's actual Vercel billing alerts at 50/80/95%.
const DAILY_CAPS_CENTS = {
  serper:     2000,  // $20/day
  firecrawl:  2000,  // $20/day
  openrouter: 5000,  // $50/day (LLM calls most expensive)
  anthropic:  5000,  // $50/day
  groq:       1000,  // $10/day (usually free tier)
  pexels:        0,  // Pexels free tier quota (20K/mo) tracked by Pexels, we don't double-count
};

// Typical per-call costs in cents — callers use these to record spend.
// Approximate; actual cost varies by model/size. Better to slightly overcount.
export const COSTS_CENTS = {
  serper_search:      0.1,   // $0.001/search
  firecrawl_scrape:   0.1,   // $0.001/page
  openrouter_small:   0.3,   // ~$0.003 for small extraction calls (GPT-4.1-mini)
  openrouter_large:   3.0,   // ~$0.03 for larger generation
  anthropic_small:    0.3,
  anthropic_large:    3.0,
  groq_call:          0.01,  // free tier mostly
};

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Small non-cryptographic hash for bounded Redis key sizes.
 * Don't use for security — use crypto.createHash('sha256') if security matters.
 */
function hashString(s) {
  let h = 0;
  for (let i = 0; i < s.length; i++) {
    h = ((h << 5) - h) + s.charCodeAt(i);
    h |= 0;
  }
  return Math.abs(h).toString(36);
}
