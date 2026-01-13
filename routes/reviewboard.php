<?php
/**
 * Review Board Routes
 *
 * Routes for story review and approval.
 */

use \Flight as Flight;

// Review Board UI
Flight::route('GET /reviewboard', ['\app\Reviewboard', 'index']);
Flight::route('GET /reviewboard/history', ['\app\Reviewboard', 'history']);
Flight::route('GET /reviewboard/project/@id', ['\app\Reviewboard', 'project']);

// AJAX endpoints
Flight::route('POST /reviewboard/updateStory', ['\app\Reviewboard', 'updateStory']);
Flight::route('POST /reviewboard/deleteStory', ['\app\Reviewboard', 'deleteStory']);
Flight::route('POST /reviewboard/approve', ['\app\Reviewboard', 'approveStories']);
Flight::route('POST /reviewboard/approveProject', ['\app\Reviewboard', 'approveProject']);
Flight::route('POST /reviewboard/deleteProject', ['\app\Reviewboard', 'deleteProject']);
Flight::route('POST /reviewboard/getJobDetails', ['\app\Reviewboard', 'getJobDetails']);

// Project editing AJAX endpoints
Flight::route('POST /reviewboard/getStory', ['\app\Reviewboard', 'getStory']);
Flight::route('POST /reviewboard/updateProject', ['\app\Reviewboard', 'updateProject']);
Flight::route('POST /reviewboard/createEpic', ['\app\Reviewboard', 'createEpic']);
Flight::route('POST /reviewboard/updateEpic', ['\app\Reviewboard', 'updateEpic']);
Flight::route('POST /reviewboard/createStory', ['\app\Reviewboard', 'createStory']);
Flight::route('POST /reviewboard/moveStory', ['\app\Reviewboard', 'moveStory']);

// Runner limit endpoints
Flight::route('POST /reviewboard/getRunnerStatus', ['\app\Reviewboard', 'getRunnerStatus']);
Flight::route('POST /reviewboard/updateRunnerLimit', ['\app\Reviewboard', 'updateRunnerLimit']);

// Stale job detection endpoints
Flight::route('POST /reviewboard/findStaleJobs', ['\app\Reviewboard', 'findStaleJobs']);
Flight::route('POST /reviewboard/cleanupStaleJobs', ['\app\Reviewboard', 'cleanupStaleJobs']);
Flight::route('POST /reviewboard/checkJobSession', ['\app\Reviewboard', 'checkJobSession']);
Flight::route('POST /reviewboard/markJobStale', ['\app\Reviewboard', 'markJobStale']);
Flight::route('POST /reviewboard/retryJob', ['\app\Reviewboard', 'retryJob']);
Flight::route('POST /reviewboard/startJob', ['\app\Reviewboard', 'startJob']);

// QA Release builder
Flight::route('POST /reviewboard/buildQARelease', ['\app\Reviewboard', 'buildQARelease']);
Flight::route('POST /reviewboard/qaReleaseStatus', ['\app\Reviewboard', 'qaReleaseStatus']);
Flight::route('POST /reviewboard/getBranches', ['\app\Reviewboard', 'getBranches']);
Flight::route('POST /reviewboard/abandonPR', ['\app\Reviewboard', 'abandonPR']);

// Batch operations
Flight::route('POST /reviewboard/batchApprove', ['\app\Reviewboard', 'batchApprove']);
Flight::route('POST /reviewboard/batchApproveAndRun', ['\app\Reviewboard', 'batchApproveAndRun']);
