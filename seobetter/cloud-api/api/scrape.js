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
 */

export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'Use POST.' });

  const { url } = req.body || {};

  if (!url || typeof url !== 'string' || !url.startsWith('http')) {
    return res.status(400).json({ success: false, error: 'Valid URL required.' });
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
