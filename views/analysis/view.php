<div class="container-fluid">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1>Analysis Results</h1>
                    <small class="text-muted">
                        <?= date('F j, Y \a\t g:i A', strtotime($analysis['created_at'])) ?>
                    </small>
                </div>
                <div class="btn-group">
                    <a href="/analysis" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                    <button type="button" class="btn btn-outline-success" onclick="emailAnalysis()">
                        <i class="bi bi-envelope"></i> Email
                    </button>
                    <?php if ($board): ?>
                    <a href="/analysis/run/<?= $board['id'] ?>" class="btn btn-primary">
                        <i class="bi bi-arrow-repeat"></i> Run Again
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Board Info -->
            <?php if ($board): ?>
            <div class="alert alert-light border mb-4">
                <div class="row">
                    <div class="col-md-4">
                        <strong>Board:</strong> <?= htmlspecialchars($board['board_name']) ?>
                    </div>
                    <div class="col-md-4">
                        <strong>Project:</strong> <code><?= htmlspecialchars($board['project_key']) ?></code>
                    </div>
                    <div class="col-md-4">
                        <strong>Type:</strong>
                        <span class="badge <?= $analysis['analysis_type'] === 'digest' ? 'bg-info' : 'bg-primary' ?>">
                            <?= htmlspecialchars($analysis['analysis_type']) ?>
                        </span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Configuration Context (Why These Results) -->
            <?php if (!empty($data['config_context'])): ?>
            <div class="card mb-4 border-info">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-gear"></i> Analysis Configuration</span>
                    <button class="btn btn-sm btn-outline-light" type="button" data-bs-toggle="collapse" data-bs-target="#configDetails">
                        <i class="bi bi-chevron-down"></i> Details
                    </button>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php if (!empty($data['config_context']['weights_applied'])): ?>
                        <div class="col-md-6">
                            <h6><i class="bi bi-sliders"></i> Priority Weights Applied</h6>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($data['config_context']['weights_applied'] as $weight => $value): ?>
                                <li>
                                    <span class="badge bg-primary me-2"><?= htmlspecialchars($value) ?></span>
                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $weight))) ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($data['config_context']['goals_applied'])): ?>
                        <div class="col-md-6">
                            <h6><i class="bi bi-bullseye"></i> Engineering Goals Applied</h6>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($data['config_context']['goals_applied'] as $goal => $value): ?>
                                <li>
                                    <span class="badge bg-success me-2"><?= htmlspecialchars($value) ?></span>
                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $goal))) ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <?php if (empty($data['config_context']['weights_applied']) && empty($data['config_context']['goals_applied'])): ?>
                        <div class="col-12">
                            <p class="text-muted mb-0">
                                <i class="bi bi-info-circle"></i> No custom weights or goals configured.
                                <a href="/boards/edit/<?= $board['id'] ?? '' ?>">Configure Pro settings</a> to customize prioritization.
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Collapsible System Prompt -->
                    <div class="collapse mt-3" id="configDetails">
                        <hr>
                        <h6><i class="bi bi-cpu"></i> System Prompt Sent to AI</h6>
                        <pre class="bg-dark text-light p-3 rounded" style="max-height: 400px; overflow-y: auto; font-size: 0.85rem; white-space: pre-wrap;"><?= htmlspecialchars($data['config_context']['system_prompt_used'] ?? 'Not available') ?></pre>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Parsed Analysis Data -->
            <?php if (!empty($data) && is_array($data)): ?>
            <div class="row mb-4">
                <!-- Summary Stats -->
                <?php if (!empty($data['priorities'])): ?>
                <div class="col-md-3">
                    <div class="card text-center border-primary">
                        <div class="card-body">
                            <h2 class="text-primary"><?= count($data['priorities']) ?></h2>
                            <p class="mb-0">Priorities</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($data['blocked_tickets'])): ?>
                <div class="col-md-3">
                    <div class="card text-center <?= count($data['blocked_tickets']) > 0 ? 'border-danger' : 'border-success' ?>">
                        <div class="card-body">
                            <h2 class="<?= count($data['blocked_tickets']) > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= count($data['blocked_tickets']) ?>
                            </h2>
                            <p class="mb-0">Blocked</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($data['risk_alerts'])): ?>
                <div class="col-md-3">
                    <div class="card text-center border-warning">
                        <div class="card-body">
                            <h2 class="text-warning"><?= count($data['risk_alerts']) ?></h2>
                            <p class="mb-0">Risks</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($data['recommendations'])): ?>
                <div class="col-md-3">
                    <div class="card text-center border-info">
                        <div class="card-body">
                            <h2 class="text-info"><?= count($data['recommendations']) ?></h2>
                            <p class="mb-0">Recommendations</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($data['clarifications_needed'])): ?>
                <div class="col-md-3">
                    <div class="card text-center border-secondary">
                        <div class="card-body">
                            <h2 class="text-secondary"><?= count($data['clarifications_needed']) ?></h2>
                            <p class="mb-0">Need Clarification</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Priorities -->
            <?php if (!empty($data['priorities'])): ?>
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-list-ol"></i> Prioritized Items
                </div>
                <div class="card-body">
                    <?php foreach ($data['priorities'] as $index => $priority): ?>
                    <div class="d-flex mb-3 pb-3 <?= $index < count($data['priorities']) - 1 ? 'border-bottom' : '' ?>">
                        <div class="flex-shrink-0">
                            <span class="badge bg-primary rounded-pill" style="width: 30px; height: 30px; line-height: 22px;">
                                <?= $index + 1 ?>
                            </span>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <strong><?= htmlspecialchars($priority['key'] ?? 'Unknown') ?></strong>:
                            <?= htmlspecialchars($priority['summary'] ?? '') ?>
                            <br>
                            <small class="text-muted"><?= htmlspecialchars($priority['reasoning'] ?? '') ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Blocked Tickets -->
            <?php if (!empty($data['blocked_tickets'])): ?>
            <div class="card mb-4 border-danger">
                <div class="card-header bg-danger text-white">
                    <i class="bi bi-exclamation-octagon"></i> Blocked Tickets
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($data['blocked_tickets'] as $blocked): ?>
                        <li class="list-group-item">
                            <strong><?= htmlspecialchars($blocked['key'] ?? 'Unknown') ?></strong>:
                            <?= htmlspecialchars($blocked['summary'] ?? '') ?>
                            <br>
                            <small class="text-danger"><?= htmlspecialchars($blocked['reason'] ?? '') ?></small>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <!-- Risk Alerts -->
            <?php if (!empty($data['risk_alerts'])): ?>
            <div class="card mb-4 border-warning">
                <div class="card-header bg-warning">
                    <i class="bi bi-exclamation-triangle"></i> Risk Alerts
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        <?php foreach ($data['risk_alerts'] as $risk): ?>
                        <li><?= htmlspecialchars($risk) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recommendations -->
            <?php if (!empty($data['recommendations'])): ?>
            <div class="card mb-4 border-info">
                <div class="card-header bg-info text-white">
                    <i class="bi bi-lightbulb"></i> Recommendations
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        <?php foreach ($data['recommendations'] as $rec): ?>
                        <li><?= htmlspecialchars($rec) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tickets Needing Clarification -->
            <?php if (!empty($data['clarifications_needed'])): ?>
            <div class="card mb-4 border-secondary">
                <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-question-circle"></i> Tickets Needing Clarification</span>
                    <span class="badge bg-light text-dark"><?= count($data['clarifications_needed']) ?> ticket(s)</span>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        <small>These tickets have low clarity scores and may need stakeholder input before work begins.</small>
                    </p>

                    <?php foreach ($data['clarifications_needed'] as $index => $item): ?>
                    <div class="card mb-3 <?= $item['clarity_score'] < 4 ? 'border-danger' : ($item['clarity_score'] < 6 ? 'border-warning' : 'border-secondary') ?>">
                        <div class="card-header d-flex justify-content-between align-items-center py-2">
                            <div>
                                <strong><?= htmlspecialchars($item['key']) ?></strong>
                                <span class="text-muted">- <?= htmlspecialchars($item['summary'] ?? '') ?></span>
                            </div>
                            <span class="badge <?= \app\analyzers\ClarityAnalyzer::getClarityBadgeClass($item['clarity_score']) ?>">
                                <?= $item['clarity_score'] ?>/10 - <?= \app\analyzers\ClarityAnalyzer::getClarityLevel($item['clarity_score']) ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <small class="text-muted">Reporter:</small>
                                    <div>
                                        <i class="bi bi-person"></i>
                                        <?= htmlspecialchars($item['reporter_name'] ?? 'Unknown') ?>
                                        <?php if (!empty($item['reporter_email'])): ?>
                                        <br><small class="text-muted">
                                            <i class="bi bi-envelope"></i>
                                            <a href="mailto:<?= htmlspecialchars($item['reporter_email']) ?>">
                                                <?= htmlspecialchars($item['reporter_email']) ?>
                                            </a>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <small class="text-muted">Type:</small>
                                    <div><?= htmlspecialchars($item['type'] ?? 'Task') ?></div>
                                </div>
                                <div class="col-md-3">
                                    <small class="text-muted">Priority:</small>
                                    <div><?= htmlspecialchars($item['priority'] ?? 'Medium') ?></div>
                                </div>
                            </div>

                            <?php if (!empty($item['assessment'])): ?>
                            <div class="mb-3">
                                <small class="text-muted">Assessment:</small>
                                <p class="mb-0"><?= htmlspecialchars($item['assessment']) ?></p>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($item['missing_elements'])): ?>
                            <div class="mb-3">
                                <small class="text-muted">Missing Elements:</small>
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($item['missing_elements'] as $element): ?>
                                    <li><span class="text-danger"><?= htmlspecialchars($element) ?></span></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($item['suggested_questions'])): ?>
                            <div class="mb-0">
                                <small class="text-muted">Suggested Questions for Stakeholder:</small>
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($item['suggested_questions'] as $question): ?>
                                    <li class="text-primary"><?= htmlspecialchars($question) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Raw Markdown -->
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-markdown"></i> Full Report
                    <button class="btn btn-sm btn-outline-secondary float-end" onclick="toggleRaw()">
                        Toggle View
                    </button>
                </div>
                <div class="card-body">
                    <div id="renderedMarkdown" class="markdown-content">
                        <?= $markdownHtml ?? '' ?>
                    </div>
                    <pre id="rawMarkdown" style="display: none; white-space: pre-wrap;"><?= htmlspecialchars($markdown ?? '') ?></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.markdown-content h1 { font-size: 1.5rem; margin-top: 1rem; }
.markdown-content h2 { font-size: 1.3rem; margin-top: 1rem; color: #0d6efd; }
.markdown-content h3 { font-size: 1.1rem; margin-top: 0.8rem; }
.markdown-content ul { margin-bottom: 1rem; }
.markdown-content li { margin-bottom: 0.3rem; }
.markdown-content code { background: #f8f9fa; padding: 0.2rem 0.4rem; border-radius: 3px; }
</style>

<script>
function toggleRaw() {
    var rendered = document.getElementById('renderedMarkdown');
    var raw = document.getElementById('rawMarkdown');
    if (rendered.style.display === 'none') {
        rendered.style.display = 'block';
        raw.style.display = 'none';
    } else {
        rendered.style.display = 'none';
        raw.style.display = 'block';
    }
}

function emailAnalysis() {
    if (confirm('Send this analysis to your email?')) {
        fetch('/analysis/email/<?= $analysis['id'] ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Analysis sent to ' + (data.data.sent_to || 'your email'));
            } else {
                alert('Error: ' + (data.message || 'Failed to send email'));
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        });
    }
}
</script>
