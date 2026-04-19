<?php
$appId = (int) ($app->id ?? 0);
$appName = h($app->name ?? 'App');
$currentVersion = $app->version ?? '';
?>
<div class="container py-4" style="max-width: 960px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="/apps">Apps</a></li>
                    <li class="breadcrumb-item"><a href="/apps/form/<?= $appId ?>"><?= $appName ?></a></li>
                    <li class="breadcrumb-item active">Releases</li>
                </ol>
            </nav>
            <h1 class="h2 mb-1">
                <i class="bi bi-tag text-primary"></i> Releases &mdash; <?= $appName ?>
                <?php if (!empty($currentVersion)): ?>
                <span class="badge bg-primary ms-2">v<?= h($currentVersion) ?></span>
                <?php endif; ?>
            </h1>
            <p class="text-muted mb-0">Release history and version management</p>
        </div>
        <a href="/apps/form/<?= $appId ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to App
        </a>
    </div>

    <!-- Create Release Form -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-plus-circle"></i> Create Release
        </div>
        <div class="card-body">
            <form method="POST" action="/apps/release/<?= $appId ?>" id="releaseForm">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label for="version" class="form-label">Version</label>
                        <input type="text" class="form-control" id="version" name="version"
                               placeholder="Leave blank to auto-increment"
                               value="">
                        <div class="form-text">
                            <?php if (!empty($currentVersion)): ?>
                            Current: v<?= h($currentVersion) ?>
                            <?php else: ?>
                            First release will be v1.0.0 if left blank
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-8 mb-3">
                        <label for="release_notes" class="form-label">Release Notes</label>
                        <textarea class="form-control" id="release_notes" name="release_notes" rows="3"
                                  placeholder="What changed in this release?"></textarea>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-tag"></i> Create Release
                </button>
            </form>
        </div>
    </div>

    <!-- Release History -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-clock-history"></i> Release History
            <span class="badge bg-secondary ms-1"><?= count($releases) ?></span>
        </div>
        <?php if (empty($releases)): ?>
        <div class="card-body text-center py-5">
            <i class="bi bi-tag display-4 text-muted"></i>
            <h4 class="mt-3">No releases yet</h4>
            <p class="text-muted mb-0">Build the app and create your first release using the form above.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Version</th>
                        <th>Date</th>
                        <th>Commit</th>
                        <th>Release Notes</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($releases as $release):
                        $releaseId = (int) ($release->id ?? $release['id'] ?? 0);
                        $version = $release->version ?? $release['version'] ?? '';
                        $createdAt = $release->created_at ?? $release['created_at'] ?? '';
                        $commitSha = $release->commit_sha ?? $release['commit_sha'] ?? '';
                        $notes = $release->release_notes ?? $release['release_notes'] ?? '';
                    ?>
                    <tr>
                        <td>
                            <span class="badge bg-primary">v<?= h($version) ?></span>
                        </td>
                        <td>
                            <?php if ($createdAt): ?>
                            <span title="<?= h($createdAt) ?>">
                                <?= date('M j, Y g:i A', strtotime($createdAt)) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($commitSha): ?>
                            <code class="small"><?= h(substr($commitSha, 0, 7)) ?></code>
                            <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($notes): ?>
                            <span title="<?= h($notes) ?>"><?= h(mb_strimwidth($notes, 0, 80, '...')) ?></span>
                            <?php else: ?>
                            <span class="text-muted">No notes</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="/apps/download/<?= $appId ?>?release=<?= $releaseId ?>"
                               class="btn btn-sm btn-outline-success" title="Download this release">
                                <i class="bi bi-download"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
