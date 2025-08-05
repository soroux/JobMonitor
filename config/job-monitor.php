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
    'middleware' => ['api'],

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
    | Redis Connection Name
    |--------------------------------------------------------------------------
    |
    | Specify the Redis connection name to use for the job monitoring package.
    |
    */
    'monitor-connection' => env('JOB_MONITOR_REDIS_CONNECTION', 'default'),

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
        'performance_threshold' => 1.5, // 1.5x historical average
        'failed_jobs_threshold' => 2.0, // 2x historical average failed jobs
        'job_count_threshold' => 1.5, // 1.5x historical average job count
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
    | Notification Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how anomalies are notified to your team.
    |
    */
    'notifications' => [
        'email' => [
            'enabled' => env('JOB_MONITOR_EMAIL_NOTIFICATIONS', false),
            'recipients' => [
                // Add email addresses here
                // 'admin@example.com',
                // 'devops@example.com',
            ],
        ],
        'slack' => [
            'enabled' => env('JOB_MONITOR_SLACK_NOTIFICATIONS', false),
            'webhook_url' => env('JOB_MONITOR_SLACK_WEBHOOK_URL'),
        ],
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
        'cleanup_enabled' => env('JOB_MONITOR_CLEANUP_ENABLED', true), // Clean up old Redis data
        'cleanup_after_hours' => env('JOB_MONITOR_CLEANUP_AFTER_HOURS', 24), // Keep Redis data for X hours
        'max_memory_mb' => env('JOB_MONITOR_MAX_MEMORY_MB', 100), // Max memory usage for sync process
        'timeout_seconds' => env('JOB_MONITOR_SYNC_TIMEOUT', 300), // Max execution time for sync
    ],
];
