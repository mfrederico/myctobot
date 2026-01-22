<?php
$board = \RedBeanPHP\R::dispense('boards');
$board->member = $member;
$board->cloud_uid = 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx';
$board->board_uid = 1;
$board->board_name = 'Schema Template Board';
$board->board_type = 'scrum';
$board->project_key = 'SCHEMA';
$board->project_name = 'Schema Template Project';
$board->is_active = true;
$board->is_shared = false;
$board->digest_enabled = true;
$board->digest_frequency = 'daily';
$board->digest_time = '09:00';
$board->digest_day = 1;
$board->last_digest_at = date('Y-m-d H:i:s');
$board->created_at = date('Y-m-d H:i:s');
$board->updated_at = date('Y-m-d H:i:s');
\RedBeanPHP\R::store($board);
