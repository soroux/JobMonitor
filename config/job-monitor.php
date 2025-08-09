<?php
return [
    /*
    | API Route Prefix
    | All routes will be prefixed with this value.
    */
    'prefix' => 'api/job-monitor',

    /*
    | API Route Middleware
    | The middleware to apply to the routes.
    | IMPORTANT: Protect these routes with auth middleware in production!
    */
    'middleware' => ['api', 'throttle:60,1'],

    /*
    | Queues to Monitor
    | An array of queue names you want to monitor.
    */
    'queues' => [
        'default',
        'notifications',
        'processing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Connection Configuration
    |--------------------------------------------------------------------------
    |
    | Specify the Redis connection configuration for the job monitoring package.
    |
    */
    'redis' => [
        'connection' => env('JOB_MONITOR_REDIS_CONNECTION', 'default'),
        'host' => env('JOB_MONITOR_REDIS_HOST', env('REDIS_HOST', '127.0.0.1')),
        'password' => env('JOB_MONITOR_REDIS_PASSWORD', env('REDIS_PASSWORD', null)),
        'port' => env('JOB_MONITOR_REDIS_PORT', env('REDIS_PORT', 6379)),
        'database' => env('JOB_MONITOR_REDIS_DB', env('REDIS_DB', 0)),
        'ssl' => env('JOB_MONITOR_REDIS_SSL', env('REDIS_SSL', false)),
        'timeout' => env('JOB_MONITOR_REDIS_TIMEOUT', 5),
        'retry_interval' => env('JOB_MONITOR_REDIS_RETRY_INTERVAL', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Command Monitoring
    |--------------------------------------------------------------------------
    |
    | If enabled, the package will listen for command events to track
    | which Artisan commands are currently running.
    |
    */
    'monitoring' => [
        'commands_enabled' => true,
        'job_correlation_enabled' => true,
    ],

    /*
   |--------------------------------------------------------------------------
   | Exception Handling Configuration
   |--------------------------------------------------------------------------
   |
   | Control how exceptions are logged, including stack trace depth.
   |
   */
    'exceptions' => [
        /*
        | Number of stack frames to include in exception logs
        | Set to 0 to disable stack traces completely
        | Recommended: 3-5 frames for production
        */
        'frame_count' => 3,

        /*
        | Enable detailed error tracking
        | Set to false in production to reduce log noise
        */
        'detailed_logging' => env('JOB_MONITOR_DETAILED_LOGGING', false),
    ],

    /*
    | Commands to Ignore
    |
    | An array of command signatures to ignore when monitoring.
    | You might want to ignore frequent, short-lived commands.
    |
    */
    'ignore_commands' => [
        'schedule:run',
        'schedule:finish',
        'package:discover',
        'vendor:publish',
        'config:cache',
        'queue:retry',
        'queue:forget',
        'tinker',
        'serve',
        'migrate',
        'queue:work',
        'metrics:sync',
        'job-monitor:analyze',
        'schedule:run',
        'migrate:fresh',
        null, // <-- Sometimes command is null (for tests or internal calls)
    ],

    /*
    | Redis TTL for Tracking Data (in seconds)
    | How long to keep the command-job correlation data in Redis.
    | Default: 24 hours (86400 seconds)
    */
    'tracking_ttl' => 86400,

    /*
    | Redis TTL for Completed Jobs (in seconds)
    | How long to keep completed job data in Redis.
    | Default: 1 hour (3600 seconds)
    */
    'completed_ttl' => 3600,

    /*
    | Redis TTL for Failed Jobs (in seconds)
    | How long to keep failed job data in Redis.
    | Default: 48 hours (172800 seconds)
    */
    'failed_ttl' => 172800,

    /*
    |--------------------------------------------------------------------------
    | Job Analyze Mode
    |--------------------------------------------------------------------------
    |
    | If enabled, the package will analyze jobs and commands
    |
    */
    'analyze_mode' => [
        'enabled' => env('JOB_MONITOR_ANALYZE', true),
        'retention_days' => 7,
        'performance_threshold' => 1.5, // 1.5x historical average (upper bound)
        'performance_threshold_lower' => 0.5, // 0.5x historical average (lower bound)
        'failed_jobs_threshold' => 2.0, // 2x historical average failed jobs (upper bound)
        'failed_jobs_threshold_lower' => 0.1, // 0.1x historical average failed jobs (lower bound)
        'job_count_threshold' => 1.5, // 1.5x historical average job count (upper bound)
        'job_count_threshold_lower' => 0.5, // 0.5x historical average job count (lower bound)
        'analysis_interval_minutes' => 15, // How often to run analysis
        'schedule_analysis_enabled' => true, // Analyze scheduled commands
        'missed_execution_threshold_hours' => 2, // Hours to wait before considering a command as missed
    ],

    /*
    |--------------------------------------------------------------------------
    | API-Triggered Commands Monitoring
    |--------------------------------------------------------------------------
    |
    | Specify commands that are expected to be triggered via API (Artisan::call)
    | and the expected interval (in minutes) between calls. Used for anomaly detection.
    |
    */
    'api_commands' => [
        // 'your:api-command' => 60, // e.g. 'my:api-command' => 60 (every hour)
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis-to-Database Sync Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how metrics are synced from Redis to database
    |
    */
    'sync' => [
        'enabled' => env('JOB_MONITOR_SYNC_ENABLED', true),
        'interval_minutes' => env('JOB_MONITOR_SYNC_INTERVAL', 5), // How often to sync
        'batch_size' => env('JOB_MONITOR_SYNC_BATCH_SIZE', 500), // Batch size for database inserts
        'retry_attempts' => env('JOB_MONITOR_SYNC_RETRY_ATTEMPTS', 3), // Retry failed syncs
        'retry_delay_seconds' => env('JOB_MONITOR_SYNC_RETRY_DELAY', 30), // Delay between retries
        'cleanup_enabled' => env('JOB_MONITOR_CLEANUP_ENABLED', false), // Clean up old Redis data
        'cleanup_after_hours' => env('JOB_MONITOR_CLEANUP_AFTER_HOURS', 24), // Keep Redis data for X hours
        'max_memory_mb' => env('JOB_MONITOR_MAX_MEMORY_MB', 100), // Max memory usage for sync process
        'timeout_seconds' => env('JOB_MONITOR_SYNC_TIMEOUT', 300), // Max execution time for sync
        'chunk_delay_ms' => env('JOB_MONITOR_CHUNK_DELAY_MS', 100), // Delay between chunks in milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check Configuration
    |--------------------------------------------------------------------------
    |
    | Configure health check endpoints and thresholds
    |
    */
    'health_check' => [
        'enabled' => env('JOB_MONITOR_HEALTH_CHECK_ENABLED', true),
        'redis_timeout' => env('JOB_MONITOR_HEALTH_REDIS_TIMEOUT', 5),
        'db_timeout' => env('JOB_MONITOR_HEALTH_DB_TIMEOUT', 5),
        'max_metrics_age_hours' => env('JOB_MONITOR_MAX_METRICS_AGE_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure logging channels and levels
    |
    */
    'logging' => [
        'channel' => env('JOB_MONITOR_LOG_CHANNEL', 'job-monitor'),
        'level' => env('JOB_MONITOR_LOG_LEVEL', 'info'),
        'structured' => env('JOB_MONITOR_STRUCTURED_LOGGING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting for API endpoints
    |
    */
    'rate_limiting' => [
        'enabled' => env('JOB_MONITOR_RATE_LIMITING_ENABLED', true),
        'requests_per_minute' => env('JOB_MONITOR_RATE_LIMIT_REQUESTS', 60),
        'burst_limit' => env('JOB_MONITOR_RATE_LIMIT_BURST', 10),
    ],
];
