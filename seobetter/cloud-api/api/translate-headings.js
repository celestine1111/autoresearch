/**
 * SEOBetter Cloud API — Heading Language Enforcement (v1.5.212.2)
 *
 * POST /api/translate-headings
 *
 * Accepts a batch of headings + a target BCP-47 language code, returns the
 * same headings translated into that language. Used by PHP post-generation
 * to fix the "one English H2 leaked into a Japanese article" failure mode
 * — the AI generation prompt forbids English headings in non-English
 * articles (Async_Generator.php:2421 v1.5.206d-fix7) but model adherence
 * is statistical, not deterministic. This endpoint runs as a server-side
 * guarantee: any heading the model still ships in the wrong script gets
 * translated before the article saves.
 *
 * Universal — works for all 21 content types and all 29 languages defined
 * in LANG_NAMES. Single batched OpenRouter call regardless of heading count
 * (~$0.0002 per article that needs the fix; zero cost when all headings
 * obey the prompt rule).
 *
 * Fail-graceful: if translation errors, returns the original headings
 * unchanged so the caller leaves the article alone rather than blocking
 * the save. Phase 5 quality gate already warns on language drift.
 */

import { verifyRequest, rejectAuth, applyCorsHeaders, enforceRateLimit } from './_auth.js';

const LANG_NAMES = {
  en: 'English', ja: 'Japanese', zh: 'Chinese (Simplified)', ko: 'Korean',
  ru: 'Russian', de: 'German', fr: 'French', es: 'Spanish', it: 'Italian',
  pt: 'Portuguese', hi: 'Hindi', ar: 'Arabic', nl: 'Dutch', pl: 'Polish',
  tr: 'Turkish', sv: 'Swedish', da: 'Danish', no: 'Norwegian', fi: 'Finnish',
  cs: 'Czech', hu: 'Hungarian', ro: 'Romanian', el: 'Greek', uk: 'Ukrainian',
  vi: 'Vietnamese', th: 'Thai', id: 'Indonesian', ms: 'Malay', he: 'Hebrew',
};

export default async function handler(req, res) {
  applyCorsHeaders(req, res);
  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'Use POST.' });

  const auth = verifyRequest(req);
  if (!auth.ok) return rejectAuth(res, auth);

  const rlReject = await enforceRateLimit(req, res, 'translate-headings', auth);
  if (rlReject) return rlReject;

  const { headings, target_language } = req.body || {};
  if (!Array.isArray(headings) || headings.length === 0) {
    return res.status(400).json({ success: false, error: 'headings array is required.' });
  }
  if (!target_language || typeof target_language !== 'string') {
    return res.status(400).json({ success: false, error: 'target_language is required.' });
  }

  const baseLang = target_language.toLowerCase().slice(0, 2);
  const langName = LANG_NAMES[baseLang];
  if (!langName) {
    return res.status(400).json({ success: false, error: `Unsupported language: ${target_language}` });
  }

  // Cap batch size — guards against an attacker passing a 10MB payload to
  // burn OpenRouter quota. Real articles have 5–15 H2/H3.
  const trimmed = headings.slice(0, 30).map(h => String(h || '').slice(0, 300));

  const OPENROUTER_KEY = process.env.OPENROUTER_KEY;
  if (!OPENROUTER_KEY) {
    // Fail-open — return originals. Caller logs and leaves the article alone.
    return res.status(200).json({ success: true, translations: trimmed, source: 'no-key-passthrough' });
  }

  const numbered = trimmed.map((h, i) => `${i + 1}. ${h}`).join('\n');
  // v1.5.212.6 — Tightened prompt. Pre-fix the model interpreted English
  // SEO keywords inside 「」 / "" / '' quotes as proper nouns and preserved
  // them — leaving leaks like `なぜ「Best Slow Cooker Recipes for Winter
  // 2026」が日本で重要なのか` (Japanese article with English keyword in
  // quotes). Now the prompt explicitly instructs: translate quoted English
  // phrases too; only preserve genuine brand/product/person names.
  const userPrompt = `Translate each of the following headings into natural ${langName} as a native ${langName} writer would phrase them for a published article.

Rules:
- TRANSLATE every English word into ${langName}, including English phrases inside quotes (「」, "", ‘’, '', etc). SEO keywords are NOT proper nouns — translate them.
- ONLY preserve in their original form: genuine brand names (iPhone, Tesla, Toyota, BMW, Honda, Sony, Samsung), product model numbers (M3, A7 IV), company names (Google, Microsoft, OpenAI), well-known acronyms (CNN, SEO, AI, EU, NSW), and person names.
- A multi-word English search query like "best slow cooker recipes for winter 2026" is NOT a brand name — translate it fully into ${langName}.
- Keep numbered list ordering.

Headings:
${numbered}

Output a JSON object with one field "translations" — an array of ${trimmed.length} strings, each the ${langName} translation of the corresponding numbered heading above. Output the JSON object only, no markdown fences, no explanation.`;

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
          { role: 'system', content: `You translate article section headings into natural ${langName}. You preserve genuine brand/product/person names (iPhone, Tesla, Sony, CNN) but TRANSLATE everything else — including English phrases inside 「」 or "" or '' quotes. SEO search keywords are NOT proper nouns and must be translated. You output strict JSON only.` },
          { role: 'user', content: userPrompt },
        ],
        max_tokens: 1200,
        temperature: 0.0,
        response_format: { type: 'json_object' },
      }),
      signal: AbortSignal.timeout(15000),
    });

    if (!resp.ok) {
      const errText = await resp.text().catch(() => '');
      console.warn(`translate-headings: OpenRouter ${resp.status} — ${errText.slice(0, 200)}`);
      return res.status(200).json({ success: true, translations: trimmed, source: 'llm-error-passthrough' });
    }

    const data = await resp.json();
    let content = data?.choices?.[0]?.message?.content || '';
    if (!content) {
      return res.status(200).json({ success: true, translations: trimmed, source: 'empty-passthrough' });
    }
    content = content.replace(/^```(?:json)?\s*\n?/gm, '').replace(/\n?```\s*$/gm, '').trim();

    let parsed;
    try {
      parsed = JSON.parse(content);
    } catch {
      return res.status(200).json({ success: true, translations: trimmed, source: 'parse-error-passthrough' });
    }

    let arr = Array.isArray(parsed?.translations) ? parsed.translations
            : Array.isArray(parsed) ? parsed
            : null;
    if (!arr || arr.length === 0) {
      return res.status(200).json({ success: true, translations: trimmed, source: 'shape-error-passthrough' });
    }

    // Pad / truncate to match the input length so the caller can always
    // do an index-aligned replacement.
    const final = trimmed.map((orig, i) => {
      const t = typeof arr[i] === 'string' ? arr[i].trim() : '';
      // Strip leading "1. " / "1) " numbering the model occasionally re-adds.
      const stripped = t.replace(/^\s*\d+[.)、]\s*/, '').trim();
      return stripped && stripped.length <= 300 ? stripped : orig;
    });

    return res.status(200).json({
      success: true,
      translations: final,
      source: 'openrouter',
      target_language: baseLang,
    });
  } catch (err) {
    console.error('translate-headings exception (non-fatal):', err.message || err);
    return res.status(200).json({ success: true, translations: trimmed, source: 'exception-passthrough' });
  }
}
