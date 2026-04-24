/**
 * SEOBetter Cloud API — Pexels image search (v1.5.212)
 *
 * POST /api/pexels
 * Body: { keyword, orientation?, per_page? }
 *
 * Server-side Pexels proxy using Ben's PEXELS_API_KEY. Makes Pexels the
 * free-tier default for featured images (replacing low-quality Picsum).
 * Pro users can still configure their own Pexels key in plugin Settings
 * to use their own quota — cloud-api is the fallback path.
 *
 * Rationale (per pro-plan-pricing.md §12 decision 2026-04-24):
 *   Picsum = random lorem-ipsum placeholder quality. Bad first impression.
 *   Pexels = keyword-relevant real stock photos, free tier generous (20K/mo).
 *   Matches the Sonar Backend Rule: all research/media data through Ben's
 *   Vercel backend, not user-owned API keys.
 *
 * Free tier limit: Pexels's 20K req/mo free tier is shared across all users
 * of this endpoint. Rate limit (100/hr per site via Upstash) keeps any
 * single site from draining the shared pool.
 *
 * 24h in-memory cache per (keyword, orientation) reduces repeat lookups.
 *
 * Environment:
 *   PEXELS_API_KEY (Sensitive) — required
 */

import { verifyRequest, rejectAuth, applyCorsHeaders, enforceRateLimit } from './_auth.js';

const CACHE = new Map();
const CACHE_TTL_MS = 24 * 60 * 60 * 1000;  // 24h

export default async function handler(req, res) {
  applyCorsHeaders(req, res);
  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'Use POST.' });

  const auth = verifyRequest(req);
  if (!auth.ok) return rejectAuth(res, auth);

  const rlReject = await enforceRateLimit(req, res, 'pexels', auth);
  if (rlReject) return rlReject;

  const { keyword, orientation = 'landscape', per_page = 5 } = req.body || {};
  if (!keyword || typeof keyword !== 'string' || keyword.length > 200) {
    return res.status(400).json({ success: false, error: 'keyword required (≤200 chars)' });
  }

  const PEXELS_KEY = process.env.PEXELS_API_KEY;
  if (!PEXELS_KEY) {
    return res.status(503).json({ success: false, error: 'Pexels not configured on server.' });
  }

  const cacheKey = `${keyword.toLowerCase().trim()}|${orientation}`;
  const cached = CACHE.get(cacheKey);
  if (cached && (Date.now() - cached.t) < CACHE_TTL_MS) {
    return res.status(200).json({ ...cached.data, cached: true });
  }

  try {
    const params = new URLSearchParams({
      query: keyword,
      orientation,
      per_page: String(Math.min(Math.max(per_page, 1), 10)),
    });
    const resp = await fetch(`https://api.pexels.com/v1/search?${params}`, {
      headers: { Authorization: PEXELS_KEY },
      signal: AbortSignal.timeout(8000),
    });

    if (!resp.ok) {
      if (resp.status === 429) {
        return res.status(503).json({
          success: false,
          error: 'Pexels rate limit hit on shared pool — try again shortly or configure your own key in Settings.',
        });
      }
      return res.status(502).json({ success: false, error: `Pexels error ${resp.status}` });
    }

    const data = await resp.json();
    const photos = Array.isArray(data.photos) ? data.photos : [];

    if (photos.length === 0) {
      return res.status(200).json({ success: true, photos: [], count: 0, keyword });
    }

    // Return a minimal shape — URL sizes + photographer attribution
    const simplified = photos.map(p => ({
      id: p.id,
      url: p.src?.landscape || p.src?.large || p.src?.original,
      url_large: p.src?.large,
      url_medium: p.src?.medium,
      width: p.width,
      height: p.height,
      photographer: p.photographer,
      photographer_url: p.photographer_url,
      source_url: p.url,  // Pexels page for attribution
      alt: p.alt || '',
    }));

    const out = {
      success: true,
      photos: simplified,
      count: simplified.length,
      keyword,
      cached: false,
      provider: 'pexels_server',
    };

    CACHE.set(cacheKey, { t: Date.now(), data: out });
    if (CACHE.size > 500) {
      CACHE.delete(CACHE.keys().next().value);
    }

    return res.status(200).json(out);
  } catch (e) {
    console.error('pexels error:', e.message || e);
    return res.status(500).json({ success: false, error: e.message || 'Pexels failed' });
  }
}
