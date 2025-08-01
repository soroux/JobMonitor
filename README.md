# Laravel Job Monitor

A comprehensive Laravel package for monitoring and tracking queue jobs and Artisan commands in real-time. This package provides detailed insights into job execution, command correlation, and failure tracking with a clean REST API.

## Features

- 🔍 **Real-time Job Monitoring**: Track job lifecycle from queuing to completion
- 🎯 **Command-Job Correlation**: Link jobs to the Artisan commands that dispatched them
- 📊 **Comprehensive Statistics**: Queue statistics, failed jobs, and execution metrics
- 🚨 **Failure Tracking**: Detailed error logging with stack traces and retry information
- ⚡ **Performance Metrics**: Queue time, execution time, and total processing time
- 🔧 **REST API**: Clean API endpoints for monitoring and management
- 🎨 **Easy Integration**: Simple trait-based implementation
- 🔒 **Production Ready**: Robust error handling and logging

## Requirements

- PHP 8.1+
- Laravel 9.0+ / 10.0+ / 11.0+ / 12.0+
- Redis (for data storage)
- Queue driver (Redis, Database, etc.)

## Installation

### 1. Install the Package

```bash
composer require soroux/job-monitor
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --provider="Soroux\JobMonitor\Providers\JobMonitorServiceProvider" --tag=config
```

### 3. Configure Redis

Ensure your Redis connection is properly configured in `config/database.php`:

```php
'redis' => [
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DB', 0),
    ],
],
```

## Configuration

The configuration file `config/job-monitor.php` contains all package settings:

```php
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
    | Command Monitoring
    | If enabled, the package will listen for command events to track
    | which Artisan commands are currently running.
    */
    'monitoring' => [
        'commands_enabled' => true,
        'job_correlation_enabled' => true,
    ],

    /*
    | Commands to Ignore
    | An array of command signatures to ignore when monitoring.
    | You might want to ignore frequent, short-lived commands.
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
        null, // Sometimes command is null (for tests or internal calls)
    ],

    /*
    | Redis TTL Settings (in seconds)
    */
    'tracking_ttl' => 86400,    // 24 hours for pending/processing jobs
    'completed_ttl' => 3600,    // 1 hour for completed jobs
    'failed_ttl' => 172800,     // 48 hours for failed jobs
];
```

## Usage

### 1. Make Your Jobs Trackable

Add the `TrackableJob` trait to your job classes:

```php
<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Soroux\JobMonitor\Concerns\TrackableJob;

class ProcessOrderJob implements ShouldQueue
{
    use Queueable, TrackableJob;

    public function __construct(
        private int $orderId,
        private string $customerEmail
    ) {
        $this->setJobType('order_processing')
             ->markJobCreated();
    }

    public function handle(): void
    {
        // Your job logic here
        $this->processOrder($this->orderId);
        $this->sendNotification($this->customerEmail);
    }
}
```

### 2. Correlate Jobs with Commands

In your Artisan commands, link jobs to the command process:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\ProcessOrderJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ProcessOrdersCommand extends Command
{
    protected $signature = 'orders:process {--batch-size=100}';
    protected $description = 'Process pending orders';

    public function handle(): void
    {
        // Get the process ID for this command instance
        $processId = $this->getCommandProcessId();
        
        $this->info("Starting order processing with Process ID: {$processId}");

        $orders = Order::pending()->take($this->option('batch-size'))->get();

        foreach ($orders as $order) {
            $job = (new ProcessOrderJob($order->id, $order->customer_email))
                ->setCommandProcessId($processId)
                ->setJobType('order_processing')
                ->markJobCreated();

            dispatch($job);
            
            $this->info("Dispatched job for order #{$order->id}");
        }

        $this->info('Finished dispatching order processing jobs.');
    }

    private function getCommandProcessId(): string
    {
        // Try to get from Redis first (if command was started with monitoring)
        $processId = Redis::connection()->get("command-pid-map:{$this->getName()}");
        
        if (!$processId) {
            // Generate a new process ID if not found
            $processId = (string) Str::uuid();
        }
        
        return $processId;
    }
}
```

### 3. Monitor Job Progress

Use the API to monitor job progress:

```php
// Get all jobs for a specific command process
$response = Http::get('/api/job-monitor/commands/{processId}/jobs');
$jobs = $response->json()['data'];

foreach ($jobs as $job) {
    echo "Job {$job['id']}: {$job['status']}\n";
}
```

## API Endpoints

### Queue Statistics

```http
GET /api/job-monitor/stats
```

**Response:**
```json
{
    "status": "success",
    "data": {
        "total_failed": 5,
        "queues": {
            "default": {
                "pending": 10,
                "processing": 2,
                "delayed": 0
            },
            "notifications": {
                "pending": 5,
                "processing": 1,
                "delayed": 0
            }
        }
    }
}
```

### Running Commands

```http
GET /api/job-monitor/commands/running
```

**Response:**
```json
{
    "status": "success",
    "data": [
        {
            "id": "550e8400-e29b-41d4-a716-446655440000",
            "command": "orders:process",
            "started_at": "2024-01-15 10:30:00",
            "environment": "production",
            "arguments": [],
            "options": {
                "batch-size": "100"
            }
        }
    ]
}
```

### Finished Commands

```http
GET /api/job-monitor/commands/finished
```

### Command Jobs

```http
GET /api/job-monitor/commands/{processId}/jobs
```

**Response:**
```json
{
    "status": "success",
    "data": [
        {
            "id": "job-uuid-1",
            "status": {
                "status": "completed",
                "completed_at": "2024-01-15 10:35:00",
                "execution_time": 2.5,
                "total_time": 3.2,
                "queue_time": 0.7,
                "job_type": "order_processing",
                "queue": "default",
                "job_class": "App\\Jobs\\ProcessOrderJob",
                "attempts": 1
            }
        },
        {
            "id": "job-uuid-2",
            "status": {
                "status": "failed",
                "failed_at": "2024-01-15 10:36:00",
                "error": "Database connection failed",
                "stack_trace": "...",
                "attempts": 3,
                "job_type": "order_processing",
                "queue": "default",
                "job_class": "App\\Jobs\\ProcessOrderJob",
                "execution_time": 1.2,
                "retryable": true,
                "exception_class": "Illuminate\\Database\\QueryException"
            }
        }
    ]
}
```

### Failed Jobs

```http
GET /api/job-monitor/jobs/failed?per_page=20&page=1
```

### Retry Failed Job

```http
POST /api/job-monitor/jobs/failed/{id}/retry
```

### Delete Failed Job

```http
DELETE /api/job-monitor/jobs/failed/{id}
```

### Retry All Failed Jobs for Command

```http
POST /api/job-monitor/commands/{processId}/retry-failed
```

## Job Status Types

Jobs can have the following statuses:

- **`pending`**: Job is queued and waiting to be processed
- **`processing`**: Job is currently being executed
- **`completed`**: Job finished successfully
- **`failed`**: Job failed with an exception

## Job Data Structure

Each job entry contains:

```json
{
    "status": "completed",
    "created_at": "2024-01-15 10:30:00",
    "started_at": "2024-01-15 10:30:05",
    "completed_at": "2024-01-15 10:30:08",
    "execution_time": 2.5,
    "queue_time": 0.7,
    "total_time": 3.2,
    "job_type": "order_processing",
    "queue": "default",
    "job_class": "App\\Jobs\\ProcessOrderJob",
    "process_id": "550e8400-e29b-41d4-a716-446655440000",
    "attempts": 1
}
```

## Security Considerations

### 1. API Protection

In production, protect your monitoring API with authentication:

```php
// In config/job-monitor.php
'middleware' => ['api', 'auth:sanctum'],
```

### 2. Redis Security

Ensure your Redis instance is properly secured:
- Use strong passwords
- Enable SSL/TLS if possible
- Restrict network access
- Use dedicated Redis database

### 3. Rate Limiting

Consider adding rate limiting to your API endpoints:

```php
'middleware' => ['api', 'auth:sanctum', 'throttle:60,1'],
```

## Performance Considerations

### 1. TTL Management

Adjust TTL settings based on your needs:
- Shorter TTL for completed jobs (1 hour)
- Longer TTL for failed jobs (48 hours)
- Moderate TTL for tracking data (24 hours)

### 2. Queue Monitoring

Only monitor queues you actually use to reduce overhead.

### 3. Command Filtering

Add frequently run commands to the ignore list to reduce noise.

## Troubleshooting

### Common Issues

1. **Jobs not being tracked**
   - Ensure your job class uses the `TrackableJob` trait
   - Check that the queue is in the monitored queues list
   - Verify Redis connection is working

2. **Process ID not found**
   - Make sure the command is not in the ignore list
   - Check that command monitoring is enabled
   - Verify the command was started with monitoring

3. **Redis connection errors**
   - Check Redis configuration
   - Ensure Redis service is running
   - Verify network connectivity

### Debug Mode

Enable debug logging in your `.env`:

```env
LOG_LEVEL=debug
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests
5. Submit a pull request

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Support

For support, please open an issue on GitHub or contact the maintainer.

---

**Made with ❤️ for the Laravel community** 
