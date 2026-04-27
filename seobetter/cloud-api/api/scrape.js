/**
 * SEOBetter Cloud API — Firecrawl Scrape Endpoint (with Jina Reader fallback)
 *
 * POST /api/scrape
 *
 * Takes a URL, scrapes it into clean markdown.
 * Primary: Firecrawl (best quality, requires FIRECRAWL_API_KEY + credits)
 * Fallback: Jina Reader (free, no auth, slightly lower quality)
 *
 * Used by the PHP recipe pipeline to get structured recipe content
 * instead of parsing messy raw HTML.
 *
 * v1.5.211: HMAC-signed requests required. SSRF protection on URL input.
 * v1.5.212.1: Jina Reader fallback when Firecrawl returns 402 (credits exhausted),
 *             401 (auth), 5xx (provider down), or any non-2xx. Matches the
 *             behaviour /api/research and /api/content-brief already had.
 *             Endpoint NEVER fails as long as Jina Reader is reachable.
 */

import { verifyRequest, rejectAuth, applyCorsHeaders, isSafeScrapeUrl, enforceRateLimit, enforceCostCap } from './_auth.js';
import { recordCost, COSTS_CENTS } from './_upstash.js';

export default async function handler(req, res) {
  applyCorsHeaders(req, res);

  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'Use POST.' });

  // v1.5.211 — HMAC request verification
  const auth = verifyRequest(req);
  if (!auth.ok) return rejectAuth(res, auth);

  // v1.5.212 — Rate limit + cost cap
  const rlReject = await enforceRateLimit(req, res, 'scrape', auth);
  if (rlReject) return rlReject;
  const costReject = await enforceCostCap(res, 'firecrawl');
  if (costReject) return costReject;

  const { url } = req.body || {};

  // v1.5.211 — SSRF protection. Rejects: non-http(s), private IP ranges,
  // cloud metadata endpoints (169.254.169.254, metadata.google.internal, etc.),
  // localhost, IPv6 literals, URLs >2048 chars.
  if (!url || typeof url !== 'string' || !isSafeScrapeUrl(url)) {
    return res.status(400).json({ success: false, error: 'Invalid or unsafe URL.' });
  }

  // v1.5.212.1 — Try Firecrawl first if configured + has credits.
  // On any failure (no key / 402 credits / 401 auth / 5xx / timeout),
  // fall through to Jina Reader. Jina Reader is the same fallback used
  // by /api/research and /api/content-brief — proven path.
  const FIRECRAWL_KEY = process.env.FIRECRAWL_API_KEY;

  if (FIRECRAWL_KEY) {
    try {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 15000);

      const resp = await fetch('https://api.firecrawl.dev/v1/scrape', {
        method: 'POST',
        signal: controller.signal,
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${FIRECRAWL_KEY}`,
        },
        body: JSON.stringify({
          url: url,
          formats: ['markdown'],
          onlyMainContent: true,
          timeout: 12000,
        }),
      });
      clearTimeout(timeout);

      if (resp.ok) {
        const data = await resp.json();
        if (data?.success && data?.data?.markdown) {
          recordCost('firecrawl', COSTS_CENTS.firecrawl_scrape).catch(() => {});
          return res.status(200).json({
            success: true,
            markdown: data.data.markdown,
            title: data.data.metadata?.title || '',
            url: data.data.metadata?.sourceURL || url,
            source: 'firecrawl',
          });
        }
      } else {
        // Log the specific failure reason; common ones: 402 (credits), 401 (bad key)
        const errText = await resp.text().catch(() => '');
        console.warn(`Firecrawl ${resp.status}, falling back to Jina Reader: ${errText.slice(0, 200)}`);
      }
    } catch (e) {
      console.warn(`Firecrawl exception, falling back to Jina Reader: ${e.message || e}`);
    }
  } else {
    console.warn('FIRECRAWL_API_KEY not set — using Jina Reader directly');
  }

  // Jina Reader fallback — free, no auth, no quota
  try {
    const jr = await fetch(`https://r.jina.ai/${url}`, {
      headers: { 'X-Return-Format': 'markdown' },
      signal: AbortSignal.timeout(15000),
    });
    if (!jr.ok) {
      return res.status(502).json({
        success: false,
        error: `Both scrapers failed. Jina Reader returned ${jr.status}.`,
      });
    }
    const text = await jr.text();
    if (!text || text.length < 50) {
      return res.status(502).json({
        success: false,
        error: 'Jina Reader returned empty/insufficient content.',
      });
    }
    return res.status(200).json({
      success: true,
      markdown: text,
      title: '',  // Jina Reader doesn't return separate metadata.title
      url,
      source: 'jina',
    });
  } catch (err) {
    console.error('All scrape paths failed:', err.message || err);
    return res.status(500).json({
      success: false,
      error: err.message || 'Scrape failed (both Firecrawl + Jina unreachable).',
    });
  }
}
