<?php

namespace Soroux\JobMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;
use Soroux\JobMonitor\Models\CommandMetric;
use Soroux\JobMonitor\Models\JobMetric;
use Soroux\JobMonitor\Providers\JobMonitorServiceProvider;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app)
    {
        return [
            JobMonitorServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Set up database configuration
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Set up job-monitor configuration
        $app['config']->set('job-monitor', [
            'monitoring' => [
                'commands_enabled' => true,
                'job_correlation_enabled' => true,
            ],
            'sync' => [
                'enabled' => true,
                'interval_minutes' => 5,
                'batch_size' => 100,
                'retry_attempts' => 3,
                'retry_delay_seconds' => 5,
                'cleanup_enabled' => true,
                'cleanup_after_hours' => 24,
                'max_memory_mb' => 512,
                'timeout_seconds' => 300,
            ],
            'analyze_mode' => [
                'enabled' => true,
                'retention_days' => 7,
                'performance_threshold' => 1.5,
                'performance_threshold_lower' => 0.5,
                'failed_jobs_threshold' => 2.0,
                'failed_jobs_threshold_lower' => 0.1,
                'job_count_threshold' => 1.5,
                'job_count_threshold_lower' => 0.5,
                'analysis_interval_minutes' => 15,
                'schedule_analysis_enabled' => true,
                'missed_execution_threshold_hours' => 2,
            ],
            'api_commands' => [
                'test:api-command' => 60,
            ],
            'notifications' => [
                'email' => [
                    'enabled' => false,
                    'recipients' => [],
                ],
                'slack' => [
                    'enabled' => false,
                    'webhook_url' => '',
                ],
            ],
            'ignore_commands' => [
                'job-monitor:analyze',
                'schedule:run',
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations
        $this->loadMigrationsFrom(__DIR__ . '/../src/database/migrations');

    }


    protected function createCommandMetric(array $attributes = []): CommandMetric
    {
        $defaults = [
            'process_id' => 'test-process-' . uniqid(),
            'command_name' => 'test:command',
            'total_time' => 10.5,
            'job_count' => 5,
            'success_jobs' => 4,
            'failed_jobs' => 1,
            'avg_job_time' => 2.1,
            'peak_memory' => 1024,
            'run_date' => now()->toDateString(),
            'source' => 'console',
        ];

        // Filter out null values and merge with defaults
        $filteredAttributes = array_filter($attributes, function ($value) {
            return $value !== null;
        });

        return CommandMetric::create(array_merge($defaults, $filteredAttributes));
    }

    protected function createJobMetric(array $attributes = []): JobMetric
    {
        $defaults = [
            'job_id' => 'test-job-' . uniqid(),
            'process_id' => 'test-process-' . uniqid(),
            'command_name' => 'test:command',
            'execution_time' => 2.1,
            'memory_usage' => 512,
            'queue_time' => 0.5,
            'status' => 'success',
        ];

        // Filter out null values and merge with defaults
        $filteredAttributes = array_filter($attributes, function ($value) {
            return $value !== null;
        });

        return JobMetric::create(array_merge($defaults, $filteredAttributes));
    }
}
