<div class="container py-4" data-assist-page="admin/cache" data-assist-purpose="Manage application cache operations">
    <div class="row">
        <div class="col-12">
            <h1 class="h3 mb-4">Cache Management</h1>
        </div>
    </div>

    <!-- Cache Actions -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="btn-group" role="group">
                <a href="/admin/cache?action=clear" class="btn btn-danger"
                   onclick="return confirm('Clear all caches? This will temporarily slow down the site.')">
                    <i class="bi bi-trash"></i> Clear All Caches
                </a>
                <a href="/admin/cache?action=clear_query" class="btn btn-warning">
                    <i class="bi bi-arrow-clockwise"></i> Clear Query Cache Only
                </a>
                <a href="/admin/cache?action=reload" class="btn btn-info">
                    <i class="bi bi-arrow-repeat"></i> Reload Permission Cache
                </a>
                <a href="/admin/cache?action=warmup" class="btn btn-success">
                    <i class="bi bi-lightning"></i> Warm Up Cache
                </a>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Query Cache Stats -->
        <?php if (isset($query_cache_stats) && $query_cache_stats): ?>
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Query Cache Statistics</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6">
                            <p><strong>Status:</strong></p>
                            <p><strong>Hit Rate:</strong></p>
                            <p><strong>Total Hits:</strong></p>
                            <p><strong>Total Misses:</strong></p>
                            <p><strong>Cached Queries:</strong></p>
                            <p><strong>Cache Size:</strong></p>
                        </div>
                        <div class="col-6">
                            <p>
                                <?php if ($query_cache_stats['enabled']): ?>
                                    <span class="badge bg-success">Enabled</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Disabled</span>
                                <?php endif; ?>
                            </p>
                            <p>
                                <span class="badge bg-<?= $query_cache_stats['hit_rate'] > 80 ? 'success' : ($query_cache_stats['hit_rate'] > 50 ? 'warning' : 'danger') ?>">
                                    <?= number_format($query_cache_stats['hit_rate'], 1) ?>%
                                </span>
                            </p>
                            <p><?= number_format($query_cache_stats['hits']) ?></p>
                            <p><?= number_format($query_cache_stats['misses']) ?></p>
                            <p><?= number_format($query_cache_stats['cached_queries'] ?? 0) ?></p>
                            <p><?= number_format($query_cache_stats['cache_size_kb'] ?? 0, 2) ?> KB</p>
                        </div>
                    </div>

                    <?php if ($query_cache_stats['hit_rate'] > 0): ?>
                    <div class="progress mt-3">
                        <div class="progress-bar <?= $query_cache_stats['hit_rate'] > 80 ? 'bg-success' : ($query_cache_stats['hit_rate'] > 50 ? 'bg-warning' : 'bg-danger') ?>"
                             role="progressbar"
                             style="width: <?= $query_cache_stats['hit_rate'] ?>%"
                             aria-valuenow="<?= $query_cache_stats['hit_rate'] ?>"
                             aria-valuemin="0"
                             aria-valuemax="100">
                            <?= number_format($query_cache_stats['hit_rate'], 1) ?>% Hit Rate
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0">Query Cache</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">Query cache is not available. APCu may not be installed or enabled.</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Permission Cache Stats -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Permission Cache Statistics <small>(TikNix Only)</small></h5>
                </div>
                <div class="card-body">
                    <?php if (isset($cache_stats) && is_array($cache_stats)): ?>
                    <div class="alert alert-success alert-dismissible fade show py-2 mb-3" role="alert">
                        <small><i class="bi bi-check-circle"></i> These statistics reset when you clear the permission cache.</small>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <p><strong>Hit Rate:</strong></p>
                            <p><strong>Total Hits:</strong></p>
                            <p><strong>Total Misses:</strong></p>
                            <p><strong>Cached Permissions:</strong></p>
                            <p><strong>Memory Usage:</strong></p>
                            <p><strong>Cache Version:</strong></p>
                        </div>
                        <div class="col-6">
                            <p>
                                <span class="badge bg-<?= ($cache_stats['hit_rate'] ?? 0) > 80 ? 'success' : (($cache_stats['hit_rate'] ?? 0) > 50 ? 'warning' : 'danger') ?>">
                                    <?= number_format($cache_stats['hit_rate'] ?? 0, 1) ?>%
                                </span>
                            </p>
                            <p><?= number_format($cache_stats['hits'] ?? 0) ?></p>
                            <p><?= number_format($cache_stats['misses'] ?? 0) ?></p>
                            <p><?= number_format($cache_stats['count'] ?? 0) ?></p>
                            <p><?= number_format(($cache_stats['memory'] ?? 0) / 1024, 2) ?> KB</p>
                            <p><small class="text-muted"><?= $cache_stats['cache_version'] ?? 'N/A' ?></small></p>
                        </div>
                    </div>
                    <?php else: ?>
                    <p class="text-muted">No permission cache statistics available.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- APCu Stats -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-warning">
                    <h5 class="mb-0">APCu System Status <small class="text-muted">(Server-Wide)</small></h5>
                </div>
                <div class="card-body">
                    <?php if ($apcu_available && isset($apcu_info)): ?>
                    <div class="alert alert-info alert-dismissible fade show py-2 mb-3" role="alert">
                        <small><i class="bi bi-info-circle"></i> These are system-wide APCu statistics for all applications on this server, not just TikNix.</small>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <div class="row">
                        <div class="col-6">
                            <p><strong>Number of Entries:</strong></p>
                            <p><strong>Total Hits:</strong></p>
                            <p><strong>Total Misses:</strong></p>
                            <p><strong>Hit Rate:</strong></p>
                            <?php if (isset($apcu_info['sma'])): ?>
                            <p><strong>Memory Size:</strong></p>
                            <p><strong>Available Memory:</strong></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-6">
                            <p><?= number_format($apcu_info['num_entries'] ?? 0) ?></p>
                            <p><?= number_format($apcu_info['num_hits'] ?? 0) ?></p>
                            <p><?= number_format($apcu_info['num_misses'] ?? 0) ?></p>
                            <p>
                                <?php
                                $num_hits = $apcu_info['num_hits'] ?? 0;
                                $num_misses = $apcu_info['num_misses'] ?? 0;
                                $hit_rate = ($num_hits > 0 || $num_misses > 0) ?
                                    ($num_hits / ($num_hits + $num_misses)) * 100 : 0;
                                ?>
                                <span class="badge bg-<?= $hit_rate > 80 ? 'success' : ($hit_rate > 50 ? 'warning' : 'danger') ?>">
                                    <?= number_format($hit_rate, 1) ?>%
                                </span>
                            </p>
                            <?php if (isset($apcu_info['sma'])): ?>
                            <p><?= number_format($apcu_info['sma']['seg_size'] / 1024 / 1024, 2) ?> MB</p>
                            <p><?= number_format($apcu_info['sma']['avail_mem'] / 1024 / 1024, 2) ?> MB</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (isset($apcu_info['sma']['seg_size']) && isset($apcu_info['sma']['avail_mem'])): ?>
                    <?php
                    $seg_size = $apcu_info['sma']['seg_size'];
                    $avail_mem = $apcu_info['sma']['avail_mem'];
                    $used_mem = $seg_size - $avail_mem;
                    $usage_percent = ($used_mem / $seg_size) * 100;
                    ?>
                    <div class="progress mt-3">
                        <div class="progress-bar <?= $usage_percent < 70 ? 'bg-success' : ($usage_percent < 90 ? 'bg-warning' : 'bg-danger') ?>"
                             role="progressbar"
                             style="width: <?= $usage_percent ?>%"
                             aria-valuenow="<?= $usage_percent ?>"
                             aria-valuemin="0"
                             aria-valuemax="100">
                            <?= number_format($usage_percent, 1) ?>% Memory Used
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <p class="text-muted">APCu is not available or not enabled.</p>
                    <p><small>To enable: <code>apt-get install php-apcu</code></small></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- OPcache Stats -->
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">OPcache Status</h5>
                </div>
                <div class="card-body">
                    <?php if (isset($opcache_stats) && $opcache_stats): ?>
                    <div class="row">
                        <div class="col-6">
                            <p><strong>Status:</strong></p>
                            <p><strong>Memory Usage:</strong></p>
                            <p><strong>Free Memory:</strong></p>
                            <p><strong>Wasted Memory:</strong></p>
                            <p><strong>Cached Scripts:</strong></p>
                            <p><strong>Hit Rate:</strong></p>
                        </div>
                        <div class="col-6">
                            <p>
                                <?php if ($opcache_stats['opcache_enabled']): ?>
                                    <span class="badge bg-success">Enabled</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Disabled</span>
                                <?php endif; ?>
                            </p>
                            <p><?= number_format($opcache_stats['memory_usage']['used_memory'] / 1024 / 1024, 2) ?> MB</p>
                            <p><?= number_format($opcache_stats['memory_usage']['free_memory'] / 1024 / 1024, 2) ?> MB</p>
                            <p><?= number_format($opcache_stats['memory_usage']['wasted_memory'] / 1024 / 1024, 2) ?> MB</p>
                            <p><?= number_format($opcache_stats['opcache_statistics']['num_cached_scripts'] ?? 0) ?></p>
                            <p>
                                <span class="badge bg-<?= $opcache_stats['opcache_statistics']['opcache_hit_rate'] > 95 ? 'success' : 'warning' ?>">
                                    <?= number_format($opcache_stats['opcache_statistics']['opcache_hit_rate'] ?? 0, 1) ?>%
                                </span>
                            </p>
                        </div>
                    </div>
                    <?php else: ?>
                    <p class="text-muted">OPcache is not available or not enabled.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Cached Permissions Table -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Cached Permissions (<?= count($permissions ?? []) ?> entries)</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($permissions)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>Controller</th>
                                    <th>Method</th>
                                    <th>Required Level</th>
                                    <th>Access</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($permissions as $key => $perm):
                                    if (is_array($perm) && isset($perm['control'], $perm['method'], $perm['level'])):
                                ?>
                                <tr>
                                    <td><code><?= h($perm['control']) ?></code></td>
                                    <td><code><?= h($perm['method']) ?></code></td>
                                    <td>
                                        <?php
                                        $level = $perm['level'];
                                        if ($level <= 1) {
                                            echo '<span class="badge bg-danger">ROOT (1)</span>';
                                        } elseif ($level <= 50) {
                                            echo '<span class="badge bg-warning">ADMIN (50)</span>';
                                        } elseif ($level <= 100) {
                                            echo '<span class="badge bg-info">MEMBER (100)</span>';
                                        } else {
                                            echo '<span class="badge bg-success">PUBLIC (101)</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($level > 100): ?>
                                            <i class="bi bi-unlock text-success"></i> Public
                                        <?php elseif ($level > 50): ?>
                                            <i class="bi bi-person-check text-info"></i> Members
                                        <?php elseif ($level > 1): ?>
                                            <i class="bi bi-shield-check text-warning"></i> Admins
                                        <?php else: ?>
                                            <i class="bi bi-shield-lock text-danger"></i> Root Only
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php
                                    endif;
                                endforeach;
                                ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-muted">No permissions cached yet. Click "Reload Permission Cache" to populate.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>