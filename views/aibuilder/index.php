<?php
/**
 * AI Builder terminal view. Renders an xterm.js terminal wired to the jailed agent
 * over a same-origin WebSocket, with Checkpoint/Rollback controls.
 *
 * Vars: $ab_app, $ab_sub, $ab_token, $ab_wspath, $ab_hasInstance
 */
$csrf = htmlspecialchars(\Flight::csrf()->getToken(), ENT_QUOTES, 'UTF-8');
$t = fn($s) => function_exists('t') ? t($s) : $s;
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/css/xterm.min.css">

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
    <div>
        <h1 class="fw-bold mb-0"><i class="bi bi-robot me-2"></i>AI Builder</h1>
        <p class="text-body-secondary mb-0">Customize your software with AI. Everything is sandboxed — you can roll back any change.</p>
    </div>
    <?php if ($ab_hasInstance): ?>
    <div class="d-flex gap-2 flex-wrap">
        <button id="ab-checkpoint" class="btn btn-outline-secondary"><i class="bi bi-bookmark-plus me-1"></i>Checkpoint</button>
        <button id="ab-rollback" class="btn btn-outline-warning"><i class="bi bi-arrow-counterclockwise me-1"></i>Roll back</button>
        <span id="ab-status" class="badge text-bg-secondary align-self-center">Connecting…</span>
    </div>
    <?php endif; ?>
</div>

<?php if (!$ab_hasInstance): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>
        The AI Builder is not enabled for this workspace yet. Contact us to have your dedicated instance provisioned.
    </div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div id="ab-terminal" style="height:70vh;width:100%;background:#1e1e1e;border-radius:.375rem;padding:8px;"></div>
        </div>
    </div>
    <p class="text-body-secondary small mt-2 mb-0">
        <i class="bi bi-shield-lock me-1"></i>
        This terminal runs inside an isolated sandbox limited to your instance. It cannot reach other customers or our systems.
    </p>

    <div class="modal fade" id="ab-rollback-modal" tabindex="-1">
      <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Roll back to a checkpoint</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-body-secondary">This restores your code AND data to the chosen checkpoint. Anything after it is undone (but kept recoverable).</p>
          <select id="ab-ckpt-list" class="form-select"></select>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button id="ab-rollback-confirm" class="btn btn-warning">Roll back</button>
        </div>
      </div></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/lib/xterm.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@xterm/addon-fit@0.10.0/lib/addon-fit.min.js"></script>
    <script>
    (function () {
      const TOKEN = <?= json_encode($ab_token) ?>;
      const WSPATH = <?= json_encode($ab_wspath) ?>;
      const CSRF = <?= json_encode($csrf) ?>;
      const statusEl = document.getElementById('ab-status');
      const setStatus = (txt, cls) => { statusEl.textContent = txt; statusEl.className = 'badge align-self-center text-bg-' + cls; };

      const term = new Terminal({
        cursorBlink: true, fontSize: 13,
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
        theme: { background: '#1e1e1e' }
      });
      const fit = new FitAddon.FitAddon();
      term.loadAddon(fit);
      term.open(document.getElementById('ab-terminal'));
      fit.fit();

      const proto = location.protocol === 'https:' ? 'wss' : 'ws';
      const ws = new WebSocket(proto + '://' + location.host + WSPATH + '?token=' + encodeURIComponent(TOKEN));

      ws.onopen = () => { setStatus('Connected', 'success'); ws.send(JSON.stringify({ type: 'resize', cols: term.cols, rows: term.rows })); };
      ws.onmessage = (e) => term.write(typeof e.data === 'string' ? e.data : new Uint8Array(e.data));
      ws.onclose = () => setStatus('Disconnected', 'secondary');
      ws.onerror = () => setStatus('Error', 'danger');

      term.onData((d) => { if (ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify({ type: 'input', data: d })); });
      const sendResize = () => { fit.fit(); if (ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify({ type: 'resize', cols: term.cols, rows: term.rows })); };
      window.addEventListener('resize', sendResize);

      const post = (url, body) => fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams(Object.assign({ csrf_token: CSRF }, body || {})).toString()
      }).then(r => r.json());

      document.getElementById('ab-checkpoint').addEventListener('click', function () {
        const btn = this; btn.disabled = true;
        post('/aibuilder/checkpoint', {}).then(j => {
          term.write('\r\n\x1b[36m[checkpoint] ' + (j.checkpoint || (j.success ? 'saved' : 'failed')) + '\x1b[0m\r\n');
        }).finally(() => { btn.disabled = false; });
      });

      const modalEl = document.getElementById('ab-rollback-modal');
      const modal = new bootstrap.Modal(modalEl);
      document.getElementById('ab-rollback').addEventListener('click', function () {
        fetch('/aibuilder/checkpoints', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(r => r.json()).then(j => {
            const sel = document.getElementById('ab-ckpt-list'); sel.innerHTML = '';
            (j.checkpoints || []).forEach(c => { const o = document.createElement('option'); o.value = c.name; o.textContent = c.name + '  (' + c.date + ')'; sel.appendChild(o); });
            if (!sel.options.length) { const o = document.createElement('option'); o.value = 'checkpoint-baseline'; o.textContent = 'checkpoint-baseline'; sel.appendChild(o); }
            modal.show();
          });
      });
      document.getElementById('ab-rollback-confirm').addEventListener('click', function () {
        const ckpt = document.getElementById('ab-ckpt-list').value; this.disabled = true;
        post('/aibuilder/rollback/' + encodeURIComponent(ckpt), {}).then(j => {
          term.write('\r\n\x1b[33m[rollback] ' + (j.success ? 'restored ' + ckpt : 'failed') + '\x1b[0m\r\n'); modal.hide();
        }).finally(() => { this.disabled = false; });
      });
    })();
    </script>
<?php endif; ?>
