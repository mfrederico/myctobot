<?php
/**
 * Code Review Routes
 */

use \Flight as Flight;

Flight::route('GET /codereview', ['\app\CodeReview', 'index']);
Flight::route('POST /codereview/run', ['\app\CodeReview', 'run']);
Flight::route('GET /codereview/patterns', ['\app\CodeReview', 'patterns']);
Flight::route('POST /codereview/patterns', ['\app\CodeReview', 'updatePatterns']);
Flight::route('POST /codereview/report', ['\app\CodeReview', 'report']);
