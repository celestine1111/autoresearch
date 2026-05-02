/**
 * SEOBetter Cloud OAuth proxy — CALLBACK endpoint (v1.5.216.62)
 *
 * GET /api/gsc-oauth/callback?code=<google-code>&state=<signed-state>
 *
 * Step 2 of the OAuth flow. Google redirects here after the user grants
 * consent. We:
 *   1. Verify the signed state (CSRF + replay protection)
 *   2. Exchange the auth code for tokens using OUR client_secret
 *   3. Generate a single-use pickup token, stash tokens in Redis (5 min TTL)
 *   4. Redirect the user back to the plugin's callback with pickup + pstate
 *
 * Tokens never travel via URL. The plugin POSTs to /exchange to retrieve.
 *
 * Required env vars on Vercel:
 *   SEOBETTER_GSC_CLIENT_ID
 *   SEOBETTER_GSC_CLIENT_SECRET
 *   SEOBETTER_GSC_REDIRECT_URI    (must match Google Cloud Console exactly)
 *   GSC_OAUTH_HMAC_SECRET
 *   UPSTASH_REDIS_REST_URL
 *   UPSTASH_REDIS_REST_TOKEN
 */

import crypto from 'crypto';
import { verifyState, redisSet } from './_helpers.js';

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

export default async function handler(req, res) {
  const code = req.query.code;
  const state = req.query.state;
  const oauthError = req.query.error;

  // Google sometimes returns ?error=access_denied if user clicks Cancel
  if (oauthError) {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.status(200).send(errorPage(
      'Google OAuth was canceled or denied.',
      `error=${oauthError}`,
    ));
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

  // Exchange the auth code for tokens
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
    return res.status(502).send(errorPage(
      'Google rejected the OAuth code exchange.',
      errBody.substring(0, 200),
    ));
  }

  const tokens = await tokenResp.json();
  if (!tokens.access_token) {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.status(502).send(errorPage(
      'Google did not return an access token.',
      JSON.stringify(tokens).substring(0, 200),
    ));
  }

  // Generate a single-use pickup token. Plugin POSTs to /exchange to redeem.
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

  // Redirect to plugin with pickup + pstate (so the plugin can verify its own CSRF)
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
