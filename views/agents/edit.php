<?php
$isNew = empty($agent);
$agentId = $agent['id'] ?? 0;
$csrf = $csrf['csrf_token'] ?? '';
$agentName = $agent['name'] ?? '';
$runnerType = $agent['runner_type'] ?? 'claude_cli';
$runnerConfig = $agent['runner_config'] ?? [];
$mcpServers = $agent['mcp_servers'] ?? [];
$hooksConfig = $agent['hooks_config'] ?? [];
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 mb-0">
            <i class="bi bi-robot"></i>
            <?= $isNew ? 'Create Agent Profile' : 'Edit Agent: ' . htmlspecialchars($agentName) ?>
        </h1>
        <a href="/agents" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Agents
        </a>
    </div>

    <?php if (!$isNew): ?>
    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link <?= $activeTab === 'general' ? 'active' : '' ?>"
               href="/agents/edit/<?= $agentId ?>?tab=general">
                <i class="bi bi-gear"></i> General
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?= $activeTab === 'mcp' ? 'active' : '' ?>"
               href="/agents/edit/<?= $agentId ?>?tab=mcp">
                <i class="bi bi-plug"></i> MCP Servers
                <?php if (count($mcpServers) > 0): ?>
                <span class="badge bg-secondary"><?= count($mcpServers) ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?= $activeTab === 'hooks' ? 'active' : '' ?>"
               href="/agents/edit/<?= $agentId ?>?tab=hooks">
                <i class="bi bi-lightning"></i> Hooks
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <!-- Tab Content -->
    <?php if ($isNew || $activeTab === 'general'): ?>
    <!-- General Tab -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-gear"></i> General Settings
        </div>
        <div class="card-body">
            <form method="POST" action="<?= $isNew ? '/agents/store' : '/agents/update/' . $agentId ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="tab" value="general">

                <div class="mb-3">
                    <label for="name" class="form-label">Agent Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="name" name="name"
                           value="<?= htmlspecialchars($agentName) ?>" required
                           placeholder="e.g., Shopify Bot, API Worker">
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="2"
                              placeholder="Optional description of this agent's purpose"><?= htmlspecialchars($agent['description'] ?? '') ?></textarea>
                </div>

                <div class="mb-3">
                    <label for="runner_type" class="form-label">Runner Type <span class="text-danger">*</span></label>
                    <select class="form-select" id="runner_type" name="runner_type" onchange="toggleRunnerConfig()">
                        <?php foreach ($runnerTypes as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $runnerType === $value ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                        <strong>Claude CLI:</strong> Uses your local Claude Code CLI subscription<br>
                        <strong>Anthropic API:</strong> Uses API key for headless execution<br>
                        <strong>Ollama:</strong> Uses local Ollama instance with specified model
                    </div>
                </div>

                <!-- Runner-specific config -->
                <div id="config-anthropic_api" class="runner-config mb-3" style="display: none;">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6><i class="bi bi-key"></i> Anthropic API Settings</h6>
                            <div class="mb-2">
                                <label class="form-label">API Key</label>
                                <input type="password" class="form-control" name="api_key"
                                       placeholder="sk-ant-api03-...">
                                <div class="form-text">Your Anthropic API key (will be encrypted)</div>
                            </div>
                            <div class="mb-0">
                                <label class="form-label">Model</label>
                                <select class="form-select" name="model">
                                    <option value="claude-sonnet-4-20250514">Claude Sonnet 4</option>
                                    <option value="claude-opus-4-20250514">Claude Opus 4</option>
                                    <option value="claude-3-5-sonnet-20241022">Claude 3.5 Sonnet</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="config-ollama" class="runner-config mb-3" style="display: none;">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h6><i class="bi bi-cpu"></i> Ollama Settings</h6>
                            <div class="mb-2">
                                <label class="form-label">Model</label>
                                <input type="text" class="form-control" name="ollama_model"
                                       value="<?= htmlspecialchars($runnerConfig['model'] ?? 'llama3') ?>"
                                       placeholder="llama3, codellama, etc.">
                            </div>
                            <div class="mb-0">
                                <label class="form-label">Base URL</label>
                                <input type="text" class="form-control" name="base_url"
                                       value="<?= htmlspecialchars($runnerConfig['base_url'] ?? 'http://localhost:11434') ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <hr>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1"
                                   <?= ($agent['is_active'] ?? true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                            <div class="form-text">Inactive agents cannot be assigned to repos</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="is_default" name="is_default" value="1"
                                   <?= ($agent['is_default'] ?? false) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_default">Default Agent</label>
                            <div class="form-text">Used when no agent is assigned to a repo</div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i>
                        <?= $isNew ? 'Create Agent' : 'Save Changes' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($activeTab === 'mcp'): ?>
    <!-- MCP Servers Tab -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-plug"></i> MCP Servers Configuration
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                Configure MCP servers that will be available to this agent. This generates the <code>.mcp.json</code> file.
            </div>

            <form method="POST" action="/agents/update/<?= $agentId ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="tab" value="mcp">

                <div class="mb-3">
                    <label class="form-label">MCP Servers (JSON)</label>
                    <textarea class="form-control font-monospace" name="mcp_servers" rows="15"
                              placeholder='[
  {
    "name": "jira",
    "type": "http",
    "url": "https://myctobot.ai/mcp-jira/message",
    "headers": {"Authorization": "Basic xxx"}
  },
  {
    "name": "playwright",
    "type": "stdio",
    "command": "npx",
    "args": ["@playwright/mcp@latest", "--headless"]
  }
]'><?= htmlspecialchars(json_encode($mcpServers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]') ?></textarea>
                    <div class="form-text">
                        Each server needs: <code>name</code>, <code>type</code> (http or stdio), and either <code>url</code>+<code>headers</code> or <code>command</code>+<code>args</code>
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Save MCP Config
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($activeTab === 'hooks'): ?>
    <!-- Hooks Tab -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-lightning"></i> Hooks Configuration
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                Configure hooks that run before/after tool execution. This generates the <code>.claude/settings.json</code> hooks section.
            </div>

            <form method="POST" action="/agents/update/<?= $agentId ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="tab" value="hooks">

                <div class="mb-3">
                    <label class="form-label">Hooks Configuration (JSON)</label>
                    <textarea class="form-control font-monospace" name="hooks_config" rows="20"
                              placeholder='{
  "PreToolUse": [
    {
      "matcher": "Bash|Write|Edit",
      "hooks": [
        {
          "type": "command",
          "command": "php /path/to/security-check.php",
          "timeout": 5
        }
      ]
    }
  ],
  "PostToolUse": [],
  "Stop": []
}'><?= htmlspecialchars(json_encode($hooksConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></textarea>
                    <div class="form-text">
                        Hook events: <code>PreToolUse</code> (can block with exit 2), <code>PostToolUse</code>, <code>Stop</code><br>
                        Each hook needs: <code>matcher</code> (regex for tool names), <code>hooks</code> array with <code>type</code>, <code>command</code>, <code>timeout</code>
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Save Hooks Config
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleRunnerConfig() {
    const runnerType = document.getElementById('runner_type').value;
    document.querySelectorAll('.runner-config').forEach(el => el.style.display = 'none');
    const configEl = document.getElementById('config-' + runnerType);
    if (configEl) {
        configEl.style.display = 'block';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', toggleRunnerConfig);
</script>
