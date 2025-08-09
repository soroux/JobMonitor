<?php

namespace Soroux\JobMonitor\Service;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Soroux\JobMonitor\Events\PerformanceAnomalyDetected;
use Soroux\JobMonitor\Models\CommandMetric;
use Soroux\JobMonitor\Models\JobMetric;
use Exception;
use Carbon\Carbon;

class PerformanceAnalyzer
{
    private $config;

    public function __construct()
    {
        $this->config = config('job-monitor.analyze_mode');
    }

    /**
     * Analyze performance and detect anomalies
     *
     * @return array
     * @throws Exception
     */
    public function analyze(): array
    {
        try {
            Log::info('Starting performance analysis', [
                'timestamp' => now()->toISOString(),
                'config' => $this->config
            ]);

            $analysis = [
                'timestamp' => now()->toISOString(),
                'anomalies_detected' => 0,
                'commands_analyzed' => 0,
                'errors' => [],
                'warnings' => []
            ];

            // Get all unique commands from recent metrics
            $commands = $this->getRecentCommands();
            $analysis['commands_analyzed'] = count($commands);

            if (empty($commands)) {
                Log::info('No commands found for analysis');
                $analysis['warnings'][] = 'No commands found for analysis';
                return $analysis;
            }

            foreach ($commands as $command) {
                try {
                    $commandAnalysis = $this->analyzeCommand($command);
                    if ($commandAnalysis['has_anomalies']) {
                        $analysis['anomalies_detected']++;
                        // Add summary for this command
                        $commandAnalysis['summary'] = $this->getAnalysisSummary($commandAnalysis);
                    }
                } catch (Exception $e) {
                    $error = "Error analyzing command '{$command}': " . $e->getMessage();
                    Log::error($error, ['command' => $command, 'exception' => $e]);
                    $analysis['errors'][] = $error;
                }
            }

            // Add overall analysis summary
            $analysis['summary'] = [
                'total_anomalies' => $analysis['anomalies_detected'],
                'commands_analyzed' => $analysis['commands_analyzed'],
                'anomaly_rate' => $analysis['commands_analyzed'] > 0 ? 
                    round(($analysis['anomalies_detected'] / $analysis['commands_analyzed']) * 100, 2) : 0
            ];

            // Check for missed scheduled executions
            if ($this->config['schedule_analysis_enabled']) {
                try {
                    $missedExecutions = $this->checkMissedScheduledExecutions();
                    if (!empty($missedExecutions)) {
                        $analysis['warnings'][] = "Missed scheduled executions detected: " . implode(', ', $missedExecutions);
                    }
                } catch (Exception $e) {
                    $error = "Error checking missed scheduled executions: " . $e->getMessage();
                    Log::error($error, ['exception' => $e]);
                    $analysis['errors'][] = $error;
                }
            }

            Log::info('Performance analysis completed', [
                'anomalies_detected' => $analysis['anomalies_detected'],
                'commands_analyzed' => $analysis['commands_analyzed']
            ]);

            return $analysis;

        } catch (Exception $e) {
            Log::error('Performance analysis failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Analyze all commands for anomalies
     *
     * @return array
     * @throws Exception
     */
    public function analyzeAllCommands(): array
    {
        return $this->analyze();
    }

    /**
     * Analyze a specific command for anomalies
     *
     * @param string $commandName
     * @return array
     */
    public function analyzeCommand(string $commandName): array
    {
        $retentionDays = $this->config['retention_days'];
        $startDate = now()->subDays($retentionDays);

        // Get recent metrics for this command
        $recentMetrics = CommandMetric::byCommand($commandName)
            ->byDateRange($startDate, now())
            ->orderBy('run_date', 'desc')
            ->get();
        
        // Get latest metric
        $latestMetric = $recentMetrics->first();
        
        // Check if we have enough data for analysis
        if ($recentMetrics->count() < 2) {
            return [
                'has_anomalies' => false,
                'reason' => 'Insufficient data for analysis (need at least 2 data points)',
                'data_points' => $recentMetrics->count()
            ];
        }
        
        // Calculate baseline metrics (excluding the latest metric)
        $historicalMetrics = $recentMetrics->except(['id' => $latestMetric->id]);
        $baseline = $this->calculateBaseline($historicalMetrics);
        
        // Check for anomalies
        $anomalies = $this->detectAnomalies($latestMetric, $baseline);
        
        $commandAnalysis = [
            'has_anomalies' => !empty($anomalies),
            'anomalies' => $anomalies,
            'baseline' => $baseline,
            'latest_metric' => $latestMetric,
            'data_points' => $recentMetrics->count()
        ];
        
        if ($commandAnalysis['has_anomalies']) {
            $this->handleAnomaly($commandName, $commandAnalysis);
            // Add summary for this command
            $commandAnalysis['summary'] = $this->getAnalysisSummary($commandAnalysis);
        }
        
        return $commandAnalysis;
    }

    /**
     * Calculate baseline metrics from historical data
     *
     * @param \Illuminate\Database\Eloquent\Collection $metrics
     * @return array
     */
    private function calculateBaseline($metrics): array
    {
        $totalTime = $metrics->avg('total_time');
        $jobCount = $metrics->avg('job_count');
        $successJobs = $metrics->avg('success_jobs');
        $failedJobs = $metrics->avg('failed_jobs');
        $avgJobTime = $metrics->avg('avg_job_time');
        $peakMemory = $metrics->avg('peak_memory');

        // Calculate standard deviations for anomaly detection
        $totalTimeStdDev = $this->calculateStandardDeviation($metrics->pluck('total_time')->toArray());
        $jobCountStdDev = $this->calculateStandardDeviation($metrics->pluck('job_count')->toArray());
        $failedJobsStdDev = $this->calculateStandardDeviation($metrics->pluck('failed_jobs')->toArray());

        return [
            'total_time' => [
                'average' => $totalTime,
                'std_dev' => $totalTimeStdDev,
                'threshold_upper' => $totalTime * $this->config['performance_threshold'],
                'threshold_lower' => $totalTime * $this->config['performance_threshold_lower']
            ],
            'job_count' => [
                'average' => $jobCount,
                'std_dev' => $jobCountStdDev,
                'threshold_upper' => $jobCount * $this->config['job_count_threshold'],
                'threshold_lower' => $jobCount * $this->config['job_count_threshold_lower']
            ],
            'failed_jobs' => [
                'average' => $failedJobs,
                'std_dev' => $failedJobsStdDev,
                'threshold_upper' => $failedJobs * $this->config['failed_jobs_threshold'],
                'threshold_lower' => $failedJobs * $this->config['failed_jobs_threshold_lower']
            ],
            'success_jobs' => $successJobs,
            'avg_job_time' => $avgJobTime,
            'peak_memory' => $peakMemory
        ];
    }

    /**
     * Detect anomalies based on thresholds
     *
     * @param CommandMetric $metric
     * @param array $baseline
     * @return array
     */
    private function detectAnomalies(CommandMetric $metric, array $baseline): array
    {
        $anomalies = [];

        // Check total execution time - both upper and lower bounds
        if ($metric->total_time > $baseline['total_time']['threshold_upper']) {
            $anomalies[] = [
                'type' => 'performance_degradation',
                'metric' => 'total_time',
                'current' => $metric->total_time,
                'threshold' => $baseline['total_time']['threshold_upper'],
                'baseline_avg' => $baseline['total_time']['average'],
                'direction' => 'worse',
                'percentage_change' => $this->calculatePercentageChange($metric->total_time, $baseline['total_time']['average']),
                'severity' => $this->calculateSeverity($metric->total_time, $baseline['total_time']['average'])
            ];
        }
        
        if ($metric->total_time < $baseline['total_time']['threshold_lower']) {
            $anomalies[] = [
                'type' => 'performance_improvement',
                'metric' => 'total_time',
                'current' => $metric->total_time,
                'threshold' => $baseline['total_time']['threshold_lower'],
                'baseline_avg' => $baseline['total_time']['average'],
                'direction' => 'better',
                'percentage_change' => $this->calculatePercentageChange($metric->total_time, $baseline['total_time']['average']),
                'severity' => $this->calculateSeverity($metric->total_time, $baseline['total_time']['average'])
            ];
        }

        // Check job count - both upper and lower bounds
        if ($metric->job_count > $baseline['job_count']['threshold_upper']) {
            $anomalies[] = [
                'type' => 'unusual_workload_high',
                'metric' => 'job_count',
                'current' => $metric->job_count,
                'threshold' => $baseline['job_count']['threshold_upper'],
                'baseline_avg' => $baseline['job_count']['average'],
                'direction' => 'higher',
                'percentage_change' => $this->calculatePercentageChange($metric->job_count, $baseline['job_count']['average']),
                'severity' => $this->calculateSeverity($metric->job_count, $baseline['job_count']['average'])
            ];
        }
        
        if ($metric->job_count < $baseline['job_count']['threshold_lower']) {
            $anomalies[] = [
                'type' => 'unusual_workload_low',
                'metric' => 'job_count',
                'current' => $metric->job_count,
                'threshold' => $baseline['job_count']['threshold_lower'],
                'baseline_avg' => $baseline['job_count']['average'],
                'direction' => 'lower',
                'percentage_change' => $this->calculatePercentageChange($metric->job_count, $baseline['job_count']['average']),
                'severity' => $this->calculateSeverity($metric->job_count, $baseline['job_count']['average'])
            ];
        }

        // Check failed jobs - both upper and lower bounds
        if ($metric->failed_jobs > $baseline['failed_jobs']['threshold_upper']) {
            $anomalies[] = [
                'type' => 'high_failure_rate',
                'metric' => 'failed_jobs',
                'current' => $metric->failed_jobs,
                'threshold' => $baseline['failed_jobs']['threshold_upper'],
                'baseline_avg' => $baseline['failed_jobs']['average'],
                'direction' => 'worse',
                'percentage_change' => $this->calculatePercentageChange($metric->failed_jobs, $baseline['failed_jobs']['average']),
                'severity' => $this->calculateSeverity($metric->failed_jobs, $baseline['failed_jobs']['average'])
            ];
        }
        
        if ($metric->failed_jobs < $baseline['failed_jobs']['threshold_lower']) {
            $anomalies[] = [
                'type' => 'low_failure_rate',
                'metric' => 'failed_jobs',
                'current' => $metric->failed_jobs,
                'threshold' => $baseline['failed_jobs']['threshold_lower'],
                'baseline_avg' => $baseline['failed_jobs']['average'],
                'direction' => 'better',
                'percentage_change' => $this->calculatePercentageChange($metric->failed_jobs, $baseline['failed_jobs']['average']),
                'severity' => $this->calculateSeverity($metric->failed_jobs, $baseline['failed_jobs']['average'])
            ];
        }

        // Check for zero success jobs when there are jobs
        if ($metric->job_count > 0 && $metric->success_jobs === 0) {
            $anomalies[] = [
                'type' => 'complete_failure',
                'metric' => 'success_jobs',
                'current' => $metric->success_jobs,
                'baseline_avg' => $baseline['success_jobs'],
                'direction' => 'worse',
                'percentage_change' => $this->calculatePercentageChange($metric->success_jobs, $baseline['success_jobs']),
                'severity' => 'critical'
            ];
        }

        return $anomalies;
    }

    /**
     * Calculate percentage change from baseline
     *
     * @param float $current
     * @param float $baseline
     * @return float
     */
    private function calculatePercentageChange(float $current, float $baseline): float
    {
        if ($baseline == 0) {
            return 0.0;
        }

        return (($current - $baseline) / $baseline) * 100;
    }

    /**
     * Calculate severity level based on deviation from baseline
     *
     * @param float $current
     * @param float $baseline
     * @return string
     */
    private function calculateSeverity(float $current, float $baseline): string
    {
        if ($baseline == 0) {
            return 'warning';
        }

        $deviation = abs(($current - $baseline) / $baseline);

        if ($deviation >= 3.0) {
            return 'critical';
        } elseif ($deviation >= 2.0) {
            return 'high';
        } elseif ($deviation >= 1.5) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Calculate standard deviation
     *
     * @param array $values
     * @return float
     */
    private function calculateStandardDeviation(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        $variance = 0.0;

        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }

        return sqrt($variance / ($count - 1));
    }

    /**
     * Get a summary of the analysis results
     *
     * @param array $analysis
     * @return array
     */
    public function getAnalysisSummary(array $analysis): array
    {
        $summary = [
            'total_anomalies' => 0,
            'performance_degradations' => 0,
            'performance_improvements' => 0,
            'workload_anomalies' => 0,
            'failure_rate_anomalies' => 0,
            'critical_anomalies' => 0,
            'high_anomalies' => 0,
            'medium_anomalies' => 0,
            'low_anomalies' => 0,
        ];

        if (isset($analysis['anomalies'])) {
            foreach ($analysis['anomalies'] as $anomaly) {
                $summary['total_anomalies']++;
                
                // Count by type
                switch ($anomaly['type']) {
                    case 'performance_degradation':
                        $summary['performance_degradations']++;
                        break;
                    case 'performance_improvement':
                        $summary['performance_improvements']++;
                        break;
                    case 'unusual_workload_high':
                    case 'unusual_workload_low':
                        $summary['workload_anomalies']++;
                        break;
                    case 'high_failure_rate':
                    case 'low_failure_rate':
                        $summary['failure_rate_anomalies']++;
                        break;
                }
                
                // Count by severity
                switch ($anomaly['severity']) {
                    case 'critical':
                        $summary['critical_anomalies']++;
                        break;
                    case 'high':
                        $summary['high_anomalies']++;
                        break;
                    case 'medium':
                        $summary['medium_anomalies']++;
                        break;
                    case 'low':
                        $summary['low_anomalies']++;
                        break;
                }
            }
        }

        return $summary;
    }

    /**
     * Handle detected anomalies
     *
     * @param string $commandName
     * @param array $analysis
     * @return void
     */
    private function handleAnomaly(string $commandName, array $analysis): void
    {
        foreach ($analysis['anomalies'] as $anomaly) {
            $event = new PerformanceAnomalyDetected(
                $commandName,
                $anomaly['type'],
                $anomaly['metric'],
                $anomaly['current'],
                $anomaly['baseline_avg'],
                $anomaly['severity'],
                $analysis['latest_metric']
            );

            event($event);

            Log::warning('Performance anomaly detected', [
                'command' => $commandName,
                'anomaly' => $anomaly,
                'metric_id' => $analysis['latest_metric']->id
            ]);
        }
    }

    /**
     * Check for missed scheduled executions
     *
     * @return array
     */
    private function checkMissedScheduledExecutions(): array
    {
        $missedCommands = [];
        $thresholdHours = $this->config['missed_execution_threshold_hours'];
        $cutoffTime = now()->subHours($thresholdHours);

        // Check API-triggered commands
        foreach (config('job-monitor.api_commands', []) as $command => $expectedIntervalMinutes) {
            $lastExecution = CommandMetric::byCommand($command)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$lastExecution || $lastExecution->created_at < $cutoffTime) {
                $missedCommands[] = $command;
            }
        }

        return $missedCommands;
    }

    /**
     * Get recent commands for analysis
     *
     * @return array
     */
    private function getRecentCommands(): array
    {
        $retentionDays = $this->config['retention_days'];
        $startDate = now()->subDays($retentionDays);

        return CommandMetric::byDateRange($startDate, now())
            ->distinct()
            ->pluck('command_name')
            ->filter(function ($command) {
                return !in_array($command, config('job-monitor.ignore_commands', []));
            })
            ->values()
            ->toArray();
    }
}
