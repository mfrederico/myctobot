<div class="container py-4" style="max-width: 800px;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
            <li class="breadcrumb-item active">New Contact</li>
        </ol>
    </nav>

    <div class="card">
        <div class="card-header"><i class="fas fa-user-plus"></i> Add New Contact</div>
        <div class="card-body">
            <form method="post" action="/crm/docreate">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">

                <h6 class="text-muted mb-3">Contact Information</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Company Email</label>
                        <input type="email" name="company_email" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-control">
                    </div>
                </div>

                <h6 class="text-muted mb-3 mt-4">Company Details</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Company Name</label>
                        <input type="text" name="company_name" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Company Size</label>
                        <input type="text" name="company_size" class="form-control" placeholder="e.g. 50">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Est. Shipments/mo</label>
                        <input type="text" name="estimated_shipments" class="form-control" placeholder="e.g. 5000">
                    </div>
                </div>

                <h6 class="text-muted mb-3 mt-4">Classification</h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Account Type</label>
                        <select name="account_type" class="form-select">
                            <option value="3pl">3PL</option>
                            <option value="brand">Brand</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Exec Type</label>
                        <select name="exec_type" class="form-select">
                            <option value="sales">Sales</option>
                            <option value="agency">Agency</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Category</label>
                        <select name="status_category" class="form-select" id="statusCategory">
                            <option value="prospect">Prospect</option>
                            <option value="customer">Customer</option>
                        </select>
                    </div>
                    <div class="col-md-3" id="stageGroup">
                        <label class="form-label">Pipeline Stage</label>
                        <select name="pipeline_stage" class="form-select">
                            <option value="cold">Cold</option>
                            <option value="warm">Warm</option>
                            <option value="hot">Hot</option>
                            <option value="closing">Closing Crucible</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-none" id="custStatusGroup">
                        <label class="form-label">Customer Status</label>
                        <select name="customer_status" class="form-select">
                            <option value="">None</option>
                            <option value="onboarding">Onboarding</option>
                            <option value="upsell">Upsell</option>
                            <option value="touchbase">Touch Base</option>
                            <option value="active">Active</option>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-12">
                        <label class="form-label">Tags <small class="text-muted">(comma separated)</small></label>
                        <input type="text" name="tags" class="form-control" placeholder="e.g. ecommerce, shopify, high-volume">
                    </div>
                </div>

                <?php if (!empty($salesReps)): ?>
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label">Assign to Sales Rep</label>
                            <select name="member_id" class="form-select">
                                <?php foreach ($salesReps as $rep): ?>
                                    <option value="<?= $rep->id ?>" <?= $rep->id == ($member['id'] ?? 0) ? 'selected' : '' ?>>
                                        <?= h($rep->displayName()) ?> (<?= $rep->username ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row g-3 mt-1">
                    <div class="col-md-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3" placeholder="Any initial notes about this contact..."></textarea>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Create Contact</button>
                    <a href="/crm/contacts" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var cat = document.getElementById('statusCategory');
    if (cat) {
        cat.addEventListener('change', function() {
            document.getElementById('stageGroup').classList.toggle('d-none', this.value === 'customer');
            document.getElementById('custStatusGroup').classList.toggle('d-none', this.value !== 'customer');
        });
    }
});
</script>
