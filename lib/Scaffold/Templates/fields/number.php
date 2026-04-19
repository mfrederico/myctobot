<?php
/**
 * Number Input Field Template
 */
$required = in_array('required', $f['options']) ? 'required' : '';
$step = $f['type'] === 'float' ? 'step="0.01"' : 'step="1"';
$fieldName = $f['name'];
?>
                        <div class="mb-3">
                            <label for="<?=$fieldName?>" class="form-label"><?=$f['label']?></label>
                            <input type="number"
                                   class="form-control"
                                   id="<?=$fieldName?>"
                                   name="<?=$fieldName?>"
                                   value="<?php echo '<?= h($bean->' . $fieldName . ' ?? \'\') ?>'; ?>"
                                   <?=$step?>
                                   <?=$required?>>
                        </div>
