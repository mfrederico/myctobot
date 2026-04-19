<?php
/**
 * Admin Controller - Protected dashboard
 *
 * View and manage datastore contents.
 * Protected by API key authentication.
 */

namespace TenantApp\Controls;

use TenantApp\Control;
use TenantApp\Bean;

class Admin extends Control {

    private string $apiKey = '{{API_KEY}}';

    /**
     * Check authentication before any action
     */
    private function requireAuth(): bool {
        // Check for API key
        $providedKey = $this->getApiKey() ?? $this->getParam('api_key');

        if (empty($this->apiKey)) {
            return true; // No key configured
        }

        if ($providedKey !== $this->apiKey) {
            // Show login form
            $this->render('admin/login', [
                'title' => 'Admin Login',
            ]);
            return false;
        }

        return true;
    }

    /**
     * GET /admin - Dashboard with table list
     */
    public function index(): void {
        if (!$this->requireAuth()) return;

        $tables = Bean::getTables();
        $tableCounts = [];

        foreach ($tables as $table) {
            $tableCounts[$table] = Bean::count($table);
        }

        $this->render('admin/dashboard', [
            'title' => 'Admin Dashboard',
            'tables' => $tables,
            'tableCounts' => $tableCounts,
            'apiKey' => $this->getParam('api_key'),
        ]);
    }

    /**
     * GET /admin/table/{name} - View table data
     */
    public function table(): void {
        if (!$this->requireAuth()) return;

        $table = $this->opId();
        if (!$table) {
            $this->redirect('/admin?api_key=' . urlencode($this->getParam('api_key') ?? ''));
            return;
        }

        // Validate table exists
        $tables = Bean::getTables();
        if (!in_array($table, $tables)) {
            $this->jsonError('Table not found', 404);
            return;
        }

        // Get pagination params
        $page = max(1, (int) $this->getParam('page', 1));
        $limit = min(100, max(10, (int) $this->getParam('limit', 50)));
        $offset = ($page - 1) * $limit;

        // Get total count
        $total = Bean::count($table);

        // Get rows
        $rows = Bean::getAll(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        // Get columns
        $columns = !empty($rows) ? array_keys($rows[0]) : [];

        $this->render('admin/table', [
            'title' => "Table: {$table}",
            'table' => $table,
            'columns' => $columns,
            'rows' => $rows,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => ceil($total / $limit),
            'apiKey' => $this->getParam('api_key'),
        ]);
    }

    /**
     * GET /admin/record/{id} - View single record
     */
    public function record(): void {
        if (!$this->requireAuth()) return;

        $table = $this->getParam('table');
        $id = (int) $this->opId();

        if (!$table || !$id) {
            $this->jsonError('Table and ID required', 400);
            return;
        }

        $bean = Bean::load($table, $id);

        if (!$bean->id) {
            $this->jsonError('Record not found', 404);
            return;
        }

        if ($this->isAjax()) {
            $this->jsonSuccess($bean->export());
            return;
        }

        $this->render('admin/record', [
            'title' => "{$table} #{$id}",
            'table' => $table,
            'record' => $bean->export(),
            'apiKey' => $this->getParam('api_key'),
        ]);
    }

    /**
     * POST /admin/delete/{id} - Delete a record
     */
    public function delete(): void {
        if (!$this->requireAuth()) return;

        $table = $this->getParam('table');
        $id = (int) $this->opId();

        if (!$table || !$id) {
            $this->jsonError('Table and ID required', 400);
            return;
        }

        $bean = Bean::load($table, $id);

        if (!$bean->id) {
            $this->jsonError('Record not found', 404);
            return;
        }

        try {
            Bean::trash($bean);
            $this->jsonSuccess(null, 'Record deleted');
        } catch (\Exception $e) {
            $this->jsonError('Delete failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /admin/export/{table} - Export table as JSON
     */
    public function export(): void {
        if (!$this->requireAuth()) return;

        $table = $this->opId();

        if (!$table) {
            $this->jsonError('Table required', 400);
            return;
        }

        $rows = Bean::getAll("SELECT * FROM {$table} ORDER BY id DESC");

        $this->response->header('Content-Type', 'application/json');
        $this->response->header('Content-Disposition', "attachment; filename=\"{$table}.json\"");
        $this->response->end(json_encode($rows, JSON_PRETTY_PRINT));
    }
}
