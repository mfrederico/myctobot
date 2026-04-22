<?php
/**
 * 70_EmailThread — Conversation threading between a sales rep and a CRM contact.
 *
 * One row per (contact, rep, subject-ish) conversation. The `reply_token` is
 * what powers the inbound webhook: emails arriving at
 *   reply-{workspace-slug}-{token}@<mailgun-domain>
 * route back to this thread. Slug is required because Mailgun hits a single
 * webhook URL regardless of tenant — the inbound handler needs the slug in
 * the address to know which workspace DB to consult.
 *
 * Scoped by (crmcontact, member) rather than dealer/distributor since
 * myctobot's tenancy is per-DB only (no distributor inside a workspace).
 */

if (!$_tableCheck('emailthread')) {
    $thread = \RedBeanPHP\R::dispense('emailthread');
    $thread->crmcontact_id   = 0;
    $thread->member_id       = 0;
    $thread->subject         = str_repeat('x', 255);
    $thread->reply_token     = str_repeat('x', 64);
    $thread->last_message_at = date('Y-m-d H:i:s');
    $thread->last_direction  = 'out';
    $thread->last_preview    = str_repeat('x', 500);
    $thread->message_count   = 1000;      // force INT (not TINYINT) by using >= 256
    $thread->unread_count    = 1000;
    $thread->is_archived     = 0;
    $thread->created_at      = date('Y-m-d H:i:s');
    $thread->updated_at      = date('Y-m-d H:i:s');
    \RedBeanPHP\R::store($thread);
    $_defer($thread);

    try { \RedBeanPHP\R::exec('ALTER TABLE `emailthread` MODIFY COLUMN `subject` VARCHAR(255) NULL'); } catch (\Exception $e) {}
    try { \RedBeanPHP\R::exec('ALTER TABLE `emailthread` MODIFY COLUMN `reply_token` VARCHAR(64) NOT NULL'); } catch (\Exception $e) {}
    try { \RedBeanPHP\R::exec('ALTER TABLE `emailthread` MODIFY COLUMN `last_preview` VARCHAR(500) NULL'); } catch (\Exception $e) {}
    try { \RedBeanPHP\R::exec('ALTER TABLE `emailthread` MODIFY COLUMN `last_direction` VARCHAR(8) NULL'); } catch (\Exception $e) {}

    try { \RedBeanPHP\R::exec('CREATE UNIQUE INDEX `uk_emailthread_token` ON `emailthread` (`reply_token`)'); } catch (\Exception $e) {}
    try { \RedBeanPHP\R::exec('CREATE INDEX `idx_emailthread_contact`  ON `emailthread` (`crmcontact_id`)'); }  catch (\Exception $e) {}
    try { \RedBeanPHP\R::exec('CREATE INDEX `idx_emailthread_member`   ON `emailthread` (`member_id`)'); }     catch (\Exception $e) {}
    try { \RedBeanPHP\R::exec('CREATE INDEX `idx_emailthread_last_msg` ON `emailthread` (`last_message_at`)'); } catch (\Exception $e) {}

    // Expose in $_scaffold so sibling seeds (71_Notify, 72_NotifyAttachment)
    // can reference this thread's id without re-querying.
    $_scaffold['emailthread_seed'] = $thread;
} else {
    // Reseed path: keep a handle so later seeds have something to reference.
    $seed = \RedBeanPHP\R::findOne('emailthread', ' 1 ORDER BY id LIMIT 1');
    if ($seed) $_scaffold['emailthread_seed'] = $seed;
}
