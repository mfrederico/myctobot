import { mkdir, rm, writeFile } from 'fs/promises';
import { existsSync } from 'fs';
import path from 'path';
import { config } from './config.js';

/**
 * Workspace manager for job isolation
 * Each job gets its own directory with repo clone and Claude settings
 */
export class WorkspaceManager {
  constructor(jobId) {
    this.jobId = jobId;
    this.basePath = path.join(config.workspacePath, jobId);
    this.repoPath = path.join(this.basePath, 'repo');
    this.claudeConfigPath = path.join(this.basePath, '.claude');
  }

  /**
   * Initialize the workspace directory structure
   */
  async init() {
    await mkdir(this.basePath, { recursive: true });
    await mkdir(this.repoPath, { recursive: true });
    await mkdir(this.claudeConfigPath, { recursive: true });
    return this;
  }

  /**
   * Write Claude settings for this job
   */
  async writeClaudeSettings(mcpServers = {}) {
    const settings = {
      permissions: {
        allow: [
          "Bash(*)",
          "Read(*)",
          "Write(*)",
          "Edit(*)",
          "Glob(*)",
          "Grep(*)",
          "WebFetch(*)",
          "WebSearch(*)"
        ],
        deny: []
      },
      mcpServers: mcpServers
    };

    await writeFile(
      path.join(this.claudeConfigPath, 'settings.json'),
      JSON.stringify(settings, null, 2)
    );
  }

  /**
   * Clone a repository into the workspace
   */
  async cloneRepo(repoUrl, token, branch = null) {
    const { spawn } = await import('child_process');

    // Insert token into URL for auth
    let authUrl = repoUrl;
    if (token && repoUrl.startsWith('https://')) {
      authUrl = repoUrl.replace('https://', `https://${token}@`);
    }

    return new Promise((resolve, reject) => {
      const args = ['clone', '--depth', '1'];
      if (branch) {
        args.push('--branch', branch);
      }
      args.push(authUrl, this.repoPath);

      const proc = spawn('git', args, {
        cwd: this.basePath,
        env: { ...process.env, GIT_TERMINAL_PROMPT: '0' }
      });

      let stderr = '';
      proc.stderr.on('data', (data) => {
        stderr += data.toString();
      });

      proc.on('close', (code) => {
        if (code === 0) {
          resolve();
        } else {
          reject(new Error(`Git clone failed: ${stderr}`));
        }
      });
    });
  }

  /**
   * Get the working directory for Claude Code execution
   */
  getWorkingDir() {
    return existsSync(this.repoPath) ? this.repoPath : this.basePath;
  }

  /**
   * Clean up the workspace
   */
  async cleanup() {
    if (existsSync(this.basePath)) {
      await rm(this.basePath, { recursive: true, force: true });
    }
  }

  /**
   * Get workspace paths
   */
  getPaths() {
    return {
      base: this.basePath,
      repo: this.repoPath,
      claudeConfig: this.claudeConfigPath
    };
  }
}
