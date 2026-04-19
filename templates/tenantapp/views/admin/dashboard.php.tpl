<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .table-card { transition: transform 0.2s; cursor: pointer; }
        .table-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-primary mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="/admin?api_key=<?= urlencode($apiKey ?? '') ?>">
                <i class="bi bi-database me-2"></i>{{APP_NAME}} Admin
            </a>
            <a href="/" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to App
            </a>
        </div>
    </nav>

    <div class="container">
        <div class="row">
            <div class="col-12">
                <h4 class="mb-4"><i class="bi bi-table me-2"></i>Database Tables</h4>
            </div>
        </div>

        <div class="row g-4">
            <?php foreach ($tables as $table): ?>
            <div class="col-md-4 col-lg-3">
                <a href="/admin/table/<?= htmlspecialchars($table) ?>?api_key=<?= urlencode($apiKey ?? '') ?>"
                   class="card table-card text-decoration-none">
                    <div class="card-body">
                        <h5 class="card-title text-primary">
                            <i class="bi bi-table me-2"></i><?= htmlspecialchars($table) ?>
                        </h5>
                        <p class="card-text text-muted mb-0">
                            <span class="badge bg-secondary"><?= $tableCounts[$table] ?? 0 ?> rows</span>
                        </p>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>

            <?php if (empty($tables)): ?>
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>No tables found in the database.
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
