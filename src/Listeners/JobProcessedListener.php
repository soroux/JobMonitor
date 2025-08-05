<?php

namespace Soroux\JobMonitor\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Soroux\JobMonitor\Concerns\TrackableJob;

class JobProcessedListener
{
    protected $redis;

    public function __construct()
    {
        $this->redis = Redis::connection(config('job-monitor.monitor-connection'));
    }

    public function handle(JobProcessed $event): void
    {
        // Early return if queue is not monitored
        if (!in_array($event->job->getQueue(), config('job-monitor.queues', []))) {
            return;
        }

        $payload = $event->job->payload();
        $jobId = $payload['uuid'] ?? null;

        if (!$jobId) {
            Log::warning("[JobMonitor] Job processed without UUID");
            return;
        }

        try {
            /** @var object $job */
            $job = unserialize($payload['data']['command']);
        } catch (\Throwable $e) {
            Log::error("[JobMonitor] Failed to unserialize job {$jobId}: " . $e->getMessage());
            return;
        }

        if (!in_array(TrackableJob::class, class_uses_recursive($job))) {
            return;
        }

        $processId = $job->commandProcessId ?? null;
        $commandName = $job->commandName ?? null;

        if (!$processId) {
            Log::warning("[JobMonitor] Job {$jobId} completed without process ID");
            return;
        }

        $key = "command:{$processId}:jobs";

        try {
            // Calculate execution time with fallback
            $oldData = $this->redis->hget($key, $jobId);
            $oldData = json_decode($oldData, true);

            $startedAt = $oldData['started_at'] ?? microtime(true);
            $duration = microtime(true) - $startedAt;

            $queueTime = $oldData['queue_time'] ?? 0;

            $totalTime = $duration + $queueTime;


            $jobData = [
                'status' => 'completed',
                'completed_at' => microtime(true),
                'execution_time' => round($duration, 4),
                'total_time' => round($totalTime, 4),
                'queue_time' => round($queueTime, 4),
                'process_id' => $processId,
                'command_name' => $commandName,
                'job_type' => $job->jobType ?? null,
                'queue' => $event->job->getQueue(),
                'job_class' => get_class($job),
            ];

            $this->redis->hset($key, $jobId, json_encode($jobData));
            $this->redis->expire($key, config('job-monitor.completed_ttl', 3600));

            if (config('job-monitor.analyze_mode.enabled')) {
                $this->analyzeJob($event, $jobData, $jobId);
            }


            Log::info("[JobMonitor] Job {$jobId} completed successfully with process ID {$processId}");
        } catch (\Exception $e) {
            Log::error("[JobMonitor] Failed to mark job {$jobId} as completed. Error: {$e->getMessage()}");
        }
    }

    public function analyzeJob(JobProcessed $event, array $jobData, string $jobId): void
    {
        $peakMemoryUsage = memory_get_peak_usage(true);
        if ($jobData['command_name'] == 'manual-dispatch') {
            // Store job metrics
            $this->redis->hmset("job:metrics:{$jobId}", [
                'execution_time' => $jobData['execution_time'],
                'process_id' => $jobData['process_id'],
                'memory_usage' => $peakMemoryUsage,
                'queue_time' => $jobData['queue_time'],
                'command_name' => $jobData['command_name'],
                'status' => 'success',
                'timestamp' => now()->toDateTimeString()
            ]);
        }
        if ($jobData['command_name'] !== 'manual-dispatch') {

            // Update command metrics
            $commandKey = "command:metrics:{$jobData['command_name']}:{$jobData['process_id']}";
            $this->redis->hincrby($commandKey, 'success_jobs', 1);
            $this->redis->hincrbyfloat($commandKey, 'total_job_time', $jobData['execution_time']);
            $this->redis->hset($commandKey, 'peak_memory',
                max($this->redis->hget($commandKey, 'peak_memory'), $peakMemoryUsage)
            );
            $this->redis->hset($commandKey, 'last_update', now()->toDateTimeString());
        }
    }
}
