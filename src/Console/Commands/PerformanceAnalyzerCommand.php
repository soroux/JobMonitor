<?php

namespace Soroux\JobMonitor\Console\Commands;

use Illuminate\Console\Command;
use Soroux\JobMonitor\Service\PerformanceAnalyzer;
use Illuminate\Support\Facades\Log;

class PerformanceAnalyzerCommand extends Command
{
    protected $signature = 'job-monitor:analyze {--command= : Analyze specific command} {--all : Analyze all commands}';
    protected $description = 'Analyze job and command performance for anomalies';

    protected $analyzer;

    public function __construct(PerformanceAnalyzer $analyzer)
    {
        parent::__construct();
        $this->analyzer = $analyzer;
    }

    public function handle()
    {
        $this->info('Starting performance analysis...');

        try {
            if ($this->option('command')) {
                $commandName = $this->option('command');
                $this->info("Analyzing specific command: {$commandName}");
                $this->analyzer->analyzeCommand($commandName);
            } else {
                $this->info('Analyzing all commands...');
                $this->analyzer->analyzeAllCommands();
            }

            $this->info('Performance analysis completed successfully.');
            
        } catch (\Exception $e) {
            $this->error('Error during performance analysis: ' . $e->getMessage());
            Log::error('Performance analysis failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return 1;
        }

        return 0;
    }
}
