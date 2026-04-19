<?php
/**
 * @step_type: datastore_query
 * @category: integration
 * @label: App Datastore
 * @icon: bi-database
 * @color: info
 * @description: Query or update an app's SQLite datastore (insert, update, select, delete)
 *
 * Each tenant app can have its own SQLite database for data storage.
 * This step allows pipelines to interact with that datastore.
 */
?>
<div class="config-panel" id="config_datastore_query" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <!-- App and Operation Row -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">App Slug</label>
                    <input type="text" class="form-control" name="config_datastore_app_slug"
                           placeholder="{context.app_slug}" value="{context.app_slug}">
                    <small class="text-muted">App that owns the datastore. Use <code>{context.app_slug}</code> for dynamic.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Operation</label>
                    <select class="form-select" name="config_datastore_operation" id="datastoreOperationSelect"
                            onchange="toggleDatastoreFields(this.value)">
                        <option value="insert">Insert Row</option>
                        <option value="update">Update Rows</option>
                        <option value="select">Select Rows</option>
                        <option value="delete">Delete Rows</option>
                        <option value="count">Count Rows</option>
                        <option value="raw">Raw SQL</option>
                    </select>
                </div>
            </div>

            <!-- Table Name (for non-raw operations) -->
            <div class="mb-3" id="datastoreTableField">
                <label class="form-label">Table Name</label>
                <input type="text" class="form-control" name="config_datastore_table"
                       placeholder="submissions">
                <small class="text-muted">Table to operate on</small>
            </div>

            <!-- Data Fields (for insert/update) -->
            <div class="mb-3" id="datastoreDataField">
                <label class="form-label">Data (JSON)</label>
                <textarea class="form-control font-monospace" name="config_datastore_data" rows="6"
                          placeholder='{
  "name": "{context.name}",
  "email": "{context.email}",
  "created_at": "NOW()"
}'></textarea>
                <small class="text-muted">
                    Column => value pairs. Supports <code>{context.key}</code> and <code>{step.output.key}</code>.
                    Use <code>"NOW()"</code> for current timestamp.
                </small>
            </div>

            <!-- Where Clause (for update/select/delete/count) -->
            <div class="mb-3" id="datastoreWhereField">
                <label class="form-label">Where Clause (JSON)</label>
                <textarea class="form-control font-monospace" name="config_datastore_where" rows="3"
                          placeholder='{
  "id": "{store_submission.output.last_insert_id}"
}'></textarea>
                <small class="text-muted">Column => value pairs for filtering. Supports variable substitution.</small>
            </div>

            <!-- Options (for select) -->
            <div class="mb-3" id="datastoreOptionsField" style="display: none;">
                <label class="form-label">Query Options (JSON)</label>
                <textarea class="form-control font-monospace" name="config_datastore_options" rows="4"
                          placeholder='{
  "order_by": "created_at DESC",
  "limit": 10,
  "offset": 0
}'></textarea>
                <small class="text-muted">Optional: <code>order_by</code>, <code>limit</code>, <code>offset</code>, <code>columns</code></small>
            </div>

            <!-- Raw SQL (for raw operation) -->
            <div class="mb-3" id="datastoreRawSqlField" style="display: none;">
                <label class="form-label">SQL Statement</label>
                <textarea class="form-control font-monospace" name="config_datastore_sql" rows="4"
                          placeholder="CREATE TABLE IF NOT EXISTS logs (id INTEGER PRIMARY KEY, message TEXT)"></textarea>
                <small class="text-muted">Raw SQL. Use for DDL or complex queries.</small>
            </div>

            <div class="mb-3" id="datastoreRawParamsField" style="display: none;">
                <label class="form-label">SQL Parameters (JSON Array)</label>
                <textarea class="form-control font-monospace" name="config_datastore_params" rows="2"
                          placeholder='["{context.user_id}", "active"]'></textarea>
                <small class="text-muted">Parameters for prepared statement (? placeholders)</small>
            </div>

            <!-- Output Reference -->
            <div class="alert alert-info mb-0 py-2">
                <strong>Output References:</strong>
                <ul class="mb-0 small">
                    <li><strong>insert:</strong> <code>{step_name.output.last_insert_id}</code></li>
                    <li><strong>update/delete:</strong> <code>{step_name.output.affected_rows}</code></li>
                    <li><strong>select:</strong> <code>{step_name.output.rows}</code>, <code>{step_name.output.count}</code></li>
                    <li><strong>count:</strong> <code>{step_name.output.count}</code></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function toggleDatastoreFields(operation) {
    // All field containers
    const dataField = document.getElementById('datastoreDataField');
    const whereField = document.getElementById('datastoreWhereField');
    const optionsField = document.getElementById('datastoreOptionsField');
    const rawSqlField = document.getElementById('datastoreRawSqlField');
    const rawParamsField = document.getElementById('datastoreRawParamsField');
    const tableField = document.getElementById('datastoreTableField');

    // Hide all first
    dataField.style.display = 'none';
    whereField.style.display = 'none';
    optionsField.style.display = 'none';
    rawSqlField.style.display = 'none';
    rawParamsField.style.display = 'none';
    tableField.style.display = 'block';

    switch (operation) {
        case 'insert':
            dataField.style.display = 'block';
            break;
        case 'update':
            dataField.style.display = 'block';
            whereField.style.display = 'block';
            break;
        case 'select':
            whereField.style.display = 'block';
            optionsField.style.display = 'block';
            break;
        case 'delete':
        case 'count':
            whereField.style.display = 'block';
            break;
        case 'raw':
            tableField.style.display = 'none';
            rawSqlField.style.display = 'block';
            rawParamsField.style.display = 'block';
            break;
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const operationSelect = document.getElementById('datastoreOperationSelect');
    if (operationSelect) {
        toggleDatastoreFields(operationSelect.value);
    }
});
</script>
