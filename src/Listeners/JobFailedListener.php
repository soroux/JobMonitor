<?php

namespace Soroux\JobMonitor\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Soroux\JobMonitor\Concerns\TrackableJob;

class JobFailedListener
{
    protected $redis;

    public function __construct()
    {
        $this->redis = Redis::connection(config('job-monitor.redis.connection', 'default'));
    }

    public function handle(JobFailed $event): void
    {
        // Early return if queue is not monitored
        if (!in_array($event->job->getQueue(), config('job-monitor.queues', []))) {
            return;
        }

        $payload = $event->job->payload();
        $jobId = $payload['uuid'] ?? null;

        if (!$jobId) {
            Log::warning("[JobMonitor] Job failed without UUID");
            return;
        }

        try {
            /** @var object $job */
            $job = unserialize($payload['data']['command']);
        } catch (\Throwable $e) {
            Log::error("[JobMonitor] Failed to unserialize job {$jobId}: " . $e->getMessage());
            return;
        }

        // Only handle jobs using TrackableJob trait
        if (!in_array(TrackableJob::class, class_uses_recursive($job))) {
            return;
        }

        $processId = $job->commandProcessId ?? null;
        $commandName = $job->commandName ?? null;

        if (!$processId) {
            Log::warning("[JobMonitor] Job {$jobId} failed without process ID");
            return;
        }

        $key = "command:{$processId}:jobs";

        try {
            $oldData = $this->redis->hget($key, $jobId);
            $oldData = json_decode($oldData, true);

            $startedAt = $oldData['started_at'] ?? microtime(true);
            $duration = microtime(true) - $startedAt;
            $queueTime = $oldData['queue_time'] ?? 0;

            $totalTime = $duration + $queueTime;

            $jobData = [
                'status' => 'failed',
                'failed_at' => now()->toDateTimeString(),
                'error' => $event->exception->getMessage(),
                'stack_trace' => array_map(
                    function ($frame) {
                        return [
                            'file' => $frame['file'] ?? '[internal]',
                            'line' => $frame['line'] ?? 0,
                            'call' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function']
                        ];
                    },
                    array_slice($event->exception->getTrace(), 0, config('job-monitor.exceptions.frame_count', 1))
                ),
                'process_id' => $processId,
                'command_name' => $commandName,
                'job_type' => $job->jobType ?? null,
                'queue' => $event->job->getQueue(),
                'job_class' => get_class($job),
                'execution_time' => $duration ? round($duration, 4) : null,
                'total_time' => round($totalTime, 4),
                'queue_time' => round($queueTime, 4),
                'exception_class' => get_class($event->exception)
            ];

            $this->redis->hset($key, $jobId, json_encode($jobData));
            $this->redis->expire($key, config('job-monitor.failed_ttl', 172800));

            $this->handleFailure($event, $jobData, $jobId);

            Log::error("[JobMonitor] Job {$jobId} failed with process ID {$processId}: {$event->exception->getMessage()}");
        } catch (\Exception $e) {
            Log::error("[JobMonitor] Failed recording failure for job {$jobId}. Error: {$e->getMessage()}");
        }
    }

    protected function handleFailure(JobFailed $event, array $jobData, $jobId): void
    {
        if (config('job-monitor.analyze_mode.enabled')) {
            $this->analyzeJob($event, $jobData, $jobId);
        }
    }

    public function analyzeJob(JobFailed $event, array $jobData, $jobId): void
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
                'status' => 'failed',
                'timestamp' => now()->toDateTimeString()
            ]);
        }
        if ($jobData['command_name'] !== 'manual-dispatch') {

            // Update command metrics
            $commandKey = "command:metrics:{$jobData['command_name']}:{$jobData['process_id']}";
            $this->redis->hincrby($commandKey, 'failed_jobs', 1);
            $this->redis->hincrbyfloat($commandKey, 'total_job_time', $jobData['execution_time']);
            $this->redis->hset($commandKey, 'peak_memory',
                max($this->redis->hget($commandKey, 'peak_memory'), $peakMemoryUsage)
            );
            $this->redis->hset($commandKey, 'last_update', now()->toDateTimeString());
        }
    }
}
