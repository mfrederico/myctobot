<div class="container py-4" style="max-width: 800px;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
            <li class="breadcrumb-item"><a href="/crm/view/<?= $contact->id ?>"><?= h($contact->fullName()) ?></a></li>
            <li class="breadcrumb-item active">Edit</li>
        </ol>
    </nav>

    <div class="card">
        <div class="card-header"><i class="fas fa-edit"></i> Edit Contact</div>
        <div class="card-body">
            <form method="post" action="/crm/doedit">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                <input type="hidden" name="id" value="<?= $contact->id ?>">

                <h6 class="text-muted mb-3">Contact Information</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-control" value="<?= h($contact->firstName) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-control" value="<?= h($contact->lastName) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Company Email</label>
                        <input type="email" name="company_email" class="form-control" value="<?= h($contact->companyEmail) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-control" value="<?= h($contact->phone) ?>">
                    </div>
                </div>

                <h6 class="text-muted mb-3 mt-4">Company Details</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Company Name</label>
                        <input type="text" name="company_name" class="form-control" value="<?= h($contact->companyName) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Company Size</label>
                        <input type="text" name="company_size" class="form-control" value="<?= h($contact->companySize) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Est. Shipments/mo</label>
                        <input type="text" name="estimated_shipments" class="form-control" value="<?= h($contact->estimatedShipments) ?>">
                    </div>
                </div>

                <h6 class="text-muted mb-3 mt-4">Classification</h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Account Type</label>
                        <select name="account_type" class="form-select">
                            <option value="3pl" <?= $contact->accountType === '3pl' ? 'selected' : '' ?>>3PL</option>
                            <option value="brand" <?= $contact->accountType === 'brand' ? 'selected' : '' ?>>Brand</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Exec Type</label>
                        <select name="exec_type" class="form-select">
                            <option value="sales" <?= $contact->execType === 'sales' ? 'selected' : '' ?>>Sales</option>
                            <option value="agency" <?= $contact->execType === 'agency' ? 'selected' : '' ?>>Agency</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Category</label>
                        <select name="status_category" class="form-select" id="statusCategory">
                            <option value="prospect" <?= $contact->statusCategory === 'prospect' ? 'selected' : '' ?>>Prospect</option>
                            <option value="customer" <?= $contact->statusCategory === 'customer' ? 'selected' : '' ?>>Customer</option>
                        </select>
                    </div>
                    <div class="col-md-3 <?= $contact->statusCategory === 'customer' ? 'd-none' : '' ?>" id="stageGroup">
                        <label class="form-label">Pipeline Stage</label>
                        <select name="pipeline_stage" class="form-select">
                            <option value="cold" <?= $contact->pipelineStage === 'cold' ? 'selected' : '' ?>>Cold</option>
                            <option value="warm" <?= $contact->pipelineStage === 'warm' ? 'selected' : '' ?>>Warm</option>
                            <option value="hot" <?= $contact->pipelineStage === 'hot' ? 'selected' : '' ?>>Hot</option>
                            <option value="closing" <?= $contact->pipelineStage === 'closing' ? 'selected' : '' ?>>Closing Crucible</option>
                        </select>
                    </div>
                    <div class="col-md-3 <?= $contact->statusCategory === 'prospect' ? 'd-none' : '' ?>" id="custStatusGroup">
                        <label class="form-label">Customer Status</label>
                        <select name="customer_status" class="form-select">
                            <option value="" <?= empty($contact->customerStatus) ? 'selected' : '' ?>>None</option>
                            <option value="onboarding" <?= $contact->customerStatus === 'onboarding' ? 'selected' : '' ?>>Onboarding</option>
                            <option value="upsell" <?= $contact->customerStatus === 'upsell' ? 'selected' : '' ?>>Upsell</option>
                            <option value="touchbase" <?= $contact->customerStatus === 'touchbase' ? 'selected' : '' ?>>Touch Base</option>
                            <option value="active" <?= $contact->customerStatus === 'active' ? 'selected' : '' ?>>Active</option>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-12">
                        <label class="form-label">Tags <small class="text-muted">(comma separated)</small></label>
                        <input type="text" name="tags" class="form-control" value="<?= h($contact->tags) ?>">
                    </div>
                </div>

                <?php if (!empty($salesReps)): ?>
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label">Assign to Sales Rep</label>
                            <select name="member_id" class="form-select">
                                <?php foreach ($salesReps as $rep): ?>
                                    <option value="<?= $rep->id ?>" <?= $rep->id == $contact->memberId ? 'selected' : '' ?>>
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
                        <textarea name="notes" class="form-control" rows="3"><?= h($contact->notes) ?></textarea>
                    </div>
                </div>

                <!-- STUB: CannonWMS fields -->
                <h6 class="text-muted mb-3 mt-4">CannonWMS Integration <small>(future)</small></h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">WMS Customer ID</label>
                        <input type="text" name="wms_customer_id" class="form-control" value="<?= h($contact->wmsCustomerId ?? '') ?>" placeholder="Will be auto-populated">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Feature Usage</label>
                        <input type="text" name="feature_usage" class="form-control" value="<?= h($contact->featureUsage ?? '') ?>" placeholder="Comma-separated features">
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                    <a href="/crm/view/<?= $contact->id ?>" class="btn btn-outline-secondary ms-2">Cancel</a>
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
