# SEOBetter Twitter bot — VPS setup

Single-file cron-driven Twitter agent for `@seobetter3`. No Hermes. ~500 lines, one `run.js`.

**Time to deploy: ~60 minutes from a fresh Hostinger Ubuntu VPS.**

---

## What this bot does

Per cron tick (every 8 minutes by default), picks ONE action and reports to Telegram:

| Action | Frequency | What it does |
|---|---|---|
| `reply_en`    | 45% of ticks | Searches an English buying-intent query, scores results, replies to the best with prospect_score ≥7 |
| `reply_multi` | 35% of ticks | Same as above but rotates through 30+ multilingual queries (ja, es, pt, de, fr, zh, ko, it, ru, nl, pl, tr, id, vi, th, ar, hi) |
| `mentions`    | 10% of ticks | Checks `/notifications/mentions`, replies to substantive comments on your tweets (Loop 6 — algo's +75 signal) |
| `likes`       |  7% of ticks | Likes 5-6 tweets matching a niche query — cheap visibility boost |
| `post`        |  3% of ticks | Original post, pillar-rotated 60/25/10/5 per playbook |
| `metrics`     | once daily   | Telegram report: follower count + today's actions |
| `sleep`       | 1 AM – 8 AM ET | No actions during human sleep window |

Daily caps enforced: 12 posts, 100 replies, 50 mention replies, 400 likes — well below Twitter's documented soft thresholds.

The brain is `agent-prompt.md` (your existing 922-line playbook). Edit that file to change behavior — the bot reloads it every run.

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

---

## STEP 3 — Build the persistent cookie file

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

When these cookies expire (~30 days), repeat this step. **Never paste tokens into Telegram or chat anymore — only into this file.**

---

## STEP 4 — Telegram bot for status pings

In Telegram on your phone:

1. Search `@BotFather` → `/newbot` → name `seobetter-status` → save the **token**
2. Send any message ("hi") to your new bot
3. From the VPS:
   ```bash
   curl "https://api.telegram.org/bot<YOUR_TOKEN>/getUpdates"
   ```
   Look for `"chat":{"id": 12345...` — that's your **chat ID**.

Create `/opt/twitter-bot/.env`:

```bash
nano /opt/twitter-bot/.env
```

Paste (with your real values):

```
TELEGRAM_BOT_TOKEN=7234567890:AAH...
TELEGRAM_CHAT_ID=12345678
OPENROUTER_API_KEY=sk-or-v1-...
MODEL=google/gemini-3-flash-lite-preview
```

```bash
chmod 600 /opt/twitter-bot/.env
```

---

## STEP 5 — Smoke test

Run each action once, verify it works, before turning on the cron.

```bash
cd /opt/twitter-bot
node run.js metrics       # should print follower count + 0 actions today + Telegram ping
```

If you see a `📊 Daily report` Telegram message land — login worked. Continue:

```bash
node run.js likes         # should like 5-6 tweets in a niche query, Telegram pings result
node run.js reply_en      # should pick an English prospect, reply, Telegram pings
node run.js reply_multi   # should pick a non-English prospect, reply in their language
node run.js mentions      # checks notifications, replies if any new
node run.js post          # posts ONE original tweet
```

Watch your Twitter profile after each — the action should be visible.

If anything fails, check the log:

```bash
tail -50 /opt/twitter-bot/log.txt
```

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

Three useful commands:

```bash
tail -f /opt/twitter-bot/log.txt          # live action stream
ls -la /opt/twitter-bot/state/            # state files: replied handles, daily counts, query rotation
cat /opt/twitter-bot/state/daily-counts.json    # actions taken today
```

Watch your Telegram chat for the first hour. You should see ~8 status pings (every cron tick), most being `reply_en` or `reply_multi`.

By end of day 1, expected counters: 30-50 replies, 4-6 likes batches, 1-2 posts, 1 metrics report.

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

**Monthly:** refresh `twitter-state.json` when cookies expire. The bot will Telegram-ping you "🚨 COOKIES EXPIRED" automatically when this happens.

**Weekly:** glance at `daily-counts.json` to make sure you're not hitting daily caps.

**As needed:** if Twitter changes a UI selector and a button stops working, the Telegram error message will tell you which selector failed. Update in `run.js`, redeploy.

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
