<?php
/**
 * @step_type: mcp_call
 * @category: integration
 * @label: MCP Call
 * @icon: bi-plug
 * @color: info
 * @description: Call a tool on an MCP server
 *
 * Required view data: $mcpServers
 */
?>
<div class="config-panel" id="config_mcp_call" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">MCP Server</label>
                        <select class="form-select" name="config_mcp_server_id" onchange="onMcpServerChange(this)">
                            <option value="">-- Inline Config (no server) --</option>
                            <?php foreach ($mcpServers ?? [] as $server): ?>
                            <option value="<?= $server['id'] ?>" data-type="<?= $server['server_type'] ?>">
                                <?= h($server['name']) ?> (<?= $server['server_type'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Select a configured MCP server or use inline config</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Tool Name</label>
                        <input type="text" class="form-control font-monospace" name="config_mcp_tool" placeholder="echo">
                        <small class="text-muted">The tool to call on the MCP server</small>
                    </div>
                </div>
            </div>

            <div id="mcpInlineConfig">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Transport Type</label>
                            <select class="form-select" name="config_mcp_transport" onchange="onMcpTransportChange(this)">
                                <option value="stdio">stdio (subprocess)</option>
                                <option value="http">HTTP/SSE</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6" id="mcpCommandField">
                        <div class="mb-3">
                            <label class="form-label">Command</label>
                            <input type="text" class="form-control font-monospace" name="config_mcp_command"
                                   placeholder="python scripts/test-mcp-server.py">
                            <small class="text-muted">Command to start the MCP server</small>
                        </div>
                    </div>
                    <div class="col-md-6" id="mcpUrlField" style="display: none;">
                        <div class="mb-3">
                            <label class="form-label">URL</label>
                            <input type="text" class="form-control font-monospace" name="config_mcp_url"
                                   placeholder="http://localhost:8080/mcp">
                            <small class="text-muted">MCP server endpoint URL</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Arguments (JSON)</label>
                <textarea class="form-control font-monospace" name="config_mcp_arguments" rows="3"
                          placeholder='{"message": "{context.message}"}'></textarea>
                <small class="text-muted">Tool arguments as JSON. Use <code>{context.key}</code> or <code>{step.output.key}</code> for variables</small>
            </div>

            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="config_mcp_list_tools_only" id="mcpListToolsOnly">
                <label class="form-check-label" for="mcpListToolsOnly">
                    List Tools Only (don't call, just return available tools)
                </label>
            </div>
        </div>
    </div>
</div>
