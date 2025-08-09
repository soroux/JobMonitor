<?php

namespace Soroux\JobMonitor\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Soroux\JobMonitor\Console\Commands\PerformanceAnalyzerCommand;
use Soroux\JobMonitor\Console\Commands\SyncMetricsToDatabase;
use Soroux\JobMonitor\Listeners\JobFailedListener;
use Soroux\JobMonitor\Listeners\JobProcessedListener;
use Soroux\JobMonitor\Listeners\JobProcessingListener;
use Soroux\JobMonitor\Listeners\JobQueuedListener;
use Soroux\JobMonitor\Listeners\LogCommandFinished;
use Soroux\JobMonitor\Listeners\LogCommandStarting;

class JobMonitorServiceProvider extends ServiceProvider
{
    /**
     * Boot the application services.
     *
     * @return void
     */
    public function boot()
    {
        // Publish the configuration file
        $this->publishes([
            __DIR__ . '/../../config/job-monitor.php' => config_path('job-monitor.php'),
        ], 'config');

        // Validate configuration and environment
        $this->validateConfig();
        $this->validateEnvironment();

        // Register the API routes
        $this->registerRoutes();

        // Register event listeners
        $this->registerEventListeners();

        // Register package migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Register performance analysis commands
        $this->commands([
            PerformanceAnalyzerCommand::class,
        ]);

        if (!app()->environment('testing')) {
            // Register sync data commands
            $this->commands([
                SyncMetricsToDatabase::class,
            ]);
        }
        // Register sync command with configurable schedule
        if (config('job-monitor.sync.enabled') && !app()->environment('testing')) {
            $this->app->booted(function () {
                $schedule = $this->app->make(Schedule::class);

                $intervalSyncMinutes = config('job-monitor.sync.interval_minutes', 5);
                // Schedule sync based on interval
                $schedule->command('metrics:sync')
                    ->cron("*/{$intervalSyncMinutes} * * * *")
                    ->withoutOverlapping()
                    ->runInBackground()
                    ->onFailure(function () {
                        Log::error('Job monitor sync failed');
                    });

                if (config('job-monitor.analyze_mode.enabled')) {
                    $intervalAnalyzeMinutes = config('job-monitor.analyze_mode.analysis_interval_minutes', 15);
                    // Schedule analysis schedule based on interval
                    $schedule->command('job-monitor:analyze')
                        ->cron("*/{$intervalAnalyzeMinutes} * * * *")
                        ->withoutOverlapping()
                        ->runInBackground()
                        ->onFailure(function () {
                            Log::error('Job monitor analysis failed');
                        });
                }
            });
        }
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        // Merge the config file
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/job-monitor.php', 'job-monitor'
        );
    }

    /**
     * Register the API routes.
     *
     * @return void
     */
    protected function registerRoutes()
    {
        Route::group($this->routeConfiguration(), function () {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/api.php');
        });
    }

    /**
     * Get the route configuration.
     *
     * @return array
     */
    protected function routeConfiguration()
    {
        $middleware = config('job-monitor.middleware', ['api']);

        // Add rate limiting if enabled
        if (config('job-monitor.rate_limiting.enabled', true)) {
            $requestsPerMinute = config('job-monitor.rate_limiting.requests_per_minute', 60);
            $burstLimit = config('job-monitor.rate_limiting.burst_limit', 10);
            $middleware[] = "throttle:{$requestsPerMinute},{$burstLimit}";
        }

        return [
            'prefix' => config('job-monitor.prefix'),
            'middleware' => $middleware,
        ];
    }

    /**
     * Register event listeners based on configuration.
     *
     * @return void
     */
    protected function registerEventListeners()
    {
        if (config('job-monitor.monitoring.commands_enabled')) {
            $this->registerListeners([
                CommandStarting::class => LogCommandStarting::class,
                CommandFinished::class => LogCommandFinished::class,
            ]);
        }

        if (config('job-monitor.monitoring.job_correlation_enabled')) {
            $this->registerListeners([
                JobQueued::class => JobQueuedListener::class,
                JobProcessing::class => JobProcessingListener::class,
                JobProcessed::class => JobProcessedListener::class,
                JobFailed::class => JobFailedListener::class,
            ]);
        }
    }

    /**
     * Register event listeners.
     *
     * @param array $listeners
     * @return void
     */
    protected function registerListeners(array $listeners)
    {
        foreach ($listeners as $event => $listener) {
            Event::listen($event, $listener);
        }
    }

    /**
     * Validate the configuration.
     *
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function validateConfig()
    {
        $requiredKeys = ['monitoring.commands_enabled', 'monitoring.job_correlation_enabled'];
        foreach ($requiredKeys as $key) {
            if (is_null(config("job-monitor.$key"))) {
                throw new \InvalidArgumentException("The configuration key '$key' is required.");
            }
        }
    }

    /**
     * Validate the environment configuration.
     *
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function validateEnvironment()
    {
        $requiredEnvVars = [
            'JOB_MONITOR_REDIS_CONNECTION',
            'JOB_MONITOR_SYNC_ENABLED',
            'JOB_MONITOR_ANALYZE',
        ];

        foreach ($requiredEnvVars as $envVar) {
            if (!env($envVar)) {
                Log::warning("Environment variable {$envVar} is not set. Using default value.");
            }
        }

        // Validate Redis connection
        if (app()->environment('production')) {
            try {
                $redis = Redis::connection(
                    config('job-monitor.redis.connection', 'default')
                );
                $redis->ping();
            } catch (\Exception $e) {
                Log::error("Redis connection failed: " . $e->getMessage());
                if (app()->environment('production')) {
                    throw new \InvalidArgumentException("Redis connection failed: " . $e->getMessage());
                }
            }
        }

    }
}
