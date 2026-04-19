<?php
/**
 * JSON Textarea Field Template
 */
$required = in_array('required', $f['options']) ? 'required' : '';
$fieldName = $f['name'];
?>
                        <div class="mb-3">
                            <label for="<?=$fieldName?>" class="form-label"><?=$f['label']?> <small class="text-muted">(JSON)</small></label>
                            <textarea class="form-control font-monospace"
                                      id="<?=$fieldName?>"
                                      name="<?=$fieldName?>"
                                      rows="6"
                                      style="font-size: 0.85em;"
                                      <?=$required?>><?php echo '<?php
$val = $bean->' . $fieldName . ' ?? \'{}\';
$decoded = json_decode($val);
echo h($decoded !== null ? json_encode($decoded, JSON_PRETTY_PRINT) : $val);
?>'; ?></textarea>
                            <div id="<?=$fieldName?>_error" class="invalid-feedback"></div>
                        </div>
                        <script>
                        document.getElementById('<?=$fieldName?>').addEventListener('blur', function() {
                            try {
                                const value = this.value.trim();
                                if (value) {
                                    JSON.parse(value);
                                    this.classList.remove('is-invalid');
                                    this.classList.add('is-valid');
                                }
                            } catch (e) {
                                this.classList.remove('is-valid');
                                this.classList.add('is-invalid');
                                document.getElementById('<?=$fieldName?>_error').textContent = 'Invalid JSON: ' + e.message;
                            }
                        });
                        </script>
