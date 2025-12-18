# Claude Shard Agent

A lightweight API service that runs Claude Code jobs in isolation for MyCTOBot Enterprise.

## Quick Deployment to LXC

### 1. Copy files to the shard

From your development machine:

```bash
# Create a tarball of the shard-agent
cd /path/to/myctobot
tar -czvf shard-agent.tar.gz shard-agent/

# Copy to shard
scp shard-agent.tar.gz root@173.231.12.84:/tmp/
```

### 2. Install on the shard

SSH into the shard:

```bash
ssh root@173.231.12.84
```

Run these commands:

```bash
# Create directories
mkdir -p /opt/shard-agent
mkdir -p /var/lib/claude-jobs

# Extract
cd /opt
tar -xzvf /tmp/shard-agent.tar.gz
mv shard-agent/* .
rm -rf shard-agent

# Install dependencies
cd /opt/shard-agent
npm install --production

# Create .env file
cp .env.example .env

# IMPORTANT: Edit .env and set a secure API key!
nano .env
```

### 3. Configure the shard

Edit `/opt/shard-agent/.env`:

```env
SHARD_API_KEY=generate-a-secure-random-key-here
SHARD_ID=shard-general-01
SHARD_TYPE=general
```

Generate a secure API key:

```bash
openssl rand -hex 32
```

### 4. Install systemd service

```bash
# Copy service file
cp /opt/shard-agent/claude-shard-agent.service /etc/systemd/system/

# Reload systemd
systemctl daemon-reload

# Enable and start
systemctl enable claude-shard-agent
systemctl start claude-shard-agent

# Check status
systemctl status claude-shard-agent

# View logs
journalctl -u claude-shard-agent -f
```

### 5. Test the shard

```bash
# Health check (no auth required)
curl http://localhost:3500/health

# Test with auth
curl -H "Authorization: Bearer YOUR_API_KEY" http://localhost:3500/capabilities
```

## API Endpoints

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/health` | No | Health check and stats |
| GET | `/capabilities` | Yes | List shard capabilities |
| POST | `/job/execute` | Yes | Start a new job |
| GET | `/job/:id/status` | Yes | Get job status |
| GET | `/job/:id/stream` | Yes | SSE stream of job output |
| GET | `/job/:id/output` | Yes | Get full job output |
| POST | `/job/:id/cancel` | Yes | Cancel a running job |
| GET | `/jobs` | Yes | List all jobs |

## Job Execution Request

```json
POST /job/execute
Authorization: Bearer YOUR_API_KEY
Content-Type: application/json

{
  "anthropic_api_key": "sk-ant-xxx",
  "task": {
    "type": "implement_ticket",
    "issue_key": "PROJ-123",
    "summary": "Add user authentication",
    "description": "Implement OAuth2 login flow...",
    "repo_url": "https://github.com/user/repo.git",
    "repo_token": "ghp_xxx",
    "branch": "feature/auth"
  },
  "context": {
    "additional_instructions": "Use the existing User model"
  },
  "callback_url": "https://myctobot.ai/webhook/shard-callback"
}
```

## Security Notes

1. Always use HTTPS in production (set up nginx reverse proxy)
2. Keep the API key secure - it's the only authentication
3. Workspaces are cleaned up after each job
4. Customer API keys are never persisted to disk

## Troubleshooting

### Claude Code not found

```bash
# Check if claude is in PATH
which claude

# If not, install it
npm install -g @anthropic-ai/claude-code

# Or set the path in .env
CLAUDE_CODE_PATH=/usr/local/bin/claude
```

### Permission denied on workspace

```bash
# Ensure workspace directory exists and is writable
mkdir -p /var/lib/claude-jobs
chmod 755 /var/lib/claude-jobs
```

### View logs

```bash
journalctl -u claude-shard-agent -f
```
