<div class="container py-4">
    <div class="row">
        <div class="col-12">
            <h1 class="h3 mb-4">Plugin Registry Cache</h1>
        </div>
    </div>

    <!-- Cache Actions -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="btn-group" role="group">
                <a href="/admin/plugincache?action=refresh" class="btn btn-primary">
                    <i class="bi bi-arrow-clockwise"></i> Refresh Cache
                </a>
                <a href="/admin/plugincache?action=invalidate" class="btn btn-danger"
                   onclick="return confirm('Invalidate plugin cache? The cache will be empty until the next refresh.')">
                    <i class="bi bi-trash"></i> Invalidate Cache
                </a>
                <a href="/admin/plugincache?action=warmup" class="btn btn-success">
                    <i class="bi bi-lightning"></i> Warm Up Cache
                </a>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Cache Statistics -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-speedometer2"></i> Cache Statistics</h5>
                </div>
                <div class="card-body">
                    <?php if (isset($cache_stats) && is_array($cache_stats)): ?>
                    <div class="row">
                        <div class="col-6">
                            <p><strong>APCu Available:</strong></p>
                            <p><strong>Cache Loaded:</strong></p>
                            <p><strong>In APCu:</strong></p>
                            <p><strong>Cached Plugins:</strong></p>
                            <p><strong>Memory Usage:</strong></p>
                            <p><strong>Last Refresh:</strong></p>
                            <p><strong>Last Duration:</strong></p>
                            <p><strong>TTL:</strong></p>
                            <p><strong>TTL Remaining:</strong></p>
                        </div>
                        <div class="col-6">
                            <p>
                                <?php if ($cache_stats['apcu_available']): ?>
                                    <span class="badge bg-success">Yes</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">No (using file cache)</span>
                                <?php endif; ?>
                            </p>
                            <p>
                                <?php if ($cache_stats['cache_loaded']): ?>
                                    <span class="badge bg-success">Yes</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">No</span>
                                <?php endif; ?>
                            </p>
                            <p>
                                <?php if ($cache_stats['in_apcu']): ?>
                                    <span class="badge bg-success">Yes</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">No</span>
                                <?php endif; ?>
                            </p>
                            <p><strong><?= number_format($cache_stats['count'] ?? 0) ?></strong></p>
                            <p><?= number_format(($cache_stats['memory'] ?? 0) / 1024, 2) ?> KB</p>
                            <p>
                                <?php if ($cache_stats['last_refresh']): ?>
                                    <?= htmlspecialchars($cache_stats['last_refresh_formatted']) ?>
                                <?php else: ?>
                                    <span class="text-muted">Never</span>
                                <?php endif; ?>
                            </p>
                            <p>
                                <?php if ($cache_stats['last_duration_ms']): ?>
                                    <?= number_format($cache_stats['last_duration_ms'], 2) ?> ms
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </p>
                            <p><?= number_format($cache_stats['ttl'] ?? 3600) ?> seconds</p>
                            <p>
                                <?php if ($cache_stats['ttl_remaining'] !== null): ?>
                                    <?php
                                    $remaining = $cache_stats['ttl_remaining'];
                                    $ttl = $cache_stats['ttl'] ?? 3600;
                                    $percent = $ttl > 0 ? ($remaining / $ttl) * 100 : 0;
                                    $badgeClass = $percent > 50 ? 'bg-success' : ($percent > 20 ? 'bg-warning' : 'bg-danger');
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= number_format($remaining) ?>s</span>
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <?php if ($cache_stats['ttl_remaining'] !== null && $cache_stats['ttl'] > 0): ?>
                    <?php
                    $remaining = $cache_stats['ttl_remaining'];
                    $ttl = $cache_stats['ttl'];
                    $percent = ($remaining / $ttl) * 100;
                    $barClass = $percent > 50 ? 'bg-success' : ($percent > 20 ? 'bg-warning' : 'bg-danger');
                    ?>
                    <div class="progress mt-3" style="height: 10px;">
                        <div class="progress-bar <?= $barClass ?>"
                             role="progressbar"
                             style="width: <?= $percent ?>%"
                             aria-valuenow="<?= $percent ?>"
                             aria-valuemin="0"
                             aria-valuemax="100"
                             title="TTL Remaining: <?= number_format($remaining) ?>s">
                        </div>
                    </div>
                    <small class="text-muted">TTL Progress (refreshes automatically when bar reaches 0)</small>
                    <?php endif; ?>
                    <?php else: ?>
                    <p class="text-muted">No cache statistics available.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Configured Sources -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-folder2-open"></i> Configured Sources</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($sources)): ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($sources as $source): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <code><?= htmlspecialchars($source) ?></code>
                            <?php if (filter_var($source, FILTER_VALIDATE_URL)): ?>
                                <span class="badge bg-primary">Remote</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Local</span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle"></i> No plugin sources configured.
                        <br><small>Add sources in <code>config.ini</code> under <code>[plugin_registry]</code> section.</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Refresh Errors -->
    <?php if (!empty($errors)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="bi bi-exclamation-octagon"></i> Last Refresh Errors (<?= count($errors) ?>)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Source</th>
                                    <th>Error</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($errors as $error): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($error['source'] ?? 'Unknown') ?></code></td>
                                    <td class="text-danger"><?= htmlspecialchars($error['error'] ?? 'Unknown error') ?></td>
                                    <td>
                                        <small class="text-muted"><?= htmlspecialchars($error['timestamp'] ?? 'N/A') ?></small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="bi bi-info-circle"></i> When a source fails to refresh, existing cached plugins from that source are preserved.
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Cached Plugins Table -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-plug"></i> Cached Plugins (<?= count($plugins ?? []) ?> entries)</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($plugins)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Version</th>
                                    <th>Author</th>
                                    <th>Source</th>
                                    <th>Scanned</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($plugins as $plugin): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($plugin['name'] ?? 'Unknown') ?></strong>
                                        <?php if (!empty($plugin['description'])): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars(substr($plugin['description'], 0, 100)) ?><?= strlen($plugin['description']) > 100 ? '...' : '' ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($plugin['version'] ?? '1.0.0') ?></span></td>
                                    <td><?= htmlspecialchars($plugin['author'] ?? 'Unknown') ?></td>
                                    <td>
                                        <code class="small"><?= htmlspecialchars(basename($plugin['source'] ?? 'unknown')) ?></code>
                                    </td>
                                    <td>
                                        <?php if (!empty($plugin['scanned_at'])): ?>
                                        <small class="text-muted"><?= date('Y-m-d H:i', $plugin['scanned_at']) ?></small>
                                        <?php else: ?>
                                        <small class="text-muted">N/A</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
                        <p class="text-muted mt-2">No plugins cached yet.</p>
                        <a href="/admin/plugincache?action=refresh" class="btn btn-primary">
                            <i class="bi bi-arrow-clockwise"></i> Refresh Cache Now
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
