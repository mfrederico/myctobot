# MyCTOBot Enterprise Tier Setup Guide

This guide covers the complete setup process for enabling Enterprise tier features, including the AI Developer.

---

## Table of Contents

1. [Server Configuration](#1-server-configuration)
2. [GitHub OAuth Setup](#2-github-oauth-setup)
3. [Webhook Configuration (Optional)](#3-webhook-configuration-optional)
4. [Enable Enterprise for a Customer](#4-enable-enterprise-for-a-customer)
5. [Customer Setup Steps](#5-customer-setup-steps)

---

## 1. Server Configuration

### Encryption Master Key

The encryption key secures customer API keys and OAuth tokens. **This must be configured before any Enterprise features work.**

1. Generate a 64-character hex key:
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```

2. Add to `conf/config.ini`:
   ```ini
   [encryption]
   master_key = "your_64_character_hex_key_here"
   ```

> **WARNING**: Changing this key after customers have saved API keys will invalidate all their encrypted data. Back up this key securely.

---

## 2. GitHub OAuth Setup

GitHub OAuth allows Enterprise customers to connect their repositories for the AI Developer feature.

### Step 1: Create GitHub OAuth App

1. Go to https://github.com/settings/developers
2. Click **OAuth Apps** in the left sidebar
3. Click **New OAuth App**
4. Fill in the form:
   | Field | Value |
   |-------|-------|
   | Application name | `MyCTOBot` |
   | Homepage URL | `https://myctobot.ai` |
   | Authorization callback URL | `https://myctobot.ai/enterprise/githubcallback` |
5. Click **Register application**

### Step 2: Get Credentials

On the app page after registration:
- Copy the **Client ID**
- Click **Generate a new client secret**
- Copy the **Client Secret** (shown only once!)

### Step 3: Update config.ini

```ini
[github]
client_id = "Iv1.abc123..."
client_secret = "your_client_secret_here"
redirect_uri = "https://myctobot.ai/enterprise/githubcallback"
```

### Notes

- **Device Flow**: Leave disabled - MyCTOBot uses web-based OAuth flow
- **Scopes requested**: `repo`, `read:user` (handled automatically in code)

---

## 3. Webhook Configuration (Optional)

Webhooks enable automatic triggers:
- **Jira webhook**: Auto-start AI Developer when `ai-dev` label is added
- **GitHub webhook**: Receive PR status updates

These are optional - customers can manually trigger jobs from the dashboard.

### Jira Webhook Setup

1. Generate a secret:
   ```bash
   php -r "echo bin2hex(random_bytes(20));"
   ```

2. Add to `conf/config.ini`:
   ```ini
   [webhooks]
   jira_secret = "your_generated_secret"
   ```

3. In Jira Cloud:
   - Go to **Settings → System → Webhooks**
   - Click **Create a WebHook**
   - Configure:
     | Field | Value |
     |-------|-------|
     | Name | `MyCTOBot AI Developer` |
     | URL | `https://myctobot.ai/webhook/jira` |
     | Secret | Same secret from step 1 |
     | Events | Issue updated, Comment created |

### GitHub Webhook Setup

1. Generate a secret:
   ```bash
   php -r "echo bin2hex(random_bytes(20));"
   ```

2. Add to `conf/config.ini`:
   ```ini
   [webhooks]
   github_secret = "your_generated_secret"
   ```

3. In GitHub repository:
   - Go to **Settings → Webhooks → Add webhook**
   - Configure:
     | Field | Value |
     |-------|-------|
     | Payload URL | `https://myctobot.ai/webhook/github` |
     | Content type | `application/json` |
     | Secret | Same secret from step 1 |
     | Events | Pull requests, Pull request reviews |

---

## 4. Enable Enterprise for a Customer

### Via PHP Script

Run from the project root:

```bash
php -r "
require 'vendor/autoload.php';
require 'bootstrap.php';
\$bootstrap = new \app\Bootstrap('conf/config.ini');
\app\services\SubscriptionService::setEnterpriseByEmail('customer@example.com');
echo 'Done';
"
```

### Via Database (Alternative)

```sql
-- Find member ID
SELECT id, email FROM member WHERE email = 'customer@example.com';

-- Update or insert subscription
INSERT INTO subscription (member_id, tier, status, current_period_start, current_period_end)
VALUES (MEMBER_ID, 'enterprise', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR))
ON DUPLICATE KEY UPDATE tier = 'enterprise', status = 'active';
```

### Create Enterprise Tables for Existing Users

If the customer already has a user database, run this SQL on their SQLite database:

```bash
sqlite3 database/USER_DB_HASH.sqlite
```

```sql
CREATE TABLE IF NOT EXISTS enterprise_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key TEXT NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    is_encrypted INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

CREATE TABLE IF NOT EXISTS repo_connections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    board_id INTEGER,
    provider TEXT NOT NULL,
    repo_owner TEXT NOT NULL,
    repo_name TEXT NOT NULL,
    default_branch TEXT DEFAULT 'main',
    clone_url TEXT NOT NULL,
    access_token TEXT,
    enabled INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT,
    UNIQUE(provider, repo_owner, repo_name)
);

CREATE TABLE IF NOT EXISTS board_repo_mappings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    board_id INTEGER NOT NULL,
    repo_connection_id INTEGER NOT NULL,
    is_default INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(board_id, repo_connection_id)
);

CREATE TABLE IF NOT EXISTS ai_dev_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id TEXT NOT NULL UNIQUE,
    board_id INTEGER NOT NULL,
    issue_key TEXT NOT NULL,
    repo_connection_id INTEGER,
    status TEXT DEFAULT 'pending',
    branch_name TEXT,
    pr_url TEXT,
    pr_number INTEGER,
    clarification_comment_id TEXT,
    error_message TEXT,
    progress_json TEXT,
    started_at TEXT,
    completed_at TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);
```

---

## 5. Customer Setup Steps

Once Enterprise is enabled, the customer completes these steps in the MyCTOBot dashboard:

### Step 1: Configure Anthropic API Key

1. Go to https://console.anthropic.com/
2. Sign in or create an account
3. Navigate to **API Keys**
4. Click **Create Key**
5. Copy the key (starts with `sk-ant-`)
6. In MyCTOBot: Go to **/enterprise/settings**
7. Paste the API key and save

### Step 2: Connect GitHub

1. In MyCTOBot: Go to **/enterprise**
2. Click **Connect GitHub**
3. Authorize MyCTOBot to access repositories
4. Select repositories to connect

### Step 3: Map Repositories to Boards

1. Go to **/enterprise/repos**
2. For each connected repo, map it to a Jira board
3. Set a default repo for each board

### Step 4: (Optional) Upgrade Jira Scopes

For the AI Developer to post clarification questions to Jira:

1. In MyCTOBot: Go to **/enterprise**
2. Click **Upgrade Scopes** under Jira Write
3. Re-authorize with Atlassian to grant write permissions

---

## Troubleshooting

### "Invalid encryption key format" Error

The encryption master key must be exactly 64 hex characters (32 bytes).

```bash
# Generate a valid key
php -r "echo bin2hex(random_bytes(32));"
```

### "GitHub integration is not configured" Error

Check that `client_id` and `client_secret` are set in `conf/config.ini` under `[github]`.

### Enterprise Menu Not Showing

1. Verify the customer has Enterprise tier:
   ```sql
   SELECT * FROM subscription WHERE member_id = X;
   ```
2. Check the tier is `enterprise` and status is `active`

### Jobs Stuck in "Pending"

1. Check storage directory exists and is writable:
   ```bash
   ls -la storage/aidev_status/
   ```
2. Check the background script runs:
   ```bash
   php scripts/ai-dev-agent.php --help
   ```

---

## File Reference

| File | Purpose |
|------|---------|
| `conf/config.ini` | Main configuration (encryption, GitHub OAuth, webhooks) |
| `services/EncryptionService.php` | Handles API key encryption |
| `services/AIDevAgent.php` | Main AI Developer orchestrator |
| `services/AIDevStatusService.php` | Job status tracking |
| `controls/Enterprise.php` | Enterprise controller |
| `controls/Webhook.php` | Webhook handlers |
| `scripts/ai-dev-agent.php` | Background job runner |
| `storage/aidev_status/` | Job status files |

---

## Security Checklist

- [ ] Encryption master key is 64 hex characters
- [ ] Encryption master key is backed up securely
- [ ] GitHub client secret is not committed to version control
- [ ] Webhook secrets are configured (if using webhooks)
- [ ] `storage/aidev_status/` is not web-accessible
- [ ] Customer API keys are encrypted in SQLite databases
