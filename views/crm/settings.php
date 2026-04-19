<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1"><i class="fas fa-cog"></i> Prospect Pipeline Settings</h1>
            <p class="text-muted mb-0">Configure discovery, analysis, enrichment, and outreach parameters.</p>
        </div>
    </div>

    <?php foreach ($_SESSION['flash'] ?? [] as $flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= htmlspecialchars($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; unset($_SESSION['flash']); ?>

    <!-- API Key Status -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <div class="d-flex align-items-center gap-4 flex-wrap">
                <strong class="text-muted me-2"><i class="fas fa-key"></i> API Keys (from config file):</strong>
                <span class="badge bg-<?= ($apiKeys['serper'] ?? false) ? 'success' : 'secondary' ?>">
                    <i class="fas fa-<?= ($apiKeys['serper'] ?? false) ? 'check' : 'times' ?>"></i> Serper
                </span>
                <span class="badge bg-<?= ($apiKeys['google_cse'] ?? false) ? 'success' : 'secondary' ?>">
                    <i class="fas fa-<?= ($apiKeys['google_cse'] ?? false) ? 'check' : 'times' ?>"></i> Google CSE
                </span>
                <span class="badge bg-<?= ($apiKeys['anthropic'] ?? false) ? 'success' : 'secondary' ?>">
                    <i class="fas fa-<?= ($apiKeys['anthropic'] ?? false) ? 'check' : 'times' ?>"></i> Anthropic
                </span>
                <small class="text-muted ms-auto">Set in <code>conf/config.*.ini</code></small>
            </div>
        </div>
    </div>

    <div class="accordion" id="settingsAccordion">

        <!-- Section 1: Discovery -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#sec-discovery">
                    <i class="fas fa-search me-2"></i> Discovery
                </button>
            </h2>
            <div id="sec-discovery" class="accordion-collapse collapse show" data-bs-parent="#settingsAccordion">
                <div class="accordion-body">
                    <form method="POST" action="/crm/dosettings">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                        <input type="hidden" name="section" value="discovery">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Search Provider</label>
                                <select class="form-select" name="search_provider">
                                    <option value="serper" <?= ($s['search_provider'] ?? '') === 'serper' ? 'selected' : '' ?>>Serper</option>
                                    <option value="google-cse" <?= ($s['search_provider'] ?? '') === 'google-cse' ? 'selected' : '' ?>>Google CSE</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Max Daily Queries</label>
                                <input type="number" class="form-control" name="max_daily_queries" value="<?= htmlspecialchars($s['max_daily_queries'] ?? '10') ?>" min="1" max="100">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Fetch Delay (seconds)</label>
                                <input type="number" class="form-control" name="fetch_delay" value="<?= htmlspecialchars($s['fetch_delay'] ?? '2') ?>" min="0" max="30">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Skip Domains</label>
                                <textarea class="form-control font-monospace" name="skip_domains" rows="3" style="font-size:0.85rem;"><?= htmlspecialchars($s['skip_domains'] ?? '') ?></textarea>
                                <small class="text-muted">Comma-separated list of domains to exclude from results.</small>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Discovery</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Section 2: Search Queries -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-queries">
                    <i class="fas fa-list me-2"></i> Search Queries
                </button>
            </h2>
            <div id="sec-queries" class="accordion-collapse collapse" data-bs-parent="#settingsAccordion">
                <div class="accordion-body">
                    <form method="POST" action="/crm/dosettings">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                        <input type="hidden" name="section" value="queries">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Search Queries</label>
                            <textarea class="form-control font-monospace" name="search_queries" rows="18" style="font-size:0.85rem;"><?= htmlspecialchars($searchQueries ?? '') ?></textarea>
                            <small class="text-muted">One query per line. Lines starting with <code>#</code> are comments. Empty lines are ignored.</small>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Queries</button>
                            <button type="submit" name="seed_from_file" value="1" class="btn btn-outline-secondary" onclick="return confirm('Load queries from conf/prospect-queries.txt? This will replace the current content.')">
                                <i class="fas fa-file-import me-1"></i> Load from File
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Section 3: Analysis -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-analysis">
                    <i class="fas fa-brain me-2"></i> Analysis
                </button>
            </h2>
            <div id="sec-analysis" class="accordion-collapse collapse" data-bs-parent="#settingsAccordion">
                <div class="accordion-body">
                    <form method="POST" action="/crm/dosettings">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                        <input type="hidden" name="section" value="analysis">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">AI Model</label>
                                <input type="text" class="form-control" name="ai_model" value="<?= htmlspecialchars($s['ai_model'] ?? '') ?>" placeholder="e.g. kimi-k2.5:cloud">
                                <small class="text-muted">Ollama model name for analysis</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">AI Fallback</label>
                                <select class="form-select" name="ai_fallback">
                                    <option value="claude" <?= ($s['ai_fallback'] ?? '') === 'claude' ? 'selected' : '' ?>>Claude (Anthropic)</option>
                                    <option value="none" <?= ($s['ai_fallback'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Batch Size</label>
                                <input type="number" class="form-control" name="batch_size" value="<?= htmlspecialchars($s['batch_size'] ?? '20') ?>" min="1" max="100">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Qualify Threshold</label>
                                <input type="number" class="form-control" name="qualify_threshold" value="<?= htmlspecialchars($s['qualify_threshold'] ?? '0.6') ?>" step="0.05" min="0" max="1">
                                <small class="text-muted">AI confidence >= this = "qualified"</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Disqualify Threshold</label>
                                <input type="number" class="form-control" name="disqualify_threshold" value="<?= htmlspecialchars($s['disqualify_threshold'] ?? '0.3') ?>" step="0.05" min="0" max="1">
                                <small class="text-muted">AI confidence < this = "disqualified"</small>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Analysis</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Section 4: CRM Push -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-push">
                    <i class="fas fa-upload me-2"></i> CRM Push
                </button>
            </h2>
            <div id="sec-push" class="accordion-collapse collapse" data-bs-parent="#settingsAccordion">
                <div class="accordion-body">
                    <form method="POST" action="/crm/dosettings">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                        <input type="hidden" name="section" value="push">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Batch Limit</label>
                                <input type="number" class="form-control" name="push_batch_limit" value="<?= htmlspecialchars($s['push_batch_limit'] ?? '50') ?>" min="1" max="500">
                                <small class="text-muted">Max prospects pushed to CRM per run</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Default Pipeline Stage</label>
                                <select class="form-select" name="push_default_stage">
                                    <?php foreach (['cold', 'warm', 'hot', 'closing'] as $stage): ?>
                                    <option value="<?= $stage ?>" <?= ($s['push_default_stage'] ?? '') === $stage ? 'selected' : '' ?>><?= ucfirst($stage) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <h6 class="fw-semibold mt-4 mb-3">Lead Score Weights <small class="text-muted fw-normal">(max 100 total)</small></h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Has Email</label>
                                <input type="number" class="form-control" name="lead_score_email" value="<?= htmlspecialchars($s['lead_score_email'] ?? '20') ?>" min="0" max="100">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Has Phone</label>
                                <input type="number" class="form-control" name="lead_score_phone" value="<?= htmlspecialchars($s['lead_score_phone'] ?? '10') ?>" min="0" max="100">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Decision-Maker Name</label>
                                <input type="number" class="form-control" name="lead_score_name" value="<?= htmlspecialchars($s['lead_score_name'] ?? '20') ?>" min="0" max="100">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Company Size Known</label>
                                <input type="number" class="form-control" name="lead_score_size" value="<?= htmlspecialchars($s['lead_score_size'] ?? '10') ?>" min="0" max="100">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Shipping Signals</label>
                                <input type="number" class="form-control" name="lead_score_signals" value="<?= htmlspecialchars($s['lead_score_signals'] ?? '15') ?>" min="0" max="100">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">AI Confidence (max)</label>
                                <input type="number" class="form-control" name="lead_score_confidence_max" value="<?= htmlspecialchars($s['lead_score_confidence_max'] ?? '25') ?>" min="0" max="100">
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save CRM Push</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Section 5: Enrichment -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-enrichment">
                    <i class="fas fa-magic me-2"></i> Enrichment
                </button>
            </h2>
            <div id="sec-enrichment" class="accordion-collapse collapse" data-bs-parent="#settingsAccordion">
                <div class="accordion-body">
                    <form method="POST" action="/crm/dosettings">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                        <input type="hidden" name="section" value="enrichment">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Batch Size</label>
                                <input type="number" class="form-control" name="enrichment_batch_size" value="<?= htmlspecialchars($s['enrichment_batch_size'] ?? '20') ?>" min="1" max="100">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">AI Model</label>
                                <input type="text" class="form-control" name="enrichment_ai_model" value="<?= htmlspecialchars($s['enrichment_ai_model'] ?? '') ?>" placeholder="e.g. kimi-k2.5:cloud">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Fetch Delay (seconds)</label>
                                <input type="number" class="form-control" name="enrichment_fetch_delay" value="<?= htmlspecialchars($s['enrichment_fetch_delay'] ?? '2') ?>" min="0" max="30">
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Enrichment</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Section 6: Outreach -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#sec-outreach">
                    <i class="fas fa-paper-plane me-2"></i> Outreach
                </button>
            </h2>
            <div id="sec-outreach" class="accordion-collapse collapse" data-bs-parent="#settingsAccordion">
                <div class="accordion-body">
                    <form method="POST" action="/crm/dosettings">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                        <input type="hidden" name="section" value="outreach">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Min Lead Score</label>
                                <input type="number" class="form-control" name="outreach_min_lead_score" value="<?= htmlspecialchars($s['outreach_min_lead_score'] ?? '40') ?>" min="0" max="100">
                                <small class="text-muted">Contacts need at least this score for outreach</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Gap Days Between Touches</label>
                                <input type="number" class="form-control" name="outreach_gap_days" value="<?= htmlspecialchars($s['outreach_gap_days'] ?? '3') ?>" min="1" max="30">
                                <small class="text-muted">Minimum days between email sequence steps</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Max Sequence Steps</label>
                                <input type="number" class="form-control" name="outreach_max_steps" value="<?= htmlspecialchars($s['outreach_max_steps'] ?? '4') ?>" min="1" max="10">
                                <small class="text-muted">Number of emails in the outreach sequence</small>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Outreach</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>
