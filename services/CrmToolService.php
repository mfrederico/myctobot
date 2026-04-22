<?php
/**
 * CrmToolService
 *
 * Business logic for CRM MCP tools. Returns MCP content arrays.
 * Caller must have already switched to the correct workspace database.
 *
 * Member-scoped: admins see all contacts, others see only their own.
 */

namespace app\services;

use app\Bean;

require_once __DIR__ . '/CrmEnrichmentService.php';

class CrmToolService
{
    private string $workspace;
    private ?int $memberId;
    private $logger;
    private ?object $memberBean = null;

    public function __construct(string $workspace, ?int $memberId, $logger)
    {
        $this->workspace = $workspace;
        $this->memberId = $memberId;
        $this->logger = $logger;

        if ($memberId) {
            $this->memberBean = Bean::load('member', $memberId);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function isAdmin(): bool
    {
        return $this->memberBean && (int)$this->memberBean->level <= 50; // LEVELS['ADMIN']
    }

    /**
     * Build scoped SQL + params so non-admins only see their contacts
     */
    private function scopeSQL(string $extraSql = '', array $params = []): array
    {
        if ($this->isAdmin()) {
            $sql = '1=1';
        } else {
            $sql = 'member_id = ?';
            $params = array_merge([$this->memberId], $params);
        }
        if ($extraSql) {
            $sql .= ' AND ' . $extraSql;
        }
        return [$sql, $params];
    }

    private function canAccess(object $contact): bool
    {
        if (!$contact->id) return false;
        if ($this->isAdmin()) return true;
        return (int)$contact->member_id === $this->memberId;
    }

    private function success(mixed $data): array
    {
        return [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_PRETTY_PRINT)]],
            'isError' => false,
        ];
    }

    private function error(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => json_encode(['error' => $message])]],
            'isError' => true,
        ];
    }

    private function contactToArray(object $c): array
    {
        return [
            'id' => (int)$c->id,
            'first_name' => $c->first_name ?? '',
            'last_name' => $c->last_name ?? '',
            'company_name' => $c->company_name ?? '',
            'company_email' => $c->company_email ?? '',
            'phone' => $c->phone ?? '',
            'account_type' => $c->account_type ?? '',
            'status_category' => $c->status_category ?? '',
            'pipeline_stage' => $c->pipeline_stage ?? '',
            'customer_status' => $c->customer_status ?? '',
            'tags' => $c->tags ?? '',
            'notes' => $c->notes ?? '',
            'company_size' => $c->company_size ?? '',
            'estimated_shipments' => $c->estimated_shipments ?? '',
            'website_url' => $c->website_url ?? '',
            'linkedin_url' => $c->linkedin_url ?? '',
            'decision_maker_name' => $c->decision_maker_name ?? '',
            'decision_maker_title' => $c->decision_maker_title ?? '',
            'lead_score' => $c->lead_score ? (int)$c->lead_score : null,
            'enrichment_status' => $c->enrichment_status ?? '',
            'outreach_status' => $c->outreach_status ?? '',
            'outreach_step' => (int)($c->outreach_step ?? 0),
            'last_outreach_at' => $c->last_outreach_at ?? null,
            'last_touch_at' => $c->last_touch_at ?? null,
            'member_id' => (int)($c->member_id ?? 0),
            'created_at' => $c->created_at ?? '',
            'updated_at' => $c->updated_at ?? '',
        ];
    }

    // ─── Tools ───────────────────────────────────────────────────────────

    public function listContacts(array $args): array
    {
        $conditions = [];
        $params = [];

        if (!empty($args['stage'])) {
            $conditions[] = 'pipeline_stage = ?';
            $params[] = $args['stage'];
        }
        if (!empty($args['status'])) {
            $conditions[] = 'status_category = ?';
            $params[] = $args['status'];
        }
        if (!empty($args['tag'])) {
            $conditions[] = 'tags LIKE ?';
            $params[] = '%' . $args['tag'] . '%';
        }
        if (!empty($args['search'])) {
            $term = '%' . $args['search'] . '%';
            $conditions[] = '(CONCAT(COALESCE(first_name,""), " ", COALESCE(last_name,"")) LIKE ? OR company_name LIKE ? OR company_email LIKE ?)';
            $params = array_merge($params, [$term, $term, $term]);
        }

        $extra = implode(' AND ', $conditions) ?: '';
        [$sql, $sqlParams] = $this->scopeSQL($extra, $params);

        $limit = min((int)($args['limit'] ?? 50), 200);
        $contacts = Bean::find('crmcontact', "{$sql} ORDER BY updated_at DESC LIMIT {$limit}", $sqlParams);

        $result = [];
        foreach ($contacts as $c) {
            $result[] = $this->contactToArray($c);
        }

        return $this->success(['contacts' => $result, 'count' => count($result)]);
    }

    public function getContact(array $args): array
    {
        $id = (int)($args['contact_id'] ?? 0);
        if (!$id) return $this->error('contact_id is required');

        $contact = Bean::load('crmcontact', $id);
        if (!$this->canAccess($contact)) {
            return $this->error('Contact not found or access denied');
        }

        $data = $this->contactToArray($contact);

        // Recent notes
        $notes = $contact->with(' ORDER BY created_at DESC LIMIT 10 ')->ownCrmnoteList;
        $data['notes_list'] = [];
        foreach ($notes as $n) {
            $data['notes_list'][] = [
                'id' => (int)$n->id,
                'content' => $n->content ?? '',
                'member_id' => (int)($n->member_id ?? 0),
                'created_at' => $n->created_at ?? '',
            ];
        }

        // Recent touches
        $touches = $contact->with(' ORDER BY touch_date DESC LIMIT 10 ')->ownCrmtouchList;
        $data['touches'] = [];
        foreach ($touches as $t) {
            $data['touches'][] = [
                'id' => (int)$t->id,
                'touch_type' => $t->touch_type ?? '',
                'summary' => $t->summary ?? '',
                'outcome' => $t->outcome ?? '',
                'touch_date' => $t->touch_date ?? '',
                'source' => $t->source ?? '',
            ];
        }

        // Enrichment data if available
        if ($contact->enrichment_json) {
            $data['enrichment'] = json_decode($contact->enrichment_json, true);
        }

        return $this->success($data);
    }

    public function createContact(array $args): array
    {
        if (empty($args['company_name'])) {
            return $this->error('company_name is required');
        }

        $contact = Bean::dispense('crmcontact');
        $contact->companyName = $args['company_name'];
        $contact->companyEmail = $args['email'] ?? '';
        $contact->firstName = $args['first_name'] ?? '';
        $contact->lastName = $args['last_name'] ?? '';
        $contact->phone = $args['phone'] ?? '';
        $contact->accountType = $args['account_type'] ?? '3pl';
        $contact->execType = $args['exec_type'] ?? 'sales';
        $contact->statusCategory = $args['status_category'] ?? 'prospect';
        $contact->pipelineStage = $args['pipeline_stage'] ?? 'cold';
        $contact->tags = $args['tags'] ?? '';
        $contact->notes = $args['notes'] ?? '';
        $contact->companySize = $args['company_size'] ?? '';
        $contact->estimatedShipments = $args['estimated_shipments'] ?? '';
        $contact->websiteUrl = $args['website_url'] ?? '';
        $contact->linkedinUrl = $args['linkedin_url'] ?? '';
        $contact->isActive = 1;
        $contact->memberId = $this->memberId;
        $contact->outreachStatus = 'none';
        $contact->outreachStep = 0;

        Bean::store($contact);

        // Log activity
        $this->logActivity('contact_created', "MCP: Created contact: {$args['company_name']}", $contact->id);

        return $this->success(['id' => (int)$contact->id, 'message' => 'Contact created']);
    }

    public function updateContact(array $args): array
    {
        $id = (int)($args['contact_id'] ?? 0);
        if (!$id) return $this->error('contact_id is required');

        $contact = Bean::load('crmcontact', $id);
        if (!$this->canAccess($contact)) {
            return $this->error('Contact not found or access denied');
        }

        // Map of arg keys to bean properties (camelCase)
        $fieldMap = [
            'first_name' => 'firstName',
            'last_name' => 'lastName',
            'company_name' => 'companyName',
            'email' => 'companyEmail',
            'company_email' => 'companyEmail',
            'phone' => 'phone',
            'account_type' => 'accountType',
            'status_category' => 'statusCategory',
            'pipeline_stage' => 'pipelineStage',
            'customer_status' => 'customerStatus',
            'tags' => 'tags',
            'notes' => 'notes',
            'company_size' => 'companySize',
            'estimated_shipments' => 'estimatedShipments',
            'website_url' => 'websiteUrl',
            'linkedin_url' => 'linkedinUrl',
            'decision_maker_name' => 'decisionMakerName',
            'decision_maker_title' => 'decisionMakerTitle',
            'lead_score' => 'leadScore',
            'enrichment_status' => 'enrichmentStatus',
            'outreach_status' => 'outreachStatus',
        ];

        $updated = [];
        foreach ($fieldMap as $argKey => $prop) {
            if (array_key_exists($argKey, $args)) {
                $contact->$prop = $args[$argKey];
                $updated[] = $argKey;
            }
        }

        if (empty($updated)) {
            return $this->error('No fields to update');
        }

        Bean::store($contact);
        $this->logActivity('contact_updated', 'MCP: Updated fields: ' . implode(', ', $updated), $id);

        return $this->success(['id' => $id, 'updated_fields' => $updated]);
    }

    public function addNote(array $args): array
    {
        $contactId = (int)($args['contact_id'] ?? 0);
        $content = trim($args['content'] ?? '');

        if (!$contactId) return $this->error('contact_id is required');
        if (empty($content)) return $this->error('content is required');

        $contact = Bean::load('crmcontact', $contactId);
        if (!$this->canAccess($contact)) {
            return $this->error('Contact not found or access denied');
        }

        $note = Bean::dispense('crmnote');
        $note->crmcontactId = $contactId;
        $note->memberId = $this->memberId;
        $note->content = $content;
        Bean::store($note);

        $this->logActivity('note_added', 'MCP: Added note', $contactId);

        return $this->success(['id' => (int)$note->id, 'message' => 'Note added']);
    }

    public function logTouch(array $args): array
    {
        $contactId = (int)($args['contact_id'] ?? 0);
        if (!$contactId) return $this->error('contact_id is required');

        $contact = Bean::load('crmcontact', $contactId);
        if (!$this->canAccess($contact)) {
            return $this->error('Contact not found or access denied');
        }

        $touch = Bean::dispense('crmtouch');
        $touch->crmcontactId = $contactId;
        $touch->memberId = $this->memberId;
        $touch->touchType = $args['touch_type'] ?? 'manual';
        $touch->touchDate = date('Y-m-d H:i:s');
        $touch->summary = trim($args['summary'] ?? '');
        $touch->outcome = $args['outcome'] ?? '';
        $touch->source = $args['source'] ?? 'mcp';
        Bean::store($touch);

        // Update last touch on contact
        $contact->lastTouchAt = date('Y-m-d H:i:s');
        Bean::store($contact);

        $this->logActivity('touch_added', "MCP: Logged {$touch->touchType}", $contactId);

        return $this->success(['id' => (int)$touch->id, 'message' => 'Touch logged']);
    }

    public function moveStage(array $args): array
    {
        $contactId = (int)($args['contact_id'] ?? 0);
        $stage = $args['stage'] ?? '';
        $validStages = ['cold', 'warm', 'hot', 'closing'];

        if (!$contactId) return $this->error('contact_id is required');
        if (!in_array($stage, $validStages)) {
            return $this->error('Invalid stage. Must be one of: ' . implode(', ', $validStages));
        }

        $contact = Bean::load('crmcontact', $contactId);
        if (!$this->canAccess($contact)) {
            return $this->error('Contact not found or access denied');
        }

        $oldStage = $contact->pipelineStage;
        $contact->pipelineStage = $stage;
        Bean::store($contact);

        $this->logActivity('stage_changed', "MCP: Moved from {$oldStage} to {$stage}", $contactId);

        return $this->success([
            'id' => $contactId,
            'old_stage' => $oldStage,
            'new_stage' => $stage,
        ]);
    }

    public function searchContacts(array $args): array
    {
        $query = trim($args['query'] ?? '');
        if (empty($query)) return $this->error('query is required');

        $limit = min((int)($args['limit'] ?? 20), 100);
        $term = '%' . $query . '%';

        // Split multi-word queries so "Paul Picton" matches first_name="Paul" + last_name="Picton"
        $words = preg_split('/\s+/', $query);
        if (count($words) > 1) {
            // Match full name via CONCAT, plus each individual field
            $searchSql = '(CONCAT(COALESCE(first_name,""), " ", COALESCE(last_name,"")) LIKE ?' .
                         ' OR company_name LIKE ? OR company_email LIKE ? OR notes LIKE ? OR tags LIKE ?)';
            $searchParams = [$term, $term, $term, $term, $term];
        } else {
            $searchSql = '(first_name LIKE ? OR last_name LIKE ? OR company_name LIKE ? OR company_email LIKE ? OR notes LIKE ? OR tags LIKE ?)';
            $searchParams = [$term, $term, $term, $term, $term, $term];
        }

        [$sql, $sqlParams] = $this->scopeSQL($searchSql, $searchParams);
        $contacts = Bean::find('crmcontact', "{$sql} ORDER BY updated_at DESC LIMIT {$limit}", $sqlParams);

        $result = [];
        foreach ($contacts as $c) {
            $result[] = $this->contactToArray($c);
        }

        return $this->success(['contacts' => $result, 'count' => count($result), 'query' => $query]);
    }

    public function enrichContact(array $args): array
    {
        $contactId = (int)($args['contact_id'] ?? 0);
        if (!$contactId) return $this->error('contact_id is required');

        $contact = Bean::load('crmcontact', $contactId);
        if (!$this->canAccess($contact)) {
            return $this->error('Contact not found or access denied');
        }

        // Delegate to enrichment service
        require_once __DIR__ . '/CrmEnrichmentService.php';
        $configFile = __DIR__ . '/../conf/config.' . $this->workspace . '.ini';
        $config = file_exists($configFile) ? parse_ini_file($configFile, true) : [];

        $enrichSvc = new CrmEnrichmentService($config, $this->logger);
        $result = $enrichSvc->enrichContact($contactId);

        return $this->success($result);
    }

    public function getStats(array $args): array
    {
        $isAdmin = $this->isAdmin();
        $memberSql = $isAdmin ? '' : 'member_id = ? AND';
        $memberParams = $isAdmin ? [] : [$this->memberId];

        // Pipeline stage counts
        $stages = ['cold', 'warm', 'hot', 'closing'];
        $pipeline = [];
        foreach ($stages as $stage) {
            $pipeline[$stage] = Bean::count('crmcontact',
                "{$memberSql} pipeline_stage = ? AND status_category = 'prospect'",
                array_merge($memberParams, [$stage])
            );
        }

        // Customer count
        $customerCount = Bean::count('crmcontact',
            "{$memberSql} status_category = 'customer'",
            $memberParams
        );

        // Stale contacts (not touched in 7+ days)
        $weekAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
        $staleCount = Bean::count('crmcontact',
            "{$memberSql} (last_touch_at IS NULL OR last_touch_at < ?) AND status_category = 'customer'",
            array_merge($memberParams, [$weekAgo])
        );

        // Enrichment stats
        $enrichedCount = Bean::count('crmcontact',
            "{$memberSql} enrichment_status = 'enriched'",
            $memberParams
        );
        $pendingEnrichment = Bean::count('crmcontact',
            "{$memberSql} (enrichment_status IS NULL OR enrichment_status = '' OR enrichment_status = 'pending')" .
            " AND status_category = 'prospect'",
            $memberParams
        );

        // Outreach stats
        $inSequence = Bean::count('crmcontact',
            "{$memberSql} outreach_status = 'in_sequence'",
            $memberParams
        );

        // Total contacts
        $totalContacts = Bean::count('crmcontact',
            $isAdmin ? '1=1' : 'member_id = ?',
            $memberParams
        );

        return $this->success([
            'total_contacts' => $totalContacts,
            'pipeline' => $pipeline,
            'customer_count' => $customerCount,
            'stale_count' => $staleCount,
            'enriched_count' => $enrichedCount,
            'pending_enrichment' => $pendingEnrichment,
            'in_outreach_sequence' => $inSequence,
            'lead_score_rubric' => CrmEnrichmentService::SCORE_RUBRIC,
        ]);
    }

    // ─── Activity logging ────────────────────────────────────────────────

    private function logActivity(string $type, string $description, int $contactId = 0): void
    {
        $activity = Bean::dispense('crmactivity');
        $activity->memberId = $this->memberId;
        $activity->crmcontactId = $contactId;
        $activity->activityType = $type;
        $activity->description = $description;
        Bean::store($activity);
    }

    // ─── LinkedIn Tools ─────────────────────────────────────────────────

    public function linkedinSearchCompanies(array $args): array
    {
        require_once __DIR__ . '/LinkedInBrowserService.php';

        $keywords = $args['keywords'] ?? '';
        if (empty($keywords)) return $this->error('keywords is required');

        $linkedin = new LinkedInBrowserService($this->workspace, $this->logger);
        $session = $linkedin->isSessionValid();
        if (empty($session['valid'])) {
            return $this->error('LinkedIn session is not valid. Re-authenticate via VNC.');
        }

        $result = $linkedin->searchCompanies($keywords, [
            'country' => $args['country'] ?? 'US',
            'count' => min((int) ($args['count'] ?? 10), 10),
            'productOnly' => $args['product_only'] ?? true,
        ]);

        if (isset($result['error'])) return $this->error($result['error']);
        return $this->success($result);
    }

    public function linkedinSearchPeople(array $args): array
    {
        require_once __DIR__ . '/LinkedInBrowserService.php';

        $keywords = $args['keywords'] ?? '';
        if (empty($keywords)) return $this->error('keywords is required');

        $linkedin = new LinkedInBrowserService($this->workspace, $this->logger);
        $session = $linkedin->isSessionValid();
        if (empty($session['valid'])) {
            return $this->error('LinkedIn session is not valid. Re-authenticate via VNC.');
        }

        $result = $linkedin->searchPeople($keywords, [
            'country' => $args['country'] ?? 'US',
            'count' => min((int) ($args['count'] ?? 10), 10),
            'company' => $args['company'] ?? null,
        ]);

        if (isset($result['error'])) return $this->error($result['error']);
        return $this->success($result);
    }

    public function linkedinEnrichContact(array $args): array
    {
        require_once __DIR__ . '/LinkedInBrowserService.php';

        $contactId = (int) ($args['contact_id'] ?? 0);
        if (!$contactId) return $this->error('contact_id is required');

        $contact = Bean::load('crmcontact', $contactId);
        if (!$contact->id) return $this->error('Contact not found');

        if (empty($contact->companyName)) {
            return $this->error('Contact has no company name — cannot search LinkedIn');
        }

        $linkedin = new LinkedInBrowserService($this->workspace, $this->logger);
        $session = $linkedin->isSessionValid();
        if (empty($session['valid'])) {
            return $this->error('LinkedIn session is not valid. Re-authenticate via VNC.');
        }

        // Search for company
        $companyResult = $linkedin->enrichCompany($contact->companyName);

        if (empty($companyResult['found'])) {
            return $this->success([
                'enriched' => false,
                'contact_id' => $contactId,
                'reason' => $companyResult['reason'] ?? 'No LinkedIn match found',
            ]);
        }

        $match = $companyResult['match'];

        // Update contact
        $contact->linkedinUrl = $match['linkedinUrl'] ?? '';
        $existing = json_decode($contact->enrichmentJson ?: '{}', true);
        $existing['linkedin'] = [
            'company_name' => $match['name'] ?? '',
            'industry' => $match['industry'] ?? '',
            'location' => $match['location'] ?? '',
            'followers' => $match['followers'] ?? '',
            'summary' => $match['summary'] ?? '',
            'linkedin_url' => $match['linkedinUrl'] ?? '',
            'enriched_at' => date('Y-m-d H:i:s'),
        ];
        $contact->enrichmentJson = json_encode($existing);
        $contact->leadScore = min(100, (int) $contact->leadScore + 15);
        Bean::store($contact);

        // Log touch
        $touch = Bean::dispense('crmtouch');
        $touch->touchType = 'linkedin';
        $touch->summary = "LinkedIn enrichment: found '{$match['name']}' ({$match['followers']})";
        $touch->source = 'mcp';
        $touch->touchDate = date('Y-m-d H:i:s');
        $contact->ownCrmtouchList[] = $touch;
        Bean::store($contact);

        $this->logActivity('linkedin_enrich', "Enriched with LinkedIn: {$match['name']}", $contactId);

        return $this->success([
            'enriched' => true,
            'contact_id' => $contactId,
            'company' => $contact->companyName,
            'linkedin_match' => $match,
            'new_lead_score' => $contact->leadScore,
            'alternates' => $companyResult['alternates'] ?? [],
        ]);
    }

    // ─── Tool Definitions ────────────────────────────────────────────────

    public static function getToolDefinitions(): array
    {
        return [
            [
                'name' => 'crm_list_contacts',
                'description' => 'List CRM contacts with optional filters. Returns contacts sorted by last updated.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'stage' => ['type' => 'string', 'enum' => ['cold', 'warm', 'hot', 'closing'], 'description' => 'Filter by pipeline stage'],
                        'status' => ['type' => 'string', 'enum' => ['prospect', 'customer'], 'description' => 'Filter by status category'],
                        'tag' => ['type' => 'string', 'description' => 'Filter by tag (partial match)'],
                        'search' => ['type' => 'string', 'description' => 'Search in name, company, email'],
                        'limit' => ['type' => 'integer', 'description' => 'Max contacts to return (default 50, max 200)'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'crm_get_contact',
                'description' => 'Get full contact details including recent notes, touches, and enrichment data.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'contact_id' => ['type' => 'integer', 'description' => 'The contact ID'],
                    ],
                    'required' => ['contact_id'],
                ],
            ],
            [
                'name' => 'crm_create_contact',
                'description' => 'Create a new CRM contact. Requires company_name at minimum.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'company_name' => ['type' => 'string', 'description' => 'Company name (required)'],
                        'email' => ['type' => 'string', 'description' => 'Company email'],
                        'first_name' => ['type' => 'string', 'description' => 'Contact first name'],
                        'last_name' => ['type' => 'string', 'description' => 'Contact last name'],
                        'phone' => ['type' => 'string', 'description' => 'Phone number'],
                        'account_type' => ['type' => 'string', 'enum' => ['3pl', 'brand'], 'description' => 'Account type (default: 3pl)'],
                        'pipeline_stage' => ['type' => 'string', 'enum' => ['cold', 'warm', 'hot', 'closing'], 'description' => 'Pipeline stage (default: cold)'],
                        'tags' => ['type' => 'string', 'description' => 'Comma-separated tags'],
                        'notes' => ['type' => 'string', 'description' => 'Initial notes'],
                        'website_url' => ['type' => 'string', 'description' => 'Company website URL'],
                    ],
                    'required' => ['company_name'],
                ],
            ],
            [
                'name' => 'crm_update_contact',
                'description' => 'Update fields on an existing CRM contact.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'contact_id' => ['type' => 'integer', 'description' => 'Contact ID to update'],
                        'first_name' => ['type' => 'string', 'description' => 'First name'],
                        'last_name' => ['type' => 'string', 'description' => 'Last name'],
                        'company_name' => ['type' => 'string', 'description' => 'Company name'],
                        'email' => ['type' => 'string', 'description' => 'Email'],
                        'phone' => ['type' => 'string', 'description' => 'Phone'],
                        'tags' => ['type' => 'string', 'description' => 'Comma-separated tags'],
                        'notes' => ['type' => 'string', 'description' => 'Notes'],
                        'website_url' => ['type' => 'string', 'description' => 'Website URL'],
                        'linkedin_url' => ['type' => 'string', 'description' => 'LinkedIn URL'],
                        'decision_maker_name' => ['type' => 'string', 'description' => 'Decision maker name'],
                        'decision_maker_title' => ['type' => 'string', 'description' => 'Decision maker title'],
                        'lead_score' => ['type' => 'integer', 'description' => 'Lead score (1-100)'],
                    ],
                    'required' => ['contact_id'],
                ],
            ],
            [
                'name' => 'crm_add_note',
                'description' => 'Add a note to a CRM contact.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'contact_id' => ['type' => 'integer', 'description' => 'Contact ID'],
                        'content' => ['type' => 'string', 'description' => 'Note content'],
                    ],
                    'required' => ['contact_id', 'content'],
                ],
            ],
            [
                'name' => 'crm_log_touch',
                'description' => 'Log a touchpoint (call, email, meeting, etc.) with a contact.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'contact_id' => ['type' => 'integer', 'description' => 'Contact ID'],
                        'touch_type' => ['type' => 'string', 'enum' => ['call', 'email', 'meeting', 'demo', 'follow_up', 'manual'], 'description' => 'Type of touch'],
                        'summary' => ['type' => 'string', 'description' => 'Summary of the interaction'],
                        'outcome' => ['type' => 'string', 'description' => 'Outcome (e.g., interested, callback, no_answer)'],
                    ],
                    'required' => ['contact_id', 'touch_type', 'summary'],
                ],
            ],
            [
                'name' => 'crm_move_stage',
                'description' => 'Move a contact to a different pipeline stage.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'contact_id' => ['type' => 'integer', 'description' => 'Contact ID'],
                        'stage' => ['type' => 'string', 'enum' => ['cold', 'warm', 'hot', 'closing'], 'description' => 'Target pipeline stage'],
                    ],
                    'required' => ['contact_id', 'stage'],
                ],
            ],
            [
                'name' => 'crm_search',
                'description' => 'Full-text search across contacts (name, company, email, notes, tags).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Search query'],
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 20, max 100)'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'crm_enrich_contact',
                'description' => 'Trigger AI-powered enrichment for a contact. Searches the web for company info, decision-maker details, email, and calculates a lead score.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'contact_id' => ['type' => 'integer', 'description' => 'Contact ID to enrich'],
                    ],
                    'required' => ['contact_id'],
                ],
            ],
            [
                'name' => 'crm_get_stats',
                'description' => 'Get CRM dashboard stats: pipeline counts, customer count, stale contacts, enrichment status.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object)[],
                    'required' => [],
                ],
            ],
            [
                'name' => 'linkedin_search_companies',
                'description' => 'Search LinkedIn for companies. Returns structured data: name, industry, location, followers, summary, LinkedIn URL. Filters to product companies by default (excludes agencies/consultants).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'keywords' => ['type' => 'string', 'description' => 'Search keywords (e.g., "DTC skincare brand", "organic pet food")'],
                        'country' => ['type' => 'string', 'description' => 'Country code: US, UK, CA, AU, DE', 'default' => 'US'],
                        'product_only' => ['type' => 'boolean', 'description' => 'Filter to product companies only (exclude agencies)', 'default' => true],
                        'count' => ['type' => 'integer', 'description' => 'Max results (1-10)', 'default' => 10],
                    ],
                    'required' => ['keywords'],
                ],
            ],
            [
                'name' => 'linkedin_search_people',
                'description' => 'Search LinkedIn for people. Returns name, headline, location, summary, LinkedIn URL.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'keywords' => ['type' => 'string', 'description' => 'Search keywords (name, title, etc.)'],
                        'company' => ['type' => 'string', 'description' => 'Company name to narrow search'],
                        'country' => ['type' => 'string', 'description' => 'Country code: US, UK, CA, AU, DE', 'default' => 'US'],
                        'count' => ['type' => 'integer', 'description' => 'Max results (1-10)', 'default' => 10],
                    ],
                    'required' => ['keywords'],
                ],
            ],
            [
                'name' => 'linkedin_enrich_contact',
                'description' => 'Enrich a CRM contact with LinkedIn data. Searches for their company on LinkedIn, updates linkedin_url, enrichment_json, and boosts lead_score.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'contact_id' => ['type' => 'integer', 'description' => 'CRM contact ID to enrich'],
                    ],
                    'required' => ['contact_id'],
                ],
            ],
        ];
    }
}
