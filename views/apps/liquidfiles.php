<?php
$appId = (int) ($app->id ?? 0);
$appName = h($app->name ?? 'App');
?>
<div class="container-fluid py-4">
    <!-- Step Indicator -->
    <div class="mb-4">
        <nav aria-label="App setup steps">
            <ol class="breadcrumb bg-light p-3 rounded">
                <li class="breadcrumb-item">
                    <a href="/apps/form/<?= $appId ?>">
                        <i class="bi bi-1-circle-fill text-primary"></i> Setup
                    </a>
                </li>
                <li class="breadcrumb-item">
                    <a href="/apps/screens/<?= $appId ?>">
                        <i class="bi bi-2-circle-fill text-primary"></i> Screens
                    </a>
                </li>
                <li class="breadcrumb-item">
                    <a href="/apps/pipelines/<?= $appId ?>">
                        <i class="bi bi-3-circle-fill text-primary"></i> Pipelines
                    </a>
                </li>
                <li class="breadcrumb-item active" aria-current="page">
                    <i class="bi bi-4-circle-fill text-primary"></i> <strong>Shopify Theme Files</strong>
                </li>
                <li class="breadcrumb-item">
                    <a href="/apps/buildshopify/<?= $appId ?>">
                        <i class="bi bi-5-circle-fill text-primary"></i> Build
                    </a>
                </li>
            </ol>
        </nav>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">
                <i class="bi bi-braces text-success"></i> Shopify Theme Files &mdash; <?= $appName ?>
            </h1>
            <p class="text-muted mb-0">Create Liquid templates for Shopify themes</p>
        </div>
        <div class="d-flex gap-2">
            <a href="/apps/pipelines/<?= $appId ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Pipelines
            </a>
            <a href="/apps/buildshopify/<?= $appId ?>" class="btn btn-primary">
                Build <i class="bi bi-arrow-right"></i>
            </a>
        </div>
    </div>

    <div class="row">
        <!-- Left Panel: File Tree -->
        <div class="col-lg-3 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-folder"></i> Files</span>
                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#newFileModal">
                        <i class="bi bi-plus-lg"></i> New
                    </button>
                </div>
                <div class="card-body p-0" style="max-height: calc(100vh - 300px); overflow-y: auto;">
                    <div class="list-group list-group-flush">
                        <!-- App Blocks -->
                        <div class="list-group-item bg-light fw-bold">
                            <i class="bi bi-box text-primary"></i> App Blocks
                            <span class="badge bg-secondary ms-1"><?= count($filesByType['block']) ?></span>
                        </div>
                        <?php foreach ($filesByType['block'] as $file): ?>
                        <a href="#" class="list-group-item list-group-item-action file-item" data-file-id="<?= $file->id ?>" onclick="loadFile(<?= $file->id ?>); return false;">
                            <i class="bi bi-file-earmark-code text-primary"></i> <?= h($file->file_name) ?>
                            <small class="text-muted d-block"><?= h(substr($file->updated_at, 0, 10)) ?></small>
                        </a>
                        <?php endforeach; ?>

                        <!-- App Embeds -->
                        <div class="list-group-item bg-light fw-bold">
                            <i class="bi bi-code-square text-info"></i> App Embeds
                            <span class="badge bg-secondary ms-1"><?= count($filesByType['embed']) ?></span>
                        </div>
                        <?php foreach ($filesByType['embed'] as $file): ?>
                        <a href="#" class="list-group-item list-group-item-action file-item" data-file-id="<?= $file->id ?>" onclick="loadFile(<?= $file->id ?>); return false;">
                            <i class="bi bi-file-earmark-code text-info"></i> <?= h($file->file_name) ?>
                            <small class="text-muted d-block"><?= h(substr($file->updated_at, 0, 10)) ?></small>
                        </a>
                        <?php endforeach; ?>

                        <!-- Sections -->
                        <div class="list-group-item bg-light fw-bold">
                            <i class="bi bi-layout-three-columns text-warning"></i> Sections
                            <span class="badge bg-secondary ms-1"><?= count($filesByType['section']) ?></span>
                        </div>
                        <?php foreach ($filesByType['section'] as $file): ?>
                        <a href="#" class="list-group-item list-group-item-action file-item" data-file-id="<?= $file->id ?>" onclick="loadFile(<?= $file->id ?>); return false;">
                            <i class="bi bi-file-earmark-code text-warning"></i> <?= h($file->file_name) ?>
                            <small class="text-muted d-block"><?= h(substr($file->updated_at, 0, 10)) ?></small>
                        </a>
                        <?php endforeach; ?>

                        <!-- Snippets -->
                        <div class="list-group-item bg-light fw-bold">
                            <i class="bi bi-puzzle text-success"></i> Snippets
                            <span class="badge bg-secondary ms-1"><?= count($filesByType['snippet']) ?></span>
                        </div>
                        <?php foreach ($filesByType['snippet'] as $file): ?>
                        <a href="#" class="list-group-item list-group-item-action file-item" data-file-id="<?= $file->id ?>" onclick="loadFile(<?= $file->id ?>); return false;">
                            <i class="bi bi-file-earmark-code text-success"></i> <?= h($file->file_name) ?>
                            <small class="text-muted d-block"><?= h(substr($file->updated_at, 0, 10)) ?></small>
                        </a>
                        <?php endforeach; ?>

                        <!-- Assets: JS -->
                        <div class="list-group-item bg-light fw-bold">
                            <i class="bi bi-filetype-js text-danger"></i> JavaScript Assets
                            <span class="badge bg-secondary ms-1"><?= count($filesByType['asset_js']) ?></span>
                        </div>
                        <?php foreach ($filesByType['asset_js'] as $file): ?>
                        <a href="#" class="list-group-item list-group-item-action file-item" data-file-id="<?= $file->id ?>" onclick="loadFile(<?= $file->id ?>); return false;">
                            <i class="bi bi-file-earmark-code text-danger"></i> <?= h($file->file_name) ?>
                            <small class="text-muted d-block"><?= h(substr($file->updated_at, 0, 10)) ?></small>
                        </a>
                        <?php endforeach; ?>

                        <!-- Assets: CSS -->
                        <div class="list-group-item bg-light fw-bold">
                            <i class="bi bi-filetype-css text-purple"></i> CSS Assets
                            <span class="badge bg-secondary ms-1"><?= count($filesByType['asset_css']) ?></span>
                        </div>
                        <?php foreach ($filesByType['asset_css'] as $file): ?>
                        <a href="#" class="list-group-item list-group-item-action file-item" data-file-id="<?= $file->id ?>" onclick="loadFile(<?= $file->id ?>); return false;">
                            <i class="bi bi-file-earmark-code" style="color: #663399;"></i> <?= h($file->file_name) ?>
                            <small class="text-muted d-block"><?= h(substr($file->updated_at, 0, 10)) ?></small>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Center Panel: Editor -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span id="editorTitle"><i class="bi bi-file-earmark"></i> No file selected</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#newFileModal">
                            <i class="bi bi-plus-lg"></i> New File
                        </button>
                        <div class="btn-group btn-group-sm" id="editorActions" style="display: none;">
                            <button type="button" class="btn btn-outline-primary" onclick="saveFile()">
                                <i class="bi bi-save"></i> Save
                            </button>
                            <button type="button" class="btn btn-outline-danger" onclick="deleteFile()">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0 d-flex flex-column" style="height: calc(100vh - 350px);">
                    <!-- File Name Input (hidden when no file) -->
                    <div id="fileNameInput" class="p-3 border-bottom" style="display: none;">
                        <label class="form-label mb-1 small">File Name</label>
                        <input type="text" class="form-control form-control-sm" id="fileName" placeholder="my-block.liquid">
                    </div>

                    <!-- Editor -->
                    <div id="liquidEditor" style="flex: 1; min-height: 400px;"></div>
                </div>
            </div>
        </div>

        <!-- Right Panel: Preview & Settings -->
        <div class="col-lg-3 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-eye"></i> Live Preview
                </div>
                <div class="card-body">
                    <!-- Shopify Connection Selector -->
                    <div class="mb-3">
                        <label class="form-label small">Shopify Store</label>
                        <select class="form-select form-select-sm" id="previewConnection">
                            <option value="">-- Select Store --</option>
                            <?php foreach ($shopifyConnections ?? [] as $conn): ?>
                            <option value="<?= $conn->id ?>">
                                <?= h($conn->external_name ?: $conn->external_eid) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Product Selector -->
                    <div class="mb-3" id="productSelectorContainer" style="display: none;">
                        <label class="form-label small">Select Product</label>
                        <select class="form-select form-select-sm" id="productSelector">
                            <option value="">-- Loading products... --</option>
                        </select>
                        <small class="text-muted">Or enter URL manually below</small>
                    </div>

                    <!-- Context URL -->
                    <div class="mb-3">
                        <label class="form-label small">Context URL</label>
                        <input type="text" class="form-control form-control-sm" id="previewUrl" placeholder="/products/test-product">
                        <small class="text-muted">Sets Liquid objects (product, collection, etc.)</small>
                    </div>

                    <button type="button" class="btn btn-sm btn-success w-100 mb-3" onclick="runPreview()">
                        <i class="bi bi-play-circle"></i> Run Preview
                    </button>

                    <!-- Preview Output -->
                    <div id="previewOutput" style="display: none;">
                        <label class="form-label small fw-bold">Output</label>
                        <pre class="bg-dark text-light p-2 rounded small" style="max-height: 300px; overflow-y: auto; font-size: 0.75rem;" id="previewResult"></pre>
                    </div>

                    <!-- Documentation -->
                    <div class="alert alert-info small mt-3">
                        <strong>Quick Reference:</strong>
                        <ul class="mb-0 ps-3">
                            <li><code>{{ product.title }}</code></li>
                            <li><code>{% for ... %}</code></li>
                            <li><code>{{ 'text' | filter }}</code></li>
                        </ul>
                        <a href="https://shopify.dev/docs/api/liquid" target="_blank" class="small">
                            Full Docs <i class="bi bi-box-arrow-up-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New File Modal -->
<div class="modal fade" id="newFileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New File</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">File Type</label>
                    <select class="form-select" id="newFileType">
                        <option value="block">App Block (Shopify 2.0 drag-and-drop)</option>
                        <option value="embed">App Embed (global script)</option>
                        <option value="section">Section (theme editor)</option>
                        <option value="snippet">Snippet (reusable component)</option>
                        <option value="asset_js">JavaScript Asset</option>
                        <option value="asset_css">CSS Asset</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">File Name</label>
                    <input type="text" class="form-control" id="newFileName" placeholder="my-block.liquid">
                    <small class="text-muted">Will be automatically suffixed (.liquid, .js, .css)</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="createNewFile()">Create</button>
            </div>
        </div>
    </div>
</div>

<!-- Monaco Editor -->
<script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs/loader.js"></script>

<script>
const APP_ID = <?= $appId ?>;
let editor = null;
let currentFile = null;

// Initialize Monaco Editor
require.config({ paths: { vs: 'https://cdn.jsdelivr.net/npm/monaco-editor@0.45.0/min/vs' } });
require(['vs/editor/editor.main'], function() {
    editor = monaco.editor.create(document.getElementById('liquidEditor'), {
        value: '',
        language: 'liquid',
        theme: 'vs-dark',
        automaticLayout: true,
        minimap: { enabled: false },
        fontSize: 13,
    });

    // Register Liquid language (basic)
    monaco.languages.register({ id: 'liquid' });
    monaco.languages.setMonarchTokensProvider('liquid', {
        tokenizer: {
            root: [
                [/\{\{/, 'delimiter', '@liquidOutput'],
                [/\{%/, 'delimiter', '@liquidTag'],
            ],
            liquidOutput: [
                [/\}\}/, 'delimiter', '@pop'],
                [/[a-z_][\w]*/, 'variable'],
                [/\|/, 'operator'],
            ],
            liquidTag: [
                [/%\}/, 'delimiter', '@pop'],
                [/(if|else|elsif|endif|for|endfor|assign|capture|endcapture)/, 'keyword'],
            ],
        },
    });
});

function createNewFile() {
    const fileType = document.getElementById('newFileType').value;
    const fileName = document.getElementById('newFileName').value.trim();

    if (!fileName) {
        alert('Please enter a file name');
        return;
    }

    // Auto-add extension
    let finalName = fileName;
    if (fileType === 'asset_js' && !fileName.endsWith('.js')) {
        finalName += '.js';
    } else if (fileType === 'asset_css' && !fileName.endsWith('.css')) {
        finalName += '.css';
    } else if (!fileName.endsWith('.liquid') && ['block', 'embed', 'section', 'snippet'].includes(fileType)) {
        finalName += '.liquid';
    }

    // Create empty file
    currentFile = {
        id: 0,
        file_type: fileType,
        file_name: finalName,
        file_content: getDefaultContent(fileType, finalName),
        settings_schema: '{}',
    };

    editor.setValue(currentFile.file_content);
    document.getElementById('fileName').value = finalName;
    document.getElementById('fileNameInput').style.display = 'block';
    document.getElementById('editorActions').style.display = 'flex';
    document.getElementById('editorTitle').innerHTML = '<i class="bi bi-file-plus"></i> New File: ' + finalName;

    document.querySelector('#newFileModal .btn-close').click();
}

function getDefaultContent(fileType, fileName) {
    if (fileType === 'block') {
        return `{% comment %}
  App Block: ${fileName}
  Use in Shopify 2.0 theme editor
{% endcomment %}

<div class="my-app-block">
  <h3>{{ block.settings.heading }}</h3>
  <p>{{ block.settings.text }}</p>
</div>

{% schema %}
{
  "name": "${fileName.replace('.liquid', '')}",
  "target": "section",
  "settings": [
    {
      "type": "text",
      "id": "heading",
      "label": "Heading",
      "default": "Hello World"
    },
    {
      "type": "textarea",
      "id": "text",
      "label": "Text",
      "default": "This is my app block"
    }
  ]
}
{% endschema %}`;
    } else if (fileType === 'embed') {
        return `{% comment %}
  App Embed: ${fileName}
  Loads on every page
{% endcomment %}

{% script %}
(function() {
  console.log('App Embed loaded');
  // Your embed code here
})();
{% endscript %}`;
    } else if (fileType === 'asset_js') {
        return `// ${fileName}
(function() {
  'use strict';

  // Your JavaScript here

})();`;
    } else if (fileType === 'asset_css') {
        return `/* ${fileName} */

.my-app {
  /* Your styles here */
}`;
    }
    return '';
}

function loadFile(fileId) {
    fetch('/apps/getliquidfile/' + fileId)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Failed to load file');
                return;
            }

            currentFile = data.data.file;
            editor.setValue(currentFile.file_content || '');
            document.getElementById('fileName').value = currentFile.file_name;
            document.getElementById('fileNameInput').style.display = 'block';
            document.getElementById('editorActions').style.display = 'flex';
            document.getElementById('editorTitle').innerHTML = '<i class="bi bi-file-earmark-code"></i> ' + currentFile.file_name;

            // Highlight in tree
            document.querySelectorAll('.file-item').forEach(el => el.classList.remove('active'));
            document.querySelector(`.file-item[data-file-id="${fileId}"]`)?.classList.add('active');
        });
}

function saveFile() {
    if (!currentFile) return;

    const data = {
        app_id: APP_ID,
        file_id: currentFile.id || 0,
        file_type: currentFile.file_type,
        file_name: document.getElementById('fileName').value.trim(),
        file_content: editor.getValue(),
        settings_schema: currentFile.settings_schema || '{}',
    };

    fetch('/apps/saveliquidfile', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            alert('File saved!');
            location.reload();
        } else {
            alert('Failed to save: ' + (result.message || 'Unknown error'));
        }
    });
}

function deleteFile() {
    if (!currentFile || !currentFile.id) return;
    if (!confirm('Delete this file?')) return;

    fetch('/apps/deleteliquidfile', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ file_id: currentFile.id })
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            alert('File deleted!');
            location.reload();
        } else {
            alert('Failed to delete: ' + (result.message || 'Unknown error'));
        }
    });
}

function runPreview() {
    const connectionId = document.getElementById('previewConnection').value;
    const url = document.getElementById('previewUrl').value.trim();
    const code = editor.getValue();

    if (!connectionId) {
        alert('Please select a Shopify store');
        return;
    }

    if (!code.trim()) {
        alert('No Liquid code to preview');
        return;
    }

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Running...';

    fetch('/pipelines/testliquideval', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?= $_SESSION['csrf_token'] ?? '' ?>'
        },
        body: JSON.stringify({
            connection_id: parseInt(connectionId),
            url: url || '',
            code: code
        })
    })
    .then(r => r.json())
    .then(data => {
        const outputDiv = document.getElementById('previewOutput');
        const resultPre = document.getElementById('previewResult');

        if (data.success && data.preview_url) {
            // Open preview in new window
            window.open(data.preview_url, '_blank');
            resultPre.textContent = `Preview opened in new window\nTheme ID: ${data.theme_id}\nSnippet: ${data.snippet_key}`;
            outputDiv.style.display = 'block';
        } else if (data.success) {
            resultPre.textContent = data.result || '(empty output)';
            outputDiv.style.display = 'block';
        } else {
            // Show error
            const output = data.result || '(no output)';
            const error = data.error || 'Unknown error';
            const debug = data.debug ? '\n\nDebug:\n' + JSON.stringify(data.debug, null, 2) : '';
            resultPre.textContent = `${output}\n\n${error}${debug}`;
            outputDiv.style.display = 'block';
        }
    })
    .catch(err => {
        alert('Preview failed: ' + err.message);
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-circle"></i> Run Preview';
    });
}

// Product selector functionality
document.getElementById('previewConnection').addEventListener('change', function() {
    const connectionId = this.value;
    const productContainer = document.getElementById('productSelectorContainer');
    const productSelector = document.getElementById('productSelector');

    if (!connectionId) {
        productContainer.style.display = 'none';
        return;
    }

    // Show loading state
    productContainer.style.display = 'block';
    productSelector.innerHTML = '<option value="">-- Loading products... --</option>';
    productSelector.disabled = true;

    // Fetch products from the selected store
    fetch('/apps/getshopifyproducts/' + connectionId)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data.products) {
                const products = data.data.products;

                if (products.length === 0) {
                    productSelector.innerHTML = '<option value="">-- No products found --</option>';
                    return;
                }

                // Populate dropdown with real products
                let html = '<option value="">-- Select a product --</option>';
                products.forEach(product => {
                    const price = product.price ? ` ($${product.price})` : '';
                    html += `<option value="/products/${product.handle}">${product.title}${price}</option>`;
                });
                productSelector.innerHTML = html;
                productSelector.disabled = false;
            } else {
                productSelector.innerHTML = '<option value="">-- Failed to load products --</option>';
                console.error('Failed to load products:', data.error);
            }
        })
        .catch(err => {
            productSelector.innerHTML = '<option value="">-- Error loading products --</option>';
            console.error('Product fetch error:', err);
        });
});

// Auto-populate context URL when product is selected
document.getElementById('productSelector').addEventListener('change', function() {
    const url = this.value;
    if (url) {
        document.getElementById('previewUrl').value = url;
    }
});
</script>

<style>
.file-item.active {
    background-color: #0d6efd !important;
    color: white !important;
    border-color: #0d6efd !important;
}
.file-item.active small {
    color: rgba(255, 255, 255, 0.8) !important;
}
</style>
