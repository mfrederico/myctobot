<?php
$appId = (int) ($app->id ?? 0);
$appName = h($app->name ?? 'App');
?>
<div class="container py-4">
    <!-- Step Indicator -->
    <div class="mb-4">
        <nav aria-label="App setup steps">
            <ol class="breadcrumb bg-light p-3 rounded">
                <li class="breadcrumb-item">
                    <a href="/detachableapps/form/<?= $appId ?>">
                        <i class="bi bi-1-circle-fill text-primary"></i> Setup
                    </a>
                </li>
                <li class="breadcrumb-item active" aria-current="page">
                    <i class="bi bi-2-circle-fill text-primary"></i> <strong>Screens</strong>
                </li>
                <li class="breadcrumb-item">
                    <a href="/detachableapps/pipelines/<?= $appId ?>">
                        <i class="bi bi-3-circle"></i> Pipelines
                    </a>
                </li>
                <li class="breadcrumb-item text-muted">
                    <i class="bi bi-4-circle"></i> Build
                </li>
            </ol>
        </nav>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi bi-window-stack text-primary"></i> Screens &mdash; <?= $appName ?>
            </h1>
            <p class="text-muted mb-0">Manage screens via Stitch or the block editor</p>
        </div>
        <div class="d-flex gap-2">
            <a href="/detachableappbuilder/index/<?= $appId ?>" class="btn btn-info">
                <i class="bi bi-bricks"></i> Open Block Editor
            </a>
            <a href="/detachableapps/form/<?= $appId ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Setup
            </a>
            <a href="/detachableapps/pipelines/<?= $appId ?>" class="btn btn-primary">
                Pipelines <i class="bi bi-arrow-right"></i>
            </a>
        </div>
    </div>

    <div class="row">
        <!-- Left Panel: Available Screens -->
        <div class="col-lg-5 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-collection"></i> Available Screens
                    <span class="badge bg-secondary ms-1"><?= count($availableScreens) ?></span>
                </div>
                <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                    <?php if (empty($availableScreens)): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-inbox display-6 text-muted"></i>
                        <?php if (!empty($app->template_slug)): ?>
                        <p class="text-muted mt-2 mb-0">Screens are pre-configured from template.</p>
                        <small class="text-muted">Template-based apps include built-in screens. No Stitch connection needed.</small>
                        <?php else: ?>
                        <p class="text-muted mt-2 mb-0">No screens found.</p>
                        <small class="text-muted">Check the Stitch connection and project ID in app settings.</small>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($availableScreens as $screen): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center" id="avail-screen-<?= h($screen['id'] ?? $screen['screen_id'] ?? '') ?>">
                            <div>
                                <strong><?= h($screen['title'] ?? $screen['name'] ?? 'Untitled') ?></strong>
                                <?php if (!empty($screen['updated_at'])): ?>
                                <br><small class="text-muted">Updated: <?= h($screen['updated_at']) ?></small>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    onclick="addScreen(<?= h(json_encode($screen)) ?>)">
                                <i class="bi bi-plus-lg"></i> Add
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Panel: Selected Screens -->
        <div class="col-lg-7 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>
                        <i class="bi bi-check2-square"></i> Selected Screens
                        <span class="badge bg-primary ms-1" id="selectedCount"><?= count($selectedScreens) ?></span>
                    </span>
                    <div class="d-flex gap-2">
                        <a href="/detachableapps/addblockscreen/<?= $appId ?>"
                           class="btn btn-sm btn-outline-info" title="Create a new screen for block editing">
                            <i class="bi bi-plus-lg"></i> Add Block Screen
                        </a>
                        <button type="button" class="btn btn-sm btn-primary" onclick="saveScreenConfig()" id="saveBtn">
                            <i class="bi bi-check-lg"></i> Save Order &amp; Routes
                        </button>
                    </div>
                </div>
                <div class="card-body" style="max-height: 600px; overflow-y: auto;">
                    <?php if (empty($selectedScreens)): ?>
                    <div class="text-center py-4" id="emptySelected">
                        <i class="bi bi-arrow-left-circle display-6 text-muted"></i>
                        <p class="text-muted mt-2 mb-0">No screens added yet.</p>
                        <small class="text-muted">Add screens from the left panel to include them in the app.</small>
                    </div>
                    <?php endif; ?>
                    <div id="selectedList">
                        <?php foreach ($selectedScreens as $idx => $screen): ?>
                        <div class="card mb-2 selected-screen-item" data-screen-id="<?= (int) $screen->id ?>">
                            <div class="card-body py-2 px-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-grip-vertical text-muted" style="cursor: grab;" title="Drag to reorder"></i>
                                        <strong><?= h($screen->screen_title ?? 'Screen') ?></strong>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <a href="/detachableappbuilder/index/<?= $appId ?>?screen_id=<?= (int) $screen->id ?>"
                                           class="btn btn-sm btn-outline-info" title="Edit with block editor">
                                            <i class="bi bi-bricks"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeScreen(<?= (int) $screen->id ?>)" title="Remove screen">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="row align-items-center">
                                    <div class="col-md-7">
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">Route</span>
                                            <input type="text" class="form-control font-monospace screen-route"
                                                   value="<?= h($screen->route_path ?? '/') ?>"
                                                   placeholder="/page-name">
                                        </div>
                                    </div>
                                    <div class="col-md-5">
                                        <div class="form-check">
                                            <input class="form-check-input screen-default" type="radio" name="is_default"
                                                   value="<?= (int) $screen->id ?>"
                                                   <?= !empty($screen->is_default) ? 'checked' : '' ?>>
                                            <label class="form-check-label small">Default screen</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Build shortcut -->
    <div class="text-center mt-2">
        <form method="POST" action="/detachableapps/build/<?= $appId ?>" class="d-inline" onsubmit="return confirm('Start a new build?')">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            <button type="submit" class="btn btn-warning">
                <i class="bi bi-hammer"></i> Build App Now
            </button>
        </form>
    </div>
</div>

<script>
const csrfToken = '<?= $_SESSION['csrf_token'] ?? '' ?>';
const appId = <?= $appId ?>;

function addScreen(screen) {
    const screenId = screen.id || screen.screen_id || '';
    const screenTitle = screen.title || screen.name || 'Untitled';
    const downloadUrl = screen.download_url || '';

    // Generate route path from title
    const routePath = '/' + screenTitle.toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');

    const body = new URLSearchParams({
        csrf_token: csrfToken,
        stitch_screen_eid: screenId,
        screen_title: screenTitle,
        route_path: routePath,
        download_url: downloadUrl
    });

    fetch('/detachableapps/addscreen/' + appId, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Failed to add screen'));
        }
    })
    .catch(err => alert('Error: ' + err.message));
}

function removeScreen(screenId) {
    if (!confirm('Remove this screen from the app?')) return;

    const body = new URLSearchParams({
        csrf_token: csrfToken,
        screen_id: screenId
    });

    fetch('/detachableapps/removescreen/' + appId, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.error || 'Failed to remove screen'));
        }
    })
    .catch(err => alert('Error: ' + err.message));
}

function saveScreenConfig() {
    const btn = document.getElementById('saveBtn');
    const origHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';

    const items = document.querySelectorAll('.selected-screen-item');
    const screens = [];

    items.forEach((item, index) => {
        const screenId = item.dataset.screenId;
        const route = item.querySelector('.screen-route')?.value || '/';
        const isDefault = item.querySelector('.screen-default')?.checked ? 1 : 0;

        screens.push({
            id: screenId,
            route_path: route,
            sort_order: index,
            is_default: isDefault
        });
    });

    fetch('/detachableapps/updatescreens/' + appId, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            csrf_token: csrfToken,
            screens: screens
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            btn.innerHTML = '<i class="bi bi-check-lg text-success"></i> Saved!';
            setTimeout(() => { btn.innerHTML = origHTML; btn.disabled = false; }, 1500);
        } else {
            alert('Error: ' + (data.error || 'Failed to save'));
            btn.innerHTML = origHTML;
            btn.disabled = false;
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
        btn.innerHTML = origHTML;
        btn.disabled = false;
    });
}
</script>
