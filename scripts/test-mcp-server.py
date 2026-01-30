#!/usr/bin/env python3
"""
Test MCP Server - provides a simple echo tool for testing pipelines.

This is a minimal MCP server implementation using stdio transport.
It provides an "echo" tool that returns the message passed to it.

Usage:
    python3 scripts/test-mcp-server.py

The server communicates via stdin/stdout using JSON-RPC 2.0.
"""

import json
import sys


def send_response(response):
    """Send a JSON-RPC response to stdout."""
    json_str = json.dumps(response)
    sys.stdout.write(json_str + "\n")
    sys.stdout.flush()


def handle_request(request):
    """Handle incoming JSON-RPC request."""
    method = request.get("method", "")
    params = request.get("params", {})
    req_id = request.get("id")

    if method == "initialize":
        return {
            "jsonrpc": "2.0",
            "result": {
                "protocolVersion": "2024-11-05",
                "capabilities": {
                    "tools": {"listChanged": False}
                },
                "serverInfo": {
                    "name": "test-mcp-server",
                    "version": "1.0.0"
                }
            },
            "id": req_id
        }

    elif method == "notifications/initialized":
        # No response needed for notifications
        return None

    elif method == "tools/list":
        return {
            "jsonrpc": "2.0",
            "result": {
                "tools": [
                    {
                        "name": "echo",
                        "description": "Echoes back the message you send",
                        "inputSchema": {
                            "type": "object",
                            "properties": {
                                "message": {
                                    "type": "string",
                                    "description": "The message to echo back"
                                }
                            },
                            "required": ["message"]
                        }
                    }
                ]
            },
            "id": req_id
        }

    elif method == "tools/call":
        tool_name = params.get("name", "")
        arguments = params.get("arguments", {})

        if tool_name == "echo":
            message = arguments.get("message", "")
            return {
                "jsonrpc": "2.0",
                "result": {
                    "content": [
                        {
                            "type": "text",
                            "text": f"Echo: {message}"
                        }
                    ],
                    "isError": False
                },
                "id": req_id
            }
        else:
            return {
                "jsonrpc": "2.0",
                "error": {
                    "code": -32602,
                    "message": f"Unknown tool: {tool_name}"
                },
                "id": req_id
            }

    else:
        return {
            "jsonrpc": "2.0",
            "error": {
                "code": -32601,
                "message": f"Method not found: {method}"
            },
            "id": req_id
        }


def main():
    """Main loop - read JSON-RPC requests from stdin, send responses to stdout."""
    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue

        try:
            request = json.loads(line)
            response = handle_request(request)
            if response:
                send_response(response)
        except json.JSONDecodeError as e:
            send_response({
                "jsonrpc": "2.0",
                "error": {
                    "code": -32700,
                    "message": f"Parse error: {e}"
                },
                "id": None
            })
        except Exception as e:
            send_response({
                "jsonrpc": "2.0",
                "error": {
                    "code": -32603,
                    "message": f"Internal error: {e}"
                },
                "id": None
            })


if __name__ == "__main__":
    main()
