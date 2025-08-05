<?php

namespace Soroux\JobMonitor\Listeners;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class LogCommandStarting
{
    protected $redis;

    public function __construct()
    {
        $this->redis = Redis::connection(config('job-monitor.monitor-connection'));
    }

    public function handle(CommandStarting $event): void
    {
        if (in_array($event->command, config('job-monitor.ignore_commands', []))) {
            return;
        }

        $key = 'commands:running';
        // Use a unique ID for each command instance
        $processId = Str::uuid()->toString();

        $payload = [
            'id' => $processId,
            'command' => $event->command,
            'source'=> app()->runningInConsole() ? 'console' : 'api',
            'started_at' => now()->toDateTimeString(),
            'environment' => app()->environment(),
            'arguments' => $event->input->getArguments(),
            'options' => $event->input->getOptions(),
        ];

        try {
            $this->redis->hset($key, $processId, json_encode($payload));


            // Also store with command name for backward compatibility (but with shorter TTL)
            $this->redis->setex("command-pid-map:{$event->command}", 300, $processId);
            if (config('job-monitor.analyze_mode.enabled')) {
                $this->analyzeCommand($event,$payload);
            }


            \Log::info("Command started: {$event->command} with Process ID: {$processId}");
        } catch (\Exception $e) {
            \Log::error("Failed to log command start for {$event->command}: {$e->getMessage()}");
        }
    }

    public function analyzeCommand(CommandStarting $event,array $payload): void
    {
        $commandKey = "command:metrics:{$event->command}:{$payload['id']}";
        $this->redis->hmset($commandKey, [
            'start_time' => $payload['started_at'],
            'process_id' => $payload['id'],
            'command_name' => $event->command,
            'job_count' => 0,
            'success_jobs' => 0,
            'failed_jobs' => 0,
            'total_job_time' => 0,
            'source'=> $payload['source'],
            'peak_memory' => memory_get_usage(),
            'last_update' => now()->toDateTimeString(),
        ]);
        // Expire after 48 hours to prevent memory leak
        $this->redis->expire($commandKey, 172800);
    }
}
