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

const MG_KEY      = process.env.MAILGUN_API_KEY;
const MG_DOMAIN   = process.env.MAILGUN_DOMAIN;        // e.g. mg.seobetter.com or sandboxXXX.mailgun.org
const MG_REGION   = (process.env.MAILGUN_REGION || 'us').toLowerCase();  // 'us' | 'eu'
const EMAIL_TO    = process.env.EMAIL_TO   || 'mindiamaiweb@gmail.com';
const EMAIL_FROM  = process.env.EMAIL_FROM || `SEOBetter Bot <bot@${MG_DOMAIN || 'mailgun.org'}>`;
const OR_KEY        = process.env.OPENROUTER_API_KEY;
const MODEL         = process.env.MODEL          || 'google/gemini-3.1-flash-lite-preview';
// Hybrid routing — mentions / Loop 6 is the highest-leverage action (algo +75
// signal + 150× distribution per playbook §0). Worth using a smarter model for
// just those ~10% of runs. Falls back to MODEL if not set.
const MODEL_MENTIONS = process.env.MODEL_MENTIONS || MODEL;

// Optional residential proxy — recommended for VPS deployments since data-center
// IPs sometimes get search results suppressed. Format: full URL incl scheme.
// Example: PROXY_URL=http://proxy.iproyal.com:12321
const PROXY_URL  = process.env.PROXY_URL || '';
const PROXY_USER = process.env.PROXY_USER || '';
const PROXY_PASS = process.env.PROXY_PASS || '';

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

// Send a real email via Mailgun. Use sparingly — daily digest + critical alerts only.
// Per-tick chatter goes to digestAppend(), not here.
async function email(subject, body) {
  if (!MG_KEY || !MG_DOMAIN) { log('email skipped: missing MAILGUN_API_KEY or MAILGUN_DOMAIN'); return; }
  const host = MG_REGION === 'eu' ? 'api.eu.mailgun.net' : 'api.mailgun.net';
  const url = `https://${host}/v3/${MG_DOMAIN}/messages`;
  const auth = 'Basic ' + Buffer.from(`api:${MG_KEY}`).toString('base64');
  const form = new URLSearchParams({
    from: EMAIL_FROM,
    to: EMAIL_TO,
    subject,
    text: body,
  });
  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Authorization': auth,
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: form.toString(),
    });
    if (!res.ok) log(`email fail: ${res.status} ${await res.text()}`);
  } catch (e) { log(`email fail: ${e.message}`); }
}

// Append a one-line entry to today's digest file. Read by the daily metrics
// action and sent as ONE email at 7 PM ET. No per-tick email noise.
function digestAppend(line) {
  fs.mkdirSync(STATE_DIR, { recursive: true });
  const f = path.join(STATE_DIR, `digest-${todayKey()}.txt`);
  fs.appendFileSync(f, `[${new Date().toISOString().slice(11, 16)}] ${line}\n`);
}

function digestRead() {
  const f = path.join(STATE_DIR, `digest-${todayKey()}.txt`);
  return fs.existsSync(f) ? fs.readFileSync(f, 'utf8') : '(no actions logged today)';
}

async function gemini(systemPrompt, userPrompt, modelOverride) {
  const res = await fetch('https://openrouter.ai/api/v1/chat/completions', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${OR_KEY}`,
      'Content-Type': 'application/json',
      'HTTP-Referer': 'https://seobetter.com',
      'X-Title': 'SEOBetter Twitter Agent',
    },
    body: JSON.stringify({
      model: modelOverride || MODEL,
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
// Loosened in v1.0.1 — exact-phrase quotes had near-zero hit rate. X uses
// space as AND, so multi-word queries still narrow the topic without forcing
// verbatim phrasing. Dropped min_faves entirely; LLM scoring filters quality.
const ENGLISH_QUERIES = [
  'rank ChatGPT WordPress -is:retweet lang:en',
  'cited Perplexity SEO -is:retweet lang:en',
  'WordPress SEO plugin -is:retweet lang:en',
  'best AI SEO -is:retweet lang:en',
  'AI Overview traffic dropped -is:retweet lang:en',
  'switching Yoast -is:retweet lang:en',
  'RankMath alternative -is:retweet lang:en',
  'GEO optimization -is:retweet lang:en',
  'generative engine optimization -is:retweet lang:en',
  'rank AI search -is:retweet lang:en',
  'AI Overviews killed -is:retweet lang:en',
  'Yoast RankMath -is:retweet lang:en',
  'is SEO dead -is:retweet lang:en',
  'Perplexity citation -is:retweet lang:en',
  'schema markup WordPress -is:retweet lang:en',
  'AI search optimization -is:retweet lang:en',
  'AppSumo SEO -is:retweet lang:en',
  'ChatGPT search ranking -is:retweet lang:en',
  'AI Overview SEO -is:retweet lang:en',
  'WordPress AI plugin -is:retweet lang:en',
];

// Loosened in v1.0.1 — same reasoning as ENGLISH_QUERIES. Multi-word AND
// queries narrow the topic without forcing exact phrasing.
const MULTILINGUAL_QUERIES = [
  // Japanese
  { q: 'WordPress SEO プラグイン -is:retweet lang:ja', lang: 'ja' },
  { q: 'AI検索 対策 -is:retweet lang:ja',               lang: 'ja' },
  { q: 'Yoast 代替 -is:retweet lang:ja',                 lang: 'ja' },
  // Spanish
  { q: 'plugin SEO WordPress -is:retweet lang:es', lang: 'es' },
  { q: 'ChatGPT aparecer SEO -is:retweet lang:es',  lang: 'es' },
  { q: 'alternativa Yoast -is:retweet lang:es',     lang: 'es' },
  // Portuguese
  { q: 'plugin SEO WordPress -is:retweet lang:pt', lang: 'pt' },
  { q: 'ChatGPT aparecer SEO -is:retweet lang:pt',  lang: 'pt' },
  // German
  { q: 'WordPress SEO Plugin -is:retweet lang:de',   lang: 'de' },
  { q: 'ChatGPT erscheinen SEO -is:retweet lang:de', lang: 'de' },
  // French
  { q: 'plugin SEO WordPress -is:retweet lang:fr',     lang: 'fr' },
  { q: 'apparaître ChatGPT SEO -is:retweet lang:fr',   lang: 'fr' },
  // Chinese
  { q: 'WordPress SEO 插件 -is:retweet lang:zh', lang: 'zh' },
  { q: 'AI 搜索 优化 -is:retweet lang:zh',        lang: 'zh' },
  // Korean
  { q: '워드프레스 SEO 플러그인 -is:retweet lang:ko', lang: 'ko' },
  { q: 'AI 검색 최적화 -is:retweet lang:ko',          lang: 'ko' },
  // Italian
  { q: 'plugin SEO WordPress -is:retweet lang:it', lang: 'it' },
  { q: 'alternativa Yoast -is:retweet lang:it',     lang: 'it' },
  // Russian
  { q: 'плагин SEO WordPress -is:retweet lang:ru',  lang: 'ru' },
  { q: 'оптимизация ChatGPT -is:retweet lang:ru',   lang: 'ru' },
  // Dutch / Polish / Turkish
  { q: 'WordPress SEO plugin -is:retweet lang:nl',   lang: 'nl' },
  { q: 'wtyczka SEO WordPress -is:retweet lang:pl',  lang: 'pl' },
  { q: 'WordPress SEO eklentisi -is:retweet lang:tr', lang: 'tr' },
  // Indonesian / Vietnamese / Thai
  { q: 'plugin SEO WordPress -is:retweet lang:id', lang: 'id' },
  { q: 'plugin SEO WordPress -is:retweet lang:vi',  lang: 'vi' },
  { q: 'ปลั๊กอิน SEO WordPress -is:retweet lang:th', lang: 'th' },
  // Arabic / Hindi
  { q: 'ووردبريس SEO إضافة -is:retweet lang:ar', lang: 'ar' },
  { q: 'WordPress SEO प्लगइन -is:retweet lang:hi', lang: 'hi' },
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
  const launchOpts = {
    headless: true,
    args: [
      '--disable-blink-features=AutomationControlled',
      '--no-sandbox',
      '--disable-dev-shm-usage',
    ],
  };
  if (PROXY_URL) {
    launchOpts.proxy = {
      server: PROXY_URL,
      ...(PROXY_USER ? { username: PROXY_USER } : {}),
      ...(PROXY_PASS ? { password: PROXY_PASS } : {}),
    };
  }
  const browser = await chromium.launch(launchOpts);
  const context = await browser.newContext({
    storageState: STATE_FILE,
    viewport: { width: 1280, height: 800 },
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
    locale: 'en-US',
    timezoneId: 'America/New_York',
    // Required when going through a proxy that MITMs HTTPS to inject headers
    // (ScrapeOps, BrightData with super-proxy mode, etc.). Safe here because
    // the proxy is paid, scoped to our account, and only used for x.com /
    // openrouter / mailgun — all of which we trust + auth via cookies/keys.
    ignoreHTTPSErrors: true,
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
  // Why this got rewritten 2026-05-04:
  //
  // Pre-fix only checked for `/login` or `/i/flow/login` in the URL.
  // Twitter changed anti-automation behavior: logged-out sessions now
  // get silently redirected to `https://x.com/` (the public landing
  // page) with title "X. It's what's happening / X" — NOT `/login`.
  // Old check thought "URL doesn't contain /login → must be logged in"
  // and let the bot proceed. Subsequent actions all failed (search
  // returned 0 results, compose modal never opened) because actually
  // logged out. No `COOKIES_EXPIRED` was raised → no critical email →
  // user only saw the "0 actions today" digest with no actionable
  // signal for ~24h until they investigated manually.
  //
  // New check is a 2-gate test:
  //   Gate 1 (URL): after going to `/home`, the URL must STILL be
  //     `/home`. Anything else (`/`, `/login`, `/i/flow/login`,
  //     `/i/flow/signup`, etc.) = logged out.
  //   Gate 2 (DOM):  even on `/home`, verify the logged-in shell is
  //     present by waiting for the compose button (sidebar "Post"
  //     button — `a[data-testid="SideNav_NewTweet_Button"]`). This
  //     catches shadow-logouts where Twitter keeps the URL but
  //     strips the logged-in UI shell.
  //
  // Either gate failing throws COOKIES_EXPIRED, which the main loop
  // turns into a critical email. User finds out the same day, not 24h
  // later from a silent digest.
  await page.goto('https://x.com/home', { waitUntil: 'domcontentloaded', timeout: 30000 });
  await jitter(2000, 4000);
  const url = page.url();

  // Gate 1 — URL didn't stay on /home → Twitter redirected us out
  if (!/\/home(\?|#|$)/.test(url)) {
    throw new Error('COOKIES_EXPIRED');
  }

  // Gate 2 — verify a logged-in-only DOM element is present
  // (sidebar Post button is one of the most stable logged-in selectors).
  // 10s timeout is intentionally generous — slower VPS + ScrapeOps proxy
  // hop can take 5-7s to fully render the shell.
  try {
    await page.waitForSelector('a[data-testid="SideNav_NewTweet_Button"]', { timeout: 10000 });
  } catch {
    throw new Error('COOKIES_EXPIRED');
  }
}

async function typeHuman(page, text) {
  for (const ch of text) {
    await page.keyboard.type(ch, { delay: 25 + Math.random() * 60 });
  }
}

// More reliable than page.keyboard.type for X's contenteditable compose box —
// pressSequentially is bound to the locator so focus can't drift away.
async function typeIntoCompose(compose, text) {
  await compose.click();
  await compose.pressSequentially(text, { delay: 25 + Math.random() * 30 });
}

// Submit a reply by trying inline button → main button → Ctrl+Enter shortcut.
// Each X UI variant uses a different submit affordance; this hits all three.
async function submitReply(page) {
  const inline = page.locator('button[data-testid="tweetButtonInline"]:not([disabled])').first();
  const main   = page.locator('button[data-testid="tweetButton"]:not([disabled])').first();
  try { await inline.click({ timeout: 8000 }); return 'inline'; } catch {}
  try { await main.click({ timeout: 4000 }); return 'main'; } catch {}
  // Last resort — keyboard shortcut works on both Mac and Linux Chromium
  await page.keyboard.press('Control+Enter');
  await jitter(1500, 2500);
  return 'kbd';
}

// Dump diagnostic state for debugging — useful when a click fails on us
async function debugReplyFailure(page, context) {
  const ts = Date.now();
  const shotPath = `/tmp/replyfail-${ts}.png`;
  try { await page.screenshot({ path: shotPath, fullPage: false }); } catch {}
  const buttonStates = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('button[data-testid]'))
      .filter(b => /tweet|post|reply/i.test(b.getAttribute('data-testid') || ''))
      .map(b => ({
        testid: b.getAttribute('data-testid'),
        disabled: b.disabled || b.getAttribute('aria-disabled') === 'true',
        visible: b.offsetParent !== null,
      }));
  });
  return `\nscreenshot: ${shotPath}\nbuttons: ${JSON.stringify(buttonStates)}\ncontext: ${context}`;
}

// =============================================================================
// ACTIONS
// =============================================================================
async function actionPost(page, systemPrompt) {
  if (getDailyCount('post') >= CAPS.post) return `skipped: post cap (${CAPS.post}) reached`;

  // 2026-05-04 — added post dedup memory.
  //
  // Pre-fix: actionPost had NO memory of past posts. The userPrompt was
  // ~6 lines + the same 74KB agent-prompt.md as system context every
  // call. Gemini saw identical context every time and tended toward
  // similar outputs (especially pillar_1 which is 60% of posts —
  // recurring stats like "Perplexity cited Wikipedia 1.8M times").
  //
  // Fix: keep a rolling window of the last 30 successful posts on
  // disk. Inject the most recent 15 into the userPrompt as an explicit
  // anti-list ("DO NOT repeat any of these facts, stats, framings or
  // angles"). 30 stored / 15 in-prompt is the right tradeoff: enough
  // context to prevent obvious repetition without bloating the prompt
  // (each post averages ~250 chars, so 15 × 250 = 3.75KB extra context
  // — negligible vs the 74KB system prompt).
  const recentPosts = loadState('recent-posts', []);
  const antiList = recentPosts.slice(-15).map((p, i) => `${i + 1}. ${p}`).join('\n');

  const userPrompt = `MODE: original_post

CONTEXT:
- Today is ${new Date().toISOString().slice(0, 10)}.
- Pre-launch mode is active per §1A — never link to seobetter.com, never paste the form URL.
- Pick one content pillar by weighted random: 60% pillar_1 (ai_search_insights), 25% pillar_2 (plugin_tactics), 10% pillar_3 (build_in_public), 5% pillar_4 (spicy_take).
- Length: ≤ 270 characters. No links. No hashtags. Lead with a specific number, name, or fact.

ANTI-REPETITION (CRITICAL):
You have already posted these — DO NOT repeat any of these facts, stats, brand names, framings, or angles. Pick a DIFFERENT angle even within the same pillar:
${antiList || '(no prior posts yet — anything goes for this first one)'}

If your first draft uses any fact / stat / brand / framing already in the list above, discard it and try a fresh angle. Variety is more important than picking the "perfect" stat.

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

  // Persist this post into the rolling 30-window so future ticks see
  // it in the anti-list. Trim oldest entries beyond the 30 cap.
  recentPosts.push(tweet.text);
  while (recentPosts.length > 30) recentPosts.shift();
  saveState('recent-posts', recentPosts);

  return `✓ posted [${tweet.pillar || '?'}]: ${tweet.text.substring(0, 120)}`;
}

async function actionReplySearch(page, systemPrompt, useLang) {
  if (getDailyCount('reply') >= CAPS.reply) return `skipped: reply cap (${CAPS.reply}) reached`;

  const { query, lang } = nextQuery(useLang);
  await page.goto(`https://x.com/search?q=${encodeURIComponent(query)}&src=typed_query&f=live`, {
    waitUntil: 'domcontentloaded',
  });
  // X's React app takes 8-12s to populate search results. Wait for the first
  // tweet article to appear instead of blind-sleeping a fixed amount.
  try {
    await page.waitForSelector('article[data-testid="tweet"]', { timeout: 15000 });
    await jitter(1500, 3000); // small extra so all 10 results render, not just first
  } catch {
    // Genuine 0 results — bail out of the action cleanly.
    return `searched "${query.substring(0, 60)}" (${lang}): 0 results after 15s wait`;
  }

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
  await page.waitForSelector('article[data-testid="tweet"]', { timeout: 15000 });
  await jitter(2000, 4000);
  await page.locator('button[data-testid="reply"]').first().click();
  await page.waitForSelector('div[data-testid="tweetTextarea_0"]', { timeout: 10000 });
  await jitter(1500, 3000);
  const compose = page.locator('div[data-testid="tweetTextarea_0"]').first();
  await typeIntoCompose(compose, pick.text);
  await jitter(2000, 4000);
  let submitMethod;
  try {
    submitMethod = await submitReply(page);
  } catch (err) {
    throw new Error(err.message + await debugReplyFailure(page, `reply_${lang} to ${pick.in_reply_to}`));
  }
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
  // Wait for either tweets to render or for an "empty state" indicator.
  try {
    await Promise.race([
      page.waitForSelector('article[data-testid="tweet"]', { timeout: 15000 }),
      page.waitForSelector('[data-testid="emptyState"]', { timeout: 15000 }),
    ]);
    await jitter(1000, 2500);
  } catch {
    // Page didn't settle; carry on with whatever rendered.
  }

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

  // Hybrid routing: mentions get the smarter MODEL_MENTIONS (Loop 6 is the
  // highest-leverage action — algo +75 + 150× distribution multiplier).
  const raw  = await gemini(systemPrompt, userPrompt, MODEL_MENTIONS);
  const data = parseJson(raw);
  const reply = data.tweets?.[0];
  if (!reply?.text || ['skip', 'needs_human_review'].includes(data.next_action)) {
    replied[pick.url] = Date.now();
    saveState('replied-mentions', replied);
    return `skipped @${pick.handle}: ${data.next_action || 'no reply'}`;
  }

  await page.goto(pick.url, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('article[data-testid="tweet"]', { timeout: 15000 });
  await jitter(2000, 4000);
  await page.locator('button[data-testid="reply"]').first().click();
  await page.waitForSelector('div[data-testid="tweetTextarea_0"]', { timeout: 10000 });
  await jitter(1500, 3000);
  const compose = page.locator('div[data-testid="tweetTextarea_0"]').first();
  await typeIntoCompose(compose, reply.text);
  await jitter(2000, 4000);
  try {
    await submitReply(page);
  } catch (err) {
    throw new Error(err.message + await debugReplyFailure(page, `mention_reply to ${pick.url}`));
  }
  await jitter(3000, 5000);

  replied[pick.url] = Date.now();
  saveState('replied-mentions', replied);
  bumpDailyCount('mention_reply');
  return `✓ mention reply to @${pick.handle}: ${reply.text.substring(0, 120)}`;
}

async function actionLikes(page) {
  if (getDailyCount('like') >= CAPS.like) return 'skipped: like cap reached';

  const queries = [
    'GEO optimization', 'WordPress SEO', 'AI Overviews',
    'ChatGPT citation', 'Perplexity ranking', 'AI search SEO',
    'WordPress AI plugin', 'schema markup',
  ];
  const q = queries[Math.floor(Math.random() * queries.length)];
  await page.goto(`https://x.com/search?q=${encodeURIComponent(q)}&src=typed_query&f=live`, {
    waitUntil: 'domcontentloaded',
  });
  try {
    await page.waitForSelector('article[data-testid="tweet"]', { timeout: 15000 });
    await jitter(1500, 3000);
  } catch {
    return `searched "${q}": 0 results after 15s wait (no tweets to like)`;
  }

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

  const allCounts = loadState('daily-counts', {});
  const counts = allCounts[todayKey()] || {};

  // 7-day rollup — sum across the last 7 days of state files
  const weekly = { post: 0, reply: 0, mention_reply: 0, like: 0 };
  const lastSevenDates = [];
  for (let i = 6; i >= 0; i--) {
    const d = new Date(); d.setUTCDate(d.getUTCDate() - i);
    const k = d.toISOString().slice(0, 10);
    lastSevenDates.push(k);
    const day = allCounts[k] || {};
    weekly.post          += day.post          || 0;
    weekly.reply         += day.reply         || 0;
    weekly.mention_reply += day.mention_reply || 0;
    weekly.like          += day.like          || 0;
  }

  // Persist follower count for delta calculation
  const fhist = loadState('follower-history', {});
  fhist[todayKey()] = stats.followers;
  // GC: keep last 30 days
  Object.keys(fhist).sort().slice(0, -30).forEach(k => delete fhist[k]);
  saveState('follower-history', fhist);
  const sevenAgoFollowers = fhist[lastSevenDates[0]] || '?';

  // Per-day breakdown table
  const byDay = lastSevenDates.map(k => {
    const d = allCounts[k] || {};
    return `  ${k}  ${String(d.post||0).padStart(2)}p ${String(d.reply||0).padStart(3)}r ${String(d.mention_reply||0).padStart(3)}m ${String(d.like||0).padStart(3)}L`;
  }).join('\n');

  const summary = `Followers: ${stats.followers}  (7d ago: ${sevenAgoFollowers})
Following: ${stats.following}

TODAY  (${todayKey()})
  ${counts.post || 0} posts · ${counts.reply || 0} replies · ${counts.mention_reply || 0} mention replies · ${counts.like || 0} likes

LAST 7 DAYS  total
  ${weekly.post} posts · ${weekly.reply} replies · ${weekly.mention_reply} mention replies · ${weekly.like} likes

per day  (p=posts r=replies m=mention-replies L=likes)
${byDay}`;

  const body = `📊 SEOBetter — ${todayKey()}

${summary}

────── Today's actions ──────
${digestRead()}
────────────────────────────

Edit /opt/twitter-bot/agent-prompt.md over SSH to change voice / queries / pillars.
Tail /opt/twitter-bot/log.txt for live debugging.
Daily caps: post=${CAPS.post} reply=${CAPS.reply} mention=${CAPS.mention_reply} like=${CAPS.like}`;

  await email(`📊 SEOBetter — ${todayKey()}`, body);
  return `daily digest (with 7-day rollup) emailed to ${EMAIL_TO}`;
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
    await email('🚨 SEOBetter bot — agent-prompt.md missing', `Cron tick failed: ${PROMPT_FILE} not found on VPS.`);
    process.exit(1);
  }
  const systemPrompt = fs.readFileSync(PROMPT_FILE, 'utf8');

  let result;
  let browser;
  let critical = false;
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
      critical = true;
    } else {
      result = `❌ ${action} failed: ${err.message}`;
      // Email immediately if we've had 3+ failures in the last hour
      const recent = loadState('recent-fails', []).filter(t => Date.now() - t < 60 * 60 * 1000);
      recent.push(Date.now());
      saveState('recent-fails', recent);
      if (recent.length >= 3) critical = true;
    }
    log(err.stack || err.message);
  } finally {
    if (browser) await browser.close();
  }

  log(`result: ${result}`);
  digestAppend(`${action} → ${result}`);

  // Critical alerts get an immediate email; everything else waits for the daily digest.
  if (critical) {
    await email(`🚨 SEOBetter bot — ${action} alert`, `${result}\n\nVPS log tail:\n${tailLog(50)}`);
  }

  process.exit(0);
})();

function tailLog(n) {
  try {
    const lines = fs.readFileSync(LOG_FILE, 'utf8').split('\n');
    return lines.slice(-n).join('\n');
  } catch { return '(no log)'; }
}
