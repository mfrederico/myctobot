<?php
/**
 * @step_type: parser
 * @category: core
 * @label: Parser
 * @icon: bi-braces
 * @color: secondary
 * @description: Transform data (jq, php, regex)
 */
?>
<div class="config-panel" id="config_parser" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="mb-3">
                        <label class="form-label">Parser Type</label>
                        <select class="form-select" name="config_parser_type">
                            <option value="jq">jq (JSON)</option>
                            <option value="php">PHP</option>
                            <option value="regex">Regex</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="mb-3">
                        <label class="form-label">Expression</label>
                        <input type="text" class="form-control font-monospace" name="config_parser_expression"
                               placeholder=".data.items[]">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
