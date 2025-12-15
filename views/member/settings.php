<div class="container py-4">
    <h1 class="h2 mb-4">Settings</h1>
    
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <?php if (!empty($csrf) && is_array($csrf)): ?>
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <h5 class="mb-3">Notification Settings</h5>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="email_notifications" 
                                       name="email_notifications" value="1"
                                       <?= Flight::getSetting('email_notifications') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="email_notifications">
                                    Email notifications
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="newsletter" 
                                       name="newsletter" value="1"
                                       <?= Flight::getSetting('newsletter') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="newsletter">
                                    Subscribe to newsletter
                                </label>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <h5 class="mb-3">Privacy Settings</h5>
                        
                        <div class="mb-3">
                            <label for="profile_visibility" class="form-label">Profile Visibility</label>
                            <select class="form-select" id="profile_visibility" name="profile_visibility">
                                <option value="public" <?= Flight::getSetting('profile_visibility') == 'public' ? 'selected' : '' ?>>Public</option>
                                <option value="members" <?= Flight::getSetting('profile_visibility') == 'members' ? 'selected' : '' ?>>Members Only</option>
                                <option value="private" <?= Flight::getSetting('profile_visibility') == 'private' ? 'selected' : '' ?>>Private</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="show_email" 
                                       name="show_email" value="1"
                                       <?= Flight::getSetting('show_email') ? 'checked' : '' ?>>
                                <label class="form-check-label" for="show_email">
                                    Show email address on profile
                                </label>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <h5 class="mb-3">Display Settings</h5>
                        
                        <div class="mb-3">
                            <label for="timezone" class="form-label">Timezone</label>
                            <select class="form-select" id="timezone" name="timezone">
                                <option value="UTC" <?= Flight::getSetting('timezone') == 'UTC' ? 'selected' : '' ?>>UTC</option>
                                <option value="America/New_York" <?= Flight::getSetting('timezone') == 'America/New_York' ? 'selected' : '' ?>>Eastern Time</option>
                                <option value="America/Chicago" <?= Flight::getSetting('timezone') == 'America/Chicago' ? 'selected' : '' ?>>Central Time</option>
                                <option value="America/Denver" <?= Flight::getSetting('timezone') == 'America/Denver' ? 'selected' : '' ?>>Mountain Time</option>
                                <option value="America/Los_Angeles" <?= Flight::getSetting('timezone') == 'America/Los_Angeles' ? 'selected' : '' ?>>Pacific Time</option>
                                <option value="Europe/London" <?= Flight::getSetting('timezone') == 'Europe/London' ? 'selected' : '' ?>>London</option>
                                <option value="Europe/Paris" <?= Flight::getSetting('timezone') == 'Europe/Paris' ? 'selected' : '' ?>>Paris</option>
                                <option value="Asia/Tokyo" <?= Flight::getSetting('timezone') == 'Asia/Tokyo' ? 'selected' : '' ?>>Tokyo</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="date_format" class="form-label">Date Format</label>
                            <select class="form-select" id="date_format" name="date_format">
                                <option value="Y-m-d" <?= Flight::getSetting('date_format') == 'Y-m-d' ? 'selected' : '' ?>>2024-12-25</option>
                                <option value="m/d/Y" <?= Flight::getSetting('date_format') == 'm/d/Y' ? 'selected' : '' ?>>12/25/2024</option>
                                <option value="d/m/Y" <?= Flight::getSetting('date_format') == 'd/m/Y' ? 'selected' : '' ?>>25/12/2024</option>
                                <option value="F j, Y" <?= Flight::getSetting('date_format') == 'F j, Y' ? 'selected' : '' ?>>December 25, 2024</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Save Settings</button>
                        <a href="/member/profile" class="btn btn-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Quick Links</h5>
                    <div class="list-group">
                        <a href="/member/profile" class="list-group-item list-group-item-action">View Profile</a>
                        <a href="/member/edit" class="list-group-item list-group-item-action">Edit Profile</a>
                        <a href="/member/dashboard" class="list-group-item list-group-item-action">Dashboard</a>
                        <?php if ($member->level <= 50): ?>
                            <a href="/admin/settings" class="list-group-item list-group-item-action">System Settings</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($settings)): ?>
            <div class="card mt-3">
                <div class="card-body">
                    <h5 class="card-title">Current Settings</h5>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <tbody>
                                <?php foreach ($settings as $setting): ?>
                                    <tr>
                                        <td><small><?= htmlspecialchars($setting->setting_key) ?></small></td>
                                        <td><small><?= htmlspecialchars(substr($setting->setting_value, 0, 20)) ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>