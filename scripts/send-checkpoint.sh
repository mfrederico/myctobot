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

RESULT_JSON="$1"

if [[ -z "$RESULT_JSON" ]]; then
    echo "Error: Result JSON required as first argument"
    echo "Usage: ./send-checkpoint.sh '{\"success\": true, \"pr_url\": \"...\"}'"
    exit 1
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
  "job_id": "$MYCTOBOT_JOB_ID",
  "member_id": ${MYCTOBOT_MEMBER_ID:-0},
  "tenant": "${MYCTOBOT_WORKSPACE:-default}",
  "status": "checkpoint",
  "result": $RESULT_JSON
}
EOF
)

echo "Posting checkpoint to webhook..."
echo "  Job ID: $MYCTOBOT_JOB_ID"
echo "  Webhook: $WEBHOOK_URL"

# POST to webhook
HTTP_CODE=$(curl -s -o /tmp/webhook_response.txt -w "%{http_code}" \
    -X POST "$WEBHOOK_URL" \
    -H "Authorization: Bearer ${MYCTOBOT_API_KEY}" \
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
