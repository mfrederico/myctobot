# AI Page Assistant Installation Guide

The Page Assistant ("Assist") is a context-aware AI widget that floats on every page, helping users understand features, fill forms, and navigate the application.

## Prerequisites

### 1. OpenSwoole Extension

The WebSocket server requires OpenSwoole (PHP async extension):

```bash
# Install via PECL
pecl install openswoole

# Add to php.ini
echo "extension=openswoole.so" | sudo tee /etc/php/8.2/cli/conf.d/20-openswoole.ini

# Verify installation
php -m | grep openswoole
```

### 2. Ollama

The assistant uses Ollama for local LLM inference:

```bash
# Install Ollama (Linux)
curl -fsSL https://ollama.com/install.sh | sh

# Start Ollama service
sudo systemctl start ollama

# Pull required models
ollama pull qwen3-coder:30b      # Main chat model (18GB)
ollama pull nomic-embed-text     # Embedding model for RAG (274MB)

# Verify models
ollama list
```

**Alternative models** (if you have less VRAM):
- `devstral:latest` - Mistral's code model (~14GB)
- `qwen2.5-coder:14b` - Smaller Qwen model (~9GB)

### 3. Directory Permissions

```bash
# Create storage directory
mkdir -p storage/assist

# Ensure web server can write
chmod 755 storage/assist
```

## Installation

### Step 1: Verify Files

Ensure these files exist:

```
services/
├── PageAssistantService.php      # Core service
├── AssistWebSocketHandler.php    # WebSocket handler
└── AssistKnowledgeStore.php      # RAG knowledge store

scripts/
├── assist-websocket-server.php   # WebSocket server
└── run-migration.php                 # Schema migration tool

views/partials/
└── pageassist.php                # Frontend widget
```

### Step 2: Test Ollama Connection

```bash
# Quick test
curl http://localhost:11434/api/tags

# Should return list of models including qwen3-coder and nomic-embed-text
```

### Step 3: Seed Knowledge Base

Seed initial page definitions for your workspace:

```bash
# Replace 'gwt' with your workspace slug
php scripts/run-migration.php --migration=58_KnowledgeBases --workspace=gwt --verbose
```

This creates `storage/assist/gwt/knowledge.sqlite` with:
- Page definitions (what each page does)
- Field definitions (form field explanations)
- Common workflows (how to accomplish tasks)

### Step 4: Start WebSocket Server

**Development (foreground):**
```bash
php scripts/assist-websocket-server.php --port=9510
```

**Production (background with systemd):**

Create `/etc/systemd/system/assist-websocket.service`:

```ini
[Unit]
Description=MyCTOBot Page Assistant WebSocket Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html/default/myctobot
ExecStart=/usr/bin/php scripts/assist-websocket-server.php --port=9510
Restart=always
RestartSec=5
Environment=OLLAMA_HOST=http://localhost:11434
Environment=OLLAMA_MODEL=qwen3-coder:30b

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable assist-websocket
sudo systemctl start assist-websocket
sudo systemctl status assist-websocket
```

### Step 5: Configure Nginx (Production)

Add WebSocket proxy to your nginx config:

```nginx
# In your server block for *.myctobot.ai

# Page Assistant WebSocket
location /assist {
    proxy_pass http://127.0.0.1:9510;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 86400;
    proxy_send_timeout 86400;
}
```

```bash
sudo nginx -t
sudo systemctl reload nginx
```

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `ASSIST_WS_PORT` | 9510 | WebSocket server port |
| `ASSIST_WS_HOST` | 0.0.0.0 | Bind address |
| `OLLAMA_HOST` | http://localhost:11434 | Ollama API URL |
| `OLLAMA_MODEL` | qwen3-coder:30b | Chat model |

### Widget Configuration

The widget auto-configures based on environment. Override in your view if needed:

```php
<?php
$assistWsUrl = 'wss://custom.domain.com/assist';
$assistEnabled = true; // or false to disable
include __DIR__ . '/../partials/pageassist.php';
?>
```

### Disabling for Specific Pages

Set `$assistEnabled = false` before including the partial, or add this to your controller:

```php
$this->viewData['assistEnabled'] = false;
```

### Connection States

The widget button shows different states:

| State | Icon | Color | Description |
|-------|------|-------|-------------|
| Unavailable | Moon+Stars (Zzz) | Gray | WebSocket server not reachable |
| Connecting | Stars (pulsing) | Yellow | Attempting to connect |
| Connected | Stars | Blue | Ready to use |

When the assistant is unavailable:
- The button appears grayed out with a "sleeping" moon icon
- Clicking shows a toast: "Assistant is sleeping. Trying to wake up..."
- Connection is retried automatically in the background

## Adding Page Context

### Data Attributes

Add `data-assist-*` attributes to help the assistant understand your page:

```html
<!-- Page-level context -->
<main data-assist-page="pipelines/create"
      data-assist-purpose="Create a new automation pipeline"
      data-assist-context='{"studio":"pipeline"}'>

<!-- Form context -->
<form data-assist-form="pipeline-form"
      data-assist-purpose="Configure pipeline settings">

<!-- Field context -->
<input name="name"
       data-assist-field="pipeline_name"
       data-assist-label="Pipeline Name"
       data-assist-hint="A short, descriptive name"
       data-assist-required="true">

<select name="trigger_type"
        data-assist-field="trigger"
        data-assist-options="manual|webhook|cron"
        data-assist-hint="How the pipeline is started">
```

### Adding Custom Knowledge

Add page-specific knowledge programmatically:

```php
use app\services\AssistKnowledgeStore;

$store = AssistKnowledgeStore::forWorkspace($workspace);

// Add a page definition
$store->addEntry('page_definition', 'Custom Page Title',
    'This page does X, Y, and Z. Use it when you need to...',
    ['page' => 'custom/page']
);

// Add a field definition
$store->addEntry('field_definition', 'API Key Field',
    'Your API key from the external service. Find it in Settings > API.',
    ['page' => 'integrations/form', 'element' => 'api_key']
);

// Add a workflow
$store->addEntry('workflow', 'Export Data',
    'To export data: 1) Go to Reports, 2) Select date range, 3) Click Export CSV.',
    []
);
```

## Testing

### 1. WebSocket Connection

```bash
# Install wscat if needed
npm install -g wscat

# Connect to local server
wscat -c ws://localhost:9510

# Send test message
{"type":"chat","message":"Hello","context":{"page":"test"}}
```

### 2. Health Check

```bash
curl http://localhost:9510/health
# Should return: {"status":"ok","service":"assist-websocket","time":"..."}
```

### 3. Browser Test

1. Navigate to any page (e.g., `/pipelines/form`)
2. Click the star button (bottom-left)
3. Type "What is this page for?"
4. Should see streaming response

### 4. Knowledge Search

```php
use app\services\AssistKnowledgeStore;

$store = AssistKnowledgeStore::forWorkspace('gwt');

// Search by query
$results = $store->search('how do I create a pipeline', ['limit' => 5]);
print_r($results);

// Get stats
print_r($store->getStats());
```

## Troubleshooting

### Widget Not Appearing

1. Check if logged in (widget only shows for authenticated users)
2. Check browser console for JS errors
3. Verify footer.php includes the partial

### WebSocket Connection Failed

```bash
# Check if server is running
ps aux | grep assist-websocket

# Check logs
tail -f log/app-$(date +%Y-%m-%d).log | grep -i assist

# Test port directly
nc -zv localhost 9510
```

### Ollama Connection Failed

```bash
# Check Ollama status
sudo systemctl status ollama

# Test API
curl http://localhost:11434/api/tags

# Check model is loaded
ollama list | grep qwen3-coder
```

### Slow Responses

1. **First request is slow**: Model loading into memory (normal)
2. **All requests slow**: Check GPU memory usage, may need smaller model
3. **Network issues**: If Ollama is remote, check latency

```bash
# Monitor GPU usage
nvidia-smi -l 1

# Use smaller model
export OLLAMA_MODEL=qwen2.5-coder:14b
```

### Knowledge Not Found

```bash
# Re-seed knowledge
php scripts/run-migration.php --migration=58_KnowledgeBases --workspace=gwt --verbose

# Check database exists
ls -la storage/assist/gwt/knowledge.sqlite

# Check embedding model
ollama list | grep nomic-embed-text
```

## Maintenance

### Cleanup Old Data

```php
use app\services\AssistKnowledgeStore;

$store = AssistKnowledgeStore::forWorkspace('gwt');

// Clean entries older than 90 days
$deleted = $store->cleanup(90);
echo "Deleted {$deleted} stale entries\n";
```

### Learn from Interactions

The system automatically learns from interactions. To manually trigger pattern extraction:

```php
$store = AssistKnowledgeStore::forWorkspace('gwt');

// Analyze interactions and create FAQ entries
$created = $store->learnFromInteractions();
echo "Created " . count($created) . " new FAQ entries\n";
```

### Backup Knowledge

```bash
# Simple file backup
cp storage/assist/gwt/knowledge.sqlite backups/knowledge-$(date +%Y%m%d).sqlite
```

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  Browser                                                    │
│  ┌───────────────────────────────────────────────────────┐ │
│  │  pageassist.php widget                                │ │
│  │  - Scans DOM for data-assist-* attributes             │ │
│  │  - Sends context + message via WebSocket              │ │
│  │  - Executes actions (highlight, fill, suggest)        │ │
│  └───────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
                              │ WebSocket (port 9510)
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  assist-websocket-server.php (OpenSwoole)                   │
│  ┌───────────────────────────────────────────────────────┐ │
│  │  AssistWebSocketHandler                               │ │
│  │  - Per-workspace PageAssistantService instances       │ │
│  │  - Streams responses to client                        │ │
│  │  - Triggers learning after interactions               │ │
│  └───────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
            │                               │
            ▼                               ▼
┌───────────────────────┐     ┌───────────────────────────────┐
│  AssistKnowledgeStore │     │  Ollama (localhost:11434)     │
│  - SQLite + embeddings│     │  - qwen3-coder:30b (chat)     │
│  - Semantic search    │     │  - nomic-embed-text (RAG)     │
│  - Interaction logging│     │                               │
└───────────────────────┘     └───────────────────────────────┘
```

## Files Reference

| File | Purpose |
|------|---------|
| `services/PageAssistantService.php` | Core chat logic, prompt building, action parsing |
| `services/AssistWebSocketHandler.php` | WebSocket message handling |
| `services/AssistKnowledgeStore.php` | RAG storage with embeddings |
| `scripts/assist-websocket-server.php` | Standalone WebSocket server |
| `scripts/run-migration.php --migration=58_KnowledgeBases` | Initial knowledge seeding |
| `views/partials/pageassist.php` | Frontend widget (HTML/CSS/JS) |
| `storage/assist/{workspace}/knowledge.sqlite` | Per-workspace knowledge DB |
