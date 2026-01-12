<?php
/**
 * Review Board Routes
 *
 * Routes for story review and approval.
 */

use \Flight as Flight;

// Review Board UI
Flight::route('GET /reviewboard', ['\app\ReviewBoard', 'index']);
Flight::route('GET /reviewboard/project/@id', ['\app\ReviewBoard', 'project']);

// AJAX endpoints
Flight::route('POST /reviewboard/update-story', ['\app\ReviewBoard', 'updateStory']);
Flight::route('POST /reviewboard/delete-story', ['\app\ReviewBoard', 'deleteStory']);
Flight::route('POST /reviewboard/approve', ['\app\ReviewBoard', 'approveStories']);
Flight::route('POST /reviewboard/approve-project', ['\app\ReviewBoard', 'approveProject']);
Flight::route('POST /reviewboard/delete-project', ['\app\ReviewBoard', 'deleteProject']);
