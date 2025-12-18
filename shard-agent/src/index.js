import express from 'express';
import { config } from './config.js';
import api from './api.js';
import { jobManager } from './executor.js';

const app = express();

// Middleware
app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true }));

// Request logging
app.use((req, res, next) => {
  const start = Date.now();
  res.on('finish', () => {
    const duration = Date.now() - start;
    console.log(`${new Date().toISOString()} ${req.method} ${req.path} ${res.statusCode} ${duration}ms`);
  });
  next();
});

// API routes
app.use('/', api);

// 404 handler
app.use((req, res) => {
  res.status(404).json({ error: 'Not found' });
});

// Error handler
app.use((err, req, res, next) => {
  console.error('Unhandled error:', err);
  res.status(500).json({ error: 'Internal server error' });
});

// Periodic cleanup of old jobs from memory
setInterval(() => {
  jobManager.cleanup(3600000); // Clean jobs older than 1 hour
}, 300000); // Every 5 minutes

// Start server
app.listen(config.port, config.host, () => {
  console.log('='.repeat(60));
  console.log('Claude Shard Agent');
  console.log('='.repeat(60));
  console.log(`Shard ID:     ${config.shardId}`);
  console.log(`Shard Type:   ${config.shardType}`);
  console.log(`Listening:    http://${config.host}:${config.port}`);
  console.log(`Max Jobs:     ${config.maxConcurrentJobs}`);
  console.log(`Capabilities: ${config.capabilities.join(', ')}`);
  console.log(`Workspace:    ${config.workspacePath}`);
  console.log('='.repeat(60));
});

// Graceful shutdown
process.on('SIGTERM', () => {
  console.log('Received SIGTERM, shutting down gracefully...');
  process.exit(0);
});

process.on('SIGINT', () => {
  console.log('Received SIGINT, shutting down gracefully...');
  process.exit(0);
});
