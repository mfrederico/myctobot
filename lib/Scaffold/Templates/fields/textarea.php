<?php
/**
 * Textarea Field Template
 */
$required = in_array('required', $f['options']) ? 'required' : '';
$fieldName = $f['name'];
?>
                        <div class="mb-3">
                            <label for="<?=$fieldName?>" class="form-label"><?=$f['label']?></label>
                            <textarea class="form-control"
                                      id="<?=$fieldName?>"
                                      name="<?=$fieldName?>"
                                      rows="4"
                                      <?=$required?>><?php echo '<?= h($bean->' . $fieldName . ' ?? \'\') ?>'; ?></textarea>
                        </div>
