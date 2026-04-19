<?php
/**
 * Select Dropdown Field Template
 *
 * Note: For this to work, the controller needs to pass an options array.
 * Expected variable: ${fieldName}Options = [['value' => 'x', 'label' => 'X'], ...]
 */
$required = in_array('required', $f['options']) ? 'required' : '';
$fieldName = $f['name'];
$optionsVar = $fieldName . 'Options';
?>
                        <div class="mb-3">
                            <label for="<?=$fieldName?>" class="form-label"><?=$f['label']?></label>
                            <select class="form-select"
                                    id="<?=$fieldName?>"
                                    name="<?=$fieldName?>"
                                    <?=$required?>>
                                <option value="">-- Select --</option>
                                <?php echo '<?php if (isset($' . $optionsVar . ')): foreach ($' . $optionsVar . ' as $opt): ?>'; ?>

                                <?php echo '<?php $optVal = $opt[\'value\'] ?? $opt; $optLbl = $opt[\'label\'] ?? $opt; ?>'; ?>

                                <option value="<?php echo '<?= h($optVal) ?>'; ?>" <?php echo '<?= ($bean->' . $fieldName . ' ?? \'\') == $optVal ? \'selected\' : \'\' ?>'; ?>><?php echo '<?= h($optLbl) ?>'; ?></option>
                                <?php echo '<?php endforeach; endif; ?>'; ?>

                            </select>
                        </div>
