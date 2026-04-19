<?php
/**
 * Pipeline Wizard View
 *
 * Displays a multi-step wizard with progress bar for pipeline input forms.
 * Supports embed mode for iframe integration.
 */

$embedMode = $embed ?? false;
$hasError = !empty($error);
$isComplete = $completed ?? false;
$csrfToken = Flight::csrf()->getToken();
?>
<?php if (!$embedMode): ?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pipeline_name ?? 'Pipeline') ?> - Wizard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
        }
        .wizard-card {
            max-width: 700px;
            margin: 0 auto;
        }
        .card {
            background: rgba(33, 37, 41, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .wizard-header {
            position: sticky;
            top: 0;
            z-index: 5;
            background: rgba(33, 37, 41, 0.98);
            backdrop-filter: blur(10px);
        }
        .step-counter {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-weight: 600;
            font-size: 0.9rem;
            box-shadow: 0 2px 8px rgba(13, 110, 253, 0.3);
        }
        .step-counter .step-num {
            background: rgba(255,255,255,0.2);
            padding: 0.2rem 0.5rem;
            border-radius: 1rem;
            font-size: 0.85rem;
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0;
            padding: 1rem 0;
        }
        .step-dot {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
        }
        .step-dot.completed {
            background: #198754;
            color: white;
        }
        .step-dot.active {
            background: #0d6efd;
            color: white;
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.3);
        }
        .step-dot.pending {
            background: rgba(255, 255, 255, 0.1);
            color: #6c757d;
            border: 2px solid #6c757d;
        }
        .step-line {
            width: 40px;
            height: 3px;
            background: rgba(255, 255, 255, 0.2);
            transition: background 0.3s ease;
        }
        .step-line.completed {
            background: #198754;
        }
        .step-labels {
            display: flex;
            justify-content: space-between;
            padding: 0 0.5rem;
            margin-top: -0.5rem;
        }
        .step-label {
            font-size: 11px;
            color: #6c757d;
            text-align: center;
            max-width: 80px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .step-label.active {
            color: #0d6efd;
            font-weight: 500;
        }
        .step-label.completed {
            color: #198754;
        }
        .wizard-form {
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            border-radius: inherit;
        }
        .completion-animation {
            animation: scaleIn 0.5s ease;
        }
        @keyframes scaleIn {
            from { transform: scale(0.8); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
    </style>
</head>
<body>
<?php endif; ?>

<?php if ($embedMode): ?>
<style>
    body { background: transparent; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
    .wizard-card { max-width: 100%; margin: 0; }
    .card { background: #212529; border-radius: 0.5rem; }
    .wizard-header { position: sticky; top: 0; z-index: 5; background: rgba(33, 37, 41, 0.98); backdrop-filter: blur(10px); }
    .step-counter { display: inline-flex; align-items: center; gap: 0.5rem; background: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%); color: white; padding: 0.5rem 1rem; border-radius: 2rem; font-weight: 600; font-size: 0.9rem; box-shadow: 0 2px 8px rgba(13, 110, 253, 0.3); }
    .step-counter .step-num { background: rgba(255,255,255,0.2); padding: 0.2rem 0.5rem; border-radius: 1rem; font-size: 0.85rem; }
    .step-indicator { display: flex; justify-content: center; align-items: center; gap: 0; padding: 1rem 0; }
    .step-dot { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px; transition: all 0.3s ease; position: relative; z-index: 1; }
    .step-dot.completed { background: #198754; color: white; }
    .step-dot.active { background: #0d6efd; color: white; box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.3); }
    .step-dot.pending { background: rgba(255, 255, 255, 0.1); color: #6c757d; border: 2px solid #6c757d; }
    .step-line { width: 40px; height: 3px; background: rgba(255, 255, 255, 0.2); transition: background 0.3s ease; }
    .step-line.completed { background: #198754; }
    .wizard-form { animation: fadeIn 0.3s ease; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .loading-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.85); display: flex; align-items: center; justify-content: center; z-index: 10; border-radius: inherit; }
    .completion-animation { animation: scaleIn 0.5s ease; }
    @keyframes scaleIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
</style>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<?php endif; ?>

<div class="<?= $embedMode ? 'p-3' : 'container py-4' ?>">
    <div class="wizard-card">
        <div class="card shadow-lg position-relative" id="wizardCard">

            <?php if ($hasError): ?>
            <!-- Error State -->
            <div class="card-body text-center py-5">
                <i class="bi bi-exclamation-triangle text-warning display-4"></i>
                <h5 class="mt-3"><?= h($error) ?></h5>
                <?php if (!$embedMode): ?>
                <a href="javascript:history.back()" class="btn btn-outline-secondary mt-3">
                    <i class="bi bi-arrow-left"></i> Go Back
                </a>
                <?php endif; ?>
            </div>

            <?php elseif ($isComplete): ?>
            <!-- Completion State -->
            <div class="card-body text-center py-5 completion-animation">
                <div class="mb-4">
                    <i class="bi bi-check-circle-fill text-success display-1"></i>
                </div>
                <h4 class="text-success">All Steps Complete!</h4>
                <p class="text-muted mb-4">
                    Pipeline "<?= h($pipeline_name ?? 'Pipeline') ?>" has finished.
                </p>
                <?php if (($run_status ?? '') === 'completed'): ?>
                <span class="badge bg-success fs-6"><i class="bi bi-check"></i> Completed Successfully</span>
                <?php elseif (($run_status ?? '') === 'failed'): ?>
                <span class="badge bg-danger fs-6"><i class="bi bi-x"></i> Failed</span>
                <?php else: ?>
                <span class="badge bg-info fs-6"><i class="bi bi-hourglass-split"></i> Processing</span>
                <?php endif; ?>

                <?php if (!$embedMode): ?>
                <div class="mt-4">
                    <a href="/pipelines/viewrun/<?= $run_id ?? '' ?>" class="btn btn-primary">
                        <i class="bi bi-eye"></i> View Run Details
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <!-- Wizard Header with Progress (Sticky) -->
            <div class="card-header border-bottom border-secondary wizard-header">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <small class="text-muted d-block mb-1"><?= h($pipeline_name ?? 'Pipeline') ?></small>
                        <!-- Prominent Step Counter Badge -->
                        <div class="step-counter">
                            <i class="bi bi-ui-checks-grid"></i>
                            <span>Step</span>
                            <span class="step-num"><?= $progress['current'] ?? 1 ?> of <?= $progress['total'] ?? 1 ?></span>
                        </div>
                    </div>
                    <?php if (!$embedMode): ?>
                    <button type="button" class="btn-close btn-close-white" aria-label="Close" onclick="window.close()"></button>
                    <?php endif; ?>
                </div>

                <!-- Progress Bar (larger) -->
                <div class="progress mb-2" style="height: 8px;">
                    <div class="progress-bar bg-success transition" role="progressbar"
                         style="width: <?= $progress['percentage'] ?? 0 ?>%; transition: width 0.5s ease;"
                         id="progressBar"></div>
                </div>

                <!-- Step Indicators -->
                <?php if (!empty($progress['steps'])): ?>
                <div class="step-indicator">
                    <?php foreach ($progress['steps'] as $idx => $step): ?>
                        <?php
                        $stepNum = $idx + 1;
                        $isCompleted = in_array($step['step_name'], $progress['completed_names'] ?? []);
                        $isActive = ($stepNum === ($progress['current'] ?? 1));
                        $stepClass = $isCompleted ? 'completed' : ($isActive ? 'active' : 'pending');
                        ?>
                        <?php if ($idx > 0): ?>
                        <div class="step-line <?= $isCompleted ? 'completed' : '' ?>"></div>
                        <?php endif; ?>
                        <div class="step-dot <?= $stepClass ?>" title="<?= h($step['label']) ?>">
                            <?php if ($isCompleted): ?>
                            <i class="bi bi-check"></i>
                            <?php else: ?>
                            <?= $stepNum ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="text-center mt-2">
                    <span class="badge bg-dark text-light px-3 py-2">
                        <i class="bi bi-pencil-square me-1"></i>
                        <?= h($step_label ?? $step_name ?? 'Input') ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Form Content -->
            <div class="card-body wizard-form" id="formContent">
                <!-- Prompt -->
                <?php if (!empty($prompt)): ?>
                <div class="alert alert-info mb-4">
                    <i class="bi bi-info-circle me-2"></i>
                    <?= nl2br(h($prompt)) ?>
                </div>
                <?php endif; ?>

                <!-- Form -->
                <form id="wizardForm" method="POST">
                    <input type="hidden" name="token" value="<?= h($token ?? '') ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

                    <?php if (!empty($schema['properties'])): ?>
                        <?php foreach ($schema['properties'] as $fieldName => $fieldDef): ?>
                        <?php
                        $type = $fieldDef['type'] ?? 'string';
                        $description = $fieldDef['description'] ?? '';
                        $enum = $fieldDef['enum'] ?? null;
                        $required = in_array($fieldName, $schema['required'] ?? []);
                        $defaultVal = $fieldDef['default'] ?? '';
                        ?>

                        <?php if ($enum): ?>
                        <!-- Enum/Select -->
                        <div class="mb-3">
                            <label for="<?= h($fieldName) ?>" class="form-label">
                                <?= h(ucwords(str_replace('_', ' ', $fieldName))) ?>
                                <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                            </label>
                            <select class="form-select" name="<?= h($fieldName) ?>"
                                    id="<?= h($fieldName) ?>" <?= $required ? 'required' : '' ?>>
                                <option value="">-- Select --</option>
                                <?php foreach ($enum as $option): ?>
                                <option value="<?= h($option) ?>"
                                        <?= $defaultVal === $option ? 'selected' : '' ?>>
                                    <?= h(ucfirst($option)) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($description): ?>
                            <div class="form-text"><?= h($description) ?></div>
                            <?php endif; ?>
                        </div>

                        <?php elseif ($type === 'boolean'): ?>
                        <!-- Boolean/Checkbox -->
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="<?= h($fieldName) ?>"
                                       id="<?= h($fieldName) ?>"
                                       value="true" <?= $defaultVal ? 'checked' : '' ?>>
                                <label class="form-check-label" for="<?= h($fieldName) ?>">
                                    <?= h(ucwords(str_replace('_', ' ', $fieldName))) ?>
                                </label>
                            </div>
                            <?php if ($description): ?>
                            <div class="form-text"><?= h($description) ?></div>
                            <?php endif; ?>
                        </div>

                        <?php elseif ($type === 'integer' || $type === 'number'): ?>
                        <!-- Number -->
                        <div class="mb-3">
                            <label for="<?= h($fieldName) ?>" class="form-label">
                                <?= h(ucwords(str_replace('_', ' ', $fieldName))) ?>
                                <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                            </label>
                            <input type="number" class="form-control"
                                   name="<?= h($fieldName) ?>"
                                   id="<?= h($fieldName) ?>"
                                   value="<?= h($defaultVal) ?>"
                                   <?= $required ? 'required' : '' ?>
                                   <?= $type === 'integer' ? 'step="1"' : 'step="any"' ?>>
                            <?php if ($description): ?>
                            <div class="form-text"><?= h($description) ?></div>
                            <?php endif; ?>
                        </div>

                        <?php elseif (strlen($description) > 100 || $type === 'text'): ?>
                        <!-- Textarea -->
                        <div class="mb-3">
                            <label for="<?= h($fieldName) ?>" class="form-label">
                                <?= h(ucwords(str_replace('_', ' ', $fieldName))) ?>
                                <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                            </label>
                            <textarea class="form-control" rows="4"
                                      name="<?= h($fieldName) ?>"
                                      id="<?= h($fieldName) ?>"
                                      <?= $required ? 'required' : '' ?>><?= h($defaultVal) ?></textarea>
                            <?php if ($description): ?>
                            <div class="form-text"><?= h($description) ?></div>
                            <?php endif; ?>
                        </div>

                        <?php else: ?>
                        <!-- Default text input -->
                        <div class="mb-3">
                            <label for="<?= h($fieldName) ?>" class="form-label">
                                <?= h(ucwords(str_replace('_', ' ', $fieldName))) ?>
                                <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                            </label>
                            <input type="text" class="form-control"
                                   name="<?= h($fieldName) ?>"
                                   id="<?= h($fieldName) ?>"
                                   value="<?= h($defaultVal) ?>"
                                   <?= $required ? 'required' : '' ?>>
                            <?php if ($description): ?>
                            <div class="form-text"><?= h($description) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>

                    <?php else: ?>
                    <!-- No schema - freeform input -->
                    <div class="mb-3">
                        <label for="response" class="form-label">
                            Your Response <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" name="response" id="response" rows="5" required
                                  placeholder="Enter your response here..."></textarea>
                    </div>
                    <?php endif; ?>

                    <!-- Navigation Buttons -->
                    <?php $isLastStep = ($progress['current'] ?? 1) >= ($progress['total'] ?? 1); ?>
                    <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top border-secondary">
                        <div>
                            <?php if (($progress['current'] ?? 1) > 1): ?>
                            <div class="d-flex align-items-center text-success">
                                <i class="bi bi-check-circle-fill me-2"></i>
                                <span class="small fw-medium"><?= ($progress['current'] ?? 1) - 1 ?> step<?= ($progress['current'] ?? 1) > 2 ? 's' : '' ?> completed</span>
                            </div>
                            <?php else: ?>
                            <div class="text-muted small">
                                <i class="bi bi-info-circle me-1"></i>
                                <?= ($progress['total'] ?? 1) ?> step<?= ($progress['total'] ?? 1) > 1 ? 's' : '' ?> total
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <?php if (!$isLastStep): ?>
                            <span class="text-muted small me-2">
                                <?= ($progress['total'] ?? 1) - ($progress['current'] ?? 1) ?> remaining
                            </span>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary btn-lg px-4" id="submitBtn">
                                <?php if ($isLastStep): ?>
                                <i class="bi bi-check-circle me-1"></i> Complete
                                <?php else: ?>
                                Continue <i class="bi bi-arrow-right ms-1"></i>
                                <?php endif; ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Loading Overlay -->
            <div class="loading-overlay d-none" id="loadingOverlay">
                <div class="text-center">
                    <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="text-white fw-bold mb-2" id="loadingTitle">Saving...</div>
                    <div class="text-white-50 small" id="loadingSubtitle">
                        <?php if (($progress['current'] ?? 1) < ($progress['total'] ?? 1)): ?>
                        Loading next step...
                        <?php else: ?>
                        Completing pipeline...
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    const embedMode = <?= json_encode($embedMode) ?>;
    const runId = <?= json_encode($run_id ?? null) ?>;
    const runUid = <?= json_encode($run_uid ?? null) ?>;
    const urlToken = <?= json_encode($url_token ?? $token ?? '') ?>;

    // Send postMessage to parent window (for iframe embedding)
    function notifyParent(type, data = {}) {
        if (window.parent !== window) {
            window.parent.postMessage({
                type: type,
                runId: runId,
                runUid: runUid,
                ...data
            }, '*');
        }
    }

    // Notify parent of initial state
    <?php if (!$hasError && !$isComplete): ?>
    notifyParent('wizard-step', {
        step: <?= $progress['current'] ?? 1 ?>,
        total: <?= $progress['total'] ?? 1 ?>,
        stepName: <?= json_encode($step_name ?? '') ?>
    });
    <?php elseif ($isComplete): ?>
    notifyParent('wizard-complete', {
        status: <?= json_encode($run_status ?? 'completed') ?>
    });
    <?php endif; ?>

    // Form submission handler
    const form = document.getElementById('wizardForm');
    const loadingOverlay = document.getElementById('loadingOverlay');
    const loadingTitle = document.getElementById('loadingTitle');
    const loadingSubtitle = document.getElementById('loadingSubtitle');
    const progressBar = document.getElementById('progressBar');
    const submitBtn = document.getElementById('submitBtn');
    const currentStep = <?= $progress['current'] ?? 1 ?>;
    const totalSteps = <?= $progress['total'] ?? 1 ?>;
    const isLastStep = currentStep >= totalSteps;

    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            // Show loading with context
            if (loadingOverlay) loadingOverlay.classList.remove('d-none');
            if (loadingTitle) {
                loadingTitle.textContent = isLastStep ? 'Completing...' : 'Saving Step ' + currentStep + '...';
            }
            if (loadingSubtitle) {
                loadingSubtitle.textContent = isLastStep ? 'Finishing up the pipeline' : 'Loading step ' + (currentStep + 1) + ' of ' + totalSteps;
            }
            if (submitBtn) submitBtn.disabled = true;

            // Gather form data
            const formData = new FormData(form);

            try {
                const response = await fetch('/pipelines/wizardsubmit/<?= $run_id ?? '' ?>', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const result = await response.json();

                if (result.success) {
                    // Update progress bar
                    if (progressBar && result.progress) {
                        progressBar.style.width = result.progress.percentage + '%';
                    }

                    if (result.pipeline_complete) {
                        // Pipeline finished - show completion
                        notifyParent('wizard-complete', {
                            status: result.status
                        });

                        // Reload wizard to show completion state
                        const tokenParam = urlToken ? '?token=' + encodeURIComponent(urlToken) : '';
                        const embedParam = embedMode ? (tokenParam ? '&embed=1' : '?embed=1') : '';
                        window.location.href = '/pipelines/wizard/<?= $run_id ?? '' ?>' + tokenParam + embedParam;

                    } else if (result.has_next_step) {
                        // More steps - notify and reload
                        notifyParent('wizard-step', {
                            step: result.progress.current,
                            total: result.progress.total,
                            completedStep: result.completed_step
                        });

                        // Reload to show next step
                        const tokenParam = urlToken ? '?token=' + encodeURIComponent(urlToken) : '';
                        const embedParam = embedMode ? (tokenParam ? '&embed=1' : '?embed=1') : '';
                        window.location.href = '/pipelines/wizard/<?= $run_id ?? '' ?>' + tokenParam + embedParam;

                    } else {
                        // No more steps but pipeline still running
                        notifyParent('wizard-processing', {
                            status: result.status
                        });

                        // Reload to show processing state
                        setTimeout(() => {
                            const tokenParam = urlToken ? '?token=' + encodeURIComponent(urlToken) : '';
                            const embedParam = embedMode ? (tokenParam ? '&embed=1' : '?embed=1') : '';
                            window.location.href = '/pipelines/wizard/<?= $run_id ?? '' ?>' + tokenParam + embedParam;
                        }, 1000);
                    }
                } else {
                    // Error
                    notifyParent('wizard-error', {
                        message: result.error || 'An error occurred'
                    });
                    alert('Error: ' + (result.error || 'Failed to submit'));

                    if (loadingOverlay) loadingOverlay.classList.add('d-none');
                    if (submitBtn) submitBtn.disabled = false;
                }

            } catch (error) {
                console.error('Submit error:', error);
                notifyParent('wizard-error', {
                    message: error.message
                });
                alert('Error: ' + error.message);

                if (loadingOverlay) loadingOverlay.classList.add('d-none');
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }
})();
</script>

<?php if (!$embedMode): ?>
</body>
</html>
<?php endif; ?>
