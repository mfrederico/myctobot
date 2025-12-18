import { config as dotenvConfig } from 'dotenv';
dotenvConfig();

export const config = {
  // Server settings
  port: parseInt(process.env.SHARD_PORT || '3500'),
  host: process.env.SHARD_HOST || '0.0.0.0',

  // Authentication
  apiKey: process.env.SHARD_API_KEY || 'change-me-in-production',

  // Shard identification
  shardId: process.env.SHARD_ID || 'shard-01',
  shardType: process.env.SHARD_TYPE || 'general',

  // Job settings
  maxConcurrentJobs: parseInt(process.env.MAX_CONCURRENT_JOBS || '2'),
  jobTimeoutMs: parseInt(process.env.JOB_TIMEOUT_MS || '600000'), // 10 minutes
  workspacePath: process.env.WORKSPACE_PATH || '/var/lib/claude-jobs',

  // Claude Code settings
  claudeCodePath: process.env.CLAUDE_CODE_PATH || 'claude',

  // Cleanup
  cleanupAfterJob: process.env.CLEANUP_AFTER_JOB !== 'false',

  // Available MCP servers on this shard
  capabilities: (process.env.CAPABILITIES || 'git,filesystem').split(',').map(s => s.trim())
};
