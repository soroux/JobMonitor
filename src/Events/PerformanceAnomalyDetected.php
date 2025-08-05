<?php

namespace Soroux\JobMonitor\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PerformanceAnomalyDetected
{
    use Dispatchable, SerializesModels;

    public $commandName;
    public $anomalyType;
    public $details;

    public function __construct($commandName, $anomalyType, $details = [])
    {
        $this->commandName = $commandName;
        $this->anomalyType = $anomalyType;
        $this->details = $details;
    }

    /**
     * Get a human-readable description of the anomaly
     */
    public function getDescription()
    {
        switch ($this->anomalyType) {
            case 'performance':
                return "Command '{$this->commandName}' is taking {$this->details['multiplier']}x longer than usual";

            case 'failed_jobs':
                return "Command '{$this->commandName}' has {$this->details['multiplier']}x more failed jobs than usual";

            case 'high_job_count':
                return "Command '{$this->commandName}' is processing {$this->details['multiplier']}x more jobs than usual";

            case 'low_job_count':
                return "Command '{$this->commandName}' is processing {$this->details['multiplier']}x fewer jobs than usual";

            case 'missed_execution':
                return "Command '{$this->commandName}' missed its scheduled execution by {$this->details['hours_overdue']} hours";

            case 'never_executed':
                return "Command '{$this->commandName}' has never been executed but is scheduled to run {$this->details['expected_next_run']}";

            default:
                return "Anomaly detected for command '{$this->commandName}'";
        }
    }
}
