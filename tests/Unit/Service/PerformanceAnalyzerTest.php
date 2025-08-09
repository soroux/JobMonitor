<?php

namespace Soroux\JobMonitor\Tests\Unit\Service;

use Soroux\JobMonitor\Service\PerformanceAnalyzer;
use Soroux\JobMonitor\Models\CommandMetric;
use Soroux\JobMonitor\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Carbon\Carbon;

class PerformanceAnalyzerTest extends TestCase
{
    protected PerformanceAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = app(PerformanceAnalyzer::class);
        Event::fake();
    }

    /** @test */
    public function it_can_analyze_all_commands()
    {
        // Create some test metrics with historical data
        $this->createCommandMetric([
            'command_name' => 'test:command1',
            'total_time' => 2.0,
            'run_date' => now()->subDays(1)->toDateString(),
        ]);
        $this->createCommandMetric([
            'command_name' => 'test:command1',
            'total_time' => 2.5,
            'run_date' => now()->toDateString(),
        ]);
        $this->createCommandMetric([
            'command_name' => 'test:command2',
            'total_time' => 3.0,
            'run_date' => now()->subDays(1)->toDateString(),
        ]);
        $this->createCommandMetric([
            'command_name' => 'test:command2',
            'total_time' => 3.5,
            'run_date' => now()->toDateString(),
        ]);

        $result = $this->analyzer->analyze();

        // Should return analysis results
        $this->assertIsArray($result);
        $this->assertArrayHasKey('commands_analyzed', $result);
        $this->assertArrayHasKey('anomalies_detected', $result);
    }

    /** @test */
    public function it_detects_performance_anomaly()
    {
        // Create historical data with average time of 2.0
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 2.0,
            'run_date' => now()->subDays(1)->toDateString(),
        ]);

        // Create current data with much higher time (3.5 > 2.0 * 1.5)
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 3.5,
            'run_date' => now()->toDateString(),
        ]);

        $this->analyzer->analyzeCommand('test:command');

        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class, function ($event) {
            return $event->commandName === 'test:command' && $event->anomalyType === 'performance_degradation';
        });
    }

    /** @test */
    public function it_does_not_detect_performance_anomaly_when_within_threshold()
    {
        // Create historical data with average time of 2.0
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 2.0,
            'run_date' => now()->subDays(1),
        ]);

        // Create current data within threshold (2.5 < 2.0 * 1.5)
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 2.5,
            'run_date' => now(),
        ]);

        $this->analyzer->analyzeCommand('test:command');

        Event::assertNotDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class);
    }

    /** @test */
    public function it_detects_failed_jobs_anomaly()
    {
        // Create historical data with average failed jobs of 1
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'failed_jobs' => 1,
            'run_date' => now()->subDays(1)->toDateString(),
        ]);
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'failed_jobs' => 1,
            'run_date' => now()->subDays(2)->toDateString(),
        ]);

        // Create current data with much higher failed jobs (3 > 1 * 2.0)
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'failed_jobs' => 3,
            'run_date' => now()->toDateString(),
        ]);

        $this->analyzer->analyzeCommand('test:command');

        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class, function ($event) {
            return $event->commandName === 'test:command' && $event->anomalyType === 'high_failure_rate';
        });
    }

    /** @test */
    public function it_detects_low_failure_rate_anomaly_when_zero_failures()
    {
        // Create historical data with average failed jobs of 1
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'failed_jobs' => 1,
            'run_date' => now()->subDays(1)->toDateString(),
        ]);
        
        // Create current data with zero failed jobs
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'failed_jobs' => 0,
            'run_date' => now(),
        ]);

        $this->analyzer->analyzeCommand('test:command');

        // Should detect low failure rate anomaly (0 < 1 * 0.1)
        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class, function ($event) {
            return $event->commandName === 'test:command' && $event->anomalyType === 'low_failure_rate';
        });
    }

    /** @test */
    public function it_detects_high_job_count_anomaly()
    {
        // Create historical data with average job count of 10
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'job_count' => 10,
            'run_date' => now()->subDays(1)->toDateString(),
        ]);

        // Create current data with much higher job count (20 > 10 * 1.5)
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'job_count' => 20,
            'run_date' => now()->toDateString(),
        ]);

        $this->analyzer->analyzeCommand('test:command');

        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class, function ($event) {
            return $event->commandName === 'test:command' && $event->anomalyType === 'unusual_workload_high';
        });
    }

    /** @test */
    public function it_detects_low_job_count_anomaly()
    {
        // Create historical data with average job count of 10
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'job_count' => 10,
            'run_date' => now()->subDays(1),
        ]);

        // Create current data with much lower job count (4 < 10 * 0.5)
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'job_count' => 4,
            'run_date' => now(),
        ]);

        $this->analyzer->analyzeCommand('test:command');

        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class, function ($event) {
            return $event->commandName === 'test:command' && $event->anomalyType === 'unusual_workload_low';
        });
    }

    /** @test */
    public function it_handles_commands_with_no_historical_data()
    {
        // Create only current data without historical data
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 5.0,
            'run_date' => now(),
        ]);

        $result = $this->analyzer->analyzeCommand('test:command');

        // Should return insufficient data response
        $this->assertFalse($result['has_anomalies']);
        $this->assertEquals('Insufficient data for analysis (need at least 2 data points)', $result['reason']);
        $this->assertEquals(1, $result['data_points']);
        
        // Should not throw exceptions and should not detect anomalies
        Event::assertNotDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class);
    }

    /** @test */
    public function it_handles_commands_with_no_current_data()
    {
        // Create only historical data without current data
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 2.0,
            'run_date' => now()->subDays(1),
        ]);

        $result = $this->analyzer->analyzeCommand('test:command');

        // Should return insufficient data response (need both historical and current)
        $this->assertFalse($result['has_anomalies']);
        $this->assertEquals('Insufficient data for analysis (need at least 2 data points)', $result['reason']);
        $this->assertEquals(1, $result['data_points']);
        
        // Should not throw exceptions
        Event::assertNotDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class);
    }

    /** @test */
    public function it_handles_zero_historical_average()
    {
        // Create historical data with zero values
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 0,
            'run_date' => now()->subDays(1),
        ]);

        // Create current data
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 5.0,
            'run_date' => now(),
        ]);

        $result = $this->analyzer->analyzeCommand('test:command');

        // With zero baseline, any non-zero current value should trigger an anomaly
        $this->assertTrue($result['has_anomalies']);
        $this->assertCount(1, $result['anomalies']);
        $this->assertEquals('performance_degradation', $result['anomalies'][0]['type']);
        
        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class);
    }

    /** @test */
    public function it_handles_edge_case_values_in_metrics()
    {
        // Create historical data with normal values
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 2.0,
            'failed_jobs' => 1,
            'job_count' => 5,
            'run_date' => now()->subDays(1)->toDateString(),
        ]);
        
        // Create metrics with edge case values (very low values that might trigger anomalies)
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 0.5, // Very low time (0.5 < 2.0 * 0.5)
            'failed_jobs' => 0.05, // Very low failed jobs (0.05 < 1 * 0.1)
            'job_count' => 2, // Low job count (2 < 5 * 0.5)
            'run_date' => now(),
        ]);

        $result = $this->analyzer->analyzeCommand('test:command');

        // Should detect multiple anomalies for improvements
        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class, 3);
        
        // Verify the anomalies are for improvements
        $this->assertTrue($result['has_anomalies']);
        $this->assertCount(3, $result['anomalies']);
        
        $anomalyTypes = array_column($result['anomalies'], 'type');
        $this->assertContains('performance_improvement', $anomalyTypes);
        $this->assertContains('low_failure_rate', $anomalyTypes);
        $this->assertContains('unusual_workload_low', $anomalyTypes);
    }

    /** @test */
    public function it_handles_multiple_anomalies_for_same_command()
    {
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
        $this->analyzer->analyzeCommand('test:command');

        // Should detect multiple anomalies
        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class, 3);
    }

    /** @test */
    public function it_handles_edge_case_threshold_values()
    {
        // Test exactly at threshold
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 2.0,
            'run_date' => now()->subDays(1),
        ]);

        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 3.0, // Exactly 2.0 * 1.5
            'run_date' => now(),
        ]);

        $this->analyzer->analyzeCommand('test:command');

        // Should not detect anomaly when exactly at threshold
        Event::assertNotDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class);
    }

    /** @test */
    public function it_handles_very_large_numbers()
    {
        // Test with very large numbers
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 1000000.0,
            'run_date' => now()->subDays(1),
        ]);

        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 2000000.0,
            'run_date' => now(),
        ]);

        $this->analyzer->analyzeCommand('test:command');

        // Should handle large numbers without issues
        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class);
    }

    /** @test */
    public function it_handles_very_small_numbers()
    {
        // Test with very small numbers
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 0.0001,
            'run_date' => now()->subDays(1),
        ]);

        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 0.0002,
            'run_date' => now(),
        ]);

        $this->analyzer->analyzeCommand('test:command');

        // Should handle small numbers without issues
        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class);
    }

    /** @test */
    public function it_handles_negative_numbers()
    {
        // Test with negative numbers (should be handled gracefully)
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => -1.0,
            'run_date' => now()->subDays(1),
        ]);

        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => -2.0,
            'run_date' => now(),
        ]);

        $result = $this->analyzer->analyzeCommand('test:command');

        // Should handle negative numbers gracefully
        // Note: With negative baseline (-1.0), current value (-2.0) may trigger anomaly
        // due to threshold calculations, but should not crash
        $this->assertIsArray($result);
        $this->assertArrayHasKey('has_anomalies', $result);
    }

    /** @test */
    public function it_handles_empty_command_name()
    {
        // Create historical data with normal command name
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 2.0,
            'run_date' => now()->subDays(1)->toDateString(),
        ]);
        
        // Create metric with empty command name
        $this->createCommandMetric([
            'command_name' => '',
            'total_time' => 2.0,
            'run_date' => now(),
        ]);

        $result = $this->analyzer->analyzeCommand('');

        // Should handle gracefully without throwing exceptions
        Event::assertNotDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class);
    }

    /** @test */
    public function it_handles_special_characters_in_command_name()
    {
        $commandName = 'test:command-with-special-chars!@#$%^&*()';
        
        // Create historical data
        $this->createCommandMetric([
            'command_name' => $commandName,
            'total_time' => 2.0,
            'run_date' => now()->subDays(1)->toDateString(),
        ]);

        // Create current data within threshold
        $this->createCommandMetric([
            'command_name' => $commandName,
            'total_time' => 2.5,
            'run_date' => now()->toDateString(),
        ]);

        $result = $this->analyzer->analyzeCommand($commandName);

        $this->assertFalse($result['has_anomalies']);
        $this->assertEquals($commandName, $result['latest_metric']->command_name);
    }

    /** @test */
    public function it_detects_both_performance_improvements_and_degradations()
    {
        // Create historical data with average time of 10.0
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 10.0,
            'run_date' => now()->subDays(1)->toDateString(),
        ]);
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 10.0,
            'run_date' => now()->subDays(2)->toDateString(),
        ]);

        // Test performance degradation (16.0 > 10.0 * 1.5)
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 16.0,
            'run_date' => now()->toDateString(),
        ]);

        $result = $this->analyzer->analyzeCommand('test:command');
        
        $this->assertTrue($result['has_anomalies']);
        $this->assertCount(1, $result['anomalies']);
        $this->assertEquals('performance_degradation', $result['anomalies'][0]['type']);
        $this->assertEquals('worse', $result['anomalies'][0]['direction']);
        $this->assertEquals(60.0, $result['anomalies'][0]['percentage_change']);

        // Now test performance improvement with a different command to avoid baseline skewing
        $this->createCommandMetric([
            'command_name' => 'test:command2',
            'total_time' => 10.0,
            'run_date' => now()->subDays(1)->toDateString(),
        ]);
        $this->createCommandMetric([
            'command_name' => 'test:command2',
            'total_time' => 10.0,
            'run_date' => now()->subDays(2)->toDateString(),
        ]);

        // Test performance improvement (4.0 < 10.0 * 0.5)
        $this->createCommandMetric([
            'command_name' => 'test:command2',
            'total_time' => 4.0,
            'run_date' => now()->toDateString(),
        ]);

        $result = $this->analyzer->analyzeCommand('test:command2');
        
        $this->assertTrue($result['has_anomalies']);
        $this->assertCount(1, $result['anomalies']);
        $this->assertEquals('performance_improvement', $result['anomalies'][0]['type']);
        $this->assertEquals('better', $result['anomalies'][0]['direction']);
        $this->assertEquals(-60.0, $result['anomalies'][0]['percentage_change']);
    }

    /** @test */
    public function it_detects_both_high_and_low_job_count_anomalies()
    {
        // Create historical data with average job count of 100
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'job_count' => 100,
            'run_date' => now()->subDays(1)->toDateString(),
        ]);
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'job_count' => 100,
            'run_date' => now()->subDays(2)->toDateString(),
        ]);

        // Test high job count (160 > 100 * 1.5)
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'job_count' => 160,
            'run_date' => now()->toDateString(),
        ]);

        $result = $this->analyzer->analyzeCommand('test:command');
        
        $this->assertTrue($result['has_anomalies']);
        $this->assertCount(1, $result['anomalies']);
        $this->assertEquals('unusual_workload_high', $result['anomalies'][0]['type']);
        $this->assertEquals('higher', $result['anomalies'][0]['direction']);

        // Test low job count (40 < 100 * 0.5)
        $this->createCommandMetric([
            'command_name' => 'test:command3',
            'job_count' => 100,
            'run_date' => now()->subDays(1)->toDateString(),
        ]);
        $this->createCommandMetric([
            'command_name' => 'test:command3',
            'job_count' => 100,
            'run_date' => now()->subDays(2)->toDateString(),
        ]);
        $this->createCommandMetric([
            'command_name' => 'test:command3',
            'job_count' => 40,
            'run_date' => now()->toDateString(),
        ]);

        $result = $this->analyzer->analyzeCommand('test:command3');
        
        $this->assertTrue($result['has_anomalies']);
        $this->assertCount(1, $result['anomalies']);
        $this->assertEquals('unusual_workload_low', $result['anomalies'][0]['type']);
        $this->assertEquals('lower', $result['anomalies'][0]['direction']);
    }

    /** @test */
    public function it_detects_both_high_and_low_failure_rate_anomalies()
    {
        // Create historical data with average failed jobs of 5
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'failed_jobs' => 5,
            'run_date' => now()->subDays(1)->toDateString(),
        ]);
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'failed_jobs' => 5,
            'run_date' => now()->subDays(2)->toDateString(),
        ]);

        // Test high failure rate (12 > 5 * 2.0)
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'failed_jobs' => 12,
            'run_date' => now()->toDateString(),
        ]);

        $result = $this->analyzer->analyzeCommand('test:command');
        
        $this->assertTrue($result['has_anomalies']);
        $this->assertCount(1, $result['anomalies']);
        $this->assertEquals('high_failure_rate', $result['anomalies'][0]['type']);
        $this->assertEquals('worse', $result['anomalies'][0]['direction']);

        // Test low failure rate (0.3 < 5 * 0.1)
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'failed_jobs' => 0.3,
            'run_date' => now()->toDateString(),
        ]);

        $result = $this->analyzer->analyzeCommand('test:command');
        
        $this->assertTrue($result['has_anomalies']);
        $this->assertCount(1, $result['anomalies']);
        $this->assertEquals('low_failure_rate', $result['anomalies'][0]['type']);
        $this->assertEquals('better', $result['anomalies'][0]['direction']);
    }

    /** @test */
    public function it_provides_analysis_summary()
    {
        // Create historical data
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 10.0,
            'job_count' => 100,
            'failed_jobs' => 5,
            'run_date' => now()->subDays(1)->toDateString(),
        ]);

        // Create current data with multiple anomalies
        $this->createCommandMetric([
            'command_name' => 'test:command',
            'total_time' => 20.0, // Performance degradation
            'job_count' => 40,     // Low workload
            'failed_jobs' => 0.3,  // Low failure rate
            'run_date' => now()->toDateString(),
        ]);

        $result = $this->analyzer->analyzeCommand('test:command');
        
        $this->assertTrue($result['has_anomalies']);
        $this->assertCount(3, $result['anomalies']);
        
        // Check that summary is included
        $this->assertArrayHasKey('summary', $result);
        $this->assertEquals(3, $result['summary']['total_anomalies']);
        $this->assertEquals(1, $result['summary']['performance_degradations']);
        $this->assertEquals(1, $result['summary']['workload_anomalies']);
        $this->assertEquals(1, $result['summary']['failure_rate_anomalies']);
    }

    /** @test */
    public function it_handles_multiple_commands_simultaneously()
    {
        // Create metrics for multiple commands
        $commands = ['command1', 'command2', 'command3'];

        foreach ($commands as $command) {
            $this->createCommandMetric([
                'command_name' => $command,
                'total_time' => 2.0,
                'run_date' => now()->subDays(1)->toDateString(),
            ]);

            $this->createCommandMetric([
                'command_name' => $command,
                'total_time' => 4.0,
                'run_date' => now()->toDateString(),
            ]);
        }

        // Analyze each command individually to avoid additional checks
        foreach ($commands as $command) {
            $this->analyzer->analyzeCommand($command);
        }

        // Should detect anomalies for all commands
        Event::assertDispatched(\Soroux\JobMonitor\Events\PerformanceAnomalyDetected::class, 3);
    }
}
