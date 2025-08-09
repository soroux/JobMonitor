<?php

namespace Soroux\JobMonitor\Tests\Unit\Events;

use Soroux\JobMonitor\Events\PerformanceAnomalyDetected;
use Soroux\JobMonitor\Tests\TestCase;

class PerformanceAnomalyDetectedTest extends TestCase
{
    /** @test */
    public function it_can_create_performance_anomaly_event()
    {
        $event = new PerformanceAnomalyDetected(
            'test:command',
            'performance_degradation',
            ['metric' => 'total_time', 'current' => 4.0, 'threshold' => 1.5],
            4.0,
            2.0,
            'high',
            ['total_time' => 4.0]
        );

        $this->assertEquals('test:command', $event->commandName);
        $this->assertEquals('performance_degradation', $event->anomalyType);
        $this->assertEquals(['metric' => 'total_time', 'current' => 4.0, 'threshold' => 1.5], $event->anomalyMetrics);
        $this->assertEquals(4.0, $event->anomalyCurrent);
        $this->assertEquals(2.0, $event->anomalyBaselineAvg);
        $this->assertEquals('high', $event->anomalySeverity);
        $this->assertEquals(['total_time' => 4.0], $event->latestMetric);
    }

    /** @test */
    public function it_can_create_failed_jobs_anomaly_event()
    {
        $event = new PerformanceAnomalyDetected(
            'test:command',
            'high_failure_rate',
            ['metric' => 'failed_jobs', 'current' => 6, 'threshold' => 3.0],
            6,
            2,
            'medium',
            ['failed_jobs' => 6]
        );

        $this->assertEquals('test:command', $event->commandName);
        $this->assertEquals('high_failure_rate', $event->anomalyType);
        $this->assertEquals(['metric' => 'failed_jobs', 'current' => 6, 'threshold' => 3.0], $event->anomalyMetrics);
        $this->assertEquals(6, $event->anomalyCurrent);
        $this->assertEquals(2, $event->anomalyBaselineAvg);
        $this->assertEquals('medium', $event->anomalySeverity);
    }

    /** @test */
    public function it_can_create_high_job_count_anomaly_event()
    {
        $event = new PerformanceAnomalyDetected(
            'test:command',
            'unusual_workload_high',
            ['metric' => 'job_count', 'current' => 200, 'threshold' => 150],
            200,
            100,
            'high',
            ['job_count' => 200]
        );

        $this->assertEquals('test:command', $event->commandName);
        $this->assertEquals('unusual_workload_high', $event->anomalyType);
        $this->assertEquals(['metric' => 'job_count', 'current' => 200, 'threshold' => 150], $event->anomalyMetrics);
        $this->assertEquals(200, $event->anomalyCurrent);
        $this->assertEquals(100, $event->anomalyBaselineAvg);
        $this->assertEquals('high', $event->anomalySeverity);
    }

    /** @test */
    public function it_can_create_low_job_count_anomaly_event()
    {
        $event = new PerformanceAnomalyDetected(
            'test:command',
            'unusual_workload_low',
            ['metric' => 'job_count', 'current' => 30, 'threshold' => 50],
            30,
            100,
            'medium',
            ['job_count' => 30]
        );

        $this->assertEquals('test:command', $event->commandName);
        $this->assertEquals('unusual_workload_low', $event->anomalyType);
        $this->assertEquals(['metric' => 'job_count', 'current' => 30, 'threshold' => 50], $event->anomalyMetrics);
        $this->assertEquals(30, $event->anomalyCurrent);
        $this->assertEquals(100, $event->anomalyBaselineAvg);
        $this->assertEquals('medium', $event->anomalySeverity);
    }

    /** @test */
    public function it_can_create_performance_improvement_event()
    {
        $event = new PerformanceAnomalyDetected(
            'test:command',
            'performance_improvement',
            ['metric' => 'total_time', 'current' => 1.0, 'threshold' => 2.0],
            1.0,
            4.0,
            'low',
            ['total_time' => 1.0]
        );

        $this->assertEquals('test:command', $event->commandName);
        $this->assertEquals('performance_improvement', $event->anomalyType);
        $this->assertEquals(['metric' => 'total_time', 'current' => 1.0, 'threshold' => 2.0], $event->anomalyMetrics);
        $this->assertEquals(1.0, $event->anomalyCurrent);
        $this->assertEquals(4.0, $event->anomalyBaselineAvg);
        $this->assertEquals('low', $event->anomalySeverity);
    }

    /** @test */
    public function it_can_create_low_failure_rate_event()
    {
        $event = new PerformanceAnomalyDetected(
            'test:command',
            'low_failure_rate',
            ['metric' => 'failed_jobs', 'current' => 0, 'threshold' => 0.5],
            0,
            5,
            'low',
            ['failed_jobs' => 0]
        );

        $this->assertEquals('test:command', $event->commandName);
        $this->assertEquals('low_failure_rate', $event->anomalyType);
        $this->assertEquals(['metric' => 'failed_jobs', 'current' => 0, 'threshold' => 0.5], $event->anomalyMetrics);
        $this->assertEquals(0, $event->anomalyCurrent);
        $this->assertEquals(5, $event->anomalyBaselineAvg);
        $this->assertEquals('low', $event->anomalySeverity);
    }

    /** @test */
    public function it_handles_empty_command_name()
    {
        $event = new PerformanceAnomalyDetected(
            '',
            'performance_degradation',
            ['metric' => 'total_time', 'current' => 4.0, 'threshold' => 1.5],
            4.0,
            2.0,
            'high',
            ['total_time' => 4.0]
        );

        $this->assertEquals('', $event->commandName);
        $this->assertEquals('performance_degradation', $event->anomalyType);
    }

    /** @test */
    public function it_handles_special_characters_in_command_name()
    {
        $event = new PerformanceAnomalyDetected(
            'test:command@#$%',
            'performance_degradation',
            ['metric' => 'total_time', 'current' => 4.0, 'threshold' => 1.5],
            4.0,
            2.0,
            'high',
            ['total_time' => 4.0]
        );

        $this->assertEquals('test:command@#$%', $event->commandName);
        $this->assertEquals('performance_degradation', $event->anomalyType);
    }

    /** @test */
    public function it_handles_unicode_characters_in_command_name()
    {
        $event = new PerformanceAnomalyDetected(
            'test:command🚀',
            'performance_degradation',
            ['metric' => 'total_time', 'current' => 4.0, 'threshold' => 1.5],
            4.0,
            2.0,
            'high',
            ['total_time' => 4.0]
        );

        $this->assertEquals('test:command🚀', $event->commandName);
        $this->assertEquals('performance_degradation', $event->anomalyType);
    }

    /** @test */
    public function it_handles_very_long_command_names()
    {
        $longCommandName = str_repeat('a', 1000);
        $event = new PerformanceAnomalyDetected(
            $longCommandName,
            'performance_degradation',
            ['metric' => 'total_time', 'current' => 4.0, 'threshold' => 1.5],
            4.0,
            2.0,
            'high',
            ['total_time' => 4.0]
        );

        $this->assertEquals($longCommandName, $event->commandName);
        $this->assertEquals('performance_degradation', $event->anomalyType);
    }

    /** @test */
    public function it_handles_empty_anomaly_metrics()
    {
        $event = new PerformanceAnomalyDetected(
            'test:command',
            'performance_degradation',
            [],
            4.0,
            2.0,
            'high',
            ['total_time' => 4.0]
        );

        $this->assertEquals('test:command', $event->commandName);
        $this->assertEquals('performance_degradation', $event->anomalyType);
        $this->assertEquals([], $event->anomalyMetrics);
    }

    /** @test */
    public function it_handles_null_anomaly_metrics()
    {
        $event = new PerformanceAnomalyDetected(
            'test:command',
            'performance_degradation',
            null,
            4.0,
            2.0,
            'high',
            ['total_time' => 4.0]
        );

        $this->assertEquals('test:command', $event->commandName);
        $this->assertEquals('performance_degradation', $event->anomalyType);
        $this->assertNull($event->anomalyMetrics);
    }

    /** @test */
    public function it_handles_zero_values()
    {
        $event = new PerformanceAnomalyDetected(
            'test:command',
            'performance_degradation',
            ['metric' => 'total_time', 'current' => 0, 'threshold' => 0],
            0,
            0,
            'low',
            ['total_time' => 0]
        );

        $this->assertEquals('test:command', $event->commandName);
        $this->assertEquals('performance_degradation', $event->anomalyType);
        $this->assertEquals(0, $event->anomalyCurrent);
        $this->assertEquals(0, $event->anomalyBaselineAvg);
    }

    /** @test */
    public function it_handles_negative_values()
    {
        $event = new PerformanceAnomalyDetected(
            'test:command',
            'performance_degradation',
            ['metric' => 'total_time', 'current' => -5, 'threshold' => -2],
            -5,
            -1,
            'high',
            ['total_time' => -5]
        );

        $this->assertEquals('test:command', $event->commandName);
        $this->assertEquals('performance_degradation', $event->anomalyType);
        $this->assertEquals(-5, $event->anomalyCurrent);
        $this->assertEquals(-1, $event->anomalyBaselineAvg);
    }

    /** @test */
    public function it_handles_decimal_values()
    {
        $event = new PerformanceAnomalyDetected(
            'test:command',
            'performance_degradation',
            ['metric' => 'total_time', 'current' => 3.14159, 'threshold' => 2.5],
            3.14159,
            2.0,
            'medium',
            ['total_time' => 3.14159]
        );

        $this->assertEquals('test:command', $event->commandName);
        $this->assertEquals('performance_degradation', $event->anomalyType);
        $this->assertEquals(3.14159, $event->anomalyCurrent);
        $this->assertEquals(2.0, $event->anomalyBaselineAvg);
    }

    /** @test */
    public function it_handles_large_numbers()
    {
        $event = new PerformanceAnomalyDetected(
            'test:command',
            'performance_degradation',
            ['metric' => 'total_time', 'current' => 999999999, 'threshold' => 500000000],
            999999999,
            100000000,
            'critical',
            ['total_time' => 999999999]
        );

        $this->assertEquals('test:command', $event->commandName);
        $this->assertEquals('performance_degradation', $event->anomalyType);
        $this->assertEquals(999999999, $event->anomalyCurrent);
        $this->assertEquals(100000000, $event->anomalyBaselineAvg);
        $this->assertEquals('critical', $event->anomalySeverity);
    }

    /** @test */
    public function it_handles_very_small_numbers()
    {
        $event = new PerformanceAnomalyDetected(
            'test:command',
            'performance_degradation',
            ['metric' => 'total_time', 'current' => 0.000001, 'threshold' => 0.0000005],
            0.000001,
            0.0000001,
            'low',
            ['total_time' => 0.000001]
        );

        $this->assertEquals('test:command', $event->commandName);
        $this->assertEquals('performance_degradation', $event->anomalyType);
        $this->assertEquals(0.000001, $event->anomalyCurrent);
        $this->assertEquals(0.0000001, $event->anomalyBaselineAvg);
    }

    /** @test */
    public function it_handles_all_anomaly_types()
    {
        $anomalyTypes = [
            'performance_degradation',
            'performance_improvement',
            'high_failure_rate',
            'low_failure_rate',
            'unusual_workload_high',
            'unusual_workload_low'
        ];

        foreach ($anomalyTypes as $type) {
            $event = new PerformanceAnomalyDetected(
                'test:command',
                $type,
                ['metric' => 'total_time', 'current' => 4.0, 'threshold' => 1.5],
                4.0,
                2.0,
                'high',
                ['total_time' => 4.0]
            );

            $this->assertEquals($type, $event->anomalyType);
        }
    }

    /** @test */
    public function it_handles_all_severity_levels()
    {
        $severityLevels = ['low', 'medium', 'high', 'critical'];

        foreach ($severityLevels as $severity) {
            $event = new PerformanceAnomalyDetected(
                'test:command',
                'performance_degradation',
                ['metric' => 'total_time', 'current' => 4.0, 'threshold' => 1.5],
                4.0,
                2.0,
                $severity,
                ['total_time' => 4.0]
            );

            $this->assertEquals($severity, $event->anomalySeverity);
        }
    }
}
