<?php
/**
 * Enum Select Field Template
 *
 * For fields with predefined values (e.g., status:enum=pending|active|archived)
 * Values are embedded in the field definition, no controller options needed.
 */
$required = in_array('required', $f['options']) ? 'required' : '';
$fieldName = $f['name'];
$enumValues = $f['enum_values'] ?? [];

// Find default value from options
$defaultValue = '';
foreach ($f['options'] as $opt) {
    if (strpos($opt, 'default=') === 0) {
        $defaultValue = substr($opt, 8);
        break;
    }
}
?>
                        <div class="mb-3">
                            <label for="<?=$fieldName?>" class="form-label"><?=$f['label']?></label>
                            <select class="form-select"
                                    id="<?=$fieldName?>"
                                    name="<?=$fieldName?>"
                                    <?=$required?>>
                                <option value="">-- Select --</option>
<?php foreach ($enumValues as $val): ?>
                                <option value="<?=h($val)?>" <?php echo '<?= ($bean->' . $fieldName . ' ?? \'' . addslashes($defaultValue) . '\') === \'' . addslashes($val) . '\' ? \'selected\' : \'\' ?>'; ?>><?=ucwords(str_replace(['_', '-'], ' ', $val))?></option>
<?php endforeach; ?>
                            </select>
                        </div>
