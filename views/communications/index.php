<?php
/**
 * Communications hub — thread list only (no thread selected yet).
 *
 * @var array $threads
 */
?>

<?php include __DIR__ . '/_styles.php'; ?>

<div class="comms-hub container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 px-3 px-md-0">
    <div>
        <h1 class="h3 mb-0">Communications</h1>
        <span class="text-muted small">Email conversations with your CRM contacts</span>
    </div>
</div>

<div class="row g-3 g-md-3 g-0">
    <?php include __DIR__ . '/_thread-list.php'; ?>
    <div class="col-lg-8 d-none d-lg-block">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5 text-body-secondary">
                <i class="fas fa-comments" style="font-size:2.5rem;"></i>
                <div class="mt-2">Select a thread on the left to view messages.</div>
                <div class="small mt-1">Every email you send to a contact starts a thread. Replies arrive here via Mailgun.</div>
            </div>
        </div>
    </div>
</div>

</div><!-- /comms-hub -->

<script>
// Poll every 60s to keep unread badges + last-activity timestamps fresh.
(function () {
    async function tick() {
        if (document.hidden) return;
        try {
            var resp = await fetch('/communications/poll', {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
            if (!resp.ok) return;
            var data = await resp.json();
            if (!Array.isArray(data.threads)) return;
            data.threads.forEach(function (t) {
                var link = document.querySelector('a[href="/communications/thread/' + t.id + '"]');
                if (!link) return;
                var hasUnread = t.unread_count > 0;
                link.classList.toggle('unread', hasUnread);
                var badge = link.querySelector('.badge.bg-warning');
                if (hasUnread) {
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
        } catch (err) { /* retry next tick */ }
    }
    setInterval(tick, 60000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
}());
</script>
