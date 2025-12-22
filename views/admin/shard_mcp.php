<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="/admin/shards">Workstations</a></li>
                    <li class="breadcrumb-item"><a href="/admin/editshard/<?= $shard['id'] ?>"><?= htmlspecialchars($shard['name']) ?></a></li>
                    <li class="breadcrumb-item active">MCP Servers</li>
                </ol>
            </nav>
            <h1 class="h2 mb-0">
                <i class="bi bi-plug"></i> MCP Servers
            </h1>
        </div>
        <a href="/admin/editshard/<?= $shard['id'] ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Workstation
        </a>
    </div>

    <?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Workstation: <?= htmlspecialchars($shard['name']) ?></h5>
        </div>
        <div class="card-body">
            <p class="text-muted mb-0">
                <?php if (!empty($ssh_mode)): ?>
                <strong>Mode:</strong> <span class="badge bg-primary"><i class="bi bi-terminal"></i> SSH + Tmux</span>
                &nbsp;|&nbsp;
                <strong>Host:</strong> <code><?= htmlspecialchars($shard['ssh_user'] ?? 'claudeuser') ?>@<?= htmlspecialchars($shard['host']) ?></code>
                <?php else: ?>
                <strong>Mode:</strong> <span class="badge bg-secondary"><i class="bi bi-cloud"></i> HTTP API</span>
                &nbsp;|&nbsp;
                <strong>Host:</strong> <code><?= htmlspecialchars($shard['host']) ?>:<?= $shard['port'] ?></code>
                <?php endif; ?>
                &nbsp;|&nbsp;
                <strong>Type:</strong> <?= htmlspecialchars($shard['shard_type']) ?>
                &nbsp;|&nbsp;
                <strong>Status:</strong>
                <span class="badge <?= $shard['health_status'] === 'healthy' ? 'bg-success' : 'bg-secondary' ?>">
                    <?= $shard['health_status'] ?>
                </span>
            </p>
        </div>
    </div>

    <?php if (!empty($ssh_mode)): ?>
    <!-- SSH Mode: MCP is configured dynamically -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-terminal"></i> SSH Mode - Dynamic MCP Configuration</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb-4">
                        <i class="bi bi-info-circle"></i>
                        <strong>How MCP works in SSH mode:</strong>
                        <p class="mb-0 mt-2">
                            When a job starts on this workstation, the system automatically generates a
                            <code>.mcp.json</code> file with the member's credentials. This ensures each job
                            has proper access to Jira, GitHub, and other services without storing credentials
                            on the workstation.
                        </p>
                    </div>

                    <h6 class="mb-3">MCP Servers Configured Per-Job:</h6>

                    <div class="card mb-3 border-success">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-check-circle text-success"></i>
                                <strong>jira</strong>
                                <span class="badge bg-success ms-2">Auto-configured</span>
                            </div>
                            <code class="small">mcp-jira-server.php</code>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-2">Jira integration for reading/updating issues, adding comments, and transitioning statuses.</p>
                            <p class="small mb-0">
                                <strong>Credentials used:</strong> Member's OAuth token (auto-refreshed)
                            </p>
                        </div>
                    </div>

                    <div class="card mb-3 border-success">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-check-circle text-success"></i>
                                <strong>github</strong>
                                <span class="badge bg-success ms-2">Auto-configured</span>
                            </div>
                            <code class="small">@modelcontextprotocol/server-github</code>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-2">GitHub integration for repository operations, PR creation, and issue management.</p>
                            <p class="small mb-0">
                                <strong>Credentials used:</strong> Member's GitHub token from board settings
                            </p>
                        </div>
                    </div>

                    <div class="card mb-3 border-secondary">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-dash-circle text-secondary"></i>
                                <strong>filesystem</strong>
                                <span class="badge bg-secondary ms-2">Built-in</span>
                            </div>
                            <code class="small">Claude Code native</code>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-0">File system access is built into Claude Code - no MCP server needed.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-code-slash"></i> Example .mcp.json Generated</h5>
                </div>
                <div class="card-body">
                    <pre class="bg-dark text-light p-3 rounded mb-0"><code>{
  "mcpServers": {
    "jira": {
      "command": "php",
      "args": ["/path/to/mcp-jira-server.php"],
      "env": {
        "JIRA_CLOUD_ID": "[member's cloud ID]",
        "JIRA_ACCESS_TOKEN": "[member's OAuth token]",
        "JIRA_MEMBER_ID": "[member ID]"
      }
    },
    "github": {
      "command": "npx",
      "args": ["-y", "@modelcontextprotocol/server-github"],
      "env": {
        "GITHUB_PERSONAL_ACCESS_TOKEN": "[from board settings]"
      }
    }
  }
}</code></pre>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-lightbulb"></i> How It Works</h5>
                </div>
                <div class="card-body">
                    <ol class="small mb-0">
                        <li class="mb-2">Job is assigned to this workstation</li>
                        <li class="mb-2">System SSHs in and clones the repository</li>
                        <li class="mb-2"><code>.mcp.json</code> is generated with member's credentials</li>
                        <li class="mb-2">Claude Code is launched in a tmux session</li>
                        <li class="mb-2">Claude uses MCP servers to interact with Jira/GitHub</li>
                        <li>Job completes and credentials are cleaned up</li>
                    </ol>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-shield-check"></i> Security Benefits</h5>
                </div>
                <div class="card-body">
                    <ul class="small mb-0">
                        <li class="mb-2">Credentials never stored on workstation</li>
                        <li class="mb-2">OAuth tokens auto-refreshed per job</li>
                        <li class="mb-2">Each job gets isolated credentials</li>
                        <li>Credentials cleaned up after job completes</li>
                    </ul>
                </div>
            </div>

            <div class="d-grid">
                <a href="/admin/shards" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Workstations
                </a>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- HTTP Mode: Configure via remote API -->
    <form method="POST">
        <?php foreach (Flight::csrf()->getFields() as $name => $value): ?>
        <input type="hidden" name="<?= $name ?>" value="<?= htmlspecialchars($value) ?>">
        <?php endforeach; ?>

        <div class="row">
            <!-- Available MCP Servers -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-box-seam"></i> Available MCP Servers</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($available_servers as $server): ?>
                        <?php
                            $name = $server['name'];
                            $isEnabled = isset($mcp_servers[$name]);
                            $currentConfig = $mcp_servers[$name] ?? [];
                            $args = implode(' ', $currentConfig['args'] ?? ['-y', $server['package']]);
                            $envVars = [];
                            if (!empty($currentConfig['env'])) {
                                foreach ($currentConfig['env'] as $k => $v) {
                                    $envVars[] = "$k=$v";
                                }
                            }
                        ?>
                        <div class="card mb-3 <?= $isEnabled ? 'border-success' : '' ?>">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox"
                                               id="mcp_<?= $name ?>_enabled"
                                               name="mcp[<?= $name ?>][enabled]"
                                               value="1"
                                               <?= $isEnabled ? 'checked' : '' ?>
                                               onchange="toggleMcpServer('<?= $name ?>')">
                                        <label class="form-check-label fw-bold" for="mcp_<?= $name ?>_enabled">
                                            <?= htmlspecialchars($name) ?>
                                        </label>
                                    </div>
                                    <small class="text-muted"><?= htmlspecialchars($server['description']) ?></small>
                                </div>
                                <code class="small"><?= htmlspecialchars($server['package']) ?></code>
                            </div>
                            <div class="card-body" id="mcp_<?= $name ?>_config" style="<?= $isEnabled ? '' : 'display:none' ?>">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label small">Command</label>
                                        <input type="text" class="form-control form-control-sm"
                                               name="mcp[<?= $name ?>][command]"
                                               value="<?= htmlspecialchars($currentConfig['command'] ?? 'npx') ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label small">Arguments</label>
                                        <input type="text" class="form-control form-control-sm"
                                               name="mcp[<?= $name ?>][args]"
                                               value="<?= htmlspecialchars($args) ?>"
                                               placeholder="-y <?= $server['package'] ?>">
                                    </div>
                                </div>
                                <?php if (!empty($server['env_vars'])): ?>
                                <div class="mb-2">
                                    <label class="form-label small">Environment Variables</label>
                                    <textarea class="form-control form-control-sm font-monospace" rows="2"
                                              name="mcp[<?= $name ?>][env]"
                                              placeholder="<?= implode("=\n", $server['env_vars']) ?>="><?= htmlspecialchars(implode("\n", $envVars)) ?></textarea>
                                    <div class="form-text">One per line: KEY=value</div>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($server['args_hint'])): ?>
                                <div class="form-text">
                                    <i class="bi bi-info-circle"></i> <?= htmlspecialchars($server['args_hint']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <!-- Custom MCP Server -->
                        <div class="card mb-3 border-dashed">
                            <div class="card-header">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox"
                                           id="mcp_custom_enabled"
                                           name="mcp[custom][enabled]"
                                           value="1"
                                           onchange="toggleMcpServer('custom')">
                                    <label class="form-check-label fw-bold" for="mcp_custom_enabled">
                                        Custom MCP Server
                                    </label>
                                </div>
                                <small class="text-muted">Add a custom MCP server not in the list above</small>
                            </div>
                            <div class="card-body" id="mcp_custom_config" style="display:none">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label small">Server Name</label>
                                        <input type="text" class="form-control form-control-sm"
                                               id="custom_server_name"
                                               placeholder="my-server">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label small">Command</label>
                                        <input type="text" class="form-control form-control-sm"
                                               name="mcp[custom][command]"
                                               value="npx">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label small">Arguments</label>
                                        <input type="text" class="form-control form-control-sm"
                                               name="mcp[custom][args]"
                                               placeholder="-y @scope/package-name">
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Environment Variables</label>
                                    <textarea class="form-control form-control-sm font-monospace" rows="2"
                                              name="mcp[custom][env]"
                                              placeholder="API_KEY=your-key"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Current Configuration -->
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-gear"></i> Current Configuration</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($mcp_servers)): ?>
                        <p class="text-muted mb-0">No MCP servers configured</p>
                        <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($mcp_servers as $name => $config): ?>
                            <li class="mb-2">
                                <i class="bi bi-check-circle text-success"></i>
                                <strong><?= htmlspecialchars($name) ?></strong>
                                <br>
                                <code class="small"><?= htmlspecialchars($config['command'] ?? 'npx') ?> <?= htmlspecialchars(implode(' ', $config['args'] ?? [])) ?></code>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-lightbulb"></i> Tips</h5>
                    </div>
                    <div class="card-body">
                        <ul class="small mb-0">
                            <li class="mb-2">MCP servers extend Claude Code's capabilities</li>
                            <li class="mb-2">Environment variables can use <code>${VAR}</code> syntax to reference job-provided values</li>
                            <li class="mb-2">GitHub token is automatically passed as <code>GITHUB_TOKEN</code></li>
                            <li>Changes take effect on next job execution</li>
                        </ul>
                    </div>
                </div>

                <div class="d-grid gap-2 mt-4">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-save"></i> Save Configuration
                    </button>
                    <a href="/admin/shards" class="btn btn-outline-secondary">
                        Cancel
                    </a>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
function toggleMcpServer(name) {
    const checkbox = document.getElementById('mcp_' + name + '_enabled');
    const config = document.getElementById('mcp_' + name + '_config');

    if (checkbox.checked) {
        config.style.display = 'block';
    } else {
        config.style.display = 'none';
    }
}
</script>
