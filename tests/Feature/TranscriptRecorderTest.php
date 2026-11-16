<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\Project;
use App\Models\QaRun;
use App\Services\BrowserWorker;
use App\Services\GitHub\GitHubAppClient;
use App\Services\ModelClient;
use App\Services\QaAgent;
use App\Services\TargetPolicy;
use App\Services\TranscriptRecorder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TranscriptRecorderTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_model_client_records_messages_and_token_usage(): void
    {
        config()->set('autoqa.model_url', 'http://model.test/v1');
        config()->set('autoqa.model_name', 'qwen3:14b');
        config()->set('autoqa.model_key', 'secret');
        Http::preventStrayRequests();
        Http::fake(['http://model.test/v1/chat/completions' => Http::response([
            'model' => 'qwen3:14b',
            'choices' => [['message' => ['content' => '{"tool":"finish","outcome":"passed","summary":"ok"}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 22, 'total_tokens' => 33],
        ])]);

        $project = Project::factory()->create();
        $run = QaRun::factory()->for($project)->create();
        $recorder = app(TranscriptRecorder::class);
        $recorder->begin($run);

        $decision = app(ModelClient::class)->decide('System instruction', ['goal' => 'Check registration']);

        $this->assertSame('finish', $decision['tool']);
        $session = $run->fresh()->chatSession;
        $this->assertInstanceOf(ChatSession::class, $session);
        $this->assertSame($project->user_id, $session->user_id);
        $this->assertSame(['system', 'user', 'assistant'], $session->messages()->pluck('role')->all());
        $this->assertSame('qwen3:14b', $session->messages()->where('role', 'assistant')->value('model_used'));
        $this->assertSame(33, $session->messages()->where('role', 'assistant')->value('total_tokens'));
    }

    public function test_recorder_attaches_redacted_tool_arguments_to_the_assistant_message(): void
    {
        $project = Project::factory()->create();
        $run = QaRun::factory()->for($project)->create();
        $recorder = app(TranscriptRecorder::class);
        $recorder->begin($run);
        $recorder->recordExchange('Instruction', ['goal' => 'Check'], '{"tool":"fill"}', 'qwen3:14b', null);
        $recorder->logTool(
            ['tool' => 'fill', 'id' => 'e1-1', 'value' => '[QA password hidden]'],
            ['url' => 'https://example.test/register', 'network_errors' => 0],
            'succeeded',
            7,
        );

        $assistant = $run->fresh()->chatSession->messages()->where('role', 'assistant')->firstOrFail();
        $log = $assistant->toolExecutionLogs()->sole();
        $this->assertSame('[QA password hidden]', $log->arguments['value']);
        $this->assertSame('succeeded', $log->status);
        $this->assertSame(7, $log->duration_ms);
    }

    public function test_recorder_failures_do_not_break_the_qa_run(): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        $snapshot = ['url' => $project->website_url, 'title' => 'Home', 'text' => 'Home', 'elements' => []];

        $this->mock(TargetPolicy::class)->shouldReceive('assertAllowed')->once();
        $browser = $this->mock(BrowserWorker::class);
        $browser->shouldReceive('start')->once()->andReturn(['session_id' => 'browser-session', 'snapshot' => $snapshot, 'network_errors' => []]);
        $browser->shouldReceive('act')->once()->andReturn(['snapshot' => $snapshot, 'assertion' => ['passed' => true], 'network_errors' => []]);
        $browser->shouldReceive('close')->once()->andReturn(['closed' => true, 'artifacts' => []]);
        $model = $this->mock(ModelClient::class);
        $model->shouldReceive('decide')->twice()->andReturn(
            ['tool' => 'assert_text', 'text' => 'Home'],
            ['tool' => 'finish', 'outcome' => 'passed', 'summary' => 'Confirmed.'],
        );
        $this->mock(GitHubAppClient::class);
        $this->app->instance(TranscriptRecorder::class, new class () extends TranscriptRecorder {
            public function begin(QaRun $run): void
            {
                throw new RuntimeException('recording failed');
            }

            public function logTool(array $action, array $result, string $status, int $durationMs, bool $system = false): void
            {
                throw new RuntimeException('recording failed');
            }
        });

        app(QaAgent::class)->run($run->id);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertNull($run->fresh()->chatSession);
    }

    public function test_qa_agent_records_browser_tools_without_breaking_the_run(): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        $snapshot = [
            'url' => $project->website_url.'/register',
            'title' => 'Register',
            'text' => 'Create an account',
            'elements' => [],
        ];

        $this->mock(TargetPolicy::class)->shouldReceive('assertAllowed')->once();
        $browser = $this->mock(BrowserWorker::class);
        $browser->shouldReceive('start')->once()->andReturn(['session_id' => 'browser-session', 'snapshot' => $snapshot, 'network_errors' => []]);
        $browser->shouldReceive('act')->once()->with('browser-session', ['tool' => 'assert_text', 'text' => 'Account created'])
            ->andReturn(['snapshot' => $snapshot, 'assertion' => ['passed' => true], 'network_errors' => []]);
        $browser->shouldReceive('close')->once()->with('browser-session')->andReturn(['closed' => true, 'artifacts' => []]);

        $model = $this->mock(ModelClient::class);
        $model->shouldReceive('decide')->twice()->andReturn(
            ['tool' => 'assert_text', 'text' => 'Account created'],
            ['tool' => 'finish', 'outcome' => 'passed', 'summary' => 'Confirmed.'],
        );
        $this->mock(GitHubAppClient::class);

        app(QaAgent::class)->run($run->id);

        $this->assertSame('completed', $run->fresh()->status);
        $session = $run->fresh()->chatSession;
        $this->assertNotNull($session);
        $this->assertSame(['start', 'assert_text', 'close'], $session->toolExecutionLogs()->pluck('tool_name')->all());
        $this->assertSame('succeeded', $session->toolExecutionLogs()->where('tool_name', 'assert_text')->value('status'));
        $this->assertSame('system', $session->toolExecutionLogs()->where('tool_name', 'start')->firstOrFail()->chatMessage->role);
        $this->assertSame('system', $session->toolExecutionLogs()->where('tool_name', 'close')->firstOrFail()->chatMessage->role);
        $this->assertSame('system', $session->toolExecutionLogs()->where('tool_name', 'assert_text')->firstOrFail()->chatMessage->role);
    }
}
