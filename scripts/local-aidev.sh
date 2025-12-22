#!/bin/bash
#
# Local AI Developer Runner
# Uses YOUR Claude Code subscription instead of API credits
#
# Usage: ./scripts/local-aidev.sh <issue_key> [--orchestrator]
#

ISSUE_KEY="$1"
USE_ORCHESTRATOR="$2"

if [ -z "$ISSUE_KEY" ]; then
    echo "Usage: ./scripts/local-aidev.sh <issue_key> [--orchestrator]"
    exit 1
fi

SESSION_NAME="aidev-${ISSUE_KEY}"
WORK_DIR="/tmp/local-aidev-${ISSUE_KEY}"
PROMPT_FILE="${WORK_DIR}/prompt.txt"
OUTPUT_FILE="${WORK_DIR}/output.log"

# Create work directory
mkdir -p "$WORK_DIR"
cd "$WORK_DIR"

# Generate prompt (you can customize this or fetch from the trigger script)
echo "Generating prompt for $ISSUE_KEY..."

# For now, create a simple prompt - in production, this would call PHP to generate
cat > "$PROMPT_FILE" << EOF
You are implementing Jira ticket $ISSUE_KEY.

## Setup
1. Clone the repository if not already cloned
2. Create a feature branch: fix/${ISSUE_KEY}-description
3. Implement the changes
4. Commit and push
5. Create a PR

## Output
When complete, output a JSON summary:
\`\`\`json
{
  "success": true,
  "issue_key": "${ISSUE_KEY}",
  "pr_url": "...",
  "branch_name": "...",
  "summary": "..."
}
\`\`\`
EOF

echo "Created prompt at: $PROMPT_FILE"
echo ""

# Kill existing session if any
tmux kill-session -t "$SESSION_NAME" 2>/dev/null

# Create new tmux session
echo "Starting tmux session: $SESSION_NAME"
tmux new-session -d -s "$SESSION_NAME" -x 200 -y 50

# Start Claude in the session
echo "Starting Claude Code (using YOUR subscription)..."
tmux send-keys -t "$SESSION_NAME" "cd '$WORK_DIR' && claude --print < '$PROMPT_FILE' 2>&1 | tee '$OUTPUT_FILE'" Enter

echo ""
echo "============================================"
echo "Claude is running in tmux session: $SESSION_NAME"
echo ""
echo "Commands:"
echo "  Watch live:    tmux attach -t $SESSION_NAME"
echo "  Monitor file:  tail -f $OUTPUT_FILE"
echo "  Kill session:  tmux kill-session -t $SESSION_NAME"
echo ""
echo "Output will be saved to: $OUTPUT_FILE"
echo "============================================"
echo ""

# Option to attach immediately
read -p "Attach to session now? [y/N] " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    tmux attach -t "$SESSION_NAME"
fi
