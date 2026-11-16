<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\QaRun;
use App\Models\ToolExecutionLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChatSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_chat_relations_and_sequence_are_recorded(): void
    {
        $user = User::factory()->create();
        $run = QaRun::factory()->create();
        $session = ChatSession::factory()->for($user)->for($run)
            ->withMessages(2, 1)
            ->create(['title' => 'QA transcript']);

        $this->assertSame([1, 2, 3, 4], $session->messages()->pluck('sequence')->all());
        $this->assertSame(2, $session->messages()->where('role', 'assistant')->count());
        $this->assertSame(2, $session->messages()->where('role', 'user')->count());
        $this->assertSame($user->id, $session->user->id);
        $this->assertSame($run->id, $session->qaRun->id);

        $assistant = $session->messages()->where('role', 'assistant')->firstOrFail();
        $this->assertSame('qwen3:14b', $assistant->model_used);
        $this->assertSame(30, $assistant->total_tokens);

        $log = $assistant->toolExecutionLogs()->firstOrFail();
        $this->assertSame('click', $log->tool_name);
        $this->assertSame('succeeded', $log->status);
        $this->assertSame(['tool' => 'click', 'id' => 'e1-1'], $log->arguments);
        $this->assertTrue($log->loggable->is($run));
        $this->assertTrue($run->toolExecutionLogs()->firstOrFail()->is($log));
    }

    public function test_deleting_a_user_cascades_chat_data(): void
    {
        $user = User::factory()->create();
        $session = ChatSession::factory()->for($user)->withMessages(1, 1)->create();

        $this->assertDatabaseCount('chat_sessions', 1);
        $this->assertDatabaseCount('chat_messages', 2);
        $this->assertDatabaseCount('tool_execution_logs', 1);

        $user->delete();

        $this->assertDatabaseCount('chat_sessions', 0);
        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertDatabaseCount('tool_execution_logs', 0);
    }

    public function test_deleting_a_qa_run_cascades_its_chat_session(): void
    {
        $session = ChatSession::factory()->withMessages(1, 1)->create();
        $run = $session->qaRun;

        $run->delete();

        $this->assertDatabaseCount('chat_sessions', 0);
        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertDatabaseCount('tool_execution_logs', 0);
    }

    public function test_chat_message_sequence_is_unique_per_session(): void
    {
        $session = ChatSession::factory()->create();
        ChatMessage::factory()->for($session)->create(['sequence' => 1]);

        $this->expectException(QueryException::class);

        ChatMessage::factory()->for($session)->create(['sequence' => 1]);
    }

    public function test_chat_factory_uses_a_single_qa_run_for_tool_logs(): void
    {
        $session = ChatSession::factory()->withMessages(3, 2)->create();

        $this->assertSame(6, $session->messages()->count());
        $this->assertSame(6, ToolExecutionLog::query()->count());
        $this->assertSame(
            [$session->qa_run_id],
            DB::table('tool_execution_logs')->distinct()->pluck('loggable_id')->all(),
        );
    }
}
