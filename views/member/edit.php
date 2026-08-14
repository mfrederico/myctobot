<div class="container py-4" data-assist-page="member/edit" data-assist-purpose="Edit member profile information">
    <div class="row mb-4">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="/member/dashboard">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="/member/profile">Profile</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Edit</li>
                </ol>
            </nav>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?= h($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?= h($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <form method="POST">
        <?php if (!empty($csrf) && is_array($csrf)): ?>
            <?php foreach ($csrf as $name => $value): ?>
                <input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>">
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="row">
            <!-- Left Column - Main Form -->
            <div class="col-lg-8">
                <!-- Profile Header Card -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-4">
                            <?php
                            $initials = strtoupper(substr($member->first_name ?? $member->username ?? 'U', 0, 1));
                            if (!empty($member->last_name)) {
                                $initials .= strtoupper(substr($member->last_name, 0, 1));
                            }
                            $colors = ['#6366f1', '#8b5cf6', '#ec4899', '#f43f5e', '#f97316', '#eab308', '#22c55e', '#14b8a6', '#06b6d4', '#3b82f6'];
                            $colorIndex = abs(crc32($member->username ?? 'user')) % count($colors);
                            $avatarColor = $colors[$colorIndex];
                            ?>
                            <div class="avatar-lg rounded-circle d-flex align-items-center justify-content-center me-3"
                                 style="width: 72px; height: 72px; background: <?= $avatarColor ?>; color: white; font-size: 1.5rem; font-weight: 600;">
                                <?= $initials ?>
                            </div>
                            <div>
                                <h4 class="mb-1"><?= h(trim(($member->first_name ?? '') . ' ' . ($member->last_name ?? '')) ?: $member->username) ?></h4>
                                <span class="text-muted">@<?= h($member->username ?? '') ?></span>
                            </div>
                        </div>

                        <h5 class="border-bottom pb-2 mb-3">
                            <i class="bi bi-person-badge me-2 text-primary"></i>Account Identity
                        </h5>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="username" class="form-label fw-semibold">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">@</span>
                                    <input type="text" class="form-control" id="username" name="username"
                                           value="<?= h($member->username ?? '') ?>"
                                           pattern="[a-zA-Z0-9_\-]+"
                                           minlength="3" maxlength="30" required>
                                </div>
                                <div class="form-text">3-30 characters: letters, numbers, underscores, hyphens</div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label fw-semibold">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email"
                                       value="<?= h($member->email ?? '') ?>" required>
                                <div class="form-text">Used for login and notifications</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Personal Info Card -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h5 class="border-bottom pb-2 mb-3">
                            <i class="bi bi-person me-2 text-primary"></i>Personal Information
                        </h5>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="first_name" class="form-label fw-semibold">First Name</label>
                                <input type="text" class="form-control" id="first_name" name="first_name"
                                       value="<?= h($member->first_name ?? '') ?>"
                                       placeholder="Enter your first name">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="last_name" class="form-label fw-semibold">Last Name</label>
                                <input type="text" class="form-control" id="last_name" name="last_name"
                                       value="<?= h($member->last_name ?? '') ?>"
                                       placeholder="Enter your last name">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="bio" class="form-label fw-semibold">Bio</label>
                            <textarea class="form-control" id="bio" name="bio" rows="3"
                                      placeholder="Tell us a bit about yourself..."><?= h($member->bio ?? '') ?></textarea>
                            <div class="form-text">Optional. Brief description shown on your profile.</div>
                        </div>
                    </div>
                </div>

                <!-- Preferences Card -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h5 class="border-bottom pb-2 mb-3">
                            <i class="bi bi-lightning-charge me-2 text-primary"></i>Preferences
                        </h5>

                        <div class="mb-3">
                            <label for="preferred_studio" class="form-label fw-semibold">Preferred Studio</label>
                            <?php $currentStudio = \Flight::getStudio(); ?>
                            <select class="form-select" id="preferred_studio" name="preferred_studio">
                                <option value="" <?= empty($currentStudio) ? 'selected' : '' ?>>Ask each time</option>
                                <option value="developer" <?= $currentStudio === 'developer' ? 'selected' : '' ?>>
                                    Developer Studio - AI Dev Jobs, Git Repos, Jira Boards
                                </option>
                                <option value="commerce" <?= $currentStudio === 'commerce' ? 'selected' : '' ?>>
                                    Commerce Studio - Shopify Stores, Theme Preview
                                </option>
                                <option value="pipeline" <?= $currentStudio === 'pipeline' ? 'selected' : '' ?>>
                                    Pipeline Studio - Workflows, Automation, MCP Servers
                                </option>
                            </select>
                            <div class="form-text">Choose which studio dashboard to show after login.</div>
                        </div>
                    </div>
                </div>

                <!-- Security Card -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h5 class="border-bottom pb-2 mb-3">
                            <i class="bi bi-shield-lock me-2 text-primary"></i>Security
                        </h5>
                        <p class="text-muted mb-3">Leave password fields empty to keep your current password.</p>

                        <div class="mb-3">
                            <label for="current_password" class="form-label fw-semibold">Current Password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password"
                                   placeholder="Enter current password to change it">
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="password" class="form-label fw-semibold">New Password</label>
                                <input type="password" class="form-control" id="password" name="password"
                                       placeholder="New password" minlength="8">
                                <div class="form-text">Minimum 8 characters</div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="password_confirm" class="form-label fw-semibold">Confirm Password</label>
                                <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                                       placeholder="Confirm new password">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex gap-2 mb-4">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg me-1"></i>Save Changes
                    </button>
                    <a href="/member/profile" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>

            <!-- Right Column - Sidebar -->
            <div class="col-lg-4">
                <!-- Account Status Card -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-transparent border-bottom-0 pt-4 pb-0">
                        <h6 class="mb-0">
                            <i class="bi bi-info-circle me-2 text-primary"></i>Account Status
                        </h6>
                    </div>
                    <div class="card-body pt-3">
                        <?php
                        // Treat legacy level 0 as ROOT(1); fall back to a Member-styled badge for unknown values.
                        $lookupLevel = ((int) $member->level) === 0 ? 1 : (int) $member->level;
                        $meta = LEVEL_META[$lookupLevel] ?? ['label' => 'Unknown', 'badge' => 'bg-secondary'];
                        $levelName = $meta['label'];
                        $levelBadge = $meta['badge'];
                        ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Role</span>
                            <span class="badge <?= $levelBadge ?>"><?= $levelName ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Status</span>
                            <span class="badge <?= ($member->status ?? 'active') === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= ucfirst(h($member->status ?? 'active')) ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Member Since</span>
                            <span class="small"><?= date('M j, Y', strtotime($member->created_at ?? 'now')) ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Last Updated</span>
                            <span class="small"><?= date('M j, Y', strtotime($member->updated_at ?? 'now')) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Quick Links Card -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-transparent border-bottom-0 pt-4 pb-0">
                        <h6 class="mb-0">
                            <i class="bi bi-lightning me-2 text-primary"></i>Quick Links
                        </h6>
                    </div>
                    <div class="card-body pt-3">
                        <div class="list-group list-group-flush">
                            <a href="/member/profile" class="list-group-item list-group-item-action border-0 px-0 py-2">
                                <i class="bi bi-person me-2 text-muted"></i>View Profile
                            </a>
                            <a href="/member/settings" class="list-group-item list-group-item-action border-0 px-0 py-2">
                                <i class="bi bi-gear me-2 text-muted"></i>Settings
                            </a>
                            <a href="/member/dashboard" class="list-group-item list-group-item-action border-0 px-0 py-2">
                                <i class="bi bi-speedometer2 me-2 text-muted"></i>Dashboard
                            </a>
                            <?php if ($member->level <= 50): ?>
                            <a href="/admin" class="list-group-item list-group-item-action border-0 px-0 py-2">
                                <i class="bi bi-sliders me-2 text-muted"></i>Admin Panel
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Tips Card -->
                <div class="card border-0 shadow-sm bg-light">
                    <div class="card-body">
                        <h6 class="mb-3">
                            <i class="bi bi-lightbulb me-2 text-warning"></i>Password Tips
                        </h6>
                        <ul class="small text-muted mb-0 ps-3">
                            <li class="mb-1">Use at least 8 characters</li>
                            <li class="mb-1">Mix letters, numbers & symbols</li>
                            <li class="mb-1">Avoid common words or patterns</li>
                            <li>Don't reuse passwords</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
