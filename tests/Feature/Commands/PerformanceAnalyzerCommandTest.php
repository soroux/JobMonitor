<?php

namespace Soroux\JobMonitor\Tests\Feature\Commands;

use Soroux\JobMonitor\Tests\TestCase;
use Soroux\JobMonitor\Models\CommandMetric;
use Illuminate\Support\Facades\Event;

class PerformanceAnalyzerCommandTest extends TestCase
{
    /** @test */
    public function it_can_analyze_all_commands()
    {
        // Create test metrics
        $this->createCommandMetric(['command_name' => 'test:command1']);
        $this->createCommandMetric(['command_name' => 'test:command1']);
        $this->createCommandMetric(['command_name' => 'test:command1']);
        $this->createCommandMetric(['command_name' => 'test:command2']);
        $this->createCommandMetric(['command_name' => 'test:command2']);
        $this->createCommandMetric(['command_name' => 'test:command2']);
        $this->artisan('job-monitor:analyze')
            ->expectsOutput('Starting performance analysis...')
            ->expectsOutput('Analyzing all commands...')
            ->expectsOutput('Performance analysis completed successfully.')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_can_analyze_specific_command()
    {
        // Create test metrics
        $this->createCommandMetric(['command_name' => 'test:command']);
        $this->createCommandMetric(['command_name' => 'test:command']);
        $this->createCommandMetric(['command_name' => 'test:command']);

        $this->artisan('job-monitor:analyze', ['--command' => 'test:command'])
            ->expectsOutput('Starting performance analysis...')
            ->expectsOutput('Analyzing specific command: test:command')
            ->expectsOutput('Performance analysis completed successfully.')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_detects_performance_anomaly()
    {
        Event::fake();

        // Create historical data
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 2.0,
            'run_date' => now()->subDays(1),
        ]);

        // Create current data with anomaly
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 5.0, // 2.5x historical average
            'run_date' => now(),
        ]);

        $this->artisan('job-monitor:analyze', ['--command' => 'test:command'])
            ->expectsOutput('Starting performance analysis...')
            ->expectsOutput('Analyzing specific command: test:command')
            ->expectsOutput('Performance analysis completed successfully.')
            ->assertExitCode(0);

        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class, function ($event) {
            return $event->commandName === 'test:command' && $event->anomalyType === 'performance_degradation';
        });
    }

    /** @test */
    public function it_detects_failed_jobs_anomaly()
    {
        Event::fake();

        // Create historical data
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'failed_jobs' => 1,
            'run_date' => now()->subDays(1),
        ]);

        // Create current data with anomaly
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'failed_jobs' => 3, // 3x historical average
            'run_date' => now(),
        ]);

        $this->artisan('job-monitor:analyze', ['--command' => 'test:command'])
            ->expectsOutput('Starting performance analysis...')
            ->expectsOutput('Analyzing specific command: test:command')
            ->expectsOutput('Performance analysis completed successfully.')
            ->assertExitCode(0);

        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class, function ($event) {
            return $event->commandName === 'test:command' && $event->anomalyType === 'high_failure_rate';
        });
    }

    /** @test */
    public function it_detects_high_job_count_anomaly()
    {
        Event::fake();

        // Create historical data
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'job_count' => 10,
            'run_date' => now()->subDays(1),
        ]);

        // Create current data with anomaly
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'job_count' => 20, // 2x historical average
            'run_date' => now(),
        ]);

        $this->artisan('job-monitor:analyze', ['--command' => 'test:command'])
            ->expectsOutput('Starting performance analysis...')
            ->expectsOutput('Analyzing specific command: test:command')
            ->expectsOutput('Performance analysis completed successfully.')
            ->assertExitCode(0);

        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class, function ($event) {
            return $event->commandName === 'test:command' && $event->anomalyType === 'unusual_workload_high';
        });
    }

    /** @test */
    public function it_detects_low_job_count_anomaly()
    {
        Event::fake();

        // Create historical data
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'job_count' => 10,
            'run_date' => now()->subDays(1),
        ]);

        // Create current data with anomaly
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'job_count' => 4, // Less than 50% of historical average
            'run_date' => now(),
        ]);

        $this->artisan('job-monitor:analyze', ['--command' => 'test:command'])
            ->expectsOutput('Starting performance analysis...')
            ->expectsOutput('Analyzing specific command: test:command')
            ->expectsOutput('Performance analysis completed successfully.')
            ->assertExitCode(0);

        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class, function ($event) {
            return $event->commandName === 'test:command' && $event->anomalyType === 'unusual_workload_low';
        });
    }

    /** @test */
    public function it_handles_commands_with_only_current_data()
    {
        // Create only current data without historical data
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 5.0,
            'run_date' => now(),
        ]);

        $this->artisan('job-monitor:analyze', ['--command' => 'test:command'])
            ->expectsOutput('Starting performance analysis...')
            ->expectsOutput('Analyzing specific command: test:command')
            ->expectsOutput('Performance analysis completed successfully.')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_handles_multiple_anomalies_for_same_command()
    {
        Event::fake();

        // Create historical data
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 2.0,
            'failed_jobs' => 1,
            'job_count' => 10,
            'run_date' => now()->subDays(1),
        ]);

        // Create current data with multiple anomalies
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 4.0, // Performance anomaly
            'failed_jobs' => 3,     // Failed jobs anomaly
            'job_count' => 20,      // High job count anomaly
            'run_date' => now(),
        ]);

        $this->artisan('job-monitor:analyze', ['--command' => 'test:command'])
            ->expectsOutput('Starting performance analysis...')
            ->expectsOutput('Analyzing specific command: test:command')
            ->expectsOutput('Performance analysis completed successfully.')
            ->assertExitCode(0);

        // Should detect multiple anomalies
        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class, 3);
    }

    /** @test */
    public function it_handles_analysis_disabled()
    {
        // Disable analysis
        config(['job-monitor.analyze_mode.enabled' => false]);

        $this->artisan('job-monitor:analyze')
            ->expectsOutput('Starting performance analysis...')
            ->expectsOutput('Analyzing all commands...')
            ->expectsOutput('Performance analysis completed successfully.')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_handles_analysis_with_verbose_output()
    {
        $this->createCommandMetric(['command_name' => 'test:command']);

        $this->artisan('job-monitor:analyze', ['--command' => 'test:command', '-v' => true])
            ->expectsOutput('Starting performance analysis...')
            ->expectsOutput('Analyzing specific command: test:command')
            ->expectsOutput('Performance analysis completed successfully.')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_handles_special_characters_in_command_name()
    {
        $commandName = 'test:command-with-special-chars!@#$%^&*()';

        $this->createCommandMetric(['command_name' => $commandName]);

        $this->artisan('job-monitor:analyze', ['--command' => $commandName])
            ->expectsOutput('Starting performance analysis...')
            ->expectsOutput("Analyzing specific command: {$commandName}")
            ->expectsOutput('Performance analysis completed successfully.')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_handles_unicode_characters_in_command_name()
    {
        $commandName = 'test:command-中文-unicode';

        $this->createCommandMetric(['command_name' => $commandName]);

        $this->artisan('job-monitor:analyze', ['--command' => $commandName])
            ->expectsOutput('Starting performance analysis...')
            ->expectsOutput("Analyzing specific command: {$commandName}")
            ->expectsOutput('Performance analysis completed successfully.')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_handles_very_long_command_names()
    {
        $commandName = str_repeat('a', 1000); // Very long command name

        $this->createCommandMetric(['command_name' => $commandName]);

        $this->artisan('job-monitor:analyze', ['--command' => $commandName])
            ->expectsOutput('Starting performance analysis...')
            ->expectsOutput("Analyzing specific command: {$commandName}")
            ->expectsOutput('Performance analysis completed successfully.')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_handles_analysis_with_custom_thresholds()
    {
        Event::fake();

        // Set custom thresholds
        config([
            'job-monitor.analyze_mode.performance_threshold' => 1.2, // Lower threshold
            'job-monitor.analyze_mode.failed_jobs_threshold' => 1.5, // Lower threshold
            'job-monitor.analyze_mode.job_count_threshold' => 1.2,   // Lower threshold
        ]);

        // Create historical data
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 2.0,
            'failed_jobs' => 1,
            'job_count' => 10,
            'run_date' => now()->subDays(1),
        ]);

        // Create current data that would trigger with lower thresholds
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 2.5, // 1.25x (would not trigger with default 1.5)
            'failed_jobs' => 2,     // 2x (would not trigger with default 2.0)
            'job_count' => 15,      // 1.5x (would not trigger with default 1.5)
            'run_date' => now(),
        ]);

        $this->artisan('job-monitor:analyze', ['--command' => 'test:command'])
            ->expectsOutput('Starting performance analysis...')
            ->expectsOutput('Analyzing specific command: test:command')
            ->expectsOutput('Performance analysis completed successfully.')
            ->assertExitCode(0);

        // Should detect anomalies with custom thresholds
        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class, 3);
    }

    /** @test */
    public function it_handles_analysis_with_null_values()
    {
        Event::fake();

        // Create metrics with null values
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => null,
            'failed_jobs' => null,
            'job_count' => null,
            'run_date' => now(),
        ]);

        $this->artisan('job-monitor:analyze', ['--command' => 'test:command'])
            ->expectsOutput('Starting performance analysis...')
            ->expectsOutput('Analyzing specific command: test:command')
            ->expectsOutput('Performance analysis completed successfully.')
            ->assertExitCode(0);

        // Should handle null values gracefully
        Event::assertNotDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class);
    }
}
