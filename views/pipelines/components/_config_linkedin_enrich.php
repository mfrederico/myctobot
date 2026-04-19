<?php
/**
 * @step_type: linkedin_enrich
 * @category: integration
 * @label: LinkedIn Enrichment
 * @icon: bi-linkedin
 * @color: info
 * @description: Search LinkedIn for companies/people and enrich CRM contacts
 */
?>
<div class="config-panel" id="config_linkedin_enrich" style="display: none;">
    <div class="card bg-light mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Mode</label>
                        <select class="form-select" name="config_linkedin_mode" onchange="toggleLinkedInMode(this.value)">
                            <option value="enrich_contacts">Enrich CRM Contacts</option>
                            <option value="search_companies">Search Companies</option>
                            <option value="search_people">Search People</option>
                        </select>
                        <small class="text-muted">
                            <strong>Enrich Contacts:</strong> Find LinkedIn data for existing CRM contacts<br>
                            <strong>Search Companies:</strong> Discover new companies by keywords<br>
                            <strong>Search People:</strong> Find people by name/title/company
                        </small>
                    </div>

                    <div class="mb-3 linkedin-search-fields">
                        <label class="form-label">Search Keywords</label>
                        <input type="text" class="form-control font-monospace" name="config_linkedin_keywords"
                               placeholder="DTC skincare brand, {context.search_term}">
                        <small class="text-muted">Supports variable substitution: <code>{context.key}</code>, <code>{step.output.key}</code></small>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Country</label>
                        <select class="form-select" name="config_linkedin_country">
                            <option value="US">United States</option>
                            <option value="UK">United Kingdom</option>
                            <option value="CA">Canada</option>
                            <option value="AU">Australia</option>
                            <option value="DE">Germany</option>
                        </select>
                    </div>

                    <div class="mb-3 linkedin-search-fields">
                        <label class="form-label">Product Companies Only</label>
                        <select class="form-select" name="config_linkedin_product_only">
                            <option value="1">Yes — filter out agencies/service providers</option>
                            <option value="0">No — include all company types</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Max Results</label>
                        <input type="number" class="form-control" name="config_linkedin_max_results"
                               value="10" min="1" max="50">
                    </div>
                </div>
            </div>

            <div class="row linkedin-enrich-fields">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Batch Limit</label>
                        <input type="number" class="form-control" name="config_linkedin_batch_limit"
                               value="10" min="1" max="50">
                        <small class="text-muted">Max contacts to enrich per run</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label">Lead Score Boost</label>
                        <input type="number" class="form-control" name="config_linkedin_score_boost"
                               value="15" min="0" max="30">
                        <small class="text-muted">Points added to lead score when LinkedIn data found</small>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="mb-3">
                        <label class="form-label">Delay Between Requests (ms)</label>
                        <input type="number" class="form-control" name="config_linkedin_delay_ms"
                               value="3000" min="1000" max="10000" step="500">
                        <small class="text-muted">Rate limiting: delay between LinkedIn API calls (min 1000ms)</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleLinkedInMode(mode) {
    const searchFields = document.querySelectorAll('.linkedin-search-fields');
    const enrichFields = document.querySelectorAll('.linkedin-enrich-fields');
    if (mode === 'enrich_contacts') {
        searchFields.forEach(el => el.style.display = 'none');
        enrichFields.forEach(el => el.style.display = '');
    } else {
        searchFields.forEach(el => el.style.display = '');
        enrichFields.forEach(el => el.style.display = 'none');
    }
}
</script>
