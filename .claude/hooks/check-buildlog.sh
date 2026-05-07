#!/usr/bin/env bash
# PreToolUse/Bash hook — v1.5.203 extended 4-doc sync enforcement.
#
# Blocks `git commit` when seobetter code is staged without the required
# companion documentation files also being staged. Per the /seobetter skill
# Step 4b hard-mapping + the 5-layer optimization framework:
#
#   Always required:
#     seobetter PHP/JS staged  →  BUILD_LOG.md staged
#
#   Per-file co-doc requirements (v1.5.203 new):
#     Async_Generator.php      →  SEO-GEO-AI-GUIDELINES.md (§3.1A / §3.1B / §10)
#     Schema_Generator.php     →  SEO-GEO-AI-GUIDELINES.md §10
#                              +  structured-data.md (§4 / §5)
#                              +  article_design.md §11
#     Content_Formatter.php    →  article_design.md §11
#     validate_outbound_links  →  external-links-policy.md
#     (seobetter.php changes to that method)
#
# The block is harness-level and cannot be bypassed without --no-verify or
# disabling the hook in .claude/settings.json. This is intentional: docs and
# code stay in lockstep, no drift possible between commits.

set -u

input=$(cat)
cmd=$(printf '%s' "$input" | jq -r '.tool_input.command // empty')

# Belt-and-braces: only act on git commit commands.
case "$cmd" in
  *"git commit"*) ;;
  *) exit 0 ;;
esac

repo_root=$(git rev-parse --show-toplevel 2>/dev/null) || exit 0
staged=$(git -C "$repo_root" diff --cached --name-only 2>/dev/null)

# If nothing under seobetter/ is staged, this hook is irrelevant.
if ! printf '%s\n' "$staged" | grep -qE '^seobetter/'; then
  exit 0
fi

# Helper — check if a path is in the staged list (exact match).
staged_has() {
  printf '%s\n' "$staged" | grep -qxF "$1"
}

# Emit a block JSON with a structured reason and exit.
block() {
  local reason="$1"
  # Escape double-quotes and newlines for JSON embedding.
  local esc
  esc=$(printf '%s' "$reason" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))' 2>/dev/null || printf '"%s"' "${reason//\"/\\\"}")
  cat <<JSON
{
  "hookSpecificOutput": {
    "hookEventName": "PreToolUse",
    "permissionDecision": "deny",
    "permissionDecisionReason": ${esc}
  }
}
JSON
  exit 0
}

# ------------------------------------------------------------------
# 1. BUILD_LOG always required when ANY seobetter PHP/JS is staged.
# ------------------------------------------------------------------
if printf '%s\n' "$staged" | grep -qE '^seobetter/.+\.(php|js)$'; then
  if ! staged_has 'seobetter/seo-guidelines/BUILD_LOG.md'; then
    block "BLOCKED by check-buildlog hook: seobetter PHP/JS code is staged but seobetter/seo-guidelines/BUILD_LOG.md is NOT. Per /seobetter Step 4, every shipped change needs a BUILD_LOG entry in the SAME commit — with a file::method() line anchor and a Verify: grep command. Add the entry, run 'git add seobetter/seo-guidelines/BUILD_LOG.md', and retry."
  fi
fi

# ------------------------------------------------------------------
# 1b. v62.94+ TDD enforcement — production code change requires a
#     test file in seobetter/tests/ to also be staged in the SAME commit.
#     Per TESTING_PROTOCOL.md mandatory red-green-refactor.
#     Override: add `[skip-tdd]` token to commit message for typo-only
#     fixes (version bumps, doc-only edits, etc.).
# ------------------------------------------------------------------
prod_changed=$(printf '%s\n' "$staged" | grep -E '^seobetter/(seobetter\.php|includes/.+\.php|cloud-api/api/.+\.(php|js))$')
if [ -n "$prod_changed" ]; then
  test_staged=$(printf '%s\n' "$staged" | grep -E '^seobetter/tests/test-.+\.php$' | head -1)
  if [ -z "$test_staged" ]; then
    # v62.97 — [skip-tdd] override REMOVED. No more escape valves. Per
    # WORKFLOW.md §2.A: every prod-code change requires a real test in the
    # same commit. If you're tempted to add a "trivial edit" exception, you
    # are about to ship something untested. Don't.
    block "BLOCKED by TDD enforcement (WORKFLOW.md §1 + §2.A): seobetter production code is staged but no seobetter/tests/test-*.php is staged in the same commit. The [skip-tdd] override has been removed (v62.97). Workflow: RED — write test FIRST. RED-VERIFY — run on VPS, confirm pass=false. GREEN — fix code. GREEN-VERIFY — run on VPS, confirm pass=true. THEN commit BOTH files together. No exceptions."
  fi
fi

# ------------------------------------------------------------------
# 1c. v62.94+ "verified" claim enforcement — commits whose message
#     contains "verified" or "sign-off" require an audit-output marker
#     proving the full audit script output was reviewed by the user.
# 1d. Validator-pass enforcement — same trigger requires either a
#     .validator-pass-{POST_ID} file OR a "validator: clean" line.
# ------------------------------------------------------------------
# Use the cmd directly (it has the full -m "..." inline). Simpler than parsing.
verifies=0
if printf '%s' "$cmd" | grep -qE '(verified end-to-end|signed off|sign-off|VERIFIED|✅ Verified)'; then
  verifies=1
fi
if [ "$verifies" -eq 1 ]; then
  if ! printf '%s' "$cmd" | grep -qE '(AUDIT:|Audit-Output:|audit-script-output:|full_audit\.py|pass: [0-9]+.*fail:|post.*\.html)'; then
    block "BLOCKED by audit-output enforcement (TESTING_PROTOCOL.md §3C): commit claims verification but commit body has no audit-script-output reference. Before claiming a post verified/signed-off, the full audit output (every check, pass/fail/warn) must be shown to the user AND referenced in the commit body. Required marker: AUDIT: or Audit-Output: line OR inline pass/fail counts (e.g. 'AUDIT: 33 pass / 0 fail on post 757')."
  fi
  post_id=$(printf '%s' "$cmd" | grep -oE 'post [0-9]+' | head -1 | grep -oE '[0-9]+')
  if [ -n "$post_id" ]; then
    if [ -f "$repo_root/seobetter/tests/.validator-pass-${post_id}" ]; then
      : # ok — file evidence
    elif printf '%s' "$cmd" | grep -qiE 'validator: (clean|warnings-only|pass)|schema\.org validator pass|rich-results test pass'; then
      : # ok — marker in commit body
    else
      block "BLOCKED by validator-pass enforcement (TESTING_PROTOCOL.md §3A): commit claims verification of post ${post_id} but no validator-pass record exists. Required: create seobetter/tests/.validator-pass-${post_id} after running validator.schema.org / Google Rich Results Test, OR include a 'validator: clean' / 'validator: warnings-only' line in the commit body."
    fi
  fi
fi

# ------------------------------------------------------------------
# 2. Async_Generator.php → requires SEO-GEO-AI-GUIDELINES.md
#    (prose templates, §3.1A / §3.1B / §10 mappings)
# ------------------------------------------------------------------
if staged_has 'seobetter/includes/Async_Generator.php'; then
  if ! staged_has 'seobetter/seo-guidelines/SEO-GEO-AI-GUIDELINES.md'; then
    block "BLOCKED by 4-doc sync hook: includes/Async_Generator.php is staged but seo-guidelines/SEO-GEO-AI-GUIDELINES.md is NOT. Async_Generator holds the per-content-type prose templates, which MUST stay in sync with §3.1A (Genre Overrides) and §10 (Content Type → Schema Mapping) in SEO-GEO-AI-GUIDELINES.md. Update that file in the same commit, or explain in the commit message why this change does not affect the template/schema mapping."
  fi
fi

# ------------------------------------------------------------------
# 3. Schema_Generator.php → requires 3-doc schema sync
#    SEO-GEO-AI-GUIDELINES.md §10 + structured-data.md §4/§5 + article_design.md §11
# ------------------------------------------------------------------
if staged_has 'seobetter/includes/Schema_Generator.php'; then
  missing=""
  staged_has 'seobetter/seo-guidelines/SEO-GEO-AI-GUIDELINES.md' || missing="${missing}SEO-GEO-AI-GUIDELINES.md, "
  staged_has 'seobetter/seo-guidelines/structured-data.md'       || missing="${missing}structured-data.md, "
  staged_has 'seobetter/seo-guidelines/article_design.md'        || missing="${missing}article_design.md, "
  if [ -n "$missing" ]; then
    missing="${missing%, }"
    block "BLOCKED by 4-doc sync hook: includes/Schema_Generator.php is staged but the following schema doc(s) are NOT: ${missing}. Schema changes require three-way doc parity — SEO-GEO-AI-GUIDELINES.md §10 (schema mapping), structured-data.md §4/§5 (fields + content-type → @type map), and article_design.md §11 (visual context). Stage all three together or explain in the commit message why this change does not need full sync."
  fi
fi

# ------------------------------------------------------------------
# 4. Content_Formatter.php → requires article_design.md
# ------------------------------------------------------------------
if staged_has 'seobetter/includes/Content_Formatter.php'; then
  if ! staged_has 'seobetter/seo-guidelines/article_design.md'; then
    block "BLOCKED by 4-doc sync hook: includes/Content_Formatter.php is staged but seo-guidelines/article_design.md is NOT. Content_Formatter renders the per-content-type HTML/CSS, which must stay in sync with article_design.md §11 (content-type visual variations). Update article_design.md in the same commit."
  fi
fi

# ------------------------------------------------------------------
# 5. external-links-policy.md gate for validator / citation pool changes
#    (triggered by seobetter.php changes touching those functions)
# ------------------------------------------------------------------
if staged_has 'seobetter/seobetter.php'; then
  # Heuristic: if the staged diff mentions validate_outbound_links, filter_link,
  # sanitize_references_section, append_references_section, verify_citation_atoms,
  # is_host_trusted, or get_trusted_domain_whitelist — require external-links-policy.md.
  if git -C "$repo_root" diff --cached --unified=0 -- 'seobetter/seobetter.php' 2>/dev/null \
      | grep -qE 'validate_outbound_links|filter_link|sanitize_references_section|append_references_section|verify_citation_atoms|is_host_trusted|get_trusted_domain_whitelist'; then
    if ! staged_has 'seobetter/seo-guidelines/external-links-policy.md'; then
      block "BLOCKED by 4-doc sync hook: seobetter.php changes touch the outbound-link validator or citation-pool helpers, but seo-guidelines/external-links-policy.md is NOT staged. Update the policy doc (Layer 1/2/3 section + failure modes if new) in the same commit."
    fi
  fi
fi

# ------------------------------------------------------------------
# 6. Citation_Pool.php → requires external-links-policy.md
# ------------------------------------------------------------------
if staged_has 'seobetter/includes/Citation_Pool.php'; then
  if ! staged_has 'seobetter/seo-guidelines/external-links-policy.md'; then
    block "BLOCKED by 4-doc sync hook: includes/Citation_Pool.php is staged but seo-guidelines/external-links-policy.md (Phase 1 — Pool construction) is NOT. Update it in the same commit."
  fi
fi

exit 0
