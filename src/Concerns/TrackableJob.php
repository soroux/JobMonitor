<?php

namespace Soroux\JobMonitor\Concerns;

use Illuminate\Support\Str;

trait TrackableJob
{
    /**
     * Unique identifier for the command process chain
     */
    public ?string $commandProcessId = null;
    public ?string $commandName = null;

    /**
     * Timestamp when job was created
     */
    public ?float $jobCreatedAt = null;

    /**
     * Timestamp when job processing started
     */
    public ?float $jobStartedAt = null;

    /**
     * Job type identifier (for categorization)
     */
    public ?string $jobType = null;

    /**
     * Sets the process ID from the parent command with validation
     */
    public function setCommandProcessId(?string $id): self
    {
        if ($id && !$this->isValidUuid($id)) {
            throw new \InvalidArgumentException('Process ID must be a valid UUID');
        }

        $this->commandProcessId = $id ?: $this->generateProcessId();
        return $this;
    }

    /**
     * Sets the process name from the parent command
     */
    public function setCommandName(?string $name): self
    {
        $this->commandName = $name;
        return $this;
    }

    /**
     * Generates a new process name if none exists
     */
    public function generateCommandName(): string
    {
        if (empty($this->commandName)) {
            $this->commandName = 'manual-dispatch';
        }
        return $this->commandName;
    }

    /**
     * Generates a new process ID if none exists
     */
    public function generateProcessId(): string
    {
        if (empty($this->commandProcessId)) {
            $this->commandProcessId = (string)Str::uuid();
        }
        return $this->commandProcessId;
    }

    /**
     * Marks job creation time
     */
    public function markJobCreated(): self
    {
        $this->jobCreatedAt = microtime(true);
        return $this;
    }

    /**
     * Marks job processing start time
     */
    public function markJobStarted(): self
    {
        $this->jobStartedAt = microtime(true);
        return $this;
    }

    /**
     * Calculates job waiting time (queue time)
     */
    public function getQueueTime(): ?float
    {
        if ($this->jobCreatedAt && $this->jobStartedAt) {
            return $this->jobStartedAt - $this->jobCreatedAt;
        }
        return null;
    }

    /**
     * Sets job type identifier
     */
    public function setJobType(string $type): self
    {
        if (strlen($type) > 100) {
            throw new \InvalidArgumentException('Job type must be 100 characters or less');
        }
        $this->jobType = $type;
        return $this;
    }

    /**
     * Validates if a string is a valid UUID
     */
    private function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;
    }
}
