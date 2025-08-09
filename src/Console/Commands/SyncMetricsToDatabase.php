<?php

namespace Soroux\JobMonitor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Soroux\JobMonitor\Models\CommandMetric;
use Soroux\JobMonitor\Models\JobMetric;
use Exception;
use Carbon\Carbon;

class SyncMetricsToDatabase extends Command
{
    protected $signature = 'metrics:sync
                            {--force : Force sync even if disabled in config}
                            {--dry-run : Show what would be synced without actually syncing}
                            {--batch-size= : Override batch size from config}
                            {--cleanup : Clean up old Redis data after sync}';

    protected $description = 'Sync metrics from Redis to database';

    private $redis;
    private $config;
    private $startTime;
    private $memoryStart;
    protected $prefix;


    public function __construct()
    {
        parent::__construct();
        $this->config = config('job-monitor.sync');
        $this->redis = Redis::connection(config('job-monitor.redis.connection', 'default'));
        $client = $this->redis->client();
        $this->prefix = $client->getOption(\Redis::OPT_PREFIX);
    }

    public function handle(): int
    {
        $this->startTime = microtime(true);
        $this->memoryStart = memory_get_usage(true);

        try {
            // Check if sync is enabled
            if (!$this->config['enabled'] && !$this->option('force')) {
                $this->warn('Sync is disabled in configuration. Use --force to override.');
                return 0;
            }

            $this->info('Starting metrics sync...');
            $this->info('Configuration: ' . json_encode($this->config, JSON_PRETTY_PRINT));

            // Validate Redis connection
            if (!$this->validateRedisConnection()) {
                return 1;
            }

            // Sync command metrics
            $commandMetricsSynced = $this->syncCommandMetrics();

            // Sync job metrics
            $jobMetricsSynced = $this->syncJobMetrics();

            // Cleanup if requested
            if ($this->option('cleanup') || $this->config['cleanup_enabled']) {
                $this->cleanupOldRedisData();
            }

            // Log summary
            $this->logSyncSummary($commandMetricsSynced, $jobMetricsSynced);

            $this->info('Sync completed successfully!');
            $this->info("Synced {$commandMetricsSynced} command metrics and {$jobMetricsSynced} job metrics");

            return 0;

        } catch (Exception $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            Log::error('Metrics sync failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * Validate Redis connection
     *
     * @return bool
     */
    private function validateRedisConnection(): bool
    {
        try {
            $this->redis->ping();
            $this->info('✓ Redis connection validated');
            return true;
        } catch (Exception $e) {
            $this->error('✗ Redis connection failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync command metrics from Redis to database
     *
     * @return int
     */
    private function syncCommandMetrics(): int
    {
        $this->info('Syncing command metrics...');

        $batchSize = $this->option('batch-size') ?: $this->config['batch_size'];
        $synced = 0;
        $errors = 0;

        try {
            // Get all command metric keys
            $keys = $this->redis->keys('command:metrics:*');

            if (empty($keys)) {
                $this->info('No command metrics found in Redis');
                return 0;
            }

            $this->info("Found " . count($keys) . " command metric keys");

            // Process in batches
            $chunks = array_chunk($keys, $batchSize);

            foreach ($chunks as $chunkIndex => $chunk) {
                $this->info("Processing chunk " . ($chunkIndex + 1) . " of " . count($chunks));

                foreach ($chunk as $key) {
                    try {
                        $jobKey = $this->extractKeyWithoutPrefix($key);
                        if (!$jobKey) continue;

                        $metricData = $this->redis->hgetall($jobKey);

                        if (empty($metricData)) {
                            continue;
                        }

                        if ($this->option('dry-run')) {
                            $this->line("Would sync: {$key} - " . json_encode($metricData));
                            $synced++;
                            continue;
                        }

                        // Validate and create/update metric
                        if ($this->syncCommandMetric($key, $metricData)) {
                            $synced++;
                        }

                        // Add delay between chunks to prevent overwhelming the system
                        if ($chunkIndex > 0 && $this->config['chunk_delay_ms'] > 0) {
                            usleep($this->config['chunk_delay_ms'] * 1000);
                        }

                    } catch (Exception $e) {
                        $errors++;
                        $this->warn("Error processing key {$key}: " . $e->getMessage());
                        Log::warning("Failed to sync command metric", [
                            'key' => $key,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                // Check memory usage
                $this->checkMemoryUsage();
            }

        } catch (Exception $e) {
            $this->error('Failed to sync command metrics: ' . $e->getMessage());
            throw $e;
        }

        if ($errors > 0) {
            $this->warn("Completed with {$errors} errors");
        }

        return $synced;
    }

    /**
     * Sync a single command metric
     *
     * @param string $key
     * @param array $metricData
     * @return bool
     */
    private function syncCommandMetric(string $key, array $metricData): bool
    {
        try {
            // Extract process ID from key
            $processId = str_replace(['command:', ':metrics'], '', $key);

            // Prepare data for model
            $data = [
                'process_id' => $processId,
                'command_name' => $metricData['command_name'] ?? 'unknown',
                'source' => $metricData['source'] ?? 'console',
                'total_time' => (float) ($metricData['total_time'] ?? 0),
                'job_count' => (int) ($metricData['job_count'] ?? 0),
                'success_jobs' => (int) ($metricData['success_jobs'] ?? 0),
                'failed_jobs' => (int) ($metricData['failed_jobs'] ?? 0),
                'avg_job_time' => (float) ($metricData['avg_job_time'] ?? 0),
                'peak_memory' => (int) ($metricData['peak_memory'] ?? 0),
                'run_date' => $metricData['run_date'] ?? now()->toDateString(),
            ];

            // Validate data
            CommandMetric::validate($data);

            // Create or update metric
            CommandMetric::updateOrCreate(
                ['process_id' => $processId],
                $data
            );

            return true;

        } catch (Exception $e) {
            Log::error('Failed to sync command metric', [
                'key' => $key,
                'data' => $metricData,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Sync job metrics from Redis to database
     *
     * @return int
     */
    private function syncJobMetrics(): int
    {
        $this->info('Syncing job metrics...');

        $batchSize = $this->option('batch-size') ?: $this->config['batch_size'];
        $synced = 0;
        $errors = 0;

        try {
            // Get all job metric keys
            $keys = $this->redis->keys('job:metrics:*');

            if (empty($keys)) {
                $this->info('No job metrics found in Redis');
                return 0;
            }

            $this->info("Found " . count($keys) . " job metric keys");

            // Process in batches
            $chunks = array_chunk($keys, $batchSize);

            foreach ($chunks as $chunkIndex => $chunk) {
                $this->info("Processing chunk " . ($chunkIndex + 1) . " of " . count($chunks));

                foreach ($chunk as $key) {
                    try {
                        $jobKey = $this->extractKeyWithoutPrefix($key);
                        if (!$jobKey) continue;

                        $jobData = $this->redis->hgetall($key);

                        if (empty($jobData)) {
                            continue;
                        }

                        if ($this->option('dry-run')) {
                            $this->line("Would sync: {$key} - " . json_encode($jobData));
                            $synced++;
                            continue;
                        }

                        // Sync each job in the command
                        foreach ($jobData as $jobId => $jobInfo) {
                            if ($this->syncJobMetric($key, $jobId, $jobInfo)) {
                                $synced++;
                            }
                        }

                        // Add delay between chunks
                        if ($chunkIndex > 0 && $this->config['chunk_delay_ms'] > 0) {
                            usleep($this->config['chunk_delay_ms'] * 1000);
                        }

                    } catch (Exception $e) {
                        $errors++;
                        $this->warn("Error processing key {$key}: " . $e->getMessage());
                        Log::warning("Failed to sync job metrics", [
                            'key' => $key,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                // Check memory usage
                $this->checkMemoryUsage();
            }

        } catch (Exception $e) {
            $this->error('Failed to sync job metrics: ' . $e->getMessage());
            throw $e;
        }

        if ($errors > 0) {
            $this->warn("Completed with {$errors} errors");
        }

        return $synced;
    }
    protected function extractKeyWithoutPrefix($key)
    {
        if (strpos($key, $this->prefix) === 0) {
            return substr($key, strlen($this->prefix));
        }
        return null;
    }

    /**
     * Sync a single job metric
     *
     * @param string $key
     * @param string $jobId
     * @param string $jobInfo
     * @return bool
     */
    private function syncJobMetric(string $key, string $jobId, string $jobInfo): bool
    {
        try {
            // Extract process ID from key
            $processId = str_replace(['command:', ':jobs'], '', $key);

            // Decode job info
            $jobData = json_decode($jobInfo, true);

            if (!$jobData) {
                return false;
            }

            // Prepare data for model
            $data = [
                'job_id' => $jobId,
                'process_id' => $processId,
                'command_name' => $jobData['command_name'] ?? 'unknown',
                'execution_time' => (float) ($jobData['execution_time'] ?? 0),
                'memory_usage' => (int) ($jobData['memory_usage'] ?? 0),
                'queue_time' => (float) ($jobData['queue_time'] ?? 0),
                'status' => $jobData['status'] ?? 'unknown',
            ];

            // Validate data
            JobMetric::validate($data);

            // Create or update metric
            JobMetric::updateOrCreate(
                ['job_id' => $jobId],
                $data
            );

            return true;

        } catch (Exception $e) {
            Log::error('Failed to sync job metric', [
                'key' => $key,
                'job_id' => $jobId,
                'job_info' => $jobInfo,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Clean up old Redis data
     *
     * @return void
     */
    private function cleanupOldRedisData(): void
    {
        $this->info('Cleaning up old Redis data...');

        try {
            $cleanupAfterHours = $this->config['cleanup_after_hours'];
            $cutoffTime = now()->subHours($cleanupAfterHours);

            // Clean up old command metrics
            $commandKeys = $this->redis->keys('command:metrics:*');
            $cleanedCommands = 0;

            foreach ($commandKeys as $key) {
                $metricData = $this->redis->hgetall($key);
                if (isset($metricData['created_at'])) {
                    $createdAt = Carbon::parse($metricData['created_at']);
                    if ($createdAt->lt($cutoffTime)) {
                        $this->redis->del($key);
                        $cleanedCommands++;
                    }
                }
            }

            // Clean up old job data
            $jobKeys = $this->redis->keys('job:metrics:*');
            $cleanedJobs = 0;

            foreach ($jobKeys as $key) {
                $jobData = $this->redis->hgetall($key);
                if (isset($jobData['created_at'])) {
                    $createdAt = Carbon::parse($jobData['created_at']);
                    if ($createdAt->lt($cutoffTime)) {
                        $this->redis->del($key);
                        $cleanedJobs++;
                    }
                }
            }

            $this->info("Cleaned up {$cleanedCommands} command keys and {$cleanedJobs} job keys");

        } catch (Exception $e) {
            $this->warn('Cleanup failed: ' . $e->getMessage());
            Log::warning('Redis cleanup failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check memory usage and warn if too high
     *
     * @return void
     */
    private function checkMemoryUsage(): void
    {
        $currentMemory = memory_get_usage(true);
        $maxMemory = $this->config['max_memory_mb'] * 1024 * 1024; // Convert MB to bytes

        if ($currentMemory > $maxMemory) {
            $this->warn("Memory usage high: " . round($currentMemory / 1024 / 1024, 2) . "MB");
            Log::warning('High memory usage during sync', [
                'current_mb' => round($currentMemory / 1024 / 1024, 2),
                'max_mb' => $this->config['max_memory_mb']
            ]);
        }
    }

    /**
     * Log sync summary
     *
     * @param int $commandMetricsSynced
     * @param int $jobMetricsSynced
     * @return void
     */
    private function logSyncSummary(int $commandMetricsSynced, int $jobMetricsSynced): void
    {
        $duration = microtime(true) - $this->startTime;
        $memoryUsed = memory_get_usage(true) - $this->memoryStart;

        $summary = [
            'command_metrics_synced' => $commandMetricsSynced,
            'job_metrics_synced' => $jobMetricsSynced,
            'total_synced' => $commandMetricsSynced + $jobMetricsSynced,
            'duration_seconds' => round($duration, 2),
            'memory_used_mb' => round($memoryUsed / 1024 / 1024, 2),
            'timestamp' => now()->toISOString()
        ];

        Log::info('Metrics sync completed', $summary);

        $this->info('Sync Summary:');
        $this->info("- Duration: " . round($duration, 2) . " seconds");
        $this->info("- Memory used: " . round($memoryUsed / 1024 / 1024, 2) . "MB");
        $this->info("- Total metrics synced: " . ($commandMetricsSynced + $jobMetricsSynced));
    }
}
