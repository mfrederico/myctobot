<?php
/**
 * Thread detail — left rail + conversation feed + reply composer.
 *
 * @var array  $threads
 * @var object $activeThread
 * @var object $contact
 * @var object $rep
 * @var array  $messages    notify rows, DESC by created_at
 * @var array  $csrf        ['token' => '...', 'name' => 'csrf_token']
 */

$csrfToken = $csrf['token'] ?? ($_SESSION['csrf_token'] ?? '');

$repLabel = trim(((string)($rep->first_name ?? '')) . ' ' . ((string)($rep->last_name ?? '')));
if ($repLabel === '') $repLabel = (string)($rep->username ?? 'Rep');

$contactDisplay = trim(((string)($contact->company_name ?? '')));
if ($contactDisplay === '') $contactDisplay = trim(((string)($contact->first_name ?? '')) . ' ' . ((string)($contact->last_name ?? '')));
if ($contactDisplay === '') $contactDisplay = 'Contact';

$greetingName = trim(((string)($contact->first_name ?? '')));
if ($greetingName === '') $greetingName = $contactDisplay;
?>

<?php include __DIR__ . '/_styles.php'; ?>
<style>
    /* Desktop gets nested scroll regions so left/right scroll independently. */
    .comms-messages-body { overflow-y: auto; max-height: 55vh; }
    .comms-thread-list   { overflow-y: auto; max-height: 65vh; }
    @media (min-width: 992px) {
        .comms-messages-body { max-height: 60vh; }
        .comms-thread-list   { max-height: 72vh; }
    }
</style>

<div class="comms-hub container-fluid py-4">

<!-- Sticky actionbar: back, contact + subject (folded in so nothing's
     occluded when scrolling), Reply, overflow. Buttons wrap to a second
     row on narrow screens via flex-wrap. -->
<div class="comms-actionbar sticky-top bg-body border-bottom py-2 px-3 px-md-0 d-flex align-items-center flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2 flex-grow-1 min-w-0" style="flex-basis: 60%;">
        <a href="/communications" class="btn btn-sm btn-link text-decoration-none d-lg-none p-1 flex-shrink-0" title="Back to threads">
            <i class="fas fa-arrow-left fs-5"></i>
        </a>
        <div class="min-w-0 flex-grow-1">
            <div class="fw-semibold text-truncate"><?= htmlspecialchars($contactDisplay) ?></div>
            <div class="small text-muted text-truncate">
                <?= htmlspecialchars($activeThread->subject ?: '(no subject)') ?>
                &middot; <?= (int)$activeThread->message_count ?> msg<?= (int)$activeThread->message_count === 1 ? '' : 's' ?>
            </div>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 ms-auto flex-shrink-0">
        <button type="button" class="btn btn-primary btn-sm"
                data-bs-toggle="collapse" data-bs-target="#comms-reply-panel"
                aria-expanded="false" aria-controls="comms-reply-panel">
            <i class="fas fa-reply me-1"></i>Reply
        </button>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="More actions">
                <i class="fas fa-ellipsis-v"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <form method="POST" action="/communications/archive/<?= (int)$activeThread->id ?>" class="mb-0">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <button class="dropdown-item" type="submit">
                            <i class="fas fa-archive me-2"></i><?= (int)$activeThread->is_archived ? 'Unarchive' : 'Archive' ?>
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</div>

<div class="row g-3">
    <?php include __DIR__ . '/_thread-list.php'; ?>

    <div class="col-12 col-lg-8">
        <!-- Reply composer (collapsed; slides in from the top and pushes the feed
             down, matching the newest-first read direction). -->
        <div class="collapse mb-3" id="comms-reply-panel">
            <div class="card border-primary-subtle shadow-sm">
                <div class="card-header bg-primary-subtle small fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-reply me-1 text-primary"></i>Reply</span>
                    <button type="button" class="btn-close btn-sm"
                            data-bs-toggle="collapse" data-bs-target="#comms-reply-panel"
                            aria-label="Close reply"></button>
                </div>
                <div class="card-body">
                    <?php include __DIR__ . '/_mail-config-banner.php'; ?>
                    <form method="POST" action="/communications/reply/<?= (int)$activeThread->id ?>"
                          id="comms-reply-form" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <div class="mb-2">
                            <label class="form-label small mb-1">Subject</label>
                            <input type="text" name="subject" class="form-control form-control-sm"
                                   value="Re: <?= htmlspecialchars($activeThread->subject) ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">CC <span class="text-muted fw-normal">(optional, comma-separated)</span></label>
                            <input type="text" name="cc" class="form-control form-control-sm" placeholder="email@example.com, other@example.com">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">Message</label>
                            <textarea name="message" id="comms-reply-message" rows="6" class="form-control"><p>Hi <?= htmlspecialchars($greetingName) ?>,</p>
<p></p>
<p>Thanks,<br><?= htmlspecialchars($repLabel) ?></p></textarea>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1 d-flex align-items-center gap-2">
                                <i class="fas fa-paperclip"></i>Attachments
                                <span class="text-muted fw-normal">(optional)</span>
                            </label>
                            <input type="file" name="attachments[]" class="form-control form-control-sm" multiple accept="*/*">
                            <div class="form-text">Max 25 MB total.</div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="form-check form-check-sm mb-0">
                                <input type="checkbox" class="form-check-input" id="include-thread" name="include_thread" value="1">
                                <label class="form-check-label small text-muted" for="include-thread">Include previous messages below</label>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                        data-bs-toggle="collapse" data-bs-target="#comms-reply-panel">Cancel</button>
                                <button class="btn btn-primary btn-sm"><i class="fas fa-paper-plane me-1"></i>Send Reply</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body comms-messages-body p-2 p-md-3" id="comms-messages">
                <?php if (empty($messages)): ?>
                    <div class="text-muted small">No messages yet.</div>
                <?php endif; ?>
                <?php foreach ($messages as $m):
                    $isOut = ($m->direction ?? 'out') === 'out';
                    $fromLabel = $m->from_name ?: $m->from_email;
                    $ts = $m->sent_at ?: $m->created_at;
                ?>
                    <div class="d-flex mb-2 <?= $isOut ? 'justify-content-end' : '' ?>" data-msg-id="<?= (int)$m->id ?>">
                        <div class="comms-msg-bubble-wrap">
                            <div class="comms-msg-meta <?= $isOut ? 'text-end' : '' ?>">
                                <span class="fw-semibold"><?= htmlspecialchars($fromLabel) ?></span>
                                <?php if ($isOut): ?><i class="fas fa-arrow-up ms-1 text-primary"></i><?php endif; ?>
                                <?php if (!$isOut): ?><i class="fas fa-arrow-down ms-1 text-success"></i><?php endif; ?>
                                <span class="ms-2"><?= $ts ? date('M j, g:ia', strtotime($ts)) : '.' ?></span>
                                <?php if ($m->status === 'error'): ?>
                                    <span class="badge bg-danger-subtle text-danger ms-1" title="<?= htmlspecialchars($m->error_message ?? '') ?>">Failed</span>
                                <?php endif; ?>
                            </div>
                            <div class="comms-msg-bubble <?= $isOut ? 'out' : 'in' ?>">
                                <?php if (!empty($m->subject) && $m->subject !== $activeThread->subject): ?>
                                    <div class="small fw-semibold mb-2"><?= htmlspecialchars($m->subject) ?></div>
                                <?php endif; ?>
                                <div><?= $m->content ?></div>
                                <?php if (!empty($m->attachments)): ?>
                                    <div class="mt-2 pt-2 border-top border-secondary-subtle d-flex flex-wrap gap-2">
                                        <?php foreach ($m->attachments as $a):
                                            $sizeKb = max(1, (int)round(((int)$a['size_bytes']) / 1024));
                                        ?>
                                            <a href="<?= htmlspecialchars($a['file_path']) ?>" target="_blank" rel="noopener"
                                               class="d-inline-flex align-items-center gap-1 small text-decoration-none border rounded px-2 py-1">
                                                <i class="fas fa-paperclip"></i>
                                                <span><?= htmlspecialchars($a['filename']) ?></span>
                                                <span class="text-muted">(<?= $sizeKb ?> KB)</span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        // Live polling — new messages prepend (matches newest-first feed order).
        (function () {
            var threadId = <?= (int)$activeThread->id ?>;
            var feed = document.getElementById('comms-messages');

            var lastSeenId = 0;
            if (feed) {
                Array.prototype.forEach.call(feed.querySelectorAll('[data-msg-id]'), function (el) {
                    var n = parseInt(el.getAttribute('data-msg-id'), 10) || 0;
                    if (n > lastSeenId) lastSeenId = n;
                });
            }

            function escapeHtml(s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
                });
            }
            function formatTs(iso) {
                if (!iso) return '';
                var d = new Date(String(iso).replace(' ', 'T'));
                if (isNaN(d.getTime())) return iso;
                return d.toLocaleString(undefined, {month:'short', day:'numeric', hour:'numeric', minute:'2-digit'});
            }
            function renderMessage(m) {
                var isOut = m.direction === 'out';
                return '<div class="d-flex mb-2 ' + (isOut ? 'justify-content-end' : '') + '" data-msg-id="' + m.id + '">'
                     +   '<div class="comms-msg-bubble-wrap">'
                     +     '<div class="comms-msg-meta ' + (isOut ? 'text-end' : '') + '">'
                     +       '<span class="fw-semibold">' + escapeHtml(m.from_name) + '</span>'
                     +       (isOut ? ' <i class="fas fa-arrow-up ms-1 text-primary"></i>'
                                    : ' <i class="fas fa-arrow-down ms-1 text-success"></i>')
                     +       ' <span class="ms-2">' + escapeHtml(formatTs(m.ts)) + '</span>'
                     +     '</div>'
                     +     '<div class="comms-msg-bubble ' + (isOut ? 'out' : 'in') + '">'
                     +       '<div>' + (m.content || '') + '</div>'
                     +     '</div>'
                     +   '</div>'
                     + '</div>';
            }
            function applyThreads(threads) {
                threads.forEach(function (t) {
                    var link = document.querySelector('a[href="/communications/thread/' + t.id + '"]');
                    if (!link) return;
                    var badge = link.querySelector('.badge');
                    if (t.unread_count > 0) {
                        if (!badge) {
                            badge = document.createElement('span');
                            badge.className = 'badge bg-warning text-dark flex-shrink-0 align-self-center';
                            link.querySelector('.d-flex').appendChild(badge);
                        }
                        badge.textContent = t.unread_count;
                    } else if (badge) {
                        badge.remove();
                    }
                });
            }
            async function tick() {
                if (document.hidden) return;
                try {
                    var url = '/communications/poll?since_msg=' + encodeURIComponent(lastSeenId) + '&thread=' + encodeURIComponent(threadId);
                    var resp = await fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
                    if (!resp.ok) return;
                    var data = await resp.json();
                    if (Array.isArray(data.new_messages) && feed) {
                        data.new_messages.forEach(function (m) {
                            if (m.thread_id !== threadId) return;
                            if (m.id <= lastSeenId) return;
                            feed.insertAdjacentHTML('afterbegin', renderMessage(m));
                            if (m.id > lastSeenId) lastSeenId = m.id;
                        });
                    }
                    if (Array.isArray(data.threads)) applyThreads(data.threads);
                } catch (err) { /* next tick will retry */ }
            }
            setInterval(tick, 60000);
            document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
        }());
        </script>
        <script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
        <script>
        // Lazy-mount TinyMCE on first collapse expand.
        (function () {
            var panel = document.getElementById('comms-reply-panel');
            var textarea = document.getElementById('comms-reply-message');
            if (!panel || !textarea) return;

            var editor = null;
            panel.addEventListener('shown.bs.collapse', function () {
                window.scrollTo({top: 0, behavior: 'smooth'});
                if (editor) { editor.focus(); return; }
                if (typeof tinymce === 'undefined') return;
                tinymce.init({
                    target: textarea, license_key: 'gpl',
                    menubar: false, branding: false, statusbar: false, height: 240,
                    plugins: 'lists link autolink',
                    toolbar: 'undo redo | bold italic underline | bullist numlist | link | removeformat',
                    content_style: 'body { font-family: -apple-system, Segoe UI, sans-serif; font-size: 14px; }',
                    setup: function (ed) {
                        editor = ed;
                        var form = document.getElementById('comms-reply-form');
                        if (form) form.addEventListener('submit', function () { ed.save(); });
                        ed.on('init', function () {
                            var body = ed.getBody();
                            var target = body ? body.querySelector('p:nth-child(2)') : null;
                            if (target) ed.selection.setCursorLocation(target, 0);
                            ed.focus();
                        });
                    }
                });
            });
        }());
        </script>
    </div>
</div>

</div><!-- /comms-hub -->
