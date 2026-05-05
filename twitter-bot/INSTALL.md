# SEOBetter Twitter bot — VPS setup

Single-file cron-driven Twitter agent for `@seobetter3`. No Hermes. ~500 lines, one `run.js`.

**Time to deploy: ~60 minutes from a fresh Hostinger Ubuntu VPS.**

---

## What this bot does

Per cron tick (every 8 minutes), picks ONE action and silently appends to today's digest file. **No Telegram. No per-tick email noise.** Email only fires for: (a) once-a-day digest at 7 PM ET, (b) cookie expiry, (c) 3+ consecutive errors in an hour.

| Action | Frequency | What it does |
|---|---|---|
| `reply_en`    | 45% of ticks | Searches an English buying-intent query, scores results, replies to the best with prospect_score ≥7 |
| `reply_multi` | 35% of ticks | Same as above but rotates through 30+ multilingual queries (ja, es, pt, de, fr, zh, ko, it, ru, nl, pl, tr, id, vi, th, ar, hi) |
| `mentions`    | 10% of ticks | Checks `/notifications/mentions`, replies to substantive comments on your tweets (Loop 6 — algo's +75 signal) |
| `likes`       |  7% of ticks | Likes 5-6 tweets matching a niche query — cheap visibility boost |
| `post`        |  3% of ticks | Original post, pillar-rotated 60/25/10/5 per playbook |
| `metrics`     | once daily   | Builds the daily digest from the day's per-tick log and emails it via Mailgun |
| `sleep`       | 1 AM – 8 AM ET | No actions during human sleep window |

Daily caps enforced: 12 posts, 100 replies, 50 mention replies, 400 likes — well below Twitter's documented soft thresholds.

The brain is `agent-prompt.md` (your existing 922-line playbook). Edit that file to change behavior — the bot reloads it every run.

To control the bot WITHOUT Telegram: SSH into the VPS, edit `agent-prompt.md`, save. The next cron tick (≤8 min later) picks up the change.

---

## STEP 1 — Provision the VPS environment

SSH into your Hostinger box:

```bash
ssh root@YOUR.VPS.IP
```

Install Node 20 LTS + Playwright + Chromium with all OS deps:

```bash
apt update && apt upgrade -y
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs git
mkdir -p /opt/twitter-bot && cd /opt/twitter-bot
```

Verify Node:

```bash
node --version   # should print v20.x
```

---

## STEP 2 — Drop the bot files into place

Two options:

### Option A — Clone from your repo

```bash
cd /opt
git clone https://github.com/celestine1111/autoresearch.git
cp -r autoresearch/twitter-bot/* /opt/twitter-bot/
cd /opt/twitter-bot
```

### Option B — scp from your Mac

From your Mac:

```bash
scp -r /Users/ben/Documents/autoresearch/twitter-bot/* root@YOUR.VPS.IP:/opt/twitter-bot/
```

Then on the VPS:

```bash
cd /opt/twitter-bot
```

Either way, install dependencies:

```bash
npm install
npx playwright install --with-deps chromium
```

`--with-deps` is critical — it `apt install`s libnss3, libgbm, libasound, etc. Without it, browser launch fails.

Verify Chromium runs headless:

```bash
node -e "const {chromium} = require('playwright'); chromium.launch().then(b => { console.log('OK'); b.close(); })"
```

Should print `OK`.

**v2026-05-05 update — also install Firefox** (the bot now defaults to Firefox so its fingerprint matches the Firefox where you extract cookies):
```bash
cd /opt/twitter-bot
npx playwright install firefox
node -e "const {firefox} = require('playwright'); firefox.launch().then(b => { console.log('OK'); b.close(); })"
```

Should also print `OK`. If it fails with missing system libs, run `npx playwright install-deps firefox` first.

---

## STEP 3 — Build the persistent cookie file

> **Why cookies expire in hours instead of 30 days, and how to fix it (v2026-05-05):**
>
> A Twitter `auth_token` is normally valid for ~30 days, but Twitter invalidates it the moment the *fingerprint* of the requests doesn't match the fingerprint of the browser that issued the cookie. Three signals matter:
>
> 1. **Browser engine** — cookies issued by Firefox die when replayed by Chromium (and vice versa). The bot now defaults to `BROWSER_TYPE=firefox` to match the Firefox extract flow.
> 2. **User-Agent string** — must match the OS + browser version of the source. The default `USER_AGENT` env value is a recent Firefox/macOS UA. If you grab cookies from Firefox/Windows or Firefox/Linux, set `USER_AGENT` in `.env` to YOUR exact UA (run `navigator.userAgent` in the source browser's DevTools → Console).
> 3. **IP geolocation** — cookies issued from your home IP (e.g. UK) replayed from a US/EU VPS look like session theft. Fix with a residential proxy in your country (see Step 5 ScrapeOps setup) — same country at minimum, same city ideal.
>
> Get all three signals matching and you'll see the full ~30-day lifetime. Get one wrong and the bot will work for hours then die.

Create `/opt/twitter-bot/twitter-state.json`:

```bash
nano /opt/twitter-bot/twitter-state.json
```

Paste this template, replacing the two `PASTE_…` strings with your actual tokens from Firefox DevTools → Storage → Cookies → `https://x.com`:

```json
{
  "cookies": [
    {
      "name": "auth_token",
      "value": "PASTE_AUTH_TOKEN_HERE",
      "domain": ".x.com",
      "path": "/",
      "expires": 1799999999,
      "httpOnly": true,
      "secure": true,
      "sameSite": "None"
    },
    {
      "name": "ct0",
      "value": "PASTE_CT0_HERE",
      "domain": ".x.com",
      "path": "/",
      "expires": 1799999999,
      "httpOnly": false,
      "secure": true,
      "sameSite": "Lax"
    }
  ],
  "origins": []
}
```

Save (Ctrl+O, Enter, Ctrl+X) and lock it:

```bash
chmod 600 /opt/twitter-bot/twitter-state.json
```

When these cookies expire (~30 days), repeat this step. The bot will email you "🚨 COOKIES EXPIRED" automatically when this happens. **Never paste tokens into chat anymore — only into this file.**

---

## STEP 4 — Mailgun for daily digest emails

You already have Mailgun. We just need three values from your dashboard.

1. Log in to **mailgun.com** → Sending → **Domains**. Pick (or note) the domain you want to send from. Two paths:
   - **Production domain** like `mg.seobetter.com` (recommended — better deliverability, but requires DNS records)
   - **Sandbox domain** like `sandboxXYZ.mailgun.org` (works immediately, but only sends to "authorized recipients" — you must add `mindiamaiweb@gmail.com` to that list under Sandbox → Authorized Recipients)
2. Go to **Sending → Sending API keys** (or Account → API Keys depending on UI version) → copy the **Private API key** (starts with `key-…` or just a long hex string)
3. Note your **region** — `us` (default) or `eu`. Look at your dashboard URL: `app.mailgun.com` = US, `app.eu.mailgun.com` = EU.

Create `/opt/twitter-bot/.env`:

```bash
nano /opt/twitter-bot/.env
```

Paste (with your real values):

```
MAILGUN_API_KEY=key-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
MAILGUN_DOMAIN=mg.seobetter.com
MAILGUN_REGION=us
EMAIL_TO=mindiamaiweb@gmail.com
EMAIL_FROM=SEOBetter Bot <bot@mg.seobetter.com>

OPENROUTER_API_KEY=sk-or-v1-...

# Hybrid model routing — cheap model for the 90% of runs that are
# search-and-reply, smart model for the 10% that are Loop 6 mentions
# (highest-leverage action: algo +75, 150× distribution multiplier)
MODEL=google/gemini-3.1-flash-lite-preview
MODEL_MENTIONS=google/gemini-3.1-pro-preview

# Browser fingerprint — defaults match Firefox/macOS. Override if your
# cookie-source browser is different. See Step 3 for why this matters.
# BROWSER_TYPE=firefox
# USER_AGENT=Mozilla/5.0 (Macintosh; Intel Mac OS X 14.15; rv:131.0) Gecko/20100101 Firefox/131.0
# LOCALE=en-GB
# TIMEZONE=Europe/London

# Residential proxy — STRONGLY RECOMMENDED for cookie longevity.
# Without it, cookies issued from your home IP and replayed from a
# data-center VPS IP get flagged as session theft within hours.
#
# ScrapeOps Residential Proxy with sticky session (use this — sticky
# session is REQUIRED for x.com because every request needs the SAME
# IP for the cookie's lifetime). Set country= to match where YOU browse
# from (where you grabbed the cookies):
# PROXY_URL=http://residential-proxy.scrapeops.io:8181
# PROXY_USER=scrapeops.country=uk.keep_session_id=seobetter1
# PROXY_PASS=YOUR_SCRAPEOPS_API_KEY
#
# IPRoyal / BrightData / Soax / Smartproxy alternatives:
# PROXY_URL=http://proxy.iproyal.com:12321
# PROXY_USER=your_username
# PROXY_PASS=your_password_country-uk
```

**ScrapeOps setup notes** (since you have an account):
- Use the **Residential Proxy** product, not the Proxy Aggregator API. The bot needs a sticky session — the Aggregator's per-request rotation breaks Twitter cookies.
- The `keep_session_id=seobetter1` parameter pins requests to one IP for the lifetime of that session ID. Use a unique value per bot (e.g. `seobetter1`, `seobetter2`).
- Sessions normally hold the same IP for ~30 minutes by default. For longer-lived pinning (matches our 30-day cookie lifetime), check ScrapeOps dashboard → Residential Proxy → set "Session Duration" to maximum (typically 30 min, but the bot will fall back to a fresh IP within the same country which Twitter usually accepts).
- `country=uk` (or `us`, `au`, etc.) MUST match where you grab the cookies. Cookies extracted from a UK IP and replayed from a US-pinned proxy = same theft signal.
- Their dashboard shows a usage meter — at 8-min ticks the bot makes ~8000 requests/month, well within their entry plan.

**Estimated monthly cost** at the default cron cadence (`*/8 * * * *`):
- All Flash Lite (skip MODEL_MENTIONS) → ~$3.50/mo
- **Hybrid (default above) → ~$10/mo**
- All Flash → ~$18/mo
- All Pro → ~$72/mo

```bash
chmod 600 /opt/twitter-bot/.env
```

**Test the email plumbing right now** before going further:

```bash
cd /opt/twitter-bot && node -e "
require('dotenv').config();
const f = new URLSearchParams({
  from: process.env.EMAIL_FROM,
  to: process.env.EMAIL_TO,
  subject: 'SEOBetter bot — Mailgun test',
  text: 'If you see this, Mailgun is wired up correctly.',
});
const host = (process.env.MAILGUN_REGION||'us')==='eu'?'api.eu.mailgun.net':'api.mailgun.net';
fetch(\`https://\${host}/v3/\${process.env.MAILGUN_DOMAIN}/messages\`, {
  method: 'POST',
  headers: {
    Authorization: 'Basic ' + Buffer.from('api:'+process.env.MAILGUN_API_KEY).toString('base64'),
    'Content-Type': 'application/x-www-form-urlencoded',
  },
  body: f.toString(),
}).then(r => r.text()).then(t => console.log('mailgun says:', t));
"
```

If you see `{"id":"<...@mg.seobetter.com>","message":"Queued. Thank you."}` — the email is on its way to your inbox. If you see `Forbidden` or `Unauthorized` → API key is wrong. If you see `not allowed` → it's a sandbox domain and `mindiamaiweb@gmail.com` isn't in the Authorized Recipients list yet.

---

## STEP 5 — Smoke test

Run each action once, verify it works, before turning on the cron.

```bash
cd /opt/twitter-bot
node run.js metrics       # builds digest + emails it (digest will be empty on first run)
```

If you receive a `📊 SEOBetter daily — 2026-05-03` email at mindiamaiweb@gmail.com — login + email both work. Continue:

```bash
node run.js likes         # should like 5-6 niche tweets
node run.js reply_en      # picks an English prospect, replies
node run.js reply_multi   # picks a non-English prospect, replies in their language
node run.js mentions      # checks notifications, replies if any new
node run.js post          # posts ONE original tweet
```

Watch your Twitter profile after each — the action should be visible. Each smoke-test action also writes a line to today's digest file (which the next `node run.js metrics` will email to you).

If anything fails, two places to look:

```bash
tail -50 /opt/twitter-bot/log.txt              # general log
cat /opt/twitter-bot/state/digest-$(date -u +%Y-%m-%d).txt    # today's per-tick activity
```

Critical errors (cookies expired, 3+ failures in an hour) email you immediately — no need to monitor the log unless you're debugging.

---

## STEP 6 — Install the cron schedule

```bash
crontab -e
```

Paste at the bottom:

```cron
*/8 * * * * cd /opt/twitter-bot && /usr/bin/node run.js >> /opt/twitter-bot/log.txt 2>&1
```

That fires every 8 minutes (180 ticks/day, ~120 actually act since the sleep window skips 7 hours).

Save + exit. Cron picks it up immediately.

Verify cron is running:

```bash
systemctl status cron     # should be active (running)
crontab -l                # should show your entry
```

---

## STEP 7 — Watch it work

Three useful commands (run from the VPS shell):

```bash
tail -f /opt/twitter-bot/log.txt                            # live action stream
cat /opt/twitter-bot/state/digest-$(date -u +%Y-%m-%d).txt  # today's per-tick activity
cat /opt/twitter-bot/state/daily-counts.json                # action counts
```

For the first hour, refresh your @seobetter3 profile and watch tweets / replies appear. The first **email** lands at 7 PM ET (00:00 UTC) — the daily digest.

By end of day 1, expected counters: 30-50 replies, 4-6 likes batches, 1-2 posts, 1 daily-digest email.

---

## Editing behavior

To change WHAT the bot says: edit `agent-prompt.md` — that's the system prompt the LLM sees every run.

To change HOW OFTEN it does each action: edit the weights in `pickAction()` near the bottom of `run.js`:

```js
if (r < 0.45) return 'reply_en';     // currently 45%
if (r < 0.80) return 'reply_multi';  // currently 35%
// ...
```

To change the cron cadence: `crontab -e` → modify the `*/8` to `*/5` (every 5 min — more aggressive) or `*/15` (every 15 min — slower).

---

## Maintenance

**Monthly:** refresh `twitter-state.json` when cookies expire. The bot emails you "🚨 COOKIES EXPIRED" automatically when this happens.

**Weekly:** glance at the daily digest emails to make sure replies are landing in the right languages and the prospect scoring looks reasonable.

**As needed:** if Twitter changes a UI selector and a button stops working, the bot emails an error after 3 consecutive failures in an hour. The email contains the last 50 log lines so you can see which selector broke. Update in `run.js`, redeploy.

---

## Killing the bot

```bash
crontab -e        # delete the line, save, exit. Bot stops within 8 minutes.
```

Or temporarily, just rename:

```bash
mv /opt/twitter-bot/.env /opt/twitter-bot/.env.disabled
```

Cron will keep firing but every run will fail-fast on missing API key.
