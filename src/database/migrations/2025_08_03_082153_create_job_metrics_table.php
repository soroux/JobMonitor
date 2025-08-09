<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('job_id')->unique();
            $table->string('process_id');
            $table->string('command_name');
            $table->string('job_type')->nullable();
            $table->float('execution_time');
            $table->integer('memory_usage');
            $table->float('queue_time');
            $table->enum('status', ['success', 'failed']);
            $table->timestamps();
            
            // Add indexes for performance optimization
            $table->index(['job_id']);
            $table->index(['process_id']);
            $table->index(['command_name']);
            $table->index(['status']);
            $table->index(['created_at']);
            $table->index(['process_id', 'created_at']);
            $table->index(['execution_time']);
            $table->index(['memory_usage']);
            $table->index(['queue_time']);
            $table->index(['job_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_metrics');
    }
};
