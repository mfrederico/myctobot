<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input Required - <?= htmlspecialchars($pipeline_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .form-card {
            max-width: 600px;
            margin: 0 auto;
        }
        .countdown {
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="form-card">
            <div class="card shadow-lg">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-input-cursor-text"></i>
                        <?= htmlspecialchars($pipeline_name) ?>
                    </h5>
                    <small class="opacity-75">Step: <?= htmlspecialchars($step_name) ?></small>
                </div>
                <div class="card-body">
                    <!-- Prompt -->
                    <div class="alert alert-info mb-4">
                        <i class="bi bi-chat-left-text me-2"></i>
                        <?= nl2br(htmlspecialchars($prompt)) ?>
                    </div>

                    <!-- Context info (if any relevant data to show) -->
                    <?php if (!empty($context) && is_array($context)): ?>
                    <div class="mb-4">
                        <h6 class="text-muted"><i class="bi bi-info-circle"></i> Context</h6>
                        <div class="small bg-light p-2 rounded">
                            <?php foreach ($context as $key => $value): ?>
                                <?php if (!str_starts_with($key, '_') && !is_array($value) && !is_object($value)): ?>
                                <div><strong><?= htmlspecialchars($key) ?>:</strong> <?= htmlspecialchars(is_string($value) ? $value : json_encode($value)) ?></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Form -->
                    <form action="/pipelines/formsubmit/<?= $run_id ?>?token=<?= urlencode($token) ?>" method="POST" id="inputForm">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                        <?php if (!empty($schema['properties'])): ?>
                            <?php foreach ($schema['properties'] as $fieldName => $fieldDef): ?>
                            <div class="mb-3">
                                <label for="<?= htmlspecialchars($fieldName) ?>" class="form-label">
                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $fieldName))) ?>
                                    <?php if (in_array($fieldName, $schema['required'] ?? [])): ?>
                                    <span class="text-danger">*</span>
                                    <?php endif; ?>
                                </label>

                                <?php
                                $type = $fieldDef['type'] ?? 'string';
                                $description = $fieldDef['description'] ?? '';
                                $enum = $fieldDef['enum'] ?? null;
                                $required = in_array($fieldName, $schema['required'] ?? []);
                                ?>

                                <?php if ($enum): ?>
                                    <!-- Enum/Select -->
                                    <select
                                        class="form-select"
                                        name="<?= htmlspecialchars($fieldName) ?>"
                                        id="<?= htmlspecialchars($fieldName) ?>"
                                        <?= $required ? 'required' : '' ?>
                                    >
                                        <option value="">-- Select --</option>
                                        <?php foreach ($enum as $option): ?>
                                        <option value="<?= htmlspecialchars($option) ?>"><?= htmlspecialchars($option) ?></option>
                                        <?php endforeach; ?>
                                    </select>

                                <?php elseif ($type === 'boolean'): ?>
                                    <!-- Boolean/Checkbox -->
                                    <div class="form-check">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            name="<?= htmlspecialchars($fieldName) ?>"
                                            id="<?= htmlspecialchars($fieldName) ?>"
                                            value="true"
                                        >
                                        <label class="form-check-label" for="<?= htmlspecialchars($fieldName) ?>">
                                            Yes
                                        </label>
                                    </div>

                                <?php elseif ($type === 'integer' || $type === 'number'): ?>
                                    <!-- Number -->
                                    <input
                                        type="number"
                                        class="form-control"
                                        name="<?= htmlspecialchars($fieldName) ?>"
                                        id="<?= htmlspecialchars($fieldName) ?>"
                                        <?= $required ? 'required' : '' ?>
                                        <?= $type === 'integer' ? 'step="1"' : 'step="any"' ?>
                                    >

                                <?php elseif (strlen($description) > 100 || str_contains($type, 'text')): ?>
                                    <!-- Textarea for long text -->
                                    <textarea
                                        class="form-control"
                                        name="<?= htmlspecialchars($fieldName) ?>"
                                        id="<?= htmlspecialchars($fieldName) ?>"
                                        rows="3"
                                        <?= $required ? 'required' : '' ?>
                                    ></textarea>

                                <?php else: ?>
                                    <!-- Default text input -->
                                    <input
                                        type="text"
                                        class="form-control"
                                        name="<?= htmlspecialchars($fieldName) ?>"
                                        id="<?= htmlspecialchars($fieldName) ?>"
                                        <?= $required ? 'required' : '' ?>
                                    >
                                <?php endif; ?>

                                <?php if ($description): ?>
                                <div class="form-text"><?= htmlspecialchars($description) ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <!-- No schema - freeform input -->
                            <div class="mb-3">
                                <label for="response" class="form-label">Your Response <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="response" id="response" rows="4" required></textarea>
                            </div>
                        <?php endif; ?>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">
                                <i class="bi bi-send"></i> Submit
                            </button>
                        </div>
                    </form>
                </div>

                <?php if ($timeout_at): ?>
                <div class="card-footer text-muted small">
                    <i class="bi bi-clock"></i>
                    Expires: <span class="countdown" data-timeout="<?= htmlspecialchars($timeout_at) ?>"><?= date('M j, Y g:i A', strtotime($timeout_at)) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="text-center mt-3">
                <small class="text-white opacity-75">
                    Run ID: <code class="text-white"><?= htmlspecialchars(substr($run_uid, 0, 16)) ?>...</code>
                </small>
            </div>
        </div>
    </div>

    <script>
        // Prevent double submission
        document.getElementById('inputForm').addEventListener('submit', function() {
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').innerHTML = '<span class="spinner-border spinner-border-sm"></span> Submitting...';
        });

        // Countdown timer
        const countdownEl = document.querySelector('.countdown');
        if (countdownEl) {
            const timeout = new Date(countdownEl.dataset.timeout);
            setInterval(() => {
                const now = new Date();
                const diff = timeout - now;
                if (diff <= 0) {
                    countdownEl.innerHTML = '<span class="text-danger">Expired</span>';
                    document.getElementById('submitBtn').disabled = true;
                } else {
                    const hours = Math.floor(diff / 3600000);
                    const mins = Math.floor((diff % 3600000) / 60000);
                    if (hours > 0) {
                        countdownEl.textContent = `${hours}h ${mins}m remaining`;
                    } else {
                        countdownEl.textContent = `${mins}m remaining`;
                    }
                }
            }, 60000);
        }
    </script>
</body>
</html>
