<?php

namespace Soroux\JobMonitor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Soroux\JobMonitor\Models\CommandMetric;
use Soroux\JobMonitor\Models\JobMetric;
use Carbon\Carbon;
use Exception;

class SyncMetricsToDatabase extends Command
{
    protected $signature = 'metrics:sync {--force : Force sync even if disabled} {--dry-run : Show what would be synced without actually syncing}';
    protected $description = 'Sync Redis metrics to database with robust error handling';
    
    protected $redis;
    protected $prefix;
    protected $startTime;
    protected $memoryLimit;
    protected $timeoutLimit;
    protected $stats = [
        'commands_synced' => 0,
        'jobs_synced' => 0,
        'errors' => 0,
        'warnings' => 0,
    ];

    public function handle()
    {
        // Check if sync is enabled
        if (!config('job-monitor.sync.enabled') && !$this->option('force')) {
            $this->warn('Sync is disabled. Use --force to override.');
            return 0;
        }

        $this->startTime = microtime(true);
        $this->memoryLimit = config('job-monitor.sync.max_memory_mb', 100) * 1024 * 1024; // Convert to bytes
        $this->timeoutLimit = config('job-monitor.sync.timeout_seconds', 300);

        $this->info('Starting metrics sync...');
        
        try {
            $this->initializeRedis();
            $this->validateRedisConnection();
            
            if ($this->option('dry-run')) {
                $this->info('DRY RUN MODE - No data will be synced');
            }

            $this->syncCommandMetrics();
            $this->syncJobMetrics();
            
            if (config('job-monitor.sync.cleanup_enabled')) {
                $this->cleanupOlderMetrics();
            }

            $this->displayStats();
            $this->info('Sync completed successfully!');
            
            return 0;
            
        } catch (Exception $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            Log::error('Metrics sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'stats' => $this->stats
            ]);
            
            return 1;
        }
    }

    protected function initializeRedis()
    {
        try {
            $this->redis = Redis::connection(config('job-monitor.monitor-connection'));
            $client = $this->redis->client();
            $this->prefix = $client->getOption(\Redis::OPT_PREFIX);
            
            $this->info('Redis connection established');
        } catch (Exception $e) {
            throw new Exception('Failed to connect to Redis: ' . $e->getMessage());
        }
    }

    protected function validateRedisConnection()
    {
        try {
            $this->redis->ping();
            $this->info('Redis connection validated');
        } catch (Exception $e) {
            throw new Exception('Redis connection validation failed: ' . $e->getMessage());
        }
    }

    protected function syncCommandMetrics()
    {
        $this->info('Syncing command metrics...');
        
        try {
            $commandKeys = $this->redis->keys("command:metrics:*");
            $totalKeys = count($commandKeys);
            
            if ($totalKeys === 0) {
                $this->info('No command metrics found in Redis');
                return;
            }

            $this->info("Found {$totalKeys} command metrics to sync");
            $bar = $this->output->createProgressBar($totalKeys);
            $bar->start();

            $syncedCount = 0;
            $errorCount = 0;

            foreach ($commandKeys as $key) {
                $this->checkMemoryAndTimeout();
                
                try {
                    $jobKey = $this->extractKeyWithoutPrefix($key);
                    if (!$jobKey) continue;

                    $metrics = $this->redis->hgetall($jobKey);
                    
                    if (empty($metrics)) {
                        $this->warn("Empty metrics for key: {$key}");
                        $this->stats['warnings']++;
                        continue;
                    }

                    if (!$this->validateCommandMetrics($metrics)) {
                        $this->warn("Invalid metrics for key: {$key}");
                        $this->stats['warnings']++;
                        continue;
                    }

                    if (!$this->option('dry-run')) {
                        $this->createOrUpdateCommandMetric($metrics);
                    }
                    
                    $syncedCount++;
                    $this->stats['commands_synced']++;
                    
                } catch (Exception $e) {
                    $this->error("Error syncing command metric {$key}: " . $e->getMessage());
                    $errorCount++;
                    $this->stats['errors']++;
                    Log::warning('Command metric sync error', [
                        'key' => $key,
                        'error' => $e->getMessage()
                    ]);
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
            $this->info("Command metrics synced: {$syncedCount}, Errors: {$errorCount}");

        } catch (Exception $e) {
            throw new Exception('Command metrics sync failed: ' . $e->getMessage());
        }
    }

    protected function syncJobMetrics()
    {
        $this->info('Syncing job metrics...');
        
        try {
            $jobKeys = $this->redis->keys("job:metrics:*");
            $totalKeys = count($jobKeys);
            
            if ($totalKeys === 0) {
                $this->info('No job metrics found in Redis');
                return;
            }

            $this->info("Found {$totalKeys} job metrics to sync");
            $batchSize = config('job-monitor.sync.batch_size', 500);
            $batch = [];
            $syncedCount = 0;
            $errorCount = 0;

            $bar = $this->output->createProgressBar($totalKeys);
            $bar->start();

            foreach ($jobKeys as $key) {
                $this->checkMemoryAndTimeout();
                
                try {
                    $jobKey = $this->extractKeyWithoutPrefix($key);
                    if (!$jobKey) continue;

                    $metrics = $this->redis->hgetall($jobKey);
                    
                    if (empty($metrics)) {
                        $this->warn("Empty metrics for key: {$key}");
                        $this->stats['warnings']++;
                        continue;
                    }

                    if (!$this->validateJobMetrics($metrics)) {
                        $this->warn("Invalid metrics for key: {$key}");
                        $this->stats['warnings']++;
                        continue;
                    }

                    $batch[] = $this->prepareJobMetricData($key, $metrics);
                    
                    if (count($batch) >= $batchSize) {
                        if (!$this->option('dry-run')) {
                            $this->insertJobMetricsBatch($batch);
                        }
                        $syncedCount += count($batch);
                        $this->stats['jobs_synced'] += count($batch);
                        $batch = [];
                    }

                } catch (Exception $e) {
                    $this->error("Error syncing job metric {$key}: " . $e->getMessage());
                    $errorCount++;
                    $this->stats['errors']++;
                    Log::warning('Job metric sync error', [
                        'key' => $key,
                        'error' => $e->getMessage()
                    ]);
                }

                $bar->advance();
            }

            // Insert remaining batch
            if (!empty($batch) && !$this->option('dry-run')) {
                $this->insertJobMetricsBatch($batch);
                $syncedCount += count($batch);
                $this->stats['jobs_synced'] += count($batch);
            }

            $bar->finish();
            $this->newLine();
            $this->info("Job metrics synced: {$syncedCount}, Errors: {$errorCount}");

        } catch (Exception $e) {
            throw new Exception('Job metrics sync failed: ' . $e->getMessage());
        }
    }

    protected function extractKeyWithoutPrefix($key)
    {
        if (strpos($key, $this->prefix) === 0) {
            return substr($key, strlen($this->prefix));
        }
        return null;
    }

    protected function validateCommandMetrics($metrics)
    {
        $required = ['command_name', 'process_id', 'start_time'];
        foreach ($required as $field) {
            if (!isset($metrics[$field]) || empty($metrics[$field])) {
                return false;
            }
        }
        return true;
    }

    protected function validateJobMetrics($metrics)
    {
        $required = ['process_id', 'command_name', 'status'];
        foreach ($required as $field) {
            if (!isset($metrics[$field]) || empty($metrics[$field])) {
                return false;
            }
        }
        return true;
    }

    protected function createOrUpdateCommandMetric($metrics)
    {
        $source = $metrics['source'] ?? (app()->runningInConsole() ? 'console' : 'api');
        
        CommandMetric::updateOrCreate(
            [
                'command_name' => $metrics['command_name'], 
                'process_id' => $metrics['process_id']
            ],
            [
                'total_time' => $metrics['total_job_time'] ?? 0,
                'job_count' => $metrics['job_count'] ?? 0,
                'success_jobs' => $metrics['success_jobs'] ?? 0,
                'failed_jobs' => $metrics['failed_jobs'] ?? 0,
                'avg_job_time' => ($metrics['total_job_time'] ?? 0) / max(1, $metrics['job_count'] ?? 1),
                'peak_memory' => $metrics['peak_memory'] ?? 0,
                'run_date' => $metrics['start_time'],
                'source' => $source,
                'updated_at' => now()
            ]
        );
    }

    protected function prepareJobMetricData($key, $metrics)
    {
        return [
            'job_id' => str_replace('job:metrics:', '', $key),
            'process_id' => $metrics['process_id'],
            'command_name' => $metrics['command_name'],
            'execution_time' => $metrics['execution_time'] ?? 0,
            'memory_usage' => $metrics['memory_usage'] ?? 0,
            'queue_time' => $metrics['queue_time'] ?? 0,
            'status' => $metrics['status'],
            'created_at' => $metrics['timestamp'] ?? now(),
            'updated_at' => now()
        ];
    }

    protected function insertJobMetricsBatch($batch)
    {
        try {
            JobMetric::insert($batch);
        } catch (Exception $e) {
            Log::error('Failed to insert job metrics batch', [
                'batch_size' => count($batch),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    protected function cleanupOlderMetrics()
    {
        $this->info('Cleaning up old Redis metrics...');
        
        try {
            $cutoff = now()->subHours(config('job-monitor.sync.cleanup_after_hours', 24));
            $deletedCount = 0;

            $keys = array_merge(
                $this->redis->keys("job:metrics:*"),
                $this->redis->keys("command:metrics:*")
            );

            foreach ($keys as $key) {
                $timestamp = $this->redis->hget($key, 'timestamp');
                if ($timestamp && Carbon::parse($timestamp)->lt($cutoff)) {
                    $this->redis->del($key);
                    $deletedCount++;
                }
            }

            $this->info("Cleaned up {$deletedCount} old metric keys");

        } catch (Exception $e) {
            $this->warn('Cleanup failed: ' . $e->getMessage());
            Log::warning('Metrics cleanup failed', ['error' => $e->getMessage()]);
        }
    }

    protected function checkMemoryAndTimeout()
    {
        // Check memory usage
        $memoryUsage = memory_get_usage(true);
        if ($memoryUsage > $this->memoryLimit) {
            throw new Exception("Memory limit exceeded: " . round($memoryUsage / 1024 / 1024, 2) . "MB");
        }

        // Check execution time
        $executionTime = microtime(true) - $this->startTime;
        if ($executionTime > $this->timeoutLimit) {
            throw new Exception("Timeout exceeded: " . round($executionTime, 2) . " seconds");
        }
    }

    protected function displayStats()
    {
        $executionTime = round(microtime(true) - $this->startTime, 2);
        $memoryUsage = round(memory_get_usage(true) / 1024 / 1024, 2);

        $this->newLine();
        $this->info('=== Sync Statistics ===');
        $this->info("Execution time: {$executionTime} seconds");
        $this->info("Memory usage: {$memoryUsage}MB");
        $this->info("Commands synced: {$this->stats['commands_synced']}");
        $this->info("Jobs synced: {$this->stats['jobs_synced']}");
        $this->info("Errors: {$this->stats['errors']}");
        $this->info("Warnings: {$this->stats['warnings']}");
    }
}
