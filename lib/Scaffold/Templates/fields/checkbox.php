<?php
/**
 * Checkbox/Switch Field Template
 */
$fieldName = $f['name'];
?>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input"
                                       type="checkbox"
                                       id="<?=$fieldName?>"
                                       name="<?=$fieldName?>"
                                       <?php echo '<?= ($bean->' . $fieldName . ' ?? false) ? \'checked\' : \'\' ?>'; ?>>
                                <label class="form-check-label" for="<?=$fieldName?>"><?=$f['label']?></label>
                            </div>
                        </div>
