<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('autoqa.model_url', 'http://model.test/v1');
        config()->set('autoqa.model_name', 'qwen3:14b');
        config()->set('autoqa.model_key', 'secret');
        Http::preventStrayRequests();
    }

    public function test_chat_requires_a_sanctum_token(): void
    {
        $response = $this->postJson('/api/chat-ai', ['prompt' => 'Hello']);

        $response->assertUnauthorized()->assertJson([
            'status' => 'error',
            'message' => 'Unauthenticated.',
        ]);
    }

    public function test_chat_returns_the_expected_resource_and_persists_messages(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['chat']);
        $this->fakeModel('The answer.');

        $response = $this->postJson('/api/chat-ai', ['prompt' => 'Explain middleware.']);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.model_used', 'qwen3:14b')
            ->assertJsonPath('data.prompt', 'Explain middleware.')
            ->assertJsonPath('data.ai_response', 'The answer.')
            ->assertJsonPath('data.total_tokens', 33)
            ->assertJsonStructure(['status', 'data' => ['session_id', 'model_used', 'prompt', 'ai_response', 'total_tokens', 'timestamp']]);

        $session = ChatSession::query()->sole();
        $this->assertSame($user->id, $session->user_id);
        $this->assertSame(['user', 'assistant'], $session->messages()->pluck('role')->all());
        $this->assertSame(33, $session->messages()->where('role', 'assistant')->value('total_tokens'));
    }

    public function test_chat_validates_prompt_length(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['chat']);

        $response = $this->postJson('/api/chat-ai', ['prompt' => str_repeat('a', 1001)]);

        $response->assertUnprocessable()->assertJsonValidationErrors('prompt');
        $this->assertDatabaseCount('chat_sessions', 0);
    }

    public function test_chat_rejects_a_session_owned_by_another_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $session = ChatSession::factory()->for($owner)->create();
        Sanctum::actingAs($other, ['chat']);

        $response = $this->postJson('/api/chat-ai', [
            'prompt' => 'Hello',
            'session_id' => $session->id,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('session_id');
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_chat_requires_the_chat_ability(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['other']);

        $response = $this->postJson('/api/chat-ai', ['prompt' => 'Hello']);

        $response->assertForbidden()->assertJson([
            'status' => 'error',
            'message' => 'Forbidden.',
        ]);
    }

    public function test_chat_returns_503_when_the_model_is_unavailable(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['chat']);
        Http::fake(['http://model.test/v1/chat/completions' => Http::response(['error' => 'down'], 503)]);

        $response = $this->postJson('/api/chat-ai', ['prompt' => 'Hello']);

        $response->assertServiceUnavailable()->assertJson([
            'status' => 'error',
            'message' => 'The language model is unavailable.',
        ]);
        $this->assertSame(1, ChatSession::query()->sole()->messages()->count());
    }

    public function test_chat_continues_an_owned_session(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['chat']);
        $session = ChatSession::factory()->for($user)->create();
        $this->fakeModel('Continued.');

        $response = $this->postJson('/api/chat-ai', [
            'prompt' => 'Continue.',
            'session_id' => $session->id,
        ]);

        $response->assertOk()->assertJsonPath('data.session_id', $session->id);
        $this->assertSame(2, $session->messages()->count());
    }

    public function test_chat_is_rate_limited(): void
    {
        config()->set('autoqa.chat_api_throttle', 1);
        Sanctum::actingAs(User::factory()->create(), ['chat']);
        $this->fakeModel('Answer.');

        $this->postJson('/api/chat-ai', ['prompt' => 'First'])->assertOk();
        $this->postJson('/api/chat-ai', ['prompt' => 'Second'])
            ->assertStatus(429)
            ->assertJson(['status' => 'error', 'message' => 'Too many requests.']);
    }

    public function test_chat_uses_themed_prompt_and_bounds_history(): void
    {
        config()->set('autoqa.chat_system_prompt', 'You are a QA engineer.');
        config()->set('autoqa.chat_history_messages', 2);
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['chat']);
        $session = \App\Models\ChatSession::factory()->for($user)->create();

        foreach (range(1, 5) as $sequence) {
            \App\Models\ChatMessage::factory()->for($session)->create([
                'sequence' => $sequence,
                'role' => $sequence % 2 ? 'user' : 'assistant',
                'content' => 'History '.$sequence,
            ]);
        }

        $this->fakeModel('Answer.');

        $this->postJson('/api/chat-ai', ['prompt' => 'Latest question', 'session_id' => $session->id])->assertOk();

        Http::assertSent(function (Request $request): bool {
            $messages = $request['messages'];

            return $messages[0] === ['role' => 'system', 'content' => 'You are a QA engineer.']
                && count($messages) === 3
                && $messages[1]['content'] === 'History 5'
                && $messages[2] === ['role' => 'user', 'content' => 'Latest question'];
        });
    }

    private function fakeModel(string $content): void
    {
        Http::fake(['http://model.test/v1/chat/completions' => Http::response([
            'model' => 'qwen3:14b',
            'choices' => [[
                'message' => [
                    'content' => $content,
                    'reasoning_content' => 'This must never be returned.',
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 22, 'total_tokens' => 33],
        ])]);
    }
}
