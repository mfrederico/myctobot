<div class="container py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-0"><i class="bi bi-archive"></i> Merged History</h1>
            <p class="text-muted mb-0">Stories that have been merged into QA releases</p>
        </div>
        <div>
            <a href="/reviewboard" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Review Board
            </a>
        </div>
    </div>

    <?php if (empty($mergedStories)): ?>
    <div class="alert alert-info">
        <h4 class="alert-heading"><i class="bi bi-inbox"></i> No Merged Stories Yet</h4>
        <p class="mb-0">When stories are included in a QA release, they will appear here.</p>
    </div>
    <?php else: ?>

    <!-- Group by QA Branch -->
    <?php
    $byBranch = [];
    foreach ($mergedStories as $item) {
        $branch = $item['job']->qa_branch ?: 'Unknown Branch';
        if (!isset($byBranch[$branch])) {
            $byBranch[$branch] = [
                'stories' => [],
                'merged_at' => $item['job']->merged_at,
            ];
        }
        $byBranch[$branch]['stories'][] = $item;
    }
    ?>

    <?php foreach ($byBranch as $branch => $data): ?>
    <div class="card mb-4">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-git me-2"></i>
                <strong><?= htmlspecialchars($branch) ?></strong>
            </div>
            <div>
                <span class="badge bg-light text-dark me-2"><?= count($data['stories']) ?> stories</span>
                <?php if ($data['merged_at']): ?>
                <small class="text-light opacity-75">
                    Merged <?= date('M j, Y g:i A', strtotime($data['merged_at'])) ?>
                </small>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 100px;">Issue</th>
                        <th>Story</th>
                        <th style="width: 200px;">Project</th>
                        <th style="width: 150px;">PR</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['stories'] as $item): ?>
                    <?php
                    $job = $item['job'];
                    $story = $item['story'];
                    $project = $item['project'];

                    // Parse issue key for GitHub link
                    $issueNum = '';
                    $issueUrl = '#';
                    if ($job->issue_key && preg_match('/^([^\/]+)\/([^#]+)#(\d+)$/', $job->issue_key, $m)) {
                        $owner = $m[1];
                        $repo = $m[2];
                        $issueNum = '#' . $m[3];
                        $issueUrl = "https://github.com/{$owner}/{$repo}/issues/{$m[3]}";
                    }

                    // PR URL
                    $prUrl = $job->pr_url ?: null;
                    $prNum = $prUrl && preg_match('/\/pull\/(\d+)/', $prUrl, $pm) ? '#' . $pm[1] : null;
                    ?>
                    <tr>
                        <td>
                            <?php if ($issueUrl !== '#'): ?>
                            <a href="<?= htmlspecialchars($issueUrl) ?>" target="_blank" class="text-decoration-none">
                                <?= $issueNum ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted"><?= htmlspecialchars($job->issue_key ?: '-') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($story): ?>
                            <?= htmlspecialchars($story->title) ?>
                            <?php else: ?>
                            <span class="text-muted fst-italic">Story data not available</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($project): ?>
                            <small class="text-muted"><?= htmlspecialchars($project->name) ?></small>
                            <?php else: ?>
                            <small class="text-muted">-</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($prUrl): ?>
                            <a href="<?= htmlspecialchars($prUrl) ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                <i class="bi bi-github"></i> <?= $prNum ?: 'View PR' ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>
</div>
