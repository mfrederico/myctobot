<div class="container py-4" data-assist-page="stitch-screens" data-assist-purpose="View screens for a Stitch project">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-3">
            <li class="breadcrumb-item"><a href="/stitch">Stitch</a></li>
            <li class="breadcrumb-item"><a href="/stitch/browse/<?= $connectionId ?>"><?= h($connection->external_eid ?: 'Projects') ?></a></li>
            <li class="breadcrumb-item active"><?= h($project['title'] ?? 'Screens') ?></li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1"><i class="bi bi-grid-3x3 text-info me-2"></i><?= h($project['title'] ?? 'Project Screens') ?></h1>
            <p class="text-muted mb-0"><?= count($screens) ?> screen(s)</p>
        </div>
        <a href="/stitch/browse/<?= $connectionId ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Projects
        </a>
    </div>

    <?php if (empty($screens)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>
        No screens found in this project. Screens will appear here after generation via a pipeline or the Stitch interface.
        <p class="mt-2 mb-0 small">
            <strong>Tip:</strong> Screen details may need to be loaded individually. If you generated screens via a pipeline, check the pipeline run output for screen data.
        </p>
    </div>
    <?php else: ?>
    <div class="row" id="screensGrid">
        <?php foreach ($screens as $screen): ?>
        <?php
            $sName = $screen['name'] ?? '';
            $sTitle = $screen['title'] ?? $screen['displayName'] ?? 'Untitled';
            $sScreenshot = $screen['screenshot']['downloadUrl'] ?? $screen['screenshotUrl'] ?? $screen['screenshot_url'] ?? '';
            $sWidth = $screen['width'] ?? $screen['dimensions']['width'] ?? '';
            $sHeight = $screen['height'] ?? $screen['dimensions']['height'] ?? '';
            $sDeviceType = $screen['deviceType'] ?? $screen['device_type'] ?? '';
            $sCodeUrl = $screen['htmlCode']['downloadUrl'] ?? $screen['codeDownloadUrl'] ?? $screen['code_download_url'] ?? '';
            // Extract screen ID from name "projects/123/screens/456" or use 'id' field
            $sId = $screen['id'] ?? '';
            if (empty($sId) && preg_match('/screens\/([^\/]+)$/', $sName, $m)) {
                $sId = $m[1];
            } elseif (empty($sId) && isset($screen['screenId'])) {
                $sId = $screen['screenId'];
            }
        ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 screen-card">
                <?php if ($sScreenshot): ?>
                <div class="card-img-top-wrapper" style="max-height: 250px; overflow: hidden; background: #f8f9fa;">
                    <img src="<?= h($sScreenshot) ?>" alt="<?= h($sTitle) ?>"
                         class="card-img-top" style="object-fit: cover; object-position: top; height: 250px; width: 100%;"
                         loading="lazy"
                         onerror="this.parentElement.innerHTML='<div class=\'text-center py-5 text-muted\'><i class=\'bi bi-image fs-1\'></i><br>Screenshot unavailable</div>'">
                </div>
                <?php else: ?>
                <div class="card-img-top text-center py-5 bg-light text-muted" data-screen-id="<?= h($sId) ?>">
                    <i class="bi bi-image fs-1"></i><br>
                    <small>Loading...</small>
                </div>
                <?php endif; ?>
                <div class="card-body">
                    <h6 class="card-title mb-2"><?= h($sTitle) ?></h6>
                    <div class="mb-2">
                        <?php if ($sWidth && $sHeight): ?>
                        <span class="badge bg-light text-dark border me-1"><?= (int)$sWidth ?> x <?= (int)$sHeight ?></span>
                        <?php endif; ?>
                        <?php if ($sDeviceType): ?>
                        <span class="badge bg-info-subtle text-info">
                            <i class="bi bi-<?= $sDeviceType === 'MOBILE' ? 'phone' : ($sDeviceType === 'TABLET' ? 'tablet' : 'display') ?> me-1"></i><?= h($sDeviceType) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($sId): ?>
                <div class="card-footer bg-transparent d-flex gap-1">
                    <?php $screenQs = 'screen=' . urlencode($sId) . ($sCodeUrl ? '&url=' . urlencode($sCodeUrl) : ''); ?>
                    <a href="/stitch/previewhtml/<?= $connectionId ?>/<?= h($projectId) ?>?<?= $screenQs ?>"
                       class="btn btn-outline-primary btn-sm flex-fill" target="_blank" title="Preview in browser">
                        <i class="bi bi-eye"></i> Preview
                    </a>
                    <a href="/stitch/downloadhtml/<?= $connectionId ?>/<?= h($projectId) ?>?<?= $screenQs ?>"
                       class="btn btn-outline-success btn-sm flex-fill" title="Download HTML file">
                        <i class="bi bi-download"></i> HTML
                    </a>
                    <a href="/stitch/viewscreen/<?= $connectionId ?>/<?= h($projectId) ?>?screen=<?= urlencode($sId) ?>"
                       class="btn btn-outline-secondary btn-sm" title="View details">
                        <i class="bi bi-info-circle"></i>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.screen-card {
    transition: transform 0.2s, box-shadow 0.2s;
}
.screen-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
</style>
