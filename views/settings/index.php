<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1"><i class="bi bi-gear"></i> Settings</h1>
            <p class="text-muted mb-0">Manage your workspace configuration</p>
        </div>
    </div>

    <div class="row g-4">
        <?php foreach ($sections as $section): ?>
        <div class="col-md-6 col-lg-4">
            <a href="<?= h($section['url']) ?>" class="text-decoration-none">
                <div class="card h-100 border-<?= $section['color'] ?> border-opacity-25 hover-shadow">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div class="rounded-circle bg-<?= $section['color'] ?> bg-opacity-10 p-3">
                                <i class="bi <?= $section['icon'] ?> fs-4 text-<?= $section['color'] ?>"></i>
                            </div>
                            <?php if ($section['badge']): ?>
                            <span class="badge <?= $section['badge_class'] ?>"><?= h($section['badge']) ?></span>
                            <?php endif; ?>
                        </div>
                        <h5 class="card-title text-dark"><?= h($section['title']) ?></h5>
                        <p class="card-text text-muted small mb-0"><?= h($section['description']) ?></p>
                    </div>
                    <div class="card-footer bg-transparent border-0 pt-0">
                        <span class="text-<?= $section['color'] ?> small">
                            Manage <i class="bi bi-arrow-right"></i>
                        </span>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<style>
.hover-shadow { transition: box-shadow 0.2s, transform 0.2s; }
.hover-shadow:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); transform: translateY(-2px); }
</style>
