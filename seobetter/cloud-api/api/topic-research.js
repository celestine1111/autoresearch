/**
 * SEOBetter Topic Research Endpoint
 *
 * POST /api/topic-research
 *
 * Combines 5 free data sources to find REAL topic ideas with search demand:
 * - Google Suggest (real search queries people type)
 * - Datamuse (semantic word clusters)
 * - Wikipedia OpenSearch (authoritative subtopics)
 * - Reddit search (real questions + audience demand)
 * - DuckDuckGo (web result patterns)
 *
 * Returns scored topics with intent classification, difficulty estimate, and source attribution.
 * No API keys required. No AI hallucination.
 */

import { verifyRequest, rejectAuth, applyCorsHeaders, enforceRateLimit } from './_auth.js';

const rateLimitStore = new Map();
const RATE_LIMIT = 20;

export default async function handler(req, res) {
  applyCorsHeaders(req, res);

  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'Use POST.' });

  // v1.5.211 — HMAC request verification
  const auth = verifyRequest(req);
  if (!auth.ok) return rejectAuth(res, auth);

  // v1.5.212 — Rate limit
  const rlReject = await enforceRateLimit(req, res, 'topic-research', auth);
  if (rlReject) return rlReject;

  const { niche, site_url, country, language } = req.body || {};
  if (!niche) return res.status(400).json({ error: 'niche is required.' });
  // v1.5.57 — accept country code to geo-localize Google Suggest completions
  const gl = (country && typeof country === 'string') ? country.toLowerCase().slice(0, 2) : '';
  // v1.5.206d-fix2 — accept article language so we skip Datamuse (English-only),
  // hit the correct Wikipedia subdomain, and ask the audience LLM to respond
  // in the target language. Empty / unknown / 'en' falls back to existing
  // English-pipeline behaviour (no regression for US/English generators).
  const lang = (language && typeof language === 'string') ? language.toLowerCase().slice(0, 5) : 'en';
  const baseLang = lang.split('-')[0];
  const isEnglish = (baseLang === 'en' || baseLang === '');

  // Rate limit
  const rateKey = `${site_url || 'unknown'}_${new Date().getHours()}`;
  const count = rateLimitStore.get(rateKey) || 0;
  if (count >= RATE_LIMIT) return res.status(429).json({ error: 'Rate limit exceeded.' });
  rateLimitStore.set(rateKey, count + 1);

  try {
    // v1.5.35 — extract the core business/topic hint from the niche before
    // calling Datamuse. Datamuse's ml= endpoint is designed for 1-3 word
    // queries and returns nonsense (aborigines, balance of payments, lidl,
    // arsenal, magazine) when given a long-tail phrase like
    // "best gelato shops in lucignano italy 2026". It treats those as
    // separate words and finds weak associations to "Italy" or "2026".
    // Fix: strip location, year, generic qualifiers, then pass the core
    // 1-3 word topic to Datamuse. Wikipedia + Google Suggest get the full
    // phrase since they handle long queries correctly.
    // v1.5.206d-fix16 — pass baseLang so CJK/Thai/Cyrillic/Arabic/Hebrew/
    // Hindi compound queries get meaningful noun extraction (e.g. Japanese
    // ソラナの最高のミームコイン 2026 → ミームコイン). Without this, non-Latin
    // core-topic stripping does nothing and Google Suggest fed the whole
    // compound string returns zero completions.
    const coreTopic = extractCoreTopic(niche, baseLang);

    // v1.5.54 — Google Suggest also receives the core topic instead of the
    // full long-tail niche. Google's suggestqueries endpoint has no
    // completion data for 8+ word phrases like "best pet shops in mudgee
    // nsw 2026", so it was silently returning zero suggestions. The core
    // topic "pet shops" has thousands of completions ("pet shops near me",
    // "pet shops sydney", "pet shops online", etc) which then flow through
    // the overlap filter in buildKeywordSets. We run BOTH the full niche
    // AND the core topic in parallel and merge the results, so if the
    // long-tail does have any completions we still capture them.
    // v1.5.206d-fix2 — Datamuse is English-only (returns English LSI words even
    // for Russian/Japanese/Korean queries). Skip it entirely for non-English
    // articles and rely on Google Suggest + language-specific Wikipedia for LSI.
    // fetchWikipedia now uses the article language subdomain (ru.wikipedia.org,
    // ja.wikipedia.org, de.wikipedia.org, etc.).
    const [suggestLong, suggestCore, datamuse, wiki, reddit, serperData] = await Promise.all([
      // v1.5.206d-fix14 — pass baseLang as hl separately from country gl.
      // Pre-fix14 sent `hl=${gl}` (country for both) which produced invalid
      // hl values for Vietnamese/Indonesian/Thai/Japanese etc. (hl=VN/ID/TH
      // are not valid language codes → Google returned no suggestions).
      fetchGoogleSuggest(niche, gl, baseLang),
      (niche !== coreTopic) ? fetchGoogleSuggest(coreTopic, gl, baseLang) : Promise.resolve([]),
      isEnglish ? fetchDatamuse(coreTopic) : Promise.resolve([]),
      fetchWikipedia(niche, baseLang),
      fetchReddit(niche),
      // v1.5.173 — Serper-powered keyword extraction (if key available)
      // v1.5.206d-fix2 — pass language so audience LLM responds in target language
      fetchSerperKeywords(niche, gl, baseLang),
    ]);
    // Merge core-topic suggestions into the main list, deduped
    const suggest = [...suggestLong];
    for (const s of suggestCore) {
      if (!suggest.includes(s)) suggest.push(s);
    }

    // Build topic candidates with scoring
    const topics = buildTopics(niche, suggest, datamuse, wiki, reddit);

    // v1.5.173 — Build keywords from Serper titles + snippets (high quality)
    // then merge with existing Google Suggest + Datamuse results as fallback.
    // v1.5.206d-fix3 — pass language so non-English paths get Google Suggest
    // overflow into LSI and relaxed Wikipedia word-count filters (CJK titles
    // are phrases, not single words).
    const keywords = buildKeywordSets(niche, suggest, datamuse, wiki, baseLang);

    // v1.5.173 — Serper-extracted keywords override when available
    if (serperData && serperData.secondary.length > 0) {
      // Merge Serper secondary into front of list (higher quality)
      const merged = [...serperData.secondary];
      const mergedSet = new Set(merged.map(s => s.toLowerCase()));
      for (const s of keywords.secondary) {
        if (!mergedSet.has(s.toLowerCase())) {
          merged.push(s);
          mergedSet.add(s.toLowerCase());
        }
      }
      keywords.secondary = merged.slice(0, 7);
      keywords.secondary_string = keywords.secondary.join(', ');
    }
    if (serperData && serperData.lsi.length > 0) {
      const merged = [...serperData.lsi];
      const mergedSet = new Set(merged.map(s => s.toLowerCase()));
      for (const s of keywords.lsi) {
        if (!mergedSet.has(s.toLowerCase())) {
          merged.push(s);
          mergedSet.add(s.toLowerCase());
        }
      }
      keywords.lsi = merged.slice(0, 10);
      keywords.lsi_string = keywords.lsi.join(', ');
    }

    // v1.5.206d-fix13 — Native-script LSI prioritization for non-Latin
    // languages. When the article language uses a non-Latin script (Hindi/
    // Cyrillic/CJK/Arabic/Hebrew/Thai/Greek/Korean), Serper-extracted LSI
    // tends to be English-dominant because Indian/Russian/Asian SERPs
    // typically rank English-titled blog posts at the top. Result: Hindi
    // article gets LSI like ['galaxy','smartphone','phone'] — readable but
    // not in the article's script. This block detects the article-language
    // script range and reorders LSI to put NATIVE-SCRIPT words first, with
    // Latin words (brand names like Galaxy, iQOO) kept at the tail. If
    // native LSI is sparse (<5), fills from leftover Google Suggest
    // completions that contain native-script characters. Universal — works
    // for any language with a defined script range.
    const SCRIPT_RANGES = {
      hi: /[ऀ-ॿ]/, mr: /[ऀ-ॿ]/, ne: /[ऀ-ॿ]/,
      ru: /[Ѐ-ӿ]/, uk: /[Ѐ-ӿ]/, bg: /[Ѐ-ӿ]/,
      sr: /[Ѐ-ӿ]/, mk: /[Ѐ-ӿ]/, mn: /[Ѐ-ӿ]/,
      ja: /[぀-ヿ一-鿿]/, zh: /[一-鿿]/,
      ko: /[가-힯]/,
      ar: /[؀-ۿ]/, fa: /[؀-ۿ]/, ur: /[؀-ۿ]/,
      he: /[֐-׿]/, yi: /[֐-׿]/,
      th: /[฀-๿]/, lo: /[຀-໿]/,
      el: /[Ͱ-Ͽ]/, hy: /[԰-֏]/, ka: /[Ⴀ-ჿ]/,
      bn: /[ঀ-৿]/, ta: /[஀-௿]/, te: /[ఀ-౿]/,
      kn: /[ಀ-೿]/, ml: /[ഀ-ൿ]/, gu: /[઀-૿]/,
      pa: /[਀-੿]/, si: /[඀-෿]/,
    };
    const nativeRegex = SCRIPT_RANGES[baseLang];
    if (nativeRegex && Array.isArray(keywords.lsi) && keywords.lsi.length > 0) {
      const native = [];
      const latin = [];
      const seenNorm = new Set();
      for (const w of keywords.lsi) {
        const k = (w || '').toLowerCase().trim();
        if (!k || seenNorm.has(k)) continue;
        seenNorm.add(k);
        (nativeRegex.test(w) ? native : latin).push(w);
      }
      // If native is sparse, fill from leftover Google Suggest phrases that
      // contain native-script characters. `suggest` is the raw Google Suggest
      // array from earlier; `secondary` is what already became user-facing
      // secondary so we skip those.
      if (native.length < 5 && Array.isArray(suggest)) {
        const secondarySet = new Set((keywords.secondary || []).map(s => s.toLowerCase()));
        for (const s of suggest) {
          const phrase = (s || '').toLowerCase().trim();
          if (!phrase || seenNorm.has(phrase) || secondarySet.has(phrase)) continue;
          if (!nativeRegex.test(phrase)) continue;
          if (phrase.length < 4 || phrase.length > 80) continue;
          if (phrase === niche.toLowerCase()) continue;
          seenNorm.add(phrase);
          native.push(phrase);
          if (native.length >= 7) break;
        }
      }
      keywords.lsi = [...native, ...latin].slice(0, 10);
      keywords.lsi_string = keywords.lsi.join(', ');
    }

    // v1.5.173 — Target audience suggestion from Serper source analysis
    if (serperData && serperData.audience) {
      keywords.audience = serperData.audience;
    }

    return res.status(200).json({
      success: true,
      niche,
      topics,
      keywords,
      sources: {
        google_suggest: suggest.length,
        datamuse: datamuse.length,
        wikipedia: wiki.length,
        reddit: reddit.length,
        serper: serperData ? serperData.citation_count : 0,
      },
    });
  } catch (err) {
    console.error('Topic research error:', err);
    return res.status(500).json({ error: 'Research failed: ' + err.message });
  }
}

// ============================================================
// Source 1: Google Suggest (real search queries)
// ============================================================
async function fetchGoogleSuggest(query, gl = '', hl = '') {
  // v1.5.206d-fix15 — CJK-aware variations. English prefixes like "best "
  // appended to a Japanese/Chinese/Korean/Thai query produce invalid
  // mixed-language strings that Google Suggest won't complete. For CJK/Thai
  // use short substrings of the query instead (last N characters, which
  // typically capture the noun tail — e.g. 最高のスマートフォン → スマートフォン).
  const baseHl = (hl || '').toLowerCase().slice(0, 2);
  const isCjkOrThai = ['ja', 'zh', 'ko', 'th', 'lo', 'km', 'my'].includes(baseHl);

  let variations;
  if (isCjkOrThai) {
    // Clean: drop years and whitespace for truncation candidates
    const cleaned = query.replace(/\b20\d{2}\b/g, '').replace(/\s+/g, '').trim();
    const tails = [];
    // Try progressive tail lengths — the noun is usually at the end in CJK
    // ("best smartphone" translates to "最高のスマートフォン" where スマートフォン is the noun at the tail).
    for (const n of [6, 5, 4, 8, 10]) {
      if (cleaned.length > n) {
        const tail = cleaned.slice(-n);
        if (!tails.includes(tail)) tails.push(tail);
      }
    }
    variations = [query, cleaned, ...tails].filter(Boolean);
  } else {
    variations = [
      query,
      'best ' + query,
      'how to ' + query,
      query + ' for',
      query + ' vs',
      'why ' + query,
      'what is ' + query,
    ];
  }

  // v1.5.57 — geo-localize completions so "pet shops" for an AU user returns
  // Australian completions ("pet shops sydney", "pet shops melbourne") not
  // US ones ("pet shops washington", "pet shops florida"). Google Suggest
  // uses `gl=XX` for country and `hl=XX` for language.
  //
  // v1.5.206d-fix14 — hl is LANGUAGE code (vi/id/th/ja/ko/zh), not country
  // code. Pre-fix14 passed `hl=${gl}` which sent country for both → for
  // Vietnamese (gl=VN) became `hl=VN` which Google treats as invalid → no
  // suggestions returned. Now accepts separate `hl` param; falls back to
  // gl for backward-compat if caller doesn't pass hl.
  const hlParam = hl ? encodeURIComponent(hl) : (gl ? encodeURIComponent(gl) : '');
  const geoParams = gl ? `&gl=${encodeURIComponent(gl)}${hlParam ? `&hl=${hlParam}` : ''}` : '';

  const allSuggestions = [];
  for (const v of variations) {
    try {
      const url = `https://suggestqueries.google.com/complete/search?client=firefox&q=${encodeURIComponent(v)}${geoParams}`;
      const resp = await fetch(url, { signal: AbortSignal.timeout(4000) });
      if (!resp.ok) continue;

      // v1.5.206d-fix10 — Google Suggest returns regional encodings for
      // non-Latin queries (e.g. Russian → Windows-1251, Greek → Windows-1253,
      // Hebrew → Windows-1255, Arabic → Windows-1256, Thai → Windows-874).
      // Calling resp.json() always decodes as UTF-8 → garbage replacement
      // characters for those languages. Read raw bytes, detect charset from
      // Content-Type header, decode with TextDecoder. Universal — works for
      // any encoding the response declares. Defaults to UTF-8 when Content-
      // Type doesn't specify a charset (modern responses).
      const contentType = resp.headers.get('content-type') || '';
      const charsetMatch = contentType.match(/charset=([^\s;]+)/i);
      const charset = (charsetMatch ? charsetMatch[1] : 'utf-8').toLowerCase();
      const buffer = await resp.arrayBuffer();
      let text;
      try {
        text = new TextDecoder(charset).decode(buffer);
      } catch (e) {
        // TextDecoder rejects unknown labels — fall back to UTF-8 then Latin-1.
        try {
          text = new TextDecoder('utf-8').decode(buffer);
        } catch (e2) {
          text = new TextDecoder('iso-8859-1').decode(buffer);
        }
      }
      const data = JSON.parse(text);
      // Format: ["query", ["suggestion1", "suggestion2", ...]]
      if (Array.isArray(data) && Array.isArray(data[1])) {
        data[1].forEach(s => {
          if (s && s.length > query.length && !allSuggestions.includes(s)) {
            allSuggestions.push(s);
          }
        });
      }
    } catch {}
  }
  return allSuggestions.slice(0, 30);
}

// ============================================================
// v1.5.35 — Extract the core business/topic hint from a long-tail keyword.
// Strips location names, years, and generic SEO qualifiers ("best", "top",
// "must-try", "2026", etc) so Datamuse's ml= query receives a short 1-3 word
// topic it can actually match against.
//
// Examples:
//   "best gelato shops in lucignano italy 2026" → "gelato shops"
//   "top 10 restaurants in rome italy"          → "restaurants"
//   "how to introduce raw food to a dog"        → "raw food dog"
//   "dog vitamins australia"                    → "dog vitamins"
// ============================================================
/**
 * v1.5.58 — extract the target location from a niche that contains "in X"
 * or "near X" (e.g. "best pet shops in mudgee nsw 2026" → ["mudgee", "nsw"]).
 * Returns an array of location tokens (lowercased, ≥3 chars, stopwords removed).
 * Empty array if no location clause found.
 */
function extractLocationTokens(niche) {
  if (!niche || typeof niche !== 'string') return [];
  const n = niche.toLowerCase().trim();
  const m = n.match(/\b(?:in|near|around|at)\s+(.+?)(?:\s+\d{4})?$/);
  if (!m) return [];
  const stop = new Set(['the','and','for','with','from','are','you','can','of','to','a','an','best','top','new','old','nsw','vic','qld','wa','sa','tas','act','nt','usa','uk','us','uae']);
  return m[1]
    .replace(/[^\p{L}\p{N}\s]/gu, ' ')
    .split(/\s+/)
    .map(w => w.trim())
    .filter(w => w.length >= 3 && !stop.has(w) && !/^\d+$/.test(w));
}

/**
 * v1.5.58 — blocklist of ~100 common English-speaking cities and US states
 * that Google Suggest frequently returns as completions for generic topic
 * queries. Used to filter secondary keyword suggestions so a local article
 * about (e.g.) Mudgee NSW doesn't end up with "pet shops washington" as a
 * secondary keyword. Suggestions containing any of these words are rejected
 * UNLESS they also contain the target location tokens.
 */
const OTHER_CITY_BLOCKLIST = new Set([
  // Major US cities
  'new york','los angeles','chicago','houston','phoenix','philadelphia','san antonio','san diego','dallas','austin','jacksonville','indianapolis','columbus','charlotte','san francisco','seattle','denver','washington','boston','nashville','baltimore','oklahoma','portland','las vegas','memphis','milwaukee','tucson','fresno','sacramento','atlanta','miami','tampa','cleveland','minneapolis','detroit','pittsburgh','orlando','cincinnati','kansas city','st louis','raleigh','salt lake',
  // US states (frequent in Google Suggest)
  'california','texas','florida','georgia','illinois','ohio','michigan','virginia','arizona','colorado','maryland','wisconsin','minnesota','alabama','louisiana','kentucky','oregon','oklahoma','connecticut','iowa','utah','nevada','arkansas','mississippi','kansas','nebraska','idaho','hawaii','maine','montana','alaska','vermont','wyoming',
  // Major UK/AU/CA cities
  'london','manchester','birmingham','glasgow','edinburgh','liverpool','bristol','leeds','sheffield','cardiff','belfast',
  'sydney','melbourne','brisbane','perth','adelaide','darwin','hobart','canberra','gold coast','newcastle','geelong','wollongong',
  'toronto','montreal','vancouver','calgary','ottawa','edmonton','winnipeg','quebec',
  // Major EU cities
  'paris','berlin','madrid','rome','milan','amsterdam','barcelona','munich','vienna','prague','dublin','lisbon','athens','florence','naples','venice','zurich','geneva','brussels','copenhagen','stockholm','oslo','helsinki','warsaw',
  // Major Asian cities
  'tokyo','osaka','kyoto','seoul','beijing','shanghai','hong kong','singapore','bangkok','mumbai','delhi','dubai','manila','jakarta',
]);

function extractCoreTopic(query, lang = '') {
  if (!query || typeof query !== 'string') return query || '';
  let q = query.toLowerCase().trim();

  // Drop year (4-digit number)
  q = q.replace(/\b20\d{2}\b/g, '');

  // v1.5.206d-fix16 — language-aware core-topic extraction for non-Latin
  // compound queries. Pre-fix16 the English stop-word lists below do
  // nothing for Japanese/Chinese/Korean/Thai/Arabic/Hindi/Cyrillic queries,
  // so the "core topic" for ソラナの最高のミームコイン 2026 was just the
  // whole string minus the year. Google Suggest had no useful completions
  // for that compound phrase → zero secondary. Fix: for each non-Latin
  // language, strip common particles/determiners/adjectives that carry
  // no topic signal, then fall back to the longest meaningful character
  // run (most likely the noun).
  const baseLang = (lang || '').toLowerCase().slice(0, 2);
  const particleMap = {
    ja: /の|は|が|を|に|で|と|も|や|な|から|まで|へ|より|こと|もの|最高|最も|最良|最適|良い|良質|最新|おすすめ/g,
    zh: /的|了|和|与|在|是|最|最好|最佳|最新|推荐|最新款/g,
    ko: /의|은|는|이|가|을|를|에|에서|으로|와|과|도|만|부터|까지|최고|최고의|가장|베스트|추천/g,
    th: /ที่|ของ|และ|ใน|กับ|จาก|ไป|มา|ให้|ได้|ดีที่สุด|ที่ดี|ยอด|ยอดนิยม|ที่สุด|แนะนำ/g,
    hi: /के|का|की|को|में|पर|से|और|या|है|हैं|सर्वश्रेष्ठ|सबसे|अच्छा|सबसे अच्छा|बेहतर|बेस्ट/g,
    ar: /ال|في|من|على|إلى|عن|مع|أو|و|أفضل|الأفضل|أحسن|الأحسن/g,
    he: /ה|של|את|ב|מ|ל|עם|או|ו|הטוב|הטובה|הטובים|ביותר|הטוב ביותר/g,
    ru: /\b(лучший|лучшие|самый|самые|хороший|хорошие|лучших|лучшего|лучшая|на|для|из|по|в|к)\b/gi,
    uk: /\b(найкращий|найкращі|найкращих|найкращого|найкраща|найкраще|кращий|кращі|хороший|хороші|на|для|з|по|в|до)\b/gi,
    el: /\b(καλύτερο|καλύτερα|καλύτερος|καλύτερη|κορυφαίο|κορυφαία|κορυφαίος|κορυφαίοι|στο|στη|στην|στους|από|για|με|ή)\b/gi,
  };
  if (particleMap[baseLang]) {
    q = q.replace(particleMap[baseLang], ' ').replace(/\s+/g, ' ').trim();
    // If result is still one long no-space token (CJK/Thai), take the
    // longest contiguous character run (the noun) as the core topic.
    const noSpace = ['ja', 'zh', 'ko', 'th', 'lo', 'km', 'my'].includes(baseLang);
    if (noSpace && !/\s/.test(q) && q.length > 6) {
      // Longest run of native-script chars between whitespace/ASCII
      const runs = q.split(/[\s -]+/).filter(Boolean);
      if (runs.length > 0) {
        q = runs.sort((a, b) => b.length - a.length)[0];
      }
    }
    // Guard: if we over-stripped, restore original-minus-year
    if (q.length < 2) {
      q = query.toLowerCase().trim().replace(/\b20\d{2}\b/g, '').trim();
    }
    return q;
  }

  // Drop generic SEO qualifiers
  const stopQualifiers = [
    'best', 'top', 'greatest', 'finest', 'cheapest', 'biggest', 'must try',
    'must-try', 'must have', 'must-have', 'ultimate', 'complete', 'essential',
    'recommended', 'favorite', 'popular', 'trending', 'new', 'latest',
    'guide', 'review', 'reviews', 'tips', 'how to', 'what is', 'where to',
    'which', 'when', 'how', 'why', 'should i', 'should you',
  ];
  const qualifierRe = new RegExp('\\b(' + stopQualifiers.map(w => w.replace(' ', '\\s+')).join('|') + ')\\b', 'gi');
  q = q.replace(qualifierRe, '');

  // Drop "in X [country]" location clauses — keep the business type that
  // precedes "in". If the query has " in ", everything after is location.
  const inMatch = q.match(/^(.*?)\s+in\s+/);
  if (inMatch) {
    q = inMatch[1];
  }

  // Drop country/region names that commonly leak into queries
  const countries = [
    'italy', 'france', 'spain', 'germany', 'portugal', 'greece', 'uk',
    'usa', 'america', 'australia', 'canada', 'new zealand', 'japan',
    'china', 'korea', 'thailand', 'vietnam', 'india', 'mexico', 'brazil',
    'argentina', 'tuscany', 'lombardy', 'sicily', 'andalusia', 'provence',
  ];
  const countryRe = new RegExp('\\b(' + countries.join('|') + ')\\b', 'gi');
  q = q.replace(countryRe, '');

  // v1.5.59 — aggressively strip action verbs, pronouns, articles,
  // prepositions, conjunctions, and adverbs. Datamuse's ml= endpoint
  // works best with 1-3 CONCRETE NOUN queries. Long "how to ..." phrases
  // like "transition your dog to raw food safely" previously got
  // truncated to "transition your dog to raw foo" and returned garbage
  // semantic associations (lion, curb, race, nose) because Datamuse
  // treated each individual word independently. Stripping filler leaves
  // "dog raw food" which Datamuse handles correctly.
  const stopContentWords = [
    // Action verbs common in how-to/informational queries
    'transition', 'transitioning', 'introduce', 'introducing', 'train', 'training',
    'teach', 'teaching', 'feed', 'feeding', 'choose', 'choosing', 'pick', 'picking',
    'select', 'selecting', 'switch', 'switching', 'change', 'changing', 'move',
    'moving', 'make', 'making', 'start', 'starting', 'begin', 'beginning', 'stop',
    'stopping', 'prepare', 'preparing', 'give', 'giving', 'find', 'finding', 'know',
    'knowing', 'understand', 'use', 'using', 'try', 'trying', 'help', 'helping',
    'keep', 'keeping', 'avoid', 'avoiding', 'prevent', 'preventing', 'fix', 'fixing',
    'solve', 'solving', 'improve', 'improving', 'learn', 'learning', 'safely',
    'quickly', 'easily', 'properly', 'correctly', 'slowly', 'carefully', 'gradually',
    'naturally', 'effectively', 'efficiently',
    // Articles + pronouns
    'a', 'an', 'the', 'your', 'my', 'his', 'her', 'their', 'our', 'its', 'some',
    // Prepositions
    'to', 'for', 'from', 'with', 'about', 'into', 'onto', 'by', 'of', 'on', 'at',
    'as', 'like', 'up', 'down', 'off', 'out', 'over', 'under',
    // Conjunctions
    'and', 'or', 'but', 'so', 'if', 'then', 'that', 'than', 'because',
    // Generic nouns that add no topic signal
    'way', 'ways', 'method', 'methods', 'step', 'steps', 'thing', 'things', 'type',
    'types', 'kind', 'kinds', 'sort', 'sorts', 'option', 'options',
  ];
  const stopContentRe = new RegExp('\\b(' + stopContentWords.join('|') + ')\\b', 'gi');
  q = q.replace(stopContentRe, '');

  // Collapse whitespace
  q = q.replace(/\s+/g, ' ').trim();

  // If we stripped too much, fall back to the last 3 content words of the original
  if (q.length < 3) {
    const allStopWords = new Set([
      ...stopQualifiers.flatMap(s => s.split(/\s+/)),
      ...stopContentWords,
      ...countries,
    ]);
    const words = query.toLowerCase().trim().split(/\s+/)
      .filter(w => w.length >= 3 && !allStopWords.has(w) && !/^\d+$/.test(w));
    q = words.slice(-3).join(' ');
  }

  // v1.5.59 — cap at 3 words (not 30 chars). Datamuse's ml= endpoint works
  // best with 1-3 noun queries. Character truncation previously cut "raw
  // food" to "raw foo" which matched the wrong semantic cluster.
  const topicWords = q.split(/\s+/).filter(w => w.length >= 3);
  if (topicWords.length > 3) {
    // Prefer the LAST 3 content words (the topic usually comes at the end
    // after the verbs/qualifiers are stripped).
    q = topicWords.slice(-3).join(' ');
  } else {
    q = topicWords.join(' ');
  }

  return q;
}

// ============================================================
// Source 2: Datamuse (semantic word clusters)
// ============================================================
async function fetchDatamuse(query) {
  try {
    // v1.5.35 — Datamuse ml= returns results with a `score` field (typically
    // 0-20000). High scores indicate strong semantic relevance. We request
    // 40 results, then filter by score and POS tags in buildKeywordSets.
    // md=fp adds frequency + part-of-speech tags per result.
    const url = `https://api.datamuse.com/words?ml=${encodeURIComponent(query)}&max=40&md=fp`;
    const resp = await fetch(url, { signal: AbortSignal.timeout(5000) });
    if (!resp.ok) return [];
    const data = await resp.json();
    return data.map(d => ({
      word: d.word,
      score: d.score || 0,
      freq: d.tags ? parseFreq(d.tags) : 0,
      pos: d.tags ? parsePOS(d.tags) : '',
    }));
  } catch {
    return [];
  }
}

// v1.5.35 — extract part-of-speech from Datamuse tags array. Returns the
// first POS tag found ('n', 'v', 'adj', 'adv') or empty string.
function parsePOS(tags) {
  for (const t of tags) {
    if (['n', 'v', 'adj', 'adv'].includes(t)) return t;
  }
  return '';
}

function parseFreq(tags) {
  const f = tags.find(t => t.startsWith('f:'));
  return f ? parseFloat(f.substring(2)) : 0;
}

// ============================================================
// Source 3: Wikipedia OpenSearch (subtopics)
// ============================================================
async function fetchWikipedia(query, lang = 'en') {
  try {
    // v1.5.206d-fix2 — use the article's language subdomain.
    // Wikipedia has 300+ language editions; most major articles have their own
    // language version with native-language titles. This is how LSI keywords
    // become Russian/Japanese/Korean/etc. instead of falling back to English
    // titles when the query is non-Latin.
    const validLang = /^[a-z]{2,3}$/.test(lang) ? lang : 'en';
    const url = `https://${validLang}.wikipedia.org/w/api.php?action=opensearch&search=${encodeURIComponent(query)}&limit=15&format=json&namespace=0`;
    const resp = await fetch(url, {
      headers: { 'User-Agent': 'SEOBetter/1.0' },
      signal: AbortSignal.timeout(5000),
    });
    if (!resp.ok) return [];
    const data = await resp.json();
    // Format: [query, [titles], [descriptions], [urls]]
    if (Array.isArray(data) && Array.isArray(data[1])) {
      return data[1].map((title, i) => ({
        title,
        url: data[3] && data[3][i] ? data[3][i] : '',
      }));
    }
    return [];
  } catch {
    return [];
  }
}

// ============================================================
// Source 4: Reddit (real questions + audience demand)
// ============================================================
async function fetchReddit(query) {
  try {
    const url = `https://old.reddit.com/search.json?q=${encodeURIComponent(query)}&sort=relevance&t=year&limit=25`;
    const resp = await fetch(url, {
      headers: { 'User-Agent': 'SEOBetter/1.0 (Research Bot)' },
      signal: AbortSignal.timeout(6000),
    });
    if (!resp.ok) return [];
    const data = await resp.json();
    const posts = (data?.data?.children || []).map(c => ({
      title: c.data.title,
      score: c.data.score || 0,
      comments: c.data.num_comments || 0,
      subreddit: c.data.subreddit,
      url: 'https://reddit.com' + c.data.permalink,
      isQuestion: /\?$|^(how|why|what|when|where|which|can|do|is|are|should)/i.test(c.data.title),
    }));
    return posts;
  } catch {
    return [];
  }
}

// ============================================================
// v1.5.22 — Keyword set builder for the Auto-suggest button
// ============================================================
// Extracts short keyword phrases from the raw research arrays so the
// Auto-suggest button in admin/views/content-generator.php can populate
// the secondary_keywords + lsi_keywords fields directly. The LLM path
// (/api/generate with strict-format prompt) was unreliable because Llama
// wrapped output in markdown, breaking the client-side regex parser.
// ============================================================
// v1.5.173 — Serper-powered keyword extraction
// Calls Serper (Google SERP), extracts secondary keywords from
// page titles, LSI terms from snippets, and audience from domains.
// ============================================================
async function fetchSerperKeywords(keyword, gl = '', lang = 'en') {
  const SERPER_KEY = process.env.SERPER_API_KEY;
  if (!SERPER_KEY) return null;

  try {
    const body = { q: keyword, num: 10 };
    if (gl) body.gl = gl;

    const resp = await fetch('https://google.serper.dev/search', {
      method: 'POST',
      headers: { 'X-API-KEY': SERPER_KEY, 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(6000),
    });
    if (!resp.ok) return null;
    const data = await resp.json();
    const results = data?.organic || [];
    if (results.length === 0) return null;

    const kwLower = keyword.toLowerCase();
    const kwWords = new Set(kwLower.split(/\s+/).filter(w => w.length >= 3));
    // Stop words to filter out of extracted phrases
    const STOP = new Set(['the','and','for','with','from','are','you','can','how','why','what',
      'when','where','who','this','that','your','our','their','its','has','have','had',
      'was','were','been','being','will','would','could','should','does','did','not',
      'but','about','into','over','after','before','between','under','above','more',
      'most','just','also','than','then','very','much','each','every','some','any',
      'all','both','few','many','such','only','same','other','like','best','top',
      'new','2024','2025','2026','2027','complete','ultimate','guide','review',
      'tips','list','everything','need','know','must','revealed','simple','steps']);

    // --- Extract secondary keywords from titles ---
    // Titles of top-ranking pages contain the exact phrases competitors target
    const secondary = [];
    const seenSec = new Set();
    for (const r of results) {
      const title = (r.title || '').toLowerCase();
      if (!title) continue;
      // Remove site name suffix ("... - Website Name" or "| Website Name")
      const cleaned = title.replace(/\s*[-|]\s*[^-|]+$/, '').trim();
      if (cleaned === kwLower || cleaned.length < 8) continue;
      // Extract 2-4 word phrases from the title that aren't the keyword itself.
      // v1.5.206d-fix12 — Unicode-aware tokenization. Previously split on
      // /[^a-z0-9]+/ which treated every Devanagari, Cyrillic, CJK, Arabic,
      // Hangul, Thai character as a separator → only Latin words extracted →
      // non-English LSI/secondary came back English-only when SERP results
      // happened to be in the article's target language. Now uses
      // /[^\p{L}\p{N}]+/u (any character not a Letter or Number is a
      // separator) so words in any script are preserved.
      const words = cleaned.split(/[^\p{L}\p{N}]+/u).filter(w => w.length >= 3 && !STOP.has(w));
      // Build 2-3 word ngrams
      for (let i = 0; i < words.length - 1; i++) {
        const bigram = words[i] + ' ' + words[i+1];
        if (seenSec.has(bigram) || kwLower.includes(bigram)) continue;
        // Must share at least one word with the keyword (relevance check)
        if (!kwWords.has(words[i]) && !kwWords.has(words[i+1])) continue;
        seenSec.add(bigram);
        secondary.push(bigram);
      }
      if (words.length >= 3) {
        for (let i = 0; i < words.length - 2; i++) {
          const trigram = words[i] + ' ' + words[i+1] + ' ' + words[i+2];
          if (seenSec.has(trigram) || kwLower.includes(trigram)) continue;
          if (!kwWords.has(words[i]) && !kwWords.has(words[i+1]) && !kwWords.has(words[i+2])) continue;
          seenSec.add(trigram);
          secondary.push(trigram);
        }
      }
    }

    // --- Extract LSI keywords from snippets ---
    // Snippets contain semantic terms that Google associates with this topic
    const lsi = [];
    const seenLsi = new Set();
    const allSnippetText = results.map(r => (r.snippet || '').toLowerCase()).join(' ');
    // v1.5.206d-fix12 — Unicode-aware split (see line ~518 comment).
    const snippetWords = allSnippetText.split(/[^\p{L}\p{N}]+/u).filter(w => w.length >= 4);
    // Count word frequency across all snippets
    const freq = {};
    for (const w of snippetWords) {
      if (STOP.has(w) || kwWords.has(w)) continue;
      freq[w] = (freq[w] || 0) + 1;
    }
    // Sort by frequency, take top terms that appear 2+ times
    const sorted = Object.entries(freq).sort((a, b) => b[1] - a[1]);
    for (const [word, count] of sorted) {
      if (count < 2) break;
      if (seenLsi.has(word) || seenSec.has(word)) continue;
      seenLsi.add(word);
      lsi.push(word);
      if (lsi.length >= 10) break;
    }

    // v1.5.193 — Audience + category inference via LLM on the live SERP.
    //
    // Previous versions (v1.5.173 → v1.5.180) used hand-written regex maps
    // over ~10 hard-coded topic buckets (healthcare, developer, recipe,
    // finance, pet, travel, crypto, business, beginner). This violated
    // the plugin's universal-rule principle and produced false positives:
    //
    //   Keyword: "should university be free in australia"
    //   SERP snippet mentions "nurse training" or "patient protests"
    //     → old regex matched /\b(patient|nurse)\b/
    //     → audience = "healthcare professionals and patients seeking
    //                   medical information"
    //
    // The LLM call below takes the keyword + top-8 SERP titles, domains,
    // and snippets and returns a fresh audience + category sized for the
    // actual topic. Works for any keyword in any language, no maintenance
    // required when new topics emerge. Falls back to empty strings on
    // LLM error (frontend handles the empty case gracefully — user fills
    // the fields manually).
    const { audience, category } = await inferAudienceAndCategoryWithLLM(
      keyword,
      results.slice(0, 8),
      lang
    );

    return {
      secondary: secondary.slice(0, 7),
      lsi: lsi.slice(0, 10),
      audience,
      category,
      citation_count: results.length,
    };
  } catch (err) {
    console.error('Serper keyword extraction error (non-fatal):', err.message);
    return null;
  }
}

/**
 * v1.5.193 — LLM-based audience + category inference.
 *
 * Replaces the hand-written regex blocks that previously inferred
 * audience and category from hard-coded topic buckets. Calls the
 * same OPENROUTER_KEY + gpt-4.1-mini infrastructure used elsewhere
 * in the backend so no new credentials are needed.
 *
 * @param {string} keyword     The search keyword the user entered.
 * @param {Array}  serpResults Up to 8 Serper SERP results ({title, link, snippet}).
 * @returns {Promise<{audience:string, category:string}>}
 *          Empty strings when the LLM is unavailable or errors out —
 *          never a hard-coded fallback. The frontend shows empty fields
 *          and lets the user fill them manually.
 */
async function inferAudienceAndCategoryWithLLM(keyword, serpResults, lang = 'en') {
  const OPENROUTER_KEY = process.env.OPENROUTER_KEY;
  if (!OPENROUTER_KEY || !Array.isArray(serpResults) || serpResults.length === 0) {
    return { audience: '', category: '' };
  }

  // v1.5.206d-fix2 — map BCP-47 base code to full language name for the prompt.
  // When the article is non-English, the audience description must come back
  // in the target language so it renders correctly in the audience form field
  // and threads through to the generation prompt without English leaks.
  const langNames = {
    en: 'English', ja: 'Japanese', zh: 'Chinese (Simplified)', ko: 'Korean',
    ru: 'Russian', de: 'German', fr: 'French', es: 'Spanish', it: 'Italian',
    pt: 'Portuguese', hi: 'Hindi', ar: 'Arabic', nl: 'Dutch', pl: 'Polish',
    tr: 'Turkish', sv: 'Swedish', da: 'Danish', no: 'Norwegian', fi: 'Finnish',
    cs: 'Czech', hu: 'Hungarian', ro: 'Romanian', el: 'Greek', uk: 'Ukrainian',
    vi: 'Vietnamese', th: 'Thai', id: 'Indonesian', ms: 'Malay', he: 'Hebrew',
  };
  const langName = langNames[lang] || 'English';

  const serpLines = serpResults.slice(0, 8).map((r, i) => {
    let host = '';
    try { host = new URL(r.link || '').hostname.replace(/^www\./, ''); } catch { /* noop */ }
    const title = (r.title || '').toString().slice(0, 120);
    const snippet = (r.snippet || '').toString().slice(0, 220);
    return `${i + 1}. [${host}] ${title}${snippet ? ' — ' + snippet : ''}`;
  }).join('\n');

  const allowedCategories = [
    'health','veterinary','technology','finance','food','travel','sports','science',
    'ecommerce','cryptocurrency','business','entertainment','weather','government',
    'education','legal','real_estate','automotive','fashion','parenting','lifestyle',
    'gaming','arts','religion','politics','general',
  ];

  const prompt = `Google search keyword: "${keyword}"

Top ranking results:
${serpLines}

Return JSON with exactly two fields:
- "audience": a 5-15 word description of WHO searches for this specific keyword, written in ${langName}. Be specific to the keyword (e.g. for "лучшие смартфоны 2026" output Russian like "российские покупатели смартфонов, технообзорщики и сравнительные шопперы"). Do NOT default to generic groups unless the keyword is actually about them. If the keyword is about a policy, country, or specific group, name them. The audience description must be in ${langName} regardless of the English prompt instructions.
- "category": ONE value from this English list that best matches the topic: ${allowedCategories.join(', ')}. Use "general" if nothing fits. Category STAYS IN ENGLISH — it's a machine-readable slug, not reader-facing copy.

Output only the JSON object, no markdown fences, no explanation.`;

  try {
    const resp = await fetch('https://openrouter.ai/api/v1/chat/completions', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${OPENROUTER_KEY}`,
      },
      body: JSON.stringify({
        model: process.env.EXTRACTION_MODEL || 'openai/gpt-4.1-mini',
        messages: [
          { role: 'system', content: 'You classify search keywords by target audience and topic category. You read the keyword and SERP results carefully and never default to generic buckets unrelated to the keyword.' },
          { role: 'user', content: prompt },
        ],
        max_tokens: 150,
        temperature: 0.0,
        response_format: { type: 'json_object' },
      }),
      signal: AbortSignal.timeout(8000),
    });

    if (!resp.ok) return { audience: '', category: '' };
    const data = await resp.json();
    let content = data?.choices?.[0]?.message?.content || '';
    if (!content) return { audience: '', category: '' };

    // Strip any markdown fences just in case response_format is ignored
    content = content.replace(/^```(?:json)?\s*\n?/gm, '').replace(/\n?```\s*$/gm, '').trim();
    const parsed = JSON.parse(content);

    const audience = typeof parsed.audience === 'string' ? parsed.audience.trim().slice(0, 200) : '';
    let category = typeof parsed.category === 'string' ? parsed.category.trim().toLowerCase() : '';
    // Validate category against the allowed list; anything else → empty (not 'general'
    // — 'general' is a valid pick but we don't want to fake it when the LLM errored).
    if (category && !allowedCategories.includes(category)) category = '';

    return { audience, category };
  } catch (err) {
    console.error('inferAudienceAndCategoryWithLLM error (non-fatal):', err.message);
    return { audience: '', category: '' };
  }
}

function buildKeywordSets(niche, suggest, datamuse, wiki, lang = 'en') {
  const isEnglish = lang === 'en' || !lang;
  const nicheLower = (niche || '').toLowerCase().trim();
  const seen = new Set([ nicheLower ]);

  // Secondary keywords — real Google Suggest variations of the niche.
  // These are full phrases people actually search. 5-7 best fits.
  // v1.5.54 — overlap filter relaxed from "word length > 3" to "word length
  // >= 3" so 3-letter niche tokens like "pet", "cat", "gym", "vet", "bar"
  // count as overlap signals. This was dropping valid suggestions like
  // "pet supplies online" or "vet clinic near me" because the filter only
  // looked at words ≥4 chars, so "pet shops in mudgee" → filter words
  // ["best","shops","mudgee","2026"] missed the core topic word "pet".
  // v1.5.58 — location-aware filter. For local-intent keywords like
  // "best pet shops in mudgee nsw 2026", extract the target location
  // ("mudgee nsw") and reject any Google Suggest completion that names
  // a DIFFERENT city. Previously "pet shops sydney" and "pet shops
  // washington" were accepted because they contained "shops" — valid
  // overlap but completely wrong for a Mudgee article. Now such
  // suggestions are dropped unless they also contain a Mudgee/NSW token.
  const secondary = [];
  const nicheParts = nicheLower.split(/\s+/).filter(w => w.length >= 3 && !['the','and','for','with','from','are','you','can','how','why','what','when','where','who','2024','2025','2026','2027','2028'].includes(w));

  // v1.5.206d-fix14 — character-level overlap for CJK/Thai/Lao/Khmer/Burmese.
  // These scripts have no inter-word whitespace, so `.split(/\s+/)` returns
  // the whole phrase as one token; Google Suggest completions rarely
  // contain the ENTIRE niche verbatim → word-level overlap fails → zero
  // secondary keywords.
  //
  // v1.5.206d-fix16 — upgrade from 2-char set-intersection to 3-char n-gram
  // substring matching. Pre-fix16 any 2 shared characters would pass, which
  // allowed false matches like ムコダイン (a pharmaceutical name) passing
  // the filter for a ミームコイン query because both contain ム/コ. 3-char
  // n-gram substring matching requires an UNBROKEN 3-character sequence
  // of the niche to appear in the phrase — kills the cross-word false
  // matches while keeping legitimate semantic matches.
  const baseLang = (lang || '').toLowerCase().slice(0, 2);
  const isNoSpace = ['ja', 'zh', 'ko', 'th', 'lo', 'km', 'my'].includes(baseLang);
  // Build 3-char n-grams of the niche (no-space version, year stripped)
  const nicheNoSpace = nicheLower.replace(/\s+|20\d{2}/g, '');
  const nicheNgrams3 = new Set();
  if (isNoSpace && nicheNoSpace.length >= 3) {
    for (let i = 0; i <= nicheNoSpace.length - 3; i++) {
      nicheNgrams3.add(nicheNoSpace.slice(i, i + 3));
    }
  }

  const targetLocationTokens = extractLocationTokens(niche);
  const hasTargetLocation = targetLocationTokens.length > 0;

  for (const s of suggest) {
    const phrase = (s || '').toLowerCase().trim();
    if (!phrase || seen.has(phrase)) continue;
    if (phrase.length < 6 || phrase.length > 80) continue;

    // Must contain the niche or a piece of it (sanity filter)
    let overlaps = nicheParts.some(w => phrase.includes(w));
    // v1.5.206d-fix16 — for CJK/Thai/etc, fall back to 3-char n-gram match
    if (!overlaps && isNoSpace && nicheNgrams3.size > 0) {
      const phraseNoSpace = phrase.replace(/\s+/g, '');
      for (const ng of nicheNgrams3) {
        if (phraseNoSpace.includes(ng)) {
          overlaps = true;
          break;
        }
      }
    }
    if (!overlaps) continue;

    // v1.5.58 — location filter. If this keyword is location-specific,
    // reject suggestions containing a different city from the global
    // blocklist unless they also contain the target location tokens.
    if (hasTargetLocation) {
      const containsTargetLocation = targetLocationTokens.some(t => phrase.includes(t));
      if (!containsTargetLocation) {
        // Check if phrase contains any other-city blocklist term
        let containsOtherCity = false;
        for (const city of OTHER_CITY_BLOCKLIST) {
          if (phrase.includes(city)) { containsOtherCity = true; break; }
        }
        if (containsOtherCity) continue;
      }
    }

    seen.add(phrase);
    secondary.push(phrase);
    if (secondary.length >= 7) break;
  }

  // v1.5.58 — for local-intent keywords, synthesize additional secondary
  // keywords by combining the target location with common business-type
  // variations. Real small towns almost never have Google Suggest data
  // for their specific business types, so Google returns 0-3 phrases
  // after filtering. Augment with synthetic combinations so the article
  // has enough secondary keyword signals.
  if (hasTargetLocation && secondary.length < 5) {
    const locationStr = targetLocationTokens.slice(0, 2).join(' ');
    const core = extractCoreTopic(niche);
    if (core && core.length >= 3) {
      // Generate variations: "core + location", "location + core",
      // and a few business-type swaps.
      const synths = [
        `${core} ${locationStr}`,
        `${locationStr} ${core}`,
        `${core} near ${locationStr}`,
        `best ${core} ${locationStr}`,
        `${locationStr} ${core.replace(/shops?/, 'supplies').replace(/stores?/, 'supplies')}`,
      ];
      for (const s of synths) {
        const phrase = s.toLowerCase().trim().replace(/\s+/g, ' ');
        if (!phrase || seen.has(phrase)) continue;
        if (phrase === core || phrase === nicheLower) continue;
        seen.add(phrase);
        secondary.push(phrase);
        if (secondary.length >= 7) break;
      }
    }
  }

  // LSI keywords — semantic single-word terms from Datamuse.
  // v1.5.35 — much stricter filtering to prevent garbage like "aborigines",
  // "balance of payments", "lidl", "arsenal", "magazine" from leaking into
  // the user's LSI field. Requires:
  //   1. Datamuse score >= 1000 (below that is weak noise)
  //   2. Noun or adjective (POS filter) — no verbs, no adverbs
  //   3. Not a country, brand, or demographic term (blocklist)
  //   4. Single word or 2-word phrase (no 3+ word junk)
  //   5. Not a subset of the niche, not in secondary words
  //
  // 8-10 best fits, deduplicated against secondary phrases.
  const BLOCKLIST = new Set([
    // Countries + regions (leak in when Datamuse parses a multi-word query)
    'italy', 'france', 'spain', 'germany', 'portugal', 'greece', 'england',
    'britain', 'america', 'australia', 'canada', 'japan', 'china', 'korea',
    'india', 'mexico', 'brazil', 'russia', 'europe', 'asia', 'africa',
    'aborigines', 'aboriginal', 'population', 'demographics', 'government',
    // Economic/political terms (Datamuse loves these for any country query)
    'economy', 'politics', 'policy', 'balance', 'payments', 'inflation',
    'gdp', 'tariff', 'trade', 'ministry',
    // Brands that hit unrelated queries
    'lidl', 'aldi', 'walmart', 'tesco', 'amazon', 'ebay', 'google',
    'arsenal', 'chelsea', 'liverpool', 'manchester', 'juventus',
    // Generic media
    'magazine', 'newspaper', 'journal', 'blog', 'website', 'article',
    // Adjectives that mean nothing in LSI
    'best', 'top', 'great', 'amazing', 'wonderful', 'perfect', 'excellent',
    'good', 'bad', 'new', 'old', 'recent', 'modern', 'popular',
    // Meta words
    'guide', 'review', 'list', 'example', 'type', 'kind', 'sort', 'way',
    'thing', 'stuff', 'place', 'area', 'region', 'location',
    // Year-like
    'year', 'years', 'decade', 'century', 'today', 'tomorrow', 'yesterday',
  ]);

  const lsi = [];
  const secondaryWords = new Set();
  secondary.forEach(s => s.split(/\s+/).forEach(w => secondaryWords.add(w)));

  // Core topic words from the extracted hint — the LSI results should be
  // semantically clustered around THIS, not around full-sentence noise
  const coreWords = extractCoreTopic(niche).split(/\s+/).filter(w => w.length > 3);

  for (const d of datamuse) {
    const word = (d.word || '').toLowerCase().trim();
    if (!word || word.length < 4 || word.length > 30) continue;
    if (seen.has(word) || secondaryWords.has(word)) continue;
    // Skip exact-match niche words
    if (nicheLower.includes(word)) continue;
    // v1.5.35 — Datamuse score threshold. Below 1000 is typically noise.
    if ((d.score || 0) < 1000) continue;
    // v1.5.35 — POS filter. Keep only nouns and adjectives. Skip verbs,
    // adverbs, and POS-less results (which are often rare/weird words).
    if (d.pos && !['n', 'adj'].includes(d.pos)) continue;
    // v1.5.35 — blocklist filter
    if (BLOCKLIST.has(word)) continue;
    // v1.5.35 — phrase junk filter (Datamuse can return multi-word results
    // which are almost always noise for LSI keyword purposes)
    if (/\s/.test(word)) continue;
    seen.add(word);
    lsi.push(word);
    if (lsi.length >= 10) break;
  }

  // If Datamuse returned too few results, top up with Wikipedia titles.
  // English: single-word or 2-word only (prevents "balance of payments" noise).
  // Non-English: allow up to 4-word phrases because CJK/Cyrillic Wikipedia
  // titles are typically compound phrases (e.g. "한국의 커피 문화" = "Korean
  // coffee culture" is 3 words and is a legitimate LSI).
  if (lsi.length < 6) {
    const maxWords = isEnglish ? 2 : 4;
    for (const w of wiki) {
      const title = (w.title || '').toLowerCase().trim();
      if (!title) continue;
      const wordCount = title.split(/\s+/).length;
      if (wordCount > maxWords) continue;
      if (seen.has(title)) continue;
      if (nicheLower.includes(title) || title.includes(nicheLower)) continue;
      seen.add(title);
      lsi.push(title);
      if (lsi.length >= 10) break;
    }
  }

  // v1.5.206d-fix3 — For non-English articles, overflow Google Suggest phrases
  // into LSI. Datamuse is English-only and skipped for non-English; Wikipedia
  // often returns <6 results for specific queries. Google Suggest typically
  // returns 10+ native-language variations — the first 7 become secondary,
  // the remaining 3-7 are perfectly good semantic variations and in Korean/
  // Japanese/Russian/Chinese/Arabic they are the primary way to get
  // native-language LSI signals into the article's keyword context.
  // English path is unchanged: Datamuse + Wikipedia handle LSI fully.
  if (!isEnglish && lsi.length < 8) {
    const secondarySet = new Set(secondary);
    for (const s of suggest) {
      const phrase = (s || '').toLowerCase().trim();
      if (!phrase || seen.has(phrase) || secondarySet.has(phrase)) continue;
      if (phrase.length < 4 || phrase.length > 80) continue;
      // Skip exact niche
      if (phrase === nicheLower) continue;
      seen.add(phrase);
      lsi.push(phrase);
      if (lsi.length >= 10) break;
    }
  }

  return {
    secondary,
    lsi,
    // Convenience: pre-joined comma-separated strings for the UI
    secondary_string: secondary.join(', '),
    lsi_string: lsi.join(', '),
  };
}

// ============================================================
// Topic builder + scoring
// ============================================================
function buildTopics(niche, suggest, datamuse, wiki, reddit) {
  const topics = [];
  const seen = new Set();

  // From Google Suggest — real searches
  suggest.forEach(s => {
    if (seen.has(s.toLowerCase())) return;
    seen.add(s.toLowerCase());
    topics.push({
      topic: titleCase(s),
      source: 'Google Suggest',
      intent: classifyIntent(s),
      difficulty: 'medium',
      score: scoreTopic(s, niche, 40),
      reason: 'Real search query — people actively type this into Google',
      url: `https://www.google.com/search?q=${encodeURIComponent(s)}`,
    });
  });

  // From Reddit — high-engagement questions
  reddit
    .filter(r => r.isQuestion && r.comments >= 5)
    .sort((a, b) => b.comments - a.comments)
    .slice(0, 10)
    .forEach(r => {
      const cleanTitle = r.title.replace(/^\w+:/, '').trim();
      if (seen.has(cleanTitle.toLowerCase())) return;
      seen.add(cleanTitle.toLowerCase());
      topics.push({
        topic: cleanTitle,
        source: `Reddit (r/${r.subreddit})`,
        intent: 'informational',
        difficulty: r.comments > 50 ? 'high-demand' : 'medium',
        score: scoreTopic(cleanTitle, niche, 30) + Math.min(r.comments / 5, 20),
        reason: `${r.comments} Reddit comments — proven audience demand`,
        url: r.url,
      });
    });

  // From Wikipedia — authoritative subtopics
  wiki
    .filter(w => w.title && w.title.toLowerCase() !== niche.toLowerCase())
    .slice(0, 8)
    .forEach(w => {
      if (seen.has(w.title.toLowerCase())) return;
      seen.add(w.title.toLowerCase());
      topics.push({
        topic: w.title,
        source: 'Wikipedia',
        intent: 'informational',
        difficulty: 'low',
        score: scoreTopic(w.title, niche, 25),
        reason: 'Authoritative subtopic — Wikipedia has an article on this',
        url: w.url,
      });
    });

  // From Datamuse — semantic clusters (combine with intent modifiers)
  const topDatamuse = datamuse.slice(0, 8);
  const intentPrefixes = ['Best', 'How to Choose', 'Top'];
  topDatamuse.forEach((d, i) => {
    if (!d.word || d.word.length < 4) return;
    const prefix = intentPrefixes[i % intentPrefixes.length];
    const topic = `${prefix} ${titleCase(d.word)}`;
    if (seen.has(topic.toLowerCase())) return;
    seen.add(topic.toLowerCase());
    topics.push({
      topic,
      source: 'Datamuse',
      intent: prefix === 'Best' || prefix === 'Top' ? 'commercial' : 'informational',
      difficulty: 'low',
      score: scoreTopic(topic, niche, 20) + (d.freq > 1 ? 10 : 0),
      reason: 'Semantically related to your niche',
      url: '',
    });
  });

  // Sort by score, return top 15
  return topics
    .sort((a, b) => b.score - a.score)
    .slice(0, 15);
}

function scoreTopic(topic, niche, baseScore) {
  let score = baseScore;
  // Bonus for containing the niche keyword
  if (topic.toLowerCase().includes(niche.toLowerCase())) score += 15;
  // Bonus for question format
  if (/\?$|^(how|why|what|when|where|which)/i.test(topic)) score += 10;
  // Bonus for "best/top" (commercial intent)
  if (/\b(best|top|review)\b/i.test(topic)) score += 8;
  // Bonus for year (current/timely)
  if (/202[5-9]/.test(topic)) score += 5;
  // Penalty for very long titles (over 70 chars)
  if (topic.length > 70) score -= 10;
  return score;
}

function classifyIntent(query) {
  const q = query.toLowerCase();
  if (/\b(buy|price|cost|cheap|deal|sale|discount|coupon|near me)\b/.test(q)) return 'transactional';
  if (/\b(best|top|vs|versus|review|comparison|alternative)\b/.test(q)) return 'commercial';
  if (/^(how|what|why|when|where|which|guide|tutorial)/.test(q)) return 'informational';
  return 'informational';
}

function titleCase(str) {
  return str.replace(/\w\S*/g, t => t.charAt(0).toUpperCase() + t.substring(1));
}
