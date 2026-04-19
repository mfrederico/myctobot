<?php
$slug = h($template->getSlug());
$schema = $template->getConfigSchema();
?>
<div class="container py-4">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/detachableapps">Apps</a></li>
            <li class="breadcrumb-item"><a href="/detachableapps/templates">Templates</a></li>
            <li class="breadcrumb-item active"><?= h($template->getName()) ?></li>
        </ol>
    </nav>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <!-- Template Header -->
            <div class="card mb-4">
                <div class="card-body d-flex align-items-center gap-3">
                    <i class="bi <?= h($template->getIcon()) ?> display-5 text-primary"></i>
                    <div>
                        <h2 class="h4 mb-1"><?= h($template->getName()) ?></h2>
                        <p class="text-muted mb-0"><?= h($template->getDescription()) ?></p>
                    </div>
                </div>
            </div>

            <!-- Config Form -->
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-gear"></i> Configure Your App
                </div>
                <div class="card-body">
                    <form method="POST" action="/detachableapps/createfromtemplate/<?= $slug ?>">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- App Name -->
                        <div class="mb-3">
                            <label for="app_name" class="form-label">App Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="app_name" name="name"
                                   placeholder="e.g., My Store Support Widget" required>
                            <div class="form-text">A unique name for your app</div>
                        </div>

                        <hr>

                        <!-- Dynamic Fields from Template Schema -->
                        <?php foreach ($schema as $field): ?>
                        <div class="mb-3">
                            <label for="tmpl_<?= h($field['name']) ?>" class="form-label">
                                <?= h($field['label']) ?>
                                <?php if (!empty($field['required'])): ?>
                                <span class="text-danger">*</span>
                                <?php endif; ?>
                            </label>

                            <?php if ($field['type'] === 'select'): ?>
                                <select class="form-select" id="tmpl_<?= h($field['name']) ?>" name="<?= h($field['name']) ?>"
                                        <?= !empty($field['required']) ? 'required' : '' ?>>
                                    <option value="">Select...</option>
                                    <?php
                                    $opts = $fieldOptions[$field['name']] ?? $field['options'] ?? [];
                                    foreach ($opts as $opt):
                                    ?>
                                    <option value="<?= h($opt['value']) ?>"
                                            <?= (($field['default'] ?? '') == $opt['value']) ? 'selected' : '' ?>>
                                        <?= h($opt['label']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>

                            <?php elseif ($field['type'] === 'boolean'): ?>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox"
                                           id="tmpl_<?= h($field['name']) ?>" name="<?= h($field['name']) ?>" value="1"
                                           <?= !empty($field['default']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="tmpl_<?= h($field['name']) ?>">
                                        Enable
                                    </label>
                                </div>

                            <?php elseif ($field['type'] === 'textarea'): ?>
                                <textarea class="form-control" id="tmpl_<?= h($field['name']) ?>" name="<?= h($field['name']) ?>"
                                          rows="3" <?= !empty($field['required']) ? 'required' : '' ?>
                                ><?= h($field['default'] ?? '') ?></textarea>

                            <?php else: ?>
                                <input type="text" class="form-control" id="tmpl_<?= h($field['name']) ?>" name="<?= h($field['name']) ?>"
                                       value="<?= h($field['default'] ?? '') ?>"
                                       <?= !empty($field['required']) ? 'required' : '' ?>>
                            <?php endif; ?>

                            <?php if (!empty($field['help'])): ?>
                            <div class="form-text"><?= h($field['help']) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>

                        <hr>

                        <div class="d-flex justify-content-between">
                            <a href="/detachableapps/templates" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Back
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-lg"></i> Create App
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
