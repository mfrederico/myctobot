<?php
/**
 * URL Input Field Template
 */
$required = in_array('required', $f['options']) ? 'required' : '';
$fieldName = $f['name'];
?>
                        <div class="mb-3">
                            <label for="<?=$fieldName?>" class="form-label"><?=$f['label']?></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-link-45deg"></i></span>
                                <input type="url"
                                       class="form-control"
                                       id="<?=$fieldName?>"
                                       name="<?=$fieldName?>"
                                       value="<?php echo '<?= h($bean->' . $fieldName . ' ?? \'\') ?>'; ?>"
                                       placeholder="https://example.com"
                                       <?=$required?>>
                                <?php echo '<?php if (!empty($bean->' . $fieldName . ')): ?>'; ?>

                                <a href="<?php echo '<?= h($bean->' . $fieldName . ') ?>'; ?>" target="_blank" class="btn btn-outline-secondary" title="Open in new tab">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                                <?php echo '<?php endif; ?>'; ?>

                            </div>
                        </div>
