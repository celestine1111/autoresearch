#!/usr/bin/env node
// /opt/twitter-bot/run.js
// SEOBetter Twitter agent — single-file, cron-driven, no Hermes.
// Usage:
//   node run.js                  # auto-pick action by time + weighted random
//   node run.js post             # force original post
//   node run.js reply_en         # force English reply search
//   node run.js reply_multi      # force multilingual reply search
//   node run.js mentions         # force own-mentions check
//   node run.js likes            # force like-batch
//   node run.js metrics          # force daily metrics report

require('dotenv').config({ path: __dirname + '/.env' });
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

// =============================================================================
// CONFIG
// =============================================================================
const ROOT        = __dirname;
const STATE_FILE  = path.join(ROOT, 'twitter-state.json');
const PROMPT_FILE = path.join(ROOT, 'agent-prompt.md');
const STATE_DIR   = path.join(ROOT, 'state');
const LOG_FILE    = path.join(ROOT, 'log.txt');

const TG_TOKEN = process.env.TELEGRAM_BOT_TOKEN;
const TG_CHAT  = process.env.TELEGRAM_CHAT_ID;
const OR_KEY   = process.env.OPENROUTER_API_KEY;
const MODEL    = process.env.MODEL || 'google/gemini-3-flash-lite-preview';

// Daily caps — per twitter-agent-prompt.md §9 daily limits
const CAPS = {
  post:          12,   // playbook says 8-15 originals/day
  reply:         100,  // covers Loop 2 + Loop 7
  mention_reply: 50,   // Loop 6 (own-tweet replies)
  like:          400,  // playbook says 500-1000 safe
};

// =============================================================================
// HELPERS
// =============================================================================
const log = (msg) => {
  const line = `[${new Date().toISOString()}] ${msg}\n`;
  fs.appendFileSync(LOG_FILE, line);
  console.log(line.trim());
};

const sleep  = (ms) => new Promise(r => setTimeout(r, ms));
const jitter = (min, max) => sleep(min + Math.random() * (max - min));

async function tg(msg) {
  if (!TG_TOKEN || !TG_CHAT) return;
  try {
    await fetch(`https://api.telegram.org/bot${TG_TOKEN}/sendMessage`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        chat_id: TG_CHAT,
        text: msg.substring(0, 4000),
        disable_web_page_preview: true,
      }),
    });
  } catch (e) { log(`tg fail: ${e.message}`); }
}

async function gemini(systemPrompt, userPrompt) {
  const res = await fetch('https://openrouter.ai/api/v1/chat/completions', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${OR_KEY}`,
      'Content-Type': 'application/json',
      'HTTP-Referer': 'https://seobetter.com',
      'X-Title': 'SEOBetter Twitter Agent',
    },
    body: JSON.stringify({
      model: MODEL,
      messages: [
        // Cache the system prompt — it's ~30K chars and identical across runs
        { role: 'system', content: [
          { type: 'text', text: systemPrompt, cache_control: { type: 'ephemeral' } },
        ]},
        { role: 'user', content: userPrompt },
      ],
      temperature: 0.85,
      max_tokens: 2000,
    }),
  });
  const data = await res.json();
  if (!data.choices?.[0]?.message?.content) {
    throw new Error('LLM no content: ' + JSON.stringify(data).substring(0, 300));
  }
  return data.choices[0].message.content;
}

function parseJson(text) {
  text = text.trim();
  try { return JSON.parse(text); } catch {}
  const fenced = text.match(/```(?:json)?\s*([\s\S]*?)```/);
  if (fenced) { try { return JSON.parse(fenced[1]); } catch {} }
  const m = text.match(/\{[\s\S]*\}/);
  if (m) { try { return JSON.parse(m[0]); } catch {} }
  throw new Error('Could not parse JSON from LLM: ' + text.substring(0, 200));
}

// =============================================================================
// STATE FILES (replied handles, daily counts, query rotation)
// =============================================================================
function loadState(name, defaultVal) {
  fs.mkdirSync(STATE_DIR, { recursive: true });
  const f = path.join(STATE_DIR, name + '.json');
  if (!fs.existsSync(f)) return defaultVal;
  try { return JSON.parse(fs.readFileSync(f)); } catch { return defaultVal; }
}
function saveState(name, val) {
  fs.mkdirSync(STATE_DIR, { recursive: true });
  fs.writeFileSync(path.join(STATE_DIR, name + '.json'), JSON.stringify(val, null, 2));
}
const todayKey = () => new Date().toISOString().slice(0, 10);

function bumpDailyCount(action) {
  const counts = loadState('daily-counts', {});
  const k = todayKey();
  if (!counts[k]) counts[k] = {};
  counts[k][action] = (counts[k][action] || 0) + 1;
  // GC: drop entries older than 14 days
  Object.keys(counts).filter(d => d < '2020').forEach(d => delete counts[d]);
  Object.keys(counts).sort().slice(0, -14).forEach(d => delete counts[d]);
  saveState('daily-counts', counts);
  return counts[k][action];
}
function getDailyCount(action) {
  const counts = loadState('daily-counts', {});
  return counts[todayKey()]?.[action] || 0;
}

// =============================================================================
// QUERY ROTATION (Loop 2 English + Loop 7 multilingual from §12.1, §12.3)
// =============================================================================
const ENGLISH_QUERIES = [
  '"how do I rank in ChatGPT" -is:retweet lang:en min_faves:1',
  '"how to get cited by Perplexity" -is:retweet lang:en min_faves:1',
  '"WordPress SEO plugin" "2026" -is:retweet lang:en',
  '"best AI SEO tool" -is:retweet lang:en min_faves:1',
  '"my traffic dropped" "AI Overview" -is:retweet lang:en',
  '"switching from Yoast" -is:retweet lang:en',
  '"alternative to RankMath" -is:retweet lang:en',
  '"GEO optimization" -is:retweet lang:en min_faves:2',
  '"generative engine optimization" -is:retweet lang:en',
  '"rank in AI search" -is:retweet lang:en',
  '"AI Overviews killed" -is:retweet lang:en min_faves:1',
  '"Yoast vs RankMath" -is:retweet lang:en',
  '"is SEO dead" -is:retweet lang:en',
  '"Perplexity citations" -is:retweet lang:en',
  '"schema markup WordPress" -is:retweet lang:en',
  '"AI search optimization" -is:retweet lang:en',
  '"AppSumo SEO" -is:retweet lang:en',
];

const MULTILINGUAL_QUERIES = [
  // Japanese
  { q: '"WordPress SEO" "プラグイン" -is:retweet lang:ja', lang: 'ja' },
  { q: '"AI検索" "対策" -is:retweet lang:ja',              lang: 'ja' },
  { q: '"Yoast" "代替" -is:retweet lang:ja',                lang: 'ja' },
  // Spanish
  { q: '"mejor plugin SEO" "WordPress" -is:retweet lang:es', lang: 'es' },
  { q: '"cómo aparecer" "ChatGPT" -is:retweet lang:es',      lang: 'es' },
  { q: '"alternativa a Yoast" -is:retweet lang:es',          lang: 'es' },
  // Portuguese
  { q: '"melhor plugin SEO" "WordPress" -is:retweet lang:pt', lang: 'pt' },
  { q: '"como aparecer" "ChatGPT" -is:retweet lang:pt',       lang: 'pt' },
  // German
  { q: '"WordPress SEO Plugin" "Empfehlung" -is:retweet lang:de', lang: 'de' },
  { q: '"in ChatGPT erscheinen" -is:retweet lang:de',             lang: 'de' },
  // French
  { q: '"meilleur plugin SEO" "WordPress" -is:retweet lang:fr', lang: 'fr' },
  { q: '"apparaître dans ChatGPT" -is:retweet lang:fr',         lang: 'fr' },
  // Chinese
  { q: '"WordPress SEO 插件" -is:retweet lang:zh', lang: 'zh' },
  { q: '"AI 搜索优化" -is:retweet lang:zh',         lang: 'zh' },
  // Korean
  { q: '"워드프레스 SEO 플러그인" -is:retweet lang:ko', lang: 'ko' },
  { q: '"AI 검색 최적화" -is:retweet lang:ko',           lang: 'ko' },
  // Italian
  { q: '"miglior plugin SEO" "WordPress" -is:retweet lang:it', lang: 'it' },
  { q: '"alternativa a Yoast" -is:retweet lang:it',            lang: 'it' },
  // Russian
  { q: '"плагин SEO WordPress" -is:retweet lang:ru',  lang: 'ru' },
  { q: '"оптимизация для ChatGPT" -is:retweet lang:ru', lang: 'ru' },
  // Dutch / Polish / Turkish
  { q: '"beste WordPress SEO plugin" -is:retweet lang:nl', lang: 'nl' },
  { q: '"najlepsza wtyczka SEO WordPress" -is:retweet lang:pl', lang: 'pl' },
  { q: '"en iyi WordPress SEO eklentisi" -is:retweet lang:tr', lang: 'tr' },
  // Indonesian / Vietnamese / Thai
  { q: '"plugin SEO WordPress terbaik" -is:retweet lang:id', lang: 'id' },
  { q: '"plugin SEO WordPress tốt nhất" -is:retweet lang:vi', lang: 'vi' },
  { q: '"ปลั๊กอิน SEO WordPress" -is:retweet lang:th', lang: 'th' },
  // Arabic / Hindi
  { q: '"إضافة سيو ووردبريس" -is:retweet lang:ar', lang: 'ar' },
  { q: '"WordPress SEO प्लगइन" -is:retweet lang:hi', lang: 'hi' },
];

function nextQuery(useLang) {
  const rot = loadState('rotation', { en: 0, multi: 0 });
  let q, lang;
  if (useLang === 'en') {
    q = ENGLISH_QUERIES[rot.en % ENGLISH_QUERIES.length];
    rot.en++;
    lang = 'en';
  } else {
    const item = MULTILINGUAL_QUERIES[rot.multi % MULTILINGUAL_QUERIES.length];
    q = item.q;
    lang = item.lang;
    rot.multi++;
  }
  saveState('rotation', rot);
  return { query: q, lang };
}

// =============================================================================
// BROWSER (Playwright + persistent storage_state + anti-detection)
// =============================================================================
async function launch() {
  if (!fs.existsSync(STATE_FILE)) {
    throw new Error(`Missing ${STATE_FILE} — build it from your Firefox auth_token + ct0 cookies.`);
  }
  const browser = await chromium.launch({
    headless: true,
    args: [
      '--disable-blink-features=AutomationControlled',
      '--no-sandbox',
      '--disable-dev-shm-usage',
    ],
  });
  const context = await browser.newContext({
    storageState: STATE_FILE,
    viewport: { width: 1280, height: 800 },
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
    locale: 'en-US',
    timezoneId: 'America/New_York',
  });
  // Hide webdriver flag (basic stealth)
  await context.addInitScript(() => {
    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
    Object.defineProperty(navigator, 'plugins',   { get: () => [1, 2, 3] });
    Object.defineProperty(navigator, 'languages', { get: () => ['en-US', 'en'] });
  });
  const page = await context.newPage();
  return { browser, context, page };
}

async function checkLoggedIn(page) {
  await page.goto('https://x.com/home', { waitUntil: 'domcontentloaded', timeout: 30000 });
  await jitter(2000, 4000);
  const url = page.url();
  if (url.includes('/login') || url.includes('/i/flow/login')) {
    throw new Error('COOKIES_EXPIRED');
  }
}

async function typeHuman(page, text) {
  for (const ch of text) {
    await page.keyboard.type(ch, { delay: 25 + Math.random() * 60 });
  }
}

// =============================================================================
// ACTIONS
// =============================================================================
async function actionPost(page, systemPrompt) {
  if (getDailyCount('post') >= CAPS.post) return `skipped: post cap (${CAPS.post}) reached`;

  const userPrompt = `MODE: original_post

CONTEXT:
- Today is ${new Date().toISOString().slice(0, 10)}.
- Pre-launch mode is active per §1A — never link to seobetter.com, never paste the form URL.
- Pick one content pillar by weighted random: 60% pillar_1 (ai_search_insights), 25% pillar_2 (plugin_tactics), 10% pillar_3 (build_in_public), 5% pillar_4 (spicy_take).
- Length: ≤ 270 characters. No links. No hashtags. Lead with a specific number, name, or fact.

Return ONLY the JSON per §4 schema. The "tweets" array contains exactly one entry.`;

  const raw  = await gemini(systemPrompt, userPrompt);
  const data = parseJson(raw);
  const tweet = data.tweets?.[0];
  if (!tweet?.text || data.next_action === 'needs_human_review' || data.next_action === 'skip') {
    return `skipped: ${data.next_action || 'no tweet generated'}`;
  }

  await page.goto('https://x.com/compose/post', { waitUntil: 'domcontentloaded' });
  await jitter(2000, 4000);
  const compose = page.locator('div[data-testid="tweetTextarea_0"]').first();
  await compose.click();
  await jitter(400, 1000);
  await typeHuman(page, tweet.text);
  await jitter(1500, 3000);
  await page.locator('button[data-testid="tweetButton"]').first().click();
  await jitter(3000, 5000);

  bumpDailyCount('post');
  return `✓ posted [${tweet.pillar || '?'}]: ${tweet.text.substring(0, 120)}`;
}

async function actionReplySearch(page, systemPrompt, useLang) {
  if (getDailyCount('reply') >= CAPS.reply) return `skipped: reply cap (${CAPS.reply}) reached`;

  const { query, lang } = nextQuery(useLang);
  await page.goto(`https://x.com/search?q=${encodeURIComponent(query)}&src=typed_query&f=live`, {
    waitUntil: 'domcontentloaded',
  });
  await jitter(3000, 6000);

  const tweets = await page.evaluate(() => {
    const articles = document.querySelectorAll('article[data-testid="tweet"]');
    const out = [];
    for (const a of Array.from(articles).slice(0, 10)) {
      const link    = a.querySelector('a[href*="/status/"]');
      const text    = (a.querySelector('div[data-testid="tweetText"]')?.innerText || '').substring(0, 500);
      const handleMatch = (a.querySelector('a[role="link"][href^="/"]')?.href || '').match(/x\.com\/([^\/?#]+)/);
      const handle  = handleMatch?.[1];
      const likeBtn = a.querySelector('button[data-testid="like"]');
      const likes   = parseInt(likeBtn?.innerText?.replace(/[^\d]/g, '') || '0') || 0;
      if (link && text && handle && handle !== 'home' && handle !== 'explore') {
        out.push({ url: link.href, text, handle, likes });
      }
    }
    return out;
  });

  if (!tweets.length) return `searched "${query.substring(0, 60)}": 0 results`;

  const replied = loadState('replied-handles', {});
  const SEVEN_DAYS = 7 * 24 * 60 * 60 * 1000;
  const fresh = tweets.filter(t =>
    t.handle.toLowerCase() !== 'seobetter3' &&
    (!replied[t.handle] || Date.now() - replied[t.handle] > SEVEN_DAYS)
  );
  if (!fresh.length) return `searched "${query.substring(0, 60)}": all ${tweets.length} from already-replied handles`;

  const userPrompt = `MODE: prospect_search

INPUT:
- Search language: ${lang}
- Query: ${query}
- Candidate tweets:
${JSON.stringify(fresh, null, 2)}

Score each tweet 1-10 per §5. For tweets scoring ≥7, write a reply IN ${lang.toUpperCase()} (the prospect's language) per §6 + §7 + §17.
Return ONLY the JSON per §4 schema. The "tweets" array has one entry per scored prospect, with "in_reply_to" set to that prospect's tweet URL.`;

  const raw  = await gemini(systemPrompt, userPrompt);
  const data = parseJson(raw);
  const candidates = (data.tweets || [])
    .filter(t => t.prospect_score >= 7 && t.text && t.in_reply_to)
    .sort((a, b) => b.prospect_score - a.prospect_score);
  if (!candidates.length) return `searched "${query.substring(0, 50)}" (${lang}, ${fresh.length} fresh): no score ≥7`;

  const pick = candidates[0];
  await page.goto(pick.in_reply_to, { waitUntil: 'domcontentloaded' });
  await jitter(3000, 5000);
  await page.locator('button[data-testid="reply"]').first().click();
  await jitter(1500, 3000);
  const compose = page.locator('div[data-testid="tweetTextarea_0"]').first();
  await compose.click();
  await typeHuman(page, pick.text);
  await jitter(2000, 4000);
  await page.locator('button[data-testid="tweetButton"]').first().click();
  await jitter(3000, 5000);

  const handle = pick.in_reply_to.match(/x\.com\/([^\/?#]+)/)?.[1];
  if (handle) {
    replied[handle] = Date.now();
    saveState('replied-handles', replied);
  }
  bumpDailyCount('reply');
  return `✓ reply [${lang}, score ${pick.prospect_score}] to @${handle}: ${pick.text.substring(0, 120)}`;
}

async function actionMentions(page, systemPrompt) {
  if (getDailyCount('mention_reply') >= CAPS.mention_reply) return 'skipped: mention cap reached';

  await page.goto('https://x.com/notifications/mentions', { waitUntil: 'domcontentloaded' });
  await jitter(3000, 5000);

  const mentions = await page.evaluate(() => {
    const articles = document.querySelectorAll('article[data-testid="tweet"]');
    const out = [];
    for (const a of Array.from(articles).slice(0, 5)) {
      const link    = a.querySelector('a[href*="/status/"]');
      const text    = (a.querySelector('div[data-testid="tweetText"]')?.innerText || '').substring(0, 500);
      const handle  = (a.querySelector('a[role="link"][href^="/"]')?.href || '').match(/x\.com\/([^\/?#]+)/)?.[1];
      if (link && text && handle && handle !== 'seobetter3') {
        out.push({ url: link.href, text, handle });
      }
    }
    return out;
  });

  if (!mentions.length) return 'no new mentions';
  const replied = loadState('replied-mentions', {});
  const fresh = mentions.filter(m => !replied[m.url]);
  if (!fresh.length) return `${mentions.length} mentions, all already responded`;

  const pick = fresh[0];
  const userPrompt = `MODE: conversation

INPUT:
- @${pick.handle} mentioned @seobetter3 or replied to one of our tweets
- Their text: ${pick.text}
- Tweet URL: ${pick.url}

Per Loop 6 + §6: substantive replies get a thoughtful response in their language; short agreement / spam / hostile gets next_action: skip.
Return ONLY JSON per §4 schema.`;

  const raw  = await gemini(systemPrompt, userPrompt);
  const data = parseJson(raw);
  const reply = data.tweets?.[0];
  if (!reply?.text || ['skip', 'needs_human_review'].includes(data.next_action)) {
    replied[pick.url] = Date.now();
    saveState('replied-mentions', replied);
    return `skipped @${pick.handle}: ${data.next_action || 'no reply'}`;
  }

  await page.goto(pick.url, { waitUntil: 'domcontentloaded' });
  await jitter(3000, 5000);
  await page.locator('button[data-testid="reply"]').first().click();
  await jitter(1500, 3000);
  const compose = page.locator('div[data-testid="tweetTextarea_0"]').first();
  await compose.click();
  await typeHuman(page, reply.text);
  await jitter(2000, 4000);
  await page.locator('button[data-testid="tweetButton"]').first().click();
  await jitter(3000, 5000);

  replied[pick.url] = Date.now();
  saveState('replied-mentions', replied);
  bumpDailyCount('mention_reply');
  return `✓ mention reply to @${pick.handle}: ${reply.text.substring(0, 120)}`;
}

async function actionLikes(page) {
  if (getDailyCount('like') >= CAPS.like) return 'skipped: like cap reached';

  const queries = [
    '"GEO optimization"', '"WordPress SEO"', '"AI Overviews"',
    '"ChatGPT citation"', '"Perplexity ranking"', '"AI search"',
  ];
  const q = queries[Math.floor(Math.random() * queries.length)];
  await page.goto(`https://x.com/search?q=${encodeURIComponent(q)}&src=typed_query&f=live`, {
    waitUntil: 'domcontentloaded',
  });
  await jitter(3000, 5000);

  const liked = await page.evaluate(async () => {
    const articles = Array.from(document.querySelectorAll('article[data-testid="tweet"]')).slice(0, 6);
    let count = 0;
    for (const a of articles) {
      const btn = a.querySelector('button[data-testid="like"]');
      if (btn && btn.getAttribute('aria-label')?.match(/^Like/i)) {
        btn.click();
        count++;
        await new Promise(r => setTimeout(r, 700 + Math.random() * 1400));
      }
    }
    return count;
  });

  for (let i = 0; i < liked; i++) bumpDailyCount('like');
  return `✓ liked ${liked} tweets matching ${q}`;
}

async function actionMetrics(page) {
  await page.goto('https://x.com/seobetter3', { waitUntil: 'domcontentloaded' });
  await jitter(3000, 5000);

  const stats = await page.evaluate(() => {
    const find = (sel) => document.querySelector(sel)?.innerText?.split('\n')[0] || '?';
    return {
      followers: find('a[href$="/verified_followers"]') || find('a[href*="/followers"]'),
      following: find('a[href$="/following"]'),
    };
  });

  const counts = loadState('daily-counts', {})[todayKey()] || {};
  return `📊 ${todayKey()} report
Followers: ${stats.followers} | Following: ${stats.following}
Today: ${counts.post || 0} posts · ${counts.reply || 0} replies · ${counts.mention_reply || 0} mention replies · ${counts.like || 0} likes`;
}

// =============================================================================
// ACTION PICKER
// =============================================================================
function pickAction() {
  const arg = process.argv[2];
  if (arg && arg !== 'auto') return arg;

  const now = new Date();
  const hourUTC = now.getUTCHours();
  // Sleep window: 05:00–12:00 UTC (1 AM – 8 AM ET) — skip
  if (hourUTC >= 5 && hourUTC < 12) return 'sleep';

  // Daily metrics push at 00:00–01:00 UTC (~7-8 PM ET)
  if (hourUTC === 0 && getDailyCount('metrics') === 0) return 'metrics';

  // Weighted random — bias HEAVY toward replies (user request: more search + replies)
  const r = Math.random();
  if (r < 0.45) return 'reply_en';      // 45%
  if (r < 0.80) return 'reply_multi';   // 35%
  if (r < 0.90) return 'mentions';      // 10%
  if (r < 0.97) return 'likes';         //  7%
  return 'post';                         //  3%
}

// =============================================================================
// MAIN
// =============================================================================
(async () => {
  const action = pickAction();
  log(`tick: action=${action}`);

  if (action === 'sleep') {
    log('sleep window, skipping');
    process.exit(0);
  }

  if (!fs.existsSync(PROMPT_FILE)) {
    await tg(`❌ ${PROMPT_FILE} missing`);
    process.exit(1);
  }
  const systemPrompt = fs.readFileSync(PROMPT_FILE, 'utf8');

  let result;
  let browser;
  try {
    const launched = await launch();
    browser = launched.browser;
    const page = launched.page;

    await checkLoggedIn(page);

    if      (action === 'post')         result = await actionPost(page, systemPrompt);
    else if (action === 'reply_en')     result = await actionReplySearch(page, systemPrompt, 'en');
    else if (action === 'reply_multi')  result = await actionReplySearch(page, systemPrompt, 'multi');
    else if (action === 'mentions')     result = await actionMentions(page, systemPrompt);
    else if (action === 'likes')        result = await actionLikes(page);
    else if (action === 'metrics')    { result = await actionMetrics(page); bumpDailyCount('metrics'); }
    else                                result = `unknown action: ${action}`;
  } catch (err) {
    if (err.message === 'COOKIES_EXPIRED') {
      result = '🚨 COOKIES EXPIRED — refresh twitter-state.json from Firefox auth_token + ct0';
    } else {
      result = `❌ ${action} failed: ${err.message}`;
    }
    log(err.stack || err.message);
  } finally {
    if (browser) await browser.close();
  }

  log(`result: ${result}`);
  await tg(`[${new Date().toISOString().slice(11, 16)} UTC · ${action}] ${result}`);
  process.exit(0);
})();
