/**
 * SEOBetter Cloud OAuth proxy — EXCHANGE endpoint (v1.5.216.62)
 *
 * POST /api/gsc-oauth/exchange
 * Body: { pickup: "<pickup-token>" }
 * Returns: { access_token, refresh_token, expires_in, scope }
 *
 * Step 3 of the OAuth flow. The plugin received a pickup token in the
 * callback redirect's query string. It POSTs that pickup token here to
 * retrieve the actual OAuth tokens. Pickup tokens are single-use and
 * expire 5 min after issuance.
 *
 * Why pickup-token pattern: passing access_token / refresh_token via URL
 * query string would leak them into browser history, web server logs,
 * and any CDN edge between Google and the user's WordPress install.
 * The pickup token is harmless on its own — without the matching
 * server-side stored entry, it grants nothing.
 */

import { handleCors, redisGetDel } from './_helpers.js';

export default async function handler(req, res) {
  if (handleCors(req, res)) return;
  if (req.method !== 'POST') {
    return res.status(405).json({ error: 'Method not allowed' });
  }

  let body = req.body;
  // Vercel Node runtime parses JSON automatically when Content-Type is application/json
  if (typeof body === 'string') {
    try {
      body = JSON.parse(body);
    } catch {
      return res.status(400).json({ error: 'Invalid JSON body' });
    }
  }

  const pickup = body && body.pickup;
  if (!pickup || typeof pickup !== 'string' || !/^[A-Za-z0-9_-]{20,80}$/.test(pickup)) {
    return res.status(400).json({ error: 'Invalid pickup token format' });
  }

  const stored = await redisGetDel(`gsc_pickup:${pickup}`);
  if (!stored) {
    return res.status(404).json({ error: 'Pickup token expired or already used' });
  }

  let tokens;
  try {
    tokens = typeof stored === 'string' ? JSON.parse(stored) : stored;
  } catch {
    return res.status(500).json({ error: 'Stored token blob malformed' });
  }

  if (!tokens || !tokens.access_token) {
    return res.status(500).json({ error: 'Stored tokens missing access_token' });
  }

  res.status(200).json({
    access_token: tokens.access_token,
    refresh_token: tokens.refresh_token || '',
    expires_in: tokens.expires_in || 3600,
    scope: tokens.scope || '',
  });
}
