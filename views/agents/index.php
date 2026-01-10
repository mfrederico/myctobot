<?php $csrf = $csrf['csrf_token'] ?? ''; ?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 mb-0">
            <i class="bi bi-robot"></i> AI Agent Profiles
        </h1>
        <div>
            <a href="/admin/shards" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> Back to Shards
            </a>
            <a href="/agents/create" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Create Agent
            </a>
        </div>
    </div>

    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i>
        <strong>Agent Profiles</strong> define <em>how</em> AI Developer jobs run: which runner to use, MCP servers, and hooks.
        <strong><a href="/admin/shards">Workstation Shards</a></strong> define <em>where</em> jobs run (remote servers with Claude installed).
        Assign agents to repositories on the <a href="/github/repos">Repos page</a>.
    </div>

    <?php if (empty($agents)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-robot display-4 text-muted"></i>
            <p class="text-muted mt-3">No agent profiles configured yet.</p>
            <a href="/agents/create" class="btn btn-primary">Create Your First Agent</a>
        </div>
    </div>
    <?php else: ?>
    <div class="row">
        <?php foreach ($agents as $agent): ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 <?= !$agent['is_active'] ? 'border-secondary opacity-75' : '' ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>
                        <i class="bi bi-robot"></i>
                        <strong><?= htmlspecialchars($agent['name']) ?></strong>
                    </span>
                    <div>
                        <?php if ($agent['is_default']): ?>
                        <span class="badge bg-warning text-dark">Default</span>
                        <?php endif; ?>
                        <span class="badge <?= $agent['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                            <?= $agent['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3"><?= htmlspecialchars($agent['description'] ?: 'No description') ?></p>

                    <table class="table table-sm table-borderless mb-3">
                        <tr>
                            <td class="text-muted" style="width: 40%">Runner:</td>
                            <td>
                                <?php
                                $runnerIcon = match($agent['runner_type']) {
                                    'claude_cli' => 'terminal',
                                    'anthropic_api' => 'cloud',
                                    'ollama' => 'cpu',
                                    default => 'gear'
                                };
                                $runnerClass = match($agent['runner_type']) {
                                    'claude_cli' => 'bg-primary',
                                    'anthropic_api' => 'bg-info',
                                    'ollama' => 'bg-success',
                                    default => 'bg-secondary'
                                };
                                ?>
                                <span class="badge <?= $runnerClass ?>">
                                    <i class="bi bi-<?= $runnerIcon ?>"></i>
                                    <?= htmlspecialchars($agent['runner_type_label']) ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">MCP Servers:</td>
                            <td>
                                <?php if ($agent['mcp_count'] > 0): ?>
                                <span class="badge bg-secondary"><?= $agent['mcp_count'] ?> configured</span>
                                <?php else: ?>
                                <span class="text-muted small">None</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Hooks:</td>
                            <td>
                                <?php if ($agent['hooks_count'] > 0): ?>
                                <span class="badge bg-secondary"><?= $agent['hooks_count'] ?> hooks</span>
                                <?php else: ?>
                                <span class="text-muted small">None</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Used By:</td>
                            <td>
                                <?php if ($agent['repo_count'] > 0): ?>
                                <span class="badge bg-info"><?= $agent['repo_count'] ?> repos</span>
                                <?php else: ?>
                                <span class="text-muted small">No repos</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="card-footer bg-transparent">
                    <div class="d-flex justify-content-between">
                        <a href="/agents/edit/<?= $agent['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-danger"
                                onclick="deleteAgent(<?= $agent['id'] ?>, '<?= htmlspecialchars($agent['name'], ENT_QUOTES) ?>')"
                                <?= $agent['repo_count'] > 0 ? 'disabled title="Unassign from repos first"' : '' ?>>
                            <i class="bi bi-trash"></i> Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function deleteAgent(id, name) {
    if (!confirm('Are you sure you want to delete agent "' + name + '"?')) {
        return;
    }

    fetch('/agents/delete/' + id, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': '<?= $csrf ?>'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to delete agent'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
}
</script>
