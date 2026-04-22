<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="fas fa-columns"></i> Sales Pipeline</h1>
        <a href="/crm/create" class="btn btn-primary"><i class="fas fa-plus"></i> New Prospect</a>
    </div>

    <div class="row">
        <?php
        $stageConfig = [
            'cold' => ['label' => 'Cold', 'color' => 'secondary', 'icon' => 'fa-snowflake'],
            'warm' => ['label' => 'Warm', 'color' => 'warning', 'icon' => 'fa-temperature-half'],
            'hot' => ['label' => 'Hot', 'color' => 'danger', 'icon' => 'fa-fire'],
            'closing' => ['label' => 'Closing Crucible', 'color' => 'primary', 'icon' => 'fa-fire-flame-curved'],
        ];
        foreach ($stageConfig as $stage => $cfg):
            $contacts = $pipeline[$stage] ?? [];
        ?>
        <div class="col-md-3">
            <div class="card">
                <div class="card-header bg-<?= $cfg['color'] ?> <?= $cfg['color'] === 'warning' ? 'text-dark' : 'text-white' ?>">
                    <i class="fas <?= $cfg['icon'] ?>"></i> <?= $cfg['label'] ?>
                    <span class="badge bg-light text-dark float-end"><?= count($contacts) ?></span>
                </div>
                <div class="card-body p-2 pipeline-column" data-stage="<?= $stage ?>" style="min-height: 200px;">
                    <?php if (empty($contacts)): ?>
                        <p class="text-muted text-center small py-4">No prospects</p>
                    <?php else: ?>
                        <?php foreach ($contacts as $c): ?>
                            <div class="card mb-2 pipeline-card" data-id="<?= $c->id ?>">
                                <div class="card-body p-2">
                                    <a href="/crm/view/<?= $c->id ?>" class="text-decoration-none">
                                        <strong class="d-block"><?= h($c->fullName()) ?></strong>
                                    </a>
                                    <small class="text-muted"><?= h($c->companyName) ?></small>
                                    <div class="mt-1">
                                        <span class="badge bg-<?= $c->accountType === '3pl' ? 'info' : 'success' ?>"><?= strtoupper($c->accountType ?? '') ?></span>
                                        <?php foreach (array_slice($c->tagArray(), 0, 2) as $t): ?>
                                            <span class="badge bg-dark"><?= h($t) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($c->lastTouchAt): ?>
                                        <small class="text-muted d-block mt-1">Last touch: <?= date('M j', strtotime($c->lastTouchAt)) ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<style>
.pipeline-column { max-height: 70vh; overflow-y: auto; }
.pipeline-card { cursor: grab; transition: box-shadow 0.2s; }
.pipeline-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
.pipeline-card.dragging { opacity: 0.5; }
.pipeline-column.drag-over { background: rgba(13,110,253,0.05); border: 2px dashed #0d6efd; border-radius: 4px; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var csrfToken = '<?= $_SESSION['csrf_token'] ?? '' ?>';

    document.querySelectorAll('.pipeline-card').forEach(function(card) {
        card.draggable = true;
        card.addEventListener('dragstart', function(e) {
            e.dataTransfer.setData('text/plain', card.dataset.id);
            card.classList.add('dragging');
        });
        card.addEventListener('dragend', function() {
            card.classList.remove('dragging');
        });
    });

    document.querySelectorAll('.pipeline-column').forEach(function(col) {
        col.addEventListener('dragover', function(e) {
            e.preventDefault();
            col.classList.add('drag-over');
        });
        col.addEventListener('dragleave', function() {
            col.classList.remove('drag-over');
        });
        col.addEventListener('drop', function(e) {
            e.preventDefault();
            col.classList.remove('drag-over');
            var contactId = e.dataTransfer.getData('text/plain');
            var newStage = col.dataset.stage;
            var body = new URLSearchParams({ id: contactId, stage: newStage, _csrf_token: csrfToken });
            fetch('/crm/movestage', { method: 'POST', body: body })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) location.reload();
                    else alert(res.message);
                });
        });
    });
});
</script>
