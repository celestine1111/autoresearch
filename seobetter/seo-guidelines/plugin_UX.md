# SEOBetter Plugin UX Specification

> **CODE WORD: When the user starts a prompt with `/seobetter` — READ ALL 4 .md files before coding.**
>
> **CRITICAL: This file is the single source of truth for the plugin's user interface.**
> **Before editing any UI code, read this file. After editing, verify nothing was removed.**
> **If a feature listed here is missing from the code, it's a bug — restore it.**
>
> **Last verified:** April 2026
> **Files:** `admin/views/content-generator.php`, `assets/js/editor-sidebar.js`

---

## 1. ARTICLE GENERATION PAGE — FORM FIELDS

### 1.1 Keywords Section
| Field | Type | Name/ID | Required | Notes |
|---|---|---|---|---|
| Primary Keyword | text input | `primary_keyword` | YES | Target keyword for the article |
| Auto-suggest | button | `seobetter-auto-keywords` | — | Populates secondary + LSI keywords from real Google Suggest + Datamuse (v1.5.22). v1.5.25: when both providers return zero (normal for ultra-long-tail keywords like "what's the best gelato shops in lucignano italy 2026"), the status shows a blue ℹ️ info message telling the user it's safe to leave the field empty — the AI pulls variations from the research pool during generation. |
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

### 3.1 GEO Score Dashboard (REQUIRED, LOCKED FORMAT v1.5.65+)

**Score Ring Specification — LOCKED FORMAT**

User feedback 2026-04-15: *"fix this styling across the board in all areas where it appears the letter is to small and not styled properly use some cool transition css"*. Previous design had `font-size: 36px` score above a `font-size: 12px` grade letter which looked unbalanced ("78" huge, "B" tiny). New design unifies the ring across every admin view and adds smooth motion.

- **SVG ring gauge — 150px** (was 130px)
  - Background ring: `stroke: {scoreRing}, stroke-width: 10` in a light variant of the score color (`#dcfce7` / `#fef3c7` / `#fee2e2`)
  - Foreground ring: `stroke: {scoreColor}, stroke-width: 10, stroke-linecap: round` in the active score color (`#22c55e` green ≥80 / `#f59e0b` amber 60-79 / `#ef4444` red <60)
  - Fill-in transition: `stroke-dasharray 1.2s cubic-bezier(0.4, 0, 0.2, 1)` — ring animates from 0 to final value over 1.2 seconds when the score first renders
  - Drop-shadow glow: `filter: drop-shadow(0 2px 8px {scoreColor}22)` — subtle colored shadow matching the score
- **Score number — 44px bold** (was 36px)
  - `font-weight: 800, letter-spacing: -0.02em, font-variant-numeric: tabular-nums`
  - `color: {scoreColor}`, same as the ring
  - Tabular numerals so 78 and 100 align visually
- **Grade badge — pill with filled background** (was plain 12px text)
  - Min-width 30px × 22px height, 11px border-radius (pill shape)
  - `background: {scoreColor}, color: #fff, font-size: 13px, font-weight: 800, letter-spacing: 0.05em`
  - `box-shadow: 0 1px 4px {scoreColor}55` — subtle colored shadow
  - Solid filled badge (like Key Takeaways/Pros/Cons eyebrow labels) so the letter reads clearly at a glance
- **"GEO Score" label — 11px uppercase**
  - `font-weight: 600, text-transform: uppercase, letter-spacing: 0.08em, color: #6b7280`
- **Hover effect** (content-generator + dashboard)
  - `.sb-geo-ring:hover { transform: translateY(-2px) scale(1.02) }` with `transition: 0.4s cubic-bezier(0.34, 1.56, 0.64, 1)` (slight spring-bounce)
- **Pop-in entry animation**
  - `@keyframes sb-score-pop { 0% { scale: 0.85; opacity: 0 } 60% { scale: 1.04 } 100% { scale: 1 } }` — 0.6s cubic-bezier entry when the score ring first renders

**Where this spec applies (LOCKED — do not change without updating this file):**

| Location | Element | Source |
|---|---|---|
| Content Generator results panel | `.sb-geo-ring-wrap + .sb-geo-ring` (inline-styled SVG) | [admin/views/content-generator.php::renderResult()](../admin/views/content-generator.php) line ~850 |
| Dashboard card | `.seobetter-score-circle` (div-based circle with border) | [admin/css/admin.css](../admin/css/admin.css) `.seobetter-score-circle` block |
| Bulk generator batch card | `.seobetter-score-circle` (same class) | [admin/views/bulk-generator.php](../admin/views/bulk-generator.php) |
| Text-only score badges (Posts list, Analytics) | `.seobetter-score.good/.ok/.poor` | [admin/css/admin.css](../admin/css/admin.css) `.seobetter-score` block — hover lift via `transform: translateY(-1px)` |
| Editor sidebar toolbar badge | `#seobetter-toolbar-badge` (inline styled) | [assets/js/editor-sidebar.js](../assets/js/editor-sidebar.js) injectToolbarBadge() — kept compact (32px) because it sits in the WordPress admin toolbar |

Both ring variants (SVG-based in content-generator, div-bordered in CSS class form for dashboard/bulk) share the `@keyframes sb-score-pop` entry animation and the hover translate/scale.

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

### 3.4 Analyze & Improve Panel (REQUIRED) — SINGLE BUTTON SYSTEM (v1.5.83+)

#### Before optimization: "⚡ Optimize All" button + compact fix summary
- **Header:** "Analyze & Improve" title + improvement count + potential points
- **One button:** "⚡ Optimize All" — gradient purple (`#764ba2` → `#667eea`), 40px height, right-aligned
- **Fix summary:** compact pill badges below the button showing what will be fixed (e.g. "Citations +10", "Table +5", "Readability +10") — NO individual action buttons
- **Progress bar:** 6px shimmer bar fills through 7 steps with labels + elapsed timer
- **Behavior:** single click runs ALL fixes via `POST /seobetter/v1/optimize-all`:
  1. ONE Perplexity Sonar call (server-side, Ben's key) for citations, quotes, stats, table
  2. Sequential injection of all 4 research categories
  3. AI readability simplification (user's model)
  4. AI keyword density optimization (user's model, only if > 1.5%)
  5. Single format/score pass at the end

#### After optimization: green summary panel
- **Header:** "Article Optimized" with green checkmark icon
- **Summary message:** what the optimize_all endpoint returned (e.g. "6 fixes applied (powered by Perplexity Sonar)")
- **Step detail pills:** green rounded badges for each step that ran, with specific details:
  - "✓ Citations: 5 citations added with inline anchor links"
  - "✓ Expert Quotes: 2 real quotes inserted from Sonar"
  - "✓ Comparison Table: 5 rows × 3 columns (real data from Sonar)"
  - "✓ Statistics: 4 real stats added from Sonar"
  - "✓ Readability: Simplified 3 sections: Grade 12.9 → 8.6"
  - "✓ Keyword Density: 7.1% → 4.69%"
- **Sonar badge:** "Powered by Perplexity Sonar — real research data" in purple
- **No individual buttons** — the panel is purely informational after optimization

### 3.4B Places Validator Debug Panel (v1.5.27, REQUIRED for local-intent keywords)
Color-coded banner rendered above the content preview in the result view when `res.places_validator.is_local_intent === true`. Three states:
- **Green** (real places found, listicle allowed) — verified Places Pool has ≥2 entries and the article was generated as a normal listicle
- **Amber** (places insufficient, article written as informational) — `places_insufficient` fired, the pre-generation switch forced an informational article, and the user sees a link to Settings → Places Integrations
- **Red** (force_informational, article structurally hallucinated) — Places_Validator's post-gen pass stripped >50% of sections and the article is gutted; critical suggestion is also prepended to the fixes list

Banner surfaces: `places_location`, `places_business_type`, `pool_size`, validator warnings, and (when amber) an explanation of why the listicle became informational. Primary diagnostic surface for users reporting "my Foursquare key isn't working" or "why are there still fake businesses". Source: [admin/views/content-generator.php](../admin/views/content-generator.php) inline `renderResult()` function around the content-preview block.
- **Two button modes:**
  - **"Add now" (purple)** — INJECT mode: adds new content WITHOUT editing existing text
  - **"Check" (gray)** — FLAG mode: shows what to fix manually, doesn't touch content

#### INJECT FIXES (5 buttons — add new content)
1. **Add Citations & References** (+10 pts) — Uses Citation Pool (DDG/Brave/Reddit/HN/Wikipedia) or Sonar-provided URLs via `optimize_all()`. Appends `## References` section + inline `[N]` anchor links. Zero hallucinated URLs. **Hidden when article already has citations** (v1.5.74b+): JS checks both the score AND whether the markdown already has a `## References` section with links OR the HTML has `<a href>` tags.
2. **Add Expert Quotes** (+6 pts) — v1.5.94: SCRAPED QUOTES ONLY. Real sentences extracted from real web pages by the Vercel scraper. Each quote has exact page text + verified source URL. NO LLM fallback — if the scraper found 0 quotes for this keyword, the step is skipped entirely. Zero hallucination guarantee.
3. **Add Statistics** (+10 pts) — Pulls real stats from Vercel research API or AI fallback. Via `optimize_all()`, stats come from Sonar with real source names.
4. **Add Comparison Table** (+5 pts) — v1.5.75+: AI generates markdown table with DYNAMIC columns (no longer hardcodes "Price Range"). Via `optimize_all()`, table data comes from Sonar with real product specs.
5. **Add Freshness Signal** (+6 pts) — Prepends "Last Updated: [Month Year]" to top of article. Skips if already present.

#### REWRITE FIXES (2 buttons — modify existing text via AI)
6. **Simplify Readability** (+10 pts) — AI rewrites sections with Flesch-Kincaid grade > 8 to grade 7. Breaks long sentences, swaps complex words ("use" not "utilize"), converts to active voice. Preserves all facts, citations, links. Per SEO-GEO-AI-GUIDELINES §5: targets grade 6-8.
7. **Optimize Keyword Density** (+10 pts) — AI replaces excess keyword mentions with pronouns/variations. Target: reduce from >1.5% to ~1.0%. Keeps first-paragraph keyword + H2 heading keywords. Auto-retries once if still above 1.5%. Per SEO-GEO-AI-GUIDELINES §5A.

#### REWRITE FIXES (continued)
8. **Fix Section Openings** (+8 pts) — v1.5.80+: converted from flag-mode to inject-mode. AI rewrites short section openers (< 30 words) to 40-60 words that directly answer the heading question. Per SEO-GEO-AI-GUIDELINES §3.2b. Caps at 4 rewrites per click to stay within timeout.

#### FLAG FIXES (2 buttons — show issues, user edits manually)
9. **Check Pronoun Starts** (+8 pts) — Lists each paragraph starting with It/This/They/These/Those/He/She/We with the violating word
10. **Check AI Patterns** (+4 pts) — Lists Tier-1/Tier-2 AI red-flag words found in the article

- Each card: icon + label + description + impact badge + button
- After successful inject: button turns green "✓ [count] added", card background turns `#f0fdf4`, GEO score updates
- **Applied-fix persistence (v1.5.67+):** applied fixes tracked in `window._seobetterAppliedFixes` across panel re-renders — completed fixes render as disabled green "Done" cards
- **Threshold-crossing persistence (v1.5.68+):** fixes that pass their score threshold after inject (and drop out of the `fixes[]` array) are re-injected as "Done" cards via `appliedLabels` lookup map so they remain visible instead of disappearing
- **No-scroll re-render (v1.5.69+):** after inject-fix success, `renderResult(res, skipScroll=true)` re-renders the panel WITHOUT scrolling to the top — user stays at their current position and can see the content preview update in place
- **"Changes applied" banner (v1.5.70+):** green banner appears above the content preview after each inject-fix showing what was done (e.g. "Simplified 4 sections: Grade 12.9 → 10.5"). Auto-clears on next render. Stored in `window._seobetterLastFixMessage`.
- **Progress bar loading on inject-fix buttons (v1.5.76+):** Tympanus-inspired horizontal bar fills across the button from left to right over 25s via `@keyframes sb-progress-fill` with eased cubic-bezier timing. Combined with: (a) CSS spinner circle, (b) elapsed time counter "Working 5s... 10s..." updated every second, (c) gradient shine effect on the bar. On success: bar snaps to 100% width (`.sb-btn-done` class) with green tint. On failure: error reason shown in red callout below button. Timer cleared via `clearInterval`.
- After flag check: button turns amber "See below", suggestions appear inline below the button
- Bottom info banner: "💡 Inject fixes add content without editing existing text. Check fixes show what to fix manually. Potential: +X points"
- **Implementation:** `includes/Content_Injector.php` class with 8 methods, REST endpoint `POST /seobetter/v1/inject-fix`

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
| Auto-suggest handler | Auto-suggest button click | Populates secondary/LSI keywords from real Google Suggest + Datamuse data (v1.5.22 — was LLM-based before; see BUILD_LOG) |
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
→ Formats markdown using format_hybrid() → native Gutenberg blocks
   + wp:html blocks for styled elements
→ Injects JSON-LD schema as wp:html block at end of post_content
→ Returns: post_id, edit_url, schema_dest
```

---

## 7. SAVED ARTICLE FORMAT (Hybrid)

### Editable Blocks (Native Gutenberg)
Users can edit these directly in the WordPress block editor:
- `wp:heading` — H1, H2, H3 headings
- `wp:paragraph` — body text paragraphs
- `wp:list` — standard bullet/numbered lists
- `wp:image` — images (centered, lazy loaded)
- `wp:separator` — horizontal rules

### Styled HTML Blocks (wp:html with inline styles)
These preserve visual styling but require HTML editing:
- **Key Takeaways** — gradient background, accent left border
- **Pros list** — green background (#f0fdf4), green border
- **Cons list** — red background (#fef2f2), red border
- **Ingredients list** — amber background (#fffbeb), amber border
- **Tip callout** — blue left border, blue background
- **Note callout** — amber left border, amber background
- **Warning callout** — red left border, red background
- **Tables** — accent colored headers, zebra striping, rounded corners
- **Blockquotes** — accent left border, gray background, italic

### Schema (wp:html)
- JSON-LD `<script type="application/ld+json">` appended at the end
- Contains Article/Review/Recipe/HowTo/FAQPage schema
- Present in EVERY article regardless of SEO plugin

### Styling Rules
- All styled elements use `!important` on colors to override theme CSS
- Inline styles only (no scoped `<style>` block in hybrid mode)
- Theme fonts and colors apply to editable blocks naturally

---

## 8. VERIFICATION CHECKLIST

After ANY code change to content-generator.php or seobetter.php, verify ALL of these:

### Plugin UI (content-generator.php)
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

### Saved Article (seobetter.php + Content_Formatter.php)
- [ ] Headings are editable wp:heading blocks
- [ ] Paragraphs are editable wp:paragraph blocks
- [ ] Standard lists are editable wp:list blocks
- [ ] Key Takeaways has styled gradient background
- [ ] Pros list has green background
- [ ] Cons list has red background
- [ ] Tables have accent colored headers
- [ ] Blockquotes have accent left border
- [ ] Tip/Note/Warning callouts have colored borders
- [ ] JSON-LD schema present at end of post_content
- [ ] Text is readable (dark on white) on published page
- [ ] All !important colors survive theme CSS

### Post Sidebar Panel — PluginDocumentSettingPanel (editor-sidebar.js)
All editor integration is in a single PluginDocumentSettingPanel (PluginSidebar crashes on WP Engine / WP 6.6+).

- [ ] Title: "SEOBetter: XX/100" in Post tab of right sidebar
- [ ] `initialOpen: true` — panel visible by default on post load
- [ ] **Score Ring** — animated SVG circle (100px), score number, grade, rating text:
  - 90+: "Excellent! 🔥🔥🔥"
  - 80+: "Great! 🔥🔥"
  - 70+: "Good 🔥"
  - 60+: "Needs work"
  - <60: "Improve this"
- [ ] **7 stat rows** with ✓/✗ pass/fail indicators:
  - 📝 Words (pass: ≥800)
  - ⏱ Read Time (always pass)
  - 📖 Readability Grade (pass: grade 6-10)
  - 🔗 Citations count/5 (pass: ≥5)
  - 💬 Quotes count/2 (pass: ≥2)
  - 📋 Tables count (pass: ≥1)
  - 🕐 Freshness Yes/No (pass: freshness signal present)
- [ ] **Headline Analyzer** (collapsible, click to expand/collapse):
  - Headline Type: List / How-to / Question / Comparison / Review / General
  - Character Count with Good/Too short/May truncate feedback
  - Word Count with 6-12 word target
  - Common Words % (goal: 20-30%)
  - Power Words % with found words listed (goal: at least one)
  - Emotional Words % with found words listed (goal: 10-15%)
  - Sentiment: Positive 😊 / Neutral 😐 / Negative 😟
  - Beginning Words (first 3) + Ending Words (last 3) as pills
- [ ] **Rich Results Preview** (collapsible, v1.5.133):
  - Google SERP preview card showing how the article appears in search results
  - Breadcrumb trail from schema
  - FAQ dropdown preview (if FAQ schema detected)
  - Recipe star rating preview (if Recipe schema detected)
  - Active rich result types list with checkmarks (Recipe card, FAQ dropdowns, Breadcrumb trail, Speakable, ItemList, Review stars)
  - Schema Impact Estimate section with research-backed statistics:
    - "+2.7x clicks with Recipe schema (Searchmetrics, 2024)"
    - "+87% CTR with FAQ schema (Ahrefs study)"
    - "+35% CTR with star ratings (Search Engine Journal)"
    - "+30-40% AI citation rate from structured data (Princeton GEO study)"
    - "Rich results get 58% of page 1 clicks (FirstPageSage, 2024)"
  - Schema validation status: errors + warnings count, valid/invalid badge
  - Link to Google Rich Results Test (opens in new tab with post URL pre-filled)
- [ ] **Re-analyze button** — clears cache and re-runs analysis
- [ ] Auto-loads analysis on post open

### Toolbar Score Badge (DOM injection, not React)
- [ ] Colored pill badge in editor header settings area (next to Save button)
- [ ] Shows 📊 icon + score/100
- [ ] Green (80+), amber (60+), red (<60) border + text color
- [ ] Injected via DOM manipulation (outside React error boundary — cannot crash plugin)
- [ ] Retries injection at 0ms, 1000ms, 3000ms (editor renders asynchronously)

### Architecture Notes (editor-sidebar.js)
- **Single registerPlugin call** — only `PluginDocumentSettingPanel` used
- **No PluginSidebar** — crashes on WP 6.6+ / WP Engine due to component resolution issues
- **No PluginPrePublishPanel** — same crash issue
- **Toolbar badge uses DOM injection** — not React, so it can't crash the plugin
- **Shared analysis cache** — `cachedData` variable avoids duplicate API calls
- **All ES5** — no arrow functions, no optional chaining, no destructuring

### Metabox Below Post Editor (seobetter.php — `register_metabox()`, `render_metabox()`)
AIOSEO-style settings panel that appears below the post content area on Post and Page edit screens.

- [ ] Registered via `add_meta_box()` on `add_meta_boxes` hook
- [ ] Title: "SEOBetter Settings" (normal context, low priority)
- [ ] Renders on both `post` and `page` screens
- [ ] **3 Tabs in header:**
  - General (default active)
  - Page Analysis
  - Readability
- [ ] **Score badge** in top-right of header — XX/100 + grade letter, color-coded
- [ ] **General Tab:**
  - SERP Preview card (site name + URL + blue title + description)
  - Focus Keyword input (saves to `_seobetter_focus_keyword` post meta)
  - 4-stat grid: GEO Score, Words, Citations, Quotes
- [ ] **Page Analysis Tab — 12 SEO checks with ✓/✗ icons:**
  1. Focus Keyword in content
  2. Focus keyword in introduction (with detail message if missing)
  3. Focus keyword in meta description
  4. Focus Keyword in URL
  5. Focus keyword length (3-50 chars)
  6. Meta description length (120-160 chars)
  7. Content length (≥300 words)
  8. Focus Keyword in Subheadings (30%+ rule)
  9. Focus keyword density (0.5%+ target, shows current density)
  10. Focus keyword in image alt
  11. Internal links (≥1)
  12. External links (≥1)
- [ ] **Readability Tab:**
  - Reading Grade level (target 6-8)
  - Island Test status (no pronoun starts)
  - Section Openings (40-60 word rule)
  - Top 5 prioritized suggestions
- [ ] Tab switching via vanilla JS (no React dependency)
- [ ] Focus keyword saves on `save_post` hook with nonce verification
- [ ] Reads existing keyword from SEOBetter, Yoast, or RankMath meta keys

---

## §8C — Places Integrations settings section (v1.5.24)

**Location:** [admin/views/settings.php](../admin/views/settings.php) — new card added after the main Settings card.

**Purpose:** Three optional API key fields for the v1.5.24 Places waterfall (Foursquare, HERE, Google Places) that ground local-intent articles in real business data instead of letting the LLM invent businesses.

**Fields (all optional, stored in `seobetter_settings` option):**
- `foursquare_api_key` — free 1K calls/day, no payment method required
- `here_api_key` — free 1K transactions/day, no payment method required
- `google_places_api_key` — paid but generous $200/month free credit (≈5,000 articles), requires Google Cloud billing account

**Required elements on the Places Integrations card:**
- [ ] Heading: "Places Integrations (Local Business Data)"
- [ ] Description paragraph explaining waterfall + coverage percentages
- [ ] Info callout: "All keys are OPTIONAL. Plugin works out of the box with free OSM + Wikidata"
- [ ] Row for OSM + Wikidata with "ALWAYS ON" badge and description
- [ ] Row for Foursquare with password input + "Get Free Key" button linking to developer.foursquare.com
- [ ] Row for HERE with password input + "Get Free Key" button linking to developer.here.com
- [ ] Row for Google Places with password input + "Get Key" button linking to console.cloud.google.com (note: PAID badge)
- [ ] Save button with form nonce `seobetter_places_nonce`
- [ ] "How the waterfall works" green info box at the bottom explaining the fallback order and the hard-refuse behavior

**Save handler:** `$_POST['seobetter_save_places']` with `seobetter_places_nonce`. Uses `array_merge` against existing settings so it doesn't wipe other settings sections.

**Whitelist dependency:** [seobetter.php::get_trusted_domain_whitelist()](../seobetter.php) must include all provider URL hosts (wikidata.org, foursquare.com, here.com, maps.google.com, etc) so `validate_outbound_links()` doesn't strip them from the saved draft References section. See [external-links-policy.md](../seo-guidelines/external-links-policy.md) for the full list.

---

## §8B — Single-source-of-truth rule for the result panel renderer (v1.5.19)

The article generator result panel (`#seobetter-async-result`) is rendered by **exactly one** JavaScript function: the `renderResult()` defined inline at [admin/views/content-generator.php](../admin/views/content-generator.php) ~line 744.

**There must be NO other `renderResult()` anywhere in the plugin.** v1.5.18 and earlier had a duplicate `renderResult()` in [admin/js/admin.js](../admin/js/admin.js) that was a stripped-down v1.5.10-era version. Both files attached click handlers to `#seobetter-async-generate`, both polled `/generate/step`, both called `fetchResult`, and both raced to write into the same result element. Whichever finished last won, and the legacy admin.js renderer (no graph, no bar charts, no fix buttons, no headline radio selector, broken Save Draft button submitting to a deleted handler) usually won. Deleted in v1.5.19.

**Process for any future result-panel changes:**

1. Edit the inline `renderResult()` in `content-generator.php` only
2. Do NOT add any JS that touches `#seobetter-async-generate`, `#seobetter-async-result`, or `/seobetter/v1/generate/*` to `admin/js/admin.js`
3. `admin/js/admin.js` is for cross-admin helpers only (API key visibility toggle, future settings-page helpers)
4. If you need to test that the single source rule still holds: `grep -n "renderResult\|seobetter-async-generate" seobetter/admin/js/admin.js` — the only matches should be in comments

---

## §9 — Standalone menu pages (added v1.5.13)

### §9.0 Domain dropdown sync rule (v1.5.15 — MANDATORY)

Three forms expose the **Category / Domain** dropdown: [content-generator.php](../admin/views/content-generator.php), [bulk-generator.php](../admin/views/bulk-generator.php), [content-brief.php](../admin/views/content-brief.php). They MUST share the **identical 25-option list** with identical values, identical labels, and identical order. The backend [research.js getCategorySearches()](../cloud-api/api/research.js) maps each value to a set of API fetchers — if a value isn't in the map, the article gets ZERO category APIs and silently degrades to generic Quotable/NagerDate/NumberFacts only.

**If you add, rename, or remove a category:**
1. Edit all 3 form files — same `<option value>` and same label
2. Edit `cloud-api/api/research.js` `getCategorySearches()` map to add/rename/remove the entry
3. Update [plugin_functionality_wordpress.md §1.3](plugin_functionality_wordpress.md) category table
4. Add a BUILD_LOG entry with the version anchor

**Drift = silent bug.** v1.5.15 fixed three drift bugs (8 vs 25 options, missing veterinary, duplicated law_government). Don't reintroduce them.

The 25 valid category values (v1.5.15+):
```
general, animals, art_design, blockchain, books, business, cryptocurrency,
currency, ecommerce, education, entertainment, environment, finance, food,
games, government, health, music, news, science, sports, technology,
transportation, veterinary, weather
```

The `law_government` value is a deprecated alias that still resolves to `government` in research.js for backwards compat. Do NOT add it back to the dropdown.



The plugin admin menu exposes 5 standalone tools beyond the main article generator. As of **v1.5.13** all 5 are temporarily ungated (FREE) so the user can test them end-to-end. Before public release, decide which stay free and which become Pro — see the comment block at the top of `License_Manager::FREE_FEATURES`.

Every page MUST follow the same header pattern: page title (24px, bold) + subtitle (14px, secondary), with a FREE/PRO badge and "Unlock Pro Features →" CTA on the right side. None of these pages may be silently removed — if a feature is regressed, restore it from BUILD_LOG.

### §9.1 Bulk Content Generator — `?page=seobetter-bulk`
- View: [admin/views/bulk-generator.php](../admin/views/bulk-generator.php)
- Backend: [includes/Bulk_Generator.php](../includes/Bulk_Generator.php) — `parse_csv()`, `parse_textarea()`, `create_batch()` (returns int batch_id), `process_next()`, `get_batch()`
- REST: `POST /seobetter/v1/bulk-process/{batch_id}` → [seobetter.php::rest_bulk_process()](../seobetter.php#L616)
- UI elements (must be present):
  - CSV upload field + textarea ("paste keywords, one per line")
  - Word count, tone, domain selectors
  - "Start Bulk Generation" submit button
  - Batch progress card with: batch ID, status badge, percentage progress bar, per-item table (keyword, status, post title link, GEO score)
  - JS poller hits `/bulk-process/{id}` every 3s until status is `completed` or `failed`
- Wiring rule: the view MUST use `Bulk_Generator::parse_csv()` / `parse_textarea()` to build the structured rows array — never hand-roll into a flat array of strings (that's the bug v1.5.13 fixed)

### §9.2 Content Brief — `?page=seobetter-content-brief`
- View: [admin/views/content-brief.php](../admin/views/content-brief.php)
- Backend: [includes/Content_Brief_Generator.php](../includes/Content_Brief_Generator.php) — `generate( $keyword, $options )` returns flat array
- UI elements:
  - Keyword + audience + domain + word_count form
  - Result panel with: title, keywords (primary/secondary/LSI tags), search intent badge, content outline (H2 list with subheadings), required elements checklist, competitor analysis table, content gap opportunities
  - Action buttons: Copy Brief, Export as Text, Generate Article from Brief
- Wiring contract (do not break): backend returns `title`, `secondary_keywords` (array), `lsi_keywords` (array), `intent_type`, `intent_description`, `outline` (array of `['heading', 'subheadings']`), `required_elements`, `competitors` (array of `['domain', 'analysis']`), `content_gap`

### §9.3 Citation Tracker — `?page=seobetter-citation-tracker`
- View: [admin/views/citation-tracker.php](../admin/views/citation-tracker.php)
- Backend: [includes/Citation_Tracker.php](../includes/Citation_Tracker.php) — `check_post( $post_id )`
- REST: `POST /seobetter/v1/citation-check/{post_id}` → [seobetter.php::rest_citation_check()](../seobetter.php#L592)
- UI elements:
  - Site-wide summary card (Published Posts, Posts Cited by AI, Citation Rate %)
  - Result card after a check: visibility score circle, Cited badge (YES/NO), reason text, top-10 competitor table
  - Bottom posts list with per-row "Check Citations" button
- Wiring contract: backend return shape is `['success', 'is_cited', 'cite_reason', 'visibility_score', 'position', 'competitors[].is_you', 'checked_at']`; cache meta key is `_seobetter_citation_check`. The view MUST read `is_cited`/`cite_reason`/`is_you` (not `cited`/`reason`/`cited` — that was the v1.5.13 bug)

### §9.4 Internal Link Suggestions — `?page=seobetter-link-suggestions`
- View: [admin/views/link-suggestions.php](../admin/views/link-suggestions.php)
- Backend: [includes/Internal_Link_Suggester.php](../includes/Internal_Link_Suggester.php) — `suggest_for_post( $post_id, $max )`
- REST: `POST /seobetter/v1/link-suggestions/{post_id}` → [seobetter.php::rest_link_suggestions()](../seobetter.php#L600)
- UI elements:
  - Info card with total published post count + "site-wide overview coming in a future release" note (the orphan-counter feature is deferred until a crawl job ships)
  - Post selection dropdown + "Get Suggestions" button
  - Result table: target post, suggested anchor text (code style), relevance bar+score, **Copy** button (puts markdown link `[anchor](url)` on clipboard)
- Wiring contract: backend returns key `suggestions` (NOT `links` — that was the v1.5.13 bug). Each row has `target_post_id`, `target_title`, `target_url`, `anchor_text`, `relevance_score`, `geo_score`, `edit_url`
- Known v1.5.13 limitation: there is no `/seobetter/v1/insert-link` REST endpoint. The button is **Copy** (clipboard) not **Insert**. Insert-into-post is on the backlog.

### §9.5 Keyword Cannibalization Detector — `?page=seobetter-cannibalization`
- View: [admin/views/cannibalization.php](../admin/views/cannibalization.php)
- Backend: [includes/Cannibalization_Detector.php](../includes/Cannibalization_Detector.php) — `detect()`
- REST: `POST /seobetter/v1/cannibalization` → [seobetter.php::rest_cannibalization()](../seobetter.php#L608)
- UI elements:
  - "Scan for Cannibalization" submit button
  - Summary card: keyword conflicts count + affected posts count + last scanned timestamp
  - Per-conflict card: keyword heading, similarity %, recommendation badge (merge / consolidate / differentiate), conflicting posts table (title, URL, words, GEO score, published date, Edit button)
- Wiring contract: backend returns `['success', 'conflicts', 'total_conflicts', 'total_posts_affected']`. Each conflict has `keyword`, `posts[]` (each with `post_id`, `title`, `url`, `edit_url`, `published`, `word_count`, `geo_score`), `count`, `recommendation` (an **array** with `action`/`keep`/`message`/`severity` — NOT a string), and `similarity` (NOT `similarity_score` — that was the v1.5.13 bug)
- Caching: after a successful scan the view persists results to `seobetter_cannibalization_results` option with an injected `scanned_at` timestamp so revisits skip re-scanning

---

*This document is the definitive UI specification. NEVER remove a feature listed here without explicit user approval. When editing content-generator.php, seobetter.php, Content_Formatter.php, editor-sidebar.js, or any of the 5 admin/views/*.php files in §9, use this file as the reference to verify nothing was lost.*
