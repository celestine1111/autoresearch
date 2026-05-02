/**
 * SEOBetter Cloud OAuth proxy — REFRESH endpoint (v1.5.216.62)
 *
 * POST /api/gsc-oauth/refresh
 * Body: { refresh_token: "<refresh-token>" }
 * Returns: { access_token, expires_in }
 *
 * The plugin holds the refresh_token but cannot exchange it without our
 * client_secret. This endpoint takes the refresh_token, exchanges it via
 * Google's token endpoint with our centralized client_secret, and returns
 * a fresh access_token to the plugin.
 *
 * Authentication: possession of a valid refresh_token is itself the auth
 * — only the install that originally completed OAuth has it. This endpoint
 * does NOT log refresh_tokens (we proxy them straight to Google).
 *
 * Required env vars:
 *   SEOBETTER_GSC_CLIENT_ID
 *   SEOBETTER_GSC_CLIENT_SECRET
 */

import { handleCors } from './_helpers.js';

export default async function handler(req, res) {
  if (handleCors(req, res)) return;
  if (req.method !== 'POST') {
    return res.status(405).json({ error: 'Method not allowed' });
  }

  let body = req.body;
  if (typeof body === 'string') {
    try {
      body = JSON.parse(body);
    } catch {
      return res.status(400).json({ error: 'Invalid JSON body' });
    }
  }

  const refreshToken = body && body.refresh_token;
  if (!refreshToken || typeof refreshToken !== 'string' || refreshToken.length < 20) {
    return res.status(400).json({ error: 'Invalid refresh_token' });
  }

  const CLIENT_ID = process.env.SEOBETTER_GSC_CLIENT_ID;
  const CLIENT_SECRET = process.env.SEOBETTER_GSC_CLIENT_SECRET;
  if (!CLIENT_ID || !CLIENT_SECRET) {
    return res.status(500).json({ error: 'OAuth proxy not configured' });
  }

  const tokenResp = await fetch('https://oauth2.googleapis.com/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      refresh_token: refreshToken,
      client_id: CLIENT_ID,
      client_secret: CLIENT_SECRET,
      grant_type: 'refresh_token',
    }),
    signal: AbortSignal.timeout(10000),
  });

  if (!tokenResp.ok) {
    const errText = await tokenResp.text().catch(() => '');
    // 400 from Google usually means the refresh_token is revoked / invalid
    // (user disconnected the app from their Google account, etc.). Pass
    // that through as 401 so the plugin treats it as needs-reauth.
    if (tokenResp.status === 400) {
      return res.status(401).json({ error: 'refresh_token rejected by Google — re-authenticate', detail: errText.substring(0, 200) });
    }
    return res.status(502).json({ error: 'Google refresh failed', detail: errText.substring(0, 200) });
  }

  const data = await tokenResp.json();
  if (!data.access_token) {
    return res.status(502).json({ error: 'Google refresh response missing access_token' });
  }

  res.status(200).json({
    access_token: data.access_token,
    expires_in: data.expires_in || 3600,
  });
}
