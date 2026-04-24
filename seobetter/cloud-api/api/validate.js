/**
 * SEOBetter Cloud API — License Validation Endpoint
 *
 * POST /api/validate
 *
 * Validates Pro license keys for the SEOBetter WordPress plugin.
 * For now: checks against SEOBETTER_PRO_KEYS env var.
 * Later: integrate with payment provider (Stripe, LemonSqueezy, etc.)
 *
 * v1.5.211: HMAC-signed requests required.
 */

import { verifyRequest, rejectAuth, applyCorsHeaders, enforceRateLimit } from './_auth.js';

export default async function handler(req, res) {
  applyCorsHeaders(req, res);

  if (req.method === 'OPTIONS') {
    return res.status(200).end();
  }

  if (req.method !== 'POST') {
    return res.status(405).json({ error: 'Method not allowed. Use POST.' });
  }

  // v1.5.211 — HMAC request verification
  const auth = verifyRequest(req);
  if (!auth.ok) return rejectAuth(res, auth);

  // v1.5.212 — Rate limit (60/hr uniform across tiers — license checks)
  const rlReject = await enforceRateLimit(req, res, 'validate', auth);
  if (rlReject) return rlReject;

  const { license_key = '', site_url = '', plugin_version = '' } = req.body || {};

  if (!license_key) {
    return res.status(400).json({ valid: false, message: 'Missing license_key' });
  }

  const proKeys = (process.env.SEOBETTER_PRO_KEYS || '')
    .split(',')
    .map((k) => k.trim())
    .filter(Boolean);

  const isValid = proKeys.includes(license_key);

  // Log for analytics (optional)
  if (isValid) {
    console.log(`License validated: ${license_key.substring(0, 8)}... for ${site_url}`);
  }

  return res.status(200).json({
    valid: isValid,
    tier: isValid ? 'pro' : 'free',
  });
}
