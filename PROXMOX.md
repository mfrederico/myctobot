# Running Chrome/Playwright in Proxmox LXC Containers

## The Problem

Chrome and Chromium-based browsers fail to create network sockets inside **unprivileged** Proxmox LXC containers. Symptoms include:

- Chrome shows "No internet connection" despite the host having full connectivity
- `CreatePlatformSocket() failed: Permission denied (13)` in Chrome's error output
- `--no-sandbox` flag alone does not fix the issue
- `curl` and `ping` work fine from the container — only Chrome is broken

This affects any use case running a real browser inside an LXC container:
- Browser automation (Playwright, Puppeteer, Selenium)
- Headless Chrome for PDF generation or screenshots
- VNC-based browser sessions
- CI/CD pipelines with browser testing

## Root Cause

Proxmox applies an **AppArmor security profile** to unprivileged LXC containers that restricts certain syscalls. Chrome's internal process model requires socket and namespace operations that this profile blocks — even when Chrome's own sandbox is disabled with `--no-sandbox`.

The container's network stack works fine (curl, ping, apt all succeed), but Chrome's multi-process architecture triggers the restricted syscalls when creating its GPU process, renderer processes, and DevTools server.

## The Fix

On the **Proxmox host** (not inside the container), add one line to the container config:

```bash
# Find your container ID
pct list

# Edit the config (replace 101 with your CTID)
nano /etc/pve/lxc/101.conf
```

Add this line:

```
lxc.apparmor.profile: unconfined
```

Then restart the container:

```bash
pct restart 101
```

### Verify the Fix

Inside the container, confirm AppArmor is unconfined:

```bash
cat /proc/1/attr/current
# Should output: unconfined
```

Then test Chrome:

```bash
google-chrome --no-sandbox --headless --dump-dom https://www.google.com 2>&1 | head -5
```

## Full Container Config Example

A working config for running Chrome/Playwright in an unprivileged LXC container:

```
arch: amd64
cores: 6
features: fuse=1,keyctl=1,mknod=1,nesting=1
hostname: myserver
memory: 8192
net0: name=eth0,bridge=vmbr0,firewall=1,gw=X.X.X.X,hwaddr=XX:XX:XX:XX:XX:XX,ip=X.X.X.X/24,type=veth
onboot: 1
ostype: ubuntu
rootfs: local-lvm:vm-101-disk-0,size=50G
swap: 8192
unprivileged: 1
lxc.apparmor.profile: unconfined
```

Key settings:
- `unprivileged: 1` — container runs without root privileges on the host
- `features: nesting=1` — required for Chrome's process model
- `lxc.apparmor.profile: unconfined` — allows Chrome's socket/namespace syscalls

## Security Considerations

Setting `lxc.apparmor.profile: unconfined` removes all AppArmor restrictions for the container. This is acceptable when:

- The container is already unprivileged (limited host access regardless)
- You trust the software running inside the container
- The container has firewall rules (`firewall=1` on the network interface)

For a more targeted approach, you could create a custom AppArmor profile that only allows the specific syscalls Chrome needs, but `unconfined` is the standard solution used by most Proxmox users running browsers in containers.

## Related Software

This fix applies to any Chromium-based application in Proxmox LXC:
- Google Chrome / Chromium
- Playwright (uses Chromium)
- Puppeteer (uses Chromium)
- Electron apps
- Microsoft Edge

## Additional Requirements

For headless browser automation (Xvfb + VNC), you'll also need:

```bash
apt install xvfb x11vnc google-chrome-stable
npm install playwright
npx playwright install chromium
```
