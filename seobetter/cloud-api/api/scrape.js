/**
 * SEOBetter Cloud API — Firecrawl Scrape Endpoint
 *
 * POST /api/scrape
 *
 * Takes a URL, scrapes it via Firecrawl into clean markdown.
 * Used by the PHP recipe pipeline to get structured recipe content
 * instead of parsing messy raw HTML.
 *
 * Requires FIRECRAWL_API_KEY env var on Vercel.
 *
 * v1.5.211: HMAC-signed requests required. SSRF protection on URL input.
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

  const FIRECRAWL_KEY = process.env.FIRECRAWL_API_KEY;
  if (!FIRECRAWL_KEY) {
    return res.status(503).json({ success: false, error: 'Scrape service not configured.' });
  }

  try {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 20000);

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
        timeout: 15000,
      }),
    });

    clearTimeout(timeout);

    if (!resp.ok) {
      const errText = await resp.text().catch(() => '');
      console.error(`Firecrawl error ${resp.status}: ${errText.slice(0, 200)}`);
      return res.status(502).json({ success: false, error: `Scrape failed (${resp.status})` });
    }

    const data = await resp.json();

    if (!data?.success || !data?.data?.markdown) {
      return res.status(502).json({ success: false, error: 'No content returned from scrape.' });
    }

    // v1.5.212 — record cost for circuit breaker
    recordCost('firecrawl', COSTS_CENTS.firecrawl_scrape).catch(() => {});

    return res.status(200).json({
      success: true,
      markdown: data.data.markdown,
      title: data.data.metadata?.title || '',
      url: data.data.metadata?.sourceURL || url,
    });
  } catch (err) {
    console.error('Scrape endpoint error:', err.message || err);
    return res.status(500).json({ success: false, error: err.message || 'Scrape failed.' });
  }
}
