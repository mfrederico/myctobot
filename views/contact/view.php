<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">View Message</h1>
        <a href="/contact/admin" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to Messages
        </a>
    </div>

    <div class="row">
        <!-- Message Details -->
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><?= htmlspecialchars($message->subject) ?></h5>
                        <?php
                        $statusClass = [
                            'new' => 'primary',
                            'read' => 'info',
                            'responded' => 'success',
                            'closed' => 'dark',
                            'spam' => 'danger'
                        ][$message->status] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $statusClass ?>">
                            <?= ucfirst($message->status) ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <strong>From:</strong> <?= htmlspecialchars($message->name) ?>
                        &lt;<?= htmlspecialchars($message->email) ?>&gt;
                    </div>

                    <div class="mb-3">
                        <strong>Category:</strong>
                        <span class="badge bg-secondary"><?= ucfirst($message->category) ?></span>
                    </div>

                    <div class="mb-3">
                        <strong>Date:</strong>
                        <?= date('F j, Y \a\t g:i A', strtotime($message->created_at)) ?>
                    </div>

                    <?php if ($member && $member->id): ?>
                    <div class="mb-3">
                        <strong>Member:</strong>
                        <a href="/admin/members/edit?id=<?= $member->id ?>">
                            <?= htmlspecialchars($member->username) ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <hr>

                    <div class="message-content">
                        <?= nl2br(htmlspecialchars($message->message)) ?>
                    </div>
                </div>
            </div>

            <!-- Responses -->
            <?php if (!empty($responses)): ?>
                <h4 class="mb-3">Response History</h4>
                <?php foreach ($responses as $response): ?>
                    <?php
                    $admin = \app\Bean::load('member', $response->admin_id);
                    ?>
                    <div class="card mb-3">
                        <div class="card-header bg-success text-white">
                            <i class="bi bi-reply-fill"></i>
                            Response from <?= htmlspecialchars($admin->username ?? 'Admin') ?>
                            <small class="float-end">
                                <?= date('M j, Y \a\t g:i A', strtotime($response->created_at)) ?>
                            </small>
                        </div>
                        <div class="card-body">
                            <?= nl2br(htmlspecialchars($response->response)) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Response Form -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-reply"></i> Send Response
                </div>
                <div class="card-body">
                    <form method="POST" action="/contact/respond">
                        <?php
                        // Include CSRF token if available
                        if (isset($csrf) && is_array($csrf)):
                            foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach;
                        endif;
                        ?>

                        <input type="hidden" name="message_id" value="<?= $message->id ?>">

                        <div class="mb-3">
                            <label for="response" class="form-label">Your Response</label>
                            <textarea class="form-control"
                                      id="response"
                                      name="response"
                                      rows="6"
                                      required
                                      placeholder="Type your response here..."></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="status" class="form-label">Update Status To</label>
                            <select class="form-select" id="status" name="status">
                                <option value="responded" selected>Responded</option>
                                <option value="closed">Closed</option>
                                <option value="read">Keep as Read</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send"></i> Send Response
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar Info -->
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-info-circle"></i> Additional Information
                </div>
                <div class="card-body">
                    <p><strong>IP Address:</strong><br>
                    <small><?= htmlspecialchars($message->ip_address ?: 'Not recorded') ?></small></p>

                    <p><strong>User Agent:</strong><br>
                    <small><?= htmlspecialchars(substr($message->user_agent ?: 'Not recorded', 0, 100)) ?></small></p>

                    <?php if ($message->read_at): ?>
                    <p><strong>First Read:</strong><br>
                    <small><?= date('M j, Y \a\t g:i A', strtotime($message->read_at)) ?></small></p>
                    <?php endif; ?>

                    <?php if ($message->responded_at): ?>
                    <p><strong>First Response:</strong><br>
                    <small><?= date('M j, Y \a\t g:i A', strtotime($message->responded_at)) ?></small></p>
                    <?php endif; ?>

                    <hr>

                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-info" onclick="updateStatus('read')">
                            <i class="bi bi-envelope-open"></i> Mark as Read
                        </button>
                        <button class="btn btn-outline-success" onclick="updateStatus('closed')">
                            <i class="bi bi-check-circle"></i> Close Ticket
                        </button>
                        <button class="btn btn-outline-danger" onclick="updateStatus('spam')">
                            <i class="bi bi-exclamation-triangle"></i> Mark as Spam
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.message-content {
    background-color: #f8f9fa;
    padding: 15px;
    border-radius: 5px;
    white-space: pre-wrap;
    word-wrap: break-word;
}
</style>

<script>
function updateStatus(status) {
    if (confirm(`Mark this message as ${status}?`)) {
        fetch('/contact/status', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `id=<?= $message->id ?>&status=${status}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.error);
            }
        });
    }
}
</script>