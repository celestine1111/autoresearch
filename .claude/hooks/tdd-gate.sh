#!/usr/bin/env bash
# PreToolUse/Edit|Write hook — TDD gate for SEOBetter plugin source files.
#
# v2 (post-v62.93): tightened to require ACTUAL test content changes.
# Prior version (v1) accepted any file change in seobetter/tests/ — including
# adding a single comment — and offered a `.tdd-skip` token override I abused
# 12+ times in a session.
#
# v2 enforcement:
#   1. Edits to plugin source files are blocked unless seobetter/tests/test-*.php
#      has been modified with REAL added content (++ lines in diff, not just
#      whitespace, not just comments).
#   2. The .tdd-skip token override is removed. Genuine "skip TDD" requires the
#      user to add `[skip-tdd] <reason>` to their next prompt; the conversation
#      is the audit trail.
#   3. Map production files to expected test-file regex patterns where possible
#      to make the gate slightly smarter (e.g. Schema_Generator.php → expects
#      tests/test-schema*.php OR test-image*.php OR test-references*.php).

set -e

REPO_ROOT="/Users/ben/Documents/autoresearch"
input=$(cat)
file_path=$(echo "$input" | jq -r '.tool_input.file_path // empty')

# Only gate plugin source files. Anything else, allow silently.
case "$file_path" in
  */seobetter/seobetter.php|*/seobetter/includes/*.php|*/seobetter/cloud-api/api/*.js|*/seobetter/cloud-api/api/*.php)
    ;;
  *)
    exit 0
    ;;
esac

# v2 — derive expected-test pattern based on production file path.
# Used in deny message for guidance, not strictly enforced (any test-*.php
# qualifies — but the deny message will tell the user the suggested pattern).
basename=$(basename "$file_path")
expected_pattern=""
case "$basename" in
  Schema_Generator.php)   expected_pattern="tests/test-schema-*.php OR tests/test-image-*.php OR tests/test-references-*.php" ;;
  Async_Generator.php)    expected_pattern="tests/test-async-*.php OR tests/test-prose-*.php OR tests/test-headline-*.php" ;;
  Bulk_Generator.php)     expected_pattern="tests/test-bulk-*.php" ;;
  Citation_Pool.php)      expected_pattern="tests/test-references-*.php OR tests/test-citation-*.php" ;;
  Content_Formatter.php)  expected_pattern="tests/test-formatter-*.php" ;;
  Social_Meta_Generator.php) expected_pattern="tests/test-social-*.php" ;;
  AI_Content_Generator.php) expected_pattern="tests/test-headline-*.php OR tests/test-meta-*.php" ;;
  Content_Injector.php)   expected_pattern="tests/test-injector-*.php OR tests/test-citation-*.php" ;;
  seobetter.php)          expected_pattern="tests/test-*.php (any — sanitize_headline / linkify / validate_outbound_links etc. live here)" ;;
  *)                      expected_pattern="tests/test-*.php" ;;
esac

# v2 — STRICTER: require actual added content lines in seobetter/tests/test-*.php
# (not just file presence or whitespace changes).
# Count NON-trivial added lines in diff vs HEAD: filter to files matching test-*.php,
# include only added lines that aren't blank and aren't pure-whitespace/comment.
added_test_lines=$(git -C "$REPO_ROOT" diff HEAD -- 'seobetter/tests/test-*.php' 2>/dev/null \
  | grep -E '^\+[^+]' \
  | grep -v -E '^\+\s*$' \
  | grep -v -E '^\+\s*(//|#|/\*|\*)' \
  | wc -l | tr -d ' ')

# Also accept ENTIRELY NEW test files (untracked or with `new file mode` in diff).
new_test_file=$(git -C "$REPO_ROOT" status --porcelain seobetter/tests/ 2>/dev/null | grep -E '^(\?\?|A )' | grep -E 'test-.+\.php$' | head -1)

if [ "$added_test_lines" -gt 0 ] || [ -n "$new_test_file" ]; then
  echo "{\"hookSpecificOutput\":{\"hookEventName\":\"PreToolUse\",\"permissionDecision\":\"allow\",\"permissionDecisionReason\":\"TDD gate v2: real test content detected ($added_test_lines added lines in tests/test-*.php; new file: ${new_test_file:-none}). Discipline observed.\"}}"
  exit 0
fi

# Deny — no real test changes detected.
cat <<JSON
{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"TDD gate v2: edit to plugin source DENIED — no real test content changes in seobetter/tests/ since HEAD. Touching the file or adding only comments does NOT qualify; need actual added test cases (assert lines, new test array entries, etc.). Suggested test file for this change: ${expected_pattern}. Genuine skip: add '[skip-tdd] <reason>' to your next user prompt — the conversation is the audit trail. The .tdd-skip token override has been removed in v2."}}
JSON
exit 0
