#!/bin/bash
#
# ============================================================================
# Directive Processor Daemon
# ============================================================================
# Runs the CEO directive processor in a persistent tmux session.
# Processes incoming CEO email directives and creates projects/stories.
#
# QUICK START:
# ------------
#   # Start the daemon for a tenant
#   ./scripts/run-directive-processor.sh --tenant=footest4
#
#   # Start and watch the output
#   ./scripts/run-directive-processor.sh --tenant=footest4 --attach
#
# EXAMPLES:
# ---------
#   # Start with default 5-minute interval
#   ./scripts/run-directive-processor.sh --tenant=gwt
#
#   # Start with 1-minute interval for testing
#   ./scripts/run-directive-processor.sh --tenant=footest4 --interval=60
#
#   # Check what's running
#   ./scripts/run-directive-processor.sh --status
#
#   # Stop a running daemon
#   ./scripts/run-directive-processor.sh --tenant=footest4 --kill
#
#   # Attach to see live output
#   tmux attach -t directive-processor-footest4
#
# OPTIONS:
# --------
#   --tenant=<name>     Required. Tenant slug (e.g., gwt, footest4)
#   --interval=<secs>   Seconds between runs (default: 300 = 5 minutes)
#   --attach            Attach to tmux session after starting
#   --kill              Kill existing session for this tenant
#   --status            Show status of all running directive processor sessions
#   --help              Show this help message
#
# TMUX COMMANDS (while attached):
# -------------------------------
#   Ctrl+B then D     Detach from session (keeps it running)
#   Ctrl+C            Stop the processor loop
#   Ctrl+B then [     Enter scroll mode (use arrows, q to exit)
#
# SESSION NAME:
# -------------
#   Sessions are named: directive-processor-{tenant}
#   Example: directive-processor-footest4
#
# ============================================================================
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_DIR="$(dirname "$SCRIPT_DIR")"

# Parse arguments
TENANT=""
INTERVAL=300
ATTACH=false
KILL=false
STATUS=false

for arg in "$@"; do
    case $arg in
        --tenant=*)
            TENANT="${arg#*=}"
            ;;
        --interval=*)
            INTERVAL="${arg#*=}"
            ;;
        --attach)
            ATTACH=true
            ;;
        --kill)
            KILL=true
            ;;
        --status)
            STATUS=true
            ;;
        --help)
            head -55 "$0" | tail -52
            exit 0
            ;;
    esac
done

# Show status
if [ "$STATUS" = true ]; then
    echo "Directive Processor Sessions:"
    tmux list-sessions 2>/dev/null | grep "directive-processor" || echo "  No sessions running"
    exit 0
fi

# Validate tenant
if [ -z "$TENANT" ]; then
    echo "Error: --tenant is required"
    echo "Usage: $0 --tenant=<tenant> [--interval=300] [--attach]"
    exit 1
fi

SESSION_NAME="directive-processor-${TENANT}"

# Kill existing session
if [ "$KILL" = true ]; then
    if tmux has-session -t "$SESSION_NAME" 2>/dev/null; then
        tmux kill-session -t "$SESSION_NAME"
        echo "Killed session: $SESSION_NAME"
    else
        echo "Session not found: $SESSION_NAME"
    fi
    exit 0
fi

# Check if session already exists
if tmux has-session -t "$SESSION_NAME" 2>/dev/null; then
    echo "Session already running: $SESSION_NAME"
    if [ "$ATTACH" = true ]; then
        tmux attach -t "$SESSION_NAME"
    else
        echo "Use --attach to connect, or --kill to stop it"
    fi
    exit 0
fi

# Check config file exists
CONFIG_FILE="${BASE_DIR}/conf/config.${TENANT}.ini"
if [ ! -f "$CONFIG_FILE" ]; then
    echo "Error: Config file not found: $CONFIG_FILE"
    exit 1
fi

# Create tmux session with inline loop command
echo "Starting directive processor for tenant: $TENANT"
echo "Session: $SESSION_NAME"
echo "Interval: ${INTERVAL}s"

# Use a simple inline command for tmux (more reliable than heredoc)
tmux new-session -d -s "$SESSION_NAME" \
    "cd '$BASE_DIR' && echo '=========================================='; \
     echo 'Directive Processor Daemon'; \
     echo '=========================================='; \
     echo 'Tenant:   $TENANT'; \
     echo 'Interval: ${INTERVAL}s'; \
     echo 'Started:  '\$(date); \
     echo '=========================================='; \
     echo ''; \
     echo 'Press Ctrl+C to stop, or detach with Ctrl+B D'; \
     echo ''; \
     run_count=0; \
     while true; do \
         run_count=\$((run_count + 1)); \
         echo ''; \
         echo \"--- Run #\${run_count} at \$(date '+%Y-%m-%d %H:%M:%S') ---\"; \
         php scripts/cron-directive-processor.php --tenant='$TENANT' --verbose; \
         echo ''; \
         echo 'Next run in ${INTERVAL} seconds... (Ctrl+C to stop)'; \
         sleep $INTERVAL; \
     done"

echo ""
echo "Session started successfully!"
echo ""
echo "Commands:"
echo "  Attach:  tmux attach -t $SESSION_NAME"
echo "  Detach:  Ctrl+B then D (while attached)"
echo "  Kill:    $0 --tenant=$TENANT --kill"
echo "  Status:  $0 --status"

if [ "$ATTACH" = true ]; then
    echo ""
    echo "Attaching to session..."
    sleep 1
    tmux attach -t "$SESSION_NAME"
fi
