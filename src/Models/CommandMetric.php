<?php

namespace Soroux\JobMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class CommandMetric extends Model
{
    protected $casts = [
        'run_date' => 'date',
        'total_time' => 'float',
        'avg_job_time' => 'float',
        'peak_memory' => 'integer',
        'job_count' => 'integer',
        'success_jobs' => 'integer',
        'failed_jobs' => 'integer',
    ];

    protected $fillable = [
        'process_id', 'command_name', 'source', 'total_time',
        'job_count', 'success_jobs', 'failed_jobs', 'avg_job_time',
        'peak_memory', 'run_date','created_at'
    ];

    // Validation rules
    public static $rules = [
        'process_id' => 'required|string|max:255',
        'command_name' => 'required|string|max:255',
        'source' => 'nullable|string|max:50|in:console,api,manual',
        'total_time' => 'required|numeric|min:0',
        'job_count' => 'required|integer|min:0',
        'success_jobs' => 'required|integer|min:0',
        'failed_jobs' => 'required|integer|min:0',
        'avg_job_time' => 'required|numeric|min:0',
        'peak_memory' => 'required|integer|min:0',
        'run_date' => 'required|date',
    ];

    // Relationships
    public function jobMetrics(): HasMany
    {
        return $this->hasMany(JobMetric::class, 'process_id', 'process_id');
    }

    // Validation method
    public static function validate(array $data): bool
    {
        $validator = validator($data, self::$rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return true;
    }

    // Scopes for common queries
    public function scopeByCommand($query, string $commandName)
    {
        return $query->where('command_name', $commandName);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('run_date', [$startDate, $endDate]);
    }

    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    // Helper methods
    public function getFailureRateAttribute(): float
    {
        if ($this->job_count === 0) {
            return 0.0;
        }

        return round(($this->failed_jobs / $this->job_count) * 100, 2);
    }

    public function getSuccessRateAttribute(): float
    {
        if ($this->job_count === 0) {
            return 0.0;
        }

        return round(($this->success_jobs / $this->job_count) * 100, 2);
    }
}
