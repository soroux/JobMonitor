<?php

namespace Soroux\JobMonitor\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;

class JobMonitorController extends Controller
{
    protected $redis;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis::connection();
    }

    public function stats(): JsonResponse
    {
        $queues = config('job-monitor.queues', ['default']);
        $stats = [];

        foreach ($queues as $queue) {
            $stats[$queue] = [
                'pending' => $this->redis->llen("queues:{$queue}"),
                'processing' => $this->redis->zcard("queues:{$queue}:reserved"),
                'delayed' => $this->redis->zcard("queues:{$queue}:delayed"),
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
            $running = $this->redis->hgetall('commands:running');
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
            $running = $this->redis->hgetall('commands:finished');
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

        if (!$this->redis->exists($key)) {
            return response()->json(['status' => 'error', 'message' => 'Command process not found or data has expired.'], 404);
        }

        $jobs = $this->redis->hgetall($key);
        $failedJobDetails = DB::table('failed_jobs')->whereIn('uuid', array_keys($jobs))->get()->keyBy('uuid');

        $results = collect($jobs)->map(function ($status, $id) use ($failedJobDetails) {
            return [
                'id' => $id,
                'status' => $status,
                'failed_details' => $failedJobDetails->get($id),
            ];
        })->values();

        return response()->json(['status' => 'success', 'data' => $results]);
    }

    public function retryFailedCommandJobs(string $processId): JsonResponse
    {
        $key = "command:{$processId}:jobs";
        $jobs = $this->redis->hgetall($key);

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
            $this->redis->hset($key, $id, json_encode($jobData));
        }

        return response()->json(['status' => 'success', 'message' => 'Retry command dispatched for all failed jobs.']);
    }
}
