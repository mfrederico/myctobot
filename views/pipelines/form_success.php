<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Success - <?= h($pipeline_name ?? 'Pipeline') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
        }
        .success-card {
            max-width: 600px;
            margin: 0 auto;
        }
        .card {
            background: rgba(33, 37, 41, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
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
                    <h2 class="mb-3 text-white">Input Received</h2>
                    <p class="text-muted mb-4">
                        <?= h($message ?? 'Your input has been processed successfully.') ?>
                    </p>

                    <?php if (!empty($status)): ?>
                    <div class="mb-4">
                        <span class="badge bg-<?= $status === 'completed' ? 'success' : ($status === 'running' ? 'primary' : ($status === 'awaiting_input' ? 'warning' : 'secondary')) ?> fs-6">
                            <?php if ($status === 'completed'): ?>
                            <i class="bi bi-check-circle"></i> Completed
                            <?php elseif ($status === 'running'): ?>
                            <i class="bi bi-play-circle"></i> Processing
                            <?php elseif ($status === 'awaiting_input'): ?>
                            <i class="bi bi-hourglass-split"></i> Awaiting More Input
                            <?php else: ?>
                            <i class="bi bi-clock"></i> <?= ucfirst(h($status)) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>

                    <?php if ($status === 'awaiting_input' || $status === 'running'): ?>
                    <div class="alert alert-info text-start">
                        <i class="bi bi-gear-wide-connected me-2"></i>
                        Your instructions have been sent to the agent. Redirecting back to chat...
                    </div>
                    <a href="/pipelines/form/<?= h($run_uid) ?>" class="btn btn-primary btn-lg" id="returnBtn">
                        <i class="bi bi-arrow-left"></i> Return to Chat
                    </a>
                    <script>
                        // Auto-redirect back to form after 2 seconds
                        setTimeout(() => {
                            window.location.href = '/pipelines/form/<?= h($run_uid) ?>';
                        }, 2000);
                    </script>
                    <?php elseif ($status === 'completed'): ?>
                    <div class="alert alert-success text-start">
                        <i class="bi bi-check2-all me-2"></i>
                        The session has been marked as complete. Thank you for using the agent!
                    </div>
                    <?php endif; ?>
                </div>

                <div class="card-footer text-center text-muted small">
                    <i class="bi bi-shield-check"></i>
                    Run ID: <code class="text-white"><?= h(substr($run_uid ?? '', 0, 16)) ?>...</code>
                </div>
            </div>

            <div class="text-center mt-3">
                <small class="text-white opacity-75">
                    You can close this window or check your email for updates.
                </small>
            </div>
        </div>
    </div>
</body>
</html>
