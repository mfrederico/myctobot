<?php
/**
 * Auth routes
 *
 * Uses default routing pattern:
 *   /auth/invite/{token} → Auth->invite() (GET shows form, POST processes)
 *   /auth/login          → Auth->login()
 *   /auth/logout         → Auth->logout()
 *   etc.
 */

// Load default routes - handles /auth/* via controller mapping
require_once __DIR__ . '/default.php';
