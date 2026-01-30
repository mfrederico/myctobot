<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Success - <?= htmlspecialchars($pipeline_name ?? 'Pipeline') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .success-card {
            max-width: 600px;
            margin: 0 auto;
        }
        .checkmark-circle {
            animation: scale-in 0.3s ease-out;
        }
        @keyframes scale-in {
            0% { transform: scale(0); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="success-card">
            <div class="card shadow-lg">
                <div class="card-body text-center py-5">
                    <div class="checkmark-circle mb-4">
                        <i class="bi bi-check-circle-fill display-1 text-success"></i>
                    </div>
                    <h2 class="mb-3">Input Received</h2>
                    <p class="text-muted mb-4">
                        <?= htmlspecialchars($message ?? 'Your input has been processed successfully.') ?>
                    </p>

                    <?php if (!empty($status)): ?>
                    <div class="mb-4">
                        <span class="badge bg-<?= $status === 'completed' ? 'success' : ($status === 'running' ? 'primary' : 'secondary') ?> fs-6">
                            <?php if ($status === 'completed'): ?>
                            <i class="bi bi-check-circle"></i> Completed
                            <?php elseif ($status === 'running'): ?>
                            <i class="bi bi-play-circle"></i> Processing
                            <?php elseif ($status === 'awaiting_input'): ?>
                            <i class="bi bi-hourglass-split"></i> Awaiting More Input
                            <?php else: ?>
                            <i class="bi bi-clock"></i> <?= ucfirst($status) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>

                    <?php if ($status === 'awaiting_input'): ?>
                    <div class="alert alert-info text-start">
                        <i class="bi bi-info-circle me-2"></i>
                        The process requires additional input. Please check your email or wait for further instructions.
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($output) && is_array($output)): ?>
                    <div class="text-start mt-4">
                        <h6 class="text-muted"><i class="bi bi-box-arrow-right"></i> Result</h6>
                        <div class="bg-light p-3 rounded small">
                            <pre class="mb-0" style="white-space: pre-wrap;"><?= htmlspecialchars(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="card-footer text-center text-muted small">
                    <i class="bi bi-shield-check"></i>
                    Run ID: <code><?= htmlspecialchars(substr($run_uid ?? '', 0, 16)) ?>...</code>
                </div>
            </div>

            <div class="text-center mt-3">
                <small class="text-white opacity-75">
                    You can close this window.
                </small>
            </div>
        </div>
    </div>
</body>
</html>
