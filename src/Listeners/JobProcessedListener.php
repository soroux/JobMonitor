<?php

namespace Soroux\JobMonitor\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Soroux\JobMonitor\Concerns\TrackableJob;

class JobProcessedListener
{
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

        if (!$processId) {
            Log::warning("[JobMonitor] Job {$jobId} completed without process ID");
            return;
        }

        $key = "command:{$processId}:jobs";

        try {
            $redis = Redis::connection(config('job-monitor.monitor-connection'));

            // Calculate execution time with fallback
            $startedAt = $job->jobStartedAt ?? microtime(true);
            $duration = microtime(true) - $startedAt;
            $queueTime = $job->getQueueTime() ?? 0;
            $totalTime = $duration + $queueTime;

            $jobData = [
                'status'         => 'completed',
                'completed_at'   => now()->toDateTimeString(),
                'execution_time' => round($duration, 4),
                'total_time'     => round($totalTime, 4),
                'queue_time'     => round($queueTime, 4),
                'job_type'       => $job->jobType ?? null,
                'queue'          => $event->job->getQueue(),
                'job_class'      => get_class($job),
                'attempts'       => $event->job->attempts()
            ];

            $redis->hset($key, $jobId, json_encode($jobData));
            $redis->expire($key, config('job-monitor.completed_ttl', 3600));

            Log::info("[JobMonitor] Job {$jobId} completed successfully with process ID {$processId}");
        } catch (\Exception $e) {
            Log::error("[JobMonitor] Failed to mark job {$jobId} as completed. Error: {$e->getMessage()}");
        }
    }
}
