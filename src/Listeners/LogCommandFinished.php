<?php

namespace Soroux\JobMonitor\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Redis;

class LogCommandFinished
{
    protected $redis;

    public function __construct()
    {
        $this->redis = Redis::connection();
    }

    public function handle(CommandFinished $event): void
    {
        if (in_array($event->command, config('job-monitor.ignore_commands', []))) {
            return;
        }

        $processId = $this->redis->get("command-pid-map:{$event->command}");

        if ($processId) {
            try {
                // Store command completion metadata
                $this->redis->hset('commands:finished', $processId, json_encode([
                    'id' => $processId,
                    'command' => $event->command,
                    'finished_at' => now()->toDateTimeString(),
                    'exit_code' => $event->exitCode,
                ]));

                // Remove the command from running commands
                $this->redis->hdel('commands:running', $processId);
                $this->redis->del("command-pid-map:{$event->command}");

                \Log::info("Command finished: {$event->command} with Process ID: {$processId} and Exit Code: {$event->exitCode}");
            } catch (\Exception $e) {
                \Log::error("Failed to log command completion for {$event->command}. Error: {$e->getMessage()}");
            }
        }
    }
}
