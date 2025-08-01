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
        'queue:work',
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
];
