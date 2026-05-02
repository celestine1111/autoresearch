/**
 * SEOBetter Cloud OAuth proxy — START endpoint (v1.5.216.62)
 *
 * GET /api/gsc-oauth/start?return_url=<plugin-callback>&pstate=<plugin-csrf>
 *
 * Step 1 of the centralized OAuth flow. Plugin redirects user here.
 * We sign (return_url, pstate, now) into the state and bounce to Google
 * with our verified app credentials.
 *
 * Required env vars on Vercel:
 *   SEOBETTER_GSC_CLIENT_ID      — Google OAuth client ID for the verified app
 *   SEOBETTER_GSC_REDIRECT_URI   — must be cloud-api.com/api/gsc-oauth/callback
 *   GSC_OAUTH_HMAC_SECRET        — random 32+ char secret for state signing
 */

import { isAllowedReturnUrl, signState, handleCors } from './_helpers.js';

export default async function handler(req, res) {
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
