# Sharded Claude Code Execution Architecture

## Overview

A multi-tenant, isolated execution environment where Claude Code + MCP servers run on dedicated LXC containers (shards), orchestrated by the main MyCTOBot application.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           MyCTOBot SaaS (Orchestrator)                      │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐ │
│  │  Enterprise │  │   Shard     │  │    Job      │  │  Account Executive │ │
│  │  Controller │  │  Router     │  │   Queue     │  │  Admin Panel       │ │
│  └─────────────┘  └─────────────┘  └─────────────┘  └─────────────────────┘ │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │ REST API Calls
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Proxmox Hypervisor                                   │
│  ┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐          │
│  │  Shard: General  │  │ Shard: Playwright│  │ Shard: Database  │   ...    │
│  │  ─────────────── │  │ ──────────────── │  │ ──────────────── │          │
│  │  • Claude Code   │  │ • Claude Code    │  │ • Claude Code    │          │
│  │  • Git MCP       │  │ • Playwright MCP │  │ • PostgreSQL MCP │          │
│  │  • FS MCP        │  │ • Browser        │  │ • MySQL MCP      │          │
│  │  ─────────────── │  │ ──────────────── │  │ ──────────────── │          │
│  │  1 CPU, 512MB    │  │ 2 CPU, 1GB       │  │ 1 CPU, 512MB     │          │
│  │  LXC Container   │  │ LXC Container    │  │ LXC Container    │          │
│  └──────────────────┘  └──────────────────┘  └──────────────────┘          │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Phase 1: Shard Foundation (Core Infrastructure)

### 1.1 Shard Agent Service
Create a lightweight API service that runs on each LXC shard.

**Shard Agent Responsibilities:**
- Accept job execution requests via REST API
- Manage Claude Code processes
- Stream job output back to orchestrator
- Report health/status metrics
- Handle cleanup after jobs

**Tech Stack:**
- Node.js or Go (lightweight, fast startup)
- Express/Fastify or net/http
- PM2 or systemd for process management

**API Endpoints:**
```
POST /job/execute     - Start a new job
GET  /job/:id/status  - Get job status
GET  /job/:id/stream  - SSE stream of job output
POST /job/:id/cancel  - Cancel running job
GET  /health          - Health check
GET  /capabilities    - List available MCP servers
```

**Job Execution Request:**
```json
{
  "job_id": "abc123",
  "anthropic_api_key": "sk-ant-xxx",
  "task": {
    "type": "implement_ticket",
    "issue_key": "PROJ-123",
    "repo_url": "https://github.com/user/repo.git",
    "repo_token": "ghp_xxx",
    "branch": "feature/ai-dev-abc123"
  },
  "context": {
    "jira_cloud_id": "xxx",
    "jira_token": "xxx",
    "additional_instructions": "..."
  },
  "callback_url": "https://myctobot.ai/webhook/shard-callback"
}
```

### 1.2 Database Schema Updates

```sql
-- Shard definitions (configured by Account Executives)
CREATE TABLE claude_shards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    host VARCHAR(255) NOT NULL,           -- e.g., "shard-01.internal.myctobot.ai"
    port INT DEFAULT 3500,
    api_key VARCHAR(255) NOT NULL,        -- Shard authentication
    shard_type ENUM('general', 'playwright', 'database', 'custom') DEFAULT 'general',
    capabilities JSON,                     -- Available MCP servers
    resource_spec JSON,                    -- CPU, RAM, etc.
    max_concurrent_jobs INT DEFAULT 2,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Shard assignments (which tenants can use which shards)
CREATE TABLE shard_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    shard_id INT NOT NULL,
    priority INT DEFAULT 0,               -- Higher = preferred
    is_default BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shard_id) REFERENCES claude_shards(id)
);

-- Job execution tracking
CREATE TABLE shard_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id VARCHAR(64) NOT NULL UNIQUE,
    member_id INT NOT NULL,
    shard_id INT NOT NULL,
    status ENUM('queued', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'queued',
    request_payload JSON,
    result_payload JSON,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shard_id) REFERENCES claude_shards(id)
);
```

### 1.3 Shard Router Service

```php
<?php
// services/ShardRouter.php

class ShardRouter {
    /**
     * Find the best shard for a job based on:
     * - Required capabilities (MCP servers needed)
     * - Shard load (current jobs vs max)
     * - Tenant assignment
     * - Priority
     */
    public static function findShard(int $memberId, array $requiredCapabilities = []): ?array;

    /**
     * Execute a job on a shard
     */
    public static function executeJob(int $shardId, array $jobPayload): array;

    /**
     * Get job status from shard
     */
    public static function getJobStatus(int $shardId, string $jobId): array;

    /**
     * Health check all shards
     */
    public static function healthCheckAll(): array;
}
```

---

## Phase 2: Account Executive Admin Panel

### 2.1 Shard Management UI

**Features:**
- View all shards with status indicators
- Add/edit/remove shards
- Test shard connectivity
- View shard logs and metrics
- Assign shards to tenants

**Routes:**
```
GET  /admin/shards              - List all shards
GET  /admin/shards/create       - Create shard form
POST /admin/shards/create       - Create shard
GET  /admin/shards/:id          - View shard details
POST /admin/shards/:id          - Update shard
POST /admin/shards/:id/test     - Test shard connectivity
GET  /admin/shards/:id/logs     - View shard logs
POST /admin/shards/:id/assign   - Assign to tenant
```

### 2.2 Tenant Shard Assignment

Account Executives can:
- Assign specific shards to specific tenants
- Set up shard pools (e.g., "Premium Shards" for high-paying customers)
- Configure fallback behavior

```
Tenant: Acme Corp
├── Primary Shard: shard-playwright-01 (Playwright + Browser)
├── Fallback Shard: shard-general-02 (General purpose)
└── Capabilities Required: [playwright, git]
```

---

## Phase 3: LXC Shard Provisioning

### 3.1 Base Shard Template

Create an LXC template with:
- Alpine Linux or Ubuntu minimal
- Node.js runtime
- Claude Code CLI installed
- Shard Agent service
- Pre-configured MCP servers

**Shard Types:**

| Type | MCP Servers | Resources | Use Case |
|------|-------------|-----------|----------|
| General | git, filesystem | 1 CPU, 512MB | Basic code tasks |
| Playwright | playwright, browser | 2 CPU, 1GB | UI testing, scraping |
| Database | postgres, mysql, sqlite | 1 CPU, 512MB | DB operations |
| Full | All of above | 4 CPU, 2GB | Complex tasks |

### 3.2 Proxmox Automation

**Option A: Proxmox API Integration**
```php
// services/ProxmoxManager.php
class ProxmoxManager {
    public function createShard(string $name, string $type, array $resources): array;
    public function destroyShard(int $vmid): bool;
    public function getShardStatus(int $vmid): array;
    public function snapshotShard(int $vmid): string;
    public function restoreShard(int $vmid, string $snapshotId): bool;
}
```

**Option B: Terraform/Ansible Provisioning**
- Terraform for infrastructure definition
- Ansible playbooks for shard configuration
- GitOps workflow for shard management

### 3.3 Shard Configuration Files

Each shard has its MCP configuration:

```json
// /etc/claude-shard/config.json
{
  "shard_id": "shard-playwright-01",
  "shard_type": "playwright",
  "api_port": 3500,
  "api_key": "shard-secret-key",
  "max_concurrent_jobs": 2,
  "claude_code": {
    "mcp_servers": {
      "playwright": {
        "command": "npx",
        "args": ["@anthropic/mcp-playwright"]
      },
      "git": {
        "command": "npx",
        "args": ["@anthropic/mcp-git"]
      }
    }
  },
  "cleanup": {
    "job_ttl_minutes": 60,
    "workspace_path": "/tmp/claude-jobs"
  }
}
```

---

## Phase 4: Enhanced AI Developer Integration

### 4.1 Update AI Developer to Use Shards

Replace direct Claude API calls with shard-routed execution:

```php
// Before (current)
$agent = new AIDevAgent($memberId, $cloudId, $repoId, $apiKey, $jobId);
$result = $agent->processTicket($issueKey);

// After (sharded)
$shard = ShardRouter::findShard($memberId, ['git', 'filesystem']);
$result = ShardRouter::executeJob($shard['id'], [
    'job_id' => $jobId,
    'anthropic_api_key' => $apiKey,
    'task' => [
        'type' => 'implement_ticket',
        'issue_key' => $issueKey,
        // ...
    ]
]);
```

### 4.2 Real-time Job Streaming

Use Server-Sent Events to stream job progress:

```javascript
// Frontend
const eventSource = new EventSource('/enterprise/job/' + jobId + '/stream');
eventSource.onmessage = (event) => {
    const data = JSON.parse(event.data);
    updateJobProgress(data);
};
```

```php
// Backend proxies to shard
public function streamJob($params) {
    $jobId = $params['operation']->name;
    $job = ShardJobService::getJob($jobId);
    $shard = ShardService::getShard($job['shard_id']);

    // Proxy SSE from shard to client
    $this->proxySSE($shard['host'], $shard['port'], $jobId);
}
```

### 4.3 MCP Server Selection UI

Let Enterprise users choose which MCP capabilities they need:

```
Start AI Developer Job
─────────────────────────────────────────
Issue Key: [PROJ-123        ]
Repository: [my-app         ▼]

MCP Capabilities Needed:
[✓] Git Operations (branch, commit, push)
[✓] File System (read, write, search)
[ ] Playwright (browser automation, testing)
[ ] PostgreSQL (database queries)
[ ] Custom: [________________]

[Start Job]
```

---

## Phase 5: Monitoring & Scaling

### 5.1 Shard Metrics Dashboard

```
┌─────────────────────────────────────────────────────────────┐
│  Claude Shards Overview                          [Refresh] │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Total Shards: 8    Active: 7    Unhealthy: 1              │
│  Total Jobs Today: 142    Success Rate: 94%                │
│                                                             │
│  ┌─────────────────┬────────┬────────┬────────┬─────────┐  │
│  │ Shard           │ Status │ Jobs   │ CPU    │ Memory  │  │
│  ├─────────────────┼────────┼────────┼────────┼─────────┤  │
│  │ general-01      │ ● OK   │ 1/2    │ 45%    │ 312MB   │  │
│  │ general-02      │ ● OK   │ 2/2    │ 78%    │ 489MB   │  │
│  │ playwright-01   │ ● OK   │ 0/2    │ 12%    │ 256MB   │  │
│  │ playwright-02   │ ○ DOWN │ -      │ -      │ -       │  │
│  │ database-01     │ ● OK   │ 1/2    │ 23%    │ 198MB   │  │
│  └─────────────────┴────────┴────────┴────────┴─────────┘  │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 5.2 Auto-scaling Rules

```json
{
  "scaling_rules": {
    "scale_up": {
      "condition": "avg_queue_wait > 30s AND all_shards_at_capacity",
      "action": "provision_new_shard",
      "cooldown": "5m"
    },
    "scale_down": {
      "condition": "shard_idle > 30m AND shard_count > min_shards",
      "action": "deprovision_shard",
      "cooldown": "15m"
    }
  },
  "limits": {
    "min_shards": 2,
    "max_shards": 20,
    "max_shards_per_type": 5
  }
}
```

### 5.3 Job Queue Management

For high-traffic periods:
- Queue jobs when all shards are busy
- Priority queue for premium tenants
- Estimated wait time display
- Webhook notifications when job starts

---

## Phase 6: Security & Isolation

### 6.1 Network Isolation

```
┌─────────────────────────────────────────┐
│  Proxmox Host                           │
│  ┌─────────────────────────────────┐    │
│  │  vmbr1 (Shard Network)          │    │
│  │  10.10.0.0/24                   │    │
│  │  ┌───────┐ ┌───────┐ ┌───────┐  │    │
│  │  │Shard 1│ │Shard 2│ │Shard 3│  │    │
│  │  │.10    │ │.11    │ │.12    │  │    │
│  │  └───────┘ └───────┘ └───────┘  │    │
│  └─────────────────────────────────┘    │
│         ▲                               │
│         │ Internal only                 │
│  ┌──────┴──────┐                        │
│  │  API Gateway │◄── External HTTPS     │
│  │  (nginx)     │    (myctobot.ai)      │
│  └─────────────┘                        │
└─────────────────────────────────────────┘
```

### 6.2 Shard Security Checklist

- [ ] Shards on isolated VLAN, no internet egress except allowlist
- [ ] API Gateway validates all requests
- [ ] Job workspaces cleaned after completion
- [ ] No persistent storage of customer API keys
- [ ] Audit logging of all shard operations
- [ ] Rate limiting per tenant
- [ ] Resource limits enforced (CPU, memory, disk, network)

### 6.3 Secrets Management

Customer secrets (API keys, tokens) are:
1. Encrypted at rest in MyCTOBot database
2. Decrypted only when sending to shard
3. Passed in job request (HTTPS)
4. Never persisted on shard disk
5. Cleared from memory after job

---

## Implementation Priority

### MVP (Weeks 1-3)
1. [x] Basic shard agent service (Node.js)
2. [ ] Single shard integration with AI Developer
3. [ ] Manual shard configuration (database)
4. [ ] Basic admin UI for shard management

### Phase 1 Complete (Weeks 4-6)
5. [ ] Shard router with load balancing
6. [ ] Multiple shard types (general, playwright)
7. [ ] Job streaming (SSE)
8. [ ] Tenant shard assignments

### Phase 2 Complete (Weeks 7-10)
9. [ ] LXC template automation
10. [ ] Proxmox API integration
11. [ ] Metrics dashboard
12. [ ] Health monitoring & alerts

### Full Release (Weeks 11-14)
13. [ ] Auto-scaling
14. [ ] Job queuing
15. [ ] Complete security hardening
16. [ ] Documentation & runbooks

---

## Open Questions

1. **Shard Persistence**: Should shards be ephemeral (recreated per job) or persistent (reused)?
   - Ephemeral: Maximum isolation, slower startup
   - Persistent: Faster, need careful cleanup

2. **MCP Server Licensing**: Do any MCP servers require licensing for commercial use?

3. **Customer-Provided MCPs**: Should customers be able to bring their own MCP servers?
   - Security implications of running untrusted code

4. **Hybrid Mode**: Support both sharded (heavy jobs) and direct API (simple jobs)?

5. **Multi-Region**: Shards in different regions for latency/compliance?

---

## Appendix: Shard Agent Reference Implementation

See `/services/shard-agent/` for the Node.js reference implementation.

```
shard-agent/
├── package.json
├── src/
│   ├── index.js          # Main entry point
│   ├── api.js            # Express routes
│   ├── executor.js       # Claude Code process manager
│   ├── workspace.js      # Job workspace management
│   └── metrics.js        # Prometheus metrics
├── config/
│   └── default.json      # Default configuration
└── Dockerfile            # Container build
```
