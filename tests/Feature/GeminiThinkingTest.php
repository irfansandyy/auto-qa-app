<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ModelClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class GeminiThinkingTest extends TestCase
{
    private const GOOGLE_MODELS = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const SERVER_KEY = 'sample-managed-key';

    private const PERSONAL_KEY = 'sample-personal-key';

    private const RESPONSE = ['candidates' => [[
        'finishReason' => 'STOP',
        'content' => ['parts' => [['thought' => true, 'text' => 'Private reasoning'], ['text' => '{"tool":"finish"}']]],
    ]]];

    #[TestWith(['gemini-4-hydrogen', ['thinkingLevel' => 'xhigh'], 65_536])]
    #[TestWith(['gemini-3.8-flash', ['thinkingLevel' => 'high'], 65_536])]
    #[TestWith(['gemini-3.5-flash-lite', ['thinkingLevel' => 'high'], 65_536])]
    #[TestWith(['gemini-2.5-flash-lite', ['thinkingBudget' => 24_576], 32_768])]
    #[TestWith(['gemini-2.5-pro', ['thinkingBudget' => 32_768], 40_960])]
    #[TestWith(['gemma-4-31b-it', ['thinkingLevel' => 'high'], 32_768])]
    #[TestWith(['gemma-4-26b-a4b-it', ['thinkingLevel' => 'high'], 32_768])]
    #[TestWith(['gemini-unlisted-model', null, 6_144])]
    public function test_managed_models_request_their_supported_thinking_setting(string $model, ?array $thinking, int $outputTokens): void
    {
        $url = self::GOOGLE_MODELS.$model.':generateContent';
        Http::preventStrayRequests();
        Http::fake([$url => Http::response(self::RESPONSE)]);

        $decision = $this->managedClient($model)->decide('Check the page.', [], schema: ['type' => 'object']);

        $this->assertSame(['tool' => 'finish'], $decision);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === $url
            && $request->hasHeader('x-goog-api-key', self::SERVER_KEY)
            && ($request['generationConfig']['thinkingConfig'] ?? null) === $thinking
            && $request['generationConfig']['maxOutputTokens'] === $outputTokens
            && $request['generationConfig']['responseJsonSchema'] === ['type' => 'object']);
    }

    public function test_hydrogen_retries_the_same_model_and_key_at_high_only_when_extra_high_is_rejected(): void
    {
        $url = self::GOOGLE_MODELS.'gemini-4-hydrogen:generateContent';
        Http::preventStrayRequests();
        Http::fake([$url => Http::sequence()
            ->push(['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Thinking level is unsupported: THINKING_LEVEL_XHIGH']], 400)
            ->push(self::RESPONSE)]);

        $decision = $this->managedClient('gemini-4-hydrogen')->decide('Check the page.', []);

        $this->assertSame(['tool' => 'finish'], $decision);
        $attempts = Http::recorded()->map(fn (array $pair): array => [
            $pair[0]->url(), $pair[0]->header('x-goog-api-key')[0], $pair[0]['generationConfig']['thinkingConfig']['thinkingLevel'],
        ])->all();
        $this->assertSame([[$url, self::SERVER_KEY, 'xhigh'], [$url, self::SERVER_KEY, 'high']], $attempts);
    }

    public function test_hydrogen_does_not_retry_an_unrelated_request_error_at_a_lower_level(): void
    {
        $url = self::GOOGLE_MODELS.'gemini-4-hydrogen:generateContent';
        Http::preventStrayRequests();
        Http::fake([$url => Http::response(['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'The response schema is invalid.']], 400)]);

        try {
            $this->managedClient('gemini-4-hydrogen')->decide('Check the page.', []);
            $this->fail('An invalid request should stop the run.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Model request failed', $error->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_personal_gemini_uses_the_same_maximum_settings_with_its_own_key(): void
    {
        $url = self::GOOGLE_MODELS.'gemini-4-hydrogen:generateContent';
        Http::preventStrayRequests();
        Http::fake([$url => Http::response(self::RESPONSE)]);
        config(['autoqa.gemini_api_keys' => [self::SERVER_KEY]]);
        $user = User::factory()->make([
            'model_provider' => 'gemini', 'model_name' => 'models/gemini-4-hydrogen', 'model_api_key' => self::PERSONAL_KEY,
        ]);

        $decision = app(ModelClient::class)->forUser($user)->decide('Check the page.', []);

        $this->assertSame(['tool' => 'finish'], $decision);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === $url
            && $request->hasHeader('x-goog-api-key', self::PERSONAL_KEY)
            && $request['generationConfig']['thinkingConfig'] === ['thinkingLevel' => 'xhigh']);
    }

    private function managedClient(string $model): ModelClient
    {
        config(['autoqa.gemini_api_keys' => [self::SERVER_KEY], 'autoqa.gemini_models' => [$model], 'autoqa.senopati_api_key' => null]);

        return app(ModelClient::class)->forUser(User::factory()->make(['model_provider' => 'server_gemini']));
    }
}
