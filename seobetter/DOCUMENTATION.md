# SEOBetter — User Guide

> **Version:** 1.5.188+
> **Requires:** WordPress 6.0+, PHP 8.0+
> **Recommended hosting:** VPS or managed WordPress (shared hosting may throttle during generation)

---

## 1. Installation

1. Download `seobetter.zip` from your account
2. In WordPress admin: **Plugins → Add New → Upload Plugin**
3. Choose the zip file and click **Install Now**
4. Click **Activate**
5. Go to **Settings → Permalinks** and click **Save Changes** (required for REST API)

---

## 2. Initial Setup (5 minutes)

### Step 1: Connect an AI Provider

Go to **SEOBetter → Settings → AI Providers**

You need ONE AI provider to write articles. The plugin supports 7 providers:

| Provider | Cost | Recommendation |
|---|---|---|
| **OpenRouter** (300+ models) | Pay-per-use | Best option — access to GPT-4.1, Claude, Gemini, Llama all in one key |
| **OpenAI** | Pay-per-use | Direct GPT-4.1 access |
| **Anthropic** | Pay-per-use | Direct Claude access |
| **Google Gemini** | Free tier available | 1,500 free requests/day |
| **Groq** | Free tier available | Fast Llama models |
| **Ollama** | Free (local) | Runs on your computer — no API key needed |
| **Custom OpenAI-compatible** | Varies | Any API that follows the OpenAI format |

**Quickest setup:** Sign up at [openrouter.ai/keys](https://openrouter.ai/keys), add $5 credit, paste the API key, select `openai/gpt-4.1` as the model.

**Free option:** Sign up at [aistudio.google.com](https://aistudio.google.com/apikey), get a Gemini API key, select `gemini-2.5-flash`.

### Step 2: Set Up Author Bio (recommended)

Go to **SEOBetter → Settings → Author Bio (E-E-A-T)**

Fill in your name, title, bio, and social links. This creates an author card at the bottom of every article and adds Person schema markup — both help with Google's E-E-A-T (Experience, Expertise, Authoritativeness, Trust) signals.

### Step 3: Add Pexels API Key (optional but recommended)

Go to **SEOBetter → Settings → General Settings → Pexels API Key**

Get a free key at [pexels.com/api](https://www.pexels.com/api/new/) (15,000 requests/month free). This adds topic-relevant stock images to articles. Without it, generic placeholder images are used.

---

## 3. Generating Your First Article

Go to **SEOBetter → Generate Content**

### Fill in the form:

| Field | What to enter | Example |
|---|---|---|
| **Primary Keyword** | The main topic you want to rank for | `best wireless headphones 2026` |
| **Auto-suggest button** | Click this to auto-fill the next 4 fields | Fills Secondary, LSI, Audience, and Category |
| **Secondary Keywords** | Related search phrases (auto-filled) | `noise cancelling headphones, bluetooth headphones` |
| **LSI Keywords** | Semantic terms (auto-filled) | `audio, bass, battery, comfort, ANC` |
| **Content Type** | The article format | Blog Post, Review, How-To, etc. (21 types) |
| **Word Count** | Article length | Auto-adjusted per content type |
| **Tone** | Writing voice | Authoritative, Conversational, Professional, etc. |
| **Category** | Topic domain (auto-detected) | Technology, Health, Business, etc. |
| **Target Audience** | Who you're writing for (auto-filled) | `buyers researching products before purchase` |
| **Country** | Target market | Sets language + triggers local research APIs |

### Click "Generate Article"

The plugin will:
1. **Research** your keyword (searches Google via Serper, scrapes top pages via Firecrawl, extracts data)
2. **Create an outline** based on the content type template
3. **Write each section** individually with research data, citations, and statistics
4. **Generate headlines** (5 variations ranked by SEO score)
5. **Create meta tags** (title + description)
6. **Assemble** the final article with tables, FAQ, images, and formatting

Generation takes 60-120 seconds depending on word count and AI model speed.

### After generation:

- **GEO Score** shows how well the article is optimized for AI search engines (target: 80+)
- **Headlines** are ranked by SEO score — select your preferred title
- **Save as Post or Page** saves to WordPress as a draft

---

## 4. Content Types (21 Types)

Each type has a different structure, tone preset, and minimum word count:

| Type | Best for | Min words | Structure |
|---|---|---|---|
| **Blog Post** | General articles | 1,000 | Key Takeaways, body sections, Pros/Cons, FAQ |
| **How-To Guide** | Step-by-step tutorials | 1,000 | Prerequisites, numbered steps, common problems |
| **Listicle** | Top 10 lists | 1,500 | 10 numbered items with details |
| **Review** | Product evaluations | 1,000 | Specs, hands-on experience, Pros/Cons, verdict |
| **Comparison** | X vs Y articles | 1,500 | Comparison table, criteria sections, winner |
| **Buying Guide** | Product roundups | 1,500 | Quick picks table, mini-reviews, buying criteria |
| **Recipe** | Food/cooking articles | 800 | Ingredient lists, step-by-step instructions, nutrition |
| **Interview** | Q&A format | 1,000 | Bio, styled Q&A cards, closing thoughts |
| **Case Study** | Success stories | 1,500 | Challenge, solution, results, client quote |
| **Tech Article** | Developer tutorials | 1,500 | Code blocks, prerequisites, setup, walkthrough |
| **White Paper** | Research reports | 2,000 | Executive summary, methodology, findings, recommendations |
| **Scholarly Article** | Academic papers | 2,000 | Abstract, literature review, methods, results |
| **News Article** | Breaking news | 800 | Inverted pyramid, dateline, short paragraphs |
| **Opinion** | Editorial pieces | 1,000 | Thesis, arguments, counterargument |
| **FAQ Page** | Question collections | 1,000 | 10-15 Q&A pairs with schema |
| **Press Release** | Announcements | 500 | Headline, dateline, quotes, boilerplate |
| **Personal Essay** | First-person stories | 1,000 | Opening scene, conflict, resolution |
| **Glossary** | Definitions | 500 | Definition, explanation, examples |
| **Pillar Guide** | Ultimate guides | 2,000 | Table of contents, 5-10 chapters |
| **Live Blog** | Event coverage | 800 | Timestamped updates |
| **Sponsored** | Paid content | 800 | Disclosure, content, CTA |

---

## 5. Understanding the GEO Score

The GEO (Generative Engine Optimization) Score measures how well your article is optimized for AI search engines (Google AI Overviews, ChatGPT, Perplexity, Gemini).

| Score | Grade | Meaning |
|---|---|---|
| 80-100 | A | Excellent — high chance of AI citation |
| 60-79 | B | Good — some improvements possible |
| 40-59 | C | Needs work — missing key GEO elements |
| 0-39 | D/F | Poor — significant gaps |

### What the score checks:

| Check | Weight | What it measures |
|---|---|---|
| Keyword Density | 10% | 0.5-1.5% density target |
| Readability | 10% | Grade 6-8 reading level |
| Citations | 10% | 5+ inline source links |
| Statistics | 10% | 3+ data points per 1000 words |
| Key Takeaways | 8% | Summary section at top |
| Section Openers | 8% | 40-60 word opening paragraphs |
| Island Test | 8% | No pronoun-starting paragraphs |
| Expert Quotes | 6% | 2+ attributed quotes |
| Entity Density | 6% | Named entities (people, orgs, places) |
| Freshness | 6% | Current year/date signals |
| CORE-EEAT | 5% | Experience, expertise, authority, trust |
| Tables | 5% | Comparison/data tables |
| Humanizer | 4% | No AI-sounding words |
| Lists | 4% | Bulleted/numbered lists |

---

## 6. Settings Reference

### General Settings

| Setting | What it does |
|---|---|
| **Auto-generate Schema** | Creates JSON-LD schema markup when you save a post |
| **Auto-analyze Content** | Runs GEO score analysis when you save a post |
| **llms.txt** | Serves an llms.txt file at yoursite.com/llms.txt for AI crawlers |
| **Tavily API Key** | Optional — enhances expert quote extraction from web pages |
| **Pexels API Key** | Adds topic-relevant stock images (free, recommended) |

### Author Bio (E-E-A-T)

All fields contribute to Google's E-E-A-T assessment:

| Field | Purpose |
|---|---|
| Full Name | Shown in author card + Person schema |
| Job Title | Professional credibility |
| Credentials | Degrees, certifications (e.g. DVM, CPA) |
| Bio | 100-200 word professional bio (third person) |
| Headshot | Professional photo for author card |
| Social Profiles | LinkedIn, Twitter, etc. — builds Knowledge Graph entity |

### Places Integrations (for local business articles only)

These are only needed if you generate articles about local businesses (e.g. "best pizza shops in Melbourne"). For blog posts, reviews, tutorials, etc., skip this section entirely.

| Provider | Cost | Coverage |
|---|---|---|
| OpenStreetMap | Free, always on | ~40% of small cities |
| Foursquare | Free tier | Strong in Europe, Asia, Brazil |
| HERE | Free tier | Strong in Europe |
| Google Places | Paid ($200/mo free credit) | Best global coverage |
| Perplexity Sonar | ~$0.008/article via OpenRouter | Best for any city worldwide |

### Branding & AI Featured Image

Configure your brand colors and an AI image provider to auto-generate branded featured images for articles.

| Provider | Cost |
|---|---|
| Pollinations | Free |
| Gemini | ~$0.02/image |
| DALL-E 3 | ~$0.04/image |
| FLUX Pro | ~$0.055/image |

---

## 7. Bulk Generate

Go to **SEOBetter → Bulk Generate**

Generate multiple articles at once:

1. **Upload a CSV** with one keyword per row, OR paste keywords (one per line)
2. Set shared settings (word count, tone, category)
3. Click **Start Bulk Generation**
4. Each article generates sequentially and saves as a WordPress draft

**CSV columns supported:** `keyword`, `secondary_keywords`, `word_count`, `tone`, `domain`, `content_type`, `country`

Minimum CSV: just a `keyword` column. All other columns are optional.

---

## 8. Editor Sidebar (Post Editor)

When editing any post in the WordPress block editor, you'll see the **SEOBetter panel** in the sidebar with:

- **GEO Score ring** — live score for the current post
- **Score breakdown** — per-check scores with bar charts
- **Suggestions** — specific improvements to raise your score
- **Re-analyze button** — refreshes the score after edits
- **Headline Analyzer** — tests alternative titles

---

## 9. Schema Markup (Automatic)

SEOBetter automatically generates JSON-LD schema for every article:

| Content type | Schema generated |
|---|---|
| Blog Post | Article + FAQPage |
| Recipe | Recipe (with ingredients, instructions, nutrition) |
| How-To | HowTo (with steps) |
| Review | Review (with rating, pros/cons) |
| FAQ Page | FAQPage (primary) |
| News | NewsArticle |
| Tech Article | TechArticle |
| White Paper | Report |
| All types | BreadcrumbList + ImageObject + Person (author) |

Schema is injected via `wp_head` — compatible with any theme. Works alongside Yoast, RankMath, and AIOSEO without conflicts.

---

## 10. Troubleshooting

### "Error: Failed to load results"
- Check browser console (F12) for the specific error
- Most common cause: PHP memory limit. Ask your host to increase `memory_limit` to 256MB

### "Error: Request failed" when saving draft
- Article content too large for your server's `post_max_size`
- Try a shorter word count, or ask your host to increase `post_max_size` to 64MB

### "Server busy — retrying" during generation
- Your hosting is rate-limiting REST API calls
- The plugin auto-retries up to 5 times with increasing delays
- If it keeps failing, consider upgrading from shared hosting to VPS

### Auto-suggest button doesn't respond
- Check if Vercel backend is reachable (requires internet connection)
- The Serper API key must be configured on the Vercel backend (managed by plugin developer)

### Articles missing FAQ section
- The AI model sometimes skips FAQ despite being asked. Regenerate the article.
- FAQ is included in all 21 content type templates

### No images in articles
- Add a Pexels API Key in Settings (free, 15,000/month)
- Without it, generic placeholder images are used

### Schema validation errors
- Test at [Google Rich Results Test](https://search.google.com/test/rich-results)
- Most common issue: recipe articles — ensure ingredients and instructions are present

---

## 11. Recommended AI Models

| Use case | Model | Provider | Cost/article |
|---|---|---|---|
| **Best quality** | GPT-4.1 or Claude Sonnet 4.6 | OpenRouter | ~$0.04 |
| **Best value** | Claude Haiku 4.5 | OpenRouter | ~$0.008 |
| **Free** | Gemini 2.5 Flash | Google | $0 (1,500/day) |

**Avoid:** Llama 3.1/3.3, DeepSeek R1/v3, Mixtral, OpenAI o3/o4 — these ignore structured prompts and produce hallucinated content.

---

## 12. Hosting Requirements

| Hosting type | Works? | Notes |
|---|---|---|
| **VPS** (DigitalOcean, Vultr, Hetzner) | Best | No rate limiting, full control |
| **Managed WordPress** (Kinsta, WP Engine) | Good | Higher limits than shared |
| **Shared hosting** (Hostinger, Bluehost, GoDaddy) | Works with retries | May see "Server busy" during generation — auto-retries handle this |

**PHP settings needed:**
- `memory_limit`: 256MB+
- `max_execution_time`: 300 seconds
- `post_max_size`: 64MB
- `upload_max_filesize`: 64MB

---

## 13. VPS Setup Guide

If you're using a VPS (DigitalOcean, Vultr, Hetzner, Linode, AWS Lightsail, etc.) with a control panel like CloudPanel, RunCloud, GridPane, or SpinupWP, follow these steps after installing WordPress and activating SEOBetter.

### Step 1: Check PHP version

SEOBetter requires PHP 8.0 or higher. Check via SSH:

```bash
php -v
```

If it shows PHP 7.x, upgrade through your control panel (CloudPanel: Sites → your site → PHP version).

### Step 2: Fix permalinks (CRITICAL — most common VPS issue)

Fresh WordPress installs on VPS often have **no permalink structure set**, which breaks the REST API entirely. SEOBetter uses the REST API for article generation — without it, you'll get 404 errors.

**Symptoms:** "Failed to load results", "No route was found matching the URL", 404 errors during generation.

**Fix via SSH:**

```bash
cd /path/to/your/wordpress
wp rewrite structure '/%postname%/' --allow-root
wp rewrite flush --allow-root
```

**Or via WordPress admin:** Go to **Settings → Permalinks**, select "Post name", click **Save Changes**.

### Step 3: Verify .htaccess / Nginx rewrite rules

**Apache (most common):** WordPress needs an `.htaccess` file with rewrite rules. Check it exists:

```bash
cat /path/to/your/wordpress/.htaccess
```

It should contain:

```apache
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
```

If the file is empty or missing, resave permalinks in WordPress admin or run:

```bash
wp rewrite flush --allow-root
```

**Nginx (CloudPanel, RunCloud):** The control panel usually handles this. If REST API returns 404, add to your Nginx config:

```nginx
location / {
    try_files $uri $uri/ /index.php?$args;
}
```

Then reload Nginx:

```bash
sudo systemctl reload nginx
```

### Step 4: Increase PHP limits

VPS defaults are often too low. Edit your PHP configuration:

```bash
# Find your php.ini
php -i | grep "Loaded Configuration"

# Edit it
sudo nano /path/to/php.ini
```

Set these values:

```ini
memory_limit = 256M
max_execution_time = 300
post_max_size = 64M
upload_max_filesize = 64M
max_input_vars = 5000
```

Then restart PHP:

```bash
# For PHP-FPM (most VPS setups)
sudo systemctl restart php8.2-fpm

# Or for CloudPanel
sudo systemctl restart php8.2-fpm
```

### Step 5: Enable WordPress debug logging

If something isn't working, enable error logging to see what's happening:

```bash
cd /path/to/your/wordpress
wp config set WP_DEBUG true --raw --allow-root
wp config set WP_DEBUG_LOG true --raw --allow-root
```

Then reproduce the issue and check the log:

```bash
tail -50 /path/to/your/wordpress/wp-content/debug.log
```

Common errors and fixes:

| Error in debug.log | Fix |
|---|---|
| `Allowed memory size exhausted` | Increase `memory_limit` in php.ini |
| `Maximum execution time exceeded` | Increase `max_execution_time` in php.ini |
| `rest_no_route` / 404 on REST API | Resave permalinks (Step 2) |
| `cURL error 28: Operation timed out` | Your server can't reach the AI provider — check firewall/DNS |
| `Class 'SEOBetter\...' not found` | Plugin files incomplete — re-upload the zip |

### Step 6: Reset WordPress password (if locked out)

If you can't log into WordPress admin on your new VPS:

```bash
cd /path/to/your/wordpress

# List users
wp user list --allow-root

# Reset password for user ID 1 (admin)
wp user update 1 --user_pass="YourNewPassword123!" --allow-root
```

### Step 7: Verify the plugin is working

After completing the steps above:

1. Go to **SEOBetter → Settings** — the page should load without errors
2. Add your AI provider key and save
3. Go to **SEOBetter → Generate Content**
4. Enter a test keyword like "best coffee shops 2026"
5. Click **Auto-suggest** — should fill secondary keywords + audience
6. Click **Generate Article** — should show progress bar and complete in 60-120 seconds

If generation fails, check `debug.log` for the specific error.

### CloudPanel-specific notes

- PHP version: **Sites → your site → Settings → PHP Version**
- Nginx config: **Sites → your site → Vhost** (rewrite rules are usually pre-configured)
- PHP settings: **Sites → your site → Settings → PHP Settings** (memory_limit, max_execution_time)
- SSL: ensure HTTPS is set up — some AI providers reject non-HTTPS callbacks

### Common VPS hosting panels

| Panel | Nginx config location | PHP config location |
|---|---|---|
| **CloudPanel** | Sites → Vhost tab | Sites → PHP Settings |
| **RunCloud** | Web App → Nginx Config | Web App → PHP Settings |
| **GridPane** | Sites → Nginx | Sites → PHP |
| **SpinupWP** | Sites → Nginx | Sites → PHP Settings |
| **Cyberpanel** | Websites → Manage → Rewrite Rules | PHP → PHP Config |
| **No panel (manual)** | `/etc/nginx/sites-available/` | `/etc/php/8.x/fpm/php.ini` |

---

*For support, visit [seobetter.com](https://seobetter.com) or open an issue on GitHub.*
