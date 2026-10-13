<?php

namespace Tests\Feature;

use App\Jobs\ExecuteQaRun;
use App\Models\QaRun;
use App\Services\QaAgent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ExecuteQaRunTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_delivers_only_the_selected_run_to_the_agent(): void
    {
        $run = QaRun::factory()->create();
        $agent = $this->mock(QaAgent::class);
        $agent->shouldReceive('run')->once()->with($run->id);

        (new ExecuteQaRun($run->id))->handle($agent);

        $this->assertSame('queued', $run->fresh()->status);
    }

    #[DataProvider('activeStatuses')]
    public function test_worker_failure_marks_an_active_run_failed_without_exposing_raw_exception_data(string $status): void
    {
        $this->freezeTime();
        $run = QaRun::factory()->create(['status' => $status]);

        (new ExecuteQaRun($run->id))->failed(new RuntimeException('private-worker-secret-in-raw-error'));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('background worker stopped', $run->error);
        $this->assertStringNotContainsString('private-worker-secret', $run->error);
    }

    public static function activeStatuses(): array
    {
        return [
            'queued' => ['queued'], 'testing' => ['testing'],
            'diagnosing' => ['diagnosing'], 'repairing' => ['repairing'],
        ];
    }

    #[DataProvider('terminalStatuses')]
    public function test_late_worker_failure_preserves_a_terminal_or_cancelled_run(string $status): void
    {
        $this->freezeTime();
        $run = QaRun::factory()->create(['status' => $status, 'error' => 'Preserved result', 'finished_at' => now()]);
        $finished = $run->finished_at;

        (new ExecuteQaRun($run->id))->failed(null);

        $run->refresh();
        $this->assertSame($status, $run->status);
        $this->assertSame('Preserved result', $run->error);
        $this->assertTrue($run->finished_at->equalTo($finished));
    }

    public static function terminalStatuses(): array
    {
        return [
            'cancelled' => ['cancelled'], 'completed' => ['completed'], 'blocked' => ['blocked'],
            'configuration required' => ['needs_configuration'], 'suggestion ready' => ['suggestion_ready'],
            'verification failed' => ['verification_failed'], 'draft PR ready' => ['ready_for_review'], 'already failed' => ['failed'],
        ];
    }

    public function test_an_overlapping_run_is_released_without_running_or_failing_it(): void
    {
        $first = QaRun::factory()->create();
        $second = QaRun::factory()->create();
        $firstJob = new ExecuteQaRun($first->id);
        $secondJob = (new ExecuteQaRun($second->id))->withFakeQueueInteractions();
        $middleware = $secondJob->middleware()[0];
        $lock = Cache::lock($firstJob->middleware()[0]->getLockKey($firstJob), 1860);
        $this->assertTrue($lock->get());
        $executed = false;

        try {
            $middleware->handle($secondJob, function () use (&$executed): void {
                $executed = true;
            });
        } finally {
            $lock->release();
        }

        $secondJob->assertReleased(10)->assertNotFailed()->assertNotDeleted();
        $this->assertFalse($executed);
        $this->assertSame('queued', $second->fresh()->status);
        $this->assertSame(0, $second->steps()->count());
    }

    public function test_queue_payload_allows_lock_releases_without_an_attempt_limit(): void
    {
        $run = QaRun::factory()->create();

        $id = Queue::connection('database')->push(new ExecuteQaRun($run->id));
        $payload = json_decode(DB::table('jobs')->where('id', $id)->value('payload'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $payload['maxTries']);
        $this->assertSame(1800, $payload['timeout']);
        $this->assertTrue($payload['failOnTimeout']);
        $this->assertSame($run->id, unserialize($payload['data']['command'])->runId);
    }
}
