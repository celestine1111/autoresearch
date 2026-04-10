# SEOBetter Plugin UX Specification

> **CRITICAL: This file is the single source of truth for the plugin's user interface.**
> **Before editing any UI code, read this file. After editing, verify nothing was removed.**
> **If a feature listed here is missing from the code, it's a bug — restore it.**
>
> **Last verified:** April 2026
> **File:** `admin/views/content-generator.php`

---

## 1. ARTICLE GENERATION PAGE — FORM FIELDS

### 1.1 Keywords Section
| Field | Type | Name/ID | Required | Notes |
|---|---|---|---|---|
| Primary Keyword | text input | `primary_keyword` | YES | Target keyword for the article |
| Auto-suggest | button | `seobetter-auto-keywords` | — | Generates secondary + LSI keywords |
| Secondary Keywords | text input | `secondary_keywords` | no | Comma-separated related phrases |
| LSI / Semantic Keywords | text input | `lsi_keywords` | no | Comma-separated semantic terms |

### 1.2 Article Settings Section
| Field | Type | Name/ID | Required | Options | Notes |
|---|---|---|---|---|---|
| Content Type | select | `content_type` | no | 21 types (Blog Post default) | Auto-adjusts tone + word count |
| Word Count | select | `word_count` | no | 800/1000/1500/2000/2500/3000 | Shows AI search guidance per option |
| Tone | select | `tone` | no | Authoritative/Conversational/Professional/Educational/Journalistic | Changes writing voice via get_tone_guidance() |
| Category | select | `domain` | YES | 25 categories | Triggers category-specific APIs + affects AI prompts |
| Target Audience | text input | `audience` | no | Free text | Injected into every section prompt |
| Accent Color | color picker | `accent_color` | no | Default: #764ba2 | Affects headings, borders, tables, links |
| Country & Language | searchable dropdown | `country` + `language` (hidden) | no | 90+ countries with flags | Sets article language + triggers country APIs |

### 1.3 Generate Button
- Single button: **"Generate Article"** (primary style, 50px height)
- No outline button (replaced by Analyze & Improve post-generation)

---

## 2. PROGRESS PANEL (During Generation)

Appears when Generate is clicked. Contains:

| Element | ID | Purpose |
|---|---|---|
| Title | `seobetter-progress-title` | "Generating article..." |
| Timer | `seobetter-progress-time` | Elapsed time (M:SS) |
| Progress bar | `seobetter-progress-bar` | Gradient bar with percentage |
| Status label | `seobetter-progress-label` | Current step description |
| Step counter | `seobetter-progress-steps` | "Step 3 of 8" |
| Error panel | `seobetter-progress-error` | Hidden; shows on failure |
| Error message | `seobetter-progress-error-msg` | Error text |
| Retry button | `seobetter-retry-btn` | Resumes generation |
| Time estimate | `seobetter-progress-estimate` | "Estimated: ~3 min" |

---

## 3. RESULTS SECTION (After Generation) — MUST ALL BE PRESENT

### 3.1 GEO Score Dashboard (REQUIRED)
- **SVG ring gauge** — animated circular score display (130px)
  - Background ring in light color
  - Foreground ring in score color (green/amber/red)
  - Center: score number (36px bold) + grade letter
- **3 stat cards** in a grid:
  - Words count (formatted with locale)
  - Citations count (green if >=5, red if not)
  - Expert Quotes count (green if >=2, red if not)
- **11 horizontal bar charts** showing individual scores:
  1. Readability (12pt weight)
  2. Citations (12pt)
  3. Statistics (12pt)
  4. Key Takeaways (10pt)
  5. Section Openers (10pt)
  6. Island Test (10pt)
  7. Expert Quotes (8pt)
  8. Entity Density (8pt)
  9. Freshness (7pt)
  10. Tables (6pt)
  11. Lists (5pt)

### 3.2 Pro Upsell Banner (REQUIRED when score < 80)
- Gradient background (purple/blue)
- Lightning bolt icon
- Text: "+X points needed for A grade"
- Description of Pro benefits
- **"Upgrade to Pro"** button linking to settings

### 3.3 Suggestions (REQUIRED)
- **High priority** — red left border, light red background, shown expanded
- **Medium priority** — amber left border, collapsible `<details>` with count

### 3.4 Analyze & Improve Panel (REQUIRED)
- Header: "Analyze & Improve" with improvement count
- PRO badge if not pro
- **8 possible fix cards** (shown based on check scores):
  1. Simplify Readability (+12 pts)
  2. Add Citations (+12 pts)
  3. Add Expert Quotes (+8 pts)
  4. Add Statistics (+12 pts)
  5. Add Comparison Table (+6 pts)
  6. Add Freshness Signal (+7 pts)
  7. Fix Section Openings (+10 pts)
  8. Fix Pronoun Starts (+10 pts)
- Each card: icon + label + description + impact badge + button
  - **Pro users:** "Fix now" button (calls AI to fix that specific issue)
  - **Free users:** "Upgrade" button linking to settings
- Total impact summary at bottom with "Upgrade to Pro →" link

### 3.5 Content Preview (REQUIRED)
- Full styled HTML article preview
- `<style>` block extracted and output separately
- Inside `<div class="seobetter-content-preview">`

### 3.6 Headline Selector (REQUIRED)
- Container with header: "Select Headline (ranked by SEO + GEO + AI snippet score)"
- Subtext: "Click to select as your post title. #1 is recommended."
- **5 headlines as radio buttons**, sorted by score (best first):
  - Score based on: length, keyword position, number, year, question format, power words, structure
  - Each shows: text (bold if #1), score/100, character count
  - Tag pills below each: "ideal length", "keyword first", "has number", etc.
  - First headline pre-selected

### 3.7 Save Draft Section (REQUIRED)
- "Save as" label
- Post type dropdown: Post / Page
- **"Save Draft"** button (primary style, 44px)
- Status message area (shows "Saved!" + edit link + schema destination)
- Schema destination feedback: "Schema → AIOSEO" / "Schema → Yoast" / "Schema → SEOBetter"

---

## 4. SIDEBAR (Right Column)

### 4.1 GEO Tips Card
- "What Makes Content Rank"
- 5 items with impact percentages from Princeton research
- Source credit

### 4.2 Pro Upsell Card (if not Pro)
- "Unlock Pro"
- Feature list
- Upgrade button

### 4.3 Topic Suggester
- "Need Ideas?"
- Niche input + "Suggest 10 Topics" button
- Results list with "Generate →" links per topic

### 4.4 Built-in Protocol Card
- "Every Article Includes"
- 10-item checklist of features

---

## 5. INTERACTIVE FEATURES (JavaScript)

| Function | Trigger | Purpose |
|---|---|---|
| `sbContentTypeChanged()` | Content Type dropdown change | Auto-adjusts tone + word count |
| Auto-suggest handler | Auto-suggest button click | Generates secondary/LSI keywords |
| Social content generator | Social button click | Generates Twitter/LinkedIn/Instagram content |
| Topic suggester | Suggest Topics button click | Generates 10 topic ideas |
| `sbRenderCountries()` | Country search input | Filters country dropdown |
| `sbSelectCountry()` | Country item click | Sets country + language |
| `renderResult()` | Generation complete | Renders ALL result components |
| `processNext()` | After each step | Continues async generation |
| `fetchResult()` | After last step | Loads final results |
| Save button handler | Save Draft click | Creates WordPress draft |
| Fix Now handlers | Fix button click | Sends targeted AI fix |
| Retry handler | Retry button click | Resumes failed generation |

---

## 6. DATA FLOW (Form → API → Results)

```
Form fields collected by JavaScript:
  keyword, secondary_keywords, lsi_keywords, content_type,
  word_count, tone, domain, audience, country, language, accent_color

→ POST /seobetter/v1/generate/start
→ Async steps: trends → outline → sections → headlines → meta → assemble
→ GET /seobetter/v1/generate/result
→ renderResult(res) with: content, markdown, geo_score, grade,
   word_count, suggestions, checks, headlines, meta

Save Draft:
→ POST /seobetter/v1/save-draft with: title, markdown, content,
   accent_color, keyword, content_type, meta_title, meta_description,
   og_title, post_type
→ Returns: post_id, edit_url, schema_dest
```

---

## 7. VERIFICATION CHECKLIST

After ANY code change to content-generator.php, verify ALL of these render:

- [ ] Form: all 11 fields present and collecting data
- [ ] Progress panel appears during generation
- [ ] GEO score SVG ring renders with score
- [ ] 3 stat cards show (Words, Citations, Quotes)
- [ ] 11 bar charts show with scores
- [ ] Pro upsell shows when score < 80
- [ ] Suggestions show (high priority expanded, medium collapsed)
- [ ] Analyze & Improve panel shows with fix cards
- [ ] Fix Now buttons work (Pro) / Upgrade buttons show (Free)
- [ ] Content preview renders with styled HTML
- [ ] 5 headlines show as radio buttons with scores
- [ ] Save section shows with Post/Page dropdown
- [ ] Save button creates draft and shows edit link
- [ ] Schema destination feedback shows after save
- [ ] Sidebar cards all present (tips, upsell, topics, protocol)

---

*This document is the definitive UI specification. NEVER remove a feature listed here without explicit user approval. When editing content-generator.php, use this file as the reference to verify nothing was lost.*
