<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatStreamTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('autoqa.model_url', 'http://model.test/v1');
        config()->set('autoqa.model_name', 'qwen3:14b');
        config()->set('autoqa.model_key', 'secret');
        config()->set('autoqa.chat_stream_usage', true);
        Http::preventStrayRequests();
    }

    public function test_stream_emits_tokens_done_and_persists_the_assistant_message(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['chat']);
        Http::fake(['http://model.test/v1/chat/completions' => Http::response($this->sse(), 200, [
            'Content-Type' => 'text/event-stream',
        ])]);

        $response = $this->post('/api/chat-ai/stream', ['prompt' => 'Hello']);

        $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');
        $body = $response->streamedContent();

        $this->assertStringContainsString('event: token', $body);
        $this->assertStringContainsString('"delta":"Hello "', $body);
        $this->assertStringContainsString('event: done', $body);
        $this->assertStringNotContainsString('ignored reasoning', $body);
        $this->assertStringContainsString('"total_tokens":3', $body);

        $assistant = ChatSession::query()->sole()->messages()->where('role', 'assistant')->sole();
        $this->assertSame('Hello world', $assistant->content);
        $this->assertSame('qwen3:14b', $assistant->model_used);
        $this->assertSame(3, $assistant->total_tokens);

        Http::assertSent(fn (Request $request): bool => $request['stream'] === true
            && $request['stream_options']['include_usage'] === true);
    }

    public function test_stream_returns_501_when_disable_streaming_is_unsupported(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['chat']);
        Http::fake(['http://model.test/v1/chat/completions' => Http::response(['error' => 'unsupported'], 400)]);

        $this->post('/api/chat-ai/stream', ['prompt' => 'Hello'])
            ->assertStatus(501)
            ->assertJson([
                'status' => 'error',
                'message' => 'Streaming is not supported by the model provider.',
            ]);
    }

    public function test_stream_returns_503_when_the_provider_is_unavailable(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['chat']);
        Http::fake(['http://model.test/v1/chat/completions' => Http::response(['error' => 'down'], 503)]);

        $this->post('/api/chat-ai/stream', ['prompt' => 'Hello'])
            ->assertServiceUnavailable()
            ->assertJson([
                'status' => 'error',
                'message' => 'The language model is unavailable.',
            ]);
    }

    private function sse(): string
    {
        return implode("\n\n", [
            'data: {"model":"qwen3:14b","choices":[{"delta":{"reasoning_content":"ignored reasoning"}}]}',
            'data: {"model":"qwen3:14b","choices":[{"delta":{"content":"Hello "}}]}',
            'data: {"model":"qwen3:14b","choices":[{"delta":{"content":"world"}}]}',
            'data: {"choices":[],"usage":{"prompt_tokens":1,"completion_tokens":2,"total_tokens":3}}',
            'data: [DONE]',
            '',
        ]);
    }
}
