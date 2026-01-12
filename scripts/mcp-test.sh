#!/bin/bash
#
# MCP CLI Test Tool
# Usage: ./scripts/mcp-test.sh <tool_name> [args_json]
#
# Examples:
#   ./scripts/mcp-test.sh jira_get_issue '{"issue_key": "SSI-1913"}'
#   ./scripts/mcp-test.sh jira_add_comment '{"issue_key": "SSI-1913", "comment": "Test"}'
#   ./scripts/mcp-test.sh jira_get_transitions '{"issue_key": "SSI-1913"}'
#   ./scripts/mcp-test.sh tools/list
#

# Configuration
MCP_URL="${MCP_URL:-https://myctobot.ai/mcp/gwt/jira}"
MEMBER_ID="${MCP_MEMBER_ID:-3}"
CLOUD_ID="${MCP_CLOUD_ID:-cb1fabf7-9018-49bb-90c7-afa23343dbe5}"

# Parse arguments
TOOL_NAME="$1"
ARGS_JSON="${2:-{}}"

if [ -z "$TOOL_NAME" ]; then
    echo "MCP CLI Test Tool"
    echo ""
    echo "Usage: $0 <tool_name> [args_json]"
    echo ""
    echo "Available tools:"
    echo "  jira_get_issue        - Get issue details"
    echo "  jira_add_comment      - Add comment to issue"
    echo "  jira_transition       - Transition issue status"
    echo "  jira_get_transitions  - List available transitions"
    echo "  jira_get_attachment   - Download attachment"
    echo "  jira_upload_attachment - Upload attachment"
    echo "  tools/list            - List all available tools"
    echo ""
    echo "Examples:"
    echo "  $0 jira_get_issue '{\"issue_key\": \"SSI-1913\"}'"
    echo "  $0 jira_add_comment '{\"issue_key\": \"SSI-1913\", \"comment\": \"Test comment\"}'"
    echo "  $0 tools/list"
    echo ""
    echo "Environment variables:"
    echo "  MCP_URL       - MCP endpoint URL (default: $MCP_URL)"
    echo "  MCP_MEMBER_ID - Member ID (default: $MEMBER_ID)"
    echo "  MCP_CLOUD_ID  - Jira Cloud ID (default: $CLOUD_ID)"
    exit 1
fi

# Handle special case for tools/list
if [ "$TOOL_NAME" = "tools/list" ]; then
    REQUEST='{"jsonrpc": "2.0", "id": 1, "method": "tools/list", "params": {}}'
else
    # Build JSON request using printf to avoid shell escaping issues
    REQUEST=$(printf '{"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "%s", "arguments": %s}}' "$TOOL_NAME" "$ARGS_JSON")
fi

# Make the request
RESPONSE=$(curl -s -L -X POST "$MCP_URL" \
    -H "Content-Type: application/json" \
    -H "X-MCP-Member-ID: $MEMBER_ID" \
    -H "X-MCP-Cloud-ID: $CLOUD_ID" \
    -d "$REQUEST")

# Pretty print with jq if available
if command -v jq &> /dev/null; then
    echo "$RESPONSE" | jq .
else
    echo "$RESPONSE"
fi
