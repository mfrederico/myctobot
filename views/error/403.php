<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title ?? '403 - Forbidden') ?></title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
        }
        .error-container {
            text-align: center;
            padding: 2rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 500px;
        }
        h1 {
            color: #dc3545;
            font-size: 3rem;
            margin: 0 0 1rem 0;
        }
        h2 {
            color: #333;
            font-size: 1.5rem;
            margin: 0 0 1rem 0;
        }
        p {
            color: #666;
            margin: 1rem 0;
        }
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
            border: 1px solid #f5c6cb;
        }
        a {
            color: #007bff;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>403</h1>
        <h2>Access Forbidden</h2>
        
        <?php if (!empty($message)): ?>
            <div class="error-message">
                <?= h($message) ?>
            </div>
        <?php else: ?>
            <p>You don't have permission to access this resource.</p>
        <?php endif; ?>
        
        <p>
            <?php if (Flight::isLoggedIn()): ?>
                <a href="/">Go to homepage</a>
            <?php else: ?>
                <a href="/auth/login">Login</a> | 
                <a href="/">Go to homepage</a>
            <?php endif; ?>
        </p>
    </div>
</body>
</html>