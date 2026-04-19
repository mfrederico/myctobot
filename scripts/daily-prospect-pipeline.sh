#!/bin/bash
# Daily Store Discovery Pipeline
# Discovers new ecommerce stores, analyzes for self-shipping, pushes qualified leads to CRM.
#
# Usage:
#   ./scripts/daily-prospect-pipeline.sh [--verbose]
#
# Cron (run daily at 6am):
#   0 6 * * * cd /home/ubuntu/production/myctobot && ./scripts/daily-prospect-pipeline.sh >> log/prospect-pipeline.log 2>&1

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_DIR"

VERBOSE=""
if [ "$1" = "--verbose" ]; then
    VERBOSE="--verbose"
fi

echo "=============================================="
echo "Store Discovery Pipeline - $(date '+%Y-%m-%d %H:%M:%S')"
echo "=============================================="

# Step 1: Discover new stores via Serper/Google
echo ""
echo "--- Step 1: Discovering new stores ---"
php scripts/cron/cron-prospect-discover.php --script $VERBOSE

# Step 2: Analyze discovered stores (platform detection + AI self-shipping analysis)
echo ""
echo "--- Step 2: Analyzing stores ---"
php scripts/cron/cron-prospect-analyze.php --script $VERBOSE --batch=20

# Step 3: Push qualified leads to CRM
echo ""
echo "--- Step 3: Pushing qualified leads to CRM ---"
php scripts/cron/cron-prospect-push.php --script $VERBOSE

echo ""
echo "=============================================="
echo "Pipeline complete - $(date '+%Y-%m-%d %H:%M:%S')"
echo "=============================================="
