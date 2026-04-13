---
name: seobetter
description: Load all 5 SEOBetter guideline files before making plugin changes
---

You are working on the SEOBetter WordPress plugin. Before making ANY code changes, you MUST read all 5 guideline files in this exact order so you have the full project context:

1. **Read** `seo-guidelines/plugin_UX.md`
   — UI verification checklist (never remove features)

2. **Read** `seo-guidelines/article_design.md`
   — Article HTML/styling spec (typography, color, images, references, content-type variations)

3. **Read** `seo-guidelines/plugin_functionality_wordpress.md`
   — Complete technical reference (research pipeline, AI generation, formatting, schema, GEO scoring, UI, humanizer, multilingual, URL integrity)

4. **Read** `seo-guidelines/SEO-GEO-AI-GUIDELINES.md`
   — SEO/GEO rules for content generation (master spec, §1–§30)

5. **Read** `seo-guidelines/external-links-policy.md`
   — Outbound link / citation / whitelist rules
   — MANDATORY if touching validate_outbound_links, Content_Injector::inject_citations, the AI URL prompt rules, Citation_Pool, or the domain whitelist

## After reading all 5 files

Perform the user's task below. While implementing:

- Follow every rule in the guideline files — the files are the contract
- Never remove features listed in plugin_UX.md — treat missing features as bugs to restore
- When changes affect anything documented in a guideline file, **update that file in the same commit** as the code change
- If the code and a guideline file disagree, the code wins — but the guideline is out of date and must be corrected immediately
- After changes: rebuild the zip to `/Users/ben/Desktop/seobetter.zip`, commit, push to GitHub
- Verify the plugin_UX.md checklist before declaring the task complete
