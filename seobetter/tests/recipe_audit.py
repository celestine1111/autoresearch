#!/usr/bin/env python3
"""Recipe-specific audit additions per TESTING_PROTOCOL.md + content-type-status.md row 9.

Run AFTER full_audit.py.

Usage: python3 recipe_audit.py POST_ID 'KEYWORD' COUNTRY
"""
import sys, json, re, urllib.request, html as h, urllib.parse

POST_ID = sys.argv[1]
KW = sys.argv[2]
COUNTRY = sys.argv[3] if len(sys.argv) > 3 else 'GB'

BASE = "https://srv1608940.hstgr.cloud"
def fetch(url):
    return urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent":"audit"}), timeout=30).read().decode("utf-8","ignore")

post = json.loads(fetch(f"{BASE}/wp-json/wp/v2/posts/{POST_ID}?_embed=1"))
content_html = post["content"]["rendered"]
page_html = fetch(post["link"])

# Parse JSON-LD
ld = re.findall(r'<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.+?)</script>', page_html, re.I|re.S)
nodes = []
for blob in ld:
    try:
        o = json.loads(blob.strip())
        if isinstance(o, dict) and "@graph" in o: nodes.extend(o["@graph"])
        elif isinstance(o, list): nodes.extend(o)
        else: nodes.append(o)
    except: pass

results = []
def chk(name, ok, info=""):
    results.append(("PASS" if ok else "FAIL", name, info))

# (R1) @type Recipe present (NOT Article-only)
recipe_nodes = [n for n in nodes if n.get("@type") == "Recipe" or
                (isinstance(n.get("@type"), list) and "Recipe" in n.get("@type"))]
chk("(R1) @type Recipe present in @graph", len(recipe_nodes) >= 1, f"found {len(recipe_nodes)} Recipe nodes; all types: {[n.get('@type') for n in nodes]}")

if not recipe_nodes:
    print("="*70)
    print(f"RECIPE AUDIT — Post {POST_ID}")
    print(f"URL: {post['link']}")
    print("="*70)
    for s,n,i in results:
        print(f"[{s}] {n}")
        if i: print(f"      {i}")
    sys.exit(1)

r = recipe_nodes[0]

# (R2) recipeIngredient is array, non-empty
ing = r.get("recipeIngredient", [])
chk("(R2) recipeIngredient is non-empty array", isinstance(ing, list) and len(ing) >= 3,
    f"got {type(ing).__name__} len={len(ing) if hasattr(ing,'__len__') else 'n/a'}; sample={ing[:3] if isinstance(ing,list) else ing}")

# (R3) recipeInstructions is HowToStep array
ri = r.get("recipeInstructions", [])
is_howto_array = isinstance(ri, list) and len(ri) >= 1 and all(
    isinstance(s, dict) and (s.get("@type") == "HowToStep" or s.get("@type") == "HowToSection") for s in ri
)
chk("(R3) recipeInstructions is HowToStep array", is_howto_array,
    f"got {type(ri).__name__}; first item type: {ri[0].get('@type') if (isinstance(ri,list) and ri and isinstance(ri[0],dict)) else 'n/a'}")

# (R4) Each HowToStep has url anchor
if is_howto_array:
    steps_with_url = [s for s in ri if s.get("url")]
    chk("(R4) Each HowToStep has url anchor", len(steps_with_url) == len(ri),
        f"{len(steps_with_url)}/{len(ri)} steps have url")
else:
    chk("(R4) Each HowToStep has url anchor", False, "(skipped — instructions not HowToStep array)")

# (R5) prepTime / cookTime ISO 8601 (PT...M or PT...H)
def is_iso8601_duration(v):
    return isinstance(v, str) and re.match(r'^PT(\d+H)?(\d+M)?(\d+S)?$', v) is not None
chk("(R5a) prepTime in ISO 8601", is_iso8601_duration(r.get("prepTime","")), f"got {r.get('prepTime', 'MISSING')!r}")
chk("(R5b) cookTime in ISO 8601", is_iso8601_duration(r.get("cookTime","")), f"got {r.get('cookTime', 'MISSING')!r}")
chk("(R5c) totalTime in ISO 8601", is_iso8601_duration(r.get("totalTime","")), f"got {r.get('totalTime', 'MISSING')!r}")

# (R6) recipeYield present
ry = r.get("recipeYield", "")
chk("(R6) recipeYield present", bool(ry), f"got {ry!r}")

# (R7) recipeCuisine = "British" for GB
rc = r.get("recipeCuisine", "")
expected_cuisine_ok = (COUNTRY != "GB") or (isinstance(rc,str) and "british" in rc.lower())
chk(f"(R7) recipeCuisine == 'British' (country={COUNTRY})", expected_cuisine_ok, f"got {rc!r}")

# (R8) recipeCategory present
chk("(R8) recipeCategory present", bool(r.get("recipeCategory")), f"got {r.get('recipeCategory', 'MISSING')!r}")

# (R9) nutrition object present
nu = r.get("nutrition", {})
chk("(R9) nutrition object present (calories at minimum)", isinstance(nu, dict) and "calories" in nu,
    f"got keys={list(nu.keys()) if isinstance(nu,dict) else 'not-dict'}")

# (R10) image array
img = r.get("image", [])
img_count = len(img) if isinstance(img, list) else (1 if img else 0)
chk("(R10) image present", img_count >= 1, f"got {img_count} images")

# ----- UK localisation checks -----
visible = re.sub(r'<[^>]+>', ' ', content_html)
visible = h.unescape(visible)

# (U1) UK temperatures °C present, °F-only NOT
deg_c_count = len(re.findall(r'\d+\s*°\s*C\b|\d+C\b(?!up)', visible))
deg_f_count = len(re.findall(r'\d+\s*°\s*F\b|\d+F\b(?!loss)', visible))
chk(f"(U1) UK oven temp uses °C (country={COUNTRY})",
    deg_c_count > 0 if COUNTRY == "GB" else True,
    f"°C count={deg_c_count}, °F count={deg_f_count}")

# (U2) UK weights — grams/ml present, cups NOT dominant
gram_count = len(re.findall(r'\b\d+\s*(?:g|gram|grams|ml|millilitre|millilitres|litre|litres|kilogram|kg)\b', visible, re.I))
cup_count = len(re.findall(r'\b\d+(?:/\d+)?\s*(?:cup|cups|tbsp|tablespoon|tsp|teaspoon|oz|ounce|pound|lb)s?\b', visible, re.I))
chk(f"(U2) UK weights: grams/ml > cups/oz (country={COUNTRY})",
    gram_count >= cup_count if COUNTRY == "GB" else True,
    f"grams/ml count={gram_count}, cups/oz count={cup_count}")

# (U3) Currency: £ present if any cost mentions, no $
pound_count = visible.count("£")
dollar_count = visible.count("$")
chk(f"(U3) UK currency: £ not $ (country={COUNTRY})",
    dollar_count == 0 if COUNTRY == "GB" else True,
    f"£ count={pound_count}, $ count={dollar_count}")

# (U4) UK English spelling
uk_spell_score = (
    visible.lower().count("flavour") + visible.lower().count("colour") +
    visible.lower().count("centre") + visible.lower().count("metre") +
    visible.lower().count("organise") + visible.lower().count("recognise")
)
us_spell_score = (
    visible.lower().count("flavor") + visible.lower().count(" color") +
    visible.lower().count(" center") + visible.lower().count(" meter") +
    visible.lower().count("organize") + visible.lower().count("recognize")
)
# allow some US words in citations
chk(f"(U4) UK English spelling (country={COUNTRY})",
    uk_spell_score >= us_spell_score if COUNTRY == "GB" else True,
    f"UK-spelling hits={uk_spell_score}, US-spelling hits={us_spell_score}")

# (U5) UK authority citations (BBC Good Food, Delicious, Jamie Oliver, Mary Berry, Olive, Great British Chefs)
uk_authority_hosts = ["bbcgoodfood.com", "deliciousmagazine.co.uk", "jamieoliver.com", "maryberry.co.uk",
                      "olivemagazine.com", "greatbritishchefs.com", "food.gov.uk", "nhs.uk"]
ext_links = re.findall(r'<a\s[^>]*href=["\']https?://([^"\'/]+)', content_html, re.I)
uk_hits = [l for l in ext_links if any(uh in l.lower() for uh in uk_authority_hosts)]
chk(f"(U5) UK authority citations (>=2) for country={COUNTRY}",
    len(uk_hits) >= 2 if COUNTRY == "GB" else True,
    f"UK authority hits: {sorted(set(uk_hits))[:8]}")

# Output
print("="*70)
print(f"RECIPE AUDIT — Post {POST_ID}")
print(f"Title: {post['title']['rendered']}")
print(f"URL:   {post['link']}")
print(f"KW:    {KW} | Country: {COUNTRY}")
print("="*70)
pn = sum(1 for r in results if r[0]=="PASS")
fn = sum(1 for r in results if r[0]=="FAIL")
for s,n,i in results:
    sym = "[PASS]" if s == "PASS" else "[FAIL]"
    print(f"{sym} {n}")
    if i: print(f"        {i}")
print("="*70)
print(f" RECIPE-SPECIFIC RESULT: {pn} pass / {fn} fail")
print("="*70)
sys.exit(1 if fn > 0 else 0)
