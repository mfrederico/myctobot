<div class="container py-4" data-assist-page="stitch-viewscreen" data-assist-purpose="View details of a single Stitch screen">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-3">
            <li class="breadcrumb-item"><a href="/stitch">Stitch</a></li>
            <li class="breadcrumb-item"><a href="/stitch/browse/<?= $connectionId ?>"><?= h($connection->external_eid ?: 'Projects') ?></a></li>
            <li class="breadcrumb-item"><a href="/stitch/screens/<?= $connectionId ?>/<?= h($projectId) ?>">Screens</a></li>
            <li class="breadcrumb-item active"><?= h($screen['title'] ?? 'Screen') ?></li>
        </ol>
    </nav>

    <?php
        $sTitle = $screen['title'] ?? $screen['displayName'] ?? 'Untitled';
        $sScreenshot = $screen['screenshot']['downloadUrl'] ?? $screen['screenshotUrl'] ?? $screen['screenshot_url'] ?? '';
        $sWidth = $screen['width'] ?? $screen['dimensions']['width'] ?? '';
        $sHeight = $screen['height'] ?? $screen['dimensions']['height'] ?? '';
        $sDeviceType = $screen['deviceType'] ?? $screen['device_type'] ?? '';
        $sCodeUrl = $screen['htmlCode']['downloadUrl'] ?? $screen['codeDownloadUrl'] ?? $screen['code_download_url'] ?? '';
        $sTheme = $screen['theme'] ?? [];
        $sColorMode = $sTheme['colorMode'] ?? $sTheme['color_mode'] ?? '';
        $sCustomColor = $sTheme['customColor'] ?? $sTheme['custom_color'] ?? '';
        $sPrompt = $screen['prompt'] ?? $screen['generationPrompt'] ?? '';
        $sStitchUrl = '';
        if (preg_match('/projects\/(\d+)/', $screen['name'] ?? '', $m)) {
            $sStitchUrl = 'https://stitch.withgoogle.com/project/' . $m[1];
        }
    ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2 mb-0"><i class="bi bi-image text-info me-2"></i><?= h($sTitle) ?></h1>
        <?php $screenQs = 'screen=' . urlencode($screenId) . ($sCodeUrl ? '&url=' . urlencode($sCodeUrl) : ''); ?>
        <div class="d-flex gap-2">
            <a href="/stitch/previewhtml/<?= $connectionId ?>/<?= h($projectId) ?>?<?= $screenQs ?>"
               class="btn btn-primary" target="_blank">
                <i class="bi bi-eye me-1"></i> Preview HTML
            </a>
            <a href="/stitch/downloadhtml/<?= $connectionId ?>/<?= h($projectId) ?>?<?= $screenQs ?>"
               class="btn btn-success">
                <i class="bi bi-download me-1"></i> Download HTML
            </a>
            <?php if ($sStitchUrl): ?>
            <a href="<?= h($sStitchUrl) ?>" class="btn btn-outline-info" target="_blank">
                <i class="bi bi-box-arrow-up-right me-1"></i> Open in Stitch
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row">
        <!-- Screenshot Preview -->
        <div class="col-lg-8 mb-4">
            <div class="card">
                <div class="card-header bg-light">
                    <i class="bi bi-image me-1"></i> Screenshot Preview
                </div>
                <div class="card-body p-0">
                    <?php if ($sScreenshot): ?>
                    <div style="max-height: 80vh; overflow: auto; background: #f0f0f0;">
                        <img src="<?= h($sScreenshot) ?>" alt="<?= h($sTitle) ?>"
                             style="width: 100%; display: block;"
                             onerror="this.parentElement.innerHTML='<div class=\'text-center py-5 text-muted\'><i class=\'bi bi-image fs-1\'></i><br>Screenshot unavailable</div>'">
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-image fs-1"></i><br>
                        No screenshot available
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Metadata Sidebar -->
        <div class="col-lg-4 mb-4">
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <i class="bi bi-info-circle me-1"></i> Screen Details
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th class="text-muted" style="width: 40%;">Title</th>
                            <td><?= h($sTitle) ?></td>
                        </tr>
                        <?php if ($sWidth && $sHeight): ?>
                        <tr>
                            <th class="text-muted">Dimensions</th>
                            <td><?= (int)$sWidth ?> x <?= (int)$sHeight ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($sDeviceType): ?>
                        <tr>
                            <th class="text-muted">Device</th>
                            <td>
                                <i class="bi bi-<?= $sDeviceType === 'MOBILE' ? 'phone' : ($sDeviceType === 'TABLET' ? 'tablet' : 'display') ?> me-1"></i>
                                <?= h($sDeviceType) ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($sColorMode): ?>
                        <tr>
                            <th class="text-muted">Color Mode</th>
                            <td><?= h($sColorMode) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($sCustomColor): ?>
                        <tr>
                            <th class="text-muted">Custom Color</th>
                            <td>
                                <span class="d-inline-block rounded me-1" style="width: 16px; height: 16px; background: <?= h($sCustomColor) ?>; vertical-align: middle; border: 1px solid #ccc;"></span>
                                <code><?= h($sCustomColor) ?></code>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (isset($screen['name'])): ?>
                        <tr>
                            <th class="text-muted">Resource</th>
                            <td><code class="small"><?= h($screen['name']) ?></code></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <?php if ($sPrompt): ?>
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <i class="bi bi-chat-dots me-1"></i> Generation Prompt
                </div>
                <div class="card-body">
                    <p class="mb-0 small"><?= nl2br(h($sPrompt)) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($screen)): ?>
            <div class="card">
                <div class="card-header bg-light">
                    <i class="bi bi-braces me-1"></i> Raw Data
                    <button class="btn btn-sm btn-link float-end p-0" type="button" data-bs-toggle="collapse" data-bs-target="#rawScreenData">
                        Show/Hide
                    </button>
                </div>
                <div class="collapse" id="rawScreenData">
                    <div class="card-body p-0">
                        <pre class="mb-0 p-2 small" style="max-height: 300px; overflow: auto;"><?= h(json_encode($screen, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
