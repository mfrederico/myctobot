<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">Contact Messages</h1>
        <div>
            <span class="badge bg-info"><?= $total ?> Total Messages</span>
        </div>
    </div>

    <!-- Status Filter -->
    <div class="btn-group mb-3" role="group">
        <a href="/contact/admin?status=all" class="btn btn-outline-secondary <?= $status === 'all' ? 'active' : '' ?>">
            All Messages
        </a>
        <a href="/contact/admin?status=new" class="btn btn-outline-primary <?= $status === 'new' ? 'active' : '' ?>">
            <i class="bi bi-envelope-fill"></i> New
        </a>
        <a href="/contact/admin?status=read" class="btn btn-outline-info <?= $status === 'read' ? 'active' : '' ?>">
            <i class="bi bi-envelope-open"></i> Read
        </a>
        <a href="/contact/admin?status=responded" class="btn btn-outline-success <?= $status === 'responded' ? 'active' : '' ?>">
            <i class="bi bi-reply-fill"></i> Responded
        </a>
        <a href="/contact/admin?status=closed" class="btn btn-outline-dark <?= $status === 'closed' ? 'active' : '' ?>">
            <i class="bi bi-check-circle"></i> Closed
        </a>
        <a href="/contact/admin?status=spam" class="btn btn-outline-danger <?= $status === 'spam' ? 'active' : '' ?>">
            <i class="bi bi-trash"></i> Spam
        </a>
    </div>

    <?php if (empty($messages)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> No messages found.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th width="30">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                        </th>
                        <th>Status</th>
                        <th>From</th>
                        <th>Subject</th>
                        <th>Category</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($messages as $msg): ?>
                        <tr class="<?= $msg->status === 'new' ? 'table-info' : '' ?>">
                            <td>
                                <input type="checkbox" class="form-check-input message-select" value="<?= $msg->id ?>">
                            </td>
                            <td>
                                <?php
                                $statusClass = [
                                    'new' => 'primary',
                                    'read' => 'info',
                                    'responded' => 'success',
                                    'closed' => 'dark',
                                    'spam' => 'danger'
                                ][$msg->status] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?= $statusClass ?>">
                                    <?= ucfirst($msg->status) ?>
                                </span>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($msg->name) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($msg->email) ?></small>
                            </td>
                            <td>
                                <a href="/contact/view?id=<?= $msg->id ?>" class="text-decoration-none">
                                    <?= htmlspecialchars(substr($msg->subject, 0, 50)) ?>
                                    <?= strlen($msg->subject) > 50 ? '...' : '' ?>
                                </a>
                            </td>
                            <td>
                                <span class="badge bg-secondary">
                                    <?= ucfirst($msg->category) ?>
                                </span>
                            </td>
                            <td>
                                <small>
                                    <?= date('M j, Y', strtotime($msg->created_at)) ?><br>
                                    <?= date('g:i A', strtotime($msg->created_at)) ?>
                                </small>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="/contact/view?id=<?= $msg->id ?>" class="btn btn-outline-primary" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <button class="btn btn-outline-success quick-status"
                                            data-id="<?= $msg->id ?>"
                                            data-status="responded"
                                            title="Mark as Responded">
                                        <i class="bi bi-check"></i>
                                    </button>
                                    <button class="btn btn-outline-danger delete-message"
                                            data-id="<?= $msg->id ?>"
                                            title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php
        $totalPages = ceil($total / $perPage);
        if ($totalPages > 1):
        ?>
        <nav>
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="/contact/admin?page=<?= $page - 1 ?>&status=<?= $status ?>">Previous</a>
                </li>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="/contact/admin?page=<?= $i ?>&status=<?= $status ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>

                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="/contact/admin?page=<?= $page + 1 ?>&status=<?= $status ?>">Next</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
// Select all checkbox
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.message-select').forEach(cb => {
        cb.checked = this.checked;
    });
});

// Quick status update
document.querySelectorAll('.quick-status').forEach(btn => {
    btn.addEventListener('click', function() {
        if (confirm('Mark this message as responded?')) {
            const id = this.dataset.id;
            const status = this.dataset.status;
            
            fetch('/contact/status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `id=${id}&status=${status}`
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
    });
});

// Delete message
document.querySelectorAll('.delete-message').forEach(btn => {
    btn.addEventListener('click', function() {
        if (confirm('Are you sure you want to delete this message?')) {
            const id = this.dataset.id;
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/contact/delete';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'id';
            input.value = id;
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    });
});
</script>