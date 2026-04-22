# SEOBetter International Optimization (Layer 6)

> **Purpose:** Single source of truth for how SEOBetter optimizes articles for international search engines and LLMs when users select a non-English/US target country or language.
>
> **Last updated:** 2026-04-22 (v1.5.205 — reference doc introduced; critical code lands v1.5.206)
>
> **Where this sits in the optimization framework:** Layer 6 of the 5-layer + 6-vector model defined in `SEO-GEO-AI-GUIDELINES.md` and the `/seobetter` skill. Layers 1–4 (SEO / AI SEO / LLM citations / Schema) are scored by `GEO_Analyzer.php`. Layer 6 (International) will be scored starting v1.5.206. Layer 5 (Design / distinctive CSS) is verified visually, not scored.
>
> **When to read this file:** any time the user selects a target country or language that is not `US` / `en`, or when changing `Schema_Generator.php`, `Async_Generator.php::get_system_prompt()`, `Content_Injector.php`, or citation whitelists that could affect non-English/US output.

---

## 1. The international engine landscape

SEOBetter optimizes for four engine classes. A single article may need to satisfy several at once (Chinese users still use Google via VPN; Russian users use both Yandex and Google; Korean users use both Naver and Google).

### 1.1 Global engines (Western-default)

| Engine | Market share note | Retrieval style |
|---|---|---|
| Google Search + AI Overviews | Dominant almost everywhere except mainland China, Russia, South Korea, Czechia | Crawl → index → rank + on-page LLM summary |
| Bing + Copilot | Default on Windows / Edge; powers ChatGPT web browsing | Crawl → index + LLM grounding |
| DuckDuckGo | Privacy-focused; uses Bing index + own ranking | Delegated crawl |

### 1.2 Regional search engines (must be optimized for separately)

| Engine | Primary market | Share in market | Critical constraints |
|---|---|---|---|
| **Baidu** | Mainland China | ~60%+ | .cn domain strongly preferred, ICP license, mainland hosting, Simplified Chinese (83% of top-ranking pages), Baidu Baike backlinks, mobile-first, meta keywords still weighted |
| **Sogou** | Mainland China (#2) | ~15% | Integrates WeChat content; similar rules to Baidu |
| **Yandex** | Russia | ~60% | Cyrillic content essential, mandatory JSON-LD schema, Yandex Turbo Pages (<1.8s LCP threshold), Cyrillic URLs preferred, IKS authority metric |
| **Naver** | South Korea | ~62.5% | C-Rank (source credibility) + P-Rank (technical quality) + DIA (document-intent alignment). Heavy Naver Blog / Naver Cafe / Naver Maps / Naver Knowledge-iN prioritization. Korean-language content required. |
| **Daum/Kakao** | South Korea (#2) | ~30% | Similar to Naver; Kakao ecosystem content favored |
| **Seznam** | Czechia | ~13% | Czech-language optimization; Sklik ads ecosystem |
| **Yahoo! Japan** | Japan | ~20% (powered by Google index with own overlay) | Japanese character encoding, Yahoo-specific categorization |

### 1.3 International LLMs (retrieval + generation)

These are distinct from the engines above — they are standalone LLMs with their own retrieval pipelines, and getting cited by them is a Layer 3 objective.

| LLM | Region / Publisher | Notes |
|---|---|---|
| **Doubao** (豆包) | ByteDance, China | Highest DAU in China as of 2026; retrieves via Baidu/Bing indexes |
| **ERNIE Bot** (文心一言) | Baidu, China | Tightly integrated with Baidu search + Baidu Baike |
| **DeepSeek** | China | Uses public web + own crawler; strong on technical/scientific content |
| **Qwen** (通义千问) | Alibaba, China | Alibaba ecosystem content boosted |
| **Kimi** (月之暗面 Moonshot) | China | Long-context focus; research-paper heavy |
| **YandexGPT** | Russia | Retrieval via Yandex index; privileges .ru domains |
| **GigaChat** | Sberbank, Russia | State-backed; privileges Russian-government sources |
| **HyperCLOVA X** | Naver, South Korea | Retrieval via Naver index + Naver Blog/Cafe; Korean-first |
| **Kanana** | Kakao, South Korea | Kakao ecosystem retrieval |
| **Mistral Le Chat** | France / EU | EU-sovereignty positioning; privileges EU publishers |
| **Aleph Alpha** | Germany | Enterprise/defense-focused; DE-first |
| Japanese LLMs (Sakana AI, PLaMo, Rinna, ELYZA, etc.) | Japan | Japanese-first retrieval; Yahoo! Japan and .jp domain bias |

### 1.4 The "answer engines" — the fourth class

| Engine | Retrieval | What it values |
|---|---|---|
| Perplexity | Web + own index | Cite-heavy answers with clickable sources |
| You.com | Web | Similar to Perplexity |
| ChatGPT Search | Bing + SearchGPT index | Publisher partnerships + clean schema |
| Grok | X/Twitter + web | Social-proof + real-time signals |

All four have specific preferences already documented in `llm-visibility-strategy.md`. This file adds the regional equivalents.

---

## 2. Per-engine optimization preferences

This section specifies what each engine values. When the user selects a target country, `Async_Generator.php::get_system_prompt()` should inject the relevant preferences as prompt context (implemented in v1.5.206).

### 2.1 Baidu (China)

**On-page:**
- **Title:** 32–54 characters (Simplified Chinese counts as 2 bytes each)
- **Meta description:** <108 characters
- **Meta keywords:** Still weighted — include 3–5 primary keywords (unlike Google, which ignores them since 2009)
- **H1:** One per page, keyword-front-loaded
- **Content length:** 1500+ characters minimum; 2500+ preferred for competitive keywords
- **Language:** Simplified Chinese (zh-CN / zh-Hans). Traditional Chinese (zh-TW / zh-Hant) ranks poorly on Baidu even for mainland users.

**Technical:**
- **Domain:** `.cn` > `.com.cn` > `.com`. Non-`.cn` domains are systematically deprioritized.
- **ICP license:** Required for `.cn` domains. Articles should reference the hosting site's ICP number when applicable.
- **Hosting:** Mainland China CDN required for full indexing; non-mainland hosts are crawled slowly or not at all.
- **Mobile:** Mobile-first since 2017. AMP equivalent is Baidu MIP (Mobile Instant Pages).
- **HTTPS:** Weak ranking signal (unlike Google); does not hurt to have it.

**Citation targets (Layer 3 for Baidu retrieval):**
- `baike.baidu.com` (Baidu Baike — Baidu's Wikipedia equivalent, HIGHEST weight)
- `zhihu.com` (Chinese Quora)
- `jiandan.net`, `36kr.com`, `tmtpost.com` (Chinese tech media)
- `people.com.cn`, `xinhuanet.com`, `chinadaily.com.cn` (state media — always trusted)
- Government: `*.gov.cn`, `*.edu.cn`

**Schema:** Baidu has its own structured-data spec (Baidu Rich Results) that overlaps with Schema.org but has Chinese-specific types. Schema.org JSON-LD is still parsed as a fallback signal.

### 2.2 Yandex (Russia)

**On-page:**
- **Title:** 50–70 characters
- **Meta description:** 150–160 characters
- **Language:** Russian (ru). Cyrillic content is essential. Transliterated Russian (e.g. "Rossiya" instead of "Россия") is penalized.
- **URL:** Cyrillic URLs preferred (e.g. `/о-нас/` over `/about/`). Punycode allowed but less desirable.

**Technical:**
- **Schema:** JSON-LD is effectively **mandatory** for Yandex Rich Results. Missing schema → dramatically reduced rich-result eligibility.
- **Yandex Turbo Pages:** AMP-equivalent with 1.8s LCP threshold. Heavily prioritized in mobile SERPs.
- **IKS (Index of Site Quality):** Yandex's proprietary authority metric. Accrues from domain age, backlink profile, user behavior signals.
- **Hosting:** Russian CDN / `.ru` domain preferred for full crawling.

**Citation targets (Layer 3 for Yandex + YandexGPT + GigaChat):**
- `ru.wikipedia.org`
- `yandex.ru/q` (Yandex Q — Quora-like)
- `habr.com` (Russian tech community)
- `lenta.ru`, `ria.ru`, `tass.ru`, `rbc.ru` (Russian news — RIA/TASS are state-backed, always trusted by GigaChat)
- Government: `*.gov.ru`, `kremlin.ru`

### 2.3 Naver (South Korea)

Naver's ranking algorithm is built on three scores applied together:

| Score | What it measures | How to signal |
|---|---|---|
| **C-Rank** | Source credibility | Cite Naver Knowledge-iN, Naver Academic, Naver Encyclopedia; avoid unknown blogs |
| **P-Rank** | Technical quality | Korean-language content, mobile-first, page speed, structured data |
| **DIA** (Document Intent Alignment) | Whether the document matches user intent | Clear H1 → intent, FAQ-style Q&A blocks, answer-first openings |

**On-page:**
- **Title:** 30–60 characters
- **Meta description:** 80–120 characters
- **Language:** Korean (ko). Hangul required; mixed Hanja accepted.

**Ecosystem priority (critical):**
- Naver Blog (blog.naver.com) content outranks ordinary websites for many queries
- Naver Cafe (cafe.naver.com) community content outranks websites for lifestyle/forum queries
- Naver Maps (map.naver.com) dominates local queries
- Naver Knowledge-iN (kin.naver.com) dominates Q&A queries

Standalone websites must compete against Naver's own ecosystem content. The practical implication: **cite Naver ecosystem sources** (Knowledge-iN, Encyclopedia, Blog) to inherit authority.

**Citation targets (Layer 3 for Naver + HyperCLOVA X + Kanana):**
- `terms.naver.com` (Naver Encyclopedia)
- `kin.naver.com` (Naver Knowledge-iN)
- `academic.naver.com` (Naver Academic)
- `ko.wikipedia.org`
- `yna.co.kr` (Yonhap News), `chosun.com`, `donga.com`, `hani.co.kr`
- Government: `*.go.kr`, `*.ac.kr`

### 2.4 Seznam (Czechia)

- Czech-language content (cs-CZ)
- Seznam Slovník (their encyclopedia) is the Wikipedia equivalent
- Citation targets: `cs.wikipedia.org`, `idnes.cz`, `novinky.cz`, `aktualne.cz`

### 2.5 Yahoo! Japan + Japanese LLMs

Yahoo! Japan uses Google's index under an exclusive license, but its SERP overlay and ranking favor:
- Japanese-language (ja) content
- `.jp` domains
- Yahoo! Japan News (news.yahoo.co.jp) and Yahoo! Japan Chiebukuro (chiebukuro.yahoo.co.jp — their Knowledge-iN equivalent)

**Citation targets for Japanese LLMs (Sakana AI, PLaMo, Rinna, ELYZA):**
- `ja.wikipedia.org`
- `chiebukuro.yahoo.co.jp`
- `kotobank.jp` (Japanese encyclopedia aggregator)
- `nhk.or.jp`, `asahi.com`, `mainichi.jp`, `nikkei.com`
- Government: `*.go.jp`, `*.ac.jp`

### 2.6 EU engines and Mistral / Aleph Alpha

EU sovereignty-focused LLMs privilege EU publishers and `.eu` / EU-country TLDs.

**Citation targets:**
- `de.wikipedia.org`, `fr.wikipedia.org`, `es.wikipedia.org`, `it.wikipedia.org`, `pt.wikipedia.org`
- `spiegel.de`, `faz.net`, `zeit.de` (Germany)
- `lemonde.fr`, `lefigaro.fr`, `liberation.fr` (France)
- `elpais.com`, `elmundo.es` (Spain)
- `corriere.it`, `repubblica.it` (Italy)
- Government: `*.europa.eu`, `*.gouv.fr`, `*.bund.de`, `*.gob.es`, `*.gov.it`

---

## 3. Per-region optimization tactics

A practical checklist of what each region needs beyond per-engine settings.

### 3.1 China

1. Simplified Chinese content (zh-CN), not Traditional
2. Include Baidu-Baike-eligible entities in the article so Baidu can match them
3. Meta keywords populated
4. Mobile-first layout (Baidu indexes mobile first)
5. Consider mainland-CDN hosting if China is a primary market
6. Image alt text in Chinese
7. Cite: Baidu Baike, Zhihu, state media, `*.gov.cn`

### 3.2 Russia

1. Full Cyrillic content (ru)
2. JSON-LD schema mandatory
3. Target <1.8s LCP for Yandex Turbo eligibility
4. Cyrillic URL slugs where possible
5. Cite: ru.wikipedia.org, RIA/TASS, Yandex Q, habr.com

### 3.3 South Korea

1. Korean-language (ko) content
2. Q&A / FAQ blocks aligned with DIA (document-intent)
3. Cite Naver ecosystem (terms.naver.com, kin.naver.com, academic.naver.com)
4. Short paragraph, image-heavy layout (matches Naver Blog norms)

### 3.4 Japan

1. Japanese (ja) content — Hiragana, Katakana, and Kanji as appropriate
2. Cite ja.wikipedia.org, kotobank.jp, Chiebukuro, NHK
3. `.jp` domain preferred

### 3.5 Germany / DACH

1. German (de) content with correct formal register (Sie-form for professional)
2. Cite German-language sources (Spiegel, FAZ, Zeit) — English citations are penalized by German users and EU LLMs
3. GDPR-compliant cookie notice (affects user-behavior signals)

### 3.6 Brazil

1. Brazilian Portuguese (pt-BR), not European Portuguese
2. Cite: pt.wikipedia.org, globo.com, folha.uol.com.br, uol.com.br, estadao.com.br
3. Government: `*.gov.br`, `*.edu.br`

---

## 4. Schema.org additions for international targeting

All implemented in v1.5.206 (see §8). This section is the spec.

### 4.1 `inLanguage` (REQUIRED for every international article)

Every schema @type output by `Schema_Generator.php` must include `inLanguage` set to the BCP-47 code matching the article's language:

```json
{
  "@context": "https://schema.org",
  "@type": "BlogPosting",
  "inLanguage": "zh-CN",
  ...
}
```

Valid codes this plugin supports:
`en`, `en-US`, `en-GB`, `en-AU`, `en-CA`, `en-NZ`, `zh-CN`, `zh-TW`, `ja`, `ko`, `ru`, `de`, `fr`, `es`, `es-MX`, `pt`, `pt-BR`, `it`, `nl`, `pl`, `tr`, `ar`, `he`, `hi`, `th`, `vi`, `id`, `ms`, `cs`, `sv`, `da`, `no`, `fi`, `el`, `hu`, `ro`, `uk`.

### 4.2 `hreflang` (HTML head)

When the user has created parallel content in multiple languages (not yet supported by SEOBetter — flagged for a future feature), emit `<link rel="alternate" hreflang="X" href="...">` tags. For v1.5.206 we only emit `hreflang` self-reference on the current article (no alternates).

### 4.3 `sameAs` with Wikidata and regional equivalents

When the article mentions a notable entity (person, place, organization, product), the schema should include `sameAs` links to that entity's authoritative pages — region-specific:

```json
"mentions": [
  {
    "@type": "Person",
    "name": "Angela Merkel",
    "sameAs": [
      "https://www.wikidata.org/wiki/Q567",
      "https://de.wikipedia.org/wiki/Angela_Merkel",
      "https://en.wikipedia.org/wiki/Angela_Merkel",
      "https://baike.baidu.com/item/默克尔"
    ]
  }
]
```

Wikidata is the lingua franca — every LLM, including Chinese/Russian/Korean ones, cross-references Wikidata. If only one `sameAs` is possible, pick Wikidata.

### 4.4 `audience` / `spatialCoverage` / `contentLocation`

For articles targeted at a specific country:

```json
"audience": {
  "@type": "Audience",
  "geographicArea": {
    "@type": "Country",
    "name": "Japan"
  }
},
"contentLocation": {
  "@type": "Country",
  "name": "Japan"
}
```

Signals to LLMs and search engines that the content is regionally scoped.

---

## 5. The `llms.txt` standard

### 5.1 What it is

Proposed by Answer.AI (Jeremy Howard, September 2024) as an emerging retrieval-pipeline input for LLMs. A Markdown file at `/llms.txt` on a site's root that lists the site's most important URLs with short descriptions. Intended for LLM crawlers to consume in preference to HTML parsing.

Format:
```markdown
# SEOBetter

> The SEOBetter WordPress plugin generates SEO + GEO + AI-optimized content.

## Docs
- [Getting Started](https://seobetter.com/docs/getting-started.md): Install and generate your first article
- [SEO Rules](https://seobetter.com/docs/seo-rules.md): Master spec for SEO/GEO/AI optimization

## Changelog
- [v1.5 series](https://seobetter.com/changelog/v1.5.md): Current stable release notes
```

### 5.2 Adoption as of April 2026

- ✅ Anthropic (docs.anthropic.com/llms.txt)
- ✅ Stripe
- ✅ Zapier
- ✅ Cloudflare
- ⚠️ Not yet adopted by OpenAI, Google, Perplexity, Baidu, Yandex, Naver
- 🔄 Emerging — retrieval pipeline input but not first-class ranking signal yet

### 5.3 Plugin implication

SEOBetter does not currently emit an `llms.txt` file. This is a candidate feature for a post-v1.5.206 pass (noted in `pro-features-ideas.md` by the user if prioritized). Not blocking for per-article-type testing.

---

## 6. International regional citation domain whitelist

These domains must be added to `seobetter.php::get_trusted_domain_whitelist()` in v1.5.206 and mirrored in `external-links-policy.md §10`.

### 6.1 China

- `baike.baidu.com`, `zhihu.com`, `jiandan.net`, `36kr.com`
- `people.com.cn`, `xinhuanet.com`, `chinadaily.com.cn`, `cctv.com`
- `zh.wikipedia.org`
- `*.gov.cn`, `*.edu.cn`, `*.cn` (conditional — see v1.5.206 implementation)

### 6.2 Russia

- `ru.wikipedia.org`
- `yandex.ru`, `kremlin.ru`
- `lenta.ru`, `ria.ru`, `tass.ru`, `rbc.ru`, `habr.com`
- `*.gov.ru`

### 6.3 South Korea

- `ko.wikipedia.org`
- `terms.naver.com`, `kin.naver.com`, `academic.naver.com`
- `yna.co.kr`, `chosun.com`, `donga.com`, `hani.co.kr`, `joongang.co.kr`
- `*.go.kr`, `*.ac.kr`

### 6.4 Japan

- `ja.wikipedia.org`
- `chiebukuro.yahoo.co.jp`, `kotobank.jp`
- `nhk.or.jp`, `asahi.com`, `mainichi.jp`, `nikkei.com`, `yomiuri.co.jp`
- `*.go.jp`, `*.ac.jp`

### 6.5 Germany / DACH

- `de.wikipedia.org`
- `spiegel.de`, `faz.net`, `zeit.de`, `sueddeutsche.de`, `welt.de`, `tagesschau.de`
- `*.bund.de`, `*.gv.at`, `*.admin.ch`

### 6.6 France

- `fr.wikipedia.org`
- `lemonde.fr`, `lefigaro.fr`, `liberation.fr`, `leparisien.fr`
- `*.gouv.fr`

### 6.7 Spain / Latin America

- `es.wikipedia.org`
- `elpais.com`, `elmundo.es`, `clarin.com`, `lanacion.com.ar`, `reforma.com`
- `*.gob.es`, `*.gob.mx`, `*.gob.ar`

### 6.8 Italy

- `it.wikipedia.org`
- `corriere.it`, `repubblica.it`, `lastampa.it`
- `*.gov.it`

### 6.9 Brazil / Portugal

- `pt.wikipedia.org`
- `globo.com`, `folha.uol.com.br`, `uol.com.br`, `estadao.com.br`
- `publico.pt`, `expresso.pt`
- `*.gov.br`, `*.gov.pt`

### 6.10 Middle East (Arabic)

- `ar.wikipedia.org`
- `aljazeera.net`, `alarabiya.net`, `bbc.com/arabic`
- `*.gov.sa`, `*.gov.ae`

### 6.11 India

- `en.wikipedia.org` (English-first market), `hi.wikipedia.org` (Hindi)
- `thehindu.com`, `indianexpress.com`, `timesofindia.indiatimes.com`, `ndtv.com`
- `*.gov.in`, `*.ac.in`

---

## 7. Per-content-type international notes (21 types)

Populated incrementally during Phase 2 (Research) of each type's 6-phase workflow. This is the stub.

| # | Content type | International notes |
|---|---|---|
| 1 | blog_post | No special handling beyond inLanguage + regional citations |
| 2 | news_article | Dateline format varies by region (e.g. Japanese dateline convention differs from AP style) |
| 3 | opinion | Red disclosure bar caption translated per language; opinion publishing norms vary (e.g. Germany's "Kommentar" convention) |
| 4 | how_to | Measurement units (metric vs imperial); appliance voltage context |
| 5 | listicle | — |
| 6 | review | Currency symbol + price locale; product availability context |
| 7 | comparison | Availability per country; currency |
| 8 | buying_guide | Currency; regional retailers (Amazon.cn, Rakuten, Yandex Market, Coupang) |
| 9 | recipe | Metric vs US customary measurements; cuisine regional authenticity |
| 10 | faq_page | FAQ phrasing conventions vary (Korean prefers formal 습니다 register) |
| 11 | (duplicate of news_article) | — |
| 12 | tech_article | Code comments in English even on non-English articles (industry convention) |
| 13 | white_paper | Formal register per language (German Sie, Japanese 敬語, Korean 존댓말) |
| 14 | scholarly_article | Citation styles vary: APA/MLA (US), Harvard (UK), GB/T 7714 (China), GOST (Russia) |
| 15 | live_blog | Timezone display; 24-hour clock outside US |
| 16 | press_release | Dateline format per region; PR distribution services vary (PR Newswire US, Xinhua China, TASS Russia) |
| 17 | personal_essay | Literary conventions vary significantly (Japanese "I-novel" 私小説 tradition; Korean 수필 tradition) |
| 18 | glossary_definition | — |
| 19 | sponsored | Disclosure language required varies by country (e.g. Germany § 5a UWG, France's ARPP) |
| 20 | case_study | Currency; country context for metrics |
| 21 | interview | Formal register per language (Japanese / Korean especially) |
| 22 | pillar_guide | — |

---

## 8. Implementation tasks (v1.5.206 — critical international code)

All deferred from this pure-docs commit to the next. Tracked here so v1.5.206 has a clear spec.

### 8.1 Schema generator — `inLanguage` on every @type

- Add `inLanguage` field to every schema block emitted by `Schema_Generator.php` and `build_aioseo_schema()` in `seobetter.php`
- Source the BCP-47 code from the article's `article_language` meta (form field in `content-generator.php`)
- Fallback: `en` if no language selected
- Update `structured-data.md §4` + `SEO-GEO-AI-GUIDELINES.md §10`

### 8.2 Wikidata `sameAs` enrichment

- When the AI research phase returns entity mentions, query Wikidata SPARQL (`query.wikidata.org`) to resolve each entity to its Q-ID
- Inject `sameAs: [wikidata_url, regional_wikipedia_url]` in the `mentions` or `about` schema blocks
- Already-whitelisted domains: `wikidata.org`, `query.wikidata.org` (since v1.5.24)

### 8.3 Regional prompt context injector — ✅ SHIPPED v1.5.206c

- **File:** `includes/Regional_Context.php` (new, ~130 lines)
- **Integration:** `Async_Generator::get_system_prompt( $language, $country )` — country is second arg; defaults to empty; threaded from `$options['country']` at call site line 167.
- **Country-gated:** returns empty string for `''` / `US` / `GB` / `AU` / `CA` / `NZ` / `IE` — byte-identical prompt to pre-v1.5.206c for Western-default articles.
- **Priority countries with custom blocks (15):** CN, JP, KR, RU, DE, FR, ES, IT, BR, PT, IN, SA, AE, MX, AR
- **Block contents per country:** regional authority citation domains (matching `external-links-policy.md §10`), measurement units, currency, date format, thousand/decimal separator conventions, editorial register (e.g. Japanese 敬語 / keigo, German Sie, French vous, Korean 존댓말, Argentine 'vos')
- **Non-priority non-Western countries:** no-op (returns empty) — we don't guess guidance we haven't researched. Add more countries by extending `Regional_Context::get_blocks()` and updating §2 above.

### 8.4 Regional citation whitelist expansion

- Add all domains from §6 above to `seobetter.php::get_trusted_domain_whitelist()`
- Update `external-links-policy.md §10`
- Optionally gate: only trust region-specific domains when the article's target country matches (prevents English-default article citing TASS and Xinhua inappropriately). Implementation: filter whitelist by article country at runtime.

### 8.5 GEO_Analyzer — Layer 6 scoring — ✅ SHIPPED v1.5.206d

- **New 15th check:** `international_signals` — scores 3 signals visible in post content:
  1. Article language matches the target country's primary language (JP→ja, CN→zh, KR→ko, RU→ru, DE→de, FR→fr, ES→es, IT→it, BR→pt, SA/AE/EG→ar, IN→hi, MX/AR→es)
  2. Localized freshness label in body (confirms v1.5.206d i18n path fired)
  3. At least one regional authority citation domain matching the target country
- **Country-gated + non-regressive:** only added when country is set and NOT Western-default (US/GB/AU/CA/NZ/IE). Existing 14 checks keep their weights for Western articles — total still sums to 100. Non-Western articles absorb the 15th check at weight 6, making the total 106 for those articles.
- **File:** `includes/GEO_Analyzer.php::check_international_signals()` (~line 605-680)
- **Language-aware helpers also shipped (fixes the "score of 31 on Japanese article" bug):**
  - `count_words_lang()` — CJK (ja/zh/ko/th) use char-count ÷ 2 heuristic instead of str_word_count() returning 0
  - `check_bluf_header()` — accepts localized Key Takeaways label
  - `check_freshness_signal()` — accepts localized "Last Updated" label
  - `check_section_openings()` — language-aware word counting

### 8.6 Language-specific content enforcement — PARTIALLY SHIPPED v1.5.206d

- **Shipped:** the `LANGUAGE` rule in `Async_Generator::get_system_prompt()` now includes a `SECTION HEADING TRANSLATION` clause telling the AI to translate English section anchors (Key Takeaways, FAQ, References, Introduction, etc.) into the target language for reader-facing output. Plugin-rendered labels (References block header, Key Takeaways block header, Last Updated prefix) now use `Localized_Strings::get()` → `Content_Formatter.php` + `Content_Injector.php`.
- **Deferred:** post-generation script-percentage gate + regeneration loop (complex; needs a scoring signal to know when to retry; can be a later enhancement).

---

## 9. Cross-references

- `SEO-GEO-AI-GUIDELINES.md` §3.1 (DEFAULT profile), §3.1A (GENRE OVERRIDES), §6 (scoring rubric), §10 (schema)
- `llm-visibility-strategy.md` — Western LLM citation strategy (this file adds the international layer)
- `structured-data.md` §4 (Schema @type map), §5 (content-type enrichments)
- `article_design.md` §11 (content-type variations)
- `external-links-policy.md` §10 (domain whitelist — v1.5.206 adds regional domains)
- `plugin_functionality_wordpress.md` §2 (generation pipeline — v1.5.206 adds regional context injector)

---

## 10. Research sources (for this doc)

- Baidu SEO guide — Dragon Social, China SEO Shifu, Nanjing Marketing Group (2025–2026)
- Yandex SEO guide — Yandex Webmaster docs, SEMrush Yandex analysis (2025)
- Naver SEO guide — Naver Search Advisor docs, Asia-Pacific SEO firms (2025–2026)
- Baidu Baike, Naver Knowledge-iN, Yandex Q — engine-operator reference pages
- llms.txt proposal — Answer.AI (Howard, 2024); adoption tracker via Anthropic/Stripe/Zapier/Cloudflare docs (accessed 2026-04-22)
- Schema.org `inLanguage`, `sameAs`, `audience`, `contentLocation` specs — schema.org docs (2026)
- Wikidata SPARQL endpoint and Q-ID conventions — wikidata.org docs

---

## 11. Version history

- **v1.5.205 (2026-04-22)** — Document introduced. Pure reference; critical code lands v1.5.206.
- **v1.5.206 (planned)** — Schema `inLanguage`, Wikidata `sameAs`, regional prompt context, whitelist expansion, Layer 6 scoring check, language-enforcement gate.
