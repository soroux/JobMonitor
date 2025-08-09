<?php

namespace Soroux\JobMonitor\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PerformanceAnomalyDetected
{
    use Dispatchable, SerializesModels;


    public function __construct(public $commandName,
                                public $anomalyType,
                                public $anomalyMetrics = [],
                                public $anomalyCurrent,
                                public $anomalyBaselineAvg,
                                public $anomalySeverity,
                                public $latestMetric,
    )
    {

    }
}
