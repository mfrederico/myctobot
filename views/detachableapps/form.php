<?php
$isEdit = isset($app) && $app;
$isTemplateBased = $isEdit && !empty($app->template_slug);
$action = $isEdit ? '/detachableapps/update/' . (int) $app->id : '/detachableapps/store';
?>
<div class="container py-4" style="max-width: 900px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="/detachableapps">Detachable Apps</a></li>
                    <li class="breadcrumb-item active"><?= $isEdit ? h($app->name) : 'New App' ?></li>
                </ol>
            </nav>
            <h1 class="h2 mb-0">
                <i class="bi bi-box-arrow-up-right text-primary"></i>
                <?= $isEdit ? 'Edit App' : 'Create Detachable App' ?>
            </h1>
        </div>
        <a href="/detachableapps" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to List
        </a>
    </div>

    <form method="POST" action="<?= $action ?>" id="appForm">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-info-circle"></i> App Settings
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="name" name="name"
                           value="<?= h($isEdit ? $app->name : '') ?>"
                           placeholder="My Detachable App" required>
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="3"
                              placeholder="A brief description of what this app does"><?= h($isEdit ? $app->description : '') ?></textarea>
                </div>

                <?php if ($isEdit && !empty($app->slug)): ?>
                <div class="mb-3">
                    <label class="form-label">Slug</label>
                    <input type="text" class="form-control" value="<?= h($app->slug) ?>" readonly disabled>
                    <div class="form-text">Auto-generated from the name. Cannot be changed.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$isTemplateBased): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-palette"></i> Stitch Connection
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="stitch_connection_eid" class="form-label">Stitch Connection</label>
                    <select class="form-select" id="stitch_connection_eid" name="stitch_connection_eid">
                        <option value="">-- No Stitch connection --</option>
                        <?php foreach ($stitchConnections as $conn): ?>
                        <option value="<?= (int) $conn->id ?>"
                                <?= ($isEdit && ($app->stitch_connection_eid ?? '') == $conn->id) ? 'selected' : '' ?>>
                            <?= h($conn->connection_name ?? $conn->name ?? 'Connection') ?> (#<?= (int) $conn->id ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Select a Stitch OAuth connection to pull screens from.</div>
                </div>

                <div class="mb-3">
                    <label for="stitch_project_eid" class="form-label">Stitch Project</label>
                    <div class="input-group">
                        <select class="form-select" id="stitch_project_eid" name="stitch_project_eid">
                            <option value="">-- Select a project --</option>
                            <?php foreach ($stitchProjects as $proj): ?>
                            <option value="<?= h($proj['id']) ?>"
                                    <?= ($isEdit && ($app->stitch_project_eid ?? '') == $proj['id']) ? 'selected' : '' ?>>
                                <?= h($proj['title']) ?> (<?= h($proj['id']) ?>)
                            </option>
                            <?php endforeach; ?>
                            <?php if ($isEdit && !empty($app->stitch_project_eid) && empty($stitchProjects)): ?>
                            <option value="<?= h($app->stitch_project_eid) ?>" selected>
                                Project #<?= h($app->stitch_project_eid) ?> (cached)
                            </option>
                            <?php endif; ?>
                        </select>
                        <button type="button" class="btn btn-outline-primary" id="btnCreateProject"
                                title="Create new Stitch project" disabled>
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    </div>
                    <div class="form-text">Select a Stitch connection first, then pick a project.</div>
                    <div id="projectLoading" class="text-muted small mt-1 d-none">
                        <span class="spinner-border spinner-border-sm"></span> Loading projects...
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-git"></i> Git Repository
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="git_repo_url" class="form-label">Git Repository URL</label>
                    <input type="text" class="form-control font-monospace" id="git_repo_url" name="git_repo_url"
                           value="<?= h($isEdit ? ($app->git_repo_url ?? '') : '') ?>"
                           placeholder="git@github.com:user/repo.git">
                    <div class="form-text">SSH format recommended for private repos. The build output will be committed here.</div>
                </div>

                <?php if ($isEdit): ?>
                <div class="mb-3">
                    <label for="git_deploy_key" class="form-label">Git Deploy Key</label>
                    <textarea class="form-control font-monospace" id="git_deploy_key" name="git_deploy_key" rows="4"
                              placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;...&#10;-----END OPENSSH PRIVATE KEY-----"><?= h($app->git_deploy_key ?? '') ?></textarea>
                    <div class="form-text">SSH private key for push access to the repository. Stored encrypted.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isEdit): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-globe2"></i> Hosted Mode
            </div>
            <div class="card-body">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="is_hosted" name="is_hosted" value="1"
                           <?= ((int) ($app->is_hosted ?? 0)) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_hosted">
                        Enable hosted serving
                    </label>
                </div>
                <div class="form-text mb-2">
                    Serve this app directly from MyCTOBot — no Docker container needed.
                </div>
                <?php if ((int) ($app->is_hosted ?? 0) && !empty($app->slug)): ?>
                <div class="alert alert-success mb-0 py-2">
                    <i class="bi bi-link-45deg"></i>
                    Live URL:
                    <a href="/dapp/<?= h($app->slug) ?>" target="_blank" class="fw-bold">
                        /dapp/<?= h($app->slug) ?>
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-success ms-2"
                            onclick="navigator.clipboard.writeText(location.origin + '/dapp/<?= h($app->slug) ?>')">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isEdit && !empty($app->api_token)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-key"></i> API Access
            </div>
            <div class="card-body">
                <label class="form-label">API Token</label>
                <div class="input-group">
                    <code class="form-control bg-light" id="apiTokenDisplay"><?= h($app->api_token) ?></code>
                    <button type="button" class="btn btn-outline-secondary" onclick="copyApiToken()" title="Copy to clipboard">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
                <div class="form-text">Use this token to authenticate API requests to the detached app.</div>
            </div>
        </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between">
            <a href="/detachableapps" class="btn btn-outline-secondary">
                <i class="bi bi-x-lg"></i> Cancel
            </a>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg"></i> <?= $isEdit ? 'Update App' : 'Create App' ?>
            </button>
        </div>
    </form>

    <?php if ($isEdit): ?>
    <!-- Action Buttons -->
    <div class="card mt-4">
        <div class="card-header">
            <i class="bi bi-lightning-charge"></i> Actions
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2">
                <a href="/detachableapps/screens/<?= (int) $app->id ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-window-stack"></i> Manage Screens
                </a>
                <a href="/detachableapps/pipelines/<?= (int) $app->id ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-diagram-3"></i> Bind Pipelines
                </a>
                <a href="/detachableapps/liquidfiles/<?= (int) $app->id ?>" class="btn btn-outline-success">
                    <i class="bi bi-braces"></i> Shopify Files
                </a>
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-warning dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-hammer"></i> Build
                    </button>
                    <ul class="dropdown-menu">
                        <li>
                            <form method="POST" action="/detachableapps/build/<?= (int) $app->id ?>" onsubmit="return confirm('Start Docker build?')">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                <button type="submit" class="dropdown-item">
                                    <i class="bi bi-box-seam me-2"></i>Docker Build
                                </button>
                            </form>
                        </li>
                        <li>
                            <a class="dropdown-item" href="/detachableapps/buildshopify/<?= (int) $app->id ?>">
                                <i class="bi bi-shop me-2"></i>Shopify Build
                            </a>
                        </li>
                    </ul>
                </div>
                <a href="/detachableapps/releases/<?= (int) $app->id ?>" class="btn btn-outline-info">
                    <i class="bi bi-tag"></i> Releases
                </a>
                <a href="/detachableapps/download/<?= (int) $app->id ?>" class="btn btn-success">
                    <i class="bi bi-download"></i> Download
                </a>
            </div>
        </div>
    </div>

    <!-- Danger Zone -->
    <div class="card mt-4 border-danger">
        <div class="card-header bg-danger text-white">
            <i class="bi bi-exclamation-triangle"></i> Danger Zone
        </div>
        <div class="card-body">
            <p class="mb-3">Permanently delete this app and all its associated screen mappings, pipeline bindings, and releases. This action cannot be undone.</p>
            <form method="POST" action="/detachableapps/delete/<?= (int) $app->id ?>"
                  onsubmit="return confirm('Are you sure you want to permanently delete \'<?= h(addslashes($app->name)) ?>\'? This cannot be undone.')">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash"></i> Delete App
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Create Stitch Project Modal -->
<div class="modal fade" id="createProjectModal" tabindex="-1" aria-labelledby="createProjectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createProjectModalLabel"><i class="bi bi-plus-circle"></i> Create Stitch Project</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="newProjectTitle" class="form-label">Project Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="newProjectTitle" placeholder="My New Project" required>
                </div>
                <div id="createProjectError" class="alert alert-danger d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSubmitCreateProject">
                    <i class="bi bi-plus-lg"></i> Create
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function copyApiToken() {
    const tokenEl = document.getElementById('apiTokenDisplay');
    if (!tokenEl) return;
    navigator.clipboard.writeText(tokenEl.textContent.trim()).then(() => {
        const btn = tokenEl.nextElementSibling;
        const origHTML = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg text-success"></i>';
        setTimeout(() => { btn.innerHTML = origHTML; }, 1500);
    });
}

// Dynamic project loading when connection changes
const connSelect = document.getElementById('stitch_connection_eid');
const btnCreateProject = document.getElementById('btnCreateProject');

if (!connSelect || !btnCreateProject) {
    // Stitch card not rendered (template-based app) — skip Stitch JS
} else {

function updateCreateButtonState() {
    btnCreateProject.disabled = !connSelect.value;
}

connSelect.addEventListener('change', function() {
    const connId = this.value;
    const projectSelect = document.getElementById('stitch_project_eid');
    const loading = document.getElementById('projectLoading');

    // Reset dropdown
    projectSelect.innerHTML = '<option value="">-- Select a project --</option>';
    updateCreateButtonState();

    if (!connId) return;

    loading.classList.remove('d-none');
    projectSelect.disabled = true;

    fetch('/detachableapps/stitchprojects/' + connId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        loading.classList.add('d-none');
        projectSelect.disabled = false;

        if (data.success && data.data && data.data.projects) {
            data.data.projects.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.title + ' (' + p.id + ')';
                projectSelect.appendChild(opt);
            });
        } else {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'No projects found';
            opt.disabled = true;
            projectSelect.appendChild(opt);
        }
    })
    .catch(err => {
        loading.classList.add('d-none');
        projectSelect.disabled = false;
        console.error('Failed to load projects:', err);
    });
});

// Create Project modal
btnCreateProject.addEventListener('click', function() {
    document.getElementById('newProjectTitle').value = '';
    document.getElementById('createProjectError').classList.add('d-none');
    new bootstrap.Modal(document.getElementById('createProjectModal')).show();
});

document.getElementById('btnSubmitCreateProject').addEventListener('click', function() {
    const title = document.getElementById('newProjectTitle').value.trim();
    const errorEl = document.getElementById('createProjectError');
    const btn = this;

    if (!title) {
        errorEl.textContent = 'Please enter a project title.';
        errorEl.classList.remove('d-none');
        return;
    }

    errorEl.classList.add('d-none');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Creating...';

    const connId = connSelect.value;

    fetch('/detachableapps/createproject/' + connId, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ title: title })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plus-lg"></i> Create';

        if (data.success && data.data) {
            const projectSelect = document.getElementById('stitch_project_eid');
            const opt = document.createElement('option');
            opt.value = data.data.id;
            opt.textContent = data.data.title + ' (' + data.data.id + ')';
            opt.selected = true;
            projectSelect.appendChild(opt);

            bootstrap.Modal.getInstance(document.getElementById('createProjectModal')).hide();
        } else {
            errorEl.textContent = data.message || 'Failed to create project.';
            errorEl.classList.remove('d-none');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plus-lg"></i> Create';
        errorEl.textContent = 'Network error: ' + err.message;
        errorEl.classList.remove('d-none');
    });
});

// Initialize button state on page load
updateCreateButtonState();

} // end if connSelect && btnCreateProject
</script>
