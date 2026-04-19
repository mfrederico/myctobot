<?php
/**
 * Customer Support Verification View
 *
 * Order + Email verification form for customer support chat.
 * Supports embed mode for iframe integration.
 */

$embedMode = $embed ?? false;
$shopName = $shop_info['shop_name'] ?? 'Store';
$shopDomain = $shop_info['shop_domain'] ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Support - <?= h($shopName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%);
        }
        body {
            background: <?= $embedMode ? 'transparent' : 'linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%)' ?>;
            min-height: <?= $embedMode ? 'auto' : '100vh' ?>;
            <?php if (!$embedMode): ?>
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            <?php endif; ?>
        }
        .verify-card {
            max-width: 420px;
            width: 100%;
            margin: 0 auto;
        }
        .card {
            background: rgba(33, 37, 41, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }
        .card-header {
            background: var(--primary-gradient);
            border-radius: 16px 16px 0 0 !important;
            padding: 1.5rem;
            text-align: center;
        }
        .card-header h4 {
            margin: 0;
            font-weight: 600;
        }
        .shop-badge {
            background: rgba(255, 255, 255, 0.15);
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.85rem;
            display: inline-block;
            margin-top: 0.5rem;
        }
        .form-control {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            font-size: 1rem;
        }
        .form-control:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: #0d6efd;
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.15);
        }
        .form-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: #adb5bd;
        }
        .btn-primary {
            background: var(--primary-gradient);
            border: none;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            border-radius: 10px;
            width: 100%;
        }
        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        .btn-primary:disabled {
            opacity: 0.6;
        }
        .help-text {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
        .alert {
            border-radius: 10px;
            border: none;
        }
        .loading-spinner {
            display: none;
        }
        .loading .loading-spinner {
            display: inline-block;
        }
        .loading .btn-text {
            display: none;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .verify-card {
            animation: fadeIn 0.3s ease-out;
        }
    </style>
</head>
<body>
    <div class="verify-card">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-chat-dots-fill" style="font-size: 2rem;"></i>
                <h4 class="mt-2">Customer Support</h4>
                <div class="shop-badge">
                    <i class="bi bi-shop me-1"></i>
                    <?= h($shopName) ?>
                </div>
            </div>
            <div class="card-body p-4">
                <p class="text-center text-muted mb-4">
                    To help you with your order, please verify your details below.
                </p>

                <div id="error-alert" class="alert alert-danger d-none" role="alert">
                    <i class="bi bi-exclamation-circle me-2"></i>
                    <span id="error-message"></span>
                </div>

                <form id="verify-form">
                    <input type="hidden" name="widget_token" value="<?= h($widget_token) ?>">

                    <div class="mb-3">
                        <label for="order_number" class="form-label">
                            <i class="bi bi-hash me-1"></i>Order Number
                        </label>
                        <input type="text"
                               class="form-control"
                               id="order_number"
                               name="order_number"
                               placeholder="e.g., 1001 or #1001"
                               required
                               autocomplete="off">
                        <div class="help-text">Find this in your order confirmation email</div>
                    </div>

                    <div class="mb-4">
                        <label for="email" class="form-label">
                            <i class="bi bi-envelope me-1"></i>Email Address
                        </label>
                        <input type="email"
                               class="form-control"
                               id="email"
                               name="email"
                               placeholder="you@example.com"
                               required
                               autocomplete="email">
                        <div class="help-text">The email you used for your order</div>
                    </div>

                    <button type="submit" class="btn btn-primary" id="submit-btn">
                        <span class="btn-text">
                            <i class="bi bi-shield-check me-2"></i>Verify & Start Chat
                        </span>
                        <span class="loading-spinner">
                            <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                            Verifying...
                        </span>
                    </button>
                </form>
            </div>
            <div class="card-footer text-center py-3" style="background: transparent; border-top: 1px solid rgba(255,255,255,0.1);">
                <small class="text-muted">
                    <i class="bi bi-lock-fill me-1"></i>
                    Secure connection &bull; Your data is protected
                </small>
            </div>
        </div>
    </div>

    <script>
        const form = document.getElementById('verify-form');
        const submitBtn = document.getElementById('submit-btn');
        const errorAlert = document.getElementById('error-alert');
        const errorMessage = document.getElementById('error-message');
        const embedMode = <?= $embedMode ? 'true' : 'false' ?>;

        function showError(msg) {
            errorMessage.textContent = msg;
            errorAlert.classList.remove('d-none');
            errorAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function hideError() {
            errorAlert.classList.add('d-none');
        }

        function setLoading(loading) {
            submitBtn.disabled = loading;
            submitBtn.classList.toggle('loading', loading);
        }

        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            hideError();
            setLoading(true);

            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());

            try {
                const response = await fetch('/customersupport/verify', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (result.success) {
                    // Notify parent window of session start (for widget persistence)
                    if (embedMode && window.parent !== window) {
                        window.parent.postMessage({
                            type: 'myctobot-session-started',
                            sessionToken: result.data.session_token
                        }, '*');
                    }

                    // Redirect to chat with session token
                    const chatUrl = '/customersupport/chat/' + result.data.session_token +
                                   (embedMode ? '?embed=1' : '');
                    window.location.href = chatUrl;
                } else {
                    showError(result.error || 'Verification failed. Please try again.');
                    setLoading(false);
                }
            } catch (err) {
                console.error('Verification error:', err);
                showError('Connection error. Please try again.');
                setLoading(false);
            }
        });

        // Focus on order number field
        document.getElementById('order_number').focus();
    </script>
</body>
</html>
