<?php

namespace Soroux\JobMonitor\Listeners;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Soroux\JobMonitor\Concerns\TrackableJob;

class JobProcessingListener
{

    protected $redis;

    public function __construct()
    {
        $this->redis = Redis::connection(config('job-monitor.monitor-connection'));
    }

    public function handle(JobProcessing $event): void
    {
        // Early return if queue is not monitored
        if (!in_array($event->job->getQueue(), config('job-monitor.queues', []))) {
            return;
        }

        $payload = $event->job->payload();
        $jobId = $payload['uuid'] ?? null;

        if (!$jobId) {
            Log::warning("[JobMonitor] Job processing without UUID");
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

        $processId = $job->commandProcessId ?? null;
        $commandName = $job->commandName ?? null;

        if (!$processId) {
            Log::warning("[JobMonitor] Job {$jobId} processing without process ID");
            return;
        }

        $key = "command:{$processId}:jobs";

        try {
            // Mark job as started for timing calculations
            $job->markJobStarted();

            $jobData = [
                'status' => 'processing',
                'started_at' => microtime(true),
                'job_type' => $job->jobType ?? null,
                'process_id' => $processId,
                'command_name' => $commandName,
                'queue_time' => $job->getQueueTime(),
                'attempts' => $event->job->attempts(),
                'queue' => $event->job->getQueue(),
                'job_class' => get_class($job)
            ];

            $this->redis->hset($key, $jobId, json_encode($jobData));
            $this->redis->expire($key, config('job-monitor.tracking_ttl', 86400));

            Log::info("[JobMonitor] Job {$jobId} started processing with process ID {$processId}");
        } catch (\Exception $e) {
            Log::error("[JobMonitor] Failed updating job {$jobId}. Error: {$e->getMessage()}");
        }
    }
}
