#!/bin/bash
#
# monitor-job.sh - Monitor AI Developer job across shards
#
# Usage: ./scripts/monitor-job.sh <issue_key> [shard_ip] [--verbose|-v]
#
# Creates a tmux session with 3-4 panes:
#   1. Shard log filtered by issue key
#   2. Claude session output (real-time)
#   3. Job directory listing and status
#   4. (with -v) Claude's thinking/reasoning from transcript
#
# Examples:
#   ./scripts/monitor-job.sh SSI-1883
#   ./scripts/monitor-job.sh SSI-1883 173.231.12.84
#   ./scripts/monitor-job.sh SSI-1883 -v              # With Claude thinking
#   ./scripts/monitor-job.sh SSI-1883 173.231.12.84 -v
#

set -e

# Parse arguments
ISSUE_KEY=""
SHARD_IP="173.231.12.84"
VERBOSE=false

for arg in "$@"; do
    case $arg in
        -v|--verbose)
            VERBOSE=true
            ;;
        -*)
            echo "Unknown option: $arg"
            exit 1
            ;;
        *)
            if [ -z "$ISSUE_KEY" ]; then
                ISSUE_KEY="$arg"
            else
                SHARD_IP="$arg"
            fi
            ;;
    esac
done

SHARD_USER="${SHARD_USER:-claudeuser}"
SESSION_NAME="aidev-monitor"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

if [ -z "$ISSUE_KEY" ]; then
    echo -e "${RED}Usage: $0 <issue_key> [shard_ip] [--verbose|-v]${NC}"
    echo ""
    echo "Examples:"
    echo "  $0 SSI-1883"
    echo "  $0 SSI-1883 173.231.12.84"
    echo "  $0 SSI-1883 -v                # With Claude's thinking"
    echo "  $0 SSI-1883 173.231.12.84 -v"
    echo ""
    echo "This creates a tmux session with panes showing:"
    echo "  - Shard log (filtered by issue key)"
    echo "  - Claude session output"
    echo "  - Job status and file listing"
    echo "  - (with -v) Claude's thinking from JSONL transcript"
    exit 1
fi

echo -e "${BLUE}=== AI Developer Job Monitor ===${NC}"
echo -e "Issue: ${GREEN}$ISSUE_KEY${NC}"
echo -e "Shard: ${GREEN}$SHARD_IP${NC}"
echo -e "Verbose: ${GREEN}$VERBOSE${NC}"
echo ""

# Check if tmux is installed
if ! command -v tmux &> /dev/null; then
    echo -e "${RED}Error: tmux is not installed${NC}"
    echo "Install with: sudo apt install tmux"
    exit 1
fi

# Kill existing session if it exists
tmux kill-session -t "$SESSION_NAME" 2>/dev/null || true

# Find job directory on shard
echo -e "${YELLOW}Finding job directory on shard...${NC}"
JOB_DIR=$(ssh "$SHARD_USER@$SHARD_IP" "ls -td /tmp/aidev-job-*/ 2>/dev/null | head -1" || echo "")

if [ -z "$JOB_DIR" ]; then
    echo -e "${YELLOW}No active job directories found. Will monitor for new jobs.${NC}"
    JOB_DIR="/tmp/aidev-job-*"
fi

echo -e "Job directory: ${GREEN}$JOB_DIR${NC}"
echo ""

# Get today's date for log file
TODAY=$(date +%Y-%m-%d)
SHARD_LOG="/var/www/html/default/myctobot/log/shard-${TODAY}.log"

# Create helper scripts on shard
echo -e "${YELLOW}Uploading helper scripts to shard...${NC}"

# Job status helper
STATUS_SCRIPT='#!/bin/bash
while true; do
  clear
  echo "=== Active Jobs ==="
  ls -la /tmp/aidev-job-*/ 2>/dev/null | head -20
  echo
  JOB=$(ls -td /tmp/aidev-job-*/ 2>/dev/null | head -1)
  if [ -n "$JOB" ]; then
    echo "=== Latest Job: $JOB ==="
    ls -la "$JOB" 2>/dev/null
    echo
    if [ -f "$JOB/session-info.json" ]; then
      echo "=== Session Info ==="
      cat "$JOB/session-info.json" | head -30
    fi
  fi
  sleep 5
done'

# Claude thinking monitor - parses JSONL transcript
THINKING_SCRIPT='#!/usr/bin/env python3
import sys
import json
import time
import os
import glob

def get_latest_jsonl():
    """Find the latest JSONL transcript file"""
    pattern = "/tmp/aidev-job-*/.claude/projects/*/*.jsonl"
    files = glob.glob(pattern)
    if not files:
        return None
    # Sort by modification time, newest first
    files.sort(key=os.path.getmtime, reverse=True)
    return files[0]

def parse_message(data):
    """Extract readable content from a message"""
    msg_type = data.get("type", "")
    message = data.get("message", {})
    content = message.get("content", [])

    output = []

    if msg_type == "assistant":
        for item in content:
            if isinstance(item, dict):
                if item.get("type") == "text":
                    text = item.get("text", "")
                    if text:
                        # Truncate very long texts
                        if len(text) > 1000:
                            text = text[:1000] + "...[truncated]"
                        output.append(f"\033[0;36m[CLAUDE]\033[0m {text}")
                elif item.get("type") == "tool_use":
                    name = item.get("name", "unknown")
                    output.append(f"\033[0;33m[TOOL: {name}]\033[0m")
            elif isinstance(item, str):
                output.append(f"\033[0;36m[CLAUDE]\033[0m {item[:500]}")

    elif msg_type == "user":
        for item in content:
            if isinstance(item, dict):
                if item.get("type") == "tool_result":
                    is_error = item.get("is_error", False)
                    result = item.get("content", "")[:200]
                    if is_error:
                        output.append(f"\033[0;31m[ERROR]\033[0m {result}")
                    else:
                        output.append(f"\033[0;32m[RESULT]\033[0m {result}...")

    return output

def main():
    print("\033[0;34m=== Claude Thinking Monitor ===\033[0m")
    print("Waiting for JSONL transcript...")

    last_file = None
    last_pos = 0

    while True:
        jsonl_file = get_latest_jsonl()

        if jsonl_file != last_file:
            if jsonl_file:
                print(f"\n\033[0;33mMonitoring: {jsonl_file}\033[0m\n")
            last_file = jsonl_file
            last_pos = 0

        if jsonl_file and os.path.exists(jsonl_file):
            try:
                with open(jsonl_file, "r") as f:
                    f.seek(last_pos)
                    for line in f:
                        line = line.strip()
                        if line:
                            try:
                                data = json.loads(line)
                                messages = parse_message(data)
                                for msg in messages:
                                    print(msg)
                                    print()
                            except json.JSONDecodeError:
                                pass
                    last_pos = f.tell()
            except Exception as e:
                print(f"Error: {e}")

        time.sleep(1)

if __name__ == "__main__":
    main()
'

# Upload helper scripts
echo "$STATUS_SCRIPT" | ssh "$SHARD_USER@$SHARD_IP" "cat > /tmp/job-status.sh && chmod +x /tmp/job-status.sh"
echo "$THINKING_SCRIPT" | ssh "$SHARD_USER@$SHARD_IP" "cat > /tmp/claude-thinking.py && chmod +x /tmp/claude-thinking.py"

# Create tmux session with panes
echo -e "${YELLOW}Creating tmux session...${NC}"

# Create new session with first pane (shard log)
tmux new-session -d -s "$SESSION_NAME" -n "monitor"

# Pane 0: Shard log filtered by issue key
tmux send-keys -t "$SESSION_NAME:0.0" "echo -e '${BLUE}=== Shard Log (filtered: $ISSUE_KEY) ===${NC}'" Enter
tmux send-keys -t "$SESSION_NAME:0.0" "ssh $SHARD_USER@$SHARD_IP \"tail -f $SHARD_LOG 2>/dev/null | grep --line-buffered -E '$ISSUE_KEY|aidev|claude|error|Error|ERROR'\"" Enter

# Split horizontally for session log
tmux split-window -t "$SESSION_NAME:0" -h

# Pane 1: Claude session output
tmux send-keys -t "$SESSION_NAME:0.1" "echo -e '${BLUE}=== Claude Session Output ===${NC}'" Enter
tmux send-keys -t "$SESSION_NAME:0.1" "ssh $SHARD_USER@$SHARD_IP \"while true; do JOB=\\\$(ls -td /tmp/aidev-job-*/ 2>/dev/null | head -1); if [ -f \\\"\\\$JOB/session.log\\\" ]; then tail -f \\\"\\\$JOB/session.log\\\"; else echo 'Waiting for session.log...'; sleep 2; fi; done\"" Enter

# Split bottom pane vertically for status
tmux split-window -t "$SESSION_NAME:0.1" -v

# Pane 2: Job status and file listing
tmux send-keys -t "$SESSION_NAME:0.2" "echo -e '${BLUE}=== Job Status & Files ===${NC}'" Enter
tmux send-keys -t "$SESSION_NAME:0.2" "ssh -t $SHARD_USER@$SHARD_IP /tmp/job-status.sh" Enter

# If verbose mode, add Claude thinking pane
if [ "$VERBOSE" = true ]; then
    # Split the left pane (shard log) vertically to add thinking pane
    tmux split-window -t "$SESSION_NAME:0.0" -v

    # Pane 3: Claude thinking/reasoning
    tmux send-keys -t "$SESSION_NAME:0.1" "echo -e '${CYAN}=== Claude Thinking ===${NC}'" Enter
    tmux send-keys -t "$SESSION_NAME:0.1" "ssh -t $SHARD_USER@$SHARD_IP 'python3 /tmp/claude-thinking.py'" Enter

    # Re-select layout for 4 panes
    tmux select-layout -t "$SESSION_NAME:0" tiled

    # Set pane titles
    tmux select-pane -t "$SESSION_NAME:0.0" -T "Shard Log"
    tmux select-pane -t "$SESSION_NAME:0.1" -T "Claude Thinking"
    tmux select-pane -t "$SESSION_NAME:0.2" -T "Session Output"
    tmux select-pane -t "$SESSION_NAME:0.3" -T "Job Status"
else
    # Set pane titles for 3 panes
    tmux select-pane -t "$SESSION_NAME:0.0" -T "Shard Log"
    tmux select-pane -t "$SESSION_NAME:0.1" -T "Claude Output"
    tmux select-pane -t "$SESSION_NAME:0.2" -T "Job Status"

    # Adjust pane sizes
    tmux select-layout -t "$SESSION_NAME:0" main-vertical
fi

echo -e "${GREEN}Monitor session created!${NC}"
echo ""
echo -e "Attaching to tmux session. Controls:"
echo -e "  ${YELLOW}Ctrl+B, arrow keys${NC} - Switch panes"
echo -e "  ${YELLOW}Ctrl+B, z${NC}          - Zoom current pane"
echo -e "  ${YELLOW}Ctrl+B, d${NC}          - Detach (session keeps running)"
echo -e "  ${YELLOW}Ctrl+C${NC}             - Stop tail in current pane"
echo ""
echo -e "To reattach later: ${BLUE}tmux attach -t $SESSION_NAME${NC}"
echo ""

# Attach to session
tmux attach -t "$SESSION_NAME"
