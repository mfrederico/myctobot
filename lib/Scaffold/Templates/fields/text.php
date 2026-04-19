<?php
/**
 * Text Input Field Template
 *
 * Outputs PHP code for the generated view file.
 * At runtime: $bean, $isNew are available.
 */
$required = in_array('required', $f['options']) ? 'required' : '';
$fieldName = $f['name'];
?>
                        <div class="mb-3">
                            <label for="<?=$fieldName?>" class="form-label"><?=$f['label']?></label>
                            <input type="text"
                                   class="form-control"
                                   id="<?=$fieldName?>"
                                   name="<?=$fieldName?>"
                                   value="<?php echo '<?= h($bean->' . $fieldName . ' ?? \'\') ?>'; ?>"
                                   <?=$required?>>
                        </div>
