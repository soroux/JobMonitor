<?php

namespace Soroux\JobMonitor\Listeners;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Soroux\JobMonitor\Concerns\TrackableJob;

class JobQueuedListener
{
    public function handle(JobQueued $event): void
    {
        // Early return if queue is not monitored
        if (!in_array($event->queue ?? 'default', config('job-monitor.queues', []))) {
            return;
        }

        $payload = $event->payload();
        $jobId = $payload['uuid'] ?? null;

        if (!$jobId) {
            Log::warning("[JobMonitor] Job queued without UUID");
            return;
        }

        try {
            /** @var object $job */
            $job = unserialize($payload['data']['command']);
        } catch (\Throwable $e) {
            Log::error("[JobMonitor] Failed to unserialize job {$jobId}: " . $e->getMessage());
            return;
        }

        // Only track jobs using the TrackableJob trait
        if (!in_array(TrackableJob::class, class_uses_recursive($job))) {
            return;
        }

        // Ensure we have a process ID (auto-generates if null)
        $processId = $job->generateProcessId();
        
        if (!$processId) {
            Log::error("[JobMonitor] Failed to generate process ID for job {$jobId}");
            return;
        }

        $key = "command:{$processId}:jobs";
        
        try {
            $redis = Redis::connection();
            
            $jobData = [
                'status' => 'pending',
                'created_at' => now()->toDateTimeString(),
                'job_type' => $job->jobType ?? null,
                'process_id' => $processId,
                'queue' => $event->queue ?? 'default',
                'job_class' => get_class($job)
            ];

            $redis->hset($key, $jobId, json_encode($jobData));
            $redis->expire($key, config('job-monitor.tracking_ttl', 86400));
            
            Log::info("[JobMonitor] Job {$jobId} queued with process ID {$processId}");
        } catch (\Exception $e) {
            Log::error("[JobMonitor] Failed tracking job {$jobId}. Error: {$e->getMessage()}");
        }
    }
}
