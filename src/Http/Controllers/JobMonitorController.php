<?php

namespace Soroux\JobMonitor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controller;
use Soroux\JobMonitor\Models\CommandMetric;
use Soroux\JobMonitor\Models\JobMetric;
use Soroux\JobMonitor\Service\PerformanceAnalyzer;
use Exception;
use Carbon\Carbon;

class JobMonitorController extends Controller
{
    private PerformanceAnalyzer $performanceAnalyzer;
    private Connection $monitorRedis;

    public function __construct(PerformanceAnalyzer $performanceAnalyzer)
    {
        $this->performanceAnalyzer = $performanceAnalyzer;
        $this->monitorRedis = Redis::connection(config('job-monitor.redis.connection', 'default'));

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

    /**
     * Get dashboard overview
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'days' => 'integer|min:1|max:30',
                'command' => 'string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $days = $request->get('days', 7);
            $command = $request->get('command');
            $startDate = now()->subDays($days);

            // Build query
            $query = CommandMetric::byDateRange($startDate, now());
            if ($command) {
                $query->byCommand($command);
            }

            $metrics = $query->orderBy('run_date', 'desc')->get();

            // Calculate summary statistics
            $summary = $this->calculateSummary($metrics);

            // Get recent activity
            $recentActivity = $this->getRecentActivity($startDate);

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => $summary,
                    'recent_activity' => $recentActivity,
                    'metrics' => $metrics,
                    'period' => [
                        'start_date' => $startDate->toISOString(),
                        'end_date' => now()->toISOString(),
                        'days' => $days
                    ]
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Dashboard data retrieval failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dashboard data',
                'error' => app()->environment('production') ? 'Internal server error' : $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get command metrics
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function commandMetrics(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'command' => 'required|string|max:255',
                'days' => 'integer|min:1|max:90',
                'page' => 'integer|min:1',
                'per_page' => 'integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $command = $request->get('command');
            $days = $request->get('days', 30);
            $startDate = now()->subDays($days);

            $metrics = CommandMetric::byCommand($command)
                ->byDateRange($startDate, now())
                ->orderBy('run_date', 'desc')
                ->paginate($request->get('per_page', 15));

            // Get performance trends
            $trends = $this->calculateTrends($metrics->items());

            return response()->json([
                'success' => true,
                'data' => [
                    'command' => $command,
                    'metrics' => $metrics,
                    'trends' => $trends,
                    'period' => [
                        'start_date' => $startDate->toISOString(),
                        'end_date' => now()->toISOString(),
                        'days' => $days
                    ]
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Command metrics retrieval failed', [
                'command' => $request->get('command'),
                'exception' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve command metrics',
                'error' => app()->environment('production') ? 'Internal server error' : $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get job metrics
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function jobMetrics(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'process_id' => 'string|max:255',
                'command' => 'string|max:255',
                'status' => 'in:success,failed',
                'days' => 'integer|min:1|max:90',
                'page' => 'integer|min:1',
                'per_page' => 'integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $days = $request->get('days', 30);
            $startDate = now()->subDays($days);

            $query = JobMetric::byDateRange($startDate, now());

            if ($request->has('process_id')) {
                $query->byProcessId($request->get('process_id'));
            }

            if ($request->has('command')) {
                $query->byCommand($request->get('command'));
            }

            if ($request->has('status')) {
                $query->byStatus($request->get('status'));
            }

            $metrics = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            // Get job statistics
            $statistics = $this->calculateJobStatistics($startDate);

            return response()->json([
                'success' => true,
                'data' => [
                    'metrics' => $metrics,
                    'statistics' => $statistics,
                    'filters' => $request->only(['process_id', 'command', 'status']),
                    'period' => [
                        'start_date' => $startDate->toISOString(),
                        'end_date' => now()->toISOString(),
                        'days' => $days
                    ]
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Job metrics retrieval failed', [
                'filters' => $request->all(),
                'exception' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve job metrics',
                'error' => app()->environment('production') ? 'Internal server error' : $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get performance analysis
     *
     * @return JsonResponse
     */
    public function performanceAnalysis(): JsonResponse
    {
        try {
            $analysis = $this->performanceAnalyzer->analyze();

            return response()->json([
                'success' => true,
                'data' => $analysis
            ]);

        } catch (Exception $e) {
            Log::error('Performance analysis failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => request()->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to perform analysis',
                'error' => app()->environment('production') ? 'Internal server error' : $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get system health status
     *
     * @return JsonResponse
     */
    public function health(): JsonResponse
    {
        try {
            $health = [
                'status' => 'healthy',
                'timestamp' => now()->toISOString(),
                'checks' => []
            ];

            // Check Redis connection
            try {
                $redis = Redis::connection(config('job-monitor.redis.connection', 'default'));
                $redis->ping();
                $health['checks']['redis'] = ['status' => 'healthy', 'response_time' => microtime(true)];
            } catch (Exception $e) {
                $health['checks']['redis'] = ['status' => 'unhealthy', 'error' => $e->getMessage()];
                $health['status'] = 'unhealthy';
            }

            // Check database connection
            try {
                DB::connection()->getPdo();
                $health['checks']['database'] = ['status' => 'healthy'];
            } catch (Exception $e) {
                $health['checks']['database'] = ['status' => 'unhealthy', 'error' => $e->getMessage()];
                $health['status'] = 'unhealthy';
            }

            // Check metrics freshness
            $latestMetric = CommandMetric::orderBy('created_at', 'desc')->first();
            if ($latestMetric) {
                $ageHours = now()->diffInHours($latestMetric->created_at);
                $maxAge = config('job-monitor.health_check.max_metrics_age_hours', 24);

                if ($ageHours > $maxAge) {
                    $health['checks']['metrics_freshness'] = [
                        'status' => 'warning',
                        'message' => "Latest metric is {$ageHours} hours old"
                    ];
                } else {
                    $health['checks']['metrics_freshness'] = ['status' => 'healthy'];
                }
            } else {
                $health['checks']['metrics_freshness'] = ['status' => 'warning', 'message' => 'No metrics found'];
            }

            $statusCode = $health['status'] === 'healthy' ? 200 : 503;

            return response()->json([
                'success' => true,
                'data' => $health
            ], $statusCode);

        } catch (Exception $e) {
            Log::error('Health check failed', [
                'exception' => $e->getMessage(),
                'request' => request()->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Health check failed',
                'error' => app()->environment('production') ? 'Internal server error' : $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate summary statistics
     *
     * @param \Illuminate\Database\Eloquent\Collection $metrics
     * @return array
     */
    private function calculateSummary($metrics): array
    {
        if ($metrics->isEmpty()) {
            return [
                'total_commands' => 0,
                'total_jobs' => 0,
                'success_rate' => 0,
                'avg_execution_time' => 0,
                'total_memory_usage' => 0
            ];
        }

        $totalCommands = $metrics->count();
        $totalJobs = $metrics->sum('job_count');
        $successJobs = $metrics->sum('success_jobs');
        $failedJobs = $metrics->sum('failed_jobs');
        $totalTime = $metrics->sum('total_time');
        $totalMemory = $metrics->sum('peak_memory');

        return [
            'total_commands' => $totalCommands,
            'total_jobs' => $totalJobs,
            'success_jobs' => $successJobs,
            'failed_jobs' => $failedJobs,
            'success_rate' => $totalJobs > 0 ? round(($successJobs / $totalJobs) * 100, 2) : 0,
            'failure_rate' => $totalJobs > 0 ? round(($failedJobs / $totalJobs) * 100, 2) : 0,
            'avg_execution_time' => $totalCommands > 0 ? round($totalTime / $totalCommands, 2) : 0,
            'total_memory_usage' => $totalMemory,
            'avg_memory_per_command' => $totalCommands > 0 ? round($totalMemory / $totalCommands, 2) : 0
        ];
    }

    /**
     * Get recent activity
     *
     * @param Carbon $startDate
     * @return array
     */
    private function getRecentActivity(Carbon $startDate): array
    {
        $recentCommands = CommandMetric::byDateRange($startDate, now())
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $recentJobs = JobMetric::byDateRange($startDate, now())
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return [
            'recent_commands' => $recentCommands,
            'recent_jobs' => $recentJobs
        ];
    }

    /**
     * Calculate performance trends
     *
     * @param array $metrics
     * @return array
     */
    private function calculateTrends(array $metrics): array
    {
        if (count($metrics) < 2) {
            return ['trend' => 'insufficient_data'];
        }

        $first = $metrics[count($metrics) - 1];
        $last = $metrics[0];

        $timeChange = $last->total_time - $first->total_time;
        $timeChangePercent = $first->total_time > 0 ? ($timeChange / $first->total_time) * 100 : 0;

        $jobCountChange = $last->job_count - $first->job_count;
        $jobCountChangePercent = $first->job_count > 0 ? ($jobCountChange / $first->job_count) * 100 : 0;

        return [
            'execution_time' => [
                'change' => round($timeChange, 2),
                'change_percent' => round($timeChangePercent, 2),
                'trend' => $timeChange > 0 ? 'increasing' : ($timeChange < 0 ? 'decreasing' : 'stable')
            ],
            'job_count' => [
                'change' => $jobCountChange,
                'change_percent' => round($jobCountChangePercent, 2),
                'trend' => $jobCountChange > 0 ? 'increasing' : ($jobCountChange < 0 ? 'decreasing' : 'stable')
            ]
        ];
    }

    /**
     * Calculate job statistics
     *
     * @param Carbon $startDate
     * @return array
     */
    private function calculateJobStatistics(Carbon $startDate): array
    {
        $totalJobs = JobMetric::byDateRange($startDate, now())->count();
        $successJobs = JobMetric::byDateRange($startDate, now())->byStatus('success')->count();
        $failedJobs = JobMetric::byDateRange($startDate, now())->byStatus('failed')->count();

        $avgExecutionTime = JobMetric::byDateRange($startDate, now())->avg('execution_time');
        $avgQueueTime = JobMetric::byDateRange($startDate, now())->avg('queue_time');
        $avgMemoryUsage = JobMetric::byDateRange($startDate, now())->avg('memory_usage');

        return [
            'total_jobs' => $totalJobs,
            'success_jobs' => $successJobs,
            'failed_jobs' => $failedJobs,
            'success_rate' => $totalJobs > 0 ? round(($successJobs / $totalJobs) * 100, 2) : 0,
            'failure_rate' => $totalJobs > 0 ? round(($failedJobs / $totalJobs) * 100, 2) : 0,
            'avg_execution_time' => round($avgExecutionTime ?? 0, 2),
            'avg_queue_time' => round($avgQueueTime ?? 0, 2),
            'avg_memory_usage' => round($avgMemoryUsage ?? 0, 2)
        ];
    }
}
