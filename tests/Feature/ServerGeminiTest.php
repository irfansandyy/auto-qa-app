<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ModelClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class ServerGeminiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const GOOGLE_MODELS = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const PREFERRED = self::GOOGLE_MODELS.'gemini-preferred-model:generateContent';

    private const FALLBACK = self::GOOGLE_MODELS.'gemini-fallback-model:generateContent';

    private const GEMMA_31B = self::GOOGLE_MODELS.'gemma-4-31b-it:generateContent';

    private const GEMMA_26B = self::GOOGLE_MODELS.'gemma-4-26b-a4b-it:generateContent';

    private const SENOPATI = 'https://senopati.lab-alpro.com/llm-gateway/v1/chat/completions';

    private const SENOPATI_KEY = 'senopati-fallback-key';

    private const KEYS = ['first-server-key', 'second-server-key', 'third-server-key'];

    private const DECISION = ['tool' => 'assert_text', 'text' => 'Account dashboard'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('autoqa.gemini_api_keys', self::KEYS);
        config()->set('autoqa.gemini_models', ['gemini-preferred-model', 'gemini-fallback-model']);
        config()->set('autoqa.senopati_api_key', null);
        Http::preventStrayRequests();
    }

    public function test_tries_every_key_before_moving_down_the_model_list_and_stops_on_success(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['tool' => ['type' => 'string', 'enum' => ['assert_text']], 'text' => ['type' => 'string']],
            'required' => ['tool', 'text'],
        ];
        Http::fake([
            self::PREFERRED => Http::sequence()->pushStatus(429)->pushStatus(429)->pushStatus(429),
            self::FALLBACK => Http::sequence()->pushStatus(429)->push($this->geminiDecision()),
        ]);

        $result = $this->serverGemini()->decide('Check registration.', ['page' => 'Registration form'], schema: $schema);

        $this->assertSame(self::DECISION, $result);
        $this->assertSame([
            [self::PREFERRED, 'first-server-key'],
            [self::PREFERRED, 'second-server-key'],
            [self::PREFERRED, 'third-server-key'],
            [self::FALLBACK, 'first-server-key'],
            [self::FALLBACK, 'second-server-key'],
        ], $this->recordedAttempts());
        foreach (Http::recorded() as [$request]) {
            $data = $request->data();
            $this->assertSame('application/json', $data['generationConfig']['responseMimeType']);
            $this->assertSame($schema, $data['generationConfig']['responseJsonSchema']);
            $this->assertArrayNotHasKey('tools', $data);
            foreach (self::KEYS as $key) {
                $this->assertStringNotContainsString($key, $request->body());
            }
        }
    }

    public function test_a_limited_pair_cools_down_for_later_decisions_without_affecting_a_new_run(): void
    {
        $this->freezeTime();
        Http::fake([
            self::PREFERRED => Http::sequence()
                ->push(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429, ['Retry-After' => '120'])
                ->push($this->geminiDecision())
                ->push($this->geminiDecision())
                ->push($this->geminiDecision())
                ->push($this->geminiDecision()),
        ]);
        $client = $this->serverGemini();

        $client->decide('Inspect.', []);
        $client->decide('Continue.', []);
        $this->serverGemini()->decide('New run.', []);
        $this->travel(120)->seconds();
        $client->decide('Try after the cooldown.', []);

        $this->assertSame([
            [self::PREFERRED, 'first-server-key'],
            [self::PREFERRED, 'second-server-key'],
            [self::PREFERRED, 'second-server-key'],
            [self::PREFERRED, 'first-server-key'],
            [self::PREFERRED, 'first-server-key'],
        ], $this->recordedAttempts());
    }

    public function test_a_short_503_is_retried_on_the_same_model_and_key_before_fallback(): void
    {
        Sleep::fake();
        Http::fake([
            self::PREFERRED => Http::sequence()->pushStatus(503)->push($this->geminiDecision()),
        ]);

        $decision = $this->serverGemini()->decide('Check.', []);

        $this->assertSame(self::DECISION, $decision);
        $this->assertSame([
            [self::PREFERRED, self::KEYS[0]],
            [self::PREFERRED, self::KEYS[0]],
        ], $this->recordedAttempts());
        Sleep::assertSequence([Sleep::for(1)->second()]);
    }

    public function test_a_second_503_cools_down_the_model_and_uses_the_first_key_for_the_next_model(): void
    {
        $this->freezeTime();
        Sleep::fake();
        Http::fake([
            self::PREFERRED => Http::sequence()->pushStatus(503)->pushStatus(503),
            self::FALLBACK => Http::sequence()->push($this->geminiDecision())->push($this->geminiDecision()),
        ]);
        $client = $this->serverGemini();

        $decision = $client->decide('Check.', []);
        $client->decide('Continue during the cooldown.', []);

        $this->assertSame(self::DECISION, $decision);
        $this->assertSame([
            [self::PREFERRED, self::KEYS[0]],
            [self::PREFERRED, self::KEYS[0]],
            [self::FALLBACK, self::KEYS[0]],
            [self::FALLBACK, self::KEYS[0]],
        ], $this->recordedAttempts());
        Sleep::assertSleptTimes(1);
    }

    public function test_a_503_with_a_long_retry_after_skips_the_model_until_the_cooldown_ends(): void
    {
        $this->freezeTime();
        Sleep::fake();
        Http::fake([
            self::PREFERRED => Http::sequence()
                ->push($this->geminiDecision())
                ->push(['error' => ['status' => 'UNAVAILABLE']], 503, ['Retry-After' => '120'])
                ->push($this->geminiDecision()),
            self::FALLBACK => Http::sequence()->push($this->geminiDecision())->push($this->geminiDecision()),
        ]);
        $client = $this->serverGemini();

        $client->decide('Inspect.', []);
        $fallbackDecision = $client->decide('Continue.', []);
        $this->travel(119)->seconds();
        $client->decide('Still cooling down.', []);
        $this->travel(1)->seconds();
        $client->decide('After the cooldown.', []);

        $this->assertSame(self::DECISION, $fallbackDecision);
        $this->assertSame([
            [self::PREFERRED, 'first-server-key'],
            [self::PREFERRED, 'first-server-key'],
            [self::FALLBACK, 'first-server-key'],
            [self::FALLBACK, 'first-server-key'],
            [self::PREFERRED, 'first-server-key'],
        ], $this->recordedAttempts());
        Sleep::assertNeverSlept();
    }

    public function test_tries_both_gemma_models_before_senopati_and_keeps_provider_credentials_separate(): void
    {
        config()->set('autoqa.gemini_models', ['gemini-preferred-model', 'gemma-4-31b-it', 'gemma-4-26b-a4b-it']);
        config()->set('autoqa.senopati_api_key', self::SENOPATI_KEY);
        config()->set('autoqa.senopati_model', 'qwen3:14b');
        Http::fake([
            self::PREFERRED => Http::response([], 429),
            self::GEMMA_31B => Http::response([], 429),
            self::GEMMA_26B => Http::response([], 429),
            self::SENOPATI => Http::response($this->senopatiDecision()),
        ]);

        $decision = $this->serverGemini()->decide('Check registration.', ['page' => 'Registration form']);

        $this->assertSame(self::DECISION, $decision);
        $this->assertSame([
            [self::PREFERRED, self::KEYS[0]],
            [self::PREFERRED, self::KEYS[1]],
            [self::PREFERRED, self::KEYS[2]],
            [self::GEMMA_31B, self::KEYS[0]],
            [self::GEMMA_31B, self::KEYS[1]],
            [self::GEMMA_31B, self::KEYS[2]],
            [self::GEMMA_26B, self::KEYS[0]],
            [self::GEMMA_26B, self::KEYS[1]],
            [self::GEMMA_26B, self::KEYS[2]],
            [self::SENOPATI, null],
        ], $this->recordedAttempts());
        Http::assertSent(fn (Request $request): bool => $request->url() === self::SENOPATI
            && $request->hasHeader('Authorization', 'Bearer '.self::SENOPATI_KEY)
            && $request['model'] === 'qwen3:14b'
            && $request['messages'][1]['role'] === 'user'
            && str_contains($request['messages'][1]['content'], 'Registration form'));
        Http::assertNotSent(fn (Request $request): bool => $request->url() !== self::SENOPATI && $request->hasHeader('Authorization'));
        foreach (Http::recorded() as [$request]) {
            foreach ([...self::KEYS, self::SENOPATI_KEY] as $key) {
                $this->assertStringNotContainsString($key, $request->body());
            }
        }
    }

    public function test_a_successful_gemma_response_stops_fallback_and_uses_the_google_schema(): void
    {
        config()->set('autoqa.gemini_models', ['gemma-4-31b-it', 'gemma-4-26b-a4b-it']);
        config()->set('autoqa.senopati_api_key', self::SENOPATI_KEY);
        $schema = [
            'type' => 'object',
            'properties' => ['tool' => ['type' => 'string'], 'text' => ['type' => 'string']],
            'required' => ['tool', 'text'],
        ];
        Http::fake([
            self::GEMMA_31B => Http::response($this->geminiDecision()),
        ]);

        $decision = $this->serverGemini()->decide('Check registration.', [], schema: $schema);

        $this->assertSame(self::DECISION, $decision);
        $this->assertSame([[self::GEMMA_31B, self::KEYS[0]]], $this->recordedAttempts());
        Http::assertSent(fn (Request $request): bool => $request['generationConfig']['responseJsonSchema'] === $schema);
    }

    public function test_managed_connection_checks_models_on_the_senopati_gateway_when_only_its_key_is_configured(): void
    {
        config()->set('autoqa.gemini_api_keys', []);
        config()->set('autoqa.senopati_api_key', self::SENOPATI_KEY);
        $modelsUrl = 'https://senopati.lab-alpro.com/llm-gateway/v1/models';
        Http::fake([$modelsUrl => Http::response(['data' => [['id' => 'qwen3:14b']]])]);

        $health = $this->serverGemini()->health();

        $this->assertTrue($health['ready']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === $modelsUrl
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer '.self::SENOPATI_KEY));
    }

    public function test_a_missing_model_skips_only_the_pair_but_a_denied_key_is_skipped_on_lower_models(): void
    {
        Http::fake([
            self::PREFERRED => Http::sequence()->pushStatus(404)
                ->push(['error' => ['details' => [['reason' => 'API_KEY_SERVICE_BLOCKED']]]], 403)
                ->pushStatus(429),
            self::FALLBACK => Http::sequence()->pushStatus(429)->push($this->geminiDecision()),
        ]);

        $result = $this->serverGemini()->decide('Check.', []);

        $this->assertSame(self::DECISION, $result);
        $this->assertSame([
            [self::PREFERRED, 'first-server-key'],
            [self::PREFERRED, 'second-server-key'],
            [self::PREFERRED, 'third-server-key'],
            [self::FALLBACK, 'first-server-key'],
            [self::FALLBACK, 'third-server-key'],
        ], $this->recordedAttempts());
    }

    public function test_an_exhausted_pool_reports_that_no_configured_model_can_run(): void
    {
        $limited = ['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota for first-server-key exhausted.']];
        Http::fake([
            self::PREFERRED => Http::response($limited, 429),
            self::FALLBACK => Http::response($limited, 429),
        ]);

        try {
            $this->serverGemini()->decide('Check.', []);
            $this->fail('Expected the exhausted pool to stop.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('model-settings.gemini_exhausted'), $exception->getMessage());
        }

        Http::assertSentCount(6);
    }

    public function test_an_invalid_request_stops_without_exposing_the_provider_error_or_trying_other_keys(): void
    {
        config()->set('autoqa.senopati_api_key', self::SENOPATI_KEY);
        Http::fake([
            self::PREFERRED => Http::response(['error' => [
                'status' => 'INVALID_ARGUMENT',
                'message' => 'Request with first-server-key was invalid.',
            ]], 400),
            self::SENOPATI => Http::response($this->senopatiDecision()),
        ]);

        try {
            $this->serverGemini()->decide('Check.', []);
            $this->fail('Expected the invalid request to stop.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('first-server-key', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertSame([[self::PREFERRED, 'first-server-key']], $this->recordedAttempts());
    }

    public function test_a_response_echoing_any_pooled_key_is_refused_without_fallback(): void
    {
        config()->set('autoqa.senopati_api_key', self::SENOPATI_KEY);
        Http::fake([
            self::PREFERRED => Http::response($this->geminiDecision(['tool' => 'fill', 'value' => 'second-server-key'])),
            self::SENOPATI => Http::response($this->senopatiDecision()),
        ]);

        try {
            $this->serverGemini()->decide('Check.', []);
            $this->fail('Expected credential-like output to be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('second-server-key', $exception->getMessage());
        }

        $this->assertSame([[self::PREFERRED, 'first-server-key']], $this->recordedAttempts());
    }

    public function test_pooled_server_gemini_can_be_saved_without_a_personal_key_or_model(): void
    {
        $user = User::factory()->create([
            'model_provider' => 'gemini', 'model_name' => 'personal-model', 'model_api_key' => 'personal-key',
        ]);

        $this->actingAs($user)->post(route('settings.model'), ['model_provider' => 'server_gemini'])
            ->assertRedirect(route('dashboard', ['panel' => 'settings']))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'id' => $user->id, 'model_provider' => 'server_gemini', 'model_name' => null, 'model_api_key' => null,
        ]);
        $this->assertTrue(app(ModelClient::class)->forUser($user->fresh())->ready());
        Http::assertNothingSent();
    }

    public function test_personal_gemini_never_spends_the_server_pool_on_its_own_rate_limit(): void
    {
        $user = User::factory()->make([
            'model_provider' => 'gemini', 'model_name' => 'personal-model', 'model_api_key' => 'personal-key',
        ]);
        $personal = self::GOOGLE_MODELS.'personal-model:generateContent';
        Http::fake([$personal => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429)]);

        try {
            app(ModelClient::class)->forUser($user)->decide('Check.', []);
            $this->fail('Expected the personal limit to stop.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('personal-key', $exception->getMessage());
        }

        $this->assertSame([[$personal, 'personal-key']], $this->recordedAttempts());
    }

    private function serverGemini(): ModelClient
    {
        return app(ModelClient::class)->forUser(User::factory()->make(['model_provider' => 'server_gemini']));
    }

    /**
     * @param  array<string, string>  $decision
     * @return array{candidates: list<array{finishReason: string, content: array{parts: list<array{text: string}>}}>}
     */
    private function geminiDecision(array $decision = self::DECISION): array
    {
        return ['candidates' => [[
            'finishReason' => 'STOP',
            'content' => ['parts' => [['text' => json_encode($decision, JSON_THROW_ON_ERROR)]]],
        ]]];
    }

    /** @return array{choices: list<array{finish_reason: string, message: array{content: string}}>}>} */
    private function senopatiDecision(): array
    {
        return ['choices' => [[
            'finish_reason' => 'stop',
            'message' => ['content' => json_encode(self::DECISION, JSON_THROW_ON_ERROR)],
        ]]];
    }

    /** @return list<array{string, ?string}> */
    private function recordedAttempts(): array
    {
        return Http::recorded()->map(fn (array $attempt): array => [
            $attempt[0]->url(), $attempt[0]->header('x-goog-api-key')[0] ?? null,
        ])->all();
    }
}
