<?php

namespace Soroux\JobMonitor\Tests\Unit\Models;

use Soroux\JobMonitor\Models\JobMetric;
use Soroux\JobMonitor\Tests\TestCase;

class JobMetricTest extends TestCase
{
    /** @test */
    public function it_can_create_job_metric()
    {
        $metric = $this->createJobMetric();

        $this->assertInstanceOf(JobMetric::class, $metric);
        $this->assertDatabaseHas('job_metrics', [
            'command_name' => 'test:command',
            'execution_time' => 2.1,
            'memory_usage' => 512,
            'queue_time' => 0.5,
            'status' => 'success',
        ]);
    }

    /** @test */
    public function it_can_create_failed_job_metric()
    {
        $metric = $this->createJobMetric([
            'status' => 'failed',
        ]);

        $this->assertEquals('failed', $metric->status);
        $this->assertDatabaseHas('job_metrics', [
            'status' => 'failed',
        ]);
    }

    /** @test */
    public function it_can_find_jobs_by_status()
    {
        $this->createJobMetric(['status' => 'success']);
        $this->createJobMetric(['status' => 'failed']);
        $this->createJobMetric(['status' => 'success']);

        $successJobs = JobMetric::where('status', 'success')->get();
        $failedJobs = JobMetric::where('status', 'failed')->get();

        $this->assertCount(2, $successJobs);
        $this->assertCount(1, $failedJobs);
    }

    /** @test */
    public function it_can_find_jobs_by_command_name()
    {
        $this->createJobMetric(['command_name' => 'command1']);
        $this->createJobMetric(['command_name' => 'command2']);
        $this->createJobMetric(['command_name' => 'command1']);

        $command1Jobs = JobMetric::where('command_name', 'command1')->get();

        $this->assertCount(2, $command1Jobs);
        $this->assertEquals('command1', $command1Jobs->first()->command_name);
    }

    /** @test */
    public function it_can_find_jobs_by_process_id()
    {
        $processId = 'test-process-' . uniqid();
        $this->createJobMetric(['process_id' => $processId]);

        $job = JobMetric::where('process_id', $processId)->first();

        $this->assertNotNull($job);
        $this->assertEquals($processId, $job->process_id);
    }

    /** @test */
    public function it_can_find_jobs_with_long_execution_time()
    {
        $this->createJobMetric(['execution_time' => 1.0]);
        $this->createJobMetric(['execution_time' => 5.0]);
        $this->createJobMetric(['execution_time' => 10.0]);

        $longExecutionJobs = JobMetric::where('execution_time', '>', 3.0)->get();

        $this->assertCount(2, $longExecutionJobs);
    }

    /** @test */
    public function it_can_find_jobs_with_high_memory_usage()
    {
        $this->createJobMetric(['memory_usage' => 256]);
        $this->createJobMetric(['memory_usage' => 512]);
        $this->createJobMetric(['memory_usage' => 1024]);

        $highMemoryJobs = JobMetric::where('memory_usage', '>', 500)->get();

        $this->assertCount(2, $highMemoryJobs);
    }

    /** @test */
    public function it_can_find_jobs_with_long_queue_time()
    {
        $this->createJobMetric(['queue_time' => 0.1]);
        $this->createJobMetric(['queue_time' => 1.0]);
        $this->createJobMetric(['queue_time' => 5.0]);

        $longQueueJobs = JobMetric::where('queue_time', '>', 0.5)->get();

        $this->assertCount(2, $longQueueJobs);
    }

    /** @test */
    public function it_can_calculate_average_execution_time()
    {
        $this->createJobMetric(['execution_time' => 2.0]);
        $this->createJobMetric(['execution_time' => 4.0]);
        $this->createJobMetric(['execution_time' => 6.0]);

        $average = JobMetric::avg('execution_time');

        $this->assertEquals(4.0, $average);
    }

    /** @test */
    public function it_can_calculate_average_memory_usage()
    {
        $this->createJobMetric(['memory_usage' => 256]);
        $this->createJobMetric(['memory_usage' => 512]);
        $this->createJobMetric(['memory_usage' => 768]);

        $average = JobMetric::avg('memory_usage');

        $this->assertEquals(512.0, $average);
    }

    /** @test */
    public function it_can_calculate_average_queue_time()
    {
        $this->createJobMetric(['queue_time' => 0.5]);
        $this->createJobMetric(['queue_time' => 1.0]);
        $this->createJobMetric(['queue_time' => 1.5]);

        $average = JobMetric::avg('queue_time');

        $this->assertEquals(1.0, $average);
    }

    /** @test */
    public function it_can_find_failed_jobs_for_command()
    {
        $commandName = 'test:command';
        $this->createJobMetric(['command_name' => $commandName, 'status' => 'success']);
        $this->createJobMetric(['command_name' => $commandName, 'status' => 'failed']);
        $this->createJobMetric(['command_name' => $commandName, 'status' => 'success']);

        $failedJobs = JobMetric::where('command_name', $commandName)
            ->where('status', 'failed')
            ->get();

        $this->assertCount(1, $failedJobs);
        $this->assertEquals('failed', $failedJobs->first()->status);
    }

    /** @test */
    public function it_can_find_successful_jobs_for_command()
    {
        $commandName = 'test:command';
        $this->createJobMetric(['command_name' => $commandName, 'status' => 'success']);
        $this->createJobMetric(['command_name' => $commandName, 'status' => 'failed']);
        $this->createJobMetric(['command_name' => $commandName, 'status' => 'success']);

        $successJobs = JobMetric::where('command_name', $commandName)
            ->where('status', 'success')
            ->get();

        $this->assertCount(2, $successJobs);
    }

    /** @test */
    public function it_can_find_jobs_by_job_id()
    {
        $jobId = 'test-job-' . uniqid();
        $this->createJobMetric(['job_id' => $jobId]);

        $job = JobMetric::where('job_id', $jobId)->first();

        $this->assertNotNull($job);
        $this->assertEquals($jobId, $job->job_id);
    }

    /** @test */
    public function it_can_find_jobs_with_total_time_calculation()
    {
        $this->createJobMetric(['execution_time' => 2.0, 'queue_time' => 1.0]);
        $this->createJobMetric(['execution_time' => 5.0, 'queue_time' => 2.0]);

        $jobs = JobMetric::selectRaw('*, (execution_time + queue_time) as total_time')->get();

        $this->assertEquals(3.0, $jobs->first()->total_time);
        $this->assertEquals(7.0, $jobs->last()->total_time);
    }

    /** @test */
    public function it_can_find_jobs_by_date_range()
    {
        $this->createJobMetric(['created_at' => now()->subDays(5)]);
        $this->createJobMetric(['created_at' => now()->subDays(3)]);
        $this->createJobMetric(['created_at' => now()]);

        $recentJobs = JobMetric::where('created_at', '>=', now()->subDays(2))->get();

        // We expect 1 job: the one from today which is >= 2 days ago
        // The job from 3 days ago is NOT >= 2 days ago
        $this->assertCount(1, $recentJobs);
    }

    /** @test */
    public function it_can_find_jobs_with_performance_issues()
    {
        // Jobs with execution time > 5 seconds
        $this->createJobMetric(['execution_time' => 3.0]);
        $this->createJobMetric(['execution_time' => 6.0]);
        $this->createJobMetric(['execution_time' => 8.0]);

        $slowJobs = JobMetric::where('execution_time', '>', 5.0)->get();

        $this->assertCount(2, $slowJobs);
    }

    /** @test */
    public function it_can_find_jobs_with_memory_issues()
    {
        // Jobs with memory usage > 1MB
        $this->createJobMetric(['memory_usage' => 512]);
        $this->createJobMetric(['memory_usage' => 1024]);
        $this->createJobMetric(['memory_usage' => 2048]);

        $highMemoryJobs = JobMetric::where('memory_usage', '>', 1024)->get();

        $this->assertCount(1, $highMemoryJobs);
        $this->assertEquals(2048, $highMemoryJobs->first()->memory_usage);
    }

    /** @test */
    public function it_can_find_jobs_with_queue_issues()
    {
        // Jobs with queue time > 2 seconds
        $this->createJobMetric(['queue_time' => 1.0]);
        $this->createJobMetric(['queue_time' => 3.0]);
        $this->createJobMetric(['queue_time' => 5.0]);

        $longQueueJobs = JobMetric::where('queue_time', '>', 2.0)->get();

        $this->assertCount(2, $longQueueJobs);
    }

    /** @test */
    public function it_can_find_jobs_by_multiple_criteria()
    {
        $commandName = 'test:command';
        $this->createJobMetric([
            'command_name' => $commandName,
            'status' => 'success',
            'execution_time' => 3.0,
        ]);
        $this->createJobMetric([
            'command_name' => $commandName,
            'status' => 'failed',
            'execution_time' => 6.0,
        ]);
        $this->createJobMetric([
            'command_name' => $commandName,
            'status' => 'success',
            'execution_time' => 2.0,
        ]);

        $slowSuccessJobs = JobMetric::where('command_name', $commandName)
            ->where('status', 'success')
            ->where('execution_time', '>', 2.5)
            ->get();

        $this->assertCount(1, $slowSuccessJobs);
        $this->assertEquals(3.0, $slowSuccessJobs->first()->execution_time);
    }

    /** @test */
    public function it_can_count_jobs_by_status()
    {
        $this->createJobMetric(['status' => 'success']);
        $this->createJobMetric(['status' => 'failed']);
        $this->createJobMetric(['status' => 'success']);
        $this->createJobMetric(['status' => 'failed']);

        $successCount = JobMetric::where('status', 'success')->count();
        $failedCount = JobMetric::where('status', 'failed')->count();

        $this->assertEquals(2, $successCount);
        $this->assertEquals(2, $failedCount);
    }

    /** @test */
    public function it_can_find_jobs_with_zero_queue_time()
    {
        $this->createJobMetric(['queue_time' => 0.0]);
        $this->createJobMetric(['queue_time' => 1.0]);
        $this->createJobMetric(['queue_time' => 0.0]);

        $zeroQueueJobs = JobMetric::where('queue_time', 0.0)->get();

        $this->assertCount(2, $zeroQueueJobs);
    }

    /** @test */
    public function it_can_find_jobs_with_zero_execution_time()
    {
        $this->createJobMetric(['execution_time' => 0.0]);
        $this->createJobMetric(['execution_time' => 1.0]);
        $this->createJobMetric(['execution_time' => 0.0]);

        $zeroExecutionJobs = JobMetric::where('execution_time', 0.0)->get();

        $this->assertCount(2, $zeroExecutionJobs);
    }
}
