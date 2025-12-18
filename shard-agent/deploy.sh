#!/bin/bash
#
# Deploy Shard Agent to an LXC container
# Usage: ./deploy.sh <shard-ip> [shard-id] [api-key]
#

set -e

SHARD_IP="${1}"
SHARD_ID="${2:-shard-general-01}"
API_KEY="${3:-$(openssl rand -hex 32)}"

if [ -z "$SHARD_IP" ]; then
    echo "Usage: ./deploy.sh <shard-ip> [shard-id] [api-key]"
    echo ""
    echo "Example: ./deploy.sh 173.231.12.84 shard-general-01"
    exit 1
fi

echo "=============================================="
echo "Deploying Shard Agent"
echo "=============================================="
echo "Target:    root@${SHARD_IP}"
echo "Shard ID:  ${SHARD_ID}"
echo "API Key:   ${API_KEY:0:8}..."
echo "=============================================="
echo ""

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "[1/5] Creating tarball..."
cd "${SCRIPT_DIR}/.."
tar -czvf /tmp/shard-agent.tar.gz shard-agent/

echo ""
echo "[2/5] Copying to shard..."
scp /tmp/shard-agent.tar.gz root@${SHARD_IP}:/tmp/

echo ""
echo "[3/5] Installing on shard..."
ssh root@${SHARD_IP} "
set -e

# Source nvm if it exists (for non-interactive shells)
export NVM_DIR=\"\$HOME/.nvm\"
[ -s \"\$NVM_DIR/nvm.sh\" ] && . \"\$NVM_DIR/nvm.sh\"

# Create directories
mkdir -p /var/lib/claude-jobs

# Extract (creates /opt/shard-agent/)
cd /opt
rm -rf shard-agent 2>/dev/null || true
tar -xzf /tmp/shard-agent.tar.gz

# Install dependencies
cd /opt/shard-agent
npm install --production

# Create .env file
echo 'SHARD_PORT=3500
SHARD_HOST=0.0.0.0
SHARD_API_KEY=${API_KEY}
SHARD_ID=${SHARD_ID}
SHARD_TYPE=general
MAX_CONCURRENT_JOBS=2
JOB_TIMEOUT_MS=600000
WORKSPACE_PATH=/var/lib/claude-jobs
CLAUDE_CODE_PATH=claude
CLEANUP_AFTER_JOB=true
CAPABILITIES=git,filesystem' > .env

# Install systemd service
cp /opt/shard-agent/claude-shard-agent.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable claude-shard-agent
systemctl restart claude-shard-agent

echo 'Service installed and started'
"

echo ""
echo "[4/5] Waiting for service to start..."
sleep 3

echo ""
echo "[5/5] Testing health endpoint..."
HEALTH=$(curl -s http://${SHARD_IP}:3500/health)
echo "${HEALTH}" | python3 -m json.tool 2>/dev/null || echo "${HEALTH}"

echo ""
echo "=============================================="
echo "Deployment Complete!"
echo "=============================================="
echo ""
echo "Shard URL:  http://${SHARD_IP}:3500"
echo "Shard ID:   ${SHARD_ID}"
echo "API Key:    ${API_KEY}"
echo ""
echo "Save this API key - you'll need it to configure MyCTOBot!"
echo ""
echo "Test commands:"
echo "  curl http://${SHARD_IP}:3500/health"
echo "  curl -H 'Authorization: Bearer ${API_KEY}' http://${SHARD_IP}:3500/capabilities"
echo ""
