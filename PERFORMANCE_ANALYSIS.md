# Pipeline Performance Analysis

## ROOT CAUSE FOUND: SSE Auto-Detection Timeout

**The 10-second delay was caused by the MCP client's SSE auto-detection.**

When connecting to an HTTP MCP server, the client:
1. Probes `/sse` endpoint with HEAD request
2. If it returns 200 (not 404), assumes it's an SSE endpoint
3. Opens an SSE connection and waits for "endpoint" event
4. **Waits up to 10 seconds before timing out**

The `/mcp/pipelines` endpoint responds 200 to all paths (including `/mcp/pipelines/sse`),
causing the SSE detection to falsely trigger and wait 10 seconds.

### Fix Applied

**SSE auto-detection is now disabled by default.**

Modified `McpClientService.php`:
- SSE is deprecated, so only use if explicitly requested (`sse: true` in config)
- Default behavior (no sse config) goes straight to HTTP
- This eliminates the 10-second timeout entirely

### Before/After

| Metric | Before | After |
|--------|--------|-------|
| mcp_call step execution | ~10 seconds | < 1 second |
| Form submit to resume | ~10 seconds | < 1 second |

---

## Additional Optimizations Applied

### 1. substituteVariables Debug Logging Removed
- Removed 7 debug log calls from hot path (called 45+ times per step)
- Each log call was serializing array keys, doing string operations

### 2. updateProgress Batching
- Added rate limiting (max once per 2 seconds)
- Avoids serializing entire context JSON on every step

### 3. Run Status Check Batching
- In executeSteps loop, only check for cancellation every 5 iterations
- Reduces DB queries from O(n) to O(n/5)

---

## Previous Analysis (Preserved for Reference)

## Executive Summary

The PipelineExecutor has significant performance issues caused by:
1. **Excessive database operations** - 129 Bean:: calls in a single file
2. **Redundant JSON serialization** - 72 json_encode/decode operations
3. **Debug logging in hot paths** - 125 log calls, many with context serialization
4. **Polling overhead** - 3-4 DB queries per /messages poll (every 2-3 seconds)
5. **Repeated loads of the same data** - Run/pipeline loaded multiple times per execution

## Detailed Findings

### 1. Repeated Database Loads (CRITICAL)

The same run/pipeline are loaded multiple times during execution:

```php
// In execute() - line 1503
$this->run = Bean::load('pipelineruns', $this->runId);

// In executeSteps() while loop - EVERY ITERATION - line 1668
$this->run = Bean::load('pipelineruns', $this->runId);

// In stepNext() - line 817
$this->run = Bean::load('pipelineruns', $this->runId);

// In exception handlers - lines 939, 981, 1016
$this->run = Bean::load('pipelineruns', $this->runId);
```

**Impact**: 3-10x more DB reads than necessary per pipeline run.

### 2. substituteVariables Debug Logging (CRITICAL)

The `substituteVariables()` method (called 45+ times per step) has debug logging **inside the hot path**:

```php
private function substituteVariables(string $template): string {
    // LINE 3833 - Called EVERY time!
    $this->log('debug', 'substituteVariables called', [
        'template_preview' => substr($template, 0, 200),
        'available_step_outputs' => array_keys($this->stepOutputs),  // Array operation
        'context_keys' => array_keys($this->context)  // Array operation
    ]);

    // More debug logs in EACH regex callback...
}
```

**Impact**: 45+ log calls with array serialization per step = 100s of unnecessary operations.

### 3. updateProgress Saves Entire Context JSON (HIGH)

Called after EVERY step:

```php
private function updateProgress(int $completedCount): void {
    $this->run->context_json = json_encode($this->context);  // Serialize EVERYTHING
    Bean::store($this->run);  // Write to DB
}
```

**Impact**: Full context JSON serialization + DB write per step. If context grows (messages, outputs), this gets worse.

### 4. Messages Polling Overhead (HIGH)

The `/pipelines/messages` endpoint is polled every 2-3 seconds:

```php
public function messages($params = []) {
    $run = Bean::load('pipelineruns', $runId);           // DB query 1
    $awaitingStep = Bean::findOne('pipelinestepruns'...) // DB query 2 (auth)
    $context = json_decode($run->context_json ?: '{}');  // JSON parse
    if ($jobId) {
        $job = Bean::load('aidevjobs', $jobId);          // DB query 3
    }
}
```

**Impact**: 3-4 DB queries + JSON parse every 2-3 seconds per active run.

### 5. Step Run Lookups in Loops (MEDIUM)

Every step execution does multiple DB lookups:

```php
// In executeParallelRows - for EACH parallel step
$stepRun = Bean::findOne('pipelinestepruns'...);  // DB query
Bean::store($stepRun);  // DB write
Bean::store($this->run);  // Another DB write for current_step_name!
```

### 6. Log Method Serializes Context (MEDIUM)

Every log call (125 in the file) triggers:

```php
private function log(...) {
    $contextStr = !empty($context) ? ' ' . json_encode($context) : '';  // JSON on every call
}
```

---

## Optimization Plan

### Phase 1: Quick Wins (Low Risk)

#### 1.1 Remove debug logging from substituteVariables
- Move debug logs behind a `$this->debugMode` check
- Or remove entirely (these are for development only)

```php
private function substituteVariables(string $template): string {
    // REMOVE these debug logs - they're in a hot path
    // $this->log('debug', 'substituteVariables called', [...]);

    // Keep the actual substitution logic
}
```

**Expected impact**: 100+ fewer operations per step.

#### 1.2 Batch updateProgress calls
- Only update progress every N steps, or at end of execution
- Context JSON only needs saving at pause/complete points

```php
// Instead of saving after every step:
if ($completedCount % 5 === 0 || $isLastStep) {
    $this->updateProgress($completedCount);
}
```

**Expected impact**: 80% fewer DB writes during execution.

#### 1.3 Cache run status checks
- Don't reload run from DB in the while loop
- Only check for cancellation every N iterations

```php
// Before (every iteration)
$this->run = Bean::load('pipelineruns', $this->runId);

// After (every 5th iteration or after step completion)
if ($iterations % 5 === 0) {
    $this->run = Bean::load('pipelineruns', $this->runId);
}
```

### Phase 2: Structural Improvements (Medium Risk)

#### 2.1 Preload step runs at start
Instead of `Bean::findOne()` for each step:

```php
// Load all step runs once at initialization
$this->stepRuns = [];
$allStepRuns = Bean::find('pipelinestepruns', 'pipelineruns_id = ?', [$this->runId]);
foreach ($allStepRuns as $sr) {
    $this->stepRuns[$sr->pipelinesteps_id] = $sr;
}
```

**Expected impact**: Eliminates O(n) queries for n steps.

#### 2.2 Batch step run updates
- Collect step run updates in memory
- Flush to DB at checkpoints (after column, on pause, on complete)

#### 2.3 Use Server-Sent Events for messages
- Replace polling with SSE or WebSocket
- Only send updates when data changes

### Phase 3: Advanced Optimizations (Higher Risk)

#### 3.1 Lazy context serialization
- Only serialize context when it actually changes
- Use dirty flag to track modifications

#### 3.2 Message queue for step execution
- Decouple step execution from HTTP request
- Return immediately, process async

#### 3.3 Redis caching for run state
- Cache active runs in Redis
- Only persist to DB at checkpoints

---

## Immediate Actions

1. **Create profiling wrapper** to measure actual time spent in each method
2. **Remove substituteVariables debug logs** (safest, biggest impact)
3. **Reduce updateProgress frequency**
4. **Add cancellation check interval** instead of every loop iteration

## Metrics to Track

- Time from formsubmit to resume result (currently ~10 seconds!)
- DB queries per step execution
- Log entries per step execution
- Context JSON size growth over run lifetime
