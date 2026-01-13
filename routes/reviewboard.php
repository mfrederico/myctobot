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
Flight::route('POST /reviewboard/updateStory', ['\app\Reviewboard', 'updatestory']);
Flight::route('POST /reviewboard/deleteStory', ['\app\Reviewboard', 'deletestory']);
Flight::route('POST /reviewboard/approve', ['\app\Reviewboard', 'approvestories']);
Flight::route('POST /reviewboard/approveProject', ['\app\Reviewboard', 'approveproject']);
Flight::route('POST /reviewboard/deleteProject', ['\app\Reviewboard', 'deleteproject']);
Flight::route('POST /reviewboard/getJobDetails', ['\app\Reviewboard', 'getjobdetails']);

// Project editing AJAX endpoints
Flight::route('POST /reviewboard/getStory', ['\app\Reviewboard', 'getstory']);
Flight::route('POST /reviewboard/updateProject', ['\app\Reviewboard', 'updateproject']);
Flight::route('POST /reviewboard/createEpic', ['\app\Reviewboard', 'createepic']);
Flight::route('POST /reviewboard/updateEpic', ['\app\Reviewboard', 'updateepic']);
Flight::route('POST /reviewboard/createStory', ['\app\Reviewboard', 'createstory']);
Flight::route('POST /reviewboard/moveStory', ['\app\Reviewboard', 'movestory']);

// Runner limit endpoints
Flight::route('POST /reviewboard/getRunnerStatus', ['\app\Reviewboard', 'getrunnerstatus']);
Flight::route('POST /reviewboard/updateRunnerLimit', ['\app\Reviewboard', 'updaterunnerlimit']);

// Stale job detection endpoints
Flight::route('POST /reviewboard/findStaleJobs', ['\app\Reviewboard', 'findstalejobs']);
Flight::route('POST /reviewboard/cleanupStaleJobs', ['\app\Reviewboard', 'cleanupstalejobs']);
Flight::route('POST /reviewboard/checkJobSession', ['\app\Reviewboard', 'checkjobsession']);
Flight::route('POST /reviewboard/markJobStale', ['\app\Reviewboard', 'markjobstale']);
Flight::route('POST /reviewboard/retryJob', ['\app\Reviewboard', 'retryjob']);
Flight::route('POST /reviewboard/startJob', ['\app\Reviewboard', 'startjob']);

// QA Release builder
Flight::route('POST /reviewboard/buildQARelease', ['\app\Reviewboard', 'buildqarelease']);
Flight::route('POST /reviewboard/qaReleaseStatus', ['\app\Reviewboard', 'qareleasestatus']);
Flight::route('POST /reviewboard/getBranches', ['\app\Reviewboard', 'getbranches']);
Flight::route('POST /reviewboard/abandonPR', ['\app\Reviewboard', 'abandonpr']);

// Batch operations
Flight::route('POST /reviewboard/batchApprove', ['\app\Reviewboard', 'batchapprove']);
Flight::route('POST /reviewboard/batchApproveAndRun', ['\app\Reviewboard', 'batchapproveandrun']);
