<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('job_id');
            $table->string('process_id');
            $table->string('command_name');
            $table->float('execution_time');
            $table->integer('memory_usage');
            $table->float('queue_time');
            $table->enum('status',['success','failed']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_metrics');
    }
};
