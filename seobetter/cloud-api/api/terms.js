/**
 * SEOBetter — Terms of Service (v1.5.216.62)
 *
 * GET /api/terms
 *
 * Required by Google OAuth Verification process. Configure this URL in
 * the OAuth consent screen.
 */

export default function handler(req, res) {
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.setHeader('Cache-Control', 'public, max-age=3600');
  res.status(200).send(`<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>SEOBetter — Terms of Service</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 760px; margin: 40px auto; padding: 0 24px 80px; color: #1a1a1a; line-height: 1.65; }
    h1 { font-size: 30px; margin-bottom: 8px; }
    h2 { font-size: 20px; margin-top: 36px; padding-top: 8px; border-top: 1px solid #e5e7eb; }
    p, li { font-size: 15px; }
    .meta { color: #64748b; font-size: 13px; margin-bottom: 32px; }
    a { color: #2563eb; }
  </style>
</head>
<body>

<h1>SEOBetter Terms of Service</h1>
<p class="meta">Last updated: 2026-05-03 · Effective: 2026-05-03</p>

<p>These Terms govern your use of the SEOBetter WordPress plugin and the SEOBetter Cloud API (together, the "Service"). By installing the plugin or making API requests against our endpoints, you agree to these Terms.</p>

<h2>1. The Service</h2>
<p>SEOBetter is a WordPress plugin that helps you generate and refresh content optimized for AI search engines. The plugin runs on your own WordPress server. Some features (research aggregation, AI content generation via Bring-Your-Own-Key, OAuth proxy for Google Search Console) call our Cloud API.</p>

<h2>2. Pricing tiers</h2>
<p>SEOBetter offers a Free tier (BYOK — bring your own AI provider key) and paid tiers (Pro, Pro+, Agency) with cloud generation and additional features. Pricing is published on the seobetter.com website. Paid subscriptions auto-renew unless canceled.</p>

<h2>3. Acceptable use</h2>
<p>You agree not to:</p>
<ul>
  <li>Generate content that violates the law in your jurisdiction or the jurisdiction of your readers</li>
  <li>Generate spam, harassment, or content designed to manipulate search results in ways that violate Google or other search-engine policies</li>
  <li>Reverse-engineer, decompile, or attempt to extract the source code of the Cloud API beyond what is publicly readable in the plugin source</li>
  <li>Resell access to the Cloud API or use it on behalf of multiple unrelated sites without an Agency license</li>
  <li>Abuse the OAuth proxy by exceeding reasonable refresh-token rates (we rate-limit at 100 refreshes per hour per refresh token; sustained abuse will revoke access)</li>
</ul>

<h2>4. Bring Your Own Key (BYOK)</h2>
<p>The Free tier requires you to provide your own API key from an AI provider (OpenAI, Anthropic, Google, OpenRouter, Groq, etc.). You are responsible for paying that provider for usage and for keeping your keys secure. SEOBetter never logs or transmits your BYOK keys outside your WordPress install.</p>

<h2>5. Google Search Console integration</h2>
<p>The GSC integration uses Google's OAuth 2.0 to read performance data from properties you've verified at search.google.com/search-console. By connecting GSC, you authorize SEOBetter to read clicks, impressions, position, and queries for your verified properties. SEOBetter does not request write access. See the <a href="/api/privacy">Privacy Policy</a> for details on data handling.</p>

<h2>6. Disclaimers and limitations</h2>
<p>The Service is provided "as is". We do not guarantee that AI-generated content will rank in any particular search engine, be cited by any AI engine, or be free of factual errors. You are responsible for reviewing all generated content before publishing.</p>
<p>To the maximum extent permitted by law, SEOBetter's total liability for any claim arising from your use of the Service is limited to the amount you paid us in the 12 months preceding the claim, or USD $100, whichever is greater.</p>

<h2>7. Termination</h2>
<p>You can stop using the Service at any time by deactivating the plugin and (if applicable) canceling your subscription. We may suspend access for violations of section 3, with notice where reasonable.</p>

<h2>8. Changes to these Terms</h2>
<p>We may update these Terms when the Service changes materially. The "Last updated" date reflects the most recent change. Continued use after changes constitutes acceptance.</p>

<h2>9. Contact</h2>
<p>Email: <a href="mailto:hello@seobetter.com">hello@seobetter.com</a></p>

</body>
</html>`);
}
