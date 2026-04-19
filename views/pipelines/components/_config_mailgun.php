<?php
/**
 * @step_type: mailgun
 * @category: communication
 * @label: Send Email
 * @icon: bi-envelope
 * @color: danger
 * @description: Send email via Mailgun
 */
?>
<div class="config-panel" id="config_mailgun" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">To</label>
                        <input type="text" class="form-control" name="config_mailgun_to"
                               placeholder="user@example.com, {context.recipient}">
                        <small class="text-muted">Recipient email(s). Supports variables.</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">CC (optional)</label>
                        <input type="text" class="form-control" name="config_mailgun_cc"
                               placeholder="manager@example.com">
                        <small class="text-muted">CC recipients (comma-separated)</small>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Subject</label>
                <input type="text" class="form-control" name="config_mailgun_subject"
                       placeholder="Pipeline completed: {context.pipeline_name}">
                <small class="text-muted">Email subject. Supports <code>{context.key}</code> variables.</small>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Content Type</label>
                        <select class="form-select" name="config_mailgun_content_type" onchange="onMailgunContentTypeChange(this)">
                            <option value="markdown">Markdown (converted to HTML)</option>
                            <option value="html">HTML</option>
                            <option value="text">Plain Text</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Template</label>
                        <select class="form-select" name="config_mailgun_template" onchange="applyMailgunTemplate(this.value)">
                            <option value="">-- Custom --</option>
                            <option value="pipeline_success">Pipeline Success</option>
                            <option value="pipeline_failure">Pipeline Failure</option>
                            <option value="step_output">Step Output Report</option>
                            <option value="simple_notification">Simple Notification</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Body</label>
                <textarea class="form-control font-monospace" name="config_mailgun_body" rows="8"
                          placeholder="# Pipeline Report

**Pipeline:** {context.pipeline_name}
**Status:** Success

## Results
{prev.output}"></textarea>
                <small class="text-muted">
                    Use <code>{context.key}</code>, <code>{step_name.output}</code>, or <code>{prev.output}</code> for variables.
                </small>
            </div>

            <div class="mb-3">
                <label class="form-label">Attachments <span class="badge bg-secondary">Optional</span></label>
                <textarea class="form-control font-monospace" name="config_mailgun_attachments" rows="2"
                          placeholder="@{write_file.file}
@{generate_report.file}"></textarea>
                <small class="text-muted">
                    One attachment per line. Use <code>@{step_name.file}</code> to attach files from file_write steps.
                    Supports variables: <code>@{context.attachment_path}</code>, <code>@/tmp/pipeline-runs/{run_uid}/report.pdf</code>
                </small>
            </div>

            <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle"></i>
                <strong>Note:</strong> Mailgun must be configured in workspace settings.
                <a href="/mailgun" target="_blank">Configure Mailgun</a>
            </div>
        </div>
    </div>
</div>
