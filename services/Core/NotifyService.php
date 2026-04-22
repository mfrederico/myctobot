<?php
/**
 * NotifyService — Email via Mailgun with every send logged to `notify`,
 * conversation threading via `emailthread`, and attachment bookkeeping
 * via `notifyattachment`.
 *
 * Ported from dealerportal. Differences for myctobot:
 *   - threadFor(crmcontact_id, member_id, subject)  (no distributor)
 *   - Reuses MailgunService's credential-discovery cascade via Guzzle
 *     rather than the official mailgun-php SDK (which isn't installed).
 *   - Reply-To points at reply-{workspace-slug}-{token}@<mailgun-domain>
 *     so inbound webhook can switch tenants + resolve the thread.
 *
 * Usage:
 *   NotifyService::create()
 *       ->to('prospect@example.com', 'Prospect Name')
 *       ->subject('Following up')
 *       ->threadFor((int)$contact->id, (int)$member->id, 'Following up')
 *       ->send($htmlBody, ['/path/to/file.pdf']);
 */

namespace app\services\Core;

use app\Bean;
use Flight;
use GuzzleHttp\Client;

require_once dirname(__DIR__) . '/MailgunService.php';
require_once dirname(__DIR__) . '/EncryptionService.php';

class NotifyService {

    private string $apiKey      = '';
    private string $domain      = '';
    private string $endpoint    = 'https://api.mailgun.net';
    private string $fromEmail   = '';
    private string $fromName    = 'MyCTOBot';
    private string $toEmail     = '';
    private string $toName      = '';
    /** @var array<string> */
    private array  $ccList      = [];
    private string $subjectLine = 'Notification';
    private ?int $threadContactId  = null;
    private ?int $threadMemberId   = null;
    private ?string $threadSubject = null;
    private ?string $inReplyTo     = null;
    /** @var array<string> */
    private array  $referencesList = [];
    private ?string $relatedType   = null;
    private int    $relatedEid     = 0;
    private ?Client $client        = null;
    private bool   $enabled        = false;
    private        $logger;

    /** Keyed by member_id (or 0 for "no member context") so the cache
     *  doesn't collide between the current rep's own config and the
     *  workspace default. */
    private static array $configCache = [];

    private ?int $preferMemberId = null;
    /** Source of the loaded config — exposed via describeConfig() so the UI
     *  can show "Sending via your own Mailgun" vs "Sending via workspace default". */
    private string $configSource = 'none';

    public static function create(): self {
        return new self();
    }

    public function __construct() {
        $this->logger = Flight::get('log');
        // Default to the logged-in member's preference so callers don't
        // have to remember. Explicit withMemberId() still overrides.
        try {
            $m = method_exists(Flight::class, 'getMember') ? Flight::getMember() : null;
            if ($m && !empty($m->id)) $this->preferMemberId = (int)$m->id;
        } catch (\Throwable $e) { /* Flight or session unavailable */ }
        $this->loadConfig();
    }

    /** Force a specific member's connection to take priority. Useful for
     *  background jobs sending on behalf of a rep. */
    public function withMemberId(?int $memberId): self {
        $this->preferMemberId = $memberId;
        $this->loadConfig(/*force*/ true);
        return $this;
    }

    /**
     * Mirrors MailgunService's cascade so this service and that one pull from
     * the same place: connections table (encrypted) → Flight config → INI.
     * We duplicate the cascade rather than construct a MailgunService because
     * we need to send with custom headers (Reply-To, Message-Id) that
     * MailgunService's send() doesn't expose.
     */
    private function loadConfig(bool $force = false): void {
        $cacheKey = (int)($this->preferMemberId ?? 0);
        if ($force || !isset(self::$configCache[$cacheKey])) {
            self::$configCache[$cacheKey] = $this->discoverConfig();
        }
        $config = self::$configCache[$cacheKey];

        $this->apiKey    = (string)($config['key']       ?? '');
        $this->domain    = trim((string)($config['domain']    ?? ''), '"');
        // Treat empty string the same as missing — the connections-table
        // metadata can store '' for endpoint, which would otherwise collapse
        // to a path-only base URI and fail with "no host part in the URL".
        $endpoint        = trim((string)($config['endpoint']  ?? ''), '"');
        $this->endpoint  = $endpoint !== '' ? $endpoint : 'https://api.mailgun.net';
        $this->fromEmail = trim((string)($config['fromEmail'] ?? ''), '"');
        $this->fromName  = trim((string)($config['fromName']  ?? 'MyCTOBot'),  '"');
        $this->configSource = (string)($config['source'] ?? 'none');

        $this->enabled = $this->apiKey !== '' && $this->domain !== '';
        if ($this->enabled) {
            $this->client = new Client([
                'base_uri' => rtrim($this->endpoint, '/') . '/v3/',
                'auth'     => ['api', $this->apiKey],
            ]);
        }
    }

    private function discoverConfig(): array {
        // 1a. Member-owned connection (the rep's own Mailgun, if they have one).
        //     Preferred so replies go out via the rep's domain/deliverability
        //     when configured. Falls through to shared/workspace default below.
        if ($this->preferMemberId !== null && $this->preferMemberId > 0) {
            $own = $this->loadConnectionForMember($this->preferMemberId);
            if ($own !== null) {
                $own['source'] = 'member';
                return $own;
            }
        }

        // 1b. Any enabled shared/workspace Mailgun connection. Picked by
        //     lowest id for stable behavior across requests.
        try {
            $conn = Bean::findOne('connections', 'connector_type = ? AND enabled = 1 ORDER BY id ASC', ['mailgun']);
            if ($conn && !empty($conn->access_token) && !empty($conn->external_eid)) {
                $loaded = $this->connectionToConfig($conn);
                if ($loaded !== null) {
                    $loaded['source'] = 'workspace';
                    return $loaded;
                }
            }
        } catch (\Throwable $e) {
            // DB unavailable — try next source
        }

        // 2. Flight config
        $apiKey = (string)(Flight::get('mailgun.api_key') ?? '');
        $domain = (string)(Flight::get('mailgun.domain')  ?? '');
        if ($apiKey !== '' && $domain !== '') {
            return [
                'key'       => $apiKey,
                'domain'    => $domain,
                'fromEmail' => (string)(Flight::get('mailgun.from_email') ?? ''),
                'fromName'  => (string)(Flight::get('mailgun.from_name')  ?? ''),
                'endpoint'  => (string)(Flight::get('mailgun.endpoint')   ?? ''),
                'source'    => 'flight',
            ];
        }

        // 3. conf/mailgun.ini
        $iniPath = dirname(__DIR__, 2) . '/conf/mailgun.ini';
        if (file_exists($iniPath)) {
            $parsed = parse_ini_file($iniPath);
            if ($parsed && !empty($parsed['key']) && !empty($parsed['domain'])) {
                $parsed['source'] = 'ini';
                return $parsed;
            }
        }

        return ['source' => 'none'];
    }

    /** Look up a Mailgun connection owned by a specific member. Returns
     *  null (not an empty array) when none exists so the caller can tell
     *  "no connection" apart from "connection with missing credentials". */
    private function loadConnectionForMember(int $memberId): ?array {
        try {
            $conn = Bean::findOne(
                'connections',
                'connector_type = ? AND enabled = 1 AND member_id = ? ORDER BY id ASC',
                ['mailgun', $memberId]
            );
            if (!$conn || empty($conn->access_token) || empty($conn->external_eid)) {
                return null;
            }
            return $this->connectionToConfig($conn);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Normalize a connections row into the shape discoverConfig() returns. */
    private function connectionToConfig($conn): ?array {
        $apiKey = \app\services\EncryptionService::decrypt($conn->access_token);
        $metadata = json_decode((string)($conn->metadata_json ?: '{}'), true) ?: [];
        if ($apiKey === '' || $conn->external_eid === '') return null;
        return [
            'key'       => $apiKey,
            'domain'    => $conn->external_eid,
            'fromEmail' => $metadata['from_email'] ?? '',
            'fromName'  => $metadata['from_name']  ?? '',
            'endpoint'  => $metadata['endpoint']   ?? '',
        ];
    }

    /**
     * Describe which Mailgun config would be used for a send by this member.
     * Used by the UI to show a banner ("Sending via workspace default" etc).
     *
     * @return array{configured:bool, source:string, domain:string, from_email:string, is_personal:bool}
     *   source: 'member' | 'workspace' | 'flight' | 'ini' | 'none'
     */
    public static function describeConfig(?int $memberId = null): array {
        $svc = self::create();
        if ($memberId !== null) $svc = $svc->withMemberId($memberId);
        return [
            'configured' => $svc->enabled,
            'source'     => $svc->configSource,
            'domain'     => $svc->domain,
            'from_email' => $svc->fromEmail,
            'is_personal'=> $svc->configSource === 'member',
        ];
    }

    /** Force re-discovery on next instance. Useful for tests. */
    public static function resetConfigCache(): void {
        self::$configCache = [];
    }

    public function to(string $email, string $name = ''): self {
        $this->toEmail = $email;
        $safeName = preg_replace('/[\r\n<>",;]/', '', $name);
        $this->toName = $safeName !== '' ? $safeName : $email;
        return $this;
    }

    /** Silently ignores invalid addresses so callers can pass mixed input. */
    public function cc(string $email): self {
        $email = trim($email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->ccList[] = $email;
        }
        return $this;
    }

    public function from(string $email, string $name = ''): self {
        $this->fromEmail = $email;
        if ($name !== '') $this->fromName = $name;
        return $this;
    }

    /** Override display name only — envelope sender stays Mailgun-verified. */
    public function fromName(string $name): self {
        $name = preg_replace('/[\r\n<>",;]/', '', $name);
        if ($name !== '') $this->fromName = $name;
        return $this;
    }

    public function subject(string $subject): self {
        $this->subjectLine = $subject;
        return $this;
    }

    public function relatedTo(string $type, int $id): self {
        $this->relatedType = $type;
        $this->relatedEid  = $id;
        return $this;
    }

    /**
     * Bind this send to a (contact, member) conversation thread. send()
     * resolves-or-creates the thread and wires Reply-To so Mailgun routes
     * replies back through the webhook.
     */
    public function threadFor(int $crmcontactId, int $memberId, ?string $subjectHint = null): self {
        $this->threadContactId = $crmcontactId;
        $this->threadMemberId  = $memberId;
        $this->threadSubject   = $subjectHint;
        return $this;
    }

    /** Reply to an existing outbound/inbound message by Message-ID. */
    public function inReplyTo(?string $messageId, array $prevReferences = []): self {
        $this->inReplyTo      = $messageId;
        $this->referencesList = array_values(array_filter($prevReferences));
        if ($messageId && !in_array($messageId, $this->referencesList, true)) {
            $this->referencesList[] = $messageId;
        }
        return $this;
    }

    /**
     * Strip Re:/Fwd: prefixes (nested too), trim, lowercase. Used for topic-
     * based thread matching so "Re: Re: Q2 check-in" and "Q2 check-in" collapse.
     */
    public static function normalizeSubject(string $subject): string {
        $s = trim($subject);
        do {
            $before = $s;
            $s = preg_replace('/^\s*(?:re|fwd?)\s*:\s*/i', '', $s);
        } while ($s !== $before);
        $s = preg_replace('/\s+/u', ' ', $s);
        return mb_strtolower(trim($s), 'UTF-8');
    }

    /**
     * Find-or-create an emailthread for (contact, member) + matching subject.
     * Changing the subject on a reply forks a new thread so unrelated topics
     * with the same contact don't mash together.
     */
    private function resolveThread(): ?object {
        if ($this->threadContactId === null || $this->threadMemberId === null) {
            return null;
        }

        $proposed = $this->threadSubject ?: $this->subjectLine;
        $proposedNorm = self::normalizeSubject($proposed);

        $candidates = Bean::find(
            'emailthread',
            'crmcontact_id = ? AND member_id = ? AND is_archived = 0 ORDER BY id DESC',
            [$this->threadContactId, $this->threadMemberId]
        );
        foreach ($candidates as $candidate) {
            if (self::normalizeSubject($candidate->subject ?? '') === $proposedNorm) {
                return $candidate;
            }
        }

        $thread = Bean::dispense('emailthread');
        $thread->crmcontact_id   = $this->threadContactId;
        $thread->member_id       = $this->threadMemberId;
        $thread->subject         = $proposed;
        $thread->reply_token     = bin2hex(random_bytes(16));
        $thread->last_message_at = date('Y-m-d H:i:s');
        $thread->last_direction  = 'out';
        $thread->message_count   = 0;
        $thread->unread_count    = 0;
        $thread->is_archived     = 0;
        $thread->created_at      = date('Y-m-d H:i:s');
        $thread->updated_at      = date('Y-m-d H:i:s');
        Bean::store($thread);
        return $thread;
    }

    /**
     * Send the email, log to notify, and (if threadFor was called) update
     * the thread's counters/preview.
     *
     * @param string         $content      HTML body
     * @param array<string>  $attachments  Absolute file paths to attach
     * @return array{success:bool, notify_id:?int, thread_id:?int, message_id:?string, error:?string}
     */
    public function send(string $content, array $attachments = []): array {
        if ($this->toEmail === '') {
            return ['success' => false, 'notify_id' => null, 'thread_id' => null, 'message_id' => null, 'error' => 'No recipient specified'];
        }
        if (!$this->enabled || !$this->client) {
            $this->logger?->error('NotifyService: Mailgun not configured');
            return ['success' => false, 'notify_id' => null, 'thread_id' => null, 'message_id' => null, 'error' => 'Mail service not configured'];
        }

        if ($this->fromEmail === '') {
            // Safe default — Mailgun still delivers via the sending domain.
            $this->fromEmail = 'noreply@' . $this->domain;
        }

        $htmlMessage = $this->wrapInTemplate($content);
        $thread = $this->resolveThread();

        $domainForMsgId = $this->domain ?: 'myctobot.ai';
        $ourMessageId = sprintf('<myc.%s.%s@%s>',
            $thread && $thread->id ? (int)$thread->id : 'adhoc',
            bin2hex(random_bytes(8)),
            $domainForMsgId
        );

        $slug = (string)(Flight::get('workspace.slug') ?: ($_SESSION['workspace_slug'] ?? 'default'));

        $formFields = [
            'from'         => "{$this->fromName} <{$this->fromEmail}>",
            'to'           => "{$this->toName} <{$this->toEmail}>",
            'subject'      => $this->subjectLine,
            'html'         => $htmlMessage,
            'text'         => self::previewFromHtml($content, 5000),
            'h:Message-Id' => $ourMessageId,
        ];

        if ($thread && $thread->id) {
            $formFields['h:Reply-To'] = "reply-{$slug}-{$thread->reply_token}@{$this->domain}";
        }
        if ($this->inReplyTo !== null && $this->inReplyTo !== '') {
            $formFields['h:In-Reply-To'] = $this->inReplyTo;
        }
        if (!empty($this->referencesList)) {
            $formFields['h:References'] = implode(' ', $this->referencesList);
        }
        if (!empty($this->ccList)) {
            $formFields['cc'] = implode(', ', $this->ccList);
        }

        $notify = Bean::dispense('notify');
        $notify->from_email      = $this->fromEmail;
        $notify->to_email        = $this->toEmail;
        $notify->subject         = $this->subjectLine;
        $notify->notify_type     = 'email';
        $notify->content         = $content;
        $notify->body_plain      = self::previewFromHtml($content, 10000);
        $notify->status          = 'pending';
        $notify->ip              = $_SERVER['REMOTE_ADDR'] ?? 'internal';
        $notify->related_type    = $this->relatedType ?? '';
        $notify->related_eid     = $this->relatedEid;
        $notify->direction       = 'out';
        $notify->thread_id       = $thread ? (int)$thread->id : 0;
        $notify->message_id      = $ourMessageId;
        $notify->in_reply_to     = $this->inReplyTo ?? '';
        $notify->references_list = $this->referencesList ? implode(' ', $this->referencesList) : '';
        $notify->from_name       = $this->fromName;
        $notify->created_at      = date('Y-m-d H:i:s');

        try {
            $this->logger?->info("NotifyService: Sending to {$this->toEmail} Re: {$this->subjectLine}");
            $response = $this->postToMailgun($formFields, $attachments);
            if ($response !== 200) {
                throw new \RuntimeException("Mailgun returned HTTP {$response}");
            }
            $notify->status  = 'sent';
            $notify->sent_at = date('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            $notify->status        = 'error';
            $notify->error_message = $e->getMessage();
            $this->logger?->error("NotifyService send failed: {$e->getMessage()}");
        }

        $notifyId = Bean::store($notify);

        if ($thread && $notify->status === 'sent') {
            try {
                $thread->message_count   = (int)$thread->message_count + 1;
                $thread->last_message_at = $notify->sent_at ?: date('Y-m-d H:i:s');
                $thread->last_direction  = 'out';
                $thread->last_preview    = self::previewFromHtml($content);
                $thread->updated_at      = date('Y-m-d H:i:s');
                Bean::store($thread);
            } catch (\Throwable $e) { /* non-fatal */ }
        }

        return [
            'success'    => $notify->status === 'sent',
            'notify_id'  => (int)$notifyId,
            'thread_id'  => $thread ? (int)$thread->id : null,
            'message_id' => $ourMessageId,
            'error'      => $notify->status !== 'sent' ? ($notify->error_message ?: 'Send failed') : null,
        ];
    }

    /**
     * Submit to Mailgun. Uses multipart when there are attachments (so the
     * file bytes can ride the request), plain form_params otherwise.
     */
    private function postToMailgun(array $formFields, array $attachments): int {
        $url = $this->domain . '/messages';

        if (empty($attachments)) {
            $response = $this->client->post($url, ['form_params' => $formFields]);
            return $response->getStatusCode();
        }

        $multipart = [];
        foreach ($formFields as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => (string)$value];
        }
        foreach ($attachments as $path) {
            if (!file_exists($path)) continue;
            $multipart[] = [
                'name'     => 'attachment',
                'contents' => fopen($path, 'r'),
                'filename' => basename($path),
                'headers'  => ['Content-Type' => mime_content_type($path) ?: 'application/octet-stream'],
            ];
        }
        $response = $this->client->post($url, ['multipart' => $multipart]);
        return $response->getStatusCode();
    }

    /** Plain-text preview for the thread list. Also used as multipart text/plain. */
    public static function previewFromHtml(string $html, int $maxLen = 220): string {
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text);
        return mb_substr($text, 0, $maxLen, 'UTF-8');
    }

    /**
     * Wrap the body in a minimal HTML shell — no card, no brand header,
     * no footer. CRM emails should read like a real person wrote them, not
     * like a receipt from a SaaS. Outlook + some legacy clients render
     * fragment HTML awkwardly, so we do provide a bare <html><body> with
     * sensible default typography and nothing else.
     *
     * Transactional templates (password reset, invoice, etc.) should build
     * their own HTML and pass it through — the wrapping here is deliberately
     * neutral so it doesn't fight whatever the caller provides.
     */
    private function wrapInTemplate(string $content): string {
        return <<<HTML
<!doctype html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.5; color: #222; margin: 0; padding: 16px; }
p { margin: 0 0 1em; }
a { color: #0d6efd; }
</style>
</head>
<body>{$content}</body>
</html>
HTML;
    }
}
