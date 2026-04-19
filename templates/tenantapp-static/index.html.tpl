<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{APP_NAME}}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root { --app-primary: {{THEME_COLOR}}; }
        .navbar { background-color: var(--app-primary) !important; }
        .btn-primary { background-color: var(--app-primary); border-color: var(--app-primary); }
        .screen { display: none; }
        .screen.active { display: block; }
        .chat-messages { display: flex; flex-direction: column; }
        .chat-message { max-width: 80%; margin-bottom: 0.5rem; }
        .chat-message.user { align-self: flex-end; }
        .chat-message.assistant { align-self: flex-start; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="#/">{{APP_NAME}}</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#appNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="appNav">
                <ul class="navbar-nav me-auto" id="app-nav">
                    {{NAV_ITEMS}}
                </ul>
            </div>
        </div>
    </nav>
    <div class="container">
        {{SCREEN_SECTIONS}}
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="./app-config.js"></script>
    <script>
    {{RUNTIME_JS}}
    </script>
</body>
</html>
