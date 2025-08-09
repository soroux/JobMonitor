<?php

namespace Soroux\JobMonitor\Tests\Unit\Models;

use Soroux\JobMonitor\Models\CommandMetric;
use Soroux\JobMonitor\Tests\TestCase;
use Carbon\Carbon;

class CommandMetricTest extends TestCase
{
    /** @test */
    public function it_can_create_command_metric()
    {
        $metric = $this->createCommandMetric();

        $this->assertInstanceOf(CommandMetric::class, $metric);
        $this->assertDatabaseHas('command_metrics', [
            'command_name' => 'test:command',
            'total_time' => 10.5,
            'job_count' => 5,
            'success_jobs' => 4,
            'failed_jobs' => 1,
            'avg_job_time' => 2.1,
            'peak_memory' => 1024,
            'source' => 'console',
        ]);
    }

    /** @test */
    public function it_can_create_command_metric_with_api_source()
    {
        $metric = $this->createCommandMetric([
            'source' => 'api',
        ]);

        $this->assertEquals('api', $metric->source);
        $this->assertDatabaseHas('command_metrics', [
            'source' => 'api',
        ]);
    }

    /** @test */
    public function it_casts_run_date_to_date()
    {
        $metric = $this->createCommandMetric([
            'run_date' => '2024-01-15',
        ]);

        $this->assertInstanceOf(Carbon::class, $metric->run_date);
        $this->assertEquals('2024-01-15', $metric->run_date->toDateString());
    }

    /** @test */
    public function it_can_find_metrics_by_command_name()
    {
        $this->createCommandMetric(['command_name' => 'test:command1']);
        $this->createCommandMetric(['command_name' => 'test:command2']);
        $this->createCommandMetric(['command_name' => 'test:command1']);

        $metrics = CommandMetric::where('command_name', 'test:command1')->get();

        $this->assertCount(2, $metrics);
        $this->assertEquals('test:command1', $metrics->first()->command_name);
    }

    /** @test */
    public function it_can_find_metrics_by_source()
    {
        $this->createCommandMetric(['source' => 'console']);
        $this->createCommandMetric(['source' => 'api']);
        $this->createCommandMetric(['source' => 'console']);

        $consoleMetrics = CommandMetric::where('source', 'console')->get();
        $apiMetrics = CommandMetric::where('source', 'api')->get();

        $this->assertCount(2, $consoleMetrics);
        $this->assertCount(1, $apiMetrics);
    }

    /** @test */
    public function it_can_find_metrics_by_date_range()
    {
        $fiveDaysAgo = now()->subDays(5)->toDateString();
        $threeDaysAgo = now()->subDays(3)->toDateString();
        $today = now()->toDateString();
        $twoDaysAgo = now()->subDays(2)->toDateString();
        
        // Create metrics with specific dates
        $this->createCommandMetric(['run_date' => $fiveDaysAgo]);
        $this->createCommandMetric(['run_date' => $threeDaysAgo]);
        $this->createCommandMetric(['run_date' => $today]);

        // Query for metrics from the last 2 days
        $recentMetrics = CommandMetric::where('run_date', '>=', $twoDaysAgo)->get();

        // We expect 1 metric: today which is >= twoDaysAgo
        // The metric from 3 days ago is NOT >= twoDaysAgo
        $this->assertCount(1, $recentMetrics);
        
        // Verify we found the expected metric
        $this->assertEquals($today, $recentMetrics->first()->run_date->toDateString());
    }

    /** @test */
    public function it_can_calculate_average_job_time()
    {
        $this->createCommandMetric(['avg_job_time' => 2.0]);
        $this->createCommandMetric(['avg_job_time' => 4.0]);
        $this->createCommandMetric(['avg_job_time' => 6.0]);

        $average = CommandMetric::avg('avg_job_time');

        $this->assertEquals(4.0, $average);
    }

    /** @test */
    public function it_can_find_metrics_with_high_failure_rate()
    {
        $metric1 = $this->createCommandMetric(['failed_jobs' => 1, 'job_count' => 5, 'success_jobs' => 4]);
        $metric2 = $this->createCommandMetric(['failed_jobs' => 3, 'job_count' => 5, 'success_jobs' => 2]);
        $metric3 = $this->createCommandMetric(['failed_jobs' => 5, 'job_count' => 5, 'success_jobs' => 0]);

        // Try a different approach - filter in PHP instead of raw SQL
        $allMetrics = CommandMetric::all();
        $highFailureMetrics = $allMetrics->filter(function ($metric) {
            return ($metric->failed_jobs / $metric->job_count) > 0.5;
        });

        $this->assertCount(2, $highFailureMetrics);
        // Check that we have the expected metrics (the ones with 3 and 5 failed jobs)
        $failedJobs = $highFailureMetrics->pluck('failed_jobs')->toArray();
        $this->assertContains(3, $failedJobs);
        $this->assertContains(5, $failedJobs);
    }

    /** @test */
    public function it_can_find_metrics_with_low_success_rate()
    {
        $this->createCommandMetric(['success_jobs' => 5, 'job_count' => 5, 'failed_jobs' => 0]);
        $this->createCommandMetric(['success_jobs' => 3, 'job_count' => 5, 'failed_jobs' => 2]);
        $this->createCommandMetric(['success_jobs' => 1, 'job_count' => 5, 'failed_jobs' => 4]);

        // Use PHP filtering instead of raw SQL
        $allMetrics = CommandMetric::all();
        $lowSuccessMetrics = $allMetrics->filter(function ($metric) {
            return ($metric->success_jobs / $metric->job_count) < 0.5;
        });

        $this->assertCount(1, $lowSuccessMetrics);
        $this->assertEquals(1, $lowSuccessMetrics->first()->success_jobs);
    }

    /** @test */
    public function it_can_find_metrics_by_process_id()
    {
        $processId = 'test-process-' . uniqid();
        $this->createCommandMetric(['process_id' => $processId]);

        $metric = CommandMetric::where('process_id', $processId)->first();

        $this->assertNotNull($metric);
        $this->assertEquals($processId, $metric->process_id);
    }

    /** @test */
    public function it_can_find_latest_metric_for_command()
    {
        $commandName = 'test:command';
        $this->createCommandMetric([
            'command_name' => $commandName,
            'run_date' => now()->subDays(2)->toDateString(),
        ]);
        $this->createCommandMetric([
            'command_name' => $commandName,
            'run_date' => now()->subDay()->toDateString(),
        ]);
        $latest = $this->createCommandMetric([
            'command_name' => $commandName,
            'run_date' => now()->toDateString(),
        ]);

        $found = CommandMetric::where('command_name', $commandName)
            ->orderBy('run_date', 'desc')
            ->first();

        $this->assertEquals($latest->id, $found->id);
    }

    /** @test */
    public function it_can_find_distinct_commands()
    {
        $this->createCommandMetric(['command_name' => 'command1']);
        $this->createCommandMetric(['command_name' => 'command2']);
        $this->createCommandMetric(['command_name' => 'command1']);

        $commands = CommandMetric::select('command_name')->distinct()->pluck('command_name');

        $this->assertCount(2, $commands);
        $this->assertContains('command1', $commands);
        $this->assertContains('command2', $commands);
    }

    /** @test */
    public function it_can_find_metrics_with_high_memory_usage()
    {
        $this->createCommandMetric(['peak_memory' => 512]);
        $this->createCommandMetric(['peak_memory' => 1024]);
        $this->createCommandMetric(['peak_memory' => 2048]);

        $highMemoryMetrics = CommandMetric::where('peak_memory', '>', 1000)->get();

        $this->assertCount(2, $highMemoryMetrics);
    }

    /** @test */
    public function it_can_find_metrics_with_long_execution_time()
    {
        $this->createCommandMetric(['total_time' => 5.0]);
        $this->createCommandMetric(['total_time' => 15.0]);
        $this->createCommandMetric(['total_time' => 25.0]);

        $longExecutionMetrics = CommandMetric::where('total_time', '>', 10.0)->get();

        $this->assertCount(2, $longExecutionMetrics);
    }

    /** @test */
    public function it_can_find_metrics_by_date()
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $this->createCommandMetric(['run_date' => $today]);
        $this->createCommandMetric(['run_date' => $yesterday]);
        $this->createCommandMetric(['run_date' => $today]);

        // Use date comparison instead of exact match
        $todayMetrics = CommandMetric::whereDate('run_date', $today)->get();

        $this->assertCount(2, $todayMetrics);
    }

    /** @test */
    public function it_can_find_metrics_with_zero_failures()
    {
        $this->createCommandMetric(['failed_jobs' => 0]);
        $this->createCommandMetric(['failed_jobs' => 1]);
        $this->createCommandMetric(['failed_jobs' => 0]);

        $zeroFailureMetrics = CommandMetric::where('failed_jobs', 0)->get();

        $this->assertCount(2, $zeroFailureMetrics);
    }

    /** @test */
    public function it_can_find_metrics_with_perfect_success_rate()
    {
        $this->createCommandMetric(['success_jobs' => 5, 'job_count' => 5]);
        $this->createCommandMetric(['success_jobs' => 3, 'job_count' => 5]);
        $this->createCommandMetric(['success_jobs' => 10, 'job_count' => 10]);

        $perfectMetrics = CommandMetric::whereRaw('success_jobs = job_count')->get();

        $this->assertCount(2, $perfectMetrics);
    }
}
