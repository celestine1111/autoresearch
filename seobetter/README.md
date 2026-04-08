# SEOBetter - AI-Powered SEO & GEO WordPress Plugin

The first WordPress plugin that combines traditional **SEO** with **Generative Engine Optimization (GEO)** — optimizing your content for Google AI Overviews, Perplexity, SearchGPT, Gemini, and Claude.

## Why SEOBetter?

Traditional SEO plugins (Yoast, RankMath, AIOSEO) don't optimize for AI-powered search. In 2026, over 60% of searches involve AI-generated responses. SEOBetter ensures your content is the source AI models cite.

**Based on peer-reviewed research** (KDD 2024) proving GEO methods boost AI visibility by up to 41%.

## Features

### GEO Content Analyzer
- **Real-time GEO Score** (0-100) in the Gutenberg editor sidebar
- **Readability scoring** (Flesch-Kincaid grade level targeting)
- **Island Test** — ensures paragraphs are context-independent for RAG extraction
- **40-60 Word Rule** — validates section openings for optimal AI extraction length
- **BLUF Header detection** — checks for Key Takeaways at the top
- **Factual density analysis** — statistics, citations, and expert quotes per 1000 words
- **Entity usage tracking** — named entity density for knowledge graph alignment

### GEO Content Optimizer
Research-backed optimization methods with proven visibility boosts:

| Method | Visibility Boost | Status |
|---|---|---|
| Quotation Addition | **+41%** | Active |
| Statistics Addition | **+30%** | Active |
| Cite Sources | **+28%** | Active |
| Fluency Optimization | **+27%** | Active |
| Technical Terms | **+18%** | Active |
| Keyword Stuffing | **-8%** | **Blocked** |

- **Domain-aware strategy** — auto-detects content domain (Law, Health, Science, etc.) and applies the optimal GEO method mix
- **Combination strategies** — applies multiple methods for maximum impact

### Schema Markup Generator
- **Article** schema with author and dateModified
- **FAQPage** schema auto-generated from question-style H2/H3 headings
- **HowTo** schema for procedural content
- **BreadcrumbList** for navigation

### llms.txt Generator
Generates an `llms.txt` file (the robots.txt for AI crawlers) that guides how AI models should cite your site.

### Multi-Engine Targeting
Optimize for all major generative AI engines:
- Google AI Overviews
- Perplexity
- SearchGPT
- Gemini
- Claude

## Installation

1. Download or clone this repository
2. Upload the `seobetter` folder to `/wp-content/plugins/`
3. Activate the plugin through the WordPress admin
4. Configure settings at **SEOBetter > Settings**

## Plugin Structure

```
seobetter/
├── seobetter.php              # Main plugin file
├── includes/
│   ├── GEO_Analyzer.php       # Content analysis engine
│   ├── GEO_Optimizer.php      # Content optimization engine
│   ├── Schema_Generator.php   # JSON-LD schema generation
│   └── LLMS_Txt_Generator.php # llms.txt generation
├── admin/
│   ├── views/                 # Admin page templates
│   ├── css/                   # Admin styles
│   └── js/                    # Admin scripts
├── assets/
│   ├── js/editor-sidebar.js   # Gutenberg sidebar panel
│   └── css/editor-sidebar.css # Editor styles
└── languages/                 # Translation files
```

## REST API

- `GET /wp-json/seobetter/v1/analyze/{post_id}` — Run GEO analysis on a post
- `POST /wp-json/seobetter/v1/optimize` — Optimize content with GEO methods

## Requirements

- WordPress 6.0+
- PHP 8.0+

## License

GPL-2.0+
