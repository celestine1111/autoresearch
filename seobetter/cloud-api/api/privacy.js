/**
 * SEOBetter — Privacy Policy (v1.5.216.62)
 *
 * GET /api/privacy
 *
 * Required by Google OAuth Verification process. Must be publicly
 * accessible at a stable URL. Configure this URL in the OAuth consent
 * screen when submitting for Google verification.
 *
 * Plain HTML response — no framework. Keep this file static so it can
 * be cached at the edge.
 */

export default function handler(req, res) {
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.setHeader('Cache-Control', 'public, max-age=3600');
  res.status(200).send(`<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>SEOBetter — Privacy Policy</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 760px; margin: 40px auto; padding: 0 24px 80px; color: #1a1a1a; line-height: 1.65; }
    h1 { font-size: 30px; margin-bottom: 8px; }
    h2 { font-size: 20px; margin-top: 36px; padding-top: 8px; border-top: 1px solid #e5e7eb; }
    h3 { font-size: 16px; margin-top: 24px; }
    p, li { font-size: 15px; }
    code { background: #f1f5f9; padding: 1px 5px; border-radius: 3px; font-size: 13px; }
    .meta { color: #64748b; font-size: 13px; margin-bottom: 32px; }
    .important { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; margin: 16px 0; font-size: 14px; }
    a { color: #2563eb; }
  </style>
</head>
<body>

<h1>SEOBetter Privacy Policy</h1>
<p class="meta">Last updated: 2026-05-03 · Effective: 2026-05-03</p>

<p>SEOBetter is a WordPress plugin published by SEOBetter ("we", "us", "our"). This Privacy Policy explains what data we collect, how we use it, and your rights, with specific attention to data accessed via Google APIs.</p>

<h2>1. Who is the data controller</h2>
<p>The plugin runs <strong>on your own WordPress site</strong>. We do not host your content, your articles, or your Google Search Console data. You are the data controller for any data processed by your install.</p>
<p>SEOBetter operates a small set of cloud endpoints (the "Cloud API") that handle research aggregation and the OAuth proxy. Where the Cloud API processes data on your behalf, we are a data processor.</p>

<h2>2. What data we access via Google APIs</h2>
<p>If you choose to connect Google Search Console (GSC) inside the plugin, SEOBetter requests the following Google API scopes:</p>
<ul>
  <li><code>https://www.googleapis.com/auth/webmasters.readonly</code> — read-only access to your verified GSC properties' performance data (clicks, impressions, position, queries, pages)</li>
  <li><code>https://www.googleapis.com/auth/userinfo.email</code> — your Google account email address, displayed in plugin settings as "connected as user@example.com"</li>
</ul>
<p>We do not request, access, or store any other Google data. We do not request write access to your Search Console properties.</p>

<h2>3. How GSC data is used</h2>
<p>Performance data retrieved from your GSC properties is used to:</p>
<ul>
  <li>Display a Content Freshness inventory inside your WordPress admin showing which posts are losing traffic</li>
  <li>Compute per-post refresh-priority scores</li>
  <li>Surface "striking distance" pages ranking just off page 1</li>
  <li>Show top queries each post is ranked for</li>
</ul>
<p>This data is stored only in your own WordPress database (in the <code>wp_seobetter_gsc_snapshots</code> table). It never leaves your server.</p>

<h2>4. OAuth tokens — how they're stored</h2>
<p>When you complete the OAuth flow, Google issues an access token (1-hour validity) and a refresh token (long-lived). These are:</p>
<ul>
  <li><strong>Stored encrypted</strong> in your WordPress database in the <code>wp_options</code> table under the <code>seobetter_gsc_connection</code> option, using AES-256-CBC with the encryption key derived from your site's <code>SECURE_AUTH_KEY</code> WordPress salt</li>
  <li><strong>Never logged</strong> on the OAuth proxy server (cloud-api). The proxy passes tokens through to your install via a single-use 5-minute pickup-token mechanism that never writes the token to logs or persistent storage longer than the redemption window</li>
  <li><strong>Never shared</strong> with third parties</li>
  <li><strong>Revocable at any time</strong> by clicking "Disconnect" in plugin Settings, which deletes the stored tokens and revokes them at Google's token endpoint</li>
</ul>

<h2>5. The OAuth proxy — what it does and doesn't do</h2>
<p>To avoid requiring every plugin user to create their own Google Cloud Console project (an hour of friction with security pitfalls for non-technical users), SEOBetter runs a centralized OAuth proxy at <code>cloud-api.seobetter.com/api/gsc-oauth/*</code>.</p>
<p>The proxy:</p>
<ul>
  <li><strong>Holds</strong> the OAuth client_id and client_secret of the verified SEOBetter Google Cloud project</li>
  <li><strong>Forwards</strong> the user's auth-code exchange back to Google with our credentials</li>
  <li><strong>Returns</strong> the resulting tokens to the user's WordPress install via a single-use pickup token (Upstash Redis, 5-minute TTL)</li>
  <li><strong>Refreshes</strong> expired access tokens when the install's refresh_token is presented (also via our client_secret)</li>
</ul>
<p>The proxy <strong>does not</strong>:</p>
<ul>
  <li>Log access tokens, refresh tokens, or any user content</li>
  <li>Store tokens longer than the 5-minute pickup window</li>
  <li>Read or modify your GSC data on its own</li>
  <li>Have direct access to your GSC properties (only you, via your install's access_token, do)</li>
</ul>

<h2>6. Compliance with Google API Services User Data Policy</h2>
<p>SEOBetter's use and transfer to any other app of information received from Google APIs adheres to the <a href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener">Google API Services User Data Policy</a>, including the Limited Use requirements. Specifically:</p>
<ul>
  <li>We use Google API user data only to provide and improve user-facing features that are visible in the plugin's admin UI</li>
  <li>We do not transfer or sell Google API user data to third parties</li>
  <li>We do not use Google API user data for serving advertising</li>
  <li>We do not allow humans to read Google API user data unless we have your explicit consent for specific support cases, the data is necessary for security purposes, or the data is aggregated and de-identified</li>
</ul>

<h2>7. Data we collect outside Google APIs</h2>
<p>Plugin telemetry is opt-in. By default, the plugin does not transmit usage data to us. If you optionally enable telemetry, the plugin sends:</p>
<ul>
  <li>Plugin version, WordPress version, PHP version</li>
  <li>License key (if Pro/Pro+/Agency)</li>
  <li>Anonymous error logs (no post content)</li>
</ul>
<p>License validation requests sent to our Cloud API include the site URL (so we can scope licenses per-site) and the license key. No content data.</p>

<h2>8. Cookies</h2>
<p>The plugin's admin UI uses standard WordPress session cookies. The Cloud API does not set cookies on your browser.</p>

<h2>9. Data retention</h2>
<ul>
  <li><strong>OAuth tokens:</strong> until you click Disconnect or the refresh_token is revoked by you at Google. We do not auto-expire.</li>
  <li><strong>GSC snapshots in your DB:</strong> indefinite — stored locally on your site, you control retention</li>
  <li><strong>Pickup tokens (Cloud proxy):</strong> 5 minutes, then automatically deleted</li>
  <li><strong>Server logs (Cloud API):</strong> 30 days. Logs do not contain access tokens, refresh tokens, or user content. They contain timestamps, request paths, and error diagnostics</li>
</ul>

<h2>10. Your rights</h2>
<ul>
  <li><strong>Access:</strong> all GSC data we access is in your own WordPress database — you have direct access</li>
  <li><strong>Disconnect:</strong> click Disconnect in plugin Settings; tokens are revoked and deleted</li>
  <li><strong>Revoke at Google:</strong> visit <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">myaccount.google.com/permissions</a> and remove SEOBetter</li>
  <li><strong>GDPR / CCPA requests:</strong> email <a href="mailto:privacy@seobetter.com">privacy@seobetter.com</a></li>
</ul>

<h2>11. Children</h2>
<p>SEOBetter is a B2B WordPress plugin. We do not knowingly collect data from anyone under 18.</p>

<h2>12. Changes to this policy</h2>
<p>We will update this policy when we add or change features that affect data handling. The "Last updated" date at the top reflects the most recent change. Material changes are also announced on the plugin's settings page.</p>

<h2>13. Contact</h2>
<p>Questions or requests:</p>
<p>Email: <a href="mailto:privacy@seobetter.com">privacy@seobetter.com</a></p>

</body>
</html>`);
}
