<?php
$isNew = empty($agent);
$agentId = $agent['id'] ?? 0;
$csrf = $csrf['csrf_token'] ?? '';
$agentName = $agent['name'] ?? '';
$provider = $agent['provider'] ?? 'claude_cli';
$providerConfig = $agent['provider_config'] ?? [];
$mcpServers = $agent['mcp_servers'] ?? [];
$hooksConfig = $agent['hooks_config'] ?? [];
$agentCapabilities = $agent['capabilities'] ?? [];
$exposeAsMcp = $agent['expose_as_mcp'] ?? false;
$mcpToolName = $agent['mcp_tool_name'] ?? '';
$mcpToolDescription = $agent['mcp_tool_description'] ?? '';
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
            <a class="nav-link <?= $activeTab === 'provider' ? 'active' : '' ?>"
               href="/agents/edit/<?= $agentId ?>?tab=provider">
                <i class="bi bi-cpu"></i> Provider
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
            <?php
            $hookCount = 0;
            foreach (['PreToolUse', 'PostToolUse', 'Stop'] as $event) {
                if (!empty($hooksConfig[$event])) {
                    foreach ($hooksConfig[$event] as $rule) {
                        $hookCount += count($rule['hooks'] ?? []);
                    }
                }
            }
            ?>
            <a class="nav-link <?= $activeTab === 'hooks' ? 'active' : '' ?>"
               href="/agents/edit/<?= $agentId ?>?tab=hooks">
                <i class="bi bi-lightning"></i> Hooks
                <?php if ($hookCount > 0): ?>
                <span class="badge bg-secondary"><?= $hookCount ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?= $activeTab === 'capabilities' ? 'active' : '' ?>"
               href="/agents/edit/<?= $agentId ?>?tab=capabilities">
                <i class="bi bi-stars"></i> Capabilities
                <?php if (count($agentCapabilities) > 0): ?>
                <span class="badge bg-secondary"><?= count($agentCapabilities) ?></span>
                <?php endif; ?>
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

                <?php if ($isNew): ?>
                <!-- Provider Selection for New Agent -->
                <div class="mb-3">
                    <label for="provider" class="form-label">Provider <span class="text-danger">*</span></label>
                    <select class="form-select" id="provider_create" name="provider" onchange="updateCreateProviderConfig()">
                        <?php foreach ($providers as $p): ?>
                        <option value="<?= $p['type'] ?>" <?= $provider === $p['type'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?> - <?= htmlspecialchars($p['description']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Provider Config for New Agent (inline) -->
                <div id="create-provider-config" class="mb-3">
                    <!-- Claude CLI Config -->
                    <div class="create-provider-config" id="create-config-claude_cli">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6><i class="bi bi-terminal"></i> Claude CLI Settings</h6>
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" id="create-use-ollama" name="use_ollama"
                                           onchange="toggleCreateClaudeBackend()">
                                    <label class="form-check-label" for="create-use-ollama">
                                        <i class="bi bi-cpu"></i> Use Ollama as backend
                                    </label>
                                </div>
                                <div id="create-claude-anthropic">
                                    <label class="form-label">Model</label>
                                    <select class="form-select" name="model">
                                        <option value="sonnet" selected>Sonnet (Recommended)</option>
                                        <option value="opus">Opus</option>
                                        <option value="haiku">Haiku (Fast)</option>
                                    </select>
                                </div>
                                <div id="create-claude-ollama" style="display:none;">
                                    <div class="mb-2">
                                        <label class="form-label">Ollama Host</label>
                                        <input type="text" class="form-control" name="ollama_host"
                                               value="http://localhost:11434" placeholder="http://localhost:11434">
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label">Ollama Model</label>
                                        <input type="text" class="form-control" name="ollama_model"
                                               placeholder="qwen3-coder, codellama, llama3">
                                        <div class="form-text">You can load models after the agent is created</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ollama Direct Config -->
                    <div class="create-provider-config" id="create-config-ollama" style="display:none;">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6><i class="bi bi-cpu"></i> Ollama Settings</h6>
                                <div class="mb-2">
                                    <label class="form-label">Host URL</label>
                                    <input type="text" class="form-control" name="host"
                                           value="http://localhost:11434" placeholder="http://localhost:11434">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Model</label>
                                    <input type="text" class="form-control" name="model"
                                           placeholder="llama3, codellama, mistral">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- OpenAI Config -->
                    <div class="create-provider-config" id="create-config-openai" style="display:none;">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6><i class="bi bi-stars"></i> OpenAI Settings</h6>
                                <div class="mb-2">
                                    <label class="form-label">Model</label>
                                    <select class="form-select" name="model">
                                        <option value="gpt-4-turbo">GPT-4 Turbo</option>
                                        <option value="gpt-4o">GPT-4o</option>
                                        <option value="gpt-4o-mini">GPT-4o Mini</option>
                                    </select>
                                </div>
                                <div class="form-text">API key is configured in Settings → Connections</div>
                            </div>
                        </div>
                    </div>

                    <!-- Claude API Config -->
                    <div class="create-provider-config" id="create-config-claude_api" style="display:none;">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6><i class="bi bi-cloud"></i> Claude API Settings</h6>
                                <div class="mb-2">
                                    <label class="form-label">Model</label>
                                    <select class="form-select" name="model">
                                        <option value="claude-sonnet-4-20250514">Claude Sonnet 4</option>
                                        <option value="claude-opus-4-20250514">Claude Opus 4</option>
                                    </select>
                                </div>
                                <div class="form-text">API key is configured in Settings → Connections</div>
                            </div>
                        </div>
                    </div>

                    <!-- Custom HTTP Config -->
                    <div class="create-provider-config" id="create-config-custom_http" style="display:none;">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6><i class="bi bi-globe"></i> Custom HTTP Settings</h6>
                                <div class="mb-2">
                                    <label class="form-label">Endpoint URL</label>
                                    <input type="text" class="form-control" name="endpoint"
                                           placeholder="https://api.example.com/v1/chat/completions">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Model</label>
                                    <input type="text" class="form-control" name="model"
                                           placeholder="Model name">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- Provider Display for Existing Agent (edit on Provider tab) -->
                <div class="mb-3">
                    <label for="provider" class="form-label">Provider <span class="text-danger">*</span></label>
                    <select class="form-select" id="provider" name="provider" disabled>
                        <?php foreach ($providers as $p): ?>
                        <option value="<?= $p['type'] ?>" <?= $provider === $p['type'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                        Provider configuration is on the <strong>Provider</strong> tab.
                    </div>
                </div>
                <?php endif; ?>

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
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-plug"></i> MCP Servers Configuration</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="loadDefaultMcp()">
                <i class="bi bi-arrow-repeat"></i> Load Defaults
            </button>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                Configure <strong>additional</strong> MCP servers for this agent. <strong>Jira and Playwright are always enabled</strong> (auto-configured at runtime with your credentials).
                Use "Load Defaults" to add common servers like GitHub and Fetch.
            </div>

            <form method="POST" action="/agents/update/<?= $agentId ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="tab" value="mcp">

                <div class="mb-3">
                    <label class="form-label">MCP Servers (JSON)</label>
                    <textarea class="form-control font-monospace" id="mcp_servers" name="mcp_servers" rows="18"><?= htmlspecialchars(json_encode($mcpServers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]') ?></textarea>
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

<script>
function loadDefaultMcp() {
    // Note: Jira and Playwright are ALWAYS auto-added at runtime
    // This loads additional useful servers
    const defaultConfig = [
        {
            "name": "github",
            "type": "stdio",
            "command": "npx",
            "args": ["-y", "@modelcontextprotocol/server-github"],
            "env": {"GITHUB_PERSONAL_ACCESS_TOKEN": "${GITHUB_TOKEN}"}
        },
        {
            "name": "fetch",
            "type": "stdio",
            "command": "npx",
            "args": ["-y", "@modelcontextprotocol/server-fetch"]
        },
        {
            "name": "mantic",
            "type": "stdio",
            "command": "npx",
            "args": ["-y", "mantic-mcp"]
        }
    ];
    document.getElementById('mcp_servers').value = JSON.stringify(defaultConfig, null, 2);
}
</script>

    <?php elseif ($activeTab === 'hooks'): ?>
    <!-- Hooks Tab -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-lightning"></i> Hooks Configuration</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="loadAllHooks()">
                <i class="bi bi-arrow-repeat"></i> Load All Hooks
            </button>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                Configure hooks that run before/after tool execution. This generates the <code>.claude/settings.json</code> hooks section.
            </div>

            <!-- Language Validator Quick Setup -->
            <div class="card bg-light mb-4">
                <div class="card-header">
                    <i class="bi bi-shield-check"></i> Security Validators (Quick Setup)
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Select language validators to automatically check for OWASP security issues, coding standards, and best practices.
                        These run as PreToolUse hooks on Write/Edit operations.
                    </p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="hook_php" onchange="updateHooksFromCheckboxes()">
                                <label class="form-check-label" for="hook_php">
                                    <i class="bi bi-filetype-php text-primary"></i> <strong>PHP</strong>
                                    <small class="d-block text-muted">RedBeanPHP, FlightPHP, OWASP</small>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="hook_js" onchange="updateHooksFromCheckboxes()">
                                <label class="form-check-label" for="hook_js">
                                    <i class="bi bi-filetype-js text-warning"></i> <strong>JavaScript/TypeScript</strong>
                                    <small class="d-block text-muted">XSS, eval, Node.js security</small>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="hook_python" onchange="updateHooksFromCheckboxes()">
                                <label class="form-check-label" for="hook_python">
                                    <i class="bi bi-filetype-py text-success"></i> <strong>Python</strong>
                                    <small class="d-block text-muted">SQL injection, pickle, subprocess</small>
                                </label>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="hook_go" onchange="updateHooksFromCheckboxes()" disabled>
                                <label class="form-check-label text-muted" for="hook_go">
                                    <i class="bi bi-box"></i> Go <span class="badge bg-secondary">Coming Soon</span>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="hook_ruby" onchange="updateHooksFromCheckboxes()" disabled>
                                <label class="form-check-label text-muted" for="hook_ruby">
                                    <i class="bi bi-gem"></i> Ruby <span class="badge bg-secondary">Coming Soon</span>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="hook_liquid" onchange="updateHooksFromCheckboxes()" disabled>
                                <label class="form-check-label text-muted" for="hook_liquid">
                                    <i class="bi bi-droplet"></i> Liquid <span class="badge bg-secondary">Coming Soon</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Hooks Quick Setup -->
            <div class="card bg-light mb-4">
                <div class="card-header">
                    <i class="bi bi-gear"></i> Additional Hooks
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Additional automation hooks for commit cleanup, activity logging, and response capture.
                    </p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="hook_commit_cruft" onchange="updateHooksFromCheckboxes()">
                                <label class="form-check-label" for="hook_commit_cruft">
                                    <i class="bi bi-git text-danger"></i> <strong>Strip Commit Cruft</strong>
                                    <small class="d-block text-muted">Remove emojis and Claude signatures from commits</small>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="hook_log_activity" onchange="updateHooksFromCheckboxes()">
                                <label class="form-check-label" for="hook_log_activity">
                                    <i class="bi bi-journal-text text-info"></i> <strong>Log Activity</strong>
                                    <small class="d-block text-muted">Log file changes to AI Dev job</small>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="hook_capture_response" onchange="updateHooksFromCheckboxes()">
                                <label class="form-check-label" for="hook_capture_response">
                                    <i class="bi bi-chat-dots text-success"></i> <strong>Capture Responses</strong>
                                    <small class="d-block text-muted">Save Claude responses to job log</small>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <form method="POST" action="/agents/update/<?= $agentId ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="tab" value="hooks">

                <div class="mb-3">
                    <label class="form-label">Hooks Configuration (JSON)</label>
                    <textarea class="form-control font-monospace" id="hooks_config" name="hooks_config" rows="20"
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

<script>
// Available hooks configuration
// Note: ${MYCTOBOT_APP_ROOT} is set by local-aidev-full.php at runtime
const availableHooks = {
    // Language validators (PreToolUse on Write|Edit)
    php: {
        command: "php ${MYCTOBOT_APP_ROOT}/scripts/hooks/validate-php.php",
        timeout: 30,
        matcher: "Write|Edit"
    },
    js: {
        command: "php ${MYCTOBOT_APP_ROOT}/scripts/hooks/validate-js.php",
        timeout: 30,
        matcher: "Write|Edit"
    },
    python: {
        command: "php ${MYCTOBOT_APP_ROOT}/scripts/hooks/validate-python.php",
        timeout: 30,
        matcher: "Write|Edit"
    },
    // Commit cruft stripper (PreToolUse on Bash - git commit)
    commit_cruft: {
        command: "php ${MYCTOBOT_APP_ROOT}/scripts/hooks/strip-commit-cruft.php",
        timeout: 5,
        matcher: "Bash"
    },
    // Activity logger (PostToolUse on Write|Edit)
    log_activity: {
        command: "php ${MYCTOBOT_APP_ROOT}/scripts/hooks/log-activity.php",
        timeout: 10,
        matcher: "Write|Edit",
        event: "PostToolUse"
    },
    // Response capture (Stop hook)
    capture_response: {
        command: "php ${MYCTOBOT_APP_ROOT}/scripts/hooks/capture-response.php",
        timeout: 5,
        matcher: "",
        event: "Stop"
    }
};

function updateHooksFromCheckboxes() {
    const preToolUseValidators = [];
    const preToolUseBash = [];
    const postToolUse = [];
    const stop = [];

    // Language validators (PreToolUse on Write|Edit)
    if (document.getElementById('hook_php').checked) {
        preToolUseValidators.push({
            type: "command",
            command: availableHooks.php.command,
            timeout: availableHooks.php.timeout
        });
    }
    if (document.getElementById('hook_js').checked) {
        preToolUseValidators.push({
            type: "command",
            command: availableHooks.js.command,
            timeout: availableHooks.js.timeout
        });
    }
    if (document.getElementById('hook_python').checked) {
        preToolUseValidators.push({
            type: "command",
            command: availableHooks.python.command,
            timeout: availableHooks.python.timeout
        });
    }

    // Commit cruft stripper (PreToolUse on Bash)
    if (document.getElementById('hook_commit_cruft').checked) {
        preToolUseBash.push({
            type: "command",
            command: availableHooks.commit_cruft.command,
            timeout: availableHooks.commit_cruft.timeout
        });
    }

    // Activity logger (PostToolUse)
    if (document.getElementById('hook_log_activity').checked) {
        postToolUse.push({
            type: "command",
            command: availableHooks.log_activity.command,
            timeout: availableHooks.log_activity.timeout
        });
    }

    // Response capture (Stop)
    if (document.getElementById('hook_capture_response').checked) {
        stop.push({
            type: "command",
            command: availableHooks.capture_response.command,
            timeout: availableHooks.capture_response.timeout
        });
    }

    // Build config with proper structure
    const preToolUseRules = [];
    if (preToolUseValidators.length > 0) {
        preToolUseRules.push({
            matcher: "Write|Edit",
            hooks: preToolUseValidators
        });
    }
    if (preToolUseBash.length > 0) {
        preToolUseRules.push({
            matcher: "Bash",
            hooks: preToolUseBash
        });
    }

    const config = {
        PreToolUse: preToolUseRules,
        PostToolUse: postToolUse.length > 0 ? [{
            matcher: "Write|Edit",
            hooks: postToolUse
        }] : [],
        Stop: stop.length > 0 ? [{
            matcher: "",
            hooks: stop
        }] : []
    };

    document.getElementById('hooks_config').value = JSON.stringify(config, null, 2);
}

// Load all available hooks
function loadAllHooks() {
    document.getElementById('hook_php').checked = true;
    document.getElementById('hook_commit_cruft').checked = true;
    document.getElementById('hook_log_activity').checked = true;
    document.getElementById('hook_capture_response').checked = true;
    updateHooksFromCheckboxes();
}


// Initialize checkboxes from existing config on page load
document.addEventListener('DOMContentLoaded', function() {
    try {
        const configText = document.getElementById('hooks_config').value;
        if (!configText || configText === '{}' || configText === '[]') return;

        const config = JSON.parse(configText);

        // Check PreToolUse hooks
        (config.PreToolUse || []).forEach(rule => {
            (rule.hooks || []).forEach(hook => {
                const cmd = hook.command || '';
                if (cmd.includes('validate-php')) {
                    document.getElementById('hook_php').checked = true;
                }
                if (cmd.includes('validate-js')) {
                    document.getElementById('hook_js').checked = true;
                }
                if (cmd.includes('validate-python')) {
                    document.getElementById('hook_python').checked = true;
                }
                if (cmd.includes('strip-commit-cruft')) {
                    document.getElementById('hook_commit_cruft').checked = true;
                }
            });
        });

        // Check PostToolUse hooks
        (config.PostToolUse || []).forEach(rule => {
            (rule.hooks || []).forEach(hook => {
                const cmd = hook.command || '';
                if (cmd.includes('log-activity')) {
                    document.getElementById('hook_log_activity').checked = true;
                }
            });
        });

        // Check Stop hooks
        (config.Stop || []).forEach(rule => {
            (rule.hooks || []).forEach(hook => {
                const cmd = hook.command || '';
                if (cmd.includes('capture-response')) {
                    document.getElementById('hook_capture_response').checked = true;
                }
            });
        });
    } catch (e) {
        // Ignore parse errors
    }
});
</script>

    <?php elseif ($activeTab === 'provider'): ?>
    <!-- Provider Tab -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-cpu"></i> LLM Provider Configuration
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                Choose which LLM provider this agent uses. Different providers have different capabilities and costs.
            </div>

            <form method="POST" action="/agents/update/<?= $agentId ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="tab" value="provider">

                <div class="mb-4">
                    <label for="provider_select" class="form-label">Provider Type <span class="text-danger">*</span></label>
                    <select class="form-select" id="provider_select" name="provider" onchange="updateProviderForm()">
                        <?php foreach ($providers as $p): ?>
                        <option value="<?= $p['type'] ?>"
                                <?= $provider === $p['type'] ? 'selected' : '' ?>
                                data-can-orchestrate="<?= $p['can_orchestrate'] ? '1' : '0' ?>"
                                data-requires-api-key="<?= $p['requires_api_key'] ? '1' : '0' ?>">
                            <?= htmlspecialchars($p['name']) ?> - <?= htmlspecialchars($p['description']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Dynamic provider config form -->
                <div id="provider-config-form" class="mb-4">
                    <!-- Claude CLI -->
                    <div class="provider-config" id="config-claude_cli" style="display: none;">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6><i class="bi bi-terminal"></i> Claude CLI Settings</h6>
                                <p class="text-muted small">Uses Claude Code CLI. Can use Anthropic API or local Ollama as backend.</p>

                                <!-- Backend Toggle -->
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input provider-field" type="checkbox" role="switch"
                                               id="claude-use-ollama" name="config_use_ollama"
                                               <?= !empty($providerConfig['use_ollama']) ? 'checked' : '' ?>
                                               onchange="toggleClaudeBackend()">
                                        <label class="form-check-label" for="claude-use-ollama">
                                            <i class="bi bi-cpu"></i> Use Ollama as backend
                                        </label>
                                    </div>
                                    <div class="form-text">Run Claude Code with local Ollama models instead of Anthropic API</div>
                                </div>

                                <!-- Anthropic Backend (default) -->
                                <div id="claude-anthropic-config">
                                    <div class="mb-2">
                                        <label class="form-label">Model</label>
                                        <select class="form-select provider-field" name="config_model" id="claude-model-select">
                                            <option value="sonnet" <?= ($providerConfig['model'] ?? 'sonnet') === 'sonnet' ? 'selected' : '' ?>>Sonnet (Recommended)</option>
                                            <option value="opus" <?= ($providerConfig['model'] ?? '') === 'opus' ? 'selected' : '' ?>>Opus</option>
                                            <option value="haiku" <?= ($providerConfig['model'] ?? '') === 'haiku' ? 'selected' : '' ?>>Haiku (Fast)</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Ollama Backend -->
                                <div id="claude-ollama-config" style="display: none;">
                                    <div class="alert alert-info small py-2 mb-3">
                                        <i class="bi bi-info-circle"></i>
                                        Claude Code will run with Ollama. Requires Ollama running locally or accessible via network.
                                        <a href="https://docs.ollama.com/integrations/claude-code" target="_blank">Learn more</a>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Ollama Host</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control provider-field" name="config_ollama_host"
                                                   id="claude-ollama-host"
                                                   value="<?= htmlspecialchars($providerConfig['ollama_host'] ?? 'http://localhost:11434') ?>"
                                                   placeholder="http://localhost:11434">
                                            <button type="button" class="btn btn-outline-primary" onclick="loadClaudeOllamaModels()">
                                                <i class="bi bi-arrow-clockwise"></i> Load Models
                                            </button>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Ollama Model</label>
                                        <select class="form-select provider-field" name="config_ollama_model" id="claude-ollama-model"
                                                onchange="loadClaudeOllamaModelInfo(this.value)">
                                            <option value="">-- Click "Load Models" to fetch --</option>
                                            <?php if (!empty($providerConfig['ollama_model'])): ?>
                                            <option value="<?= htmlspecialchars($providerConfig['ollama_model']) ?>" selected>
                                                <?= htmlspecialchars($providerConfig['ollama_model']) ?>
                                            </option>
                                            <?php endif; ?>
                                        </select>
                                        <div class="form-text">
                                            Recommended: <code>qwen3-coder</code>, <code>gpt-oss:20b</code>, <code>codellama</code>
                                        </div>
                                    </div>

                                    <!-- Model Info Card for Claude+Ollama -->
                                    <div id="claude-ollama-model-info" class="mb-3" style="display:none;">
                                        <div class="card border-info">
                                            <div class="card-header bg-info bg-opacity-10 py-2">
                                                <strong id="claude-model-info-name">Model Info</strong>
                                            </div>
                                            <div class="card-body py-2 small">
                                                <div class="row">
                                                    <div class="col-6"><strong>Family:</strong> <span id="claude-model-info-family">-</span></div>
                                                    <div class="col-6"><strong>Size:</strong> <span id="claude-model-info-size">-</span></div>
                                                    <div class="col-6"><strong>Parameters:</strong> <span id="claude-model-info-params">-</span></div>
                                                    <div class="col-6"><strong>Quantization:</strong> <span id="claude-model-info-quant">-</span></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="testClaudeOllamaConnection()">
                                        <i class="bi bi-plug"></i> Test Ollama Connection
                                    </button>
                                    <span id="claude-ollama-test-result" class="ms-2"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ollama -->
                    <div class="provider-config" id="config-ollama" style="display: none;">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6><i class="bi bi-cpu"></i> Ollama Settings</h6>
                                <p class="text-muted small">Connect to a local or remote Ollama instance.</p>

                                <div class="mb-3">
                                    <label class="form-label">Host URL</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control provider-field" name="config_host" id="ollama-host"
                                               value="<?= htmlspecialchars($providerConfig['host'] ?? 'http://localhost:11434') ?>"
                                               placeholder="http://localhost:11434">
                                        <button type="button" class="btn btn-outline-primary" onclick="loadOllamaModels()">
                                            <i class="bi bi-arrow-clockwise"></i> Load Models
                                        </button>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Model</label>
                                    <select class="form-select provider-field" name="config_model" id="ollama-model"
                                            onchange="loadModelInfo(this.value)">
                                        <option value="">-- Click "Load Models" to fetch available models --</option>
                                        <?php if (!empty($providerConfig['model'])): ?>
                                        <option value="<?= htmlspecialchars($providerConfig['model']) ?>" selected>
                                            <?= htmlspecialchars($providerConfig['model']) ?>
                                        </option>
                                        <?php endif; ?>
                                    </select>
                                    <div id="ollama-models-loading" class="form-text text-muted" style="display:none;">
                                        <span class="spinner-border spinner-border-sm"></span> Loading models...
                                    </div>
                                </div>

                                <!-- Model Info Card -->
                                <div id="ollama-model-info" class="mb-3" style="display:none;">
                                    <div class="card border-info">
                                        <div class="card-header bg-info bg-opacity-10 py-2">
                                            <strong id="model-info-name">Model Info</strong>
                                        </div>
                                        <div class="card-body py-2">
                                            <div class="row small">
                                                <div class="col-6">
                                                    <strong>Family:</strong> <span id="model-info-family">-</span>
                                                </div>
                                                <div class="col-6">
                                                    <strong>Size:</strong> <span id="model-info-size">-</span>
                                                </div>
                                                <div class="col-6">
                                                    <strong>Parameters:</strong> <span id="model-info-params">-</span>
                                                </div>
                                                <div class="col-6">
                                                    <strong>Quantization:</strong> <span id="model-info-quant">-</span>
                                                </div>
                                            </div>
                                            <div id="model-info-system" class="mt-2 small" style="display:none;">
                                                <strong>System Prompt:</strong>
                                                <pre class="bg-dark text-light p-2 rounded small mb-0" style="max-height:100px;overflow:auto;"></pre>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Temperature</label>
                                        <input type="number" class="form-control provider-field" name="config_temperature"
                                               value="<?= htmlspecialchars($providerConfig['temperature'] ?? '0.7') ?>"
                                               min="0" max="2" step="0.1">
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Context Length</label>
                                        <input type="number" class="form-control provider-field" name="config_context_length"
                                               value="<?= htmlspecialchars($providerConfig['context_length'] ?? '8192') ?>"
                                               min="1024" max="131072" step="1024">
                                    </div>
                                </div>

                                <div class="d-flex gap-2 mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="testOllamaConnection()">
                                        <i class="bi bi-plug"></i> Test Connection
                                    </button>
                                    <span id="ollama-test-result" class="align-self-center"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- OpenAI -->
                    <div class="provider-config" id="config-openai" style="display: none;">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6><i class="bi bi-stars"></i> OpenAI Settings</h6>
                                <p class="text-muted small">Connect to OpenAI API.</p>
                                <div class="mb-2">
                                    <label class="form-label">API Key Setting Name</label>
                                    <input type="text" class="form-control provider-field" name="config_api_key_setting"
                                           value="<?= htmlspecialchars($providerConfig['api_key_setting'] ?? 'openai_api_key') ?>"
                                           placeholder="openai_api_key">
                                    <div class="form-text">Name of the encrypted setting in your enterprise settings</div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Model</label>
                                    <select class="form-select provider-field" name="config_model">
                                        <option value="gpt-4-turbo" <?= ($providerConfig['model'] ?? 'gpt-4-turbo') === 'gpt-4-turbo' ? 'selected' : '' ?>>GPT-4 Turbo</option>
                                        <option value="gpt-4o" <?= ($providerConfig['model'] ?? '') === 'gpt-4o' ? 'selected' : '' ?>>GPT-4o</option>
                                        <option value="gpt-4o-mini" <?= ($providerConfig['model'] ?? '') === 'gpt-4o-mini' ? 'selected' : '' ?>>GPT-4o Mini</option>
                                        <option value="gpt-4" <?= ($providerConfig['model'] ?? '') === 'gpt-4' ? 'selected' : '' ?>>GPT-4</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Hidden field to store provider config as JSON -->
                <input type="hidden" name="provider_config" id="provider_config_json" value="<?= htmlspecialchars(json_encode($providerConfig)) ?>">

                <hr>

                <!-- MCP Exposure -->
                <h6><i class="bi bi-plug"></i> Expose as MCP Tool</h6>
                <p class="text-muted small">Allow other agents to call this agent as an MCP tool for inter-LLM orchestration.</p>

                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="expose_as_mcp" name="expose_as_mcp" value="1"
                               <?= $exposeAsMcp ? 'checked' : '' ?> onchange="toggleMcpExposure()">
                        <label class="form-check-label" for="expose_as_mcp">
                            Expose this agent as an MCP tool
                        </label>
                    </div>
                </div>

                <div id="mcp-exposure-config" style="display: <?= $exposeAsMcp ? 'block' : 'none' ?>;">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">MCP Tool Name</label>
                            <input type="text" class="form-control" name="mcp_tool_name"
                                   value="<?= htmlspecialchars($mcpToolName) ?>"
                                   placeholder="e.g., ollama_review">
                            <div class="form-text">How Claude will call this agent</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tool Description</label>
                            <input type="text" class="form-control" name="mcp_tool_description"
                                   value="<?= htmlspecialchars($mcpToolDescription) ?>"
                                   placeholder="Get code review from local Ollama">
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary" onclick="prepareProviderConfig()">
                        <i class="bi bi-check-lg"></i> Save Provider Config
                    </button>
                </div>
            </form>
        </div>
    </div>

<script>
function updateProviderForm() {
    const provider = document.getElementById('provider_select').value;
    document.querySelectorAll('.provider-config').forEach(el => el.style.display = 'none');
    const configEl = document.getElementById('config-' + provider);
    if (configEl) {
        configEl.style.display = 'block';
    }
}

function toggleMcpExposure() {
    const show = document.getElementById('expose_as_mcp').checked;
    document.getElementById('mcp-exposure-config').style.display = show ? 'block' : 'none';
}

function prepareProviderConfig() {
    const provider = document.getElementById('provider_select').value;
    const configEl = document.getElementById('config-' + provider);
    const config = {};

    if (configEl) {
        configEl.querySelectorAll('.provider-field').forEach(field => {
            const name = field.name.replace('config_', '');
            // Handle checkboxes properly
            if (field.type === 'checkbox') {
                config[name] = field.checked;
            } else {
                config[name] = field.value;
            }
        });
    }

    document.getElementById('provider_config_json').value = JSON.stringify(config);
}

function testOllamaConnection() {
    const host = document.querySelector('#config-ollama input[name="config_host"]').value;
    const resultEl = document.getElementById('ollama-test-result');
    resultEl.innerHTML = '<span class="text-muted">Testing...</span>';

    fetch('/agents/testConnection', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': '<?= $csrf ?>'
        },
        body: 'provider=ollama&config=' + encodeURIComponent(JSON.stringify({host: host}))
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.data.success) {
            resultEl.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> ' + data.data.message + '</span>';
            // Auto-load models on successful connection
            loadOllamaModels();
        } else {
            resultEl.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> ' + (data.data?.message || data.message || 'Failed') + '</span>';
        }
    })
    .catch(e => {
        resultEl.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> Error: ' + e.message + '</span>';
    });
}

function loadOllamaModels() {
    const host = document.getElementById('ollama-host').value;
    const modelSelect = document.getElementById('ollama-model');
    const loadingEl = document.getElementById('ollama-models-loading');
    const currentModel = modelSelect.value;

    loadingEl.style.display = 'block';

    fetch('/agents/getModels', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': '<?= $csrf ?>'
        },
        body: 'provider=ollama&detailed=1&config=' + encodeURIComponent(JSON.stringify({host: host}))
    })
    .then(r => r.json())
    .then(data => {
        loadingEl.style.display = 'none';
        if (data.success && data.data.models) {
            modelSelect.innerHTML = '<option value="">-- Select a model --</option>';
            data.data.models.forEach(model => {
                const opt = document.createElement('option');
                opt.value = model.name;
                const details = model.details || {};
                const sizeInfo = model.size_formatted ? ` (${model.size_formatted})` : '';
                const paramInfo = details.parameter_size ? ` - ${details.parameter_size}` : '';
                opt.textContent = model.name + paramInfo + sizeInfo;
                opt.dataset.family = details.family || '';
                opt.dataset.params = details.parameter_size || '';
                opt.dataset.quant = details.quantization || '';
                opt.dataset.size = model.size_formatted || '';
                if (model.name === currentModel) opt.selected = true;
                modelSelect.appendChild(opt);
            });
            // Load info for current selection
            if (modelSelect.value) {
                loadModelInfo(modelSelect.value);
            }
        } else {
            modelSelect.innerHTML = '<option value="">Error loading models</option>';
        }
    })
    .catch(e => {
        loadingEl.style.display = 'none';
        modelSelect.innerHTML = '<option value="">Error: ' + e.message + '</option>';
    });
}

function loadModelInfo(modelName) {
    const infoCard = document.getElementById('ollama-model-info');
    if (!modelName) {
        infoCard.style.display = 'none';
        return;
    }

    const host = document.getElementById('ollama-host').value;
    const modelSelect = document.getElementById('ollama-model');
    const selectedOpt = modelSelect.options[modelSelect.selectedIndex];

    // Show quick info from dropdown data attributes first
    document.getElementById('model-info-name').textContent = modelName;
    document.getElementById('model-info-family').textContent = selectedOpt?.dataset.family || '-';
    document.getElementById('model-info-size').textContent = selectedOpt?.dataset.size || '-';
    document.getElementById('model-info-params').textContent = selectedOpt?.dataset.params || '-';
    document.getElementById('model-info-quant').textContent = selectedOpt?.dataset.quant || '-';
    infoCard.style.display = 'block';

    // Fetch detailed model info
    fetch('/agents/getModelInfo', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': '<?= $csrf ?>'
        },
        body: 'provider=ollama&model=' + encodeURIComponent(modelName) + '&config=' + encodeURIComponent(JSON.stringify({host: host}))
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.data.success) {
            const info = data.data;
            // Update with detailed info
            if (info.details) {
                document.getElementById('model-info-family').textContent = info.details.family || '-';
                document.getElementById('model-info-params').textContent = info.details.parameter_size || '-';
                document.getElementById('model-info-quant').textContent = info.details.quantization_level || '-';
            }
            // Show system prompt if present
            const systemEl = document.getElementById('model-info-system');
            if (info.system) {
                systemEl.querySelector('pre').textContent = info.system;
                systemEl.style.display = 'block';
            } else {
                systemEl.style.display = 'none';
            }
        }
    })
    .catch(e => console.log('Model info error:', e));
}

// Claude + Ollama backend functions
function toggleClaudeBackend() {
    const useOllama = document.getElementById('claude-use-ollama').checked;
    document.getElementById('claude-anthropic-config').style.display = useOllama ? 'none' : 'block';
    document.getElementById('claude-ollama-config').style.display = useOllama ? 'block' : 'none';
}

function loadClaudeOllamaModels() {
    const host = document.getElementById('claude-ollama-host').value;
    const modelSelect = document.getElementById('claude-ollama-model');
    const currentModel = modelSelect.value;

    modelSelect.innerHTML = '<option value="">Loading...</option>';

    fetch('/agents/getModels', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': '<?= $csrf ?>'
        },
        body: 'provider=ollama&detailed=1&config=' + encodeURIComponent(JSON.stringify({host: host}))
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.data.models) {
            modelSelect.innerHTML = '<option value="">-- Select a model --</option>';
            data.data.models.forEach(model => {
                const opt = document.createElement('option');
                opt.value = model.name;
                const details = model.details || {};
                const sizeInfo = model.size_formatted ? ` (${model.size_formatted})` : '';
                const paramInfo = details.parameter_size ? ` - ${details.parameter_size}` : '';
                opt.textContent = model.name + paramInfo + sizeInfo;
                opt.dataset.family = details.family || '';
                opt.dataset.params = details.parameter_size || '';
                opt.dataset.quant = details.quantization || '';
                opt.dataset.size = model.size_formatted || '';
                if (model.name === currentModel) opt.selected = true;
                modelSelect.appendChild(opt);
            });
            if (modelSelect.value) loadClaudeOllamaModelInfo(modelSelect.value);
        } else {
            modelSelect.innerHTML = '<option value="">Error loading models</option>';
        }
    })
    .catch(e => {
        modelSelect.innerHTML = '<option value="">Error: ' + e.message + '</option>';
    });
}

function loadClaudeOllamaModelInfo(modelName) {
    const infoCard = document.getElementById('claude-ollama-model-info');
    if (!modelName) {
        infoCard.style.display = 'none';
        return;
    }

    const modelSelect = document.getElementById('claude-ollama-model');
    const selectedOpt = modelSelect.options[modelSelect.selectedIndex];

    document.getElementById('claude-model-info-name').textContent = modelName;
    document.getElementById('claude-model-info-family').textContent = selectedOpt?.dataset.family || '-';
    document.getElementById('claude-model-info-size').textContent = selectedOpt?.dataset.size || '-';
    document.getElementById('claude-model-info-params').textContent = selectedOpt?.dataset.params || '-';
    document.getElementById('claude-model-info-quant').textContent = selectedOpt?.dataset.quant || '-';
    infoCard.style.display = 'block';
}

function testClaudeOllamaConnection() {
    const host = document.getElementById('claude-ollama-host').value;
    const resultEl = document.getElementById('claude-ollama-test-result');
    resultEl.innerHTML = '<span class="text-muted">Testing...</span>';

    fetch('/agents/testConnection', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': '<?= $csrf ?>'
        },
        body: 'provider=ollama&config=' + encodeURIComponent(JSON.stringify({host: host}))
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.data.success) {
            resultEl.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> ' + data.data.message + '</span>';
            loadClaudeOllamaModels();
        } else {
            resultEl.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> ' + (data.data?.message || data.message || 'Failed') + '</span>';
        }
    })
    .catch(e => {
        resultEl.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> Error: ' + e.message + '</span>';
    });
}

// Create form provider functions
function updateCreateProviderConfig() {
    const provider = document.getElementById('provider_create')?.value;
    if (!provider) return;

    document.querySelectorAll('.create-provider-config').forEach(el => el.style.display = 'none');
    const configEl = document.getElementById('create-config-' + provider);
    if (configEl) {
        configEl.style.display = 'block';
    }
}

function toggleCreateClaudeBackend() {
    const useOllama = document.getElementById('create-use-ollama')?.checked;
    const anthropicEl = document.getElementById('create-claude-anthropic');
    const ollamaEl = document.getElementById('create-claude-ollama');
    if (anthropicEl) anthropicEl.style.display = useOllama ? 'none' : 'block';
    if (ollamaEl) ollamaEl.style.display = useOllama ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    // For edit form (Provider tab)
    if (document.getElementById('provider_select')) {
        updateProviderForm();
        toggleClaudeBackend();
    }
    // For create form
    if (document.getElementById('provider_create')) {
        updateCreateProviderConfig();
    }
});
</script>

    <?php elseif ($activeTab === 'capabilities'): ?>
    <!-- Capabilities Tab -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-stars"></i> Agent Capabilities
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                Define what tasks this agent can handle. The orchestrator uses capabilities to route tasks to appropriate agents.
            </div>

            <form method="POST" action="/agents/update/<?= $agentId ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="tab" value="capabilities">

                <div class="row">
                    <?php foreach ($capabilities as $key => $label): ?>
                    <div class="col-md-6 mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="capabilities[]"
                                   value="<?= $key ?>" id="cap_<?= $key ?>"
                                   <?= in_array($key, $agentCapabilities) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cap_<?= $key ?>">
                                <strong><?= htmlspecialchars($label) ?></strong>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <hr>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Save Capabilities
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php endif; ?>
</div>
