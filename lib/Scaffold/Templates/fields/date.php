<?php
/**
 * Date Input Field Template
 */
$required = in_array('required', $f['options']) ? 'required' : '';
$fieldName = $f['name'];
?>
                        <div class="mb-3">
                            <label for="<?=$fieldName?>" class="form-label"><?=$f['label']?></label>
                            <input type="date"
                                   class="form-control"
                                   id="<?=$fieldName?>"
                                   name="<?=$fieldName?>"
                                   value="<?php echo '<?= h($bean->' . $fieldName . ' ?? \'\') ?>'; ?>"
                                   <?=$required?>>
                        </div>
