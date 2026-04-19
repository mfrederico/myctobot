#!/bin/bash
# Sync MyCTOBot code to shard servers
# Usage: ./sync-to-shards.sh [shard-ip]

EXCLUDES="--exclude=.git --exclude=vendor --exclude=node_modules --exclude=database/*.db --exclude=database/*.sqlite --exclude=log/* --exclude=conf/config.ini"
SOURCE_DIR="$(git rev-parse --show-toplevel)/"

# Default shard or use argument (override: export SHARD_IP or pass as $1)
SHARD_IP="${1:-${SHARD_IP:-your-shard-host.example.com}}"
DEST_DIR="/var/www/html/default/myctobot/"

echo "[$(date)] Syncing to shard: $SHARD_IP"
rsync -az --delete $EXCLUDES "$SOURCE_DIR" "root@$SHARD_IP:$DEST_DIR"
echo "[$(date)] Sync complete"
