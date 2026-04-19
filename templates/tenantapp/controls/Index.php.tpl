<?php
/**
 * Index Controller - Public endpoints
 *
 * Handles the main form and submission endpoint.
 */

namespace TenantApp\Controls;

use TenantApp\Control;
use TenantApp\Bean;

class Index extends Control {

    /**
     * GET / - Show the form
     */
    public function index(): void {
        // If JSON requested, return API info
        $accept = $this->request->header['accept'] ?? '';
        if (str_contains($accept, 'application/json')) {
            $this->jsonSuccess([
                'app' => '{{APP_SLUG}}',
                'name' => '{{APP_NAME}}',
                'endpoints' => [
                    'GET /' => 'Form UI or API info',
                    'POST /run' => 'Submit form data',
                    'GET /admin' => 'Admin dashboard (requires API key)',
                    'GET /health' => 'Health check',
                ],
            ]);
            return;
        }

        // Render the form view
        $this->render('index/form', [
            'title' => '{{APP_NAME}}',
            'description' => '{{APP_DESCRIPTION}}',
        ]);
    }

    /**
     * POST /run - Handle form submission
     */
    public function run(): void {
        if (!$this->isPost()) {
            $this->jsonError('POST required', 405);
            return;
        }

        $data = $this->getParams();

        // Add metadata
        $data['source_ip'] = $this->getClientIp();
        $data['user_agent'] = $this->request->header['user-agent'] ?? '';
        $data['submitted_at'] = date('Y-m-d H:i:s');

        // Store submission
        $bean = Bean::dispense('submissions');
        $bean->import($data);

        try {
            $id = Bean::store($bean);

            $this->jsonSuccess([
                'submission_id' => $id,
            ], 'Submission received successfully');

        } catch (\Exception $e) {
            $this->jsonError('Failed to save submission: ' . $e->getMessage(), 500);
        }
    }
}
