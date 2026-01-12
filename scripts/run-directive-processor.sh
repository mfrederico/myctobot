#!/bin/bash
#
# Directive Processor Daemon
# Runs the CEO directive processor in a tmux session
#
# Usage:
#   ./scripts/run-directive-processor.sh --tenant=footest4 [--interval=300]
#   ./scripts/run-directive-processor.sh --tenant=gwt --attach
#
# Options:
#   --tenant=<name>     Required. Tenant slug (e.g., gwt, footest4)
#   --interval=<secs>   Seconds between runs (default: 300 = 5 minutes)
#   --attach            Attach to tmux session after starting
#   --kill              Kill existing session for this tenant
#   --status            Show status of running sessions
#
# The script creates a tmux session named: directive-processor-{tenant}
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
            head -25 "$0" | tail -22
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

# Create the processor loop script
LOOP_SCRIPT=$(cat <<'SCRIPT'
#!/bin/bash
TENANT="$1"
INTERVAL="$2"
BASE_DIR="$3"

cd "$BASE_DIR"

echo "=========================================="
echo "Directive Processor Daemon"
echo "=========================================="
echo "Tenant:   $TENANT"
echo "Interval: ${INTERVAL}s"
echo "Started:  $(date)"
echo "=========================================="
echo ""
echo "Press Ctrl+C to stop, or detach with Ctrl+B D"
echo ""

run_count=0
while true; do
    run_count=$((run_count + 1))
    echo ""
    echo "--- Run #${run_count} at $(date '+%Y-%m-%d %H:%M:%S') ---"

    php scripts/cron-directive-processor.php --tenant="$TENANT" --verbose

    echo ""
    echo "Next run in ${INTERVAL} seconds... (Ctrl+C to stop)"
    sleep "$INTERVAL"
done
SCRIPT
)

# Create tmux session with the loop
echo "Starting directive processor for tenant: $TENANT"
echo "Session: $SESSION_NAME"
echo "Interval: ${INTERVAL}s"

tmux new-session -d -s "$SESSION_NAME" "bash -c '$LOOP_SCRIPT' _ '$TENANT' '$INTERVAL' '$BASE_DIR'"

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
