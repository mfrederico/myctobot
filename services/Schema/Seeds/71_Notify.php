<?php
/**
 * 71_Notify — Outbound/inbound notification audit trail.
 *
 * Every email sent via NotifyService and every inbound email that lands on
 * the CRM comms webhook writes a row here. Not a queue — an after-the-fact
 * log.
 *
 * Defensive: the `notify` table may pre-exist with narrower column types
 * (message_id as INT is a common RedBean guess). ALTER first, then create.
 */

// Upsize any pre-existing columns RedBean may have sized too narrowly.
// No-op if the table doesn't exist yet (next block creates it).
$_notifyAlters = [
    'ALTER TABLE `notify` MODIFY COLUMN `message_id`      VARCHAR(200) NULL',
    'ALTER TABLE `notify` MODIFY COLUMN `in_reply_to`     VARCHAR(200) NULL',
    'ALTER TABLE `notify` MODIFY COLUMN `references_list` TEXT NULL',
    'ALTER TABLE `notify` MODIFY COLUMN `direction`       VARCHAR(8)   NULL',
    'ALTER TABLE `notify` MODIFY COLUMN `from_name`       VARCHAR(150) NULL',
    'ALTER TABLE `notify` MODIFY COLUMN `subject`         VARCHAR(255) NULL',
    'ALTER TABLE `notify` MODIFY COLUMN `related_eid`     INT UNSIGNED NULL',
    'ALTER TABLE `notify` MODIFY COLUMN `content`         TEXT NULL',
    'ALTER TABLE `notify` MODIFY COLUMN `body_plain`      TEXT NULL',
];
foreach ($_notifyAlters as $_sql) {
    try { \RedBeanPHP\R::exec($_sql); } catch (\Exception $e) { /* table / column not yet created */ }
}

if (!$_tableCheck('notify')) {
    // Reference the emailthread scaffold from 70_EmailThread if available,
    // so thread_id gets a valid FK target on first insert.
    $threadRef = $_scaffold['emailthread_seed'] ?? \RedBeanPHP\R::findOne('emailthread', ' 1 ORDER BY id LIMIT 1');
    $threadId  = $threadRef && !empty($threadRef->id) ? (int)$threadRef->id : 0;

    $notify = \RedBeanPHP\R::dispense('notify');
    $notify->from_email      = 'schema@placeholder.local';
    $notify->to_email        = 'schema@placeholder.local';
    $notify->subject         = str_repeat('x', 255);
    $notify->notify_type     = 'email';
    $notify->content         = str_repeat('x', 4000);
    $notify->body_plain      = str_repeat('x', 4000);
    $notify->status          = 'seed';
    $notify->error_message   = str_repeat('x', 500);
    $notify->ip              = '255.255.255.255';
    $notify->related_type    = str_repeat('x', 50);
    $notify->related_eid     = 1000;       // force INT
    $notify->direction       = 'out';
    $notify->thread_id       = $threadId;
    $notify->message_id      = '<seed-' . str_repeat('x', 180) . '>';
    $notify->in_reply_to     = '<seed-' . str_repeat('x', 180) . '>';
    $notify->references_list = str_repeat('x', 2000);
    $notify->from_name       = str_repeat('x', 150);
    $notify->sent_at         = date('Y-m-d H:i:s');
    $notify->created_at      = date('Y-m-d H:i:s');
    \RedBeanPHP\R::store($notify);
    $_defer($notify);

    $_scaffold['notify_seed'] = $notify;
} else {
    $existing = \RedBeanPHP\R::findOne('notify', ' 1 ORDER BY id LIMIT 1');
    if ($existing) $_scaffold['notify_seed'] = $existing;
}

foreach ([
    'CREATE INDEX `idx_notify_status`     ON `notify` (`status`)',
    'CREATE INDEX `idx_notify_created`    ON `notify` (`created_at`)',
    'CREATE INDEX `idx_notify_related`    ON `notify` (`related_type`, `related_eid`)',
    'CREATE INDEX `idx_notify_to_email`   ON `notify` (`to_email`)',
    'CREATE INDEX `idx_notify_thread`     ON `notify` (`thread_id`)',
    'CREATE INDEX `idx_notify_direction`  ON `notify` (`direction`)',
    'CREATE INDEX `idx_notify_message_id` ON `notify` (`message_id`)',
] as $_sql) {
    try { \RedBeanPHP\R::exec($_sql); } catch (\Exception $e) { /* exists */ }
}
