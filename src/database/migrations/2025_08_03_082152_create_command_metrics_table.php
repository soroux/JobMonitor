<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('command_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('process_id');
            $table->string('command_name');
            $table->string('source')->nullable(); // console, api
            $table->float('total_time');
            $table->integer('job_count');
            $table->integer('success_jobs');
            $table->integer('failed_jobs');
            $table->float('avg_job_time');
            $table->integer('peak_memory');
            $table->date('run_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_metrics');
    }
};
