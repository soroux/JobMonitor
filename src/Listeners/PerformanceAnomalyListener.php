<?php

namespace Soroux\JobMonitor\Listeners;

use Soroux\JobMonitor\Events\PerformanceAnomalyDetected;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Mail;

class PerformanceAnomalyListener
{
    /**
     * Handle the event.
     */
    public function handle(PerformanceAnomalyDetected $event): void
    {
        $this->logAnomaly($event);
        $this->sendNotification($event);
    }

    /**
     * Log the anomaly
     */
    private function logAnomaly(PerformanceAnomalyDetected $event): void
    {
        $logData = [
            'command' => $event->commandName,
            'anomaly_type' => $event->anomalyType,
            'details' => $event->details,
            'description' => $event->getDescription(),
            'timestamp' => now()->toISOString(),
        ];

        Log::warning('Performance anomaly detected', $logData);
    }

    /**
     * Send notification about the anomaly
     */
    private function sendNotification(PerformanceAnomalyDetected $event): void
    {
        // You can implement various notification methods here
        // For example, email, Slack, Teams, etc.
        
        $notificationConfig = config('job-monitor.notifications', []);
        
        if (isset($notificationConfig['email']) && $notificationConfig['email']['enabled']) {
            $this->sendEmailNotification($event);
        }
        
        if (isset($notificationConfig['slack']) && $notificationConfig['slack']['enabled']) {
            $this->sendSlackNotification($event);
        }
    }

    /**
     * Send email notification
     */
    private function sendEmailNotification(PerformanceAnomalyDetected $event): void
    {
        $notificationConfig = config('job-monitor.notifications.email', []);
        $recipients = $notificationConfig['recipients'] ?? [];
        
        if (empty($recipients)) {
            return;
        }

        $subject = "Performance Anomaly Detected: {$event->commandName}";
        $message = $this->formatEmailMessage($event);

        foreach ($recipients as $recipient) {
            try {
                Mail::raw($message, function ($mail) use ($recipient, $subject) {
                    $mail->to($recipient)
                         ->subject($subject);
                });
            } catch (\Exception $e) {
                Log::error('Failed to send anomaly email notification', [
                    'recipient' => $recipient,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Send Slack notification
     */
    private function sendSlackNotification(PerformanceAnomalyDetected $event): void
    {
        $notificationConfig = config('job-monitor.notifications.slack', []);
        $webhookUrl = $notificationConfig['webhook_url'] ?? null;
        
        if (!$webhookUrl) {
            return;
        }

        $message = $this->formatSlackMessage($event);

        try {
            $response = \Http::post($webhookUrl, [
                'text' => $message,
                'username' => 'Job Monitor',
                'icon_emoji' => ':warning:'
            ]);

            if (!$response->successful()) {
                Log::error('Failed to send Slack notification', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send Slack notification', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Format email message
     */
    private function formatEmailMessage(PerformanceAnomalyDetected $event): string
    {
        $message = "Performance Anomaly Detected\n";
        $message .= "==========================\n\n";
        $message .= "Command: {$event->commandName}\n";
        $message .= "Type: {$event->anomalyType}\n";
        $message .= "Description: {$event->getDescription()}\n";
        $message .= "Time: " . now()->format('Y-m-d H:i:s') . "\n\n";
        
        if (!empty($event->details)) {
            $message .= "Details:\n";
            foreach ($event->details as $key => $value) {
                $message .= "- {$key}: " . (is_array($value) ? json_encode($value) : $value) . "\n";
            }
        }

        return $message;
    }

    /**
     * Format Slack message
     */
    private function formatSlackMessage(PerformanceAnomalyDetected $event): string
    {
        $emoji = $this->getAnomalyEmoji($event->anomalyType);
        
        $message = "{$emoji} *Performance Anomaly Detected*\n";
        $message .= "• *Command:* `{$event->commandName}`\n";
        $message .= "• *Type:* {$event->anomalyType}\n";
        $message .= "• *Description:* {$event->getDescription()}\n";
        $message .= "• *Time:* " . now()->format('Y-m-d H:i:s');

        return $message;
    }

    /**
     * Get appropriate emoji for anomaly type
     */
    private function getAnomalyEmoji(string $anomalyType): string
    {
        $emojis = [
            'performance' => ':snail:',
            'failed_jobs' => ':x:',
            'high_job_count' => ':chart_with_upwards_trend:',
            'low_job_count' => ':chart_with_downwards_trend:',
            'missed_execution' => ':clock1:',
            'never_executed' => ':no_entry:',
        ];

        return $emojis[$anomalyType] ?? ':warning:';
    }
} 