import { spawn } from 'child_process';
import { EventEmitter } from 'events';
import { config } from './config.js';
import { WorkspaceManager } from './workspace.js';

/**
 * Job status constants
 */
export const JobStatus = {
  QUEUED: 'queued',
  RUNNING: 'running',
  COMPLETED: 'completed',
  FAILED: 'failed',
  CANCELLED: 'cancelled'
};

/**
 * Manages Claude Code execution for a single job
 */
export class JobExecutor extends EventEmitter {
  constructor(jobId, payload) {
    super();
    this.jobId = jobId;
    this.payload = payload;
    this.status = JobStatus.QUEUED;
    this.output = [];
    this.error = null;
    this.result = null;
    this.process = null;
    this.workspace = new WorkspaceManager(jobId);
    this.startedAt = null;
    this.completedAt = null;
  }

  /**
   * Execute the job
   */
  async execute() {
    this.status = JobStatus.RUNNING;
    this.startedAt = new Date().toISOString();
    this.emit('status', { status: this.status, message: 'Job started' });

    try {
      // Initialize workspace
      this.emit('log', { level: 'info', message: 'Initializing workspace...' });
      await this.workspace.init();

      // Clone repository if specified
      if (this.payload.task?.repo_url) {
        this.emit('log', { level: 'info', message: 'Cloning repository...' });
        await this.workspace.cloneRepo(
          this.payload.task.repo_url,
          this.payload.task.repo_token,
          this.payload.task.branch
        );
        this.emit('log', { level: 'info', message: 'Repository cloned successfully' });
      }

      // Write Claude settings
      await this.workspace.writeClaudeSettings(this.payload.mcp_servers || {});

      // Build the prompt for Claude
      const prompt = this.buildPrompt();

      // Execute Claude Code
      this.emit('log', { level: 'info', message: 'Starting Claude Code...' });
      const result = await this.runClaudeCode(prompt);

      this.result = result;
      this.status = JobStatus.COMPLETED;
      this.completedAt = new Date().toISOString();
      this.emit('status', { status: this.status, message: 'Job completed successfully' });
      this.emit('complete', { result });

    } catch (err) {
      this.error = err.message;
      this.status = JobStatus.FAILED;
      this.completedAt = new Date().toISOString();
      this.emit('status', { status: this.status, message: `Job failed: ${err.message}` });
      this.emit('error', { error: err.message });

    } finally {
      // Cleanup workspace if configured
      if (config.cleanupAfterJob) {
        try {
          await this.workspace.cleanup();
          this.emit('log', { level: 'info', message: 'Workspace cleaned up' });
        } catch (cleanupErr) {
          this.emit('log', { level: 'warn', message: `Cleanup failed: ${cleanupErr.message}` });
        }
      }
    }
  }

  /**
   * Build the prompt for Claude Code based on task type
   */
  buildPrompt() {
    const task = this.payload.task || {};
    const context = this.payload.context || {};

    let prompt = '';

    switch (task.type) {
      case 'implement_ticket':
        prompt = `You are an AI Developer assistant. Your task is to implement the following Jira ticket.

Issue Key: ${task.issue_key}
${task.summary ? `Summary: ${task.summary}` : ''}
${task.description ? `Description:\n${task.description}` : ''}

${context.additional_instructions ? `Additional Instructions:\n${context.additional_instructions}` : ''}

Please:
1. Analyze the requirements
2. Explore the codebase to understand the structure
3. Implement the necessary changes
4. Create a git branch named: ${task.branch || 'feature/ai-dev-' + this.jobId.slice(0, 8)}
5. Commit your changes with a descriptive message
6. Push the branch to origin

If you need clarification on any requirements, list your questions clearly.`;
        break;

      case 'code_review':
        prompt = `Review the code in this repository and provide feedback on:
1. Code quality and best practices
2. Potential bugs or issues
3. Performance concerns
4. Security considerations

${context.additional_instructions || ''}`;
        break;

      case 'run_tests':
        prompt = `Run the test suite for this project and report the results.
${context.test_command ? `Use command: ${context.test_command}` : 'Detect and run the appropriate test command.'}

${context.additional_instructions || ''}`;
        break;

      case 'custom':
        prompt = task.prompt || context.additional_instructions || 'No task specified';
        break;

      default:
        prompt = task.prompt || context.additional_instructions || 'No task specified';
    }

    return prompt;
  }

  /**
   * Run Claude Code with the given prompt
   */
  async runClaudeCode(prompt) {
    return new Promise((resolve, reject) => {
      const workingDir = this.workspace.getWorkingDir();

      // Build Claude Code arguments
      const args = [
        '--print',  // Non-interactive mode, print output
        '--dangerously-skip-permissions',  // Skip permission prompts
        prompt
      ];

      // Set up environment with customer's API key
      const env = {
        ...process.env,
        ANTHROPIC_API_KEY: this.payload.anthropic_api_key,
        HOME: this.workspace.getPaths().base,  // Isolate home directory
      };

      this.emit('log', { level: 'debug', message: `Executing in: ${workingDir}` });

      this.process = spawn(config.claudeCodePath, args, {
        cwd: workingDir,
        env,
        timeout: config.jobTimeoutMs
      });

      let stdout = '';
      let stderr = '';

      this.process.stdout.on('data', (data) => {
        const text = data.toString();
        stdout += text;
        this.output.push({ type: 'stdout', text, timestamp: new Date().toISOString() });
        this.emit('output', { type: 'stdout', text });
      });

      this.process.stderr.on('data', (data) => {
        const text = data.toString();
        stderr += text;
        this.output.push({ type: 'stderr', text, timestamp: new Date().toISOString() });
        this.emit('output', { type: 'stderr', text });
      });

      this.process.on('close', (code) => {
        if (code === 0) {
          resolve({
            exitCode: code,
            stdout,
            stderr,
            output: this.output
          });
        } else {
          reject(new Error(`Claude Code exited with code ${code}: ${stderr || stdout}`));
        }
      });

      this.process.on('error', (err) => {
        reject(new Error(`Failed to start Claude Code: ${err.message}`));
      });
    });
  }

  /**
   * Cancel the job
   */
  cancel() {
    if (this.process && this.status === JobStatus.RUNNING) {
      this.process.kill('SIGTERM');
      this.status = JobStatus.CANCELLED;
      this.completedAt = new Date().toISOString();
      this.emit('status', { status: this.status, message: 'Job cancelled' });
    }
  }

  /**
   * Get job status summary
   */
  getStatus() {
    return {
      job_id: this.jobId,
      status: this.status,
      started_at: this.startedAt,
      completed_at: this.completedAt,
      error: this.error,
      output_lines: this.output.length,
      result: this.result
    };
  }
}

/**
 * Job queue manager
 */
export class JobManager {
  constructor() {
    this.jobs = new Map();
    this.runningCount = 0;
  }

  /**
   * Create and queue a new job
   */
  createJob(jobId, payload) {
    if (this.jobs.has(jobId)) {
      throw new Error(`Job ${jobId} already exists`);
    }

    const executor = new JobExecutor(jobId, payload);
    this.jobs.set(jobId, executor);

    return executor;
  }

  /**
   * Start executing a job
   */
  async startJob(jobId) {
    const executor = this.jobs.get(jobId);
    if (!executor) {
      throw new Error(`Job ${jobId} not found`);
    }

    if (this.runningCount >= config.maxConcurrentJobs) {
      throw new Error(`Maximum concurrent jobs (${config.maxConcurrentJobs}) reached`);
    }

    this.runningCount++;

    executor.on('complete', () => {
      this.runningCount--;
    });

    executor.on('error', () => {
      this.runningCount--;
    });

    // Execute in background
    executor.execute().catch(err => {
      console.error(`Job ${jobId} failed:`, err);
    });

    return executor;
  }

  /**
   * Get a job by ID
   */
  getJob(jobId) {
    return this.jobs.get(jobId);
  }

  /**
   * Cancel a job
   */
  cancelJob(jobId) {
    const executor = this.jobs.get(jobId);
    if (executor) {
      executor.cancel();
    }
  }

  /**
   * Get all jobs summary
   */
  getAllJobs() {
    const jobs = [];
    for (const [id, executor] of this.jobs) {
      jobs.push(executor.getStatus());
    }
    return jobs;
  }

  /**
   * Clean up old completed jobs from memory
   */
  cleanup(maxAgeMs = 3600000) {
    const now = Date.now();
    for (const [id, executor] of this.jobs) {
      if (executor.completedAt) {
        const completedTime = new Date(executor.completedAt).getTime();
        if (now - completedTime > maxAgeMs) {
          this.jobs.delete(id);
        }
      }
    }
  }

  /**
   * Get current stats
   */
  getStats() {
    let queued = 0, running = 0, completed = 0, failed = 0;

    for (const executor of this.jobs.values()) {
      switch (executor.status) {
        case JobStatus.QUEUED: queued++; break;
        case JobStatus.RUNNING: running++; break;
        case JobStatus.COMPLETED: completed++; break;
        case JobStatus.FAILED: failed++; break;
      }
    }

    return { queued, running, completed, failed, total: this.jobs.size };
  }
}

// Singleton instance
export const jobManager = new JobManager();
