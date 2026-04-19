#!/bin/bash
#
# LinkedIn Browser Management
# Manages Xvfb + x11vnc + Chromium for LinkedIn browser automation
#
# Usage:
#   ./scripts/linkedin-browser.sh start [workspace]  - Start Xvfb + VNC
#   ./scripts/linkedin-browser.sh stop               - Stop everything
#   ./scripts/linkedin-browser.sh status [workspace]  - Check if running
#   ./scripts/linkedin-browser.sh vnc                 - Show VNC connection info
#   ./scripts/linkedin-browser.sh login [workspace]   - Start browser for manual LinkedIn login
#
# Connect via ssvnc:
#   ssvncviewer localhost:5999
#
# From remote (SSH tunnel):
#   ssh -L 5999:localhost:5999 user@server
#   ssvncviewer localhost:5999

WORKSPACE="${2:-dev}"
DISPLAY_NUM=99
VNC_PORT=5999
BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PROFILE_DIR="$BASE_DIR/data/linkedin-profile-${WORKSPACE}"
PIDFILE_DIR="/tmp/linkedin-browser"
XVFB_PID="$PIDFILE_DIR/xvfb.pid"
VNC_PID="$PIDFILE_DIR/x11vnc.pid"
CHROME_PID="$PIDFILE_DIR/chrome-${WORKSPACE}.pid"

mkdir -p "$PIDFILE_DIR"
mkdir -p "$PROFILE_DIR"

start_xvfb() {
    if [ -f "$XVFB_PID" ] && kill -0 "$(cat "$XVFB_PID")" 2>/dev/null; then
        echo "Xvfb already running (PID $(cat "$XVFB_PID"))"
        return 0
    fi
    echo "Starting Xvfb on :$DISPLAY_NUM..."
    Xvfb :$DISPLAY_NUM -screen 0 1280x1024x24 -ac &
    echo $! > "$XVFB_PID"
    sleep 1
    if kill -0 "$(cat "$XVFB_PID")" 2>/dev/null; then
        echo "Xvfb started (PID $(cat "$XVFB_PID"))"
    else
        echo "ERROR: Xvfb failed to start"
        return 1
    fi
}

start_vnc() {
    if [ -f "$VNC_PID" ] && kill -0 "$(cat "$VNC_PID")" 2>/dev/null; then
        echo "x11vnc already running (PID $(cat "$VNC_PID"))"
        return 0
    fi
    echo "Starting x11vnc on localhost:$VNC_PORT..."
    x11vnc -display :$DISPLAY_NUM -rfbport $VNC_PORT -listen localhost -forever -nopw -bg
    # x11vnc backgrounds itself with -bg, find its PID
    sleep 1
    VNC_ACTUAL_PID=$(pgrep -f "x11vnc.*:$DISPLAY_NUM.*$VNC_PORT" | head -1)
    if [ -n "$VNC_ACTUAL_PID" ]; then
        echo "$VNC_ACTUAL_PID" > "$VNC_PID"
        echo "x11vnc started (PID $VNC_ACTUAL_PID)"
    else
        echo "ERROR: x11vnc failed to start"
        return 1
    fi
}

start_chrome() {
    if [ -f "$CHROME_PID" ] && kill -0 "$(cat "$CHROME_PID")" 2>/dev/null; then
        echo "Chrome already running (PID $(cat "$CHROME_PID"))"
        return 0
    fi
    echo "Starting Chrome with persistent profile..."
    DISPLAY=:$DISPLAY_NUM google-chrome \
        --user-data-dir="$PROFILE_DIR" \
        --no-first-run \
        --no-default-browser-check \
        --disable-background-networking \
        --disable-sync \
        --no-sandbox \
        --disable-gpu \
        --remote-debugging-port=9222 \
        --window-size=1280,1024 \
        "https://www.linkedin.com" &
    echo $! > "$CHROME_PID"
    sleep 2
    if kill -0 "$(cat "$CHROME_PID")" 2>/dev/null; then
        echo "Chrome started (PID $(cat "$CHROME_PID"))"
    else
        echo "WARNING: Chrome process may have forked - check manually"
    fi
}

stop_all() {
    echo "Stopping LinkedIn browser stack..."
    for pidfile in "$CHROME_PID" "$VNC_PID" "$XVFB_PID"; do
        if [ -f "$pidfile" ]; then
            pid=$(cat "$pidfile")
            if kill -0 "$pid" 2>/dev/null; then
                kill "$pid" 2>/dev/null
                echo "Stopped PID $pid ($(basename "$pidfile" .pid))"
            fi
            rm -f "$pidfile"
        fi
    done
    # Clean up any strays
    pkill -f "Xvfb :$DISPLAY_NUM" 2>/dev/null
    pkill -f "x11vnc.*:$DISPLAY_NUM" 2>/dev/null
}

status() {
    echo "LinkedIn Browser Status (workspace: $WORKSPACE):"
    echo "================================================="
    for name in xvfb x11vnc; do
        pidfile="$PIDFILE_DIR/$name.pid"
        if [ -f "$pidfile" ] && kill -0 "$(cat "$pidfile")" 2>/dev/null; then
            echo "  $name: running (PID $(cat "$pidfile"))"
        else
            echo "  $name: stopped"
        fi
    done
    # Chrome is per-workspace
    if [ -f "$CHROME_PID" ] && kill -0 "$(cat "$CHROME_PID")" 2>/dev/null; then
        echo "  chrome [$WORKSPACE]: running (PID $(cat "$CHROME_PID"))"
    else
        echo "  chrome [$WORKSPACE]: stopped"
    fi
    echo ""
    echo "Profile dir: $PROFILE_DIR"
    if [ -d "$PROFILE_DIR/Default" ]; then
        echo "Profile: exists (has Default/ directory)"
        if [ -f "$PROFILE_DIR/Default/Cookies" ]; then
            echo "Cookies: present"
        else
            echo "Cookies: not yet created (need to login)"
        fi
    else
        echo "Profile: fresh (no login yet)"
    fi
    echo ""
    # Show all workspace profiles
    echo "All workspace profiles:"
    for dir in "$BASE_DIR"/data/linkedin-profile-*/; do
        [ -d "$dir" ] || continue
        ws=$(basename "$dir" | sed 's/linkedin-profile-//')
        has_cookies="no cookies"
        [ -f "$dir/Default/Cookies" ] && has_cookies="has cookies"
        echo "  $ws: $has_cookies"
    done
}

case "${1:-status}" in
    start)
        start_xvfb && start_vnc
        echo ""
        echo "Ready. Connect with: ssvncviewer localhost:$VNC_PORT"
        echo "Then run: $0 login"
        ;;
    login)
        start_xvfb && start_vnc && start_chrome
        echo ""
        echo "Browser launched with LinkedIn (workspace: $WORKSPACE)."
        echo "Connect with: ssvncviewer localhost:$VNC_PORT"
        echo "Log in manually, then close this script."
        echo "Session cookies will persist in: $PROFILE_DIR"
        ;;
    stop)
        stop_all
        ;;
    status)
        status
        ;;
    vnc)
        echo "Local:  ssvncviewer localhost:$VNC_PORT"
        echo "Remote: ssh -L $VNC_PORT:localhost:$VNC_PORT user@server && ssvncviewer localhost:$VNC_PORT"
        ;;
    *)
        echo "Usage: $0 {start|stop|status|login|vnc} [workspace]"
        exit 1
        ;;
esac
