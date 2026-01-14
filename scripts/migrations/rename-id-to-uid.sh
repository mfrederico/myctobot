#!/bin/bash
#
# Migration: Rename non-FK _id fields to _uid
#
# This script renames fields that look like foreign keys but are actually
# string identifiers (UUIDs, external IDs, etc.) to follow RedBeanPHP conventions.
#
# RedBeanPHP treats *_id as integer foreign keys, so we use *_uid for string IDs.
#
# Usage: ./scripts/migrations/rename-id-to-uid.sh [--dry-run]
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

DRY_RUN=false
if [[ "$1" == "--dry-run" ]]; then
    DRY_RUN=true
    echo "=== DRY RUN MODE - No changes will be made ==="
fi

echo "Project root: $PROJECT_ROOT"
echo ""

# Fields to rename: old_name -> new_name
# These are NON-FK fields that RedBeanPHP incorrectly treats as integer FKs
declare -A RENAMES=(
    ["cloud_id"]="cloud_uid"
    ["job_id"]="job_uid"
    ["directive_id"]="directive_uid"
    ["project_id"]="project_uid"
    ["plugin_id"]="plugin_uid"
    ["scan_id"]="scan_uid"
    ["webhook_id"]="webhook_uid"
    ["github_user_id"]="github_user_uid"
    ["source_id"]="source_uid"
    ["current_shard_job_id"]="current_shard_job_uid"
    ["clarification_comment_id"]="clarification_comment_uid"
)

# File patterns to process
FILE_PATTERNS="*.php"

cd "$PROJECT_ROOT"

for old_name in "${!RENAMES[@]}"; do
    new_name="${RENAMES[$old_name]}"

    echo "=== Renaming: $old_name -> $new_name ==="

    # Count occurrences
    count=$(grep -r "$old_name" --include="$FILE_PATTERNS" . 2>/dev/null | wc -l)
    echo "Found $count occurrences"

    if [[ "$count" -gt 0 ]]; then
        if [[ "$DRY_RUN" == true ]]; then
            echo "Would rename $count occurrences"
            # Show sample matches
            grep -r "$old_name" --include="$FILE_PATTERNS" . 2>/dev/null | head -5
        else
            # Perform the rename using find + sed for safety
            find . -name "$FILE_PATTERNS" -type f ! -path "./vendor/*" ! -path "./.git/*" -exec \
                sed -i "s/$old_name/$new_name/g" {} +
            echo "Renamed $count occurrences"
        fi
    fi

    echo ""
done

echo "=== PHP file renaming complete ==="
echo ""

if [[ "$DRY_RUN" == false ]]; then
    echo "IMPORTANT: You must also run the SQL migration to rename database columns."
    echo "Run: mysql -u myctobot -p myctobot_gwt < scripts/migrations/rename-id-to-uid.sql"
fi
