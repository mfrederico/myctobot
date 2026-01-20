<?php
/**
 * API Keys routes
 */

use \app\Apikeys;

// List API keys
Flight::route('GET /apikeys', [Apikeys::class, 'index']);

// Create new API key
Flight::route('POST /apikeys', [Apikeys::class, 'store']);
Flight::route('POST /apikeys/store', [Apikeys::class, 'store']);

// Update API key
Flight::route('POST /apikeys/update/@id', [Apikeys::class, 'update']);
Flight::route('PUT /apikeys/@id', [Apikeys::class, 'update']);

// Delete API key
Flight::route('POST /apikeys/delete/@id', [Apikeys::class, 'delete']);
Flight::route('DELETE /apikeys/@id', [Apikeys::class, 'delete']);

// Regenerate API key token
Flight::route('POST /apikeys/regenerate/@id', [Apikeys::class, 'regenerate']);

// Toggle API key active status
Flight::route('POST /apikeys/toggle/@id', [Apikeys::class, 'toggle']);

// Get available scopes (AJAX)
Flight::route('GET /apikeys/scopes', [Apikeys::class, 'scopes']);
