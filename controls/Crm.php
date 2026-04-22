<?php
/**
 * CRM Controller
 * Sales rep CRM for managing customers and prospects.
 * Requires SALES level (75) - ADMIN and ROOT can also access.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \Exception as Exception;

class Crm extends BaseControls\Control {

    /**
     * Override render to use CRM app layout (sidebar, no marketing header/footer)
     */
    protected function render($template, $data = [], $layout = true) {
        $data = array_merge($this->viewData, $data);

        if ($layout) {
            Flight::render($template, $data, 'body_content');
            Flight::render('layouts/crm-layout', $data);
        } else {
            Flight::render($template, $data);
        }
    }

    /**
     * Gate: redirect to agreement page if sales rep hasn't signed
     * Admins and root are exempt.
     */
    private function requireAgreement(): bool {
        if (!Agreement::hasSignedAgreement($this->member->id)) {
            Flight::redirect('/agreement');
            return false;
        }
        return true;
    }

    /**
     * Visibility filter for crmcontact queries.
     * Admin/root sees everything. Others see owned OR shared-with contacts.
     * Returns [sqlFragment, params] to be AND-combined into a WHERE clause.
     */
    private function visibilitySql(): array {
        if ($this->member->level <= LEVELS['ADMIN']) {
            return ['1=1', []];
        }
        $mid = (int)$this->member->id;
        $sql = '(member_id = ? OR id IN (SELECT crmcontact_id FROM crmcontact_member WHERE member_id = ?))';
        return [$sql, [$mid, $mid]];
    }

    /**
     * Can the member view the contact and log notes/touches/stage changes.
     * Owner, sharee, or admin.
     */
    private function canAccess($contact): bool {
        if (!$contact->id) return false;
        if ($this->member->level <= LEVELS['ADMIN']) return true;
        if ((int)$contact->memberId === (int)$this->member->id) return true;
        foreach ($contact->sharedMemberList as $m) {
            if ((int)$m->id === (int)$this->member->id) return true;
        }
        return false;
    }

    /**
     * Can the member edit, delete, reassign, or change sharing on the contact.
     * Owner or admin only.
     */
    private function canManage($contact): bool {
        if (!$contact->id) return false;
        if ($this->member->level <= LEVELS['ADMIN']) return true;
        return (int)$contact->memberId === (int)$this->member->id;
    }

    /**
     * Log a CRM activity
     */
    private function logActivity(string $type, string $description, int $contactId = 0): void {
        $activity = Bean::dispense('crmactivity');
        $activity->memberId = $this->member->id;
        $activity->crmcontactId = $contactId;
        $activity->activityType = $type;
        $activity->description = $description;
        Bean::store($activity);
    }

    /**
     * CRM Dashboard
     */
    public function index($params = null) {
        if (!$this->requireAgreement()) return;

        $member = $this->member;
        $isAdmin = $member->level <= LEVELS['ADMIN'];
        [$vis, $visParams] = $this->visibilitySql();

        // Pipeline counts
        $stages = ['cold', 'warm', 'hot', 'closing'];
        $pipelineCounts = [];
        foreach ($stages as $stage) {
            $sql = "pipeline_stage = ? AND status_category = 'prospect' AND {$vis}";
            $pipelineCounts[$stage] = Bean::count('crmcontact', $sql, array_merge([$stage], $visParams));
        }

        // Customer count
        $customerCount = Bean::count('crmcontact', "status_category = 'customer' AND {$vis}", $visParams);

        // Recent activity — still scoped to this member's own actions (not every action on shared leads)
        $actSql = $isAdmin ? '1=1 ORDER BY created_at DESC LIMIT 15' : 'member_id = ? ORDER BY created_at DESC LIMIT 15';
        $recentActivity = Bean::find('crmactivity', $actSql, $isAdmin ? [] : [$member->id]);

        // Recent touches needing follow-up (contacts not touched in 7+ days)
        $weekAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
        $staleContacts = Bean::find(
            'crmcontact',
            "(last_touch_at IS NULL OR last_touch_at < ?) AND status_category = 'customer' AND {$vis} ORDER BY last_touch_at ASC LIMIT 10",
            array_merge([$weekAgo], $visParams)
        );

        $this->render('crm/index', [
            'title' => 'CRM Dashboard',
            'crm_page' => 'dashboard',
            'pipelineCounts' => $pipelineCounts,
            'customerCount' => $customerCount,
            'recentActivity' => $recentActivity,
            'staleContacts' => $staleContacts,
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * List contacts with filters
     */
    public function contacts($params = null) {
        if (!$this->requireAgreement()) return;
        $filter = $this->getParam('filter', 'all');
        $stage = $this->getParam('stage', '');
        $status = $this->getParam('status', '');
        $accountType = $this->getParam('account_type', '');
        $search = $this->getParam('q', '');
        $tag = $this->getParam('tag', '');

        $member = $this->member;
        $isAdmin = $member->level <= LEVELS['ADMIN'];
        [$vis, $visParams] = $this->visibilitySql();

        $sql = $vis;
        $sqlParams = $visParams;

        if ($filter === 'prospects') {
            $sql .= " AND status_category = 'prospect'";
        } elseif ($filter === 'customers') {
            $sql .= " AND status_category = 'customer'";
        }

        if ($stage) {
            $sql .= ' AND pipeline_stage = ?';
            $sqlParams[] = $stage;
        }

        if ($status) {
            $sql .= ' AND customer_status = ?';
            $sqlParams[] = $status;
        }

        if ($accountType) {
            $sql .= ' AND account_type = ?';
            $sqlParams[] = $accountType;
        }

        if ($tag) {
            $sql .= ' AND tags LIKE ?';
            $sqlParams[] = "%{$tag}%";
        }

        if ($search) {
            $sql .= ' AND (first_name LIKE ? OR last_name LIKE ? OR company_name LIKE ? OR company_email LIKE ?)';
            $term = "%{$search}%";
            $sqlParams = array_merge($sqlParams, [$term, $term, $term, $term]);
        }

        $contacts = Bean::find('crmcontact', "{$sql} ORDER BY updated_at DESC", $sqlParams);

        // Collect unique tags for the filter sidebar
        $allTags = [];
        foreach ($contacts as $c) {
            foreach ($c->tagArray() as $t) {
                $allTags[$t] = ($allTags[$t] ?? 0) + 1;
            }
        }
        arsort($allTags);

        $this->render('crm/contacts', [
            'title' => 'CRM Contacts',
            'crm_page' => $filter === 'prospects' ? 'prospects' : 'contacts',
            'contacts' => $contacts,
            'filter' => $filter,
            'stage' => $stage,
            'status' => $status,
            'accountType' => $accountType,
            'search' => $search,
            'tag' => $tag,
            'allTags' => $allTags,
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * View single contact with notes, touches, timeline
     */
    public function view($params = null) {
        if (!$this->requireAgreement()) return;
        $id = (int)($params['operation']->type ?? 0);
        if (!$id) { $id = (int)($params['operation']->name ?? 0); }
        $contact = Bean::load('crmcontact', $id);

        if (!$this->canAccess($contact)) {
            $this->flash('error', 'Contact not found.');
            Flight::redirect('/crm/contacts');
            return;
        }

        $notes = $contact->with(' ORDER BY created_at DESC ')->ownCrmnoteList;
        $touches = $contact->with(' ORDER BY touch_date DESC ')->ownCrmtouchList;
        $employees = $contact->with(' ORDER BY role_type ASC, name ASC ')->ownCrmemployeeList;

        // Recent email conversations for the contact-page inline summary card.
        // Sales reps: scoped to their own threads. Admin: all threads on the
        // contact. We cap at 3 because the card is a glance, not a full index —
        // the full inbox is a click away via the "Open inbox" link.
        $threadWhere  = 'crmcontact_id = ? AND is_archived = 0';
        $threadParams = [(int)$contact->id];
        if ($this->member->level > LEVELS['ADMIN']) {
            $threadWhere  .= ' AND member_id = ?';
            $threadParams[] = (int)$this->member->id;
        }
        $recentThreads = Bean::find('emailthread', "{$threadWhere} ORDER BY last_message_at DESC LIMIT 3", $threadParams);

        // Load the sales rep who owns this contact
        $owner = Bean::load('member', (int)($contact->memberId ?? 0));

        // Sharing: current sharees + full list of reps the owner/admin can share with
        $canManage = $this->canManage($contact);
        $sharedReps = $contact->sharedMemberList;
        $allReps = [];
        if ($canManage) {
            $ownerId = (int)($contact->memberId ?? 0);
            // Anyone below PUBLIC (any real team member) can be a sharee
            $allReps = Bean::find('member', 'level < ? AND id != ? ORDER BY username ASC', [LEVELS['PUBLIC'], $ownerId]);
        }

        // Parse LinkedIn enrichment data
        $enrichmentAll = json_decode($contact->enrichmentJson ?? '{}', true);
        $linkedinData = $enrichmentAll['linkedin'] ?? [];
        $linkedinInsights = $enrichmentAll['linkedin_insights'] ?? [];
        $hiringSignals = $enrichmentAll['hiring_signals'] ?? [];

        $this->render('crm/view', [
            'title' => $contact->fullName() . ' - CRM',
            'crm_page' => 'contacts',
            'contact' => $contact,
            'notes' => $notes,
            'touches' => $touches,
            'employees' => $employees,
            'owner' => $owner,
            'sharedReps' => $sharedReps,
            'allReps' => $allReps,
            'canManage' => $canManage,
            'linkedinData' => $linkedinData,
            'linkedinInsights' => $linkedinInsights,
            'hiringSignals' => $hiringSignals,
            'recentThreads' => $recentThreads,
        ]);
    }

    /**
     * Replace the set of members a contact is shared with (AJAX).
     * Only the owner or an admin can change sharing.
     * Expects POST: contact_id, member_ids[]
     */
    public function share($params = null) {
        if (!$this->requireAgreement()) return;
        if (!$this->validateCSRF()) return;

        $contactId = (int)$this->getParam('contact_id');
        $contact = Bean::load('crmcontact', $contactId);

        if (!$this->canManage($contact)) {
            $this->jsonError('Only the owner or an admin can change sharing.', 403);
            return;
        }

        $ownerId = (int)($contact->memberId ?? 0);
        $raw = $this->getParam('member_ids', []);
        if (is_string($raw)) {
            $raw = array_filter(explode(',', $raw), fn($v) => $v !== '');
        }
        $ids = array_values(array_unique(array_map('intval', (array)$raw)));
        $ids = array_values(array_filter($ids, fn($i) => $i > 0 && $i !== $ownerId));

        $newList = [];
        $names = [];
        foreach ($ids as $id) {
            $m = Bean::load('member', $id);
            if (!$m->id) continue;
            $newList[] = $m;
            $names[] = method_exists($m, 'displayName') ? $m->displayName() : ($m->username ?? "member#{$m->id}");
        }

        $contact->sharedMemberList = $newList;
        Bean::store($contact);

        $this->logActivity(
            'contact_shared',
            'Shared with: ' . (empty($names) ? 'no one' : implode(', ', $names)),
            $contact->id
        );

        $this->jsonSuccess(
            ['shared_count' => count($newList), 'shared_with' => $names],
            empty($newList) ? 'Sharing cleared.' : 'Sharing updated.'
        );
    }

    /**
     * Create new contact form
     */
    public function create($params = null) {
        if (!$this->requireAgreement()) return;
        // Get list of sales reps for admin assignment
        $salesReps = [];
        if ($this->member->level <= LEVELS['ADMIN']) {
            $salesReps = Bean::find('member', 'level <= ? ORDER BY username ASC', [LEVELS['SALES']]);
        }

        $this->render('crm/create', [
            'title' => 'Add Contact - CRM',
            'crm_page' => 'create',
            'salesReps' => $salesReps,
        ]);
    }

    /**
     * Process create contact
     */
    public function docreate($params = null) {
        if (!$this->requireAgreement()) return;
        if (!$this->validateCSRF()) return;

        $contact = Bean::dispense('crmcontact');
        $this->populateContact($contact);

        // Set owner
        if ($this->member->level <= LEVELS['ADMIN'] && $this->getParam('member_id')) {
            $contact->memberId = (int)$this->getParam('member_id');
        } else {
            $contact->memberId = $this->member->id;
        }

        Bean::store($contact);

        $this->logActivity('contact_created', "Created contact: {$contact->fullName()}", $contact->id);
        $this->flash('success', 'Contact created successfully.');
        Flight::redirect("/crm/view/{$contact->id}");
    }

    /**
     * Edit contact form
     */
    public function edit($params = null) {
        if (!$this->requireAgreement()) return;
        $id = (int)($params['operation']->name ?? 0);
        $contact = Bean::load('crmcontact', $id);

        if (!$this->canManage($contact)) {
            $this->flash('error', 'Contact not found.');
            Flight::redirect('/crm/contacts');
            return;
        }

        $salesReps = [];
        if ($this->member->level <= LEVELS['ADMIN']) {
            $salesReps = Bean::find('member', 'level <= ? ORDER BY username ASC', [LEVELS['SALES']]);
        }

        $this->render('crm/edit', [
            'title' => 'Edit ' . $contact->fullName() . ' - CRM',
            'crm_page' => 'contacts',
            'contact' => $contact,
            'salesReps' => $salesReps,
        ]);
    }

    /**
     * Process edit contact
     */
    public function doedit($params = null) {
        if (!$this->requireAgreement()) return;
        if (!$this->validateCSRF()) return;

        $id = (int)$this->getParam('id');
        $contact = Bean::load('crmcontact', $id);

        if (!$this->canManage($contact)) {
            $this->flash('error', 'Contact not found.');
            Flight::redirect('/crm/contacts');
            return;
        }

        $this->populateContact($contact);

        // Admin can reassign
        if ($this->member->level <= LEVELS['ADMIN'] && $this->getParam('member_id')) {
            $contact->memberId = (int)$this->getParam('member_id');
        }

        Bean::store($contact);

        $this->logActivity('contact_updated', "Updated contact: {$contact->fullName()}", $contact->id);
        $this->flash('success', 'Contact updated.');
        Flight::redirect("/crm/view/{$contact->id}");
    }

    /**
     * Delete contact
     */
    public function delete($params = null) {
        if (!$this->requireAgreement()) return;
        if (!$this->validateCSRF()) return;

        $id = (int)$this->getParam('id');
        $contact = Bean::load('crmcontact', $id);

        if (!$this->canManage($contact)) {
            $this->flash('error', 'Contact not found.');
            Flight::redirect('/crm/contacts');
            return;
        }

        $name = $contact->fullName();

        // Cascade delete notes and touches
        $contact->xownCrmnoteList;
        $contact->xownCrmtouchList;
        Bean::trash($contact);

        $this->logActivity('contact_deleted', "Deleted contact: {$name}");
        $this->flash('success', 'Contact deleted.');
        Flight::redirect('/crm/contacts');
    }

    /**
     * Add note (AJAX)
     */
    public function addnote($params = null) {
        if (!$this->requireAgreement()) return;
        if (!$this->validateCSRF()) return;

        $contactId = (int)$this->getParam('contact_id');
        $contact = Bean::load('crmcontact', $contactId);

        if (!$this->canAccess($contact)) {
            $this->jsonError('Contact not found.', 404);
            return;
        }

        $content = trim($this->getParam('content', ''));
        if (empty($content)) {
            $this->jsonError('Note content is required.');
            return;
        }

        $note = Bean::dispense('crmnote');
        $note->crmcontactId = $contactId;
        $note->memberId = $this->member->id;
        $note->content = $content;
        Bean::store($note);

        $this->logActivity('note_added', "Added note to {$contact->fullName()}", $contactId);

        $this->jsonSuccess([
            'id' => $note->id,
            'content' => htmlspecialchars($content),
            'author' => $this->member->displayName(),
            'date' => date('M j, Y g:ia'),
        ], 'Note added.');
    }

    /**
     * Add touch (AJAX) - calls, emails, meetings, etc.
     */
    public function addtouch($params = null) {
        if (!$this->requireAgreement()) return;
        if (!$this->validateCSRF()) return;

        $contactId = (int)$this->getParam('contact_id');
        $contact = Bean::load('crmcontact', $contactId);

        if (!$this->canAccess($contact)) {
            $this->jsonError('Contact not found.', 404);
            return;
        }

        $touch = Bean::dispense('crmtouch');
        $touch->crmcontactId = $contactId;
        $touch->memberId = $this->member->id;
        $touch->touchType = $this->getParam('touch_type', 'manual');
        $touch->touchDate = $this->getParam('touch_date', date('Y-m-d H:i:s'));
        $touch->summary = trim($this->getParam('summary', ''));
        $touch->outcome = $this->getParam('outcome', '');
        $touch->duration = (int)$this->getParam('duration', 0) ?: null;
        $touch->source = 'manual';
        Bean::store($touch);

        // Update last touch date on contact
        $contact->lastTouchAt = date('Y-m-d H:i:s');
        Bean::store($contact);

        $this->logActivity('touch_added', "Logged {$touch->touchType} with {$contact->fullName()}", $contactId);

        $this->jsonSuccess([
            'id' => $touch->id,
            'touchType' => $touch->touchType,
            'summary' => htmlspecialchars($touch->summary),
            'date' => date('M j, Y g:ia', strtotime($touch->touchDate)),
        ], 'Touch logged.');
    }

    /**
     * Pipeline Kanban view
     */
    public function pipeline($params = null) {
        if (!$this->requireAgreement()) return;
        $member = $this->member;
        $isAdmin = $member->level <= LEVELS['ADMIN'];
        [$vis, $visParams] = $this->visibilitySql();

        $pipeline = [];
        foreach (['cold', 'warm', 'hot', 'closing'] as $stage) {
            $pipeline[$stage] = Bean::find('crmcontact',
                "{$vis} AND pipeline_stage = ? AND status_category = 'prospect' ORDER BY updated_at DESC",
                array_merge($visParams, [$stage])
            );
        }

        $this->render('crm/pipeline', [
            'title' => 'Sales Pipeline - CRM',
            'crm_page' => 'pipeline',
            'pipeline' => $pipeline,
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Move contact to different pipeline stage (AJAX)
     */
    public function movestage($params = null) {
        if (!$this->requireAgreement()) return;
        if (!$this->validateCSRF()) return;

        $id = (int)$this->getParam('id');
        $stage = $this->getParam('stage');
        $validStages = ['cold', 'warm', 'hot', 'closing'];

        if (!in_array($stage, $validStages)) {
            $this->jsonError('Invalid stage.');
            return;
        }

        $contact = Bean::load('crmcontact', $id);
        if (!$this->canAccess($contact)) {
            $this->jsonError('Contact not found.', 404);
            return;
        }

        $oldStage = $contact->pipelineStage;
        $contact->pipelineStage = $stage;
        Bean::store($contact);

        $this->logActivity('stage_changed', "Moved {$contact->fullName()} from {$oldStage} to {$stage}", $id);
        $this->jsonSuccess([], 'Stage updated.');
    }

    /**
     * Refresh LinkedIn people for a contact (AJAX)
     */
    public function refreshlinkedin($params = null) {
        if (!$this->requireAgreement()) return;
        if (!$this->validateCSRF()) return;

        $contactId = (int) $this->getParam('contact_id');
        $contact = Bean::load('crmcontact', $contactId);
        if (!$this->canAccess($contact)) {
            Flight::jsonError('Contact not found.', 404);
            return;
        }

        require_once __DIR__ . '/../services/LinkedInBrowserService.php';
        require_once __DIR__ . '/../models/Model_Crmemployee.php';

        $workspace = $_SESSION['workspace_slug'] ?? $_SERVER['WORKSPACE'] ?? 'default';
        $svc = new \app\services\LinkedInBrowserService($workspace);

        // Check session
        $session = $svc->isSessionValid();
        if (empty($session['valid'])) {
            Flight::jsonError('LinkedIn session expired. Re-authenticate via VNC.');
            return;
        }

        // Use custom search term if provided, otherwise fall back to LinkedIn/company name
        $enrichment = json_decode($contact->enrichmentJson ?: '{}', true);
        $customSearch = trim($this->getParam('search_term') ?? '');
        $searchName = $customSearch ?: ($enrichment['linkedin']['company_name'] ?? $contact->companyName);
        $linkedinUrl = $contact->linkedinUrl ?: null;

        // Delete existing employees for this contact
        $existing = Bean::find('crmemployee', 'crmcontact_id = ?', [$contactId]);
        foreach ($existing as $e) { Bean::trash($e); }

        // Discover employees
        $discovery = $svc->discoverEmployees($searchName, $linkedinUrl);
        $confirmed = $discovery['employees'] ?? [];

        // Update insights if available
        if (!empty($discovery['insights'])) {
            $enrichment['linkedin_insights'] = $discovery['insights'];
        }
        if (!empty($discovery['hiring'])) {
            $enrichment['hiring_signals'] = $discovery['hiring'];
        }
        $contact->enrichmentJson = json_encode($enrichment);

        // Store confirmed employees
        $created = 0;
        foreach ($confirmed as $emp) {
            $name = $emp['name'] ?? '';
            if (empty($name)) continue;

            $employee = Bean::dispense('crmemployee');
            $employee->name = $name;
            $employee->title = $emp['headline'] ?? '';
            $employee->linkedin_url = $emp['linkedinUrl'] ?? '';
            $employee->location = $emp['location'] ?? '';
            $employee->role_type = \Model_Crmemployee::classifyRole($emp['headline'] ?? '');
            $employee->source = 'linkedin';
            $contact->ownCrmemployeeList[] = $employee;
            $created++;
        }

        Bean::store($contact);

        // Log touch
        $touch = Bean::dispense('crmtouch');
        $touch->touchType = 'linkedin';
        $touch->summary = "LinkedIn refresh: {$created} employees found" . ($linkedinUrl ? '' : ' (people search only)');
        $touch->source = 'manual';
        $touch->touchDate = date('Y-m-d H:i:s');
        $contact->ownCrmtouchList[] = $touch;
        Bean::store($contact);

        Flight::jsonSuccess(
            ['employees' => $created, 'insights' => !empty($discovery['insights'])],
            "{$created} employees found" . (!empty($discovery['insights']) ? ', insights updated' : '')
        );
    }

    /**
     * Convert prospect to customer (AJAX or POST)
     */
    public function convert($params = null) {
        if (!$this->requireAgreement()) return;
        if (!$this->validateCSRF()) return;

        $id = (int)$this->getParam('id');
        $contact = Bean::load('crmcontact', $id);

        if (!$this->canAccess($contact)) {
            $this->jsonError('Contact not found.', 404);
            return;
        }

        $contact->statusCategory = 'customer';
        $contact->customerStatus = $this->getParam('customer_status', 'onboarding');
        $contact->pipelineStage = null;
        Bean::store($contact);

        $this->logActivity('prospect_converted', "Converted {$contact->fullName()} to customer", $id);

        if (Flight::request()->ajax) {
            $this->jsonSuccess([], 'Contact converted to customer.');
        } else {
            $this->flash('success', 'Contact converted to customer.');
            Flight::redirect("/crm/view/{$id}");
        }
    }

    /**
     * Merge contacts - set primary/secondary designation
     */
    public function merge($params = null) {
        if (!$this->requireAgreement()) return;
        if (!$this->validateCSRF()) return;

        $primaryId = (int)$this->getParam('primary_id');
        $secondaryId = (int)$this->getParam('secondary_id');

        $primary = Bean::load('crmcontact', $primaryId);
        $secondary = Bean::load('crmcontact', $secondaryId);

        if (!$this->canManage($primary) || !$this->canManage($secondary)) {
            $this->jsonError('Contact not found.', 404);
            return;
        }

        $primary->contactDesignation = 'primary';
        $secondary->contactDesignation = 'secondary';
        $secondary->mergeParentId = $primaryId;
        Bean::store($primary);
        Bean::store($secondary);

        $this->logActivity('contacts_merged',
            "Merged {$secondary->fullName()} into {$primary->fullName()} as secondary",
            $primaryId
        );

        $this->flash('success', 'Contacts merged. ' . $secondary->fullName() . ' is now secondary.');
        Flight::redirect("/crm/view/{$primaryId}");
    }

    /**
     * Customers list view
     */
    public function customers($params = null) {
        if (!$this->requireAgreement()) return;
        $member = $this->member;
        $isAdmin = $member->level <= LEVELS['ADMIN'];
        [$vis, $visParams] = $this->visibilitySql();

        $sql = "status_category = 'customer' AND {$vis}";
        $sqlParams = $visParams;

        $status = $this->getParam('status', '');
        if ($status) {
            $sql .= ' AND customer_status = ?';
            $sqlParams[] = $status;
        }

        $customers = Bean::find('crmcontact', "{$sql} ORDER BY updated_at DESC", $sqlParams);

        $this->render('crm/customers', [
            'title' => 'Customers - CRM',
            'crm_page' => 'customers',
            'customers' => $customers,
            'status' => $status,
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Prospect Pipeline Settings (admin only)
     */
    public function settings($params = null) {
        if ($this->member->level > LEVELS['ADMIN']) {
            $this->flash('danger', 'Admin access required.');
            Flight::redirect('/crm');
            return;
        }

        $workspace = $_SESSION['workspace_slug'] ?? 'default';
        $settingsService = \app\services\ProspectSettingsService::fromWorkspace($workspace);

        $this->render('crm/settings', [
            'title' => 'Pipeline Settings - CRM',
            'crm_page' => 'settings',
            's' => $settingsService->getAll(),
            'searchQueries' => $settingsService->getSearchQueries(),
            'apiKeys' => $settingsService->getApiKeyStatus(),
        ]);
    }

    /**
     * Save prospect pipeline settings (admin only, POST)
     */
    public function dosettings($params = null) {
        if ($this->member->level > LEVELS['ADMIN']) {
            Flight::redirect('/crm');
            return;
        }
        if (!$this->validateCSRF()) return;

        $workspace = $_SESSION['workspace_slug'] ?? 'default';
        $settingsService = \app\services\ProspectSettingsService::fromWorkspace($workspace);
        $section = $this->getParam('section', '');
        $sections = \app\services\ProspectSettingsService::getSections();

        if ($section === 'queries') {
            // Special handling for queries
            if ($this->getParam('seed_from_file')) {
                $text = $settingsService->loadQueriesFromFile();
                $settingsService->setSearchQueries($text);
                $this->flash('success', 'Queries loaded from file.');
            } else {
                $settingsService->setSearchQueries($this->getParam('search_queries', ''));
                $this->flash('success', 'Search queries saved.');
            }
        } elseif (isset($sections[$section])) {
            $keys = $sections[$section];
            foreach ($keys as $key) {
                $value = $this->getParam($key, '');
                $settingsService->set($key, $value);
            }
            $this->flash('success', ucfirst($section) . ' settings saved.');
        } else {
            $this->flash('danger', 'Unknown settings section.');
        }

        Flight::redirect('/crm/settings');
    }

    /**
     * Populate contact bean from request params
     */
    private function populateContact($contact): void {
        $contact->firstName = $this->sanitize($this->getParam('first_name', ''));
        $contact->lastName = $this->sanitize($this->getParam('last_name', ''));
        $contact->companyEmail = $this->sanitize($this->getParam('company_email', ''), 'email');
        $contact->companyName = $this->sanitize($this->getParam('company_name', ''));
        $contact->companySize = $this->sanitize($this->getParam('company_size', ''));
        $contact->estimatedShipments = $this->sanitize($this->getParam('estimated_shipments', ''));
        $contact->phone = $this->sanitize($this->getParam('phone', ''));
        $contact->accountType = $this->getParam('account_type', '3pl');
        $contact->execType = $this->getParam('exec_type', 'sales');
        $contact->statusCategory = $this->getParam('status_category', 'prospect');
        $contact->pipelineStage = $this->getParam('pipeline_stage', 'cold');
        $contact->customerStatus = $this->getParam('customer_status', '');
        $contact->tags = $this->sanitize($this->getParam('tags', ''));
        $contact->notes = $this->sanitize($this->getParam('notes', ''));
        $contact->isActive = 1;

        // STUB: Future CannonWMS integration fields
        $contact->wmsCustomerId = $this->getParam('wms_customer_id', '') ?: null;
        $contact->featureUsage = $this->getParam('feature_usage', '') ?: null;
    }

    /**
     * Team Activity Dashboard — admin-only view of rep performance
     * GET /crm/teamactivity
     */
    public function teamactivity($params = null) {
        if (!$this->requireAgreement()) return;

        // Admin only
        if ($this->member->level > LEVELS['ADMIN']) {
            $this->flash('error', 'Admin access required.');
            Flight::redirect('/crm');
            return;
        }

        $now = time();
        $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
        $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

        // Get all CRM-enabled members
        $members = Bean::find('member', 'crm_access = 1 ORDER BY last_login DESC');

        $repStats = [];
        foreach ($members as $m) {
            $mid = (int)$m->id;

            // Activity counts (last 30 days)
            $activitiesMonth = Bean::count('crmactivity',
                'member_id = ? AND created_at >= ?', [$mid, $thirtyDaysAgo]);
            $activitiesWeek = Bean::count('crmactivity',
                'member_id = ? AND created_at >= ?', [$mid, $sevenDaysAgo]);

            // Touch counts
            $touchesMonth = Bean::count('crmtouch',
                'member_id = ? AND created_at >= ?', [$mid, $thirtyDaysAgo]);
            $touchesWeek = Bean::count('crmtouch',
                'member_id = ? AND created_at >= ?', [$mid, $sevenDaysAgo]);

            // Notes
            $notesMonth = Bean::count('crmnote',
                'member_id = ? AND created_at >= ?', [$mid, $thirtyDaysAgo]);

            // Contacts owned
            $contactsOwned = Bean::count('crmcontact', 'member_id = ?', [$mid]);

            // Contacts created (from activity log)
            $contactsCreated = Bean::count('crmactivity',
                "member_id = ? AND activity_type = 'contact_created' AND created_at >= ?",
                [$mid, $thirtyDaysAgo]);

            // Conversions
            $conversions = Bean::count('crmactivity',
                "member_id = ? AND activity_type = 'prospect_converted' AND created_at >= ?",
                [$mid, $thirtyDaysAgo]);

            // Days since last login
            $lastLogin = $m->last_login ? strtotime($m->last_login) : 0;
            $daysSinceLogin = $lastLogin ? (int)(($now - $lastLogin) / 86400) : null;

            // Days since last activity
            $lastActivity = Bean::getCell(
                'SELECT MAX(created_at) FROM crmactivity WHERE member_id = ?', [$mid]);
            $daysSinceActivity = $lastActivity ? (int)(($now - strtotime($lastActivity)) / 86400) : null;

            // Status flags
            $isStale = ($daysSinceLogin === null || $daysSinceLogin > 3)
                    && ($daysSinceActivity === null || $daysSinceActivity > 3);
            $isInactive = ($daysSinceLogin === null || $daysSinceLogin > 7)
                       && ($daysSinceActivity === null || $daysSinceActivity > 7);

            $repStats[] = [
                'member' => $m,
                'contacts_owned' => $contactsOwned,
                'contacts_created_30d' => $contactsCreated,
                'conversions_30d' => $conversions,
                'activities_30d' => $activitiesMonth,
                'activities_7d' => $activitiesWeek,
                'touches_30d' => $touchesMonth,
                'touches_7d' => $touchesWeek,
                'notes_30d' => $notesMonth,
                'days_since_login' => $daysSinceLogin,
                'days_since_activity' => $daysSinceActivity,
                'last_login' => $m->last_login,
                'last_activity' => $lastActivity,
                'login_count' => (int)($m->login_count ?? 0),
                'is_stale' => $isStale,
                'is_inactive' => $isInactive,
            ];
        }

        // Unassigned contacts
        $unassigned = Bean::count('crmcontact',
            "(member_id IS NULL OR member_id = 0 OR member_id = '')");

        $this->render('crm/teamactivity', [
            'title' => 'Team Activity - CRM',
            'crm_page' => 'teamactivity',
            'repStats' => $repStats,
            'unassigned' => $unassigned,
            'totalContacts' => Bean::count('crmcontact', '1=1'),
        ]);
    }

    /**
     * Public API endpoint for external lead capture (e.g., landing-page funnel)
     * Authenticates via shared secret in X-Lead-Key header.
     * POST /crm/apilead
     */
    public function apilead($params = null) {
        if (Flight::request()->method !== 'POST') {
            Flight::jsonError('Method not allowed', 405);
            return;
        }

        // Authenticate via shared secret
        $config = parse_ini_file(__DIR__ . '/../conf/config.shipcannon.ini', true);
        $expectedKey = $config['security']['lead_api_key'] ?? '';
        $providedKey = $_SERVER['HTTP_X_LEAD_KEY'] ?? '';

        if (empty($expectedKey) || !hash_equals($expectedKey, $providedKey)) {
            Flight::jsonError('Unauthorized', 401);
            return;
        }

        // Parse JSON body
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input) || empty($input['email'])) {
            Flight::jsonError('Email is required', 400);
            return;
        }

        $email = trim($input['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flight::jsonError('Invalid email', 400);
            return;
        }

        // Deduplicate by email
        $existing = Bean::findOne('crmcontact', ' company_email = ? ', [$email]);
        if ($existing) {
            Flight::jsonSuccess(['id' => $existing->id, 'duplicate' => true], 'Contact already exists');
            return;
        }

        $path = $input['path'] ?? 'brand';
        $domain = trim($input['domain'] ?? '');
        $zip = trim($input['zip'] ?? '');
        $answers = $input['answers'] ?? [];
        $sourcePage = trim($input['source_page'] ?? '');
        $pathLabel = $path === '3pl' ? '3PL / Fulfillment' : 'Self-Fulfill Ecommerce';

        $contact = Bean::dispense('crmcontact');
        $contact->companyEmail = $email;
        $contact->companyName = $domain ?: '';
        $contact->accountType = $path === '3pl' ? '3pl' : 'brand';
        $contact->execType = 'sales';
        $contact->statusCategory = 'prospect';
        $contact->pipelineStage = 'cold';
        $contact->isActive = 1;
        $contact->memberId = 1;

        if (!empty($answers['volume'])) {
            $contact->estimatedShipments = $answers['volume'];
        }
        if (!empty($answers['clients'])) {
            $contact->companySize = $answers['clients'] . ' clients';
        }

        $noteLines = ["Funnel lead ({$pathLabel})", "Source: {$sourcePage}"];
        if ($domain) $noteLines[] = "Website: {$domain}";
        if ($zip) $noteLines[] = "ZIP: {$zip}";
        foreach ($answers as $field => $value) {
            $noteLines[] = ucfirst($field) . ": {$value}";
        }
        $contact->notes = implode("\n", $noteLines);

        $tags = ['funnel-lead', $path];
        if (!empty($answers['pain'])) {
            $tags[] = strtolower(str_replace(' ', '-', $answers['pain']));
        }
        $contact->tags = implode(',', $tags);

        Bean::store($contact);

        // Log activity
        $activity = Bean::dispense('crmactivity');
        $activity->memberId = 1;
        $activity->crmcontactId = $contact->id;
        $activity->activityType = 'contact_created';
        $activity->description = "Auto-created from website funnel ({$pathLabel})";
        Bean::store($activity);

        Flight::jsonSuccess(['id' => $contact->id], 'Lead captured');
    }
}
