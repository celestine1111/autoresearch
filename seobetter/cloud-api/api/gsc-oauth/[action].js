/**
 * SEOBetter Cloud OAuth proxy — UNIFIED handler (v1.5.216.62.1)
 *
 * Single Vercel serverless function handling all 4 OAuth proxy routes via
 * dynamic-route file naming. URLs remain identical to the previous 4-file
 * layout — Vercel routes /api/gsc-oauth/<anything> to this handler with
 * the path segment available as req.query.action.
 *
 * Routes handled:
 *   GET  /api/gsc-oauth/start    — initiates OAuth flow
 *   GET  /api/gsc-oauth/callback — Google → proxy → plugin redirect
 *   POST /api/gsc-oauth/exchange — pickup-token → tokens
 *   POST /api/gsc-oauth/refresh  — refresh access_token
 *
 * Why one file: Vercel Hobby plan caps a Deployment at 12 serverless
 * functions; consolidating these 4 into 1 dynamic route saves slots so
 * cloud-api stays under the limit without paying for Pro.
 *
 * Required env vars (Vercel):
 *   SEOBETTER_GSC_CLIENT_ID
 *   SEOBETTER_GSC_CLIENT_SECRET
 *   SEOBETTER_GSC_REDIRECT_URI    (must equal cloud-api/api/gsc-oauth/callback)
 *   GSC_OAUTH_HMAC_SECRET
 *   UPSTASH_REDIS_REST_URL
 *   UPSTASH_REDIS_REST_TOKEN
 */

import crypto from 'crypto';
import { isAllowedReturnUrl, signState, verifyState, handleCors, redisSet, redisGetDel } from './_helpers.js';

const PICKUP_TTL_SECONDS = 300; // 5 min — plugin must redeem within this window

function errorPage(message, detail) {
  return `<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>SEOBetter — OAuth error</title>
<style>body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;max-width:560px;margin:60px auto;padding:0 20px;color:#1a1a1a;line-height:1.6}h1{font-size:22px;color:#dc2626}code{background:#f1f5f9;padding:2px 6px;border-radius:3px;font-size:13px}</style>
</head><body>
<h1>SEOBetter — OAuth flow could not complete</h1>
<p>${message}</p>
${detail ? `<p><code>${detail}</code></p>` : ''}
<p>Return to your WordPress admin → Settings → SEOBetter → Research &amp; Integrations and click <strong>Connect Google Search Console</strong> again.</p>
</body></html>`;
}

// ────────── /start ──────────────────────────────────────────────────────
async function handleStart(req, res) {
  if (handleCors(req, res)) return;

  const returnUrl = req.query.return_url;
  const pstate = req.query.pstate;

  if (!isAllowedReturnUrl(returnUrl)) {
    return res.status(400).json({
      error: 'Invalid return_url',
      hint: 'return_url must be HTTPS and end in /wp-json/seobetter/v1/gsc/oauth-callback',
    });
  }
  if (!pstate || typeof pstate !== 'string' || pstate.length < 8 || pstate.length > 64) {
    return res.status(400).json({
      error: 'Invalid pstate',
      hint: 'pstate (plugin CSRF token) must be 8-64 chars',
    });
  }

  const CLIENT_ID = process.env.SEOBETTER_GSC_CLIENT_ID;
  const REDIRECT_URI = process.env.SEOBETTER_GSC_REDIRECT_URI;
  if (!CLIENT_ID || !REDIRECT_URI) {
    return res.status(500).json({ error: 'OAuth proxy not configured (missing env vars)' });
  }

  let state;
  try {
    state = signState(returnUrl, pstate, Date.now());
  } catch (e) {
    return res.status(500).json({ error: 'State signing failed', detail: e.message });
  }

  const params = new URLSearchParams({
    client_id: CLIENT_ID,
    redirect_uri: REDIRECT_URI,
    response_type: 'code',
    scope: 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/userinfo.email',
    access_type: 'offline',
    prompt: 'consent',
    state,
  });

  res.redirect(302, `https://accounts.google.com/o/oauth2/v2/auth?${params.toString()}`);
}

// ────────── /callback ───────────────────────────────────────────────────
async function handleCallback(req, res) {
  const code = req.query.code;
  const state = req.query.state;
  const oauthError = req.query.error;

  if (oauthError) {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.status(200).send(errorPage('Google OAuth was canceled or denied.', `error=${oauthError}`));
  }

  if (!code || !state) {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.status(400).send(errorPage('Missing OAuth response parameters.', 'Both code and state are required.'));
  }

  const verified = verifyState(state);
  if (!verified) {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.status(400).send(errorPage(
      'Invalid or expired OAuth state.',
      'The 10-minute window may have lapsed, or someone replayed an old request. Please retry the connect flow from your WordPress admin.',
    ));
  }
  const { returnUrl, pstate } = verified;

  const tokenResp = await fetch('https://oauth2.googleapis.com/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      code,
      client_id: process.env.SEOBETTER_GSC_CLIENT_ID,
      client_secret: process.env.SEOBETTER_GSC_CLIENT_SECRET,
      redirect_uri: process.env.SEOBETTER_GSC_REDIRECT_URI,
      grant_type: 'authorization_code',
    }),
    signal: AbortSignal.timeout(10000),
  });

  if (!tokenResp.ok) {
    const errBody = await tokenResp.text().catch(() => '');
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.status(502).send(errorPage('Google rejected the OAuth code exchange.', errBody.substring(0, 200)));
  }

  const tokens = await tokenResp.json();
  if (!tokens.access_token) {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.status(502).send(errorPage('Google did not return an access token.', JSON.stringify(tokens).substring(0, 200)));
  }

  const pickupToken = crypto.randomBytes(24).toString('base64url');
  const stored = await redisSet(
    `gsc_pickup:${pickupToken}`,
    JSON.stringify({
      access_token: tokens.access_token,
      refresh_token: tokens.refresh_token || '',
      expires_in: tokens.expires_in || 3600,
      scope: tokens.scope || '',
    }),
    PICKUP_TTL_SECONDS,
  );

  if (!stored) {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.status(500).send(errorPage(
      'Could not store OAuth pickup token.',
      'The OAuth proxy storage layer is misconfigured or down. Try again in a few minutes.',
    ));
  }

  let redirectUrl;
  try {
    redirectUrl = new URL(returnUrl);
  } catch {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.status(500).send(errorPage('Invalid return URL after state verification.'));
  }
  redirectUrl.searchParams.set('pickup', pickupToken);
  redirectUrl.searchParams.set('state', pstate);
  res.redirect(302, redirectUrl.toString());
}

// ────────── /exchange ───────────────────────────────────────────────────
async function handleExchange(req, res) {
  if (handleCors(req, res)) return;
  if (req.method !== 'POST') {
    return res.status(405).json({ error: 'Method not allowed' });
  }

  let body = req.body;
  if (typeof body === 'string') {
    try { body = JSON.parse(body); }
    catch { return res.status(400).json({ error: 'Invalid JSON body' }); }
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
  try { tokens = typeof stored === 'string' ? JSON.parse(stored) : stored; }
  catch { return res.status(500).json({ error: 'Stored token blob malformed' }); }

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

// ────────── /refresh ────────────────────────────────────────────────────
async function handleRefresh(req, res) {
  if (handleCors(req, res)) return;
  if (req.method !== 'POST') {
    return res.status(405).json({ error: 'Method not allowed' });
  }

  let body = req.body;
  if (typeof body === 'string') {
    try { body = JSON.parse(body); }
    catch { return res.status(400).json({ error: 'Invalid JSON body' }); }
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

// ────────── Router ──────────────────────────────────────────────────────
export default async function handler(req, res) {
  const action = (req.query && req.query.action) || '';
  switch (action) {
    case 'start':    return handleStart(req, res);
    case 'callback': return handleCallback(req, res);
    case 'exchange': return handleExchange(req, res);
    case 'refresh':  return handleRefresh(req, res);
    default:
      return res.status(404).json({ error: 'Unknown action', valid: ['start', 'callback', 'exchange', 'refresh'] });
  }
}
