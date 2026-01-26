<?php
/**
 * Reusable provider configuration partial
 *
 * Required variables:
 *   $prefix       - Unique prefix for element IDs (e.g., 'create', 'edit')
 *   $provider     - Current provider type
 *   $providerConfig - Current provider config array
 *   $providers    - Available providers list
 *   $anthropicKeys - Available Anthropic API keys (optional)
 *   $agent        - Agent data array (for existing agents, optional)
 */

$prefix = $prefix ?? 'form';
$provider = $provider ?? 'claude_cli';
$providerConfig = $providerConfig ?? [];
$anthropicKeys = $anthropicKeys ?? [];
$agent = $agent ?? [];
?>

<!-- Provider Selection -->
<div class="mb-3">
    <label for="provider_<?= $prefix ?>" class="form-label">Provider <span class="text-danger">*</span></label>
    <select class="form-select" id="provider_<?= $prefix ?>" name="provider" onchange="updateProviderConfig('<?= $prefix ?>')">
        <?php foreach ($providers as $p): ?>
        <option value="<?= $p['type'] ?>" <?= $provider === $p['type'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($p['name']) ?> - <?= htmlspecialchars($p['description']) ?>
        </option>
        <?php endforeach; ?>
    </select>
</div>

<!-- Provider Config -->
<div id="<?= $prefix ?>-provider-config" class="mb-3">
    <!-- Claude CLI Config -->
    <div class="provider-config-<?= $prefix ?>" id="<?= $prefix ?>-config-claude_cli" style="display: <?= $provider === 'claude_cli' ? 'block' : 'none' ?>;">
        <div class="card bg-light">
            <div class="card-body">
                <h6><i class="bi bi-terminal"></i> Claude CLI Settings</h6>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" id="<?= $prefix ?>-use-ollama" name="use_ollama"
                           <?= !empty($providerConfig['use_ollama']) ? 'checked' : '' ?>
                           <?= $provider !== 'claude_cli' ? 'disabled' : '' ?>
                           onchange="toggleClaudeBackend('<?= $prefix ?>')">
                    <label class="form-check-label" for="<?= $prefix ?>-use-ollama">
                        <i class="bi bi-cpu"></i> Use Ollama as backend
                    </label>
                </div>
                <div id="<?= $prefix ?>-claude-anthropic" style="display: <?= empty($providerConfig['use_ollama']) ? 'block' : 'none' ?>;">
                    <label class="form-label">Model</label>
                    <select class="form-select provider-model-field" name="model" <?= $provider !== 'claude_cli' ? 'disabled' : '' ?>>
                        <option value="sonnet" <?= ($providerConfig['model'] ?? 'sonnet') === 'sonnet' ? 'selected' : '' ?>>Sonnet (Recommended)</option>
                        <option value="opus" <?= ($providerConfig['model'] ?? '') === 'opus' ? 'selected' : '' ?>>Opus</option>
                        <option value="haiku" <?= ($providerConfig['model'] ?? '') === 'haiku' ? 'selected' : '' ?>>Haiku (Fast)</option>
                    </select>
                </div>
                <div id="<?= $prefix ?>-claude-ollama" style="display: <?= !empty($providerConfig['use_ollama']) ? 'block' : 'none' ?>;">
                    <div class="mb-2">
                        <label class="form-label">Ollama Host</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="<?= $prefix ?>-ollama-host" name="ollama_host"
                                   value="<?= htmlspecialchars($providerConfig['ollama_host'] ?? 'http://localhost:11434') ?>"
                                   placeholder="http://localhost:11434">
                            <button type="button" class="btn btn-outline-primary" onclick="loadOllamaModels('<?= $prefix ?>')">
                                <i class="bi bi-arrow-clockwise"></i> Load Models
                            </button>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Ollama Model</label>
                        <select class="form-select" id="<?= $prefix ?>-ollama-model" name="ollama_model">
                            <?php if (!empty($providerConfig['ollama_model'])): ?>
                            <option value="<?= htmlspecialchars($providerConfig['ollama_model']) ?>" selected>
                                <?= htmlspecialchars($providerConfig['ollama_model']) ?>
                            </option>
                            <?php else: ?>
                            <option value="">-- Click "Load Models" to fetch --</option>
                            <?php endif; ?>
                        </select>
                        <div class="form-text">
                            Recommended: <code>qwen3-coder</code>, <code>codellama</code>, <code>llama3</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Ollama Direct Config -->
    <div class="provider-config-<?= $prefix ?>" id="<?= $prefix ?>-config-ollama" style="display: <?= $provider === 'ollama' ? 'block' : 'none' ?>;">
        <div class="card bg-light">
            <div class="card-body">
                <h6><i class="bi bi-cpu"></i> Ollama Settings</h6>
                <div class="mb-2">
                    <label class="form-label">Ollama Host</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="<?= $prefix ?>-ollama-direct-host" name="base_url"
                               value="<?= htmlspecialchars($providerConfig['base_url'] ?? $providerConfig['host'] ?? 'http://localhost:11434') ?>"
                               placeholder="http://localhost:11434"
                               <?= $provider !== 'ollama' ? 'disabled' : '' ?>>
                        <button type="button" class="btn btn-outline-primary" onclick="loadOllamaModels('<?= $prefix ?>', true)">
                            <i class="bi bi-arrow-clockwise"></i> Load Models
                        </button>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label">Ollama Model</label>
                    <select class="form-select provider-model-field" id="<?= $prefix ?>-ollama-direct-model" name="model" <?= $provider !== 'ollama' ? 'disabled' : '' ?>>
                        <?php if (!empty($providerConfig['model'])): ?>
                        <option value="<?= htmlspecialchars($providerConfig['model']) ?>" selected>
                            <?= htmlspecialchars($providerConfig['model']) ?>
                        </option>
                        <?php else: ?>
                        <option value="">-- Click "Load Models" to fetch --</option>
                        <?php endif; ?>
                    </select>
                    <div class="form-text">
                        Recommended: <code>llama3.2</code>, <code>qwen3-coder</code>, <code>codellama</code>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- OpenAI Config -->
    <div class="provider-config-<?= $prefix ?>" id="<?= $prefix ?>-config-openai" style="display: <?= $provider === 'openai' ? 'block' : 'none' ?>;">
        <div class="card bg-light">
            <div class="card-body">
                <h6><i class="bi bi-stars"></i> OpenAI Settings</h6>
                <div class="mb-2">
                    <label class="form-label">Model</label>
                    <select class="form-select provider-model-field" name="model" <?= $provider !== 'openai' ? 'disabled' : '' ?>>
                        <option value="gpt-4-turbo" <?= ($providerConfig['model'] ?? 'gpt-4-turbo') === 'gpt-4-turbo' ? 'selected' : '' ?>>GPT-4 Turbo</option>
                        <option value="gpt-4o" <?= ($providerConfig['model'] ?? '') === 'gpt-4o' ? 'selected' : '' ?>>GPT-4o</option>
                        <option value="gpt-4o-mini" <?= ($providerConfig['model'] ?? '') === 'gpt-4o-mini' ? 'selected' : '' ?>>GPT-4o Mini</option>
                    </select>
                </div>
                <div class="form-text">API key is configured in Settings &rarr; Connections</div>
            </div>
        </div>
    </div>

    <!-- Claude API Config -->
    <div class="provider-config-<?= $prefix ?>" id="<?= $prefix ?>-config-claude_api" style="display: <?= $provider === 'claude_api' ? 'block' : 'none' ?>;">
        <div class="card bg-light">
            <div class="card-body">
                <h6><i class="bi bi-cloud"></i> Claude API Settings</h6>

                <div class="mb-3">
                    <label class="form-label">Anthropic API Key <span class="text-danger">*</span></label>
                    <select class="form-select" name="anthropickeys_id" id="<?= $prefix ?>_anthropickeys_id">
                        <option value="">-- Select API Key --</option>
                        <?php if (!empty($anthropicKeys)): ?>
                            <?php foreach ($anthropicKeys as $key): ?>
                            <option value="<?= $key->id ?>"
                                    <?= ($agent['anthropickeys_id'] ?? null) == $key->id ? 'selected' : '' ?>
                                    data-model="<?= htmlspecialchars($key->model ?? '') ?>">
                                <?= htmlspecialchars($key->name) ?>
                                <?php if ($key->shared): ?>(Shared)<?php endif; ?>
                                - <?= htmlspecialchars($key->model ?? 'default') ?>
                            </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <div class="form-text">
                        Select the API key this agent will use.
                        <a href="/anthropic/keys">Manage API Keys</a>
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label">Model</label>
                    <select class="form-select provider-model-field" name="model" <?= $provider !== 'claude_api' ? 'disabled' : '' ?>>
                        <option value="claude-sonnet-4-20250514" <?= ($providerConfig['model'] ?? 'claude-sonnet-4-20250514') === 'claude-sonnet-4-20250514' ? 'selected' : '' ?>>Claude Sonnet 4 (Recommended)</option>
                        <option value="claude-opus-4-20250514" <?= ($providerConfig['model'] ?? '') === 'claude-opus-4-20250514' ? 'selected' : '' ?>>Claude Opus 4</option>
                        <option value="claude-3-5-haiku-20241022" <?= ($providerConfig['model'] ?? '') === 'claude-3-5-haiku-20241022' ? 'selected' : '' ?>>Claude 3.5 Haiku (Fast)</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Custom HTTP Config -->
    <div class="provider-config-<?= $prefix ?>" id="<?= $prefix ?>-config-custom_http" style="display: <?= $provider === 'custom_http' ? 'block' : 'none' ?>;">
        <div class="card bg-light">
            <div class="card-body">
                <h6><i class="bi bi-globe"></i> Custom HTTP Settings</h6>
                <div class="mb-2">
                    <label class="form-label">Endpoint URL</label>
                    <input type="text" class="form-control provider-field" name="endpoint"
                           value="<?= htmlspecialchars($providerConfig['endpoint'] ?? '') ?>"
                           placeholder="https://api.example.com/v1/chat/completions"
                           <?= $provider !== 'custom_http' ? 'disabled' : '' ?>>
                </div>
                <div class="mb-2">
                    <label class="form-label">Model</label>
                    <input type="text" class="form-control provider-model-field" name="model"
                           value="<?= htmlspecialchars($providerConfig['model'] ?? '') ?>"
                           placeholder="Model name"
                           <?= $provider !== 'custom_http' ? 'disabled' : '' ?>>
                </div>
            </div>
        </div>
    </div>
</div>
