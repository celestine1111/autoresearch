#!/usr/bin/env python3
"""Full per-article audit per TESTING_PROTOCOL.md.

Usage: python3 full_audit.py POST_ID KEYWORD COUNTRY TARGET_WORDS

Runs the standard 30+ check audit + new v62.94 audit additions:
 - ImageObject dedup (no duplicate src in @graph)
 - Duplicate singular @types check
 - Visible-body unlinked parens/brackets scan
 - Schema.org validator hint (URL to copy into validator.schema.org)
"""
import sys, json, re, urllib.request, html
from urllib.parse import quote_plus

if len(sys.argv) < 5:
    print("usage: full_audit.py POST_ID KEYWORD COUNTRY TARGET_WORDS"); sys.exit(2)

POST_ID = sys.argv[1]
KW      = sys.argv[2]
COUNTRY = sys.argv[3]
TARGET_WORDS = int(sys.argv[4])

BASE = "https://srv1608940.hstgr.cloud"

def fetch(url):
    req = urllib.request.Request(url, headers={"User-Agent":"audit"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read().decode("utf-8", "ignore")

def fetch_json(url):
    """Strip PHP deprecation/warning prefix Yoast emits before JSON."""
    raw = fetch(url)
    for marker in ('{"id":', '[{"id":', '{"', '[{'):
        idx = raw.find(marker)
        if idx > -1:
            return json.loads(raw[idx:])
    return json.loads(raw)

# ----- pull post via REST -----
post_json = fetch_json(f"{BASE}/wp-json/wp/v2/posts/{POST_ID}?_embed=1")
title  = post_json.get("title",{}).get("rendered","")
slug   = post_json.get("slug","")
link   = post_json.get("link","")
content_html = post_json.get("content",{}).get("rendered","") or ""

# ----- pull rendered HTML for schema/visible-text -----
page_html = fetch(link)

results = []
def chk(name, ok, info=""):
    results.append( ("PASS" if ok else "FAIL", name, info) )

# ----- 1. Word count -----
visible_text = re.sub(r"<[^>]+>"," ", content_html)
visible_text = html.unescape(visible_text)
words = len(re.findall(r"\b\w+\b", visible_text))
chk(f"Word count >= 0.85 * target ({int(TARGET_WORDS*0.85)})", words >= int(TARGET_WORDS*0.85), f"got {words} words / target {TARGET_WORDS}")

# ----- 2. Single H1 -----
h1s = re.findall(r"<h1\b", page_html, re.I)
chk("Single H1 in rendered page", len(h1s) == 1, f"found {len(h1s)} H1 tags")

# ----- 3. H2 count (content-type-aware) -----
# faq_page uses H3 for questions (Q&A pairs ARE the article — H2 is just
# Topic Intro + FAQ wrapper + References = 3). Recipe uses H2 per recipe
# section + intro + ingredients + steps + tips. Default minimum is 5.
# Detect content-type from JSON-LD primary @type since the audit doesn't
# get content_type passed explicitly.
h2s = re.findall(r"<h2\b", content_html, re.I)
# `types_found` is built from JSON-LD below — we need the H2 check to use it,
# so move this check until after JSON-LD parses, or do a quick pre-scan.
_pretypes = re.findall(r'"@type"\s*:\s*"([^"]+)"', page_html)
H2_MIN = 3 if 'FAQPage' in _pretypes else (4 if 'Recipe' in _pretypes else 5)
chk(f"H2 count >= {H2_MIN}", len(h2s) >= H2_MIN, f"found {len(h2s)} H2 (type-aware floor: {H2_MIN})")

# ----- 4. Keyword in title -----
title_clean = re.sub(r"<[^>]+>","", title).lower()
chk(f"Keyword in title", KW.lower().split()[0] in title_clean, f"title='{title_clean}'")

# ----- 5. References section -----
chk("References section present", "References" in visible_text or "references" in visible_text.lower(), "")

# ----- 6. JSON-LD present -----
ld = re.findall(r'<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.+?)</script>', page_html, re.I|re.S)
chk("JSON-LD scripts present", len(ld) >= 1, f"found {len(ld)} JSON-LD blocks")

# ----- 7. Parse JSON-LD -----
all_nodes = []
for blob in ld:
    try:
        obj = json.loads(blob.strip())
        if isinstance(obj, dict):
            if "@graph" in obj:
                all_nodes.extend(obj["@graph"])
            else:
                all_nodes.append(obj)
        elif isinstance(obj, list):
            all_nodes.extend(obj)
    except Exception as e:
        pass
chk("JSON-LD parses cleanly", len(all_nodes) > 0, f"{len(all_nodes)} graph nodes")

# ----- 8. @types breakdown -----
types_found = []
for n in all_nodes:
    t = n.get("@type")
    if isinstance(t,list):
        types_found.append("+".join(t))
    elif t:
        types_found.append(t)
PRIMARY_OK = ("Article","BlogPosting","NewsArticle","OpinionNewsArticle","TechArticle",
              "ScholarlyArticle","Product","ItemList","Recipe","FAQPage","QAPage",
              "DefinedTerm","Review","HowTo","LiveBlogPosting","BlogPosting")
chk("Has primary @type (Article/Recipe/FAQPage/Review/etc.)",
    any(any(p in t for p in PRIMARY_OK) for t in types_found),
    f"types: {types_found}")

# ----- 9. ImageObject dedup (NEW v62.93/94 audit) -----
img_objs = [n for n in all_nodes if (n.get("@type")=="ImageObject" or (isinstance(n.get("@type"),list) and "ImageObject" in n.get("@type")))]
img_srcs = [ (n.get("url") or n.get("contentUrl") or "") for n in img_objs ]
img_srcs_clean = [s for s in img_srcs if s]
unique_srcs = set(img_srcs_clean)
chk("ImageObject — no duplicate URLs",
    len(img_srcs_clean) == len(unique_srcs),
    f"{len(img_objs)} ImageObject nodes, {len(img_srcs_clean)} non-empty URLs, {len(unique_srcs)} unique")

# ----- 10. Singular @type duplicate check -----
singular_types = ["Article","BlogPosting","NewsArticle","Product","FAQPage","HowTo","BreadcrumbList","Organization","WebSite","WebPage"]
# Recipe MAY appear multiple times in multi-recipe articles — that's intentional, not a duplicate bug
dup_singulars = []
for st in singular_types:
    matches = [t for t in types_found if t == st]
    if len(matches) > 1:
        dup_singulars.append(f"{st} x{len(matches)}")
chk("No duplicate singular @types", len(dup_singulars) == 0, f"dups: {dup_singulars}" if dup_singulars else "clean")

# ----- 11. FAQ schema if FAQ section present -----
has_faq_section = bool(re.search(r"frequently asked questions|^FAQ", visible_text, re.I|re.M))
has_faq_schema = any("FAQPage" in t for t in types_found)
if has_faq_section:
    chk("FAQ section → FAQPage schema present", has_faq_schema, f"section={has_faq_section}, schema={has_faq_schema}")

# ----- 12. BreadcrumbList present -----
chk("BreadcrumbList in JSON-LD", any("BreadcrumbList" in t for t in types_found), "")

# ----- 13. Meta description -----
m = re.search(r'<meta[^>]+name=["\']?description["\']?[^>]+content=["\']?([^"\'>]+)["\']?', page_html, re.I)
md_desc = m.group(1) if m else ""
chk("Meta description present", bool(md_desc), f"len={len(md_desc)}")
chk("Meta description length 50-160", 50 <= len(md_desc) <= 160, f"len={len(md_desc)}")

# ----- 14. v62.94 — Visible body — unlinked PARENTHETICAL citations -----
# Walk all <p> and <li>, look for (Source-Looking-Text) NOT inside an <a>.
para_text = []
for m in re.finditer(r'<(p|li)\b[^>]*>(.+?)</\1>', content_html, re.I|re.S):
    para_text.append(m.group(2))
joined = "\n".join(para_text)

# Strip <a>...</a> blocks so any leftover (Text) is unlinked
no_links = re.sub(r'<a\b[^>]*>.*?</a>', '', joined, flags=re.I|re.S)
no_links_text = re.sub(r"<[^>]+>"," ", no_links)
no_links_text = html.unescape(no_links_text)

# Look for parentheticals that look like source citations:
# - 4+ chars, starts with capital letter or has .com / .org, not a generic "(e.g. ...)"
suspect_parens = []
for m in re.finditer(r'\(([^)]{4,180})\)', no_links_text):
    s = m.group(1).strip()
    if re.match(r'^(e\.g\.|i\.e\.|see |note:|approx)', s, re.I): continue
    if re.match(r'^[\d.,\s%$£€-]+$', s): continue   # numeric only
    if re.match(r'^[a-z]', s) and not re.search(r'\.(com|org|net|io|co|edu|gov|ai)\b', s, re.I): continue
    if len(s) < 5: continue
    # Skip prose sentences (em-dash / en-dash / period mid-string indicates parenthetical clause, not source)
    if re.search(r'[–—]', s) and not re.search(r'\.(com|org|net|io|co|edu|gov|ai)\b', s, re.I): continue
    if s.count('.') > 2 or s.count(',') > 4: continue   # comma/period dense prose = sentence not citation
    # If it has at least 2 capital letters or .com/.org, treat as source-looking
    caps = sum(1 for ch in s if ch.isupper())
    has_host = bool(re.search(r'\.(com|org|net|io|co|edu|gov|ai)\b', s, re.I))
    # Suppress CTA / imperative-prose false-positives: "Compare X", "See Y",
    # "Get Z" etc are advertising copy or instructions, not source citations.
    cta_lead = re.match(r'^(Compare|See|Get|Find|Buy|Visit|Read|Watch|Click|Browse|Explore|Discover|Learn|Try|Save|Order|Shop|Check|Download|Install|Sign|Subscribe|Apply|Request)\b', s)
    if cta_lead:
        continue
    if caps >= 2 or has_host:
        suspect_parens.append(s[:120])

chk(f"v62.94: visible body has 0 unlinked source-looking parentheticals",
    len(suspect_parens) == 0,
    f"found {len(suspect_parens)}: {suspect_parens[:5]}")

# ----- 15. Visible body — unlinked [bracketed] citations -----
suspect_brackets = []
for m in re.finditer(r'\[([^\]]{4,180})\]', no_links_text):
    s = m.group(1).strip()
    if re.match(r'^[\d,\s-]+$', s): continue   # [1] [2] [1,2,3] = footnote refs (handled separately)
    suspect_brackets.append(s[:120])
chk("visible body has 0 unlinked source [brackets]",
    len(suspect_brackets) == 0,
    f"found {len(suspect_brackets)}: {suspect_brackets[:5]}")

# ----- 16. Stock images -----
imgs = re.findall(r'<img\b[^>]+>', content_html, re.I)
chk("Has at least 2 images", len(imgs) >= 2, f"found {len(imgs)} <img>")

# ----- 17. External links count -----
ext_links = re.findall(r'<a\s[^>]*href=["\']https?://(?!srv1608940\.hstgr\.cloud)([^"\']+)', content_html, re.I)
ext_hosts = sorted(set([x.split('/')[0] for x in ext_links]))
chk("External outbound links present (>=3)", len(ext_links) >= 3, f"{len(ext_links)} links across {len(ext_hosts)} hosts: {ext_hosts[:8]}")

# ----- 18. No banned hosts (bsky/mastodon/lemmy/etc) -----
banned = ["bsky.app","bsky.social","mastodon.","lemmy.","news.ycombinator","quora.com"]
hits = [h for h in ext_hosts if any(b in h for b in banned)]
chk("No banned-host citations (bsky/mastodon/lemmy/HN/quora)", len(hits) == 0, f"hits: {hits}")

# ----- 19. Speakable specification -----
# Speakable can appear as top-level @graph node OR as nested 'speakable' property on Article — accept both
has_speakable = any("Speakable" in t for t in types_found) or any(
    isinstance(n.get("speakable"), dict) and "Speakable" in str(n.get("speakable", {}).get("@type",""))
    for n in all_nodes
)
chk("SpeakableSpecification (node or nested)", has_speakable, "")

# ----- 20. Author Person node -----
chk("Author Person node present", any(t=="Person" for t in types_found), "")

# ----- 21. Organization publisher node -----
chk("Organization publisher present", any(t=="Organization" for t in types_found), "")

# ----- 22. Inline bold count (should be 0 per v62 rules) -----
bolds = re.findall(r'<(strong|b)\b', content_html, re.I)
chk("Zero inline bolds in body", len(bolds) == 0, f"found {len(bolds)} inline bolds")

# ----- 23. Flesch Reading Ease — target 60-70+ -----
def _syllables(word):
    word = word.lower()
    word = re.sub(r'[^a-z]', '', word)
    if not word: return 0
    # Drop trailing silent e (but not for "le" pattern like "table")
    if word.endswith('e') and not word.endswith('le') and len(word) > 2:
        word = word[:-1]
    groups = re.findall(r'[aeiouy]+', word)
    return max(1, len(groups))

# Build clean prose: drop wp:html callouts, References list, schema scripts, code blocks
prose_html = re.sub(r'<!--\s*wp:html\s*-->.*?<!--\s*/wp:html\s*-->', '', content_html, flags=re.S)
prose_html = re.sub(r'<script\b[^>]*>.*?</script>', '', prose_html, flags=re.I|re.S)
prose_html = re.sub(r'<pre\b[^>]*>.*?</pre>', '', prose_html, flags=re.I|re.S)
prose_html = re.sub(r'<code\b[^>]*>.*?</code>', '', prose_html, flags=re.I|re.S)
# Drop the References section (anchor-heavy, distorts readability)
prose_html = re.sub(r'<h2\b[^>]*>\s*References\s*</h2>.*$', '', prose_html, flags=re.I|re.S)
prose_text = re.sub(r"<[^>]+>", " ", prose_html)
prose_text = html.unescape(prose_text)
prose_text = re.sub(r'\s+', ' ', prose_text).strip()

words_list = re.findall(r"[A-Za-z][A-Za-z\-']*", prose_text)
sentences_split = [s for s in re.split(r'[.!?]+', prose_text) if s.strip()]
n_words     = len(words_list)
n_sentences = max(1, len(sentences_split))
n_syllables = sum(_syllables(w) for w in words_list)
flesch = 206.835 - 1.015 * (n_words / n_sentences) - 84.6 * (n_syllables / max(1, n_words))
chk("Flesch Reading Ease >= 60",
    flesch >= 60,
    f"score={flesch:.1f} (words={n_words}, sentences={n_sentences}, syllables={n_syllables}; target 60-70+)")

# ----- 24. Island Test — no pronoun starts on H2/H3 section openers -----
PRONOUN_RE = re.compile(r'^\s*(He|She|It|They|We|You|This|That|These|Those|There|It\'s|They\'re|These|Those|Such)\b', re.I)
section_paras = []  # (heading_text, opener_para_text)
# Walk content; for every H2/H3, capture the first <p>...</p> after it (before next H2/H3)
for m in re.finditer(r'<(h[2-3])\b[^>]*>(.*?)</\1>(.*?)(?=<h[2-3]\b|$)', content_html, re.I|re.S):
    head = re.sub(r'<[^>]+>', '', m.group(2)).strip()
    body = m.group(3)
    p = re.search(r'<p\b[^>]*>(.*?)</p>', body, re.I|re.S)
    if p:
        opener = re.sub(r'<[^>]+>', '', p.group(1)).strip()
        opener = html.unescape(opener)
        section_paras.append((head, opener))

# Skip the References section + the type-badge wrappers (no opener prose there)
def _section_has_pronoun_start(opener):
    return bool(PRONOUN_RE.match(opener))
pronoun_violations = [(h[:60], o[:80]) for h, o in section_paras if _section_has_pronoun_start(o)]
chk("Island Test: no pronoun-starts on section openers",
    len(pronoun_violations) == 0,
    f"{len(pronoun_violations)} violations: {pronoun_violations[:5]}")

# ----- 25. Section openings 40-60 words (extractability chunk) -----
opener_word_counts = []
for h, o in section_paras:
    wc = len(re.findall(r'\b\w+\b', o))
    opener_word_counts.append((h, wc))
out_of_band = [(h[:60], wc) for h, wc in opener_word_counts if not (40 <= wc <= 60)]
within = sum(1 for _, wc in opener_word_counts if 40 <= wc <= 60)
total_sections = len(opener_word_counts)
# Allow up to 2 sections out of band (intro + references + closing summary)
chk("Section openings 40-60 words (≥75% of sections)",
    total_sections > 0 and within / total_sections >= 0.75,
    f"{within}/{total_sections} within 40-60w; out: {out_of_band[:5]}")

# ----- 26. Schema.org validator URL hint -----
results.append(("INFO", f"Schema.org validator", f"validator.schema.org → paste {link} OR Google Rich Results: https://search.google.com/test/rich-results?url={quote_plus(link)}"))

# ----- output -----
print("="*70)
print(f"FULL AUDIT — Post {POST_ID}")
print(f"Title: {title}")
print(f"URL:   {link}")
print(f"KW:    {KW} | Country: {COUNTRY} | Target words: {TARGET_WORDS}")
print("="*70)
pass_n = sum(1 for r in results if r[0]=="PASS")
fail_n = sum(1 for r in results if r[0]=="FAIL")
info_n = sum(1 for r in results if r[0]=="INFO")
for status, name, info in results:
    sym = "[PASS]" if status=="PASS" else ("[FAIL]" if status=="FAIL" else "[INFO]")
    print(f"{sym} {name}")
    if info:
        print(f"        {info}")
print("="*70)
print(f" RESULT: {pass_n} pass / {fail_n} fail / {info_n} info")
print("="*70)
sys.exit(1 if fail_n>0 else 0)
