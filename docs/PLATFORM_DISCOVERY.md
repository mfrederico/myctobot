# MyCTOBot Platform Discovery Report

## Executive Summary

MyCTOBot is an **AI-powered development orchestration platform** that transforms business directives into working code through automated workflows. The platform connects Jira, GitHub, and Shopify with Claude AI to implement features, create PRs, and manage the full development lifecycle.

---

## Part 1: Platform Discovery

### Core Value Proposition
- **From Idea to Production** - CEOs/managers send directives, AI breaks them into implementable stories
- **CLAUDE.md as Constitution** - Coding standards, security requirements, and patterns enforced by AI
- **Label-Based Automation** - Add `ai-dev` label in Jira/GitHub to trigger AI implementation
- **Security-First Architecture** - OWASP compliance, CSRF protection, input validation baked in

### Pricing Tiers
| Tier | Price | Features |
|------|-------|----------|
| Free | $0/month | 1 project, daily digests, basic AI analysis |
| Pro | $150/month | Unlimited projects, AI Developer agents, CEO Directives, Review Board, Shopify integration |

---

## Part 2: System Concepts & Primitives

### A. CEO Directives Workflow

**Concept**: Email-based project intake system where executives send high-level directives that get parsed, planned, and executed by AI.

**Primitive Flow**:
```
Email to {workspace}@myctobot.ai
    → Received (parsing)
    → Planning (AI breaks into projects/stories)
    → Executing (AI implements)
    → Completed/Failed
```

**Key Components**:
- **Intent Detection**: Project vs Story vs Bug
- **Auto Mode**: Automatic processing vs manual approval
- **Project Decomposition**: Directives → Projects → Epics → Stories

### B. Projects

**Concept**: Container for related work items generated from CEO directives.

**Statuses**: Planning → In Progress → Blocked → Completed

**Relationship**: Directives create Projects, Projects contain Stories

### C. Review Board

**Concept**: Kanban-style approval interface for AI-generated stories before Jira issue creation.

**Key Features**:
- AI Runner concurrency control (1-4 runners)
- Story approval workflow
- Batch processing capability

### D. Boards (Jira Integration)

**Concept**: Jira sprint boards tracked for analysis and AI automation.

**Features**:
- Sprint analysis and metrics
- Daily digest emails
- AI Developer integration via `ai-dev` label

### E. AI Developer System

**Concept**: Automated code implementation from Jira tickets using Claude Code CLI.

**Workflow**:
```
1. Add `ai-dev` label to Jira ticket
2. AI analyzes requirements
3. If unclear → posts questions to Jira
4. Clones repo, implements changes
5. Creates GitHub PR
6. Updates Jira with results
```

**Components**:
- **Agent Profiles** - HOW jobs run (MCP servers, hooks, AI provider)
- **Workstations** - WHERE jobs run (remote servers with Claude CLI)
- **Jobs** - Individual execution instances with logs

### F. Agent Profiles

**Concept**: Configuration templates defining how AI Developer jobs execute.

**Configuration Options**:
- AI provider selection (Anthropic)
- MCP server attachments
- Pre/post execution hooks
- Runner assignment

**Default MCP Servers** (auto-configured):
- Jira (read/write issues)
- Playwright (browser automation)

### G. Workstations (Runners)

**Concept**: Server infrastructure where AI agents execute code.

**Types**:
- Remote Server (VPS/dedicated)
- Local Machine

**Capabilities**:
- Clone repositories
- Run Claude Code CLI
- Create commits/branches
- Run tests and builds

**Management**:
- SSH key configuration
- Health checks
- Capacity monitoring

### H. Repository Connections

**Concept**: GitHub repositories linked for AI-powered code changes.

**Label System**:
- Each repo gets unique label: `repo-{slug}`
- Add repo label first, then `ai-dev` to trigger
- Webhooks auto-created for push events

### I. Knowledge Base

**Concept**: RAG document storage for AI context enhancement.

**Features**:
- 500 MB storage limit
- Supports: PDFs, Word docs, images, web pages
- Multiple knowledge bases per workspace
- Search functionality

### J. MCP Server Library

**Concept**: Reusable MCP server configurations shared across agents.

**Auto-Configured Servers**:
- Jira (read/write)
- Playwright (browser automation)

**Custom Servers**: User-defined MCP configurations

### K. Pipelines (Automation Engine)

**Concept**: Spreadsheet-like automation workflows with phases and steps.

**Architecture**:
```
Pipeline
├── Columns (Phases): Start → Execute → Validate → Complete
└── Rows (Parallel paths)
    └── Steps (Cells with named outputs)
```

**Step Types**:
| Type | Description | Icon |
|------|-------------|------|
| `ai_agent` | Run AI agents (impl, verify, fix) | Robot |
| `script` | Execute repo scripts | Code |
| `direct_exec` | Shell commands with stdin/stdout | Terminal |
| `parser` | Transform data (jq, php, custom) | Braces |
| `webhook_out` | POST to external services | Send |
| `wait` | Wait for events or approval | Hourglass |
| `harvest` | Gather parallel row results | Collection |

**Trigger Types**:
| Type | Description |
|------|-------------|
| `manual` | Form or API trigger |
| `webhook` | Incoming webhooks (Jira, GitHub, custom) |
| `cron` | Scheduled execution |
| `http_poll` | Poll URLs at intervals |

**MCP Tool Exposure**:
- Pipelines can be exposed as MCP tools
- `GET /pipelines/mcp/tools/{workspace}` - List available tools
- `POST /pipelines/mcp/call/{workspace}/{slug}` - Execute as tool

### L. Plugin Marketplace

**Concept**: Searchable catalog of plugins/integrations.

**Features**:
- Category filtering
- Relevance-ranked search
- Autocomplete suggestions
- View count tracking

### M. Integrations

| Integration | Purpose | Required Scopes |
|-------------|---------|-----------------|
| **Atlassian/Jira** | Sprint analysis, daily digests, AI Developer | read/write issues |
| **GitHub** | Code implementation, automated PRs | push access |
| **Anthropic API** | Claude AI for code generation | API key |
| **Shopify** | Theme development | read_themes, write_themes |
| **Mailgun** | Email notifications, digest delivery | send access |

### N. Subscription & Billing

**Concept**: Stripe-powered subscription management.

**Features**:
- Plan upgrades
- Usage tracking
- Workspace-level billing

---

## Part 3: UX Bottlenecks & Observations

### Identified Issues

1. **Directive → Project → Story Flow Unclear**
   - New users may not understand how directives become implementable work
   - Recommendation: Add visual workflow diagram on directives page

2. **AI Runners Selector Confusing**
   - Review Board has "AI Runners: 1-4" dropdown
   - Not immediately clear what this controls
   - Recommendation: Tooltip explaining concurrent job limit

3. **Email-Based Directives Not Discoverable**
   - `{workspace}@myctobot.ai` email not prominently displayed
   - Recommendation: Add to dashboard with "send directive" prompt

4. **Workstation vs Agent Profile Distinction**
   - "HOW vs WHERE" explained on page but may confuse new users
   - Recommendation: Setup wizard handles this well - make it more prominent

5. **Label-Based Triggering**
   - `repo-{slug}` + `ai-dev` pattern requires documentation
   - Recommendation: In-app guide or assistant

### Positive UX Elements

- **Setup Wizard** - Guided 5-step onboarding flow
- **Dashboard Hub** - Clear navigation to all features
- **Connection Status Cards** - Visual indicators of setup progress
- **Explanatory Text** - Good descriptions on empty states

---

## Part 4: Database Entities (Models)

### Core Tables
- `member` - Users
- `jiraboards` - Tracked Jira boards
- `subscription` - Billing plans
- `pluginregistry` - Installed plugins
- `pluginversion` - Plugin versions

### AI Developer Tables
- `aiagents` - Agent profiles
- `aidevjobs` - Job instances
- `aidevjoblogs` - Job execution logs
- `runners` - Workstation configurations

### Pipeline Tables
- `pipelines` - Pipeline definitions
- `pipelinesteps` - Step configurations
- `pipelineruns` - Run instances
- `pipelinestepruns` - Step execution records

### Integration Tables
- `repoconnections` - GitHub repositories
- `atlassiantoken` - Jira OAuth tokens
- `shopifystores` - Shopify store connections

---

## Part 5: Technical Architecture

### Framework Stack
- **PHP 8.x** with FlightPHP routing
- **RedBeanPHP** ORM with Bean wrapper
- **Bootstrap 5.3** + jQuery frontend
- **Claude Code CLI** for AI execution

### Multi-Tenancy
- Session-based workspace switching
- Per-workspace databases (MySQL/SQLite)
- Config files: `conf/config.{workspace}.ini`
- Unique subdomains: `{workspace}.myctobot.ai`

### Security Model
- CSRF token validation on POST
- Permission levels: ROOT(1) → ADMIN(50) → MEMBER(100) → PUBLIC(101)
- Input sanitization via `$this->sanitize()`
- OAuth 2.0 for integrations

---

## Appendix: Key URLs

| Feature | URL Pattern |
|---------|-------------|
| Dashboard | `/settings/connections` |
| CEO Directives | `/directives` |
| Projects | `/projects` |
| Review Board | `/reviewboard` |
| AI Developer | `/enterprise` |
| Jobs | `/jobs` |
| Agent Profiles | `/agents` |
| Workstations | `/admin/runners` |
| Knowledge Base | `/knowledgebase` |
| MCP Servers | `/mcpservers` |
| Pipelines | `/pipelines` |
| Repositories | `/github/repos` |
| Boards | `/boards` |
| Shopify | `/shopify` |
| Plugin Marketplace | `/plugins` |

---

*Generated: January 21, 2026*
