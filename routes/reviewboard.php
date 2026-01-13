<?php
/**
 * Review Board Routes
 *
 * Routes for story review and approval.
 */

use \Flight as Flight;

// Review Board UI
Flight::route('GET /reviewboard', ['\app\ReviewBoard', 'index']);
Flight::route('GET /reviewboard/history', ['\app\ReviewBoard', 'history']);
Flight::route('GET /reviewboard/project/@id', ['\app\ReviewBoard', 'project']);

// AJAX endpoints
Flight::route('POST /reviewboard/updateStory', ['\app\ReviewBoard', 'updateStory']);
Flight::route('POST /reviewboard/deleteStory', ['\app\ReviewBoard', 'deleteStory']);
Flight::route('POST /reviewboard/approve', ['\app\ReviewBoard', 'approveStories']);
Flight::route('POST /reviewboard/approveProject', ['\app\ReviewBoard', 'approveProject']);
Flight::route('POST /reviewboard/deleteProject', ['\app\ReviewBoard', 'deleteProject']);
Flight::route('POST /reviewboard/getJobDetails', ['\app\ReviewBoard', 'getJobDetails']);

// Project editing AJAX endpoints
Flight::route('POST /reviewboard/getStory', ['\app\ReviewBoard', 'getStory']);
Flight::route('POST /reviewboard/updateProject', ['\app\ReviewBoard', 'updateProject']);
Flight::route('POST /reviewboard/createEpic', ['\app\ReviewBoard', 'createEpic']);
Flight::route('POST /reviewboard/updateEpic', ['\app\ReviewBoard', 'updateEpic']);
Flight::route('POST /reviewboard/createStory', ['\app\ReviewBoard', 'createStory']);
Flight::route('POST /reviewboard/moveStory', ['\app\ReviewBoard', 'moveStory']);

// Runner limit endpoints
Flight::route('POST /reviewboard/getRunnerStatus', ['\app\ReviewBoard', 'getRunnerStatus']);
Flight::route('POST /reviewboard/updateRunnerLimit', ['\app\ReviewBoard', 'updateRunnerLimit']);

// Stale job detection endpoints
Flight::route('POST /reviewboard/findStaleJobs', ['\app\ReviewBoard', 'findStaleJobs']);
Flight::route('POST /reviewboard/cleanupStaleJobs', ['\app\ReviewBoard', 'cleanupStaleJobs']);
Flight::route('POST /reviewboard/checkJobSession', ['\app\ReviewBoard', 'checkJobSession']);
Flight::route('POST /reviewboard/markJobStale', ['\app\ReviewBoard', 'markJobStale']);
Flight::route('POST /reviewboard/retryJob', ['\app\ReviewBoard', 'retryJob']);
Flight::route('POST /reviewboard/startJob', ['\app\ReviewBoard', 'startJob']);

// QA Release builder
Flight::route('POST /reviewboard/buildQARelease', ['\app\ReviewBoard', 'buildQARelease']);
Flight::route('POST /reviewboard/qaReleaseStatus', ['\app\ReviewBoard', 'qaReleaseStatus']);
Flight::route('POST /reviewboard/getBranches', ['\app\ReviewBoard', 'getBranches']);
Flight::route('POST /reviewboard/abandonPR', ['\app\ReviewBoard', 'abandonPR']);

// Batch operations
Flight::route('POST /reviewboard/batchApprove', ['\app\ReviewBoard', 'batchApprove']);
Flight::route('POST /reviewboard/batchApproveAndRun', ['\app\ReviewBoard', 'batchApproveAndRun']);
