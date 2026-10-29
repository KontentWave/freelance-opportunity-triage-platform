<?php

namespace App\Jobs;

use App\Application\DemoAi\ProcessDemoAi;
use App\Domain\DemoAi\DemoAiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDemoAiRun implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $timeout = 45;

    public int $tries = 1;

    public function __construct(public readonly string $runId) {}

    /**
     * Execute the job.
     */
    public function handle(ProcessDemoAi $process): void
    {
        try {
            $process->execute($this->runId);
        } catch (QueryException) {
            throw new DemoAiException('demo_ai.persistence_failed');
        }
    }
}
