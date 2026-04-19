<?php
/**
 * @step_type: llm_call
 * @category: ai
 * @label: LLM API Call
 * @icon: bi-chat-dots
 * @color: info
 * @description: Lightweight LLM API call (Anthropic or Ollama)
 *
 * Configuration options:
 * - provider: anthropic | ollama
 * - model: model identifier
 * - base_url: API endpoint (for Ollama)
 * - system_prompt: system message
 * - prompt: user prompt (supports variable substitution)
 * - max_tokens: max response tokens
 * - temperature: creativity (0.0-1.0)
 * - json_output: expect JSON response
 */
?>
<div class="config-panel" id="config_llm_call" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label">Provider</label>
                        <select class="form-select" name="config_provider" id="llmCallProvider" onchange="toggleLlmCallProvider()">
                            <option value="ollama">Ollama (Local)</option>
                            <option value="anthropic">Anthropic API</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label">Model</label>
                        <input type="text" class="form-control font-monospace" name="config_model"
                               id="llmCallModel" value="qwen3-coder:30b" placeholder="model name">
                        <small class="text-muted" id="llmCallModelHint">e.g. qwen3-coder:30b, devstral:latest</small>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="mb-3" id="llmCallBaseUrlGroup">
                        <label class="form-label">Ollama URL</label>
                        <input type="text" class="form-control font-monospace" name="config_base_url"
                               value="http://localhost:11434" placeholder="http://localhost:11434">
                        <small class="text-muted">Ollama server endpoint</small>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-3">
                    <div class="mb-3">
                        <label class="form-label">Max Tokens</label>
                        <input type="number" class="form-control" name="config_max_tokens"
                               value="500" min="1" max="100000">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mb-3">
                        <label class="form-label">Temperature</label>
                        <input type="number" class="form-control" name="config_temperature"
                               value="0.7" min="0" max="2" step="0.1">
                        <small class="text-muted">0=precise, 1=creative</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Options</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="config_json_output" id="llmCallJsonOutput">
                            <label class="form-check-label" for="llmCallJsonOutput">Expect JSON output</label>
                        </div>
                        <small class="text-muted">Parse response as JSON (adds JSON instruction to prompt)</small>
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">System Prompt <small class="text-muted">(optional)</small></label>
                <textarea class="form-control font-monospace" name="config_system_prompt" rows="2"
                          placeholder="You are a helpful assistant that responds in JSON format."></textarea>
                <small class="text-muted">Sets the AI's behavior and context</small>
            </div>
            <div class="mb-0">
                <label class="form-label">Prompt Template</label>
                <textarea class="form-control font-monospace" name="config_prompt" rows="5"
                          placeholder="Classify this contact submission:

Name: {context.name}
Email: {context.email}
Message: {context.message}

Respond with JSON: {&quot;classification&quot;: &quot;spam|prospect|support&quot;, &quot;confidence&quot;: 0.95}"></textarea>
                <small class="text-muted">Use <code>{context.key}</code> or <code>{step_name.output.key}</code> for variable substitution</small>
            </div>
        </div>
    </div>
</div>
<script>
function toggleLlmCallProvider() {
    const provider = document.getElementById('llmCallProvider').value;
    const baseUrlGroup = document.getElementById('llmCallBaseUrlGroup');
    const modelHint = document.getElementById('llmCallModelHint');
    const modelInput = document.getElementById('llmCallModel');

    if (provider === 'ollama') {
        baseUrlGroup.style.display = 'block';
        modelHint.textContent = 'e.g. qwen3-coder:30b, devstral:latest, llama3';
        if (!modelInput.value || modelInput.value.startsWith('claude')) {
            modelInput.value = 'qwen3-coder:30b';
        }
    } else {
        baseUrlGroup.style.display = 'none';
        modelHint.textContent = 'e.g. claude-3-haiku-20240307, claude-3-sonnet-20240229';
        if (!modelInput.value || !modelInput.value.startsWith('claude')) {
            modelInput.value = 'claude-3-haiku-20240307';
        }
    }
}

// Initialize on load if this panel becomes visible
document.addEventListener('DOMContentLoaded', function() {
    toggleLlmCallProvider();
});
</script>
