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
