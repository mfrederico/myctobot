#!/bin/bash
#
# send-checkpoint.sh - Signal checkpoint to MyCTOBot (session stays alive)
#
# Called by AI Dev runners (Claude) after creating a PR.
# This script:
#   1. Writes result.json for local backup
#   2. POSTs to the webhook with status="checkpoint"
#   3. Session stays alive to receive comments/updates
#
# Usage:
#   ./send-checkpoint.sh '{"success": true, "pr_url": "...", ...}'
#
# Required environment variables:
#   MYCTOBOT_JOB_ID      - The job ID
#   MYCTOBOT_MEMBER_ID   - Member ID
#   MYCTOBOT_WORKSPACE   - Tenant slug
#   MYCTOBOT_API_KEY     - API key for webhook auth
#   MYCTOBOT_WEBHOOK_URL - Base URL for webhook (optional, defaults to myctobot.ai)
#

set -e

# Accept EITHER the positional JSON blob or --flag form.
#
# Agents reach for flags. One wrote its whole checkpoint as
#   ./send-checkpoint.sh --success true --issue-key ... --pr-url ...
# and because "--success" is a non-empty $1 it sailed past the guard below,
# was written to result.json verbatim, and posted as  "result": --success
# which is not JSON. The webhook dropped it, the job sat in "running" for five
# days, and nothing anywhere said a word. Parse the flags rather than lose the
# checkpoint, and validate before sending either way.
build_json_from_flags() {
    local py
    py="$(command -v python3 || command -v python || true)"
    if [[ -z "$py" ]]; then
        echo "Error: --flag form needs python3 to build JSON. Pass a JSON string instead." >&2
        return 1
    fi
    "$py" -c '
import json, sys

KEYS = {
    "--success": ("success", "bool"),
    "--issue-key": ("issue_key", "str"),
    "--pr-url": ("pr_url", "str"),
    "--pr-number": ("pr_number", "int"),
    "--branch-name": ("branch_name", "str"),
    "--branch": ("branch_name", "str"),
    "--summary": ("summary", "str"),
    "--files-changed": ("files_changed", "list"),
    "--verification-passed": ("verification_passed", "bool"),
    "--verification-notes": ("verification_notes", "str"),
}

out, args = {}, sys.argv[1:]
i = 0
while i < len(args):
    a = args[i]
    if a in KEYS:
        name, kind = KEYS[a]
        nxt = args[i + 1] if i + 1 < len(args) else None
        # A bare boolean flag ("--success --pr-url X") must not eat the next
        # flag as its value, or the option after it is silently dropped.
        if kind == "bool" and (nxt is None or nxt.startswith("--")):
            out[name] = True
            i += 1
            continue
        val = nxt if nxt is not None else ""
        if kind == "bool":
            out[name] = str(val).lower() in ("1", "true", "yes")
        elif kind == "int":
            try:
                out[name] = int(val)
            except ValueError:
                out[name] = None
        elif kind == "list":
            out[name] = [x for x in (q.strip() for q in val.split(",")) if x]
        else:
            out[name] = val
        i += 2
        continue
    i += 1

out.setdefault("success", True)
print(json.dumps(out))
' "$@"
}

if [[ "$1" == --* ]]; then
    RESULT_JSON="$(build_json_from_flags "$@")" || exit 1
    echo "Built result JSON from flags: $RESULT_JSON"
else
    RESULT_JSON="$1"
fi

if [[ -z "$RESULT_JSON" ]]; then
    echo "Error: Result JSON required as first argument"
    echo "Usage: ./send-checkpoint.sh '{\"success\": true, \"pr_url\": \"...\"}'"
    exit 1
fi

if command -v python3 >/dev/null 2>&1; then
    if ! printf '%s' "$RESULT_JSON" | python3 -c 'import json,sys; json.load(sys.stdin)' 2>/dev/null; then
        echo "Error: result is not valid JSON:" >&2
        printf '  %s\n' "$RESULT_JSON" >&2
        echo "Pass a JSON string, or use --success/--pr-url/--pr-number/--branch-name/--summary." >&2
        exit 1
    fi
fi

if [[ -z "$MYCTOBOT_JOB_ID" ]]; then
    echo "Error: MYCTOBOT_JOB_ID environment variable not set"
    exit 1
fi

# Write result.json locally for backup
RESULT_FILE="result.json"
echo "$RESULT_JSON" > "$RESULT_FILE"
echo "Wrote result to $RESULT_FILE"

# Build webhook URL
WEBHOOK_BASE="${MYCTOBOT_WEBHOOK_URL:-https://myctobot.ai}"
WEBHOOK_URL="${WEBHOOK_BASE}/webhook/aidev"

# Build the payload with job metadata
PAYLOAD=$(cat <<EOF
{
  "job_uid": "$MYCTOBOT_JOB_ID",
  "member_id": ${MYCTOBOT_MEMBER_ID:-0},
  "workspace": "${MYCTOBOT_WORKSPACE:-default}",
  "status": "checkpoint",
  "result": $RESULT_JSON
}
EOF
)

# /webhook/aidev validates the global cron.api_key, NOT the member API key.
# Fall back to MYCTOBOT_API_KEY only for older dispatchers that didn't set this.
WEBHOOK_KEY="${MYCTOBOT_WEBHOOK_KEY:-$MYCTOBOT_API_KEY}"

echo "Posting checkpoint to webhook..."
echo "  Job ID: $MYCTOBOT_JOB_ID"
echo "  Webhook: $WEBHOOK_URL"

# POST to webhook
HTTP_CODE=$(curl -s -o /tmp/webhook_response.txt -w "%{http_code}" \
    -X POST "$WEBHOOK_URL" \
    -H "Authorization: Bearer ${WEBHOOK_KEY}" \
    -H "Content-Type: application/json" \
    -d "$PAYLOAD" 2>/dev/null || echo "000")

if [[ "$HTTP_CODE" =~ ^2 ]]; then
    echo "Webhook response: $HTTP_CODE (success)"
    cat /tmp/webhook_response.txt 2>/dev/null || true
    echo ""
else
    echo "Warning: Webhook returned HTTP $HTTP_CODE"
    cat /tmp/webhook_response.txt 2>/dev/null || true
    echo ""
    echo "Result was saved locally to $RESULT_FILE"
fi

# Get the tmux session name
SESSION_NAME="${TMUX_SESSION_NAME:-}"
if [[ -z "$SESSION_NAME" && -n "$TMUX" ]]; then
    # Extract session name from $TMUX (format: /tmp/tmux-uid/default,pid,session_index)
    SESSION_NAME=$(tmux display-message -p '#S' 2>/dev/null || echo "")
fi

if [[ -n "$SESSION_NAME" ]]; then
    # Capture terminal snapshot for debugging
    SNAPSHOT_FILE="terminal_snapshot.txt"
    echo "Capturing terminal snapshot to $SNAPSHOT_FILE..."

    # Capture the entire scrollback buffer (up to 50000 lines)
    tmux capture-pane -t "$SESSION_NAME" -p -S -50000 > "$SNAPSHOT_FILE" 2>/dev/null || true

    # Also save with timestamp for historical record
    TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    SNAPSHOT_ARCHIVE="terminal_snapshot_${TIMESTAMP}.txt"
    cp "$SNAPSHOT_FILE" "$SNAPSHOT_ARCHIVE" 2>/dev/null || true

    echo "  Snapshot saved: $SNAPSHOT_FILE ($(wc -l < "$SNAPSHOT_FILE" 2>/dev/null || echo 0) lines)"
fi

# Session stays alive to receive further updates from issue tracker
# The webhook will send /exit when ticket transitions to "Ready for QA"
echo ""
echo "═══════════════════════════════════════════════════════════════════"
echo "                     CHECKPOINT COMPLETE"
echo "═══════════════════════════════════════════════════════════════════"
echo ""
echo "✓ Initial work is done. The PR has been created."
echo ""
echo "This session will remain active to receive updates:"
echo "  • Comments added to the ticket will appear here"
echo "  • You can continue to iterate on the implementation"
echo "  • When the ticket moves to 'Ready for QA', this session will close"
echo ""
echo "To manually exit: type /exit"
echo "═══════════════════════════════════════════════════════════════════"
echo ""
