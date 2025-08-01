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
        $this->redis = Redis::connection();
    }

    public function handle(CommandStarting $event): void
    {
        if (in_array($event->command, config('job-monitor.ignore_commands', []))) {
            return;
        }

        $key = 'commands:running';
        // Use a unique ID for each command instance
        $processId = Str::uuid()->toString();
        
        // Create a unique key for this command instance
        $commandInstanceKey = "command-instance:{$event->command}:" . time() . ":" . Str::random(8);

        $payload = json_encode([
            'id' => $processId,
            'command' => $event->command,
            'started_at' => now()->toDateTimeString(),
            'environment' => app()->environment(),
            'arguments' => $event->input->getArguments(),
            'options' => $event->input->getOptions(),
            'instance_key' => $commandInstanceKey,
        ]);

        try {
            $this->redis->hset($key, $processId, $payload);
            
            // Store the process ID with the unique instance key
            $this->redis->setex($commandInstanceKey, 3600, $processId);
            
            // Also store with command name for backward compatibility (but with shorter TTL)
            $this->redis->setex("command-pid-map:{$event->command}", 300, $processId);

            \Log::info("Command started: {$event->command} with Process ID: {$processId}");
        } catch (\Exception $e) {
            \Log::error("Failed to log command start for {$event->command}: {$e->getMessage()}");
        }
    }
}
