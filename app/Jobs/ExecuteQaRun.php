<?php

namespace App\Jobs;

use App\Models\QaRun;
use App\Services\QaAgent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ExecuteQaRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('autoqa-heavy'))->shared()->releaseAfter(10)->expireAfter(1860)];
    }

    public function handle(QaAgent $agent): void
    {
        $agent->run($this->runId);
    }

    public function failed(?\Throwable $exception): void
    {
        QaRun::whereKey($this->runId)->whereIn('status', ['queued', 'testing', 'diagnosing', 'repairing'])
            ->update(['status' => 'failed', 'error' => 'The background worker stopped or exceeded its time limit. Start a new run after checking the worker.', 'finished_at' => now()]);
    }
}
