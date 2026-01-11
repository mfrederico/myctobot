<?php
/**
 * Knowledge Base Routes
 *
 * Maps hyphenated URLs to KnowledgeBase controller
 */

use \Flight as Flight;

// Main index
Flight::route('GET /knowledge-base', function() {
    $controller = new \app\KnowledgeBase();
    $controller->index();
});

// Upload document
Flight::route('POST /knowledge-base/upload', function() {
    $controller = new \app\KnowledgeBase();
    $controller->upload();
});

// Upload from URL
Flight::route('POST /knowledge-base/upload-url', function() {
    $controller = new \app\KnowledgeBase();
    $controller->uploadUrl();
});

// Delete document
Flight::route('POST /knowledge-base/delete', function() {
    $controller = new \app\KnowledgeBase();
    $controller->delete();
});

// Get document status
Flight::route('GET /knowledge-base/status/@id', function($id) {
    $controller = new \app\KnowledgeBase();
    $controller->status($id);
});

// Poll async job status
Flight::route('GET /knowledge-base/poll-job/@jobId', function($jobId) {
    $controller = new \app\KnowledgeBase();
    $controller->pollJob($jobId);
});

// Chat interface
Flight::route('GET /knowledge-base/chat', function() {
    $controller = new \app\KnowledgeBase();
    $controller->chat();
});

// Create knowledge base
Flight::route('POST /knowledge-base/create-kb', function() {
    $controller = new \app\KnowledgeBase();
    $controller->createKb();
});

// Delete knowledge base
Flight::route('POST /knowledge-base/delete-kb', function() {
    $controller = new \app\KnowledgeBase();
    $controller->deleteKb();
});

// Update knowledge base (agent assignment, etc.)
Flight::route('POST /knowledge-base/update-kb', function() {
    $controller = new \app\KnowledgeBase();
    $controller->updateKb();
});

// RAG Query interface
Flight::route('GET /knowledge-base/query', function() {
    $controller = new \app\KnowledgeBase();
    $controller->queryInterface();
});

// Execute RAG query
Flight::route('POST /knowledge-base/query', function() {
    $controller = new \app\KnowledgeBase();
    $controller->executeQuery();
});
