<?php

namespace Soroux\JobMonitor\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Soroux\JobMonitor\Concerns\TrackableJob;

class JobFailedListener
{
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

        if (!$processId) {
            Log::warning("[JobMonitor] Job {$jobId} failed without process ID");
            return;
        }

        $key = "command:{$processId}:jobs";

        try {
            $redis = Redis::connection(config('job-monitor.monitor-connection'));

            // Calculate execution time if job was started
            $executionTime = null;
            if ($job->jobStartedAt) {
                $executionTime = microtime(true) - $job->jobStartedAt;
            }

            $jobData = [
                'status'      => 'failed',
                'failed_at'   => now()->toDateTimeString(),
                'error'       => $event->exception->getMessage(),
                'stack_trace' =>array_map(
                    function ($frame) {
                        return [
                            'file' => $frame['file'] ?? '[internal]',
                            'line' => $frame['line'] ?? 0,
                            'call' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function']
                        ];
                    },
                    array_slice($event->exception->getTrace(), 0, config('job-monitor.exceptions.frame_count', 1))
                ),
                'attempts'    => $event->job->attempts(),
                'job_type'    => $job->jobType ?? null,
                'queue'       => $event->job->getQueue(),
                'job_class'   => get_class($job),
                'execution_time' => $executionTime ? round($executionTime, 4) : null,
                'retryable'   => method_exists($event->exception, 'report'),
                'exception_class' => get_class($event->exception)
            ];

            $redis->hset($key, $jobId, json_encode($jobData));
            $redis->expire($key, config('job-monitor.failed_ttl', 172800));

            $this->handleFailure($event, $jobData);

            Log::error("[JobMonitor] Job {$jobId} failed with process ID {$processId}: {$event->exception->getMessage()}");
        } catch (\Exception $e) {
            Log::error("[JobMonitor] Failed recording failure for job {$jobId}. Error: {$e->getMessage()}");
        }
    }

    protected function handleFailure(JobFailed $event, array $jobData): void
    {
        // Optional: send notification, update DB, etc.
        // You can implement notifications, database logging, or other failure handling here
    }
}
