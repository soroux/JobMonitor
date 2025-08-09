<?php

namespace Soroux\JobMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class JobMetric extends Model
{
    protected $casts = [
        'execution_time' => 'float',
        'memory_usage' => 'integer',
        'queue_time' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'job_id', 'process_id', 'command_name', 'execution_time',
        'memory_usage', 'queue_time', 'status', 'job_type', 'created_at'
    ];

    // Validation rules
    public static $rules = [
        'job_id' => 'required|string|max:255',
        'process_id' => 'required|string|max:255',
        'command_name' => 'required|string|max:255',
        'execution_time' => 'required|numeric|min:0',
        'memory_usage' => 'required|integer|min:0',
        'queue_time' => 'required|numeric|min:0',
        'status' => 'required|in:success,failed',
        'job_type' => 'nullable|string|max:100',
    ];

    // Relationships
    public function commandMetric(): BelongsTo
    {
        return $this->belongsTo(CommandMetric::class, 'process_id', 'process_id');
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
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByCommand($query, string $commandName)
    {
        return $query->where('command_name', $commandName);
    }

    public function scopeByProcessId($query, string $processId)
    {
        return $query->where('process_id', $processId);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    // Helper methods
    public function getTotalTimeAttribute(): float
    {
        return $this->execution_time + $this->queue_time;
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
