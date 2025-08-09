## Job Monitor for Laravel

Production-ready monitoring for Laravel queues and Artisan commands with Redis-backed tracking, REST APIs, anomaly detection, and optional DB persistence.

### Highlights
- **Command + Job correlation**: Link every queued job to the command that spawned it
- **Live state via Redis**: Running/finished commands, per-command job states and timings
- **APIs for dashboards**: JSON endpoints for stats, metrics, health, and control actions
- **Anomaly detection**: Automatic analysis of regressions, unusual load, and failure spikes
- **DB sync**: Persist metrics from Redis to SQL for history and trends
- **Secure + scalable**: Route prefix, middleware, throttling, and low-overhead design

### Requirements
- PHP 8.1+
- Laravel 9.x, 10.x, 11.x or 12.x
- Redis server
- A database (MySQL/PostgreSQL/SQLite) if you enable DB sync

## Quick start (5 minutes)
1) Install
```bash
composer require soroux/job-monitor
```

2) Publish config and run migrations
```bash
php artisan vendor:publish --tag=config
php artisan migrate
```

3) Protect your API endpoints (recommended)
- Open `config/job-monitor.php` and set:
```php
'middleware' => ['api', 'auth:sanctum'],
```

4) Make your jobs trackable (for correlation)
```php
use Soroux\JobMonitor\Concerns\TrackableJob;

class ProcessUserData implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TrackableJob;
}
```

5) When a command dispatches jobs, pass the process id and name
```php
use Illuminate\Support\Str;

$processId = (string) Str::uuid();
$commandName = 'data:process';

ProcessUserData::dispatch()
    ->setCommandProcessId($processId)
    ->setCommandName($commandName)
    ->markJobCreated();
```

That’s it. The package listeners track events and expose everything over the APIs below.

## How it works
### Event listeners
- `LogCommandStarting` and `LogCommandFinished` capture command lifecycle in Redis:
  - `commands:running` and `commands:finished`
  - `command-pid-map:{command}` maps command signature to the latest process id
- `JobQueuedListener`, `JobProcessingListener`, `JobProcessedListener`, `JobFailedListener` record per-job state under:
  - `command:{processId}:jobs` → per job JSON with status, times, queue, class
  - `command:metrics:{command}:{processId}` → counters for success/failed, peak memory, total time
- TTLs are configurable to manage memory usage:
  - `tracking_ttl` (pending/processing), `completed_ttl`, `failed_ttl`

### Correlating jobs to commands
- Include `TrackableJob` in your jobs
- Provide `processId` and `commandName` when dispatching
- The listeners will automatically update Redis keys and metrics

### Redis → Database sync (optional)
The command `metrics:sync` reads Redis metrics and persists them into SQL tables:
- `command_metrics` (per command run; fields: `process_id`, `command_name`, `job_count`, `success_jobs`, `failed_jobs`, `avg_job_time`, `total_time`, `peak_memory`, `run_date`, ...)
- `job_metrics` (per job; fields: `job_id`, `process_id`, `command_name`, `execution_time`, `queue_time`, `memory_usage`, `status`, ...)

Scheduling is automatic if enabled in config; otherwise, run it manually.

### Anomaly detection
`job-monitor:analyze` compares the latest run of each command with recent history and flags anomalies: performance degradation/improvement, workload spikes/drops, failure rate anomalies, missed expected executions. It dispatches `PerformanceAnomalyDetected` events you can listen to for alerting.

## Configuration (config/job-monitor.php)
Key options you will likely tune:

- Basic
  - `prefix`: Route prefix (default `api/job-monitor`)
  - `middleware`: Array of route middleware. Add your auth middleware in production

- Redis
  - `redis.connection`, `host`, `port`, `password`, `database`, `ssl`, `timeout`, `retry_interval`

- Monitoring toggles
  - `monitoring.commands_enabled`: Capture command start/finish
  - `monitoring.job_correlation_enabled`: Capture job lifecycle and link to commands

- Exceptions
  - `exceptions.frame_count`: Stack frames captured on failures (default 3)
  - `exceptions.detailed_logging`: Extra details in non-prod

- Analyze mode
  - `enabled`, `retention_days`, `performance_threshold` and lower bound
  - `failed_jobs_threshold` and lower bound
  - `job_count_threshold` and lower bound
  - `analysis_interval_minutes`
  - `schedule_analysis_enabled` and `missed_execution_threshold_hours`

- Sync (Redis → DB)
  - `enabled`, `interval_minutes`, `batch_size`, `retry_attempts`, `retry_delay_seconds`
  - `cleanup_enabled`, `cleanup_after_hours`, `max_memory_mb`, `timeout_seconds`, `chunk_delay_ms`

- Health check
  - `enabled`, `redis_timeout`, `db_timeout`, `max_metrics_age_hours`

- Logging and rate limiting
  - `logging.channel`, `logging.level`, `logging.structured`
  - `rate_limiting.enabled`, `requests_per_minute`, `burst_limit`

Environment variables are supported for all of the above (see the config file for exact names, e.g. `JOB_MONITOR_*`).

## HTTP API
Base URL: `/{prefix}` (default: `/api/job-monitor`)

Security: Add your auth middleware in `config/job-monitor.php` (e.g. `auth:sanctum`). Throttling is enabled by default.

### Dashboard and health
- GET `/{prefix}/dashboard`
  - Query: `days` (int, default 7), `command` (string)
  - Returns summary, recent activity and metrics window

- GET `/{prefix}/health`
  - Checks Redis, DB, and metrics freshness. Returns 200 when healthy, 503 otherwise

### Queue and failure management
- GET `/{prefix}/stats`
  - Returns per-queue pending/processing counts (from Redis) and failed counts (from DB)

- GET `/{prefix}/jobs/failed`
  - Query: `page`, `per_page` (default 20)
  - Returns failed jobs (from `failed_jobs` table)

- POST `/{prefix}/jobs/failed/{id}/retry`
  - Retries a failed job via `queue:retry`

- DELETE `/{prefix}/jobs/failed/{id}`
  - Deletes a failed job via `queue:forget`

### Commands and job correlation
- GET `/{prefix}/commands/running`
  - Returns currently running commands (`commands:running`)

- GET `/{prefix}/commands/finished`
  - Returns recently finished commands (`commands:finished`)

- GET `/{prefix}/commands/{processId}/jobs`
  - Returns job ids and statuses under this process

- POST `/{prefix}/commands/{processId}/retry-failed`
  - Retries all failed jobs linked to this process id

### Metrics
- GET `/{prefix}/command-metrics`
  - Query: `command` (required), `days` (default 30), `page`, `per_page`
  - Returns paginated `command_metrics` and trend summary

- GET `/{prefix}/job-metrics`
  - Query: `process_id`, `command`, `status` in `success,failed`, `days` (default 30), `page`, `per_page`
  - Returns paginated `job_metrics` plus statistics

### Performance analysis
- POST `/{prefix}/analysis`
  - Runs analysis now and returns summary and anomalies

### Example requests
```bash
# Dashboard (last 14 days)
curl -H "Authorization: Bearer <token>" \
  "https://your.app/api/job-monitor/dashboard?days=14"

# Command metrics for a given command
curl -H "Authorization: Bearer <token>" \
  "https://your.app/api/job-monitor/command-metrics?command=data:process&days=30&per_page=15"

# Job metrics filtered by process
curl -H "Authorization: Bearer <token>" \
  "https://your.app/api/job-monitor/job-metrics?process_id=<uuid>&status=failed"

# Retry one failed job
curl -X POST -H "Authorization: Bearer <token>" \
  "https://your.app/api/job-monitor/jobs/failed/123/retry"
```

### Response structures (examples)

Note: Some endpoints return `success: true|false` while others return `status: 'success'|'error'` for historical reasons. Below match the current implementation.

- GET `/{prefix}/dashboard` (200 on success, 500 on error)
```json
{
  "success": true,
  "data": {
    "summary": {
      "total_commands": 42,
      "total_jobs": 1280,
      "success_jobs": 1240,
      "failed_jobs": 40,
      "success_rate": 96.88,
      "failure_rate": 3.12,
      "avg_execution_time": 12.53,
      "total_memory_usage": 734003200,
      "avg_memory_per_command": 17476219
    },
    "recent_activity": {
      "recent_commands": [
        {
          "process_id": "71c4...",
          "command_name": "data:process",
          "run_date": "2025-08-03",
          "job_count": 55,
          "success_jobs": 55,
          "failed_jobs": 0,
          "total_time": 9.42,
          "peak_memory": 134217728
        }
      ],
      "recent_jobs": [
        {
          "job_id": "c1f2...",
          "process_id": "71c4...",
          "command_name": "data:process",
          "execution_time": 0.42,
          "queue_time": 0.03,
          "memory_usage": 10485760,
          "status": "success"
        }
      ]
    },
    "metrics": [],
    "period": {
      "start_date": "2025-07-27T00:00:00Z",
      "end_date": "2025-08-03T12:00:00Z",
      "days": 7
    }
  }
}
```

- GET `/{prefix}/health` (200 healthy, 503 unhealthy)
```json
{
  "success": true,
  "data": {
    "status": "healthy",
    "timestamp": "2025-08-03T12:00:00Z",
    "checks": {
      "redis": { "status": "healthy", "response_time": 0.0004 },
      "database": { "status": "healthy" },
      "metrics_freshness": { "status": "healthy" }
    }
  }
}
```

- GET `/{prefix}/stats`
```json
{
  "status": "success",
  "data": {
    "total_failed": 12,
    "queues": {
      "default": { "pending": 3, "processing": 1, "failed": 10 },
      "notifications": { "pending": 0, "processing": 0, "failed": 2 }
    }
  }
}
```

- GET `/{prefix}/jobs/failed` (Laravel paginator JSON)
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 123,
      "uuid": "c1f2...",
      "queue": "default",
      "payload": "...",
      "exception": "...",
      "failed_at": "2025-08-03 11:55:10"
    }
  ],
  "first_page_url": "https://your.app/api/job-monitor/jobs/failed?page=1",
  "from": 1,
  "last_page": 5,
  "last_page_url": "https://your.app/api/job-monitor/jobs/failed?page=5",
  "links": [],
  "next_page_url": "https://your.app/api/job-monitor/jobs/failed?page=2",
  "path": "https://your.app/api/job-monitor/jobs/failed",
  "per_page": 20,
  "prev_page_url": null,
  "to": 20,
  "total": 95
}
```

- POST `/{prefix}/jobs/failed/{id}/retry`
```json
{ "status": "success", "message": "Job [123] has been pushed back onto the queue." }
```
Errors return 404 with:
```json
{ "status": "error", "message": "Could not retry job [123]. It may no longer exist." }
```

- DELETE `/{prefix}/jobs/failed/{id}`
```json
{ "status": "success", "message": "Job [123] has been deleted." }
```
Errors return 404 with:
```json
{ "status": "error", "message": "Could not delete job [123]. It may no longer exist." }
```

- GET `/{prefix}/commands/running`
```json
{
  "status": "success",
  "data": [
    {
      "id": "71c4...", 
      "command": "data:process",
      "source": "console",
      "started_at": "2025-08-03 11:58:00",
      "environment": "production",
      "arguments": { },
      "options": { }
    }
  ]
}
```

- GET `/{prefix}/commands/finished`
```json
{
  "status": "success",
  "data": [
    {
      "id": "71c4...",
      "command": "data:process",
      "source": "console",
      "finished_at": "2025-08-03 12:01:10",
      "exit_code": 0
    }
  ]
}
```

- GET `/{prefix}/commands/{processId}/jobs`
```json
{
  "status": "success",
  "data": [
    { "id": "c1f2...", "status": "{\"status\":\"completed\",\"execution_time\":0.42, ... }" }
  ]
}
```
Note: The `status` field contains the JSON-encoded job record as stored in Redis for this process. You may decode it client-side to access fields like `status`, `queue_time`, `execution_time`, etc.

- POST `/{prefix}/commands/{processId}/retry-failed`
```json
{ "status": "success", "message": "Retry command dispatched for all failed jobs." }
```

- GET `/{prefix}/command-metrics`
```json
{
  "success": true,
  "data": {
    "command": "data:process",
    "metrics": {
      "current_page": 1,
      "data": [
        {
          "process_id": "71c4...",
          "command_name": "data:process",
          "source": "console",
          "total_time": 9.42,
          "job_count": 55,
          "success_jobs": 55,
          "failed_jobs": 0,
          "avg_job_time": 0.17,
          "peak_memory": 134217728,
          "run_date": "2025-08-03"
        }
      ],
      "per_page": 15,
      "total": 42
    },
    "trends": {
      "execution_time": { "change": 1.2, "change_percent": 14.62, "trend": "increasing" },
      "job_count": { "change": -5, "change_percent": -8.33, "trend": "decreasing" }
    },
    "period": { "start_date": "...", "end_date": "...", "days": 30 }
  }
}
```

- GET `/{prefix}/job-metrics`
```json
{
  "success": true,
  "data": {
    "metrics": {
      "current_page": 1,
      "data": [
        {
          "job_id": "c1f2...",
          "process_id": "71c4...",
          "command_name": "data:process",
          "execution_time": 0.42,
          "memory_usage": 10485760,
          "queue_time": 0.03,
          "status": "success"
        }
      ],
      "per_page": 15,
      "total": 1280
    },
    "statistics": {
      "total_jobs": 1280,
      "success_jobs": 1240,
      "failed_jobs": 40,
      "success_rate": 96.88,
      "failure_rate": 3.12,
      "avg_execution_time": 0.37,
      "avg_queue_time": 0.04,
      "avg_memory_usage": 11534336
    },
    "filters": { "process_id": "71c4...", "status": "success" },
    "period": { "start_date": "...", "end_date": "...", "days": 30 }
  }
}
```

- POST `/{prefix}/analysis`
```json
{
  "success": true,
  "data": {
    "timestamp": "2025-08-03T12:00:00Z",
    "anomalies_detected": 1,
    "commands_analyzed": 5,
    "errors": [],
    "warnings": ["Missed scheduled executions detected: data:sync"],
    "summary": {
      "total_anomalies": 1,
      "performance_degradations": 1,
      "performance_improvements": 0,
      "workload_anomalies": 0,
      "failure_rate_anomalies": 0,
      "critical_anomalies": 0,
      "high_anomalies": 1,
      "medium_anomalies": 0,
      "low_anomalies": 0
    }
  }
}
```

Common error shape for `dashboard`, `command-metrics`, `job-metrics`, `analysis`, `health` (500):
```json
{ "success": false, "message": "Failed to ...", "error": "Internal server error" }
```

## Console commands
```bash
# Sync metrics from Redis to DB
php artisan metrics:sync \
  [--force] [--dry-run] [--batch-size=500] [--cleanup]

# Analyze performance (all or one command)
php artisan job-monitor:analyze [--all] [--command=signature]
```

Scheduling is registered automatically (non-testing environments):
- `metrics:sync` runs every `sync.interval_minutes`
- `job-monitor:analyze` runs every `analyze_mode.analysis_interval_minutes` when `analyze_mode.enabled`

## TrackableJob trait reference
Include in your jobs to enable correlation and timings.

- `setCommandProcessId(?string $id): self` (validates UUID, auto-generates if null)
- `setCommandName(?string $name): self`
- `generateProcessId(): string`
- `generateCommandName(): string` (defaults to `manual-dispatch`)
- `markJobCreated(): self`
- `markJobStarted(): self`
- `getQueueTime(): ?float`
- `setJobType(string $type): self`

Tip: call `markJobCreated()` right after dispatch to capture queue time accurately, and `markJobStarted()` is called by the listener when processing begins.

## Redis keys (implementation details)
- `commands:running` (hash: processId → command payload)
- `commands:finished` (hash: processId → completion payload)
- `command-pid-map:{command}` (string: latest processId for the signature)
- `command:{processId}:jobs` (hash: jobId → job JSON: status, timings, queue, class)
- `command:metrics:{command}:{processId}` (hash counters: job_count, success_jobs, failed_jobs, total_job_time, peak_memory, last_update)
- `job:metrics:{jobId}` (per-job metrics; set for `manual-dispatch` jobs)

TTLs:
- `tracking_ttl` (default 86400s), `completed_ttl` (3600s), `failed_ttl` (172800s)

## Database schema (migrations)
Tables are created by the included migrations:
- `command_metrics` with indexes on `command_name`, `process_id`, `run_date`, `created_at`, and counters/timings
- `job_metrics` with indexes on `job_id`, `process_id`, `command_name`, `status`, `created_at`, and timings

## Events
`Soroux\JobMonitor\Events\PerformanceAnomalyDetected` is dispatched per anomaly:
- Properties include: `commandName`, `anomalyType`, `anomalyMetrics/metric`, `anomalyCurrent`, `anomalyBaselineAvg`, `anomalySeverity`, `latestMetric`

Example listener registration in your app:
```php
Event::listen(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class, function ($event) {
    // Send notification, page on-call, etc.
});
```

## Security hardening
- Always add auth middleware: `['api','auth:sanctum']` or your choice
- Use network firewalls/reverse proxy rules to restrict access further
- Tune `rate_limiting` to your traffic patterns

## Troubleshooting
- No metrics appear:
  - Ensure Redis connection works and queues in `config('job-monitor.queues')` include your queue names
  - Jobs must use `TrackableJob` and receive `processId` and `commandName` on dispatch
- Commands not shown as running/finished:
  - Ensure `monitoring.commands_enabled = true` and your middleware allows route access
- Sync to DB does nothing:
  - Check `sync.enabled = true` or run `metrics:sync --force`
- Health endpoint returns 503:
  - Inspect Redis/DB connectivity and `max_metrics_age_hours`

## Testing
```bash
composer test
composer test-coverage
```

## License
MIT
