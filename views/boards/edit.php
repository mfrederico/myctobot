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

                        <?php
                        // Decode priority weights and goals from JSON
                        $weights = json_decode($board['priority_weights'] ?? '{}', true) ?: [];
                        $goals = json_decode($board['goals'] ?? '{}', true) ?: [];
                        ?>

                        <!-- Priority Weights Section (Pro Feature) -->
                        <div class="mb-4 <?= $isPro ? '' : 'pro-feature-locked' ?>">
                            <h5 class="mb-3">
                                <i class="bi bi-sliders"></i> Priority Weights
                                <?php if (!$isPro): ?>
                                <span class="badge bg-warning text-dark ms-2">Pro Feature</span>
                                <?php endif; ?>
                            </h5>
                            <p class="text-muted small mb-3">Configure how the AI prioritizes tasks during analysis. Each weight influences the ranking algorithm.</p>

                            <!-- Quick Wins -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input weight-toggle" type="checkbox" id="weight_quick_wins_enabled" name="weight_quick_wins_enabled"
                                               <?= ($weights['quick_wins']['enabled'] ?? false) ? 'checked' : '' ?> <?= $isPro ? '' : 'disabled' ?>>
                                        <label class="form-check-label" for="weight_quick_wins_enabled"><strong>Quick Wins</strong></label>
                                    </div>
                                    <span class="badge bg-primary weight-value" id="weight_quick_wins_value"><?= $weights['quick_wins']['value'] ?? 50 ?>%</span>
                                </div>
                                <input type="range" class="form-range" id="weight_quick_wins" name="weight_quick_wins"
                                       min="0" max="100" value="<?= $weights['quick_wins']['value'] ?? 50 ?>" <?= $isPro ? '' : 'disabled' ?>>
                                <small class="text-muted">Favor low-effort, high-impact tasks that can be completed quickly.</small>
                            </div>

                            <!-- Task Synergy -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input weight-toggle" type="checkbox" id="weight_synergy_enabled" name="weight_synergy_enabled"
                                               <?= ($weights['synergy']['enabled'] ?? false) ? 'checked' : '' ?> <?= $isPro ? '' : 'disabled' ?>>
                                        <label class="form-check-label" for="weight_synergy_enabled"><strong>Task Synergy</strong></label>
                                    </div>
                                    <span class="badge bg-primary weight-value" id="weight_synergy_value"><?= $weights['synergy']['value'] ?? 30 ?>%</span>
                                </div>
                                <input type="range" class="form-range" id="weight_synergy" name="weight_synergy"
                                       min="0" max="100" value="<?= $weights['synergy']['value'] ?? 30 ?>" <?= $isPro ? '' : 'disabled' ?>>
                                <small class="text-muted">Group related tasks together to reduce context switching.</small>
                            </div>

                            <!-- Customer Directed -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input weight-toggle" type="checkbox" id="weight_customer_enabled" name="weight_customer_enabled"
                                               <?= ($weights['customer']['enabled'] ?? true) ? 'checked' : '' ?> <?= $isPro ? '' : 'disabled' ?>>
                                        <label class="form-check-label" for="weight_customer_enabled"><strong>Customer Directed</strong></label>
                                    </div>
                                    <span class="badge bg-primary weight-value" id="weight_customer_value"><?= $weights['customer']['value'] ?? 70 ?>%</span>
                                </div>
                                <input type="range" class="form-range" id="weight_customer" name="weight_customer"
                                       min="0" max="100" value="<?= $weights['customer']['value'] ?? 70 ?>" <?= $isPro ? '' : 'disabled' ?>>
                                <small class="text-muted">Weight customer-facing work and features higher.</small>
                            </div>

                            <!-- Design Directed -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input weight-toggle" type="checkbox" id="weight_design_enabled" name="weight_design_enabled"
                                               <?= ($weights['design']['enabled'] ?? false) ? 'checked' : '' ?> <?= $isPro ? '' : 'disabled' ?>>
                                        <label class="form-check-label" for="weight_design_enabled"><strong>Design Directed</strong></label>
                                    </div>
                                    <span class="badge bg-primary weight-value" id="weight_design_value"><?= $weights['design']['value'] ?? 40 ?>%</span>
                                </div>
                                <input type="range" class="form-range" id="weight_design" name="weight_design"
                                       min="0" max="100" value="<?= $weights['design']['value'] ?? 40 ?>" <?= $isPro ? '' : 'disabled' ?>>
                                <small class="text-muted">Prioritize design/UX improvements and polish.</small>
                            </div>

                            <!-- Technical Debt -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input weight-toggle" type="checkbox" id="weight_tech_debt_enabled" name="weight_tech_debt_enabled"
                                               <?= ($weights['tech_debt']['enabled'] ?? false) ? 'checked' : '' ?> <?= $isPro ? '' : 'disabled' ?>>
                                        <label class="form-check-label" for="weight_tech_debt_enabled"><strong>Technical Debt</strong></label>
                                    </div>
                                    <span class="badge bg-primary weight-value" id="weight_tech_debt_value"><?= $weights['tech_debt']['value'] ?? 20 ?>%</span>
                                </div>
                                <input type="range" class="form-range" id="weight_tech_debt" name="weight_tech_debt"
                                       min="0" max="100" value="<?= $weights['tech_debt']['value'] ?? 20 ?>" <?= $isPro ? '' : 'disabled' ?>>
                                <small class="text-muted">Allocate time for paying down technical debt.</small>
                            </div>

                            <!-- Risk Mitigation -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input weight-toggle" type="checkbox" id="weight_risk_enabled" name="weight_risk_enabled"
                                               <?= ($weights['risk']['enabled'] ?? false) ? 'checked' : '' ?> <?= $isPro ? '' : 'disabled' ?>>
                                        <label class="form-check-label" for="weight_risk_enabled"><strong>Risk Mitigation</strong></label>
                                    </div>
                                    <span class="badge bg-primary weight-value" id="weight_risk_value"><?= $weights['risk']['value'] ?? 50 ?>%</span>
                                </div>
                                <input type="range" class="form-range" id="weight_risk" name="weight_risk"
                                       min="0" max="100" value="<?= $weights['risk']['value'] ?? 50 ?>" <?= $isPro ? '' : 'disabled' ?>>
                                <small class="text-muted">Prioritize tasks that reduce project risk or blockers.</small>
                            </div>

                            <?php if (!$isPro): ?>
                            <div class="alert alert-warning mt-3">
                                <i class="bi bi-star"></i> <strong>Upgrade to Pro</strong> to unlock custom priority weights and optimize your sprint planning.
                                <a href="/subscription" class="alert-link">Learn more</a>
                            </div>
                            <?php endif; ?>
                        </div>

                        <hr>

                        <!-- Engineering Goals Section (Pro Feature) -->
                        <div class="mb-4 <?= $isPro ? '' : 'pro-feature-locked' ?>">
                            <h5 class="mb-3">
                                <i class="bi bi-bullseye"></i> Engineering Goals
                                <?php if (!$isPro): ?>
                                <span class="badge bg-warning text-dark ms-2">Pro Feature</span>
                                <?php endif; ?>
                            </h5>
                            <p class="text-muted small mb-3">Set targets that guide the AI's recommendations and help track sprint health.</p>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="goal_velocity" class="form-label"><strong>Sprint Velocity Target</strong></label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="goal_velocity" name="goal_velocity"
                                               value="<?= $goals['velocity'] ?? '' ?>" placeholder="40" min="0" <?= $isPro ? '' : 'disabled' ?>>
                                        <span class="input-group-text">story points</span>
                                    </div>
                                    <small class="text-muted">Target story points per sprint.</small>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="goal_debt_reduction" class="form-label"><strong>Tech Debt Allocation</strong></label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="goal_debt_reduction" name="goal_debt_reduction"
                                               value="<?= $goals['debt_reduction'] ?? '' ?>" placeholder="10" min="0" max="100" <?= $isPro ? '' : 'disabled' ?>>
                                        <span class="input-group-text">% of sprint</span>
                                    </div>
                                    <small class="text-muted">Percentage of sprint capacity for tech debt.</small>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="goal_predictability" class="form-label"><strong>Delivery Predictability</strong></label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="goal_predictability" name="goal_predictability"
                                               value="<?= $goals['predictability'] ?? '' ?>" placeholder="85" min="0" max="100" <?= $isPro ? '' : 'disabled' ?>>
                                        <span class="input-group-text">% target</span>
                                    </div>
                                    <small class="text-muted">Target percentage of committed work delivered.</small>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="goal_sprint_days" class="form-label"><strong>Sprint Length</strong></label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="goal_sprint_days" name="goal_sprint_days"
                                               value="<?= $goals['sprint_days'] ?? 10 ?>" placeholder="10" min="1" max="30" <?= $isPro ? '' : 'disabled' ?>>
                                        <span class="input-group-text">working days</span>
                                    </div>
                                    <small class="text-muted">Number of working days in a sprint (10 = 2 weeks).</small>
                                </div>
                            </div>

                            <!-- Team Capacity Section -->
                            <div class="card bg-light mb-3">
                                <div class="card-body">
                                    <h6 class="card-title"><i class="bi bi-people"></i> Team Capacity Calculator</h6>
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label for="goal_fte_count" class="form-label"><strong>Team Size</strong></label>
                                            <div class="input-group">
                                                <input type="number" class="form-control" id="goal_fte_count" name="goal_fte_count"
                                                       value="<?= $goals['fte_count'] ?? '' ?>" placeholder="5" min="0.5" step="0.5" <?= $isPro ? '' : 'disabled' ?>>
                                                <span class="input-group-text">FTEs</span>
                                            </div>
                                            <small class="text-muted">Full-time equivalent engineers.</small>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label for="goal_hours_per_day" class="form-label"><strong>Hours per Day</strong></label>
                                            <div class="input-group">
                                                <input type="number" class="form-control" id="goal_hours_per_day" name="goal_hours_per_day"
                                                       value="<?= $goals['hours_per_day'] ?? 8 ?>" placeholder="8" min="1" max="12" <?= $isPro ? '' : 'disabled' ?>>
                                                <span class="input-group-text">hrs</span>
                                            </div>
                                            <small class="text-muted">Work hours per day per FTE.</small>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label for="goal_productivity" class="form-label"><strong>Productivity Factor</strong></label>
                                            <div class="input-group">
                                                <input type="number" class="form-control" id="goal_productivity" name="goal_productivity"
                                                       value="<?= $goals['productivity'] ?? 70 ?>" placeholder="70" min="10" max="100" <?= $isPro ? '' : 'disabled' ?>>
                                                <span class="input-group-text">%</span>
                                            </div>
                                            <small class="text-muted">Coding time after meetings, etc.</small>
                                        </div>
                                    </div>

                                    <!-- Calculated Capacity Display -->
                                    <div class="alert alert-info mb-0 mt-2" id="capacity_display">
                                        <div class="row text-center">
                                            <div class="col-md-4">
                                                <div class="h5 mb-0" id="calc_total_hours">--</div>
                                                <small class="text-muted">Total Hours/Sprint</small>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="h5 mb-0" id="calc_available_hours">--</div>
                                                <small class="text-muted">Available Hours</small>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="h5 mb-0" id="calc_per_person">--</div>
                                                <small class="text-muted">Per Person</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="goal_clarity_threshold" class="form-label"><strong>Clarity Threshold</strong></label>
                                <div class="input-group" style="max-width: 200px;">
                                    <input type="number" class="form-control" id="goal_clarity_threshold" name="goal_clarity_threshold"
                                           value="<?= $goals['clarity_threshold'] ?? 6 ?>" min="1" max="10" <?= $isPro ? '' : 'disabled' ?>>
                                    <span class="input-group-text">/ 10</span>
                                </div>
                                <small class="text-muted">Minimum clarity score for a ticket to be considered ready for work. Tickets below this score will be flagged for clarification.</small>
                            </div>

                            <?php if (!$isPro): ?>
                            <div class="alert alert-warning mt-3">
                                <i class="bi bi-star"></i> <strong>Upgrade to Pro</strong> to set engineering goals and get AI recommendations aligned with your targets.
                                <a href="/subscription" class="alert-link">Learn more</a>
                            </div>
                            <?php endif; ?>
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

<style>
.pro-feature-locked {
    opacity: 0.6;
    pointer-events: none;
    position: relative;
}
.pro-feature-locked::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: transparent;
    pointer-events: auto;
}
.weight-value {
    min-width: 50px;
    text-align: center;
}
</style>

<script>
document.getElementById('digest_enabled').addEventListener('change', function() {
    document.getElementById('digest_options').style.opacity = this.checked ? '1' : '0.5';
});

// Initialize opacity
document.getElementById('digest_options').style.opacity =
    document.getElementById('digest_enabled').checked ? '1' : '0.5';

// Weight slider value updates
const weightSliders = ['quick_wins', 'synergy', 'customer', 'design', 'tech_debt', 'risk'];
weightSliders.forEach(function(name) {
    const slider = document.getElementById('weight_' + name);
    const valueDisplay = document.getElementById('weight_' + name + '_value');
    const toggle = document.getElementById('weight_' + name + '_enabled');

    if (slider && valueDisplay) {
        slider.addEventListener('input', function() {
            valueDisplay.textContent = this.value + '%';
        });
    }

    // Update slider opacity based on toggle
    if (slider && toggle) {
        function updateSliderState() {
            slider.style.opacity = toggle.checked ? '1' : '0.5';
        }
        toggle.addEventListener('change', updateSliderState);
        updateSliderState();
    }
});

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

// Team Capacity Calculator
function calculateCapacity() {
    const fteCount = parseFloat(document.getElementById('goal_fte_count').value) || 0;
    const hoursPerDay = parseFloat(document.getElementById('goal_hours_per_day').value) || 8;
    const sprintDays = parseFloat(document.getElementById('goal_sprint_days').value) || 10;
    const productivity = parseFloat(document.getElementById('goal_productivity').value) || 70;

    if (fteCount > 0) {
        const totalHours = fteCount * hoursPerDay * sprintDays;
        const availableHours = Math.round(totalHours * (productivity / 100));
        const perPerson = Math.round(availableHours / fteCount);

        document.getElementById('calc_total_hours').textContent = totalHours + ' hrs';
        document.getElementById('calc_available_hours').textContent = availableHours + ' hrs';
        document.getElementById('calc_per_person').textContent = perPerson + ' hrs';
        document.getElementById('capacity_display').classList.remove('alert-secondary');
        document.getElementById('capacity_display').classList.add('alert-info');
    } else {
        document.getElementById('calc_total_hours').textContent = '--';
        document.getElementById('calc_available_hours').textContent = '--';
        document.getElementById('calc_per_person').textContent = '--';
        document.getElementById('capacity_display').classList.remove('alert-info');
        document.getElementById('capacity_display').classList.add('alert-secondary');
    }
}

// Add event listeners for capacity calculator
['goal_fte_count', 'goal_hours_per_day', 'goal_sprint_days', 'goal_productivity'].forEach(function(id) {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('input', calculateCapacity);
    }
});

// Calculate on page load
calculateCapacity();
</script>
