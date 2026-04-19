<div class="container py-4" data-assist-page="pluginsources/add" data-assist-purpose="Add a new plugin repository source">
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/settings/connections">Settings</a></li>
            <li class="breadcrumb-item"><a href="/pluginsources">Plugin Sources</a></li>
            <li class="breadcrumb-item active">Add Source</li>
        </ol>
    </nav>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Add Plugin Repository Source</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="/pluginsources/store" id="addSourceForm">
                        <?= $csrf['field'] ?? '' ?>

                        <div class="mb-3">
                            <label for="provider" class="form-label">Provider <span class="text-danger">*</span></label>
                            <select class="form-select" id="provider" name="provider" required onchange="updatePlaceholder()">
                                <option value="">Select a provider...</option>
                                <option value="github">GitHub</option>
                                <option value="gitlab">GitLab</option>
                                <option value="bitbucket">Bitbucket</option>
                                <option value="git">Generic Git</option>
                            </select>
                            <div class="form-text" id="provider-help">Select the Git hosting provider.</div>
                        </div>

                        <div class="mb-3">
                            <label for="repository_url" class="form-label">Repository URL <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="repository_url" name="repository_url"
                                   placeholder="owner/repo or https://github.com/owner/repo" required>
                            <div class="form-text" id="url-help">
                                Enter the repository URL or path. Examples:
                                <ul class="mb-0 mt-1">
                                    <li><code>owner/repo</code> - A specific repository</li>
                                    <li><code>organization</code> - An entire organization (GitHub only)</li>
                                    <li><code>https://github.com/owner/repo</code> - Full URL</li>
                                </ul>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="source_type" class="form-label">Source Type</label>
                            <select class="form-select" id="source_type" name="source_type">
                                <option value="repository">Repository (single repo)</option>
                                <option value="organization">Organization (scan all repos)</option>
                            </select>
                            <div class="form-text">Choose whether this is a single repository or an organization to scan.</div>
                        </div>

                        <div class="mb-3">
                            <label for="access_token" class="form-label">Access Token</label>
                            <input type="password" class="form-control" id="access_token" name="access_token"
                                   placeholder="Optional: For private repositories">
                            <div class="form-text" id="token-help">
                                Required for private repositories. Leave blank for public repos.
                                <span id="token-format-help"></span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <input type="text" class="form-control" id="description" name="description"
                                   placeholder="Optional: Brief description of this source">
                            <div class="form-text">A helpful note about what plugins this source contains.</div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-lg"></i> Add Source
                            </button>
                            <a href="/pluginsources" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Provider Information</h5>
                </div>
                <div class="card-body">
                    <div id="provider-info-github" class="provider-info d-none">
                        <h6><i class="bi bi-github"></i> GitHub</h6>
                        <p class="mb-2">Supports repositories and organizations.</p>
                        <p class="mb-2"><strong>URL formats:</strong></p>
                        <ul class="small">
                            <li><code>owner/repo</code></li>
                            <li><code>organization</code></li>
                            <li><code>https://github.com/owner/repo</code></li>
                        </ul>
                        <p class="mb-0"><strong>Token:</strong> Personal Access Token with <code>repo</code> scope for private repos.</p>
                    </div>

                    <div id="provider-info-gitlab" class="provider-info d-none">
                        <h6><i class="bi bi-gitlab"></i> GitLab</h6>
                        <p class="mb-2">Supports repositories and groups.</p>
                        <p class="mb-2"><strong>URL formats:</strong></p>
                        <ul class="small">
                            <li><code>group/project</code></li>
                            <li><code>https://gitlab.com/group/project</code></li>
                            <li>Self-hosted URLs supported</li>
                        </ul>
                        <p class="mb-0"><strong>Token:</strong> Personal Access Token with <code>read_api</code> scope.</p>
                    </div>

                    <div id="provider-info-bitbucket" class="provider-info d-none">
                        <h6><i class="bi bi-bucket"></i> Bitbucket</h6>
                        <p class="mb-2">Supports repositories.</p>
                        <p class="mb-2"><strong>URL formats:</strong></p>
                        <ul class="small">
                            <li><code>workspace/repo</code></li>
                            <li><code>https://bitbucket.org/workspace/repo</code></li>
                        </ul>
                        <p class="mb-0"><strong>Token:</strong> App password in format <code>username:app_password</code></p>
                    </div>

                    <div id="provider-info-git" class="provider-info d-none">
                        <h6><i class="bi bi-git"></i> Generic Git</h6>
                        <p class="mb-2">Any Git repository accessible via URL.</p>
                        <p class="mb-2"><strong>URL formats:</strong></p>
                        <ul class="small">
                            <li><code>https://example.com/repo.git</code></li>
                            <li><code>git://example.com/repo.git</code></li>
                        </ul>
                        <p class="mb-0"><strong>Note:</strong> Authentication may not be supported for all Git servers.</p>
                    </div>

                    <div id="provider-info-default" class="provider-info">
                        <p class="text-muted mb-0">Select a provider to see specific instructions.</p>
                    </div>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Tips</h5>
                </div>
                <div class="card-body">
                    <ul class="mb-0 small">
                        <li class="mb-2">Start with public repositories - no token needed</li>
                        <li class="mb-2">For organizations, only public repos are scanned unless a token is provided</li>
                        <li class="mb-2">Tokens are encrypted before storage</li>
                        <li>The source will be validated automatically after creation</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function updatePlaceholder() {
    const provider = document.getElementById('provider').value;
    const urlInput = document.getElementById('repository_url');
    const tokenHelp = document.getElementById('token-format-help');

    // Hide all provider info cards
    document.querySelectorAll('.provider-info').forEach(el => el.classList.add('d-none'));

    // Show relevant provider info
    if (provider) {
        const infoEl = document.getElementById('provider-info-' + provider);
        if (infoEl) {
            infoEl.classList.remove('d-none');
        }
    } else {
        document.getElementById('provider-info-default').classList.remove('d-none');
    }

    // Update placeholder and help text based on provider
    switch (provider) {
        case 'github':
            urlInput.placeholder = 'owner/repo or organization';
            tokenHelp.textContent = 'Create at: Settings > Developer settings > Personal access tokens';
            break;
        case 'gitlab':
            urlInput.placeholder = 'group/project or full URL';
            tokenHelp.textContent = 'Create at: User Settings > Access Tokens';
            break;
        case 'bitbucket':
            urlInput.placeholder = 'workspace/repo';
            tokenHelp.textContent = 'Format: username:app_password';
            break;
        case 'git':
            urlInput.placeholder = 'https://example.com/repo.git';
            tokenHelp.textContent = '';
            break;
        default:
            urlInput.placeholder = 'owner/repo or https://github.com/owner/repo';
            tokenHelp.textContent = '';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', updatePlaceholder);
</script>
