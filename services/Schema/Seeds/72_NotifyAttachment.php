<?php
/**
 * 72_NotifyAttachment — Files attached to a notify message (in or out).
 *
 * Each row = one file, tied to a notify row + its thread. Created by:
 *   - Communications::reply when a rep attaches files to an outbound reply.
 *   - CrmCommsInboundService when a contact replies with attachments.
 *
 * Files live on disk under public/uploads/{in|out}bound-mail/{thread_id}/.
 * `file_path` is web-accessible (relative to /public).
 *
 * FK gotcha: RedBeanPHP in fluid mode auto-creates FK constraints on any
 * column ending in `_id`. Scaffold row points at the thread + notify seeds
 * registered by 70_EmailThread + 71_Notify (via $_scaffold) so the FK
 * targets actually exist at INSERT time. $_defer keeps parents alive until
 * 90_Cleanup drains in reverse order.
 */

if (!$_tableCheck('notifyattachment')) {
    // Pull parents from $_scaffold; fall back to whatever's in the DB.
    $threadRef = $_scaffold['emailthread_seed'] ?? \RedBeanPHP\R::findOne('emailthread', ' 1 ORDER BY id LIMIT 1');
    $notifyRef = $_scaffold['notify_seed']      ?? \RedBeanPHP\R::findOne('notify',      ' 1 ORDER BY id LIMIT 1');
    $threadId  = $threadRef && !empty($threadRef->id) ? (int)$threadRef->id : 0;
    $notifyId  = $notifyRef && !empty($notifyRef->id) ? (int)$notifyRef->id : 0;

    $att = \RedBeanPHP\R::dispense('notifyattachment');
    $att->notify_id  = $notifyId;
    $att->thread_id  = $threadId;
    $att->filename   = str_repeat('x', 255);
    $att->file_path  = str_repeat('x', 500);
    $att->mime_type  = str_repeat('x', 100);
    $att->size_bytes = 99999999;    // force INT (not TINYINT)
    $att->created_at = date('Y-m-d H:i:s');
    \RedBeanPHP\R::store($att);
    $_defer($att);

    try { \RedBeanPHP\R::exec('ALTER TABLE `notifyattachment` MODIFY COLUMN `filename`   VARCHAR(255) NULL'); } catch (\Exception $e) {}
    try { \RedBeanPHP\R::exec('ALTER TABLE `notifyattachment` MODIFY COLUMN `file_path`  VARCHAR(500) NULL'); } catch (\Exception $e) {}
    try { \RedBeanPHP\R::exec('ALTER TABLE `notifyattachment` MODIFY COLUMN `mime_type`  VARCHAR(100) NULL'); } catch (\Exception $e) {}
    try { \RedBeanPHP\R::exec('ALTER TABLE `notifyattachment` MODIFY COLUMN `size_bytes` INT UNSIGNED NULL'); } catch (\Exception $e) {}

    try { \RedBeanPHP\R::exec('CREATE INDEX `idx_notifyattachment_notify` ON `notifyattachment` (`notify_id`)'); } catch (\Exception $e) {}
    try { \RedBeanPHP\R::exec('CREATE INDEX `idx_notifyattachment_thread` ON `notifyattachment` (`thread_id`)'); } catch (\Exception $e) {}
}
