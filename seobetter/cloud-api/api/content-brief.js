/**
 * SEOBetter — /api/content-brief (v1.5.208)
 *
 * POST /api/content-brief
 * Body: { keyword, country?, language?, top_n? }
 *
 * Returns the "Competitive Content Brief" — the data needed to implement
 * SEO-GEO-AI-GUIDELINES.md §28.1 "Topic Selection via Competitor Analysis":
 *   1. Top N organic Google results for the keyword (Serper)
 *   2. Scraped body text per URL (Firecrawl)
 *   3. BM25-ranked distinctive concepts across the corpus
 *   4. Common H2 patterns (≥2 competitors used them)
 *   5. People-Also-Ask questions
 *   6. Average / min / max / median competitor word count
 *
 * This feeds into Async_Generator as pre-generation constraints:
 *   - Outline step uses H2 patterns as structural hints
 *   - Section step + system prompt use BM25 terms as concept coverage
 *   - Quality Gate phase-5 report uses BM25 terms to compute coverage score
 *
 * Cross-references:
 *   - SEO-GEO-AI-GUIDELINES.md §28.1 (this endpoint IS the implementation)
 *   - SEO-GEO-AI-GUIDELINES.md §1 (keyword stuffing = -9%; we return
 *     concept coverage, not density targets)
 *   - SEO-GEO-AI-GUIDELINES.md §27 (LLM citation mechanics — hybrid
 *     BM25+vector retrieval means coverage drives inclusion)
 *   - plugin_functionality_wordpress.md §1.2 (Serper + Firecrawl already
 *     used elsewhere for server-side research)
 *
 * Free tier: top 5 scraped, 20 terms returned
 * Pro tier (optional via license_tier=pro): top 10 scraped, 50 terms, fresh cache
 */

import { bm25Corpus, commonH2Patterns, wordCount } from './_bm25_util.js';
import { verifyRequest, rejectAuth, applyCorsHeaders } from './_auth.js';

const CACHE = new Map(); // in-memory cache, keyed by sha-style hash
const CACHE_TTL_MS_FREE = 7 * 24 * 60 * 60 * 1000; // 7 days
const CACHE_TTL_MS_PRO  = 24 * 60 * 60 * 1000;     // 24 hours (fresher for Pro)

export default async function handler(req, res) {
  applyCorsHeaders(req, res);
  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'Use POST.' });

  // v1.5.211 — HMAC request verification
  const auth = verifyRequest(req);
  if (!auth.ok) return rejectAuth(res, auth);

  const { keyword, country = '', language = 'en', top_n, tier } = req.body || {};
  if (!keyword) return res.status(400).json({ error: 'keyword is required' });

  const isPro = tier === 'pro';
  const requestedN = Number(top_n) || (isPro ? 10 : 5);
  const maxTerms = isPro ? 50 : 20;
  const cacheTTL = isPro ? CACHE_TTL_MS_PRO : CACHE_TTL_MS_FREE;

  try {
    // Cache lookup
    const cacheKey = `${keyword.toLowerCase().trim()}|${country}|${language}|${requestedN}`;
    const cached = CACHE.get(cacheKey);
    if (cached && (Date.now() - cached.t) < cacheTTL) {
      return res.status(200).json({ ...cached.data, cached: true });
    }

    // 1. Serper SERP fetch
    const serperKey = process.env.SERPER_API_KEY || '';
    if (!serperKey) {
      return res.status(500).json({ error: 'SERPER_API_KEY missing on server' });
    }

    const serperBody = { q: keyword, num: Math.min(requestedN, 10) };
    if (country) {
      // Serper uses gl (country) + hl (language), matching topic-research.js fix2
      serperBody.gl = String(country).toLowerCase();
    }
    if (language) {
      serperBody.hl = String(language).toLowerCase().split('-')[0];
    }

    const serperResp = await fetch('https://google.serper.dev/search', {
      method: 'POST',
      headers: { 'X-API-KEY': serperKey, 'Content-Type': 'application/json' },
      body: JSON.stringify(serperBody),
    });
    if (!serperResp.ok) {
      return res.status(502).json({ error: `Serper error ${serperResp.status}` });
    }
    const serperJson = await serperResp.json();

    const organic = Array.isArray(serperJson.organic) ? serperJson.organic.slice(0, requestedN) : [];
    const paa = (Array.isArray(serperJson.peopleAlsoAsk) ? serperJson.peopleAlsoAsk : [])
      .map(q => q.question || q)
      .filter(q => typeof q === 'string' && q.length > 5)
      .slice(0, 8);

    if (organic.length === 0) {
      return res.status(200).json({
        keyword, country, language,
        eligible_count: 0,
        urls: [],
        terms: [],
        h2_patterns: [],
        paa_questions: paa,
        word_count: { avg: 0, min: 0, max: 0, median: 0 },
        stats: { scraped: 0, reason: 'no_organic_results' },
      });
    }

    // 2. Firecrawl scrape in parallel (allSettled so one failure doesn't kill all)
    const firecrawlKey = process.env.FIRECRAWL_API_KEY || '';
    const urls = organic.map(o => o.link).filter(Boolean);

    const scrapeOne = async (url) => {
      // Prefer Firecrawl for clean main-content extraction. Fallback: Jina
      // Reader (free, no key) for users/requests without Firecrawl quota.
      try {
        if (firecrawlKey) {
          const r = await fetch('https://api.firecrawl.dev/v1/scrape', {
            method: 'POST',
            headers: { Authorization: `Bearer ${firecrawlKey}`, 'Content-Type': 'application/json' },
            body: JSON.stringify({
              url,
              formats: ['markdown', 'html'],
              onlyMainContent: true,
              timeout: 15000,
            }),
          });
          if (r.ok) {
            const j = await r.json();
            return {
              url,
              text: j?.data?.markdown || j?.data?.content || '',
              html: j?.data?.html || '',
              source: 'firecrawl',
            };
          }
        }
        // Jina Reader fallback (no auth, text-only, good for BM25)
        const jr = await fetch(`https://r.jina.ai/${url}`, { headers: { 'X-Return-Format': 'markdown' } });
        if (jr.ok) {
          const text = await jr.text();
          return { url, text, html: '', source: 'jina' };
        }
      } catch (e) {
        return { url, text: '', html: '', error: e?.message || 'scrape_failed' };
      }
      return { url, text: '', html: '', error: 'no_scraper_available' };
    };

    const scrapeResults = await Promise.allSettled(urls.map(scrapeOne));
    const scrapedDocs = scrapeResults
      .map(r => r.status === 'fulfilled' ? r.value : null)
      .filter(d => d && d.text && d.text.length > 300); // skip empty/failed/too-short

    if (scrapedDocs.length === 0) {
      return res.status(200).json({
        keyword, country, language,
        eligible_count: 0,
        urls, terms: [], h2_patterns: [], paa_questions: paa,
        word_count: { avg: 0, min: 0, max: 0, median: 0 },
        stats: { scraped: 0, reason: 'all_scrapes_failed' },
      });
    }

    // 3. BM25 corpus
    const bm25 = bm25Corpus(
      scrapedDocs.map(d => d.text),
      language,
      { k1: 1.5, b: 0.75, maxTerms }
    );

    // 4. H2 patterns (HTML-based)
    const h2 = commonH2Patterns(scrapedDocs.map(d => d.html || ''));

    // 5. Word count stats
    const wcs = scrapedDocs.map(d => wordCount(d.text, language)).filter(n => n > 100);
    const sorted = [...wcs].sort((a, b) => a - b);
    const median = sorted.length ? sorted[Math.floor(sorted.length / 2)] : 0;
    const avg = wcs.length ? Math.round(wcs.reduce((a, b) => a + b, 0) / wcs.length) : 0;

    const out = {
      keyword, country, language,
      eligible_count: scrapedDocs.length,
      urls: scrapedDocs.map(d => ({ url: d.url, source: d.source })),
      terms: bm25.terms,            // [{ term, score, df, tf_total }]
      h2_patterns: h2,              // [{ text, count }]
      paa_questions: paa,           // ["What is X?", ...]
      word_count: {
        avg,
        min: sorted[0] || 0,
        max: sorted[sorted.length - 1] || 0,
        median,
      },
      stats: {
        scraped: scrapedDocs.length,
        serp_returned: organic.length,
        avg_doc_length: bm25.avgDocLength,
        unique_terms: bm25.stats.uniqueTerms,
        k1: bm25.stats.k1, b: bm25.stats.b,
        tier: isPro ? 'pro' : 'free',
      },
      cached: false,
      generated_at: new Date().toISOString(),
    };

    // Cache before returning
    CACHE.set(cacheKey, { t: Date.now(), data: out });
    // Keep cache bounded (simple FIFO at 1000 keys)
    if (CACHE.size > 1000) {
      const firstKey = CACHE.keys().next().value;
      CACHE.delete(firstKey);
    }

    return res.status(200).json(out);
  } catch (err) {
    console.error('content-brief error:', err);
    return res.status(500).json({ error: 'Brief failed: ' + (err?.message || 'unknown') });
  }
}
