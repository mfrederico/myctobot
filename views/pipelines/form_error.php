<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error - Pipeline Input</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .error-card {
            max-width: 500px;
            margin: 0 auto;
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="error-card">
            <div class="card shadow-lg text-center">
                <div class="card-body py-5">
                    <i class="bi bi-exclamation-triangle display-1 text-danger mb-4"></i>
                    <h2 class="mb-3">Unable to Process</h2>
                    <p class="text-muted mb-4">
                        <?= htmlspecialchars($error ?? 'An error occurred') ?>
                    </p>
                    <p class="small text-muted">
                        If you believe this is an error, please contact support or try again with a new link.
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
