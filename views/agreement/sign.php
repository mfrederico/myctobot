<style>
    .agreement-container { max-width: 800px; margin: 0 auto; padding: 24px; }
    .agreement-text {
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 2rem;
        max-height: 500px;
        overflow-y: auto;
        margin-bottom: 2rem;
        font-size: 0.9rem;
        line-height: 1.7;
    }
    .signature-section {
        background: #fff;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 2rem;
    }
    .signature-pad-wrapper {
        border: 2px dashed #dee2e6;
        border-radius: 8px;
        background: #fafafa;
        position: relative;
        margin-bottom: 1rem;
    }
    .signature-pad-wrapper canvas {
        width: 100%;
        height: 150px;
        display: block;
    }
    .signature-pad-wrapper .placeholder-text {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        color: #adb5bd;
        font-size: 0.9rem;
        pointer-events: none;
        transition: opacity 0.2s;
    }
    .signature-pad-wrapper.has-signature .placeholder-text { opacity: 0; }
    .clear-sig-btn {
        position: absolute;
        top: 8px;
        right: 8px;
        font-size: 0.75rem;
        z-index: 5;
    }
    .agreement-badge {
        display: inline-block;
        background: #e8f5e9;
        color: #2e7d32;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }
</style>

<div class="agreement-container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1">Sales Referral Agreement</h3>
            <p class="text-muted mb-0">Please read the agreement below and sign to get started.</p>
        </div>
        <span class="agreement-badge">v<?= htmlspecialchars($agreementVersion) ?></span>
    </div>

    <?php foreach ($_SESSION['flash'] ?? [] as $flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= htmlspecialchars($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; unset($_SESSION['flash']); ?>

    <!-- Agreement Text -->
    <div class="agreement-text" id="agreement-text">
        <?= $agreementHtml ?>
    </div>

    <!-- Signature Section -->
    <div class="signature-section">
        <form method="POST" action="/agreement/sign" id="agreement-form">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
            <input type="hidden" name="signature_data" id="signature-data" value="">

            <div class="row g-3">
                <!-- Typed Name -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Full Legal Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="typed_name" id="typed-name"
                           placeholder="Type your full name" required
                           value="<?= htmlspecialchars(trim(($member->firstName ?? '') . ' ' . ($member->lastName ?? ''))) ?>">
                </div>

                <!-- Date (read-only) -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Date</label>
                    <input type="text" class="form-control" value="<?= date('M j, Y') ?>" readonly>
                </div>

                <!-- IP (read-only) -->
                <div class="col-md-3">
                    <label class="form-label fw-semibold">IP Address</label>
                    <input type="text" class="form-control text-muted" value="<?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '') ?>" readonly style="font-size:0.85rem;">
                </div>

                <!-- Signature Pad -->
                <div class="col-12">
                    <label class="form-label fw-semibold">Signature <span class="text-danger">*</span></label>
                    <div class="signature-pad-wrapper" id="sig-wrapper">
                        <canvas id="signature-canvas"></canvas>
                        <span class="placeholder-text">Draw your signature here</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary clear-sig-btn" onclick="clearSignature()">Clear</button>
                    </div>
                </div>

                <!-- Agreement Checkbox -->
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="agreed" value="1" id="agreed-check" required>
                        <label class="form-check-label" for="agreed-check">
                            I have read and agree to the terms of the Sales Referral Agreement (Version <?= htmlspecialchars($agreementVersion) ?>). I understand this is a binding agreement.
                        </label>
                    </div>
                </div>

                <!-- Submit -->
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-lg w-100" id="sign-btn" disabled>
                        <i class="fas fa-signature me-2"></i>Sign Agreement
                    </button>
                    <div class="text-center mt-2">
                        <small class="text-muted">
                            <i class="fas fa-lock me-1"></i>Your signature, IP address, and timestamp are recorded as a legally binding electronic signature under the ESIGN Act.
                        </small>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.2.0/dist/signature_pad.umd.min.js"></script>
<script>
(function() {
    var canvas = document.getElementById('signature-canvas');
    var wrapper = document.getElementById('sig-wrapper');
    var signBtn = document.getElementById('sign-btn');
    var agreedCheck = document.getElementById('agreed-check');
    var typedName = document.getElementById('typed-name');
    var signatureDataInput = document.getElementById('signature-data');
    var form = document.getElementById('agreement-form');

    // Size canvas properly
    function resizeCanvas() {
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        signaturePad.clear();
    }

    var signaturePad = new SignaturePad(canvas, {
        backgroundColor: 'rgb(250, 250, 250)',
        penColor: 'rgb(0, 0, 0)',
        minWidth: 1,
        maxWidth: 2.5,
    });

    signaturePad.addEventListener('beginStroke', function() {
        wrapper.classList.add('has-signature');
    });

    window.clearSignature = function() {
        signaturePad.clear();
        wrapper.classList.remove('has-signature');
        checkReady();
    };

    function checkReady() {
        var hasName = typedName.value.trim().length > 0;
        var hasSig = !signaturePad.isEmpty();
        var hasAgreed = agreedCheck.checked;
        signBtn.disabled = !(hasName && hasSig && hasAgreed);
    }

    typedName.addEventListener('input', checkReady);
    agreedCheck.addEventListener('change', checkReady);
    signaturePad.addEventListener('endStroke', checkReady);

    // On submit, capture signature data
    form.addEventListener('submit', function(e) {
        if (signaturePad.isEmpty()) {
            e.preventDefault();
            alert('Please draw your signature.');
            return;
        }
        signatureDataInput.value = signaturePad.toDataURL('image/png');
        signBtn.disabled = true;
        signBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
    });

    // Resize on load and window resize
    window.addEventListener('resize', resizeCanvas);
    resizeCanvas();

    // Scroll indicator
    var textEl = document.getElementById('agreement-text');
    textEl.addEventListener('scroll', function() {
        if (textEl.scrollTop + textEl.clientHeight >= textEl.scrollHeight - 20) {
            textEl.style.borderColor = '#198754';
        }
    });
})();
</script>
