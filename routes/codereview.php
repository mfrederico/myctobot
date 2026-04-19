<?php
/**
 * Code Review Routes
 */

use \Flight as Flight;

Flight::route('GET /codereview', ['\app\Codereview', 'index']);
Flight::route('POST /codereview/run', ['\app\Codereview', 'run']);
Flight::route('GET /codereview/patterns', ['\app\Codereview', 'patterns']);
Flight::route('POST /codereview/patterns', ['\app\Codereview', 'updatepatterns']);
Flight::route('POST /codereview/report', ['\app\Codereview', 'report']);
