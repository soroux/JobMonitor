# Job Monitor Package

A comprehensive Laravel package for monitoring job and command performance with intelligent anomaly detection.

## Features

### Data Collection
- **Command Tracking**: Monitors all Artisan commands execution
- **Job Correlation**: Links jobs to their parent commands
- **Performance Metrics**: Tracks execution time, memory usage, and job counts
- **Failure Tracking**: Monitors failed jobs and their patterns

### Intelligent Analysis
- **Performance Anomalies**: Detects commands taking longer than usual
- **Failure Rate Analysis**: Identifies commands with unusually high failure rates
- **Job Count Anomalies**: Detects unusual job processing volumes
- **Missed Executions**: Identifies scheduled commands that didn't run
- **Historical Comparison**: Uses historical data for baseline comparison

### Notifications
- **Email Notifications**: Configurable email alerts
- **Slack Integration**: Real-time Slack notifications
- **Logging**: Comprehensive logging of all anomalies

## Installation

1. Install the package:
```bash
composer require soroux/job-monitor
```

2. Publish the configuration:
```bash
php artisan vendor:publish --tag=job-monitor-config
```

3. Run migrations:
```bash
php artisan migrate
```

4. Set up the analysis schedule:
```bash
php artisan job-monitor:setup-schedule
```

## Configuration

### Basic Configuration

Edit `config/job-monitor.php`:

```php
'analyze_mode' => [
    'enabled' => true,
    'retention_days' => 7,
    'performance_threshold' => 1.5, // 1.5x historical average
    'failed_jobs_threshold' => 2.0, // 2x historical average failed jobs
    'job_count_threshold' => 1.5, // 1.5x historical average job count
    'analysis_interval_minutes' => 15, // How often to run analysis
    'schedule_analysis_enabled' => true, // Analyze scheduled commands
    'missed_execution_threshold_hours' => 2, // Hours to wait before considering a command as missed
],
```

### Notification Configuration

```php
'notifications' => [
    'email' => [
        'enabled' => env('JOB_MONITOR_EMAIL_NOTIFICATIONS', false),
        'recipients' => [
            'admin@example.com',
            'devops@example.com',
        ],
    ],
    'slack' => [
        'enabled' => env('JOB_MONITOR_SLACK_NOTIFICATIONS', false),
        'webhook_url' => env('JOB_MONITOR_SLACK_WEBHOOK_URL'),
    ],
],
```

### Environment Variables

Add to your `.env` file:

```env
JOB_MONITOR_ANALYZE=true
JOB_MONITOR_EMAIL_NOTIFICATIONS=true
JOB_MONITOR_SLACK_NOTIFICATIONS=false
JOB_MONITOR_SLACK_WEBHOOK_URL=your-slack-webhook-url
```

## Usage

### Manual Analysis

Run analysis manually:

```bash
# Analyze all commands
php artisan job-monitor:analyze

# Analyze specific command
php artisan job-monitor:analyze --command=queue:work
```

### Scheduled Analysis

The package automatically sets up scheduled analysis. Make sure your Laravel scheduler is running:

```bash
php artisan schedule:work
```

### Anomaly Types

The system detects the following anomalies:

1. **Performance Anomaly**: Command taking longer than threshold × average time
2. **Failed Jobs Anomaly**: Command with more failed jobs than threshold × average
3. **High Job Count**: Command processing more jobs than usual
4. **Low Job Count**: Command processing fewer jobs than usual
5. **Missed Execution**: Scheduled command that didn't run when expected
6. **Never Executed**: Scheduled command that has never run

### Event Handling

Listen for anomaly events:

```php
use Soroux\JobMonitor\Events\PerformanceAnomalyDetected;

Event::listen(PerformanceAnomalyDetected::class, function ($event) {
    // Handle the anomaly
    Log::warning('Anomaly detected', [
        'command' => $event->commandName,
        'type' => $event->anomalyType,
        'details' => $event->details
    ]);
});
```

## API Endpoints

### Get Command Metrics

```http
GET /api/job-monitor/commands
```

### Get Job Metrics

```http
GET /api/job-monitor/jobs
```

### Get Performance Analysis

```http
GET /api/job-monitor/analysis
```

## Commands

### Available Commands

- `job-monitor:analyze` - Run performance analysis
- `job-monitor:setup-schedule` - Set up analysis schedule
- `job-monitor:setup-sync-schedule` - Set up metrics sync schedule
- `metrics:sync` - Sync metrics to database

### Sync Command Options

```bash
# Basic sync
php artisan metrics:sync

# Force sync even if disabled
php artisan metrics:sync --force

# Dry run (show what would be synced without actually syncing)
php artisan metrics:sync --dry-run

# Set up sync schedule (every 5 minutes by default)
php artisan job-monitor:setup-sync-schedule

# Set up sync schedule with custom interval
php artisan job-monitor:setup-sync-schedule --interval=10
```

## Redis-to-Database Sync

### Configuration

The sync system is highly configurable through `config/job-monitor.php`:

```php
'sync' => [
    'enabled' => env('JOB_MONITOR_SYNC_ENABLED', true),
    'interval_minutes' => env('JOB_MONITOR_SYNC_INTERVAL', 5),
    'batch_size' => env('JOB_MONITOR_SYNC_BATCH_SIZE', 500),
    'retry_attempts' => env('JOB_MONITOR_SYNC_RETRY_ATTEMPTS', 3),
    'retry_delay_seconds' => env('JOB_MONITOR_SYNC_RETRY_DELAY', 30),
    'cleanup_enabled' => env('JOB_MONITOR_CLEANUP_ENABLED', true),
    'cleanup_after_hours' => env('JOB_MONITOR_CLEANUP_AFTER_HOURS', 24),
    'max_memory_mb' => env('JOB_MONITOR_MAX_MEMORY_MB', 100),
    'timeout_seconds' => env('JOB_MONITOR_SYNC_TIMEOUT', 300),
],
```

### Environment Variables

Add to your `.env` file:

```env
# Sync Configuration
JOB_MONITOR_SYNC_ENABLED=true
JOB_MONITOR_SYNC_INTERVAL=5
JOB_MONITOR_SYNC_BATCH_SIZE=500
JOB_MONITOR_SYNC_RETRY_ATTEMPTS=3
JOB_MONITOR_SYNC_RETRY_DELAY=30
JOB_MONITOR_CLEANUP_ENABLED=true
JOB_MONITOR_CLEANUP_AFTER_HOURS=24
JOB_MONITOR_MAX_MEMORY_MB=100
JOB_MONITOR_SYNC_TIMEOUT=300
```

### Features

- **Configurable Intervals**: Sync every 1, 5, 10, 15, 30 minutes, hourly, or custom
- **Batch Processing**: Configurable batch sizes for optimal performance
- **Memory Management**: Automatic memory limit enforcement
- **Timeout Protection**: Prevents sync from running indefinitely
- **Error Handling**: Comprehensive error logging and retry logic
- **Progress Tracking**: Real-time progress bars and statistics
- **Data Validation**: Validates metrics before database insertion
- **Cleanup**: Automatic cleanup of old Redis data
- **Dry Run Mode**: Test sync without making changes

### Monitoring

The sync process provides detailed statistics:

```
=== Sync Statistics ===
Execution time: 2.34 seconds
Memory usage: 45.2MB
Commands synced: 150
Jobs synced: 1250
Errors: 0
Warnings: 2
```

### Troubleshooting

#### Common Issues

1. **Sync not running**: Check if `php artisan schedule:work` is running
2. **Memory errors**: Reduce `JOB_MONITOR_SYNC_BATCH_SIZE` or increase `JOB_MONITOR_MAX_MEMORY_MB`
3. **Timeout errors**: Increase `JOB_MONITOR_SYNC_TIMEOUT`
4. **Redis connection errors**: Check Redis configuration and connectivity

#### Debug Commands

```bash
# Test sync with dry run
php artisan metrics:sync --dry-run

# Force sync with verbose output
php artisan metrics:sync --force -v

# Check Redis data
php artisan tinker
>>> Redis::keys('command:metrics:*')
>>> Redis::keys('job:metrics:*')
```

## Database Schema

### Command Metrics Table

```sql
CREATE TABLE command_metrics (
    id BIGINT PRIMARY KEY,
    process_id VARCHAR(255),
    command_name VARCHAR(255),
    total_time FLOAT,
    job_count INT,
    success_jobs INT,
    failed_jobs INT,
    avg_job_time FLOAT,
    peak_memory INT,
    run_date DATE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Job Metrics Table

```sql
CREATE TABLE job_metrics (
    id BIGINT PRIMARY KEY,
    job_id VARCHAR(255),
    process_id VARCHAR(255),
    command_name VARCHAR(255),
    execution_time FLOAT,
    memory_usage INT,
    queue_time FLOAT,
    status ENUM('success', 'failed'),
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

## Customization

### Custom Anomaly Detection

Extend the `PerformanceAnalyzer` class:

```php
use Soroux\JobMonitor\Service\PerformanceAnalyzer;

class CustomPerformanceAnalyzer extends PerformanceAnalyzer
{
    protected function checkCustomAnomaly($commandName)
    {
        // Your custom anomaly detection logic
    }
}
```

### Custom Notifications

Create custom notification listeners:

```php
use Soroux\JobMonitor\Events\PerformanceAnomalyDetected;

class CustomAnomalyListener
{
    public function handle(PerformanceAnomalyDetected $event)
    {
        // Your custom notification logic
    }
}
```

## Troubleshooting

### Common Issues

1. **Analysis not running**: Ensure `php artisan schedule:work` is running
2. **No data collected**: Check that the service provider is registered
3. **Notifications not working**: Verify configuration and credentials

### Debug Commands

```bash
# Check if analysis is working
php artisan job-monitor:analyze --verbose

# View collected metrics
php artisan tinker
>>> Soroux\JobMonitor\Models\CommandMetric::latest()->first()
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests
5. Submit a pull request

## License

This package is open-sourced software licensed under the [MIT license](LICENSE). 
