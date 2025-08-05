<?php

namespace Soroux\JobMonitor\Models;

use Illuminate\Database\Eloquent\Model;

class CommandMetric extends Model
{
    protected $guarded = [];
    protected $casts = [
        'run_date' => 'date',
    ];
}
