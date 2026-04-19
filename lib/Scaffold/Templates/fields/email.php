<?php
/**
 * Email Input Field Template
 */
$required = in_array('required', $f['options']) ? 'required' : '';
$fieldName = $f['name'];
?>
                        <div class="mb-3">
                            <label for="<?=$fieldName?>" class="form-label"><?=$f['label']?></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email"
                                       class="form-control"
                                       id="<?=$fieldName?>"
                                       name="<?=$fieldName?>"
                                       value="<?php echo '<?= h($bean->' . $fieldName . ' ?? \'\') ?>'; ?>"
                                       placeholder="email@example.com"
                                       <?=$required?>>
                            </div>
                        </div>
