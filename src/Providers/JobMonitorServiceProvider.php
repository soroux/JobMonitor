<?php

namespace Soroux\JobMonitor\Providers;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
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

        // Validate configuration
        $this->validateConfig();

        // Register the API routes
        $this->registerRoutes();

        // Register event listeners
        $this->registerEventListeners();
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
        return [
            'prefix' => config('job-monitor.prefix'),
            'middleware' => config('job-monitor.middleware'),
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
}
