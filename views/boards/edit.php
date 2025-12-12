<div class="container-fluid">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>Edit Board Settings</h1>
                <a href="/boards" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Boards
                </a>
            </div>

            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-kanban"></i> <?= htmlspecialchars($board['board_name']) ?>
                    <code class="ms-2 text-white-50"><?= htmlspecialchars($board['project_key']) ?></code>
                </div>
                <div class="card-body">
                    <form method="POST" action="/boards/edit/<?= $board['id'] ?>">
                        <!-- Board Info (Read-only) -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label class="form-label text-muted">Board ID</label>
                                <input type="text" class="form-control" value="<?= $board['board_id'] ?>" readonly disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted">Site</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($board['site_name'] ?? 'Unknown') ?>" readonly disabled>
                            </div>
                        </div>

                        <hr>

                        <!-- Enable/Disable -->
                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="enabled" name="enabled" <?= $board['enabled'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="enabled">
                                    <strong>Enable Board Tracking</strong>
                                </label>
                            </div>
                            <small class="text-muted">When disabled, this board won't be available for analysis or digests.</small>
                        </div>

                        <!-- Status Filter -->
                        <div class="mb-4">
                            <label for="status_filter" class="form-label"><strong>Status Filter</strong></label>
                            <input type="text" class="form-control" id="status_filter" name="status_filter"
                                   value="<?= htmlspecialchars($board['status_filter'] ?? 'To Do') ?>"
                                   placeholder="To Do, In Progress">
                            <small class="text-muted">Comma-separated list of Jira statuses to include in analysis. Leave empty for all statuses.</small>
                        </div>

                        <hr>

                        <!-- Daily Digest Settings -->
                        <h5 class="mb-3"><i class="bi bi-envelope"></i> Daily Digest Settings</h5>

                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="digest_enabled" name="digest_enabled"
                                       <?= $board['digest_enabled'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="digest_enabled">
                                    <strong>Enable Daily Digest</strong>
                                </label>
                            </div>
                            <small class="text-muted">Receive an automated email digest every day at your preferred time.</small>
                        </div>

                        <div class="row mb-3" id="digest_options">
                            <div class="col-md-6">
                                <label for="digest_time" class="form-label">Digest Time</label>
                                <input type="time" class="form-control" id="digest_time" name="digest_time"
                                       value="<?= htmlspecialchars($board['digest_time'] ?? '08:00') ?>">
                                <small class="text-muted">Time to receive the daily digest.</small>
                            </div>
                            <div class="col-md-6">
                                <label for="timezone" class="form-label">Timezone</label>
                                <select class="form-select" id="timezone" name="timezone">
                                    <?php
                                    $timezones = [
                                        'UTC' => 'UTC',
                                        'America/New_York' => 'Eastern Time (US)',
                                        'America/Chicago' => 'Central Time (US)',
                                        'America/Denver' => 'Mountain Time (US)',
                                        'America/Los_Angeles' => 'Pacific Time (US)',
                                        'Europe/London' => 'London',
                                        'Europe/Paris' => 'Paris',
                                        'Europe/Berlin' => 'Berlin',
                                        'Asia/Tokyo' => 'Tokyo',
                                        'Asia/Shanghai' => 'Shanghai',
                                        'Australia/Sydney' => 'Sydney'
                                    ];
                                    $currentTz = $board['timezone'] ?? 'UTC';
                                    foreach ($timezones as $tz => $label):
                                    ?>
                                    <option value="<?= $tz ?>" <?= $currentTz === $tz ? 'selected' : '' ?>>
                                        <?= $label ?> (<?= $tz ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-4" id="digest_cc_section">
                            <label for="digest_cc" class="form-label">CC Recipients</label>
                            <input type="text" class="form-control" id="digest_cc" name="digest_cc"
                                   value="<?= htmlspecialchars($board['digest_cc'] ?? '') ?>"
                                   placeholder="email1@example.com, email2@example.com">
                            <small class="text-muted">Comma-separated list of email addresses to CC on the daily digest. Your email will always be the primary recipient.</small>
                        </div>

                        <hr>

                        <!-- Actions -->
                        <div class="d-flex justify-content-between">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check"></i> Save Changes
                            </button>
                            <button type="button" class="btn btn-outline-danger" onclick="removeBoard()">
                                <i class="bi bi-trash"></i> Remove Board
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card mt-4">
                <div class="card-header">
                    <i class="bi bi-lightning"></i> Quick Actions
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="/analysis/run/<?= $board['id'] ?>" class="btn btn-success">
                            <i class="bi bi-play-circle"></i> Run Analysis Now
                        </a>
                        <?php if (!empty($lastAnalysis)): ?>
                        <a href="/analysis/view/<?= $lastAnalysis['id'] ?>" class="btn btn-outline-primary">
                            <i class="bi bi-eye"></i> View Last Analysis
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Analysis History -->
            <?php if (!empty($analyses)): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <i class="bi bi-clock-history"></i> Recent Analyses
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($analyses as $analysis): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-muted"><?= date('M j, Y H:i', strtotime($analysis['created_at'])) ?></small>
                                <span class="badge bg-secondary ms-2"><?= $analysis['analysis_type'] ?></span>
                            </div>
                            <a href="/analysis/view/<?= $analysis['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.getElementById('digest_enabled').addEventListener('change', function() {
    document.getElementById('digest_options').style.opacity = this.checked ? '1' : '0.5';
});

// Initialize opacity
document.getElementById('digest_options').style.opacity =
    document.getElementById('digest_enabled').checked ? '1' : '0.5';

function removeBoard() {
    if (confirm('Are you sure you want to remove this board? This will delete all analysis history.')) {
        fetch('/boards/remove/<?= $board['id'] ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.href = '/boards';
            } else {
                alert('Error: ' + (data.message || 'Failed to remove board'));
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        });
    }
}
</script>
