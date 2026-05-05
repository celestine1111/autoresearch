#!/bin/bash
# Twitter cookie refresh helper for /opt/twitter-bot/twitter-state.json
#
# Why this exists: ScrapeOps's residential sticky-session rotates within AU
# every 10-30 min, which kills x.com cookies after a few hours instead of
# the normal ~30 days. Until we move to a static-residential provider, we
# refresh cookies daily.
#
# Usage:
#   /opt/twitter-bot/refresh-cookies.sh                 # interactive
#   /opt/twitter-bot/refresh-cookies.sh AUTH_TOKEN CT0  # one-shot

set -e
STATE_FILE=/opt/twitter-bot/twitter-state.json

if [ ! -f "$STATE_FILE" ]; then
  echo "❌ $STATE_FILE not found"
  exit 1
fi

if [ -n "$1" ] && [ -n "$2" ]; then
  AUTH_TOKEN="$1"
  CT0="$2"
else
  echo "Paste auth_token from Firefox DevTools → Storage → Cookies → x.com → auth_token (long hex string), then press Enter:"
  read -r AUTH_TOKEN
  echo "Paste ct0 (long hex string), then press Enter:"
  read -r CT0
fi

if [ ${#AUTH_TOKEN} -lt 30 ] || [ ${#CT0} -lt 30 ]; then
  echo "❌ Both values look too short (auth_token=${#AUTH_TOKEN} chars, ct0=${#CT0} chars). Aborting."
  exit 1
fi

# Patch the JSON via python3 (preinstalled on Ubuntu/Debian). Pass values via
# env so heredoc body doesn't have shell-interpolation footguns.
AUTH="$AUTH_TOKEN" CT="$CT0" STATE="$STATE_FILE" python3 <<'EOF'
import json, os, sys
state_file = os.environ["STATE"]
with open(state_file) as f:
    state = json.load(f)
updated = {"auth_token": False, "ct0": False}
for c in state.get("cookies", []):
    if c.get("name") == "auth_token":
        c["value"] = os.environ["AUTH"]
        updated["auth_token"] = True
    elif c.get("name") == "ct0":
        c["value"] = os.environ["CT"]
        updated["ct0"] = True
if not all(updated.values()):
    print(f"❌ Required cookie names not found in state file. Status: {updated}")
    sys.exit(1)
with open(state_file, "w") as f:
    json.dump(state, f, indent=2)
EOF

chmod 600 "$STATE_FILE"
echo "✓ Cookies refreshed at $STATE_FILE"
echo "  Smoke test: cd /opt/twitter-bot && node run.js mentions"
