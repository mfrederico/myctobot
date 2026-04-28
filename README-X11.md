# X11 Virtual Display + VNC Setup

Remote GUI access via Xvfb + x11vnc + twm for browser testing and visual debugging.

## Architecture

```
Xvfb :99 (virtual framebuffer, 1280x1024)
  └── twm (window manager)
       └── google-chrome (or other GUI apps)
            └── x11vnc :5999 (VNC server, localhost only)
                 └── SSH tunnel → your local VNC viewer
```

## Quick Start

### Connect from your local machine

```bash
# SSH tunnel (run on your local machine)
ssh -C -L5999:localhost:5999 ubuntu@myctobot.ai

# Then open VNC viewer to: localhost:5999
# No password required (x11vnc runs with -nopw on localhost)
```

### Start everything (on the server)

```bash
# 1. Start virtual display
Xvfb :99 -screen 0 1280x1024x24 -ac &

# 2. Start window manager
DISPLAY=:99 twm &

# 3. Start VNC server (localhost only)
x11vnc -display :99 -rfbport 5999 -listen localhost -forever -nopw -bg

# 4. Start a browser (maximized to fill the virtual screen)
DISPLAY=:99 google-chrome --no-sandbox --disable-gpu --start-maximized --window-size=1280,1024 --window-position=0,0 https://shipcannon.myctobot.ai &
```

## Troubleshooting

### VNC not connecting / black screen

Check if all three processes are running:

```bash
ps aux | grep -E "Xvfb|x11vnc|twm" | grep -v grep
```

Expected output: 3 processes (Xvfb, x11vnc, twm).

If any are missing, restart them in order:

```bash
# Kill stale processes
pkill -f "Xvfb :99" 2>/dev/null
pkill -f "x11vnc.*5999" 2>/dev/null
pkill -f twm 2>/dev/null
sleep 2

# Restart
Xvfb :99 -screen 0 1280x1024x24 -ac &
sleep 1
DISPLAY=:99 twm &
sleep 1
x11vnc -display :99 -rfbport 5999 -listen localhost -forever -nopw -bg
```

### VNC connects but screen is frozen / stale

The processes may have been running too long (weeks). Restart all:

```bash
pkill -9 -f "Xvfb :99"
pkill -9 -f "x11vnc.*5999"
pkill -9 -f twm
sleep 2

Xvfb :99 -screen 0 1280x1024x24 -ac &
sleep 1
DISPLAY=:99 twm &
sleep 1
x11vnc -display :99 -rfbport 5999 -listen localhost -forever -nopw -bg
```

### Browser stuck in top-left corner / can't resize

twm is not running. Start it:

```bash
DISPLAY=:99 twm &
```

### Port 5999 already in use

```bash
ss -tlnp | grep 5999
# Kill whatever is holding it
kill $(lsof -t -i:5999) 2>/dev/null
sleep 1
x11vnc -display :99 -rfbport 5999 -listen localhost -forever -nopw -bg
```

### Take a screenshot without VNC

```bash
DISPLAY=:99 import -window root /tmp/vnc-screenshot.png
```

### Check display is working

```bash
DISPLAY=:99 xdpyinfo | head -5
```

## Dependencies

- `xvfb` — virtual framebuffer
- `x11vnc` — VNC server
- `twm` — window manager (install: `sudo apt install twm`)
- `google-chrome` — browser
- `x11-apps` — includes `import` for screenshots

## Notes

- VNC listens on **localhost only** — must use SSH tunnel for access
- No password set — security relies on SSH authentication
- twm font warnings are harmless (missing charset fonts)
- Processes may go stale after weeks — restart if unresponsive
- Playwright MCP browsers run headless separately and don't appear on VNC
