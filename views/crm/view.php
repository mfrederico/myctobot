<div class="container-fluid py-4">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/crm">CRM</a></li>
            <li class="breadcrumb-item"><a href="/crm/contacts">Contacts</a></li>
            <li class="breadcrumb-item active"><?= h($contact->fullName()) ?></li>
        </ol>
    </nav>

    <div class="row">
        <!-- Contact Info -->
        <div class="col-md-4">
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-user"></i> Contact Details</span>
                    <div class="d-flex gap-1">
                        <?php if (!empty($contact->companyEmail)): ?>
                            <a href="/communications/compose/<?= $contact->id ?>" class="btn btn-sm btn-outline-primary" title="Send email">
                                <i class="fas fa-paper-plane"></i>
                            </a>
                        <?php endif; ?>
                        <a href="/crm/edit/<?= $contact->id ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>
                    </div>
                </div>
                <div class="card-body">
                    <h4><?= h($contact->fullName()) ?></h4>

                    <?php if ($contact->contactDesignation === 'secondary'): ?>
                        <span class="badge bg-warning text-dark mb-2">Secondary Contact</span>
                        <?php if ($contact->mergeParentId): ?>
                            <a href="/crm/view/<?= $contact->mergeParentId ?>" class="small">View Primary</a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <table class="table table-sm mt-2">
                        <tr><th>Company</th><td><?php if ($contact->websiteUrl): ?><a href="<?= h($contact->websiteUrl) ?>" target="_blank"><?= h($contact->companyName ?? '') ?></a><?php else: ?><?= h($contact->companyName ?? '') ?><?php endif; ?></td></tr>
                        <tr><th>Email</th><td><a href="mailto:<?= h($contact->companyEmail ?? '') ?>"><?= h($contact->companyEmail ?? '') ?></a></td></tr>
                        <?php if ($contact->phone): ?>
                            <tr><th>Phone</th><td><a href="tel:<?= h($contact->phone ?? '') ?>"><?= h($contact->phone ?? '') ?></a></td></tr>
                        <?php endif; ?>
                        <tr><th>Type</th><td><span class="badge bg-<?= $contact->accountType === '3pl' ? 'info' : 'success' ?>"><?= strtoupper($contact->accountType ?? '') ?></span></td></tr>
                        <tr><th>Exec Type</th><td><?= ucfirst($contact->execType ?? '') ?></td></tr>
                        <tr><th>Company Size</th><td><?= h($contact->companySize ?? '') ?></td></tr>
                        <tr><th>Est. Shipments/mo</th><td><?= h($contact->estimatedShipments ?? '') ?></td></tr>
                        <tr>
                            <th>Status</th>
                            <td>
                                <?php if ($contact->statusCategory === 'prospect'): ?>
                                    <span class="badge <?= $contact->stageBadgeClass() ?>"><?= $contact->stageLabel() ?></span>
                                <?php else: ?>
                                    <span class="badge bg-success">Customer</span>
                                    <?php if ($contact->customerStatus): ?>
                                        - <?= h(ucfirst($contact->customerStatus)) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Tags</th>
                            <td>
                                <?php foreach ($contact->tagArray() as $t): ?>
                                    <span class="badge bg-dark"><?= h($t) ?></span>
                                <?php endforeach; ?>
                                <?php if (empty($contact->tagArray())): ?><span class="text-muted">None</span><?php endif; ?>
                            </td>
                        </tr>
                        <tr><th>Owner</th><td><?= h($owner && method_exists($owner, 'displayName') ? $owner->displayName() : 'Unassigned') ?></td></tr>
                        <tr><th>Last Touch</th><td>
                                    <?php if ($contact->lastTouchAt): ?>
                                        <small class="text-muted d-block mt-1">Last touch: <?= date('M j', @strtotime($contact->lastTouchAt)) ?></small>
                                    <?php endif; ?>

					</td></tr>
                    </table>

                    <!-- Quick Actions -->
                    <div class="d-flex gap-2 flex-wrap mt-3">
                        <?php if ($contact->statusCategory === 'prospect'): ?>
                            <form method="post" action="/crm/convert" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                <input type="hidden" name="id" value="<?= $contact->id ?>">
                                <input type="hidden" name="customer_status" value="onboarding">
                                <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Convert to customer?')">
                                    <i class="fas fa-arrow-right"></i> Convert to Customer
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="/crm/delete" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                            <input type="hidden" name="id" value="<?= $contact->id ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this contact and all associated data?')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>

                    <?php if ($contact->notes): ?>
                        <hr>
                        <small class="text-muted">Quick Notes</small>
                        <p class="small"><?= nl2br(h($contact->notes)) ?></p>
                    <?php endif; ?>

                    <?php if ($contact->wmsCustomerId): ?>
                        <hr>
                        <small class="text-muted">CannonWMS</small>
                        <p class="small">Customer ID: <?= h($contact->wmsCustomerId) ?></p>
                        <?php if ($contact->featureUsage): ?>
                            <p class="small">Features: <?= h($contact->featureUsage) ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Lead Score & Enrichment -->
            <?php
                $score = (int)($contact->leadScore ?? 0);
                $enrichment = json_decode($contact->enrichmentJson ?? '{}', true);
                $enrichData = $enrichment['enrichment'] ?? [];
            ?>
            <?php if ($score > 0 || $contact->enrichmentStatus): ?>
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-chart-bar"></i> Lead Score</span>
                        <?php if ($score > 0): ?>
                            <span class="badge bg-<?= $score >= 70 ? 'success' : ($score >= 40 ? 'warning text-dark' : 'secondary') ?> fs-6"><?= $score ?>/100</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($score > 0): ?>
                            <div class="progress mb-3" style="height: 8px;">
                                <div class="progress-bar bg-<?= $score >= 70 ? 'success' : ($score >= 40 ? 'warning' : 'secondary') ?>" style="width: <?= $score ?>%"></div>
                            </div>
                            <?php
                                // Score rubric breakdown
                                $hasEmail = !empty($contact->companyEmail);
                                $hasPhone = !empty($contact->phone);
                                $hasDM = !empty($contact->decisionMakerName);
                                $hasSize = !empty($contact->companySize);
                                $hasShipping = !empty($enrichData['shipping_signals']);
                                $confidence = (float)($enrichData['confidence'] ?? 0);
                                $rubric = [
                                    ['Has email', 20, $hasEmail],
                                    ['Has phone', 10, $hasPhone],
                                    ['Decision-maker found', 20, $hasDM],
                                    ['Company size known', 10, $hasSize],
                                    ['Shipping/fulfillment signals', 15, $hasShipping],
                                    ['AI confidence (' . round($confidence * 100) . '%)', 25, $confidence > 0],
                                ];
                            ?>
                            <table class="table table-sm table-borderless mb-0 small">
                                <?php foreach ($rubric as [$label, $pts, $earned]): ?>
                                    <tr class="<?= $earned ? '' : 'text-muted' ?>">
                                        <td style="width:20px">
                                            <?php if ($earned): ?>
                                                <i class="fas fa-check-circle text-success"></i>
                                            <?php else: ?>
                                                <i class="far fa-circle"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $label ?></td>
                                        <td class="text-end"><?= $earned ? '+' . (str_contains($label, 'AI confidence') ? (int)round($confidence * 25) : $pts) : '0' ?>/<?= $pts ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        <?php elseif ($contact->enrichmentStatus === 'failed'): ?>
                            <p class="text-danger mb-0 small"><i class="fas fa-exclamation-triangle"></i> Enrichment failed</p>
                        <?php elseif ($contact->enrichmentStatus === 'pending'): ?>
                            <p class="text-muted mb-0 small"><i class="fas fa-spinner fa-spin"></i> Enrichment pending...</p>
                        <?php else: ?>
                            <p class="text-muted mb-0 small">Not enriched yet</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Enrichment Details -->
            <?php if (!empty($enrichData)): ?>
                <div class="card mb-3">
                    <div class="card-header"><i class="fas fa-magic"></i> Enrichment Data</div>
                    <div class="card-body">
                        <table class="table table-sm mb-0">
                            <?php if (!empty($contact->decisionMakerName)): ?>
                                <tr><th>Decision Maker</th><td><?= h($contact->decisionMakerName) ?><?php if ($contact->decisionMakerTitle): ?> <small class="text-muted">(<?= h($contact->decisionMakerTitle) ?>)</small><?php endif; ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($contact->websiteUrl)): ?>
                                <tr><th>Website</th><td><a href="<?= h($contact->websiteUrl) ?>" target="_blank"><?= h($contact->websiteUrl) ?></a></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($contact->linkedinUrl)): ?>
                                <tr><th>LinkedIn</th><td><a href="<?= h($contact->linkedinUrl) ?>" target="_blank"><?= h($contact->linkedinUrl) ?></a></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($enrichData['industry'])): ?>
                                <tr><th>Industry</th><td><?= h($enrichData['industry']) ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($enrichData['shipping_signals'])): ?>
                                <tr><th>Shipping Signals</th><td class="small"><?= h($enrichData['shipping_signals']) ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($enrichData['summary'])): ?>
                                <tr><th>AI Summary</th><td class="small"><?= h($enrichData['summary']) ?></td></tr>
                            <?php endif; ?>
                        </table>
                        <?php if ($contact->enrichedAt): ?>
                            <small class="text-muted">Enriched <?= date('M j, Y g:ia', strtotime($contact->enrichedAt)) ?> via <?= h($contact->enrichmentSource ?? 'ai') ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- LinkedIn Company Insights -->
            <?php if (!empty($linkedinInsights) || !empty($linkedinData)): ?>
                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-linkedin text-primary"></i> LinkedIn</div>
                    <div class="card-body">
                        <table class="table table-sm mb-0">
                            <?php if (!empty($linkedinData['company_name'])): ?>
                                <tr><th style="width:35%">Company</th><td>
                                    <a href="<?= h($linkedinData['linkedin_url'] ?? '') ?>" target="_blank"><?= h($linkedinData['company_name']) ?></a>
                                </td></tr>
                            <?php endif; ?>
                            <?php if (!empty($linkedinData['industry'])): ?>
                                <tr><th>Industry</th><td><?= h($linkedinData['industry']) ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($linkedinInsights['employeeCount'] ?? $linkedinData['followers'])): ?>
                                <tr><th>Size</th><td>
                                    <?php if (!empty($linkedinInsights['employeeCount'])): ?>
                                        <?= h($linkedinInsights['employeeCount']) ?> employees
                                    <?php endif; ?>
                                    <?php if (!empty($linkedinData['followers'])): ?>
                                        <small class="text-muted">(<?= h($linkedinData['followers']) ?>)</small>
                                    <?php endif; ?>
                                </td></tr>
                            <?php endif; ?>
                            <?php if (!empty($linkedinInsights['headquarters'])): ?>
                                <tr><th>HQ</th><td><?= h($linkedinInsights['headquarters']) ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($linkedinInsights['founded'])): ?>
                                <tr><th>Founded</th><td><?= h($linkedinInsights['founded']) ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($linkedinInsights['website'])): ?>
                                <tr><th>Website</th><td><a href="<?= h($linkedinInsights['website']) ?>" target="_blank"><?= h($linkedinInsights['website']) ?></a></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($linkedinInsights['specialties'])): ?>
                                <tr><th>Specialties</th><td class="small"><?= h($linkedinInsights['specialties']) ?></td></tr>
                            <?php endif; ?>
                        </table>

                        <?php if (!empty($hiringSignals)): ?>
                            <hr class="my-2">
                            <div class="d-flex align-items-center gap-2">
                                <?php $jobCount = $hiringSignals['job_count'] ?? 0; ?>
                                <?php if ($jobCount > 0): ?>
                                    <span class="badge bg-success"><i class="bi bi-briefcase"></i> <?= $jobCount ?> open role<?= $jobCount > 1 ? 's' : '' ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><i class="bi bi-briefcase"></i> No open roles</span>
                                <?php endif; ?>
                                <?php if (!empty($hiringSignals['signals']['activelyHiring'])): ?>
                                    <span class="badge bg-success"><i class="bi bi-lightning"></i> Actively Hiring</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($linkedinData['enriched_at'])): ?>
                            <small class="text-muted d-block mt-2">LinkedIn data from <?= date('M j, Y', strtotime($linkedinData['enriched_at'])) ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- People at this Company -->
            <?php if (!empty($employees) || $contact->linkedinUrl || $contact->companyName): ?>
                <div class="card mb-3">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-people"></i> People (<?= count($employees ?? []) ?>)</span>
                            <button class="btn btn-sm btn-outline-primary" id="btnRefreshPeople" onclick="refreshLinkedInPeople(<?= $contact->id ?>)">
                                <i class="bi bi-arrow-clockwise"></i> <?= empty($employees) ? 'Discover' : 'Refresh' ?>
                            </button>
                        </div>
                        <div class="input-group input-group-sm mt-2">
                            <input type="text" class="form-control" id="linkedinSearchTerm"
                                   placeholder="Search term (company or person name)"
                                   value="<?= h($linkedinData['company_name'] ?? $contact->companyName ?? '') ?>">
                            <button class="btn btn-outline-secondary" type="button" onclick="refreshLinkedInPeople(<?= $contact->id ?>)">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>
                    <?php if (!empty($employees)): ?>
                        <div class="card-body p-0">
                            <div class="list-group list-group-flush">
                                <?php foreach ($employees as $emp): ?>
                                    <div class="list-group-item py-2">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <?php if ($emp->linkedin_url): ?>
                                                    <a href="<?= h($emp->linkedin_url) ?>" target="_blank" class="fw-semibold text-decoration-none">
                                                        <?= h($emp->name) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="fw-semibold"><?= h($emp->name) ?></span>
                                                <?php endif; ?>
                                                <br>
                                                <small class="text-muted"><?= h($emp->title) ?></small>
                                            </div>
                                            <span class="badge <?= $emp->roleBadgeClass() ?> ms-2">
                                                <i class="bi <?= $emp->roleIcon() ?>"></i>
                                                <?= ucfirst(str_replace('_', ' ', $emp->role_type ?? 'unknown')) ?>
                                            </span>
                                        </div>
                                        <?php if ($emp->location): ?>
                                            <small class="text-muted"><i class="bi bi-geo-alt"></i> <?= h($emp->location) ?></small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card-body">
                            <p class="text-muted mb-0 small">No people discovered yet. Click "Discover" to search LinkedIn.</p>
                        </div>
                    <?php endif; ?>
                    <div id="peopleRefreshStatus" class="px-3 pb-2" style="display:none;"></div>
                </div>
            <?php endif; ?>

            <!-- Pipeline Stage Quick Change (prospects only) -->
            <?php if ($contact->statusCategory === 'prospect'): ?>
                <div class="card mb-3">
                    <div class="card-header"><i class="fas fa-exchange-alt"></i> Move Stage</div>
                    <div class="card-body d-flex gap-2 flex-wrap">
                        <?php foreach (['cold' => 'secondary', 'warm' => 'warning', 'hot' => 'danger', 'closing' => 'primary'] as $s => $color): ?>
                            <button class="btn btn-sm btn-<?= $contact->pipelineStage === $s ? '' : 'outline-' ?><?= $color ?> btn-move-stage"
                                    data-stage="<?= $s ?>" data-id="<?= $contact->id ?>"
                                    <?= $contact->pipelineStage === $s ? 'disabled' : '' ?>>
                                <?= ucfirst($s === 'closing' ? 'Crucible' : $s) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Timeline: Notes & Touches -->
        <div class="col-md-8">
            <!-- Add Touch -->
            <div class="card mb-3">
                <div class="card-header"><i class="fas fa-hand-pointer"></i> Log a Touch</div>
                <div class="card-body">
                    <form id="touchForm">
                        <input type="hidden" name="contact_id" value="<?= $contact->id ?>">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <select name="touch_type" class="form-select form-select-sm">
                                    <option value="call">Phone Call</option>
                                    <option value="email">Email</option>
                                    <option value="meeting">Meeting</option>
                                    <option value="text">Text/SMS</option>
                                    <option value="linkedin">LinkedIn</option>
                                    <option value="manual">Other</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <input type="number" name="duration" class="form-control form-control-sm" placeholder="Min" min="0">
                            </div>
                            <div class="col-md-3">
                                <select name="outcome" class="form-select form-select-sm">
                                    <option value="">No outcome</option>
                                    <option value="connected">Connected</option>
                                    <option value="voicemail">Voicemail</option>
                                    <option value="noanswer">No Answer</option>
                                    <option value="callback">Callback Requested</option>
                                    <option value="sent">Sent</option>
                                    <option value="scheduled">Scheduled</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <input type="datetime-local" name="touch_date" class="form-control form-control-sm" value="<?= date('Y-m-d\TH:i') ?>">
                            </div>
                        </div>
                        <div class="row g-2 mt-1">
                            <div class="col-md-10">
                                <textarea name="summary" class="form-control form-control-sm" rows="2" placeholder="Summary of the interaction..."></textarea>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-sm btn-primary w-100">Log</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Add Note -->
            <div class="card mb-3">
                <div class="card-header"><i class="fas fa-sticky-note"></i> Add Note</div>
                <div class="card-body">
                    <form id="noteForm">
                        <input type="hidden" name="contact_id" value="<?= $contact->id ?>">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                        <div class="row g-2">
                            <div class="col-md-10">
                                <textarea name="content" class="form-control form-control-sm" rows="2" placeholder="Write a note..." required></textarea>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-sm btn-outline-primary w-100">Save</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Recent email conversations -->
            <?php if (!empty($recentThreads)): ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-envelope-open-text"></i> Recent Conversations</span>
                    <?php if (!empty($contact->companyEmail)): ?>
                        <a href="/communications/compose/<?= (int)$contact->id ?>" class="btn btn-sm btn-outline-primary" title="Send new email">
                            <i class="fas fa-paper-plane"></i> New
                        </a>
                    <?php endif; ?>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($recentThreads as $th):
                        $unread = (int)$th->unreadCount > 0;
                        $inbound = ($th->lastDirection ?? 'out') === 'in';
                    ?>
                        <a href="/communications/thread/<?= (int)$th->id ?>" class="list-group-item list-group-item-action">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div class="min-w-0 flex-grow-1">
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($unread): ?>
                                            <span class="rounded-circle d-inline-block" style="width:8px;height:8px;background:#0d6efd;flex-shrink:0;" title="Unread"></span>
                                        <?php endif; ?>
                                        <strong class="text-truncate <?= $unread ? '' : 'fw-semibold' ?>" style="min-width:0;">
                                            <?= h($th->subject ?: '(no subject)') ?>
                                        </strong>
                                    </div>
                                    <?php if ($th->lastPreview): ?>
                                        <div class="small text-muted text-truncate mt-1">
                                            <?php if ($inbound): ?>
                                                <i class="fas fa-arrow-down text-success me-1"></i>
                                            <?php else: ?>
                                                <i class="fas fa-arrow-up text-primary me-1"></i>
                                            <?php endif; ?>
                                            <?= h($th->lastPreview) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted text-nowrap">
                                    <?= $th->lastMessageAt ? date('M j', @strtotime($th->lastMessageAt)) : '' ?>
                                </small>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="card-footer bg-transparent text-center small">
                    <a href="/communications" class="text-decoration-none">Open Inbox <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Touches Timeline -->
            <div class="card mb-3">
                <div class="card-header"><i class="fas fa-history"></i> Touch History</div>
                <div class="card-body p-0" id="touchList">
                    <?php if (empty($touches)): ?>
                        <p class="text-muted p-3 mb-0">No touches logged yet.</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($touches as $t): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <i class="fas <?= $t->typeIcon() ?> text-primary me-2"></i>
                                            <strong><?= $t->typeLabel() ?></strong>
                                            <?php if ($t->outcome): ?>
                                                <span class="badge bg-light text-dark"><?= h(ucfirst($t->outcome)) ?></span>
                                            <?php endif; ?>
                                            <?php if ($t->duration): ?>
                                                <small class="text-muted">(<?= $t->duration ?> min)</small>
                                            <?php endif; ?>
                                            <?php if (!empty($t->emailthreadId)): ?>
                                                <a href="/communications/thread/<?= (int)$t->emailthreadId ?>" class="ms-1 small text-decoration-none">
                                                    <i class="fas fa-arrow-up-right-from-square"></i> View thread
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted"><?= date('M j, Y g:ia', @strtotime($t->touchDate)) ?></small>
                                    </div>
                                    <?php if ($t->summary): ?>
                                        <p class="mb-0 mt-1 small"><?= nl2br(h($t->summary)) ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Notes -->
            <div class="card mb-3">
                <div class="card-header"><i class="fas fa-sticky-note"></i> Notes</div>
                <div class="card-body p-0" id="noteList">
                    <?php if (empty($notes)): ?>
                        <p class="text-muted p-3 mb-0">No notes yet.</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($notes as $n): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <p class="mb-0"><?= nl2br(h($n->content)) ?></p>
                                        <small class="text-muted text-nowrap ms-3"><?= date('M j, Y', @strtotime($n->createdAt)) ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function refreshLinkedInPeople(contactId) {
    var btn = document.getElementById('btnRefreshPeople');
    var status = document.getElementById('peopleRefreshStatus');
    var searchTerm = document.getElementById('linkedinSearchTerm').value.trim();
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Searching...';
    status.style.display = 'block';
    status.innerHTML = '<small class="text-muted">Searching LinkedIn for "' + searchTerm + '"...</small>';

    fetch('/crm/refreshlinkedin', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'contact_id=' + contactId + '&search_term=' + encodeURIComponent(searchTerm) + '&csrf_token=' + encodeURIComponent('<?= $_SESSION['csrf_token'] ?? '' ?>')
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            status.innerHTML = '<small class="text-success"><i class="bi bi-check-circle"></i> ' + res.message + '</small>';
            setTimeout(function() { location.reload(); }, 1500);
        } else {
            status.innerHTML = '<small class="text-danger"><i class="bi bi-x-circle"></i> ' + (res.message || 'Failed') + '</small>';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Retry';
        }
    })
    .catch(function(err) {
        status.innerHTML = '<small class="text-danger">Error: ' + err.message + '</small>';
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Retry';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var csrfToken = '<?= $_SESSION['csrf_token'] ?? '' ?>';

    // Note form
    document.getElementById('noteForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        var formData = new FormData(form);
        fetch('/crm/addnote', { method: 'POST', body: new URLSearchParams(formData) })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    var html = '<div class="list-group-item"><div class="d-flex justify-content-between">' +
                        '<p class="mb-0">' + res.data.content + '</p>' +
                        '<small class="text-muted text-nowrap ms-3">' + res.data.date + '</small>' +
                        '</div></div>';
                    var list = document.getElementById('noteList');
                    var empty = list.querySelector('.text-muted.p-3');
                    if (empty) list.innerHTML = '<div class="list-group list-group-flush"></div>';
                    var group = list.querySelector('.list-group');
                    if (group) group.insertAdjacentHTML('afterbegin', html);
                    form.querySelector('textarea').value = '';
                } else {
                    alert(res.message);
                }
            });
    });

    // Touch form
    document.getElementById('touchForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        fetch('/crm/addtouch', { method: 'POST', body: new URLSearchParams(formData) })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) location.reload();
                else alert(res.message);
            });
    });

    // Move stage buttons
    document.querySelectorAll('.btn-move-stage').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var stage = this.dataset.stage;
            var id = this.dataset.id;
            var body = new URLSearchParams({ id: id, stage: stage, _csrf_token: csrfToken });
            fetch('/crm/movestage', { method: 'POST', body: body })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) location.reload();
                    else alert(res.message);
                });
        });
    });
});
</script>
