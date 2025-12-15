<div class="container py-4">
    <h1 class="h2 mb-4">System Settings</h1>
    
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-header">
            <h5>Application Settings</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <?php if (!empty($csrf) && is_array($csrf)): ?>
                    <?php foreach ($csrf as $name => $value): ?>
                        <input type="hidden" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>">
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <div class="mb-3">
                    <label for="site_name" class="form-label">Site Name</label>
                    <input type="text" class="form-control" id="site_name" name="site_name" 
                           value="<?= htmlspecialchars(Flight::getSystemSetting('site_name') ?? 'TikNix') ?>">
                </div>
                
                <div class="mb-3">
                    <label for="site_description" class="form-label">Site Description</label>
                    <textarea class="form-control" id="site_description" name="site_description" rows="3"><?= htmlspecialchars(Flight::getSystemSetting('site_description') ?? '') ?></textarea>
                </div>
                
                <div class="mb-3">
                    <label for="maintenance_mode" class="form-label">Maintenance Mode</label>
                    <select class="form-select" id="maintenance_mode" name="maintenance_mode">
                        <option value="0" <?= Flight::getSystemSetting('maintenance_mode') == '0' ? 'selected' : '' ?>>Disabled</option>
                        <option value="1" <?= Flight::getSystemSetting('maintenance_mode') == '1' ? 'selected' : '' ?>>Enabled</option>
                    </select>
                    <small class="form-text text-muted">When enabled, only admins can access the site</small>
                </div>
                
                <div class="mb-3">
                    <label for="registration_enabled" class="form-label">User Registration</label>
                    <select class="form-select" id="registration_enabled" name="registration_enabled">
                        <option value="1" <?= Flight::getSystemSetting('registration_enabled') != '0' ? 'selected' : '' ?>>Enabled</option>
                        <option value="0" <?= Flight::getSystemSetting('registration_enabled') == '0' ? 'selected' : '' ?>>Disabled</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label for="default_user_level" class="form-label">Default User Level</label>
                    <select class="form-select" id="default_user_level" name="default_user_level">
                        <option value="100" <?= Flight::getSystemSetting('default_user_level') == '100' ? 'selected' : '' ?>>MEMBER (100)</option>
                        <option value="101" <?= Flight::getSystemSetting('default_user_level') == '101' ? 'selected' : '' ?>>PUBLIC (101)</option>
                    </select>
                    <small class="form-text text-muted">Level assigned to new registrations</small>
                </div>
                
                <div class="mb-3">
                    <label for="session_timeout" class="form-label">Session Timeout (minutes)</label>
                    <input type="number" class="form-control" id="session_timeout" name="session_timeout" 
                           value="<?= htmlspecialchars(Flight::getSystemSetting('session_timeout') ?? '60') ?>">
                </div>
                
                <div class="mb-3">
                    <label for="debug_mode" class="form-label">Debug Mode</label>
                    <select class="form-select" id="debug_mode" name="debug_mode">
                        <option value="0" <?= Flight::getSystemSetting('debug_mode') == '0' ? 'selected' : '' ?>>Production</option>
                        <option value="1" <?= Flight::getSystemSetting('debug_mode') == '1' ? 'selected' : '' ?>>Development</option>
                    </select>
                    <small class="form-text text-muted">Shows detailed error messages when enabled</small>
                </div>
                
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </form>
        </div>
    </div>
    
    <?php if (!empty($settings)): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h5>All System Settings</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Key</th>
                                <th>Value</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($settings as $setting): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($setting->setting_key) ?></code></td>
                                    <td><?= htmlspecialchars(substr($setting->setting_value, 0, 100)) ?></td>
                                    <td><?= $setting->updated_at ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>