<?php
/**
 * Password Input Field Template
 */
$required = in_array('required', $f['options']) ? 'required' : '';
$fieldName = $f['name'];
?>
                        <div class="mb-3">
                            <label for="<?=$fieldName?>" class="form-label"><?=$f['label']?></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="password"
                                       class="form-control"
                                       id="<?=$fieldName?>"
                                       name="<?=$fieldName?>"
                                       placeholder="<?php echo '<?= $isNew ? \'\' : \'Leave blank to keep current\' ?>'; ?>"
                                       autocomplete="new-password"
                                       <?php echo '<?= ($isNew && ' . (in_array('required', $f['options']) ? 'true' : 'false') . ') ? \'required\' : \'\' ?>'; ?>>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword_<?=$fieldName?>()">
                                    <i class="bi bi-eye" id="<?=$fieldName?>_icon"></i>
                                </button>
                            </div>
                            <?php echo '<?php if (!$isNew): ?>'; ?>

                            <small class="text-muted">Leave blank to keep the current password.</small>
                            <?php echo '<?php endif; ?>'; ?>

                        </div>
                        <script>
                        function togglePassword_<?=$fieldName?>() {
                            const input = document.getElementById('<?=$fieldName?>');
                            const icon = document.getElementById('<?=$fieldName?>_icon');
                            if (input.type === 'password') {
                                input.type = 'text';
                                icon.classList.replace('bi-eye', 'bi-eye-slash');
                            } else {
                                input.type = 'password';
                                icon.classList.replace('bi-eye-slash', 'bi-eye');
                            }
                        }
                        </script>
