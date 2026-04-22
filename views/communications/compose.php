<?php
/**
 * New-message composer — launched from a contact's page to start a thread.
 *
 * @var object $contact
 * @var array  $threads
 * @var array  $csrf
 */

$csrfToken = $csrf['token'] ?? ($_SESSION['csrf_token'] ?? '');

$contactDisplay = trim((string)($contact->company_name ?? ''));
if ($contactDisplay === '') $contactDisplay = trim(((string)($contact->first_name ?? '')) . ' ' . ((string)($contact->last_name ?? '')));
if ($contactDisplay === '') $contactDisplay = 'Contact';

$contactEmail = (string)($contact->company_email ?? '');

$greetingName = trim((string)($contact->first_name ?? ''));
if ($greetingName === '') $greetingName = $contactDisplay;

$repLabel = '';
if (isset($member)) {
    $repLabel = trim(((string)($member->first_name ?? '')) . ' ' . ((string)($member->last_name ?? '')));
    if ($repLabel === '') $repLabel = (string)($member->username ?? '');
}
?>

<?php include __DIR__ . '/_styles.php'; ?>

<div class="comms-hub container-fluid py-4">

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="/crm/view/<?= (int)$contact->id ?>" class="btn btn-sm btn-link text-decoration-none p-1">
        <i class="fas fa-arrow-left"></i> Back to contact
    </a>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header">
                <i class="fas fa-paper-plane me-1 text-primary"></i>
                Email <strong><?= htmlspecialchars($contactDisplay) ?></strong>
                <?php if ($contactEmail !== ''): ?>
                    <span class="text-muted small">&middot; <?= htmlspecialchars($contactEmail) ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($contactEmail === ''): ?>
                    <div class="alert alert-warning mb-0">
                        No email address on file for this contact. <a href="/crm/edit/<?= (int)$contact->id ?>">Add one</a> to send a message.
                    </div>
                <?php else: ?>
                    <?php include __DIR__ . '/_mail-config-banner.php'; ?>
                    <form method="POST" action="/communications/compose/<?= (int)$contact->id ?>" id="comms-compose-form" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <div class="mb-3">
                            <label class="form-label small mb-1">Subject</label>
                            <input type="text" name="subject" class="form-control" value="<?= htmlspecialchars($_GET['subject'] ?? '') ?>" placeholder="Subject line">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small mb-1">Message</label>
                            <?php $prefillBody = $_GET['body'] ?? ''; ?>
                            <textarea name="message" id="comms-compose-message" rows="10" class="form-control"><?php if ($prefillBody): ?><p>Hi <?= htmlspecialchars($greetingName) ?>,</p>
<p><?= htmlspecialchars($prefillBody) ?></p>
<p>Thanks,<br><?= htmlspecialchars($repLabel) ?></p><?php else: ?><p>Hi <?= htmlspecialchars($greetingName) ?>,</p>
<p></p>
<p>Thanks,<br><?= htmlspecialchars($repLabel) ?></p><?php endif; ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small mb-1 d-flex align-items-center gap-2">
                                <i class="fas fa-paperclip"></i>Attachments
                                <span class="text-muted fw-normal">(optional)</span>
                            </label>
                            <input type="file" name="attachments[]" class="form-control form-control-sm" multiple accept="*/*">
                            <div class="form-text">Max 25 MB total.</div>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <a href="/crm/view/<?= (int)$contact->id ?>" class="btn btn-outline-secondary">Cancel</a>
                            <button class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>Send</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(function () {
    var ta = document.getElementById('comms-compose-message');
    if (!ta || typeof tinymce === 'undefined') return;
    tinymce.init({
        target: ta, license_key: 'gpl',
        menubar: false, branding: false, statusbar: false, height: 300,
        plugins: 'lists link autolink',
        toolbar: 'undo redo | bold italic underline | bullist numlist | link | removeformat',
        content_style: 'body { font-family: -apple-system, Segoe UI, sans-serif; font-size: 14px; }',
        setup: function (ed) {
            var form = document.getElementById('comms-compose-form');
            if (form) form.addEventListener('submit', function () { ed.save(); });
            ed.on('init', function () {
                // Check for assistant-prefilled compose data
                try {
                    var prefill = sessionStorage.getItem('assist_compose');
                    if (prefill) {
                        sessionStorage.removeItem('assist_compose');
                        var data = JSON.parse(prefill);
                        if (data.subject) {
                            var subjectField = document.querySelector('input[name="subject"]');
                            if (subjectField) subjectField.value = data.subject;
                        }
                        if (data.body) {
                            var greeting = '<?= htmlspecialchars($greetingName, ENT_QUOTES) ?>';
                            var repName = '<?= htmlspecialchars($repLabel, ENT_QUOTES) ?>';
                            ed.setContent(
                                '<p>Hi ' + greeting + ',</p>' +
                                '<p>' + data.body.replace(/\n/g, '<br>') + '</p>' +
                                '<p>Thanks,<br>' + repName + '</p>'
                            );
                        }
                    }
                } catch (e) {}

                var body = ed.getBody();
                var target = body ? body.querySelector('p:nth-child(2)') : null;
                if (target) ed.selection.setCursorLocation(target, 0);
                ed.focus();
            });
        }
    });
}());
</script>
