<?php
/**
 * Left rail — threads grouped by contact (company).
 *
 * @var array   $threads       Communications::loadThreadsForViewer() output
 * @var ?object $activeThread  Null on /communications, a thread on /communications/thread/{id}
 */

$activeId = $activeThread ? (int)$activeThread->id : 0;

// Mobile/tablet: when a thread is open, hide the list (back link returns here).
$listCol = $activeThread
    ? 'col-lg-4 d-none d-lg-block'
    : 'col-12 col-lg-4';

$avatarColor = function (string $name): string {
    $hash = crc32($name);
    $palette = ['#2563eb','#059669','#dc2626','#7c3aed','#ea580c','#0891b2','#be185d','#4d7c0f','#b45309'];
    return $palette[$hash % count($palette)];
};
$initials = function (string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $ini = '';
    foreach ($parts as $p) { if ($p !== '') $ini .= mb_strtoupper(mb_substr($p, 0, 1)); }
    return mb_substr($ini, 0, 2) ?: '.';
};
?>
<div class="<?= $listCol ?>">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent small fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="fas fa-inbox me-1 text-primary"></i>Threads</span>
            <span class="text-muted"><?= count($threads) ?> <?= count($threads) === 1 ? 'contact' : 'contacts' ?></span>
        </div>
        <div class="list-group list-group-flush comms-thread-list">
            <?php if (empty($threads)): ?>
                <div class="text-center text-muted small py-4 px-3">
                    No conversations yet.<br>
                    <span class="d-block mt-1">Start one from a contact's page.</span>
                </div>
            <?php else: ?>
                <?php foreach ($threads as $group): ?>
                    <div class="comms-contact-group px-3 py-2 small fw-semibold text-body-secondary border-bottom bg-body-tertiary d-flex align-items-center gap-2">
                        <i class="fas fa-building text-muted"></i>
                        <span class="flex-grow-1 text-truncate">
                            <?= htmlspecialchars($group['contact_name']) ?>
                        </span>
                        <?php if ($group['unread_total'] > 0): ?>
                            <span class="badge bg-warning text-dark"><?= $group['unread_total'] ?> new</span>
                        <?php endif; ?>
                    </div>
                    <?php foreach ($group['threads'] as $t):
                        $isActive = (int)$t->id === $activeId;
                        $isUnread = (int)$t->unread_count > 0;
                        $color = $avatarColor($group['contact_name']);
                        $ini   = $initials($group['contact_name']);
                    ?>
                        <a href="/communications/thread/<?= (int)$t->id ?>"
                           class="comms-thread-row list-group-item list-group-item-action <?= $isActive ? 'active' : '' ?> <?= $isUnread ? 'unread' : '' ?>"
                           style="border-radius:0;">
                            <div class="d-flex align-items-start gap-2">
                                <span class="comms-unread-dot"></span>
                                <div class="d-flex align-items-center justify-content-center flex-shrink-0 rounded-circle text-white fw-semibold"
                                     style="width:36px; height:36px; background:<?= $color ?>; font-size:.75rem;" aria-hidden="true">
                                    <?= htmlspecialchars($ini) ?>
                                </div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="comms-thread-subject text-truncate">
                                            <?= htmlspecialchars($t->subject ?: '(no subject)') ?>
                                        </span>
                                        <span class="small text-<?= $isActive ? 'white-50' : 'muted' ?> flex-shrink-0">
                                            <?= $t->last_message_at ? date('M j', strtotime($t->last_message_at)) : '' ?>
                                        </span>
                                    </div>
                                    <div class="small text-<?= $isActive ? 'white-50' : 'body-secondary' ?> text-truncate mt-1">
                                        <?php if ($t->last_direction === 'in'): ?>
                                            <i class="fas fa-arrow-down me-1 text-success"></i>
                                        <?php else: ?>
                                            <i class="fas fa-arrow-up me-1 text-primary"></i>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($t->last_preview ?: '.') ?>
                                    </div>
                                </div>
                                <?php if ($isUnread): ?>
                                    <span class="badge bg-warning text-dark flex-shrink-0 align-self-center"><?= (int)$t->unread_count ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
