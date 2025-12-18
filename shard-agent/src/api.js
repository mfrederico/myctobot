import express from 'express';
import { v4 as uuidv4 } from 'uuid';
import { config } from './config.js';
import { jobManager, JobStatus } from './executor.js';

const router = express.Router();

/**
 * Authentication middleware
 */
const authenticate = (req, res, next) => {
  const authHeader = req.headers.authorization;

  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    return res.status(401).json({ error: 'Missing or invalid authorization header' });
  }

  const token = authHeader.substring(7);
  if (token !== config.apiKey) {
    return res.status(403).json({ error: 'Invalid API key' });
  }

  next();
};

// Apply authentication to all routes except health
router.use((req, res, next) => {
  if (req.path === '/health') {
    return next();
  }
  authenticate(req, res, next);
});

/**
 * Health check endpoint (no auth required)
 */
router.get('/health', (req, res) => {
  const stats = jobManager.getStats();
  res.json({
    status: 'ok',
    shard_id: config.shardId,
    shard_type: config.shardType,
    timestamp: new Date().toISOString(),
    jobs: stats,
    capabilities: config.capabilities,
    max_concurrent_jobs: config.maxConcurrentJobs
  });
});

/**
 * Get shard capabilities
 */
router.get('/capabilities', (req, res) => {
  res.json({
    shard_id: config.shardId,
    shard_type: config.shardType,
    capabilities: config.capabilities,
    max_concurrent_jobs: config.maxConcurrentJobs,
    job_timeout_ms: config.jobTimeoutMs
  });
});

/**
 * Execute a new job
 *
 * POST /job/execute
 * Body: {
 *   job_id: string (optional, will be generated if not provided)
 *   anthropic_api_key: string (required)
 *   task: {
 *     type: 'implement_ticket' | 'code_review' | 'run_tests' | 'custom'
 *     issue_key: string (for implement_ticket)
 *     summary: string
 *     description: string
 *     repo_url: string
 *     repo_token: string
 *     branch: string
 *     prompt: string (for custom)
 *   }
 *   context: {
 *     additional_instructions: string
 *     jira_cloud_id: string
 *     jira_token: string
 *   }
 *   mcp_servers: object (optional MCP server config)
 *   callback_url: string (optional webhook URL)
 * }
 */
router.post('/job/execute', async (req, res) => {
  try {
    const payload = req.body;

    // Validate required fields
    if (!payload.anthropic_api_key) {
      return res.status(400).json({ error: 'anthropic_api_key is required' });
    }

    // Generate job ID if not provided
    const jobId = payload.job_id || uuidv4();

    // Check capacity
    const stats = jobManager.getStats();
    if (stats.running >= config.maxConcurrentJobs) {
      return res.status(429).json({
        error: 'Shard at capacity',
        running_jobs: stats.running,
        max_jobs: config.maxConcurrentJobs
      });
    }

    // Create and start the job
    const executor = jobManager.createJob(jobId, payload);

    // Set up callback if provided
    if (payload.callback_url) {
      executor.on('complete', async (data) => {
        try {
          await fetch(payload.callback_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              job_id: jobId,
              status: 'completed',
              result: data.result
            })
          });
        } catch (err) {
          console.error(`Callback failed for job ${jobId}:`, err);
        }
      });

      executor.on('error', async (data) => {
        try {
          await fetch(payload.callback_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              job_id: jobId,
              status: 'failed',
              error: data.error
            })
          });
        } catch (err) {
          console.error(`Callback failed for job ${jobId}:`, err);
        }
      });
    }

    // Start execution (async, don't await)
    jobManager.startJob(jobId);

    res.status(202).json({
      success: true,
      job_id: jobId,
      status: 'queued',
      message: 'Job accepted and starting execution'
    });

  } catch (err) {
    console.error('Failed to execute job:', err);
    res.status(500).json({ error: err.message });
  }
});

/**
 * Get job status
 */
router.get('/job/:id/status', (req, res) => {
  const executor = jobManager.getJob(req.params.id);

  if (!executor) {
    return res.status(404).json({ error: 'Job not found' });
  }

  res.json(executor.getStatus());
});

/**
 * Stream job output (Server-Sent Events)
 */
router.get('/job/:id/stream', (req, res) => {
  const executor = jobManager.getJob(req.params.id);

  if (!executor) {
    return res.status(404).json({ error: 'Job not found' });
  }

  // Set up SSE
  res.setHeader('Content-Type', 'text/event-stream');
  res.setHeader('Cache-Control', 'no-cache');
  res.setHeader('Connection', 'keep-alive');

  // Send existing output first
  for (const entry of executor.output) {
    res.write(`data: ${JSON.stringify(entry)}\n\n`);
  }

  // Stream new output
  const onOutput = (data) => {
    res.write(`data: ${JSON.stringify(data)}\n\n`);
  };

  const onStatus = (data) => {
    res.write(`event: status\ndata: ${JSON.stringify(data)}\n\n`);
  };

  const onComplete = (data) => {
    res.write(`event: complete\ndata: ${JSON.stringify(data)}\n\n`);
    cleanup();
  };

  const onError = (data) => {
    res.write(`event: error\ndata: ${JSON.stringify(data)}\n\n`);
    cleanup();
  };

  const cleanup = () => {
    executor.off('output', onOutput);
    executor.off('status', onStatus);
    executor.off('complete', onComplete);
    executor.off('error', onError);
    res.end();
  };

  executor.on('output', onOutput);
  executor.on('status', onStatus);
  executor.on('complete', onComplete);
  executor.on('error', onError);

  // Handle client disconnect
  req.on('close', cleanup);

  // If job already completed, close stream
  if (executor.status === JobStatus.COMPLETED ||
      executor.status === JobStatus.FAILED ||
      executor.status === JobStatus.CANCELLED) {
    res.write(`event: status\ndata: ${JSON.stringify({ status: executor.status })}\n\n`);
    res.end();
  }
});

/**
 * Get job output (non-streaming)
 */
router.get('/job/:id/output', (req, res) => {
  const executor = jobManager.getJob(req.params.id);

  if (!executor) {
    return res.status(404).json({ error: 'Job not found' });
  }

  res.json({
    job_id: req.params.id,
    status: executor.status,
    output: executor.output,
    error: executor.error,
    result: executor.result
  });
});

/**
 * Cancel a job
 */
router.post('/job/:id/cancel', (req, res) => {
  const executor = jobManager.getJob(req.params.id);

  if (!executor) {
    return res.status(404).json({ error: 'Job not found' });
  }

  if (executor.status !== JobStatus.RUNNING && executor.status !== JobStatus.QUEUED) {
    return res.status(400).json({ error: `Cannot cancel job in status: ${executor.status}` });
  }

  executor.cancel();

  res.json({
    success: true,
    job_id: req.params.id,
    status: executor.status
  });
});

/**
 * List all jobs
 */
router.get('/jobs', (req, res) => {
  const jobs = jobManager.getAllJobs();
  const stats = jobManager.getStats();

  res.json({
    shard_id: config.shardId,
    stats,
    jobs
  });
});

export default router;
