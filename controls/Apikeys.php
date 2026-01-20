<?php
/**
 * API Keys Controller
 *
 * Manages API keys for programmatic access to the system.
 * Keys are scoped to specific permissions and run as a specific member.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\services\ApiAuthService;
use \Exception as Exception;

class Apikeys extends BaseControls\Control {

    /**
     * List all API keys (admin sees all, members see own)
     */
    public function index($params = []) {
        if (!$this->requireLogin()) return;

        $isAdmin = $this->member->level <= LEVELS['ADMIN'];

        if ($isAdmin) {
            // Admin sees all keys with member info
            $keys = Bean::findAll('apikeys', 'ORDER BY created_at DESC');
        } else {
            // Members see only keys that run as them
            $keys = Bean::find('apikeys', 'member_id = ? ORDER BY created_at DESC', [$this->member->id]);
        }

        $keyList = [];
        foreach ($keys as $key) {
            $keyList[] = $this->formatKey($key);
        }

        // Get available scopes for the form
        $availableScopes = ApiAuthService::getAvailableScopes();

        // Get members list for admin to assign keys
        $members = [];
        if ($isAdmin) {
            $allMembers = Bean::findAll('member', 'ORDER BY username ASC');
            foreach ($allMembers as $m) {
                $members[] = [
                    'id' => $m->id,
                    'username' => $m->username,
                    'email' => $m->email,
                    'level' => $m->level
                ];
            }
        }

        $this->viewData['title'] = 'API Keys';
        $this->viewData['keys'] = $keyList;
        $this->viewData['availableScopes'] = $availableScopes;
        $this->viewData['members'] = $members;
        $this->viewData['isAdmin'] = $isAdmin;
        $this->viewData['levels'] = LEVELS;

        $this->render('apikeys/index', $this->viewData);
    }

    /**
     * Create a new API key
     */
    public function store($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $isAdmin = $this->member->level <= LEVELS['ADMIN'];

        $input = $this->getParam('json') ? json_decode($this->getParam('json'), true) : $_POST;

        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $scopes = $input['scopes'] ?? [];
        $expiresIn = $input['expires_in'] ?? '';
        $memberId = $input['member_id'] ?? $this->member->id;

        // Validate name
        if (empty($name)) {
            Flight::jsonError('Key name is required', 400);
            return;
        }

        if (strlen($name) < 2 || strlen($name) > 100) {
            Flight::jsonError('Key name must be between 2 and 100 characters', 400);
            return;
        }

        // Non-admins can only create keys for themselves
        if (!$isAdmin && $memberId != $this->member->id) {
            Flight::jsonError('You can only create keys for yourself', 403);
            return;
        }

        // Validate member exists
        $targetMember = Bean::load('member', $memberId);
        if (!$targetMember || !$targetMember->id) {
            Flight::jsonError('Invalid member', 400);
            return;
        }

        // Non-admins cannot create keys with scopes beyond their permission level
        if (!$isAdmin) {
            // For now, restrict non-admins to basic scopes
            // TODO: Validate scopes against member's permission level
        }

        // Process scopes
        if (is_string($scopes)) {
            $scopes = array_filter(array_map('trim', explode(',', $scopes)));
        }
        if (empty($scopes)) {
            $scopes = []; // Empty scopes = no access
        }

        // Calculate expiration
        $expiresAt = null;
        switch ($expiresIn) {
            case '7d':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
                break;
            case '30d':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
                break;
            case '90d':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));
                break;
            case '1y':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
                break;
            case 'never':
            default:
                $expiresAt = null;
                break;
        }

        // Generate token
        $token = ApiAuthService::generateToken();

        // Create key
        $key = Bean::dispense('apikeys');
        $key->member_id = $memberId;
        $key->created_by_member_id = $this->member->id;
        $key->token = $token;
        $key->name = $name;
        $key->description = $description;
        $key->scopes_json = json_encode(array_values($scopes));
        $key->expires_at = $expiresAt;
        $key->is_active = 1;
        $key->usage_count = 0;
        $key->created_at = date('Y-m-d H:i:s');
        $key->updated_at = date('Y-m-d H:i:s');

        Bean::store($key);

        $this->logger->info('API key created', [
            'key_id' => $key->id,
            'key_name' => $name,
            'member_id' => $memberId,
            'created_by' => $this->member->id
        ]);

        // Return the token - this is the only time it will be shown
        Flight::jsonSuccess([
            'id' => $key->id,
            'name' => $key->name,
            'token' => $token, // Only returned on creation
            'expires_at' => $expiresAt
        ], 'API key created. Copy the token now - it will not be shown again.');
    }

    /**
     * Update an API key (not the token itself)
     */
    public function update($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $keyId = (int) ($this->opId() ?? $params[0] ?? 0);
        $key = Bean::load('apikeys', $keyId);

        if (!$key->id) {
            Flight::jsonError('API key not found', 404);
            return;
        }

        $isAdmin = $this->member->level <= LEVELS['ADMIN'];

        // Check permission
        if (!$isAdmin && $key->member_id != $this->member->id) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        $input = $this->getParam('json') ? json_decode($this->getParam('json'), true) : $_POST;

        // Update allowed fields
        if (isset($input['name'])) {
            $name = trim($input['name']);
            if (strlen($name) >= 2 && strlen($name) <= 100) {
                $key->name = $name;
            }
        }

        if (isset($input['description'])) {
            $key->description = trim($input['description']);
        }

        if (isset($input['scopes'])) {
            $scopes = $input['scopes'];
            if (is_string($scopes)) {
                $scopes = array_filter(array_map('trim', explode(',', $scopes)));
            }
            $key->scopes_json = json_encode(array_values($scopes));
        }

        if (isset($input['is_active'])) {
            $key->is_active = $input['is_active'] ? 1 : 0;
        }

        // Only admin can change member_id
        if ($isAdmin && isset($input['member_id'])) {
            $newMember = Bean::load('member', $input['member_id']);
            if ($newMember && $newMember->id) {
                $key->member_id = $newMember->id;
            }
        }

        $key->updated_at = date('Y-m-d H:i:s');
        Bean::store($key);

        $this->logger->info('API key updated', [
            'key_id' => $key->id,
            'updated_by' => $this->member->id
        ]);

        Flight::jsonSuccess($this->formatKey($key), 'API key updated');
    }

    /**
     * Delete an API key
     */
    public function delete($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $keyId = (int) ($this->opId() ?? $params[0] ?? 0);
        $key = Bean::load('apikeys', $keyId);

        if (!$key->id) {
            Flight::jsonError('API key not found', 404);
            return;
        }

        $isAdmin = $this->member->level <= LEVELS['ADMIN'];

        // Check permission - admin or key owner
        if (!$isAdmin && $key->created_by_member_id != $this->member->id) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        $this->logger->info('API key deleted', [
            'key_id' => $key->id,
            'key_name' => $key->name,
            'deleted_by' => $this->member->id
        ]);

        Bean::trash($key);

        Flight::jsonSuccess(null, 'API key deleted');
    }

    /**
     * Regenerate an API key token
     */
    public function regenerate($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $keyId = (int) ($this->opId() ?? $this->getParam('id') ?? 0);
        $key = Bean::load('apikeys', $keyId);

        if (!$key->id) {
            Flight::jsonError('API key not found', 404);
            return;
        }

        $isAdmin = $this->member->level <= LEVELS['ADMIN'];

        // Check permission
        if (!$isAdmin && $key->created_by_member_id != $this->member->id) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        // Generate new token
        $newToken = ApiAuthService::generateToken();
        $key->token = $newToken;
        $key->updated_at = date('Y-m-d H:i:s');
        Bean::store($key);

        $this->logger->info('API key regenerated', [
            'key_id' => $key->id,
            'regenerated_by' => $this->member->id
        ]);

        Flight::jsonSuccess([
            'token' => $newToken
        ], 'Token regenerated. Copy it now - it will not be shown again.');
    }

    /**
     * Toggle active status
     */
    public function toggle($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->validateCSRF()) {
            Flight::jsonError('Invalid CSRF token', 403);
            return;
        }

        $keyId = (int) ($this->opId() ?? $params[0] ?? 0);
        $key = Bean::load('apikeys', $keyId);

        if (!$key->id) {
            Flight::jsonError('API key not found', 404);
            return;
        }

        $isAdmin = $this->member->level <= LEVELS['ADMIN'];

        // Check permission
        if (!$isAdmin && $key->created_by_member_id != $this->member->id) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        $key->is_active = $key->is_active ? 0 : 1;
        $key->updated_at = date('Y-m-d H:i:s');
        Bean::store($key);

        $status = $key->is_active ? 'enabled' : 'disabled';
        Flight::jsonSuccess($this->formatKey($key), "API key {$status}");
    }

    /**
     * Get available scopes (for AJAX)
     */
    public function scopes($params = []) {
        if (!$this->requireLogin()) return;

        $scopes = ApiAuthService::getAvailableScopes();
        Flight::jsonSuccess($scopes);
    }

    /**
     * Format key bean for JSON response
     */
    private function formatKey($key): array {
        // Load member info
        $member = Bean::load('member', $key->member_id);
        $createdBy = Bean::load('member', $key->created_by_member_id);

        $scopes = json_decode($key->scopes_json ?: '[]', true);
        $isExpired = $key->expires_at && strtotime($key->expires_at) < time();

        return [
            'id' => $key->id,
            'name' => $key->name,
            'description' => $key->description,
            'token_preview' => substr($key->token, 0, 12) . '...',
            'scopes' => $scopes,
            'member_id' => $key->member_id,
            'member_username' => $member->username ?? 'Unknown',
            'member_email' => $member->email ?? '',
            'created_by_member_id' => $key->created_by_member_id,
            'created_by_username' => $createdBy->username ?? 'Unknown',
            'expires_at' => $key->expires_at,
            'is_expired' => $isExpired,
            'is_active' => (bool) $key->is_active,
            'last_used_at' => $key->last_used_at,
            'last_used_ip' => $key->last_used_ip,
            'usage_count' => (int) ($key->usage_count ?? 0),
            'created_at' => $key->created_at,
            'updated_at' => $key->updated_at
        ];
    }
}
