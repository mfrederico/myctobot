<?php
/**
 * Communications — Email thread hub for the CRM.
 *
 * One conversation per (crmcontact, sales-rep) pair. Threads collect every
 * outbound message the rep sends and every inbound reply that lands on the
 * Mailgun webhook at reply-{workspace-slug}-{token}@<mailgun-domain>.
 *
 * Scope:
 *   ADMIN — sees all threads in the workspace
 *   SALES — sees threads where member_id = them
 *
 * Ported from dealerportal/controls/Web/Communications.php. Differences:
 *   - No distributor — myctobot's tenancy is per-workspace only
 *   - No dealer-level viewer (this hub is rep-facing; contacts don't log in)
 *   - Contact surrogate is crmcontact; email field is company_email
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\BaseControls\Control;
use \app\services\Core\NotifyService;

require_once __DIR__ . '/../services/Core/NotifyService.php';

class Communications extends Control {

    /** Align with CRM's layout so the sidebar is consistent. */
    protected function render($template, $data = [], $layout = true) {
        $data = array_merge($this->viewData, $data);
        if ($layout) {
            Flight::render($template, $data, 'body_content');
            Flight::render('layouts/crm-layout', $data);
        } else {
            Flight::render($template, $data);
        }
    }

    // ======================================================================
    // Thread list + welcome
    // ======================================================================

    public function index($params = null) {
        if (!$this->requireLogin()) return;

        $threads = $this->loadThreadsForViewer();
        $this->render('communications/index', [
            'title'        => 'Communications',
            'crm_page'     => 'communications',
            'threads'      => $threads,
            'activeThread' => null,
            'messages'     => [],
        ]);
    }

    // ======================================================================
    // Thread detail
    // ======================================================================

    public function thread($params = null) {
        if (!$this->requireLogin()) return;

        $threadId = (int)$this->opId();
        $thread = Bean::load('emailthread', $threadId);
        if (!$thread->id || !$this->canSeeThread($thread)) {
            $this->flash('danger', 'Thread not found.');
            Flight::redirect('/communications');
            return;
        }

        // Mark pending inbound as read for this viewer.
        if ((int)$thread->unread_count > 0 && $this->canMarkRead($thread)) {
            $thread->unread_count = 0;
            Bean::store($thread);
        }

        $contact = Bean::load('crmcontact', (int)$thread->crmcontact_id);
        $rep     = Bean::load('member', (int)$thread->member_id);

        // Newest-first (Gmail-style). Avoids scroll-to-bottom gymnastics.
        $messageRows = Bean::getAll(
            'SELECT * FROM notify WHERE thread_id = ? ORDER BY created_at DESC, id DESC',
            [(int)$thread->id]
        );

        $attachmentRows = Bean::getAll(
            'SELECT id, notify_id, filename, file_path, mime_type, size_bytes
             FROM notifyattachment WHERE thread_id = ? ORDER BY id',
            [(int)$thread->id]
        );
        $attByNotify = [];
        foreach ($attachmentRows as $a) {
            $attByNotify[(int)$a['notify_id']][] = $a;
        }

        $messages = [];
        foreach ($messageRows as $r) {
            $obj = (object)$r;
            $obj->attachments = $attByNotify[(int)$r['id']] ?? [];
            $messages[] = $obj;
        }

        $this->render('communications/thread', [
            'title'        => 'Communications',
            'crm_page'     => 'communications',
            'threads'      => $this->loadThreadsForViewer(),
            'activeThread' => $thread,
            'contact'      => $contact,
            'rep'          => $rep,
            'messages'     => $messages,
            'mailConfig'   => NotifyService::describeConfig((int)$this->member->id),
        ]);
    }

    // ======================================================================
    // Start a new thread from the CRM contact page
    // ======================================================================

    /**
     * GET  /communications/compose/{contactId}  — render composer for a contact
     * POST /communications/compose/{contactId}  — send first message, creating thread
     */
    public function compose($params = null) {
        if (!$this->requireLogin()) return;

        $contactId = (int)$this->opId();
        $contact = Bean::load('crmcontact', $contactId);
        if (!$contact->id || !$this->canAccessContact($contact)) {
            $this->flash('danger', 'Contact not found.');
            Flight::redirect('/crm/contacts');
            return;
        }

        if (Flight::request()->method !== 'POST') {
            // Render composer page
            $this->render('communications/compose', [
                'title'      => 'New message — ' . ($contact->fullName() ?: $contact->company_name ?? ''),
                'crm_page'   => 'communications',
                'contact'    => $contact,
                'threads'    => $this->loadThreadsForViewer(),
                'mailConfig' => NotifyService::describeConfig((int)$this->member->id),
            ]);
            return;
        }

        if (!$this->validateCSRF()) return;

        $toEmail = trim((string)($contact->company_email ?? ''));
        if ($toEmail === '') {
            $this->flash('danger', 'Contact has no email on file.');
            Flight::redirect('/crm/view/' . $contactId);
            return;
        }

        $subject = trim((string)($_POST['subject'] ?? ''));
        if ($subject === '') $subject = 'A note from ' . $this->memberDisplayName();

        $message = (string)($_POST['message'] ?? '');
        $body = $this->sanitizeHtmlBody($message);

        $savedAttachments = $this->saveReplyAttachments(['threadId' => 'new-' . $contactId]);
        $attachmentPaths = array_map(fn($a) => $a['disk_path'], $savedAttachments);

        $result = NotifyService::create()
            ->to($toEmail, $contact->fullName() ?: ($contact->company_name ?? ''))
            ->subject($subject)
            ->threadFor((int)$contact->id, (int)$this->member->id, $subject)
            ->fromName($this->memberDisplayName())
            ->relatedTo('crmcontact', (int)$contact->id)
            ->send($body, $attachmentPaths);

        $this->persistAttachments($result, $savedAttachments);

        if (!$result['success']) {
            $this->flash('danger', 'Send failed: ' . ($result['error'] ?? 'unknown'));
            Flight::redirect('/crm/view/' . $contactId);
            return;
        }

        // Log the send as a CRM touch so it appears on the contact's timeline.
        $this->logEmailTouch($contact, (int)$result['thread_id'], $subject, $body);

        // Send-from-compose came from /crm/view/{id} — return there, not to
        // the thread. The rep wanted to continue working the contact; they
        // can open the thread from the Recent Conversations card when ready.
        $this->flash('success', 'Message sent.');
        Flight::redirect('/crm/view/' . $contactId);
    }

    // ======================================================================
    // Reply on an existing thread
    // ======================================================================

    public function reply($params = null) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;

        $threadId = (int)$this->opId();
        $thread = Bean::load('emailthread', $threadId);
        if (!$thread->id || !$this->canSeeThread($thread)) {
            $this->flash('danger', 'Thread not found.');
            Flight::redirect('/communications');
            return;
        }

        $contact = Bean::load('crmcontact', (int)$thread->crmcontact_id);
        $toEmail = trim((string)($contact->company_email ?? ''));
        if ($toEmail === '') {
            $this->flash('danger', 'Contact has no email on file.');
            Flight::redirect('/communications/thread/' . $threadId);
            return;
        }

        $message = (string)($_POST['message'] ?? '');
        $subject = trim((string)($_POST['subject'] ?? ''));
        if ($subject === '') $subject = 'Re: ' . ($thread->subject ?: 'Your message');
        $body = $this->sanitizeHtmlBody($message);

        // Find most recent Message-ID so we can set In-Reply-To / References.
        $lastRow = Bean::getRow(
            'SELECT message_id, references_list FROM notify WHERE thread_id = ? AND message_id != "" ORDER BY id DESC LIMIT 1',
            [(int)$thread->id]
        );
        $inReplyTo = $lastRow['message_id'] ?? null;
        $prevRefs  = $lastRow && !empty($lastRow['references_list'])
            ? explode(' ', $lastRow['references_list'])
            : [];

        // Optional Gmail-style quoted history appended to the body.
        if (!empty($_POST['include_thread'])) {
            $body .= $this->buildQuotedHistory((int)$thread->id);
        }

        $savedAttachments = $this->saveReplyAttachments(['threadId' => (int)$thread->id]);
        $attachmentPaths = array_map(fn($a) => $a['disk_path'], $savedAttachments);

        $ccRaw = trim((string)($_POST['cc'] ?? ''));
        $ccList = array_filter(array_map('trim', preg_split('/[,;]/', $ccRaw)));

        $builder = NotifyService::create()
            ->to($toEmail, $contact->fullName() ?: ($contact->company_name ?? ''))
            ->subject($subject)
            // Pass the POSTed subject (not $thread->subject) so a subject change
            // in the composer forks a new thread via resolveThread().
            ->threadFor((int)$thread->crmcontact_id, (int)$thread->member_id, $subject)
            ->inReplyTo($inReplyTo, $prevRefs)
            ->fromName($this->memberDisplayName())
            ->relatedTo('communications', (int)$thread->id);
        foreach ($ccList as $cc) $builder = $builder->cc($cc);
        $result = $builder->send($body, $attachmentPaths);

        $this->persistAttachments($result, $savedAttachments);

        if ($result['success']) {
            // Log the reply as a CRM touch too — same treatment as first-send.
            $this->logEmailTouch($contact, (int)($result['thread_id'] ?? $threadId), $subject, $body);
            $this->flash('success', 'Reply sent.');
        } else {
            $this->flash('danger', 'Send failed: ' . ($result['error'] ?? 'unknown'));
        }
        // If subject change forked, land on the new thread; otherwise the original.
        $landOn = (int)($result['thread_id'] ?? $threadId);
        Flight::redirect('/communications/thread/' . $landOn);
    }

    // ======================================================================
    // Archive toggle
    // ======================================================================

    public function archive($params = null) {
        if (!$this->requireLogin()) return;
        if (!$this->validateCSRF()) return;

        $threadId = (int)$this->opId();
        $thread = Bean::load('emailthread', $threadId);
        if (!$thread->id || !$this->canSeeThread($thread)) {
            Flight::redirect('/communications');
            return;
        }

        $thread->is_archived = (int)$thread->is_archived ? 0 : 1;
        Bean::store($thread);
        Flight::redirect('/communications');
    }

    // ======================================================================
    // Polling
    // ======================================================================

    /**
     * GET /communications/poll?since_msg=<id>&thread=<id>
     * Returns thread summary + any new messages the viewer can see with id > since_msg.
     */
    public function poll($params = null) {
        if (!$this->requireLogin()) return;

        $sinceMsgId  = (int)($_GET['since_msg'] ?? 0);
        $threadFilter = (int)($_GET['thread']   ?? 0);
        $myLevel = (int)$this->member->level;

        $where = ['t.is_archived = 0'];
        $params = [];
        if ($myLevel > LEVELS['ADMIN']) {
            $where[] = 't.member_id = ?';
            $params[] = (int)$this->member->id;
        }
        $whereClause = implode(' AND ', $where);

        $threadRows = Bean::getAll(
            "SELECT t.id, t.crmcontact_id, t.unread_count, t.last_message_at, t.last_direction, t.last_preview,
                    c.company_name AS contact_company, c.first_name AS contact_first, c.last_name AS contact_last
             FROM emailthread t
             LEFT JOIN crmcontact c ON t.crmcontact_id = c.id
             WHERE {$whereClause}",
            $params
        );
        $visibleThreadIds = array_map(fn($r) => (int)$r['id'], $threadRows);

        $newMessages = [];
        if ($sinceMsgId > 0 && !empty($visibleThreadIds)) {
            $idList = implode(',', array_map('intval', $visibleThreadIds));
            $threadClause = $threadFilter > 0 && in_array($threadFilter, $visibleThreadIds, true)
                ? "AND thread_id = {$threadFilter}"
                : "AND thread_id IN ({$idList})";
            $rows = Bean::getAll(
                "SELECT id, thread_id, direction, from_email, from_name, subject,
                        content, status, sent_at, created_at
                 FROM notify
                 WHERE id > ? {$threadClause}
                 ORDER BY id ASC",
                [$sinceMsgId]
            );
            foreach ($rows as $r) {
                $newMessages[] = [
                    'id'         => (int)$r['id'],
                    'thread_id'  => (int)$r['thread_id'],
                    'direction'  => $r['direction'],
                    'from_email' => $r['from_email'],
                    'from_name'  => $r['from_name'] ?: $r['from_email'],
                    'subject'    => $r['subject'],
                    'content'    => $r['content'],
                    'status'     => $r['status'],
                    'ts'         => $r['sent_at'] ?: $r['created_at'],
                ];
            }
        }

        Flight::json([
            'now'          => date('c'),
            'threads'      => array_map(fn($r) => [
                'id'              => (int)$r['id'],
                'crmcontact_id'   => (int)$r['crmcontact_id'],
                'contact_name'    => $this->contactLabel($r),
                'unread_count'    => (int)$r['unread_count'],
                'last_message_at' => $r['last_message_at'],
                'last_direction'  => $r['last_direction'],
                'last_preview'    => $r['last_preview'],
            ], $threadRows),
            'new_messages' => $newMessages,
        ]);
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    /**
     * Return threads grouped by contact, ordered by recency, filtered by
     * viewer scope. Returned as:
     *   [['contact_id'=>…, 'contact_name'=>…, 'threads'=>[…], 'unread_total'=>n], …]
     */
    private function loadThreadsForViewer(): array {
        $myLevel = (int)$this->member->level;

        $where = ['t.is_archived = 0'];
        $params = [];
        if ($myLevel > LEVELS['ADMIN']) {
            $where[] = 't.member_id = ?';
            $params[] = (int)$this->member->id;
        }
        $whereClause = implode(' AND ', $where);

        $rows = Bean::getAll(
            "SELECT t.*, c.company_name AS contact_company, c.first_name AS contact_first,
                    c.last_name AS contact_last, c.company_email AS contact_email,
                    m.first_name AS rep_first, m.last_name AS rep_last
             FROM emailthread t
             LEFT JOIN crmcontact c ON t.crmcontact_id = c.id
             LEFT JOIN member m     ON t.member_id    = m.id
             WHERE {$whereClause}
             ORDER BY c.company_name ASC, t.last_message_at DESC",
            $params
        );

        $grouped = [];
        foreach ($rows as $r) {
            $key = (int)$r['crmcontact_id'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'contact_id'   => $key,
                    'contact_name' => $this->contactLabel($r),
                    'contact_email'=> $r['contact_email'] ?? '',
                    'threads'      => [],
                    'unread_total' => 0,
                ];
            }
            $grouped[$key]['threads'][] = (object)$r;
            $grouped[$key]['unread_total'] += (int)$r['unread_count'];
        }
        return array_values($grouped);
    }

    /** Display name for a contact — company first, falling back to person. */
    private function contactLabel(array $row): string {
        $company = trim((string)($row['contact_company'] ?? ''));
        if ($company !== '') return $company;
        $person = trim(trim((string)($row['contact_first'] ?? '')) . ' ' . trim((string)($row['contact_last'] ?? '')));
        return $person !== '' ? $person : ('Contact #' . (int)($row['crmcontact_id'] ?? 0));
    }

    private function canSeeThread($thread): bool {
        $myLevel = (int)$this->member->level;
        if ($myLevel <= LEVELS['ADMIN']) return true;
        return (int)$thread->member_id === (int)$this->member->id;
    }

    private function canMarkRead($thread): bool {
        $myLevel = (int)$this->member->level;
        if ($myLevel <= LEVELS['ADMIN']) return true;
        return (int)$thread->member_id === (int)$this->member->id;
    }

    private function canAccessContact($contact): bool {
        if (!$contact->id) return false;
        $myLevel = (int)$this->member->level;
        if ($myLevel <= LEVELS['ADMIN']) return true;
        return (int)$contact->member_id === (int)$this->member->id;
    }

    private function memberDisplayName(): string {
        if (!$this->member) return 'MyCTOBot';
        if (method_exists($this->member, 'displayName')) {
            $name = (string)$this->member->displayName();
            if ($name !== '') return $name;
        }
        $parts = array_filter([$this->member->first_name ?? '', $this->member->last_name ?? '']);
        $joined = trim(implode(' ', $parts));
        return $joined !== '' ? $joined : ((string)($this->member->username ?? 'MyCTOBot'));
    }

    /** Allowlist tag-strip + anchor sanitize. Same set dealerportal uses. */
    private function sanitizeHtmlBody(string $html): string {
        $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><a><h1><h2><h3><h4><span><div><blockquote>';
        $clean = strip_tags($html, $allowed);
        return preg_replace_callback('/<a\s+([^>]*?)>/i', function ($m) {
            if (preg_match('/href="(https?:\/\/[^"]+)"/i', $m[1], $h)) {
                return '<a href="' . $h[1] . '" target="_blank" rel="noopener">';
            }
            return '';
        }, $clean);
    }

    /**
     * Save files posted in `attachments[]` to disk. For new threads the
     * threadId is "new-{contactId}" and the directory is renamed after the
     * thread gets its real ID — here we just stash under a temp dir.
     * Hard cap 25MB total (Mailgun free-tier ceiling).
     *
     * @return array<int, array{filename:string, disk_path:string, web_path:string, mime_type:string, size_bytes:int}>
     */
    private function saveReplyAttachments(array $ctx): array {
        $files = $_FILES['attachments'] ?? null;
        if (empty($files) || !is_array($files['name'] ?? null)) return [];

        $count = count($files['name']);
        if ($count === 0) return [];

        $threadKey = $ctx['threadId'];
        $webDir  = "/uploads/outbound-mail/{$threadKey}";
        $diskDir = 'public' . $webDir;
        if (!is_dir($diskDir) && !mkdir($diskDir, 0755, true)) return [];

        $saved = [];
        $totalBytes = 0;
        $maxTotal = 25 * 1024 * 1024;
        $banned = ['php', 'phtml', 'phar', 'pl', 'py', 'sh', 'cgi', 'js', 'jsp', 'asp', 'aspx', 'exe', 'bat', 'cmd'];

        for ($i = 0; $i < $count; $i++) {
            $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err !== UPLOAD_ERR_OK)      continue;

            $size = (int)($files['size'][$i] ?? 0);
            if ($size <= 0) continue;
            if ($totalBytes + $size > $maxTotal) break;
            $totalBytes += $size;

            $origName = $files['name'][$i] ?? 'file';
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (in_array($ext, $banned, true)) continue;

            $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $origName);
            $filename = bin2hex(random_bytes(4)) . '-' . $safeName;
            $diskPath = $diskDir . '/' . $filename;
            $webPath  = $webDir  . '/' . $filename;

            if (!move_uploaded_file($files['tmp_name'][$i], $diskPath)) continue;

            $saved[] = [
                'filename'   => $origName,
                'disk_path'  => $diskPath,
                'web_path'   => $webPath,
                'mime_type'  => $files['type'][$i] ?? 'application/octet-stream',
                'size_bytes' => $size,
            ];
        }
        return $saved;
    }

    /**
     * Either persist attachment rows (on successful send) or clean up the
     * temp files (on failed send). Called once per send() return.
     */
    private function persistAttachments(array $result, array $saved): void {
        if ($result['success'] && !empty($result['notify_id']) && !empty($saved)) {
            foreach ($saved as $a) {
                $att = Bean::dispense('notifyattachment');
                $att->notify_id  = (int)$result['notify_id'];
                $att->thread_id  = (int)($result['thread_id'] ?? 0);
                $att->filename   = $a['filename'];
                $att->file_path  = $a['web_path'];
                $att->mime_type  = $a['mime_type'];
                $att->size_bytes = (int)$a['size_bytes'];
                $att->created_at = date('Y-m-d H:i:s');
                Bean::store($att);
            }
        } elseif (!$result['success']) {
            foreach ($saved as $a) @unlink($a['disk_path']);
        }
    }

    /**
     * Record a crmtouch row for an outbound email send so it shows on the
     * contact's timeline (/crm/view/{id}). Also bumps last_touch_at so
     * stale-contact lists reflect the latest activity.
     *
     * The crmcontact_id, member_id, touch_type, touch_date, summary,
     * outcome, duration, source columns already exist on the crmtouch
     * table (created by /crm logtouch). We add:
     *   emailthread_id — FK link to emailthread so the timeline entry can
     *                    link back to the full conversation.
     */
    private function logEmailTouch($contact, int $threadId, string $subject, string $bodyHtml): void {
        if (!$contact || !$contact->id) return;

        $preview = trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $preview = preg_replace('/\s+/u', ' ', $preview);
        // Keep the summary compact — subject line + trimmed body preview.
        $summary = $subject;
        if ($preview !== '') {
            $summary .= "\n" . mb_substr($preview, 0, 500, 'UTF-8');
        }

        try {
            $touch = Bean::dispense('crmtouch');
            $touch->crmcontact_id  = (int)$contact->id;
            $touch->member_id      = (int)$this->member->id;
            $touch->touch_type     = 'email';
            $touch->touch_date     = date('Y-m-d H:i:s');
            $touch->summary        = $summary;
            $touch->outcome        = '';
            $touch->duration       = null;
            $touch->source         = 'auto_email';
            // FK to emailthread — powers the "View thread" link on the timeline.
            if ($threadId > 0) {
                $touch->emailthread_id = $threadId;
            }
            Bean::store($touch);

            $contact->last_touch_at = date('Y-m-d H:i:s');
            Bean::store($contact);
        } catch (\Throwable $e) {
            // Don't fail the whole send just because timeline logging hiccuped.
            $this->logger?->warning('Communications: failed to log email touch', [
                'contact_id' => (int)$contact->id,
                'thread_id'  => $threadId,
                'err'        => $e->getMessage(),
            ]);
        }
    }

    /** Gmail-style quoted history block appended to replies when asked. */
    private function buildQuotedHistory(int $threadId): string {
        $rows = Bean::getAll(
            'SELECT direction, from_email, from_name, subject, content, body_plain, sent_at, created_at
             FROM notify
             WHERE thread_id = ?
             ORDER BY created_at DESC',
            [$threadId]
        );
        if (empty($rows)) return '';

        $html  = '<br><br><div style="color:#666; font-size:13px; border-left:3px solid #ccc; padding-left:12px; margin-top:24px;">';
        $html .= '<div style="font-weight:600; margin-bottom:6px;">----- Previous messages -----</div>';

        foreach ($rows as $r) {
            $who = trim((string)($r['from_name'] ?? '')) ?: ($r['from_email'] ?? '—');
            $ts  = $r['sent_at'] ?: ($r['created_at'] ?? '');
            $when = $ts ? date('M j, Y g:ia', strtotime((string)$ts)) : '—';
            $arrow = ($r['direction'] ?? 'out') === 'out' ? '&rarr;' : '&larr;';
            $body = (string)($r['content'] ?? '');
            if (!empty($r['body_plain']) && strlen((string)$r['body_plain']) < strlen($body)) {
                $body = nl2br(htmlspecialchars((string)$r['body_plain'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $html .= '<div style="margin:14px 0;">';
            $html .= '<div style="font-size:12px; color:#999; margin-bottom:4px;">'
                   . htmlspecialchars("On {$when}, {$who}", ENT_QUOTES | ENT_HTML5, 'UTF-8')
                   . " {$arrow} wrote:</div>";
            $html .= '<div>' . $body . '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}
