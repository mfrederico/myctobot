<?php
/**
 * Fancy Date Selector Widget
 *
 * An example custom widget demonstrating how to create specialized field templates.
 */
$required = in_array('required', $f['options']) ? 'required' : '';
$fieldName = $f['name'];
?>
                        <div class="mb-3">
                            <label for="<?=$fieldName?>" class="form-label">
                                <i class="bi bi-calendar-event text-primary"></i>
                                <?=$f['label']?>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-primary text-white">
                                    <i class="bi bi-calendar3"></i>
                                </span>
                                <input type="date"
                                       class="form-control"
                                       id="<?=$fieldName?>_date"
                                       name="<?=$fieldName?>_date"
                                       value="<?php echo '<?= $bean->' . $fieldName . ' ? date(\'Y-m-d\', strtotime($bean->' . $fieldName . ')) : \'\' ?>'; ?>"
                                       <?=$required?>>
                                <span class="input-group-text">at</span>
                                <input type="time"
                                       class="form-control"
                                       id="<?=$fieldName?>_time"
                                       name="<?=$fieldName?>_time"
                                       value="<?php echo '<?= $bean->' . $fieldName . ' ? date(\'H:i\', strtotime($bean->' . $fieldName . ')) : \'09:00\' ?>'; ?>">
                                <button class="btn btn-outline-secondary" type="button"
                                        onclick="setToday_<?=$fieldName?>()" title="Set to now">
                                    <i class="bi bi-clock-history"></i> Now
                                </button>
                            </div>
                            <!-- Hidden combined field for form submission -->
                            <input type="hidden" id="<?=$fieldName?>" name="<?=$fieldName?>">
                            <small class="text-muted">Select date and time separately for easier input.</small>
                        </div>
                        <script>
                        // Combine date and time on form submit
                        document.querySelector('form').addEventListener('submit', function() {
                            const date = document.getElementById('<?=$fieldName?>_date').value;
                            const time = document.getElementById('<?=$fieldName?>_time').value || '00:00';
                            document.getElementById('<?=$fieldName?>').value = date ? date + ' ' + time + ':00' : '';
                        });

                        function setToday_<?=$fieldName?>() {
                            const now = new Date();
                            document.getElementById('<?=$fieldName?>_date').value = now.toISOString().split('T')[0];
                            document.getElementById('<?=$fieldName?>_time').value = now.toTimeString().slice(0,5);
                        }
                        </script>
