# AI Developer Agent Architecture

## Current Problem

Each job runs a single Claude Code session that accumulates context across multiple phases:
1. Analyze requirements (~10k tokens)
2. Explore codebase (~30k tokens cumulative)
3. Plan implementation (~50k tokens cumulative)
4. Implement changes (~80k tokens cumulative)
5. Verify changes (~100k tokens cumulative)
6. Fix issues (~120k tokens cumulative)
7. Re-verify (~140k tokens cumulative)
8. ... repeat verification loop

By iteration 4, context is bloated with old tool results, failed attempts, and irrelevant history.

## Proposed Solution: Specialized Agents

Use Claude Code's Task tool to spawn focused subagents for each phase. Each agent starts fresh with only the context it needs.

```
┌─────────────────────────────────────────────────────────────────────┐
│                         ORCHESTRATOR                                 │
│  (Minimal context - coordinates agents, tracks state)               │
│                                                                      │
│  1. Parse ticket → 2. Spawn agents → 3. Coordinate → 4. Create PR  │
└────────────────────────────┬────────────────────────────────────────┘
                             │
         ┌───────────────────┼───────────────────┐
         ▼                   ▼                   ▼
    ┌─────────┐        ┌─────────┐        ┌─────────┐
    │  IMPL   │        │ VERIFY  │        │   FIX   │
    │  AGENT  │───────▶│  AGENT  │───────▶│  AGENT  │
    └─────────┘        └─────────┘        └─────────┘
         │                   │                   │
         │                   │                   │
    Fresh context       Fresh context       Fresh context
    ~30-40k tokens      ~20-30k tokens      ~20-30k tokens
```

## Agent Definitions

### 1. Orchestrator (Main Session)

**Purpose**: Coordinate the workflow, maintain minimal state, spawn agents

**Context**:
- Ticket summary (not full history)
- Agent results (summaries only)
- PR/branch state

**Responsibilities**:
- Parse incoming ticket
- Spawn impl-agent with requirements
- Spawn verify-agent with files changed
- If issues: spawn fix-agent with specific issues
- Loop verify→fix until pass or max iterations
- Create PR with aggregated results

```php
// Orchestrator prompt template
$orchestratorPrompt = <<<PROMPT
You are an AI Developer orchestrator. Your job is to coordinate specialized agents.

TICKET: {$issueKey}
SUMMARY: {$summary}
REQUIREMENTS: {$requirementsSummary}

WORKFLOW:
1. Use Task tool with subagent_type="impl-agent" to implement changes
2. Use Task tool with subagent_type="verify-agent" to verify changes
3. If verification fails, use Task tool with subagent_type="fix-agent"
4. Repeat verify→fix until pass (max 3 iterations)
5. Create PR with final results

Start by spawning the impl-agent.
PROMPT;
```

### 2. Implementation Agent (`impl-agent`)

**Purpose**: Analyze codebase and implement the fix

**Receives**:
- Ticket requirements (parsed)
- Repository path
- Affected areas hint

**Returns**:
```json
{
  "success": true,
  "branch_name": "fix/SSI-1883-loyalty-points",
  "files_changed": ["assets/loyalty.js", "snippets/price.liquid"],
  "commit_sha": "abc123",
  "summary": "Implemented LoyaltyLion SDK rescan on dynamic product load"
}
```

**Agent Definition** (for CLAUDE.md):
```yaml
impl-agent:
  description: "Implement code changes for a ticket"
  tools: [Read, Write, Edit, Bash, Glob, Grep]
  system_prompt: |
    You are an implementation specialist. Your job is to:
    1. Explore the codebase to understand the architecture
    2. Plan the implementation approach
    3. Write the code changes
    4. Commit and push to a feature branch

    Focus on clean, minimal changes. Don't over-engineer.
    Return a JSON summary when complete.
```

### 3. Verification Agent (`verify-agent`)

**Purpose**: Test the implementation with browser/visual verification

**Receives**:
- List of files changed
- Acceptance criteria
- Preview URL (if Shopify)
- Screenshots of expected behavior

**Returns**:
```json
{
  "passed": true,
  "issues": [],
  "screenshots": ["proof-plp.png", "proof-quickview.png"]
}
// OR
{
  "passed": false,
  "issues": [
    {
      "severity": "critical",
      "description": "Loyalty points not showing on PLP",
      "location": "Collection page product cards",
      "expected": "Show 'Earn X points'",
      "actual": "Shows '+ loyalty points'",
      "screenshot": "issue-plp.png"
    }
  ]
}
```

**Agent Definition**:
```yaml
verify-agent:
  description: "Verify implementation with browser testing"
  tools: [Read, Bash, mcp__puppeteer]  # Browser automation
  system_prompt: |
    You are a QA specialist. Your job is to:
    1. Navigate to the preview URL
    2. Test the specific acceptance criteria
    3. Capture screenshots as evidence
    4. Report pass/fail with detailed issues

    Be thorough but focused on the specific changes.
    Return a JSON verification report.
```

### 4. Fix Agent (`fix-agent`)

**Purpose**: Fix specific issues found by verification

**Receives**:
- Specific issues from verify-agent (not full history)
- Files to modify
- Current file contents

**Returns**:
```json
{
  "success": true,
  "files_modified": ["assets/loyalty.js"],
  "changes_summary": "Added 500ms delay for SDK initialization"
}
```

**Agent Definition**:
```yaml
fix-agent:
  description: "Fix specific issues found during verification"
  tools: [Read, Edit, Bash]
  system_prompt: |
    You are a bug-fix specialist. You receive specific issues to fix.

    Focus ONLY on the issues provided. Don't refactor or improve
    unrelated code. Make minimal, targeted changes.

    Return a JSON summary of changes made.
```

## Implementation Plan

### Phase 1: Define Agent Types

Add to `CLAUDE.md` on shards:

```markdown
## Custom Agents

The following agent types are available for the Task tool:

- impl-agent: Implementation specialist for writing code changes
- verify-agent: QA specialist for browser-based verification
- fix-agent: Bug-fix specialist for targeted fixes
- pr-agent: PR creation and documentation specialist
```

### Phase 2: Create Orchestrator Prompt

```php
// services/AIDevAgentOrchestrator.php

class AIDevAgentOrchestrator {

    public function buildOrchestratorPrompt(array $ticket, array $repo): string {
        return <<<PROMPT
You are the AI Developer orchestrator for ticket {$ticket['key']}.

## Ticket Summary
{$ticket['summary']}

## Requirements
{$ticket['requirements']}

## Repository
- Path: {$repo['path']}
- Default branch: {$repo['default_branch']}

## Your Workflow

1. **Implementation Phase**
   Use Task tool: subagent_type="impl-agent"
   Prompt: "Implement fix for: {$ticket['summary']}. Requirements: {$ticket['requirements']}"

2. **Verification Phase** (after impl-agent returns)
   Use Task tool: subagent_type="verify-agent"
   Prompt: "Verify changes in files: [files from impl]. Acceptance criteria: {$ticket['criteria']}"

3. **Fix Phase** (if verification fails)
   Use Task tool: subagent_type="fix-agent"
   Prompt: "Fix these issues: [issues from verify]. Files to modify: [affected files]"

4. **Loop** verify→fix until pass (max 3 iterations)

5. **Create PR** when verification passes

## Important
- Pass only essential info between agents (not full conversation)
- Each agent starts fresh - include all needed context in the prompt
- Track iteration count to avoid infinite loops

Start by spawning the impl-agent.
PROMPT;
    }
}
```

### Phase 3: Update Shard Runner

Modify how the shard invokes Claude Code to use the orchestrator pattern:

```bash
# Current approach (single session, accumulating context)
claude --print "Implement and verify ticket SSI-1883..."

# New approach (orchestrator spawns focused agents)
claude --print "$ORCHESTRATOR_PROMPT"
```

The orchestrator will use `Task` tool calls internally to spawn the specialized agents.

## Context Savings Estimate

| Phase | Current (Cumulative) | Agent-Based |
|-------|---------------------|-------------|
| Analyze | 10k | 10k (orchestrator) |
| Implement | 50k | 30k (impl-agent, fresh) |
| Verify #1 | 80k | 25k (verify-agent, fresh) |
| Fix #1 | 100k | 20k (fix-agent, fresh) |
| Verify #2 | 120k | 25k (verify-agent, fresh) |
| Fix #2 | 140k | 20k (fix-agent, fresh) |
| **Total** | **140k** | **~130k but distributed** |

**Key Benefits**:
1. Each agent has focused, clean context
2. Failed attempts don't pollute future iterations
3. Agents can be parallelized (impl while previous verify runs)
4. Easier debugging - each agent's transcript is isolated
5. Can use different models per agent (haiku for simple fixes)

## File Structure

```
services/
├── AIDevAgentOrchestrator.php   # New orchestrator
├── AIDevAgent.php               # Existing (refactor to prompts)
├── agents/
│   ├── ImplAgentPrompt.php      # impl-agent prompt builder
│   ├── VerifyAgentPrompt.php    # verify-agent prompt builder
│   └── FixAgentPrompt.php       # fix-agent prompt builder
```

## Next Steps

1. [ ] Add agent type definitions to shard CLAUDE.md
2. [ ] Create AIDevAgentOrchestrator.php
3. [ ] Create agent prompt builders
4. [ ] Update shard runner to use orchestrator
5. [ ] Test on a single ticket
6. [ ] Compare context usage vs current approach
7. [ ] Iterate on agent prompts based on results
