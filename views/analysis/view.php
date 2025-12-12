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
