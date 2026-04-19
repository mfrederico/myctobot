<div class="container py-4" data-assist-page="stitch-browse" data-assist-purpose="Browse Google Stitch projects for a connection">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-3">
            <li class="breadcrumb-item"><a href="/stitch">Stitch</a></li>
            <li class="breadcrumb-item"><a href="/stitch"><?= h($connection->external_eid ?: $connection->connection_name ?: 'Connection') ?></a></li>
            <li class="breadcrumb-item active">Projects</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1"><i class="bi bi-folder text-info me-2"></i>Stitch Projects</h1>
            <p class="text-muted mb-0"><?= h($connection->external_eid ?: $connection->connection_name) ?></p>
        </div>
        <a href="/stitch" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>

    <?php if (empty($projects)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>
        No projects found. Create one via the Stitch AI interface or a pipeline.
    </div>
    <?php else: ?>
    <div class="row">
        <?php foreach ($projects as $project): ?>
        <?php
            $pName = $project['name'] ?? '';
            $pTitle = $project['title'] ?? $project['displayName'] ?? 'Untitled Project';
            $pType = $project['type'] ?? $project['projectType'] ?? 'DESIGN';
            $pVisibility = $project['visibility'] ?? 'PRIVATE';
            // Extract numeric project ID from "projects/12345"
            $pId = '';
            if (preg_match('/projects\/(\d+)/', $pName, $m)) {
                $pId = $m[1];
            } elseif (isset($project['projectId'])) {
                $pId = $project['projectId'];
            }
            $pCreateTime = $project['createTime'] ?? $project['created_at'] ?? '';
            $pUpdateTime = $project['updateTime'] ?? $project['updated_at'] ?? '';
        ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 border-info-subtle">
                <div class="card-body">
                    <h5 class="card-title mb-2">
                        <i class="bi bi-palette text-info me-1"></i>
                        <?= h($pTitle) ?>
                    </h5>
                    <div class="mb-3">
                        <span class="badge bg-info-subtle text-info me-1"><?= h($pType) ?></span>
                        <?php if ($pVisibility === 'PRIVATE'): ?>
                        <span class="badge bg-secondary-subtle text-secondary"><i class="bi bi-lock me-1"></i>Private</span>
                        <?php else: ?>
                        <span class="badge bg-success-subtle text-success"><i class="bi bi-globe me-1"></i>Public</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($pUpdateTime): ?>
                    <p class="text-muted small mb-0">
                        <i class="bi bi-clock me-1"></i>Updated: <?= date('M j, Y', strtotime($pUpdateTime)) ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php if ($pId): ?>
                <div class="card-footer bg-transparent border-0 pt-0">
                    <a href="/stitch/screens/<?= $connectionId ?>/<?= h($pId) ?>" class="btn btn-outline-info btn-sm w-100">
                        <i class="bi bi-grid-3x3 me-1"></i> View Screens
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
