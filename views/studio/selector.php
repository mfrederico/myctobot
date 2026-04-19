<div class="container py-5">
    <div class="text-center mb-5">
        <h1 class="display-5 fw-bold mb-3">What are you building today?</h1>
        <p class="lead text-muted">Choose a studio to get a focused dashboard tailored to your workflow.</p>
    </div>

    <div class="row justify-content-center g-4">
        <?php foreach ($studios as $key => $studio): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100 studio-card border-0 shadow-sm" data-studio="<?= $key ?>">
                <div class="card-body p-4 text-center">
                    <div class="rounded-circle bg-<?= $studio['color'] ?> bg-opacity-10 d-inline-flex align-items-center justify-content-center mb-4" style="width: 80px; height: 80px;">
                        <i class="bi <?= $studio['icon'] ?> fs-1 text-<?= $studio['color'] ?>"></i>
                    </div>
                    <h4 class="card-title mb-2"><?= h($studio['name']) ?></h4>
                    <p class="text-muted mb-4"><?= h($studio['tagline']) ?></p>
                    <ul class="list-unstyled text-start mb-4">
                        <?php foreach ($studio['features'] as $feature): ?>
                        <li class="mb-2">
                            <i class="bi bi-check-circle-fill text-<?= $studio['color'] ?> me-2"></i>
                            <small><?= h($feature) ?></small>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="card-footer bg-transparent border-0 p-4 pt-0">
                    <button type="button" class="btn btn-<?= $studio['color'] ?> btn-lg w-100" onclick="selectStudio('<?= $key ?>')">
                        Get Started
                        <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="text-center mt-5">
        <p class="text-muted">
            <i class="bi bi-info-circle me-1"></i>
            You can switch studios anytime from the navigation menu or your profile settings.
        </p>
    </div>
</div>

<style>
.studio-card {
    transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
    cursor: pointer;
}
.studio-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.175) !important;
}
</style>

<script>
function selectStudio(studio) {
    // Show loading state on the clicked button
    const card = document.querySelector(`[data-studio="${studio}"]`);
    const btn = card.querySelector('button');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Loading...';
    btn.disabled = true;

    // Save preference and redirect
    fetch('/studio/setstudio', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ studio: studio })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = data.data.redirect;
        } else {
            alert('Error: ' + data.message);
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
}
</script>
