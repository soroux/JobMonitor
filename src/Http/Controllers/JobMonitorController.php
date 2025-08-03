<?php

namespace Soroux\JobMonitor\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;

class JobMonitorController extends Controller
{
    private Connection $monitorRedis;

    public function __construct(Redis $redis)
    {
        $this->monitorRedis = $redis::connection(config('job-monitor.monitor-connection'));
    }

    public function stats(): JsonResponse
    {
        $queues = config('job-monitor.queues', ['default']);
        $stats = [];

        // Get all keys with the specified pattern
        $keys = $this->monitorRedis->keys("command:*:jobs");
        $client = $this->monitorRedis->client();
        $prefix = $client->getOption(\Redis::OPT_PREFIX);
        $statusCounts = [];

        foreach ($keys as $key) {
            // Check if the key starts with the prefix and remove it
            if (strpos($key, $prefix) === 0) {
                $jobKey = substr($key, strlen($prefix)); // Remove the prefix
            } else {
                // If the prefix is not at the start, skip this key
                continue;
            }

            $jobs = $this->monitorRedis->hgetall($jobKey); // Use the jobKey without prefix

            foreach ($jobs as $jobData) {
                $data = json_decode($jobData, true);
                $queue = $data['queue'] ?? 'default';
                $status = $data['status'] ?? 'unknown';

                if (!in_array($queue, $queues)) {
                    continue;
                }

                if (!isset($statusCounts[$queue][$status])) {
                    $statusCounts[$queue][$status] = 0;
                }

                $statusCounts[$queue][$status]++;
            }
        }

        foreach ($queues as $queue) {
            $stats[$queue] = [
                'pending' => $statusCounts[$queue]['pending'] ?? 0,
                'processing' => $statusCounts[$queue]['processing'] ?? 0,
                'failed' => DB::table('failed_jobs')->where('queue', $queue)->count(),
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_failed' => DB::table('failed_jobs')->count(),
                'queues' => $stats,
            ]
        ]);
    }

    public function failedJobs(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);

        $failed = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json($failed);
    }

    public function retryFailedJob(string $id): JsonResponse
    {
        $exitCode = Artisan::call('queue:retry', ['id' => $id]);

        if ($exitCode === 0) {
            return response()->json(['status' => 'success', 'message' => "Job [{$id}] has been pushed back onto the queue."]);
        }

        return response()->json(['status' => 'error', 'message' => "Could not retry job [{$id}]. It may no longer exist."], 404);
    }

    public function deleteFailedJob(string $id): JsonResponse
    {
        $exitCode = Artisan::call('queue:forget', ['id' => $id]);

        if ($exitCode === 0) {
            return response()->json(['status' => 'success', 'message' => "Job [{$id}] has been deleted."]);
        }

        return response()->json(['status' => 'error', 'message' => "Could not delete job [{$id}]. It may no longer exist."], 404);
    }

    public function runningCommands(): JsonResponse
    {
        try {
            $running = $this->monitorRedis->hgetall('commands:running');
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Could not retrieve running commands.'], 500);
        }

        $commands = collect($running)->map(function ($command) {
            return json_decode($command, true);
        })->sortBy('started_at')->values();

        return response()->json([
            'status' => 'success',
            'data' => $commands,
        ]);
    }

    public function finishedCommands(): JsonResponse
    {
        try {
            $running = $this->monitorRedis->hgetall('commands:finished');
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => 'Could not retrieve running commands.'], 500);
        }

        $commands = collect($running)->map(function ($command) {
            return json_decode($command, true);
        })->sortBy('started_at')->values();

        return response()->json([
            'status' => 'success',
            'data' => $commands,
        ]);
    }

    public function getCommandJobs(string $processId): JsonResponse
    {
        $key = "command:{$processId}:jobs";

        if (!$this->monitorRedis->exists($key)) {
            return response()->json(['status' => 'error', 'message' => 'Command process not found or data has expired.'], 404);
        }

        $jobs = $this->monitorRedis->hgetall($key);

        $results = collect($jobs)->map(function ($status, $id) {
            return [
                'id' => $id,
                'status' => $status,
            ];
        })->values();

        return response()->json(['status' => 'success', 'data' => $results]);
    }

    public function retryFailedCommandJobs(string $processId): JsonResponse
    {
        $key = "command:{$processId}:jobs";
        $jobs = $this->monitorRedis->hgetall($key);

        $failedJobIds = collect($jobs)->filter(fn($status) => json_decode($status)->status === 'failed')->keys();

        if ($failedJobIds->isEmpty()) {
            return response()->json(['status' => 'success', 'message' => 'No failed jobs to retry for this command.']);
        }

        Artisan::call('queue:retry', ['id' => $failedJobIds->all()]);

        // Update status in Redis back to "pending"
        foreach ($failedJobIds as $id) {
            $decoded = json_decode($jobs[$id]);

            $jobData = [
                'status' => 'pending',
                'created_at' => now()->toDateTimeString(),
                'job_type' => $decoded->job_type ?? null,
                'process_id' => $processId,
                'queue' => $decoded->queue ?? 'default',
                'job_class' => $decoded->job_class ?? null,
            ];
            $this->monitorRedis->hset($key, $id, json_encode($jobData));
        }

        return response()->json(['status' => 'success', 'message' => 'Retry command dispatched for all failed jobs.']);
    }
}
