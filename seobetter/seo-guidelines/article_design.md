# SEOBetter Article Design Reference

> **Purpose:** Defines how generated articles should look — both in the plugin preview and when saved as WordPress drafts. All formatting uses **inline styles** for maximum compatibility across themes.
>
> Referenced by: `Content_Formatter.php`, `Async_Generator.php`, `admin/views/content-generator.php`

---

## 1. DESIGN PRINCIPLES

1. **Inline styles only** — no `<style>` blocks, no CSS classes. WordPress Classic blocks strip `<style>` tags.
2. **Theme-agnostic** — must look good on ANY WordPress theme (light or dark, serif or sans-serif)
3. **Mobile-first** — all elements must be responsive. Use `max-width:100%`, no fixed widths wider than 100%.
4. **Scannable** — visitors scan before reading. Use visual hierarchy to guide the eye.
5. **Accessible** — minimum 4.5:1 contrast ratio for text. No color-only indicators.

---

## 2. WORDPRESS DESIGN TOKENS (Reference)

These are WordPress's built-in spacing, typography, and color values. Our inline styles should align with these when possible.

### Spacing Scale
| Token | Value | Use For |
|---|---|---|
| `--wp--preset--spacing--20` | 0.44rem (7px) | Tight gaps, small padding |
| `--wp--preset--spacing--30` | 0.67rem (11px) | Compact spacing |
| `--wp--preset--spacing--40` | 1rem (16px) | Standard spacing |
| `--wp--preset--spacing--50` | 1.5rem (24px) | Section padding |
| `--wp--preset--spacing--60` | 2.25rem (36px) | Large section gaps |
| `--wp--preset--spacing--70` | 3.38rem (54px) | Hero/major section gaps |

### Font Sizes
| Token | Value | Use For |
|---|---|---|
| `--wp--preset--font-size--small` | 13px | Captions, metadata, fine print |
| `--wp--preset--font-size--medium` | 20px | Body text (default) |
| `--wp--preset--font-size--large` | 36px | H2 headings |
| `--wp--preset--font-size--x-large` | 42px | H1 headings |

### Shadow Presets
| Name | Value |
|---|---|
| Natural | `6px 6px 9px rgba(0,0,0,0.2)` |
| Deep | `12px 12px 50px rgba(0,0,0,0.4)` |
| Sharp | `6px 6px 0px rgba(0,0,0,0.2)` |
| Outlined | `6px 6px 0px -3px rgba(255,255,255,1), 6px 6px rgba(0,0,0,1)` |
| Crisp | `6px 6px 0px rgba(0,0,0,1)` |

---

## 3. ARTICLE ELEMENT STYLES

### 3.1 Last Updated Line
```html
<p style="color:#6b7280;font-size:0.85em;font-style:italic;margin-bottom:0.5em">Last Updated: April 2026</p>
```

### 3.2 H1 — Article Title
```html
<h1 style="font-size:2em;font-weight:800;line-height:1.2;margin:0 0 0.5em;color:#111827">Title Here</h1>
```
- Font size: 2em (scales with theme)
- Font weight: 800 (extra bold)
- Color: near-black (#111827) — works on all themes
- No accent color on H1 (let theme handle it)

### 3.3 H2 — Section Headings
```html
<h2 style="font-size:1.5em;font-weight:700;line-height:1.3;color:{accent};margin:2em 0 0.75em;padding-bottom:0.4em;border-bottom:2px solid {accent}22">Heading</h2>
```
- Accent color on text + subtle border-bottom
- 2em top margin for breathing room between sections
- `{accent}22` = accent color at ~13% opacity for border

### 3.4 H3 — Sub-headings
```html
<h3 style="font-size:1.2em;font-weight:700;line-height:1.4;color:#1f2937;margin:1.5em 0 0.5em">Sub-heading</h3>
```

### 3.5 Paragraphs
```html
<p style="line-height:1.75;margin:0 0 1.25em;color:#374151;font-size:1.05em">Text here</p>
```
- Line-height 1.75 for comfortable reading
- Slightly larger than default (1.05em) for readability
- Dark gray (#374151) not pure black — easier on eyes

### 3.6 Key Takeaways Box
```html
<div style="border-left:4px solid {accent};background:linear-gradient(135deg,#f8f9ff 0%,#f0f0ff 100%);padding:1.25em 1.5em;border-radius:0 8px 8px 0;margin:1.5em 0">
  <ul style="line-height:1.8;padding-left:1.25em;margin:0">
    <li style="margin-bottom:0.5em"><strong>Takeaway text here</strong></li>
  </ul>
</div>
```
- Left border accent stripe = visual anchor
- Subtle gradient background = premium feel
- Rounded corners (right side only) = modern design

### 3.7 Blockquotes (Expert Quotes)
```html
<blockquote style="border-left:4px solid {accent};margin:1.5em 0;padding:1em 1.5em;background:#f9fafb;border-radius:0 8px 8px 0;font-style:italic;font-size:1.05em;color:#4b5563;line-height:1.7">
  <p>"Quote text here," says <strong>Dr. Name</strong>, Title at Organization.</p>
</blockquote>
```
- Same left-border pattern as Key Takeaways for consistency
- Slightly larger text (1.05em) for emphasis
- Italic to distinguish from body text

### 3.8 Comparison Tables
```html
<div style="overflow-x:auto;margin:1.5em 0">
  <table style="width:100%;border-collapse:collapse;font-size:0.95em;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1)">
    <thead>
      <tr>
        <th style="background:{accent};color:#ffffff;padding:0.75em 1em;text-align:left;font-weight:600;font-size:0.9em;text-transform:uppercase;letter-spacing:0.05em">Header</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td style="padding:0.75em 1em;border-bottom:1px solid #e5e7eb;color:#374151">Cell</td>
      </tr>
      <tr>
        <td style="padding:0.75em 1em;border-bottom:1px solid #e5e7eb;color:#374151;background:#f9fafb">Even row</td>
      </tr>
    </tbody>
  </table>
</div>
```
- Wrapper `overflow-x:auto` for mobile scroll
- Rounded corners via `overflow:hidden`
- Subtle shadow for depth
- Accent color header with white text
- Zebra striping on even rows
- Uppercase, letter-spaced headers = professional

### 3.9 Unordered Lists
```html
<ul style="line-height:1.8;padding-left:1.5em;margin:1em 0;color:#374151">
  <li style="margin-bottom:0.5em"><strong>Key term</strong> — explanation text here</li>
</ul>
```
- Bold lead-in pattern: `<strong>Term</strong> — description`
- Generous line-height for scanability

### 3.10 Ordered Lists
```html
<ol style="line-height:1.8;padding-left:1.5em;margin:1em 0;color:#374151">
  <li style="margin-bottom:0.5em"><strong>Step name</strong>: action description</li>
</ol>
```

### 3.11 Images
```html
<figure style="margin:1.5em 0;text-align:center">
  <img src="URL" alt="Descriptive alt text" width="800" height="450" loading="lazy" decoding="async" style="max-width:100%;height:auto;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1)" />
</figure>
```
- Rounded corners match table style
- Subtle shadow for depth
- `max-width:100%` for mobile responsiveness
- Lazy loading for performance

### 3.12 Horizontal Rules (Section Dividers)
```html
<hr style="border:none;border-top:2px solid #e5e7eb;margin:2.5em 0" />
```

### 3.13 Inline Links
```html
<a href="URL" style="color:{accent};text-decoration:underline;text-underline-offset:2px">Link text</a>
```
- Accent color for links
- Underline with offset for readability

### 3.14 CTA Buttons (Affiliate)
```html
<div style="margin:1.25em 0">
  <a href="URL" rel="nofollow sponsored" target="_blank" style="display:inline-block;padding:0.875em 1.75em;background:{accent};color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;font-size:0.95em;box-shadow:0 2px 4px rgba(0,0,0,0.15);transition:opacity 0.2s">
    Check Prices &rarr;
  </a>
</div>
```
- Accent color background
- Rounded corners
- Subtle shadow for depth
- Arrow indicator for action

### 3.15 FAQ Section
```html
<h3 style="font-size:1.1em;font-weight:700;color:#1f2937;margin:1.5em 0 0.5em">What is the question?</h3>
<p style="line-height:1.75;margin:0 0 1.25em;color:#374151;font-size:1.05em">Direct answer paragraph (40-60 words).</p>
```
- Same paragraph style as body text
- H3 headings for each Q&A pair

---

## 4. COLOR SYSTEM

### Accent Color
- Default: `#764ba2` (purple)
- User-configurable via accent_color setting
- Used for: H2 borders, H2 text, blockquote borders, Key Takeaways border, table headers, CTA buttons, links
- Must have 4.5:1 contrast against white for table headers

### Neutral Colors (Fixed — theme-agnostic)
| Use | Color | Hex |
|---|---|---|
| Body text | Dark gray | `#374151` |
| Headings (H3+) | Near-black | `#1f2937` |
| Muted text (dates, captions) | Medium gray | `#6b7280` |
| Table even rows | Light gray | `#f9fafb` |
| Borders/dividers | Border gray | `#e5e7eb` |
| Blockquote background | Off-white | `#f9fafb` |
| Key Takeaways gradient start | Light blue | `#f8f9ff` |
| Key Takeaways gradient end | Lighter blue | `#f0f0ff` |

---

## 5. TYPOGRAPHY SCALE

| Element | Size | Weight | Line Height |
|---|---|---|---|
| H1 | 2em | 800 | 1.2 |
| H2 | 1.5em | 700 | 1.3 |
| H3 | 1.2em | 700 | 1.4 |
| Body paragraph | 1.05em | 400 | 1.75 |
| Table header | 0.9em | 600 | 1.4 |
| Table cell | 0.95em | 400 | 1.5 |
| Small/muted | 0.85em | 400 | 1.5 |
| Blockquote | 1.05em | 400 | 1.7 |
| CTA button | 0.95em | 600 | 1.4 |

All sizes use `em` units so they scale with the theme's base font size.

---

## 6. SPACING SYSTEM

| Context | Value | Description |
|---|---|---|
| Between sections (before H2) | `2em` | Major breathing room |
| After H2 | `0.75em` | Tight to section content |
| Between paragraphs | `1.25em` | Comfortable reading flow |
| Before H3 | `1.5em` | Sub-section gap |
| After H3 | `0.5em` | Tight to sub-section content |
| List items | `0.5em` | Compact but readable |
| Table cell padding | `0.75em 1em` | Comfortable touch targets |
| Key Takeaways padding | `1.25em 1.5em` | Box breathing room |
| Blockquote padding | `1em 1.5em` | Quote breathing room |
| Image margin | `1.5em 0` | Visual separation |
| CTA button padding | `0.875em 1.75em` | Comfortable click target |
| Border radius | `8px` | Consistent rounded corners |

---

## 7. RESPONSIVE CONSIDERATIONS

### Tables
- Wrap in `<div style="overflow-x:auto">` for horizontal scroll on mobile
- Minimum cell padding for touch targets

### Images
- Always `max-width:100%;height:auto`
- Width/height attributes prevent CLS
- `loading="lazy"` for performance

### Text
- All sizes in `em` — scales with theme
- Line heights generous (1.7-1.8) for mobile reading
- No fixed widths on text containers

---

## 8. PREVIEW vs DRAFT — TWO DIFFERENT FORMATS

### Admin Preview (in plugin) — Classic HTML with inline styles
- Uses inline styles on every element (as defined in Section 3)
- Wrapped in `<div class="seobetter-content-preview">`
- `<style>` block extracted separately before `wp_kses_post()`
- Looks styled and polished in the admin panel

### WordPress Draft (saved post) — Gutenberg Blocks
- Uses native Gutenberg block markup (`<!-- wp:heading -->`, `<!-- wp:paragraph -->`, etc.)
- NO inline styles — Gutenberg/theme handles all styling
- Each element is a proper block that users can edit individually
- NO `wp_kses_post()` — it strips block comments

### Why Two Formats?
- **Preview needs inline styles** because it renders in a plain admin `<div>` with no theme CSS
- **Draft needs Gutenberg blocks** because `wp_kses_post()` strips inline styles, and blocks give users edit control

### Gutenberg Block Patterns Used

#### Heading Block
```html
<!-- wp:heading -->
<h2 class="wp-block-heading">Section Title</h2>
<!-- /wp:heading -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Sub Title</h3>
<!-- /wp:heading -->
```

#### Paragraph Block
```html
<!-- wp:paragraph -->
<p>Body text with <strong>bold</strong> and <em>italic</em>.</p>
<!-- /wp:paragraph -->
```

#### List Block
```html
<!-- wp:list -->
<ul><li>Item one</li><li>Item two</li></ul>
<!-- /wp:list -->

<!-- wp:list {"ordered":true} -->
<ol><li>Step one</li><li>Step two</li></ol>
<!-- /wp:list -->
```

#### Table Block (Striped Style)
```html
<!-- wp:table {"hasFixedLayout":true,"className":"is-style-stripes"} -->
<figure class="wp-block-table is-style-stripes"><table class="has-fixed-layout"><thead><tr><th>Header</th></tr></thead><tbody><tr><td>Cell</td></tr></tbody></table></figure>
<!-- /wp:table -->
```

#### Quote Block
```html
<!-- wp:quote -->
<blockquote class="wp-block-quote"><p>Quote text here.</p></blockquote>
<!-- /wp:quote -->
```

#### Image Block
```html
<!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="URL" alt="Alt text"/></figure>
<!-- /wp:image -->
```

#### Separator Block
```html
<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->
```

### Block Markup Rules (CRITICAL)
1. JSON attributes in `<!-- wp:block {JSON} -->` MUST exactly match the HTML attributes
2. Use MINIMAL attributes — don't add `style`, `className`, or other attributes unless needed
3. Simpler blocks = fewer validation failures
4. Don't add extra classes that Gutenberg doesn't expect
5. Always include the closing comment `<!-- /wp:block -->`
6. Use double newlines between blocks for readability

---

## 9. FEATURED IMAGE HANDLING

### Current Implementation
- Unsplash images inserted inline via `Stock_Image_Inserter.php`
- 800x450px, WebP format via CDN params
- SEO-optimized alt text based on keyword + heading context

### Future Enhancement (Featured Image)
WordPress featured images use `set_post_thumbnail()`:
```php
// After wp_insert_post(), set featured image:
$image_url = 'https://source.unsplash.com/1200x630/?' . urlencode($keyword);
$image_id = media_sideload_image($image_url, $post_id, $keyword . ' featured image', 'id');
if (!is_wp_error($image_id)) {
    set_post_thumbnail($post_id, $image_id);
}
```
- 1200x630px for OG image / Google Discover compatibility
- Requires `media_sideload_image()` (downloads to media library)
- Alt text = keyword + " — featured guide image"

---

## 10. DESIGN CHECKLIST (for Content_Formatter)

Every generated article MUST have:
- [ ] Inline styles on EVERY element (no classes, no style blocks)
- [ ] Accent color applied to: H2 text, H2 border, blockquote border, Key Takeaways border, table headers, CTA buttons
- [ ] Neutral colors for: body text (#374151), headings (#1f2937), muted text (#6b7280)
- [ ] Responsive tables with `overflow-x:auto` wrapper
- [ ] Responsive images with `max-width:100%`
- [ ] All sizes in `em` units
- [ ] Rounded corners (8px) on: tables, images, Key Takeaways box, blockquotes, CTA buttons
- [ ] Subtle shadows on: tables, images, CTA buttons
- [ ] Generous line-height (1.75 for body, 1.8 for lists)
- [ ] Zebra striping on table rows
- [ ] Bold key terms on first mention
- [ ] No `<style>` tags anywhere in the output
- [ ] No CSS class names in the output (except WordPress defaults if needed)

---

*This document defines the visual design language for all SEOBetter article output. Update when WordPress block editor standards change.*
