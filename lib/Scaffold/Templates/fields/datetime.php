<?php
/**
 * Datetime Input Field Template
 */
$required = in_array('required', $f['options']) ? 'required' : '';
$fieldName = $f['name'];
?>
                        <div class="mb-3">
                            <label for="<?=$fieldName?>" class="form-label"><?=$f['label']?></label>
                            <input type="datetime-local"
                                   class="form-control"
                                   id="<?=$fieldName?>"
                                   name="<?=$fieldName?>"
                                   value="<?php echo '<?= $bean->' . $fieldName . ' ? date(\'Y-m-d\\TH:i\', strtotime($bean->' . $fieldName . ')) : \'\' ?>'; ?>"
                                   <?=$required?>>
                        </div>
