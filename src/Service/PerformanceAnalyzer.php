<?php

namespace Soroux\JobMonitor\Service;

use Illuminate\Support\Str;
use Soroux\JobMonitor\Events\PerformanceAnomalyDetected;
use Soroux\JobMonitor\Models\CommandMetric;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;

class PerformanceAnalyzer
{
    /**
     * Analyze all commands for performance anomalies
     */
    public function analyzeAllCommands()
    {
        $commands = CommandMetric::select('command_name')
            ->distinct()
            ->where('run_date', '>=', now()->subDays(config('job-monitor.analyze_mode.retention_days')))
            ->pluck('command_name');

        foreach ($commands as $commandName) {
            $this->analyzeCommand($commandName);
        }

        // Check for missed scheduled commands
        if (config('job-monitor.analyze_mode.schedule_analysis_enabled')) {
            $this->checkMissedScheduledCommands();
        }
        // Check for missed API-triggered commands
        $this->checkMissedApiCommands();
    }

    /**
     * Analyze a specific command for all types of anomalies
     */
    public function analyzeCommand($commandName)
    {
        $this->checkPerformanceAnomaly($commandName);
        $this->checkFailedJobsAnomaly($commandName);
        $this->checkJobCountAnomaly($commandName);
    }

    /**
     * Check if command execution time is abnormally long
     */
    private function checkPerformanceAnomaly($commandName)
    {
        $current = CommandMetric::where('command_name', $commandName)
            ->where('run_date', now()->toDateString())
            ->first();

        if (!$current) return;

        $historical = CommandMetric::where('command_name', $commandName)
            ->where('run_date', '>=', now()->subDays(config('job-monitor.analyze_mode.retention_days')))
            ->where('run_date', '<>', now()->toDateString())
            ->avg('avg_job_time');

        if ($historical && $current->avg_job_time > ($historical * config('job-monitor.analyze_mode.performance_threshold'))) {
            event(new PerformanceAnomalyDetected(
                $commandName,
                'performance',
                [
                    'current_time' => $current->avg_job_time,
                    'historical_average' => $historical,
                    'threshold' => config('job-monitor.analyze_mode.performance_threshold'),
                    'multiplier' => $current->avg_job_time / $historical
                ]
            ));
        }
    }

    /**
     * Check if failed jobs count is abnormally high
     */
    private function checkFailedJobsAnomaly($commandName)
    {
        $current = CommandMetric::where('command_name', $commandName)
            ->where('run_date', now()->toDateString())
            ->first();

        if (!$current || $current->failed_jobs == 0) return;

        $historical = CommandMetric::where('command_name', $commandName)
            ->where('run_date', '>=', now()->subDays(config('job-monitor.analyze_mode.retention_days')))
            ->where('run_date', '<>', now()->toDateString())
            ->avg('failed_jobs');

        if ($historical && $current->failed_jobs > ($historical * config('job-monitor.analyze_mode.failed_jobs_threshold'))) {
            event(new PerformanceAnomalyDetected(
                $commandName,
                'failed_jobs',
                [
                    'current_failed' => $current->failed_jobs,
                    'historical_average' => $historical,
                    'threshold' => config('job-monitor.analyze_mode.failed_jobs_threshold'),
                    'multiplier' => $current->failed_jobs / $historical
                ]
            ));
        }
    }

    /**
     * Check if total job count is unusual
     */
    private function checkJobCountAnomaly($commandName)
    {
        $current = CommandMetric::where('command_name', $commandName)
            ->where('run_date', now()->toDateString())
            ->first();

        if (!$current) return;

        $historical = CommandMetric::where('command_name', $commandName)
            ->where('run_date', '>=', now()->subDays(config('job-monitor.analyze_mode.retention_days')))
            ->where('run_date', '<>', now()->toDateString())
            ->avg('job_count');

        if ($historical && $current->job_count > ($historical * config('job-monitor.analyze_mode.job_count_threshold'))) {
            event(new PerformanceAnomalyDetected(
                $commandName,
                'high_job_count',
                [
                    'current_count' => $current->job_count,
                    'historical_average' => $historical,
                    'threshold' => config('job-monitor.analyze_mode.job_count_threshold'),
                    'multiplier' => $current->job_count / $historical
                ]
            ));
        }

        // Check for unusually low job count
        if ($historical && $current->job_count < ($historical * 0.5)) {
            event(new PerformanceAnomalyDetected(
                $commandName,
                'low_job_count',
                [
                    'current_count' => $current->job_count,
                    'historical_average' => $historical,
                    'threshold' => 0.5,
                    'multiplier' => $current->job_count / $historical
                ]
            ));
        }
    }

    /**
     * Check for scheduled commands that should have run but didn't
     * Uses Laravel's Schedule service for robust schedule discovery
     */
    private function checkMissedScheduledCommands()
    {
        $scheduledCommands = $this->getScheduledCommands();
        $thresholdHours = config('job-monitor.analyze_mode.missed_execution_threshold_hours');
        foreach ($scheduledCommands as $event) {
            $command = $event['command'];
            $nextRun = Carbon::parse($event['nextRun']);
            $expression = $event['expression'];
            $description = $event['description'];

            // Only consider console runs (manual or schedule)
            $lastExecution = CommandMetric::where('command_name', $command)
                ->where('source', 'console')
                ->orderBy('run_date', 'desc')
                ->first();

            if (!$lastExecution) {
                // Command has never run
                event(new PerformanceAnomalyDetected(
                    $command,
                    'never_executed',
                    [
                        'schedule_expression' => $expression,
                        'description' => $description,
                        'expected_next_run' => $nextRun->toDateTimeString(),
                    ]
                ));
                continue;
            }

            // If the next scheduled run is in the past by more than threshold, it's missed
            $now = now();
            if ($now->greaterThan($nextRun) && $now->diffInHours($nextRun) > $thresholdHours) {
                event(new PerformanceAnomalyDetected(
                    $command,
                    'missed_execution',
                    [
                        'last_execution' => $lastExecution->run_date,
                        'expected_next_run' => $nextRun->toDateTimeString(),
                        'hours_overdue' => $now->diffInHours($nextRun),
                        'schedule_expression' => $expression,
                        'description' => $description,
                    ]
                ));
            }
        }
    }

    /**
     * Check for API-triggered commands that should have run but didn't
     * (e.g., commands called via Artisan::call from HTTP requests)
     * You can customize the threshold as needed.
     */
    private function checkMissedApiCommands()
    {
        // Define which commands are expected to be called via API and their expected interval (in minutes)
        $apiCommands = config('job-monitor.api_commands', []); // e.g. ['my:api-command' => 60]
        foreach ($apiCommands as $command => $expectedIntervalMinutes) {
            $lastExecution = CommandMetric::where('command_name', $command)
                ->where('source', 'api')
                ->orderBy('run_date', 'desc')
                ->first();
            $now = now();
            if (!$lastExecution || $now->diffInMinutes($lastExecution->run_date) > $expectedIntervalMinutes) {
                event(new PerformanceAnomalyDetected(
                    $command,
                    'missed_api_call',
                    [
                        'last_execution' => $lastExecution ? $lastExecution->run_date : null,
                        'expected_interval_minutes' => $expectedIntervalMinutes,
                        'now' => $now->toDateTimeString(),
                    ]
                ));
            }
        }
    }

    /**
     * Get scheduled commands using Laravel's Schedule service
     * Returns array of [command, expression, description, nextRun]
     */
    private function getScheduledCommands()
    {
        $schedule = app()->make(Schedule::class);
        $commands = [];

        foreach ($schedule->events() as $event) {
            $artisanCommand = Str::before(Str::after($event->command, 'artisan '), ' ');
            if (in_array($artisanCommand, config('job-monitor.ignore_commands'))) {
                continue;
            }
            $commands[] = [
                'command' => $event->command,
                'expression' => $event->expression,
                'description' => $event->description,
                'nextRun' => $event->nextRunDate()->toDateTimeString(),
            ];
        }
        return $commands;
    }
}
