---
description: Load all 5 SEOBetter guideline files before making plugin changes
argument-hint: [what you want to do]
---

You are working on the SEOBetter WordPress plugin. Before making ANY code changes, you MUST read all 5 guideline files in this exact order so you have the full project context:

1. **Read** `seobetter/seo-guidelines/plugin_UX.md`
   - UI verification checklist — every feature listed here must still exist after your changes
   - If a feature is missing from the code after you edit, that's a bug — restore it

2. **Read** `seobetter/seo-guidelines/article_design.md`
   - Article HTML/styling specification (typography, color system, image placement, references, content-type variations)
   - Enforces the design checklist that every generated article must pass

3. **Read** `seobetter/seo-guidelines/plugin_functionality_wordpress.md`
   - Complete technical reference for all plugin systems (research pipeline, AI generation, formatting, schema, GEO scoring, UI, humanizer, multilingual, URL integrity)

4. **Read** `seobetter/seo-guidelines/SEO-GEO-AI-GUIDELINES.md`
   - SEO/GEO rules for content generation — the master spec
   - Sections 1-30 cover GEO visibility, article structure, extractability blocks, humanizer, keyword density, scoring rubric, schema, E-E-A-T, AI snippet optimization, international SEO

5. **Read** `seobetter/seo-guidelines/external-links-policy.md`
   - Outbound link / citation / whitelist rules
   - Research Pool architecture, 4 enforcement layers, 13 failure modes
   - MANDATORY reading if touching `validate_outbound_links`, `Content_Injector::inject_citations`, the AI URL prompt rules, the citation pool, the domain whitelist, or the check-citations skill

## After reading all 5 files

Then perform the user's task below. While implementing:

- Follow every rule in the guideline files — the files are the contract
- Never remove features listed in `plugin_UX.md` — treat missing features as bugs to restore
- When changes affect anything documented in a guideline file, **update that file in the same commit** as the code change
- If the code and the guideline files disagree, the code wins — but that means the guideline is out of date and must be corrected immediately
- After changes: rebuild the zip to `/Users/ben/Desktop/seobetter.zip`, commit, push to GitHub
- Verify the `plugin_UX.md` checklist before declaring the task complete

## User's task

$ARGUMENTS
