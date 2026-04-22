<?php
/**
 * CrmCommsInboundService — Handle inbound Mailgun webhooks for CRM comms.
 *
 * Recipient pattern: reply-{workspace-slug}-{token}@<mailgun-domain>
 *
 * Flow:
 *   1. Parse slug + token from recipient local-part.
 *   2. Switch to workspace DB (WorkspaceResolver::switchDatabase).
 *   3. Find emailthread by reply_token.
 *   4. Subject-aware fork: if subject changed, find-or-create thread with the
 *      new normalized subject (on the same crmcontact + member pair).
 *   5. Persist inbound notify row, any attachments, and bump thread counters.
 *
 * Security is handled by the Webhook controller BEFORE this service runs
 * (preshared key + HMAC + timestamp freshness). This service assumes the
 * request is already authenticated.
 *
 * Response codes (written directly to Flight response):
 *   200 with accepted=true/false — success or "nothing to do, don't retry"
 *   500                          — storage failure, DO retry
 */

namespace app\services;

use \Flight as Flight;
use \app\Bean;
use \app\WorkspaceResolver;
use \app\services\Core\NotifyService;

require_once __DIR__ . '/Core/NotifyService.php';

class CrmCommsInboundService {

    private $logger;

    public function __construct() {
        $this->logger = Flight::get('log');
    }

    /**
     * Check whether this request's recipient matches the CRM comms reply pattern.
     * Doesn't mutate anything — safe to call for routing decisions.
     */
    public static function matches(string $recipient): bool {
        return (bool)preg_match('/^reply-[a-z0-9-]+-[a-f0-9]{32,}@/i', trim($recipient));
    }

    /**
     * Main entry point. Reads directly from $_POST/$_FILES (Mailgun form-data).
     * Writes the response to Flight.
     */
    public function handle(): void {
        if (!$this->verifyAuthentication()) return;

        $recipient = trim((string)($_POST['recipient'] ?? ''));

        $slug = null;
        $token = null;
        if (preg_match('/reply-([a-z0-9-]+?)-([a-f0-9]{32,})@/i', $recipient, $m)) {
            $slug  = strtolower($m[1]);
            $token = strtolower($m[2]);
        }
        if ($slug === null || $token === null) {
            $this->logger?->info('CrmComms inbound: recipient did not match pattern', ['recipient' => $recipient]);
            Flight::json(['accepted' => false, 'reason' => 'unrecognized recipient'], 200);
            return;
        }

        $currentSlug = Flight::get('workspace.slug') ?: ($_SESSION['workspace_slug'] ?? null);
        if ($currentSlug !== $slug) {
            if (!WorkspaceResolver::switchDatabase($slug)) {
                $this->logger?->warning('CrmComms inbound: unknown workspace', ['slug' => $slug]);
                Flight::json(['accepted' => false, 'reason' => 'unknown workspace'], 200);
                return;
            }
        }

        $originalThread = Bean::findOne('emailthread', 'reply_token = ?', [$token]);
        if (!$originalThread || !$originalThread->id) {
            $this->logger?->info('CrmComms inbound: no thread for token', ['slug' => $slug, 'token' => $token]);
            Flight::json(['accepted' => false, 'reason' => 'unknown thread'], 200);
            return;
        }

        $sender        = trim((string)($_POST['sender'] ?? ''));
        $fromRaw       = trim((string)($_POST['from']   ?? $sender));
        $subject       = trim((string)($_POST['subject'] ?? '(no subject)'));
        $bodyPlain     = (string)($_POST['body-plain']    ?? '');
        $bodyHtml      = (string)($_POST['body-html']     ?? '');
        $strippedHtml  = (string)($_POST['stripped-html'] ?? '');
        $strippedPlain = (string)($_POST['stripped-text'] ?? '');
        $messageId     = trim((string)($_POST['Message-Id']  ?? ($_POST['message-id']  ?? '')));
        $inReplyTo     = trim((string)($_POST['In-Reply-To'] ?? ($_POST['in-reply-to'] ?? '')));
        $referencesHdr = trim((string)($_POST['References']  ?? ($_POST['references']  ?? '')));

        // Subject-aware fork: compare normalized subjects. If the contact changed
        // the subject, this is a new topic with the same contact+rep pair.
        $proposedNorm = NotifyService::normalizeSubject($subject);
        $origNorm     = NotifyService::normalizeSubject((string)($originalThread->subject ?? ''));

        if ($proposedNorm === $origNorm) {
            $thread = $originalThread;
        } else {
            $candidates = Bean::find(
                'emailthread',
                'crmcontact_id = ? AND member_id = ? AND is_archived = 0 ORDER BY id DESC',
                [(int)$originalThread->crmcontact_id, (int)$originalThread->member_id]
            );
            $thread = null;
            foreach ($candidates as $c) {
                if (NotifyService::normalizeSubject((string)($c->subject ?? '')) === $proposedNorm) {
                    $thread = $c;
                    break;
                }
            }
            if (!$thread) {
                $thread = Bean::dispense('emailthread');
                $thread->crmcontact_id   = (int)$originalThread->crmcontact_id;
                $thread->member_id       = (int)$originalThread->member_id;
                $thread->subject         = $subject;
                $thread->reply_token     = bin2hex(random_bytes(16));
                $thread->last_message_at = date('Y-m-d H:i:s');
                $thread->last_direction  = 'in';
                $thread->message_count   = 0;
                $thread->unread_count    = 0;
                $thread->is_archived     = 0;
                $thread->created_at      = date('Y-m-d H:i:s');
                $thread->updated_at      = date('Y-m-d H:i:s');
                Bean::store($thread);
                $this->logger?->info('CrmComms inbound: forked new thread on subject change', [
                    'original_thread' => (int)$originalThread->id,
                    'new_thread'      => (int)$thread->id,
                    'subject'         => $subject,
                ]);
            }
        }

        // Parse display name from "From" header.
        $fromName  = '';
        $fromEmail = $sender;
        if (preg_match('/^\s*"?([^"<]+?)"?\s*<([^>]+)>\s*$/', $fromRaw, $fm)) {
            $fromName  = trim($fm[1]);
            $fromEmail = trim($fm[2]);
        }

        $displayHtml  = $strippedHtml  !== '' ? $strippedHtml  : $bodyHtml;
        $displayPlain = $strippedPlain !== '' ? $strippedPlain : $bodyPlain;

        try {
            $notify = Bean::dispense('notify');
            $notify->from_email      = $fromEmail;
            $notify->from_name       = $fromName;
            $notify->to_email        = $recipient;
            $notify->subject         = $subject;
            $notify->notify_type     = 'email';
            $notify->content         = $displayHtml !== ''
                ? $displayHtml
                : nl2br(htmlspecialchars($displayPlain, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $notify->body_plain      = $displayPlain;
            $notify->status          = 'received';
            $notify->direction       = 'in';
            $notify->thread_id       = (int)$thread->id;
            $notify->message_id      = $messageId;
            $notify->in_reply_to     = $inReplyTo;
            $notify->references_list = $referencesHdr;
            $notify->ip              = $_SERVER['REMOTE_ADDR'] ?? 'mailgun';
            $notify->related_type    = 'inbound';
            $notify->related_eid     = 0;
            $notify->sent_at         = date('Y-m-d H:i:s');
            $notify->created_at      = date('Y-m-d H:i:s');
            Bean::store($notify);

            $this->saveAttachments($thread, $notify);

            $thread->message_count   = (int)$thread->message_count + 1;
            $thread->unread_count    = (int)$thread->unread_count + 1;
            $thread->last_message_at = $notify->sent_at;
            $thread->last_direction  = 'in';
            $thread->last_preview    = mb_substr(preg_replace('/\s+/u', ' ', trim($displayPlain)), 0, 220, 'UTF-8');
            $thread->updated_at      = date('Y-m-d H:i:s');
            Bean::store($thread);

            $this->logger?->info('CrmComms inbound: stored', [
                'thread_id' => (int)$thread->id,
                'from'      => $fromEmail,
                'subject'   => $subject,
            ]);
            Flight::json(['accepted' => true, 'thread_id' => (int)$thread->id], 200);
        } catch (\Throwable $e) {
            $this->logger?->error('CrmComms inbound: storage failed', ['err' => $e->getMessage()]);
            Flight::json(['error' => 'Storage failure'], 500);
        }
    }

    /**
     * Verify the request is actually from Mailgun. Two accepted modes:
     *   1. Preshared ?key= query param matching webhook_preshared_key from config
     *   2. HMAC sha256(timestamp + token, webhook_signing_key) == signature,
     *      with ±5 min freshness to prevent replay.
     *
     * If neither is configured, accept unverified (dev / first-boot only) but log
     * a loud warning. Writes a 4xx response and returns false on failure.
     */
    private function verifyAuthentication(): bool {
        $config      = $this->loadMailgunWebhookConfig();
        $preshared   = trim((string)($config['webhook_preshared_key'] ?? ''), '"');
        $signingKey  = trim((string)($config['webhook_signing_key']   ?? $config['key'] ?? ''), '"');

        // 1. Preshared query pre-filter (cheap, drops internet noise)
        if ($preshared !== '') {
            $presented = $_GET['key'] ?? '';
            if (hash_equals($preshared, (string)$presented)) {
                return true; // preshared passed — skip HMAC
            }
            // Preshared failed — don't log loudly yet, HMAC may still pass
        }

        // 2. HMAC signature verification
        if ($signingKey !== '') {
            $timestamp = (string)($_POST['timestamp'] ?? '');
            $token     = (string)($_POST['token']     ?? '');
            $signature = (string)($_POST['signature'] ?? '');

            if ($timestamp === '' || $token === '' || $signature === '') {
                $this->logger?->warning('CrmComms inbound: missing signature fields');
                Flight::json(['error' => 'Signature fields missing'], 403);
                return false;
            }
            $expected = hash_hmac('sha256', $timestamp . $token, $signingKey);
            if (!hash_equals($expected, $signature)) {
                $this->logger?->warning('CrmComms inbound: HMAC mismatch');
                Flight::json(['error' => 'Invalid signature'], 403);
                return false;
            }
            if (abs(time() - (int)$timestamp) > 300) {
                $this->logger?->warning('CrmComms inbound: stale timestamp');
                Flight::json(['error' => 'Stale request'], 403);
                return false;
            }
            return true;
        }

        // Neither configured — dev mode
        $this->logger?->warning('CrmComms inbound: no auth configured, accepting unverified (dev only)');
        return true;
    }

    /**
     * Load Mailgun webhook config (api key + webhook signing key + preshared).
     * Mirrors MailgunService's cascade but pulls webhook-specific keys too.
     */
    private function loadMailgunWebhookConfig(): array {
        // Prefer the connections table (encrypted api key + metadata)
        try {
            $conn = Bean::findOne('connections', 'connector_type = ? AND enabled = 1 ORDER BY id ASC', ['mailgun']);
            if ($conn && !empty($conn->access_token)) {
                require_once __DIR__ . '/EncryptionService.php';
                $apiKey = \app\services\EncryptionService::decrypt($conn->access_token);
                $meta = json_decode((string)($conn->metadata_json ?: '{}'), true) ?: [];
                return [
                    'key'                  => $apiKey,
                    'webhook_signing_key'  => $meta['webhook_signing_key']  ?? '',
                    'webhook_preshared_key'=> $meta['webhook_preshared_key'] ?? '',
                ];
            }
        } catch (\Throwable $e) { /* fall through */ }

        // Flight config
        if (Flight::get('mailgun.api_key')) {
            return [
                'key'                   => (string)Flight::get('mailgun.api_key'),
                'webhook_signing_key'   => (string)(Flight::get('mailgun.webhook_signing_key')   ?? ''),
                'webhook_preshared_key' => (string)(Flight::get('mailgun.webhook_preshared_key') ?? ''),
            ];
        }

        // INI fallback
        $iniPath = dirname(__DIR__) . '/conf/mailgun.ini';
        if (file_exists($iniPath)) {
            $parsed = parse_ini_file($iniPath) ?: [];
            return [
                'key'                   => (string)($parsed['key']                   ?? ''),
                'webhook_signing_key'   => (string)($parsed['webhook_signing_key']   ?? ''),
                'webhook_preshared_key' => (string)($parsed['webhook_preshared_key'] ?? ''),
            ];
        }
        return [];
    }

    /**
     * Mailgun sends inbound attachments as attachment-1, attachment-2, ...
     * in $_FILES. Save under public/uploads/inbound-mail/{threadId}/.
     */
    private function saveAttachments($thread, $notify): void {
        if (empty($_FILES)) return;
        $dir = 'public/uploads/inbound-mail/' . (int)$thread->id;
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) return;

        foreach ($_FILES as $key => $f) {
            if (empty($f['tmp_name']) || ($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) continue;
            $origName = $f['name'] ?: $key;
            $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $origName);
            $dest = $dir . '/' . bin2hex(random_bytes(4)) . '-' . $safeName;
            if (!move_uploaded_file($f['tmp_name'], $dest)) continue;

            $att = Bean::dispense('notifyattachment');
            $att->notify_id  = (int)$notify->id;
            $att->thread_id  = (int)$thread->id;
            $att->filename   = $origName;
            $att->file_path  = substr($dest, strlen('public')); // web-accessible
            $att->mime_type  = $f['type'] ?: 'application/octet-stream';
            $att->size_bytes = (int)$f['size'];
            $att->created_at = date('Y-m-d H:i:s');
            Bean::store($att);
        }
    }
}
