<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ModelClient;
use App\Services\ModelEndpointPolicy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ModelProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('autoqa.model_url', 'http://127.0.0.1:11434/v1');
        config()->set('autoqa.model_name', 'server-model');
        config()->set('autoqa.model_key', 'server-private-key');
        config()->set('autoqa.model_reasoning_effort', 'none');
        Http::preventStrayRequests();
    }

    #[DataProvider('providerResponses')]
    public function test_uses_selected_provider_native_contract_and_keeps_credentials_out_of_context(string $provider, string $url, array $response): void
    {
        $user = User::factory()->make([
            'model_provider' => $provider, 'model_name' => 'test-model', 'model_api_key' => 'private-api-key',
            'model_base_url' => 'https://gateway.example.com/llm-gateway/v1',
        ]);
        if ($provider === 'custom') {
            $this->mock(ModelEndpointPolicy::class, function ($mock): void {
                $mock->shouldReceive('requestOptions')->once()->with('https://gateway.example.com/llm-gateway/v1')
                    ->andReturn(['allow_redirects' => false, 'proxy' => '', 'curl' => [CURLOPT_RESOLVE => ['gateway.example.com:443:93.184.216.34']]]);
            });
        }
        $options = [];
        Http::fake([$url => function (Request $request, array $requestOptions) use ($response, &$options): array {
            $options = $requestOptions;

            return $response;
        }]);

        $decision = (new ModelClient)->forUser($user)->decide('Check registration.', ['page' => 'Untrusted page content.']);

        $this->assertSame(['tool' => 'assert_text', 'text' => 'Account dashboard'], $decision);
        $this->assertFalse($options['allow_redirects']);
        if ($provider === 'custom') {
            $this->assertSame('', $options['proxy']);
            $this->assertSame(['gateway.example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
        }
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($provider, $url): bool {
            $data = $request->data();
            $serialized = json_encode($data);
            if ($request->method() !== 'POST' || $request->url() !== $url
                || str_contains($serialized, 'private-api-key') || str_contains($serialized, 'server-private-key')
                || ! str_contains($serialized, 'Untrusted page content.') || ! str_contains($serialized, 'untrusted data')
                || array_key_exists('reasoning_effort', $data)) {
                return false;
            }
            if ($provider === 'anthropic') {
                return $request->hasHeader('x-api-key', 'private-api-key')
                    && $request->hasHeader('anthropic-version', '2023-06-01')
                    && ! $request->hasHeader('Authorization') && $data['model'] === 'test-model'
                    && $data['max_tokens'] === 6144 && is_string($data['system'])
                    && $data['messages'][0]['role'] === 'user' && ! isset($data['response_format']);
            }
            if ($provider === 'gemini') {
                return $request->hasHeader('x-goog-api-key', 'private-api-key')
                    && ! $request->hasHeader('Authorization')
                    && $data['contents'][0]['role'] === 'user'
                    && is_string($data['systemInstruction']['parts'][0]['text'])
                    && $data['generationConfig'] === ['responseMimeType' => 'application/json', 'maxOutputTokens' => 6144];
            }

            return $request->hasHeader('Authorization', 'Bearer private-api-key')
                && $data['model'] === 'test-model' && $data['messages'][0]['role'] === 'system'
                && $data['response_format'] === ['type' => 'json_object']
                && ($provider === 'openai' ? $data['max_completion_tokens'] === 6144 && ! isset($data['temperature'], $data['max_tokens'])
                    : $data['max_tokens'] === 6144);
        });
    }

    public static function providerResponses(): array
    {
        $decision = '{"tool":"assert_text","text":"Account dashboard"}';
        $chat = ['choices' => [['message' => ['content' => $decision], 'finish_reason' => 'stop']]];

        return [
            'OpenAI GPT' => ['openai', 'https://api.openai.com/v1/chat/completions', $chat],
            'DeepSeek' => ['deepseek', 'https://api.deepseek.com/chat/completions', $chat],
            'custom gateway path' => ['custom', 'https://gateway.example.com/llm-gateway/v1/chat/completions', $chat],
            'Claude native Messages' => ['anthropic', 'https://api.anthropic.com/v1/messages', [
                'content' => [['type' => 'thinking', 'thinking' => 'Private reasoning.'], ['type' => 'text', 'text' => $decision]],
                'stop_reason' => 'end_turn',
            ]],
            'Gemini native generateContent' => ['gemini', 'https://generativelanguage.googleapis.com/v1beta/models/test-model:generateContent', [
                'candidates' => [['content' => ['parts' => [['thought' => true, 'text' => 'Private reasoning.'], ['text' => $decision]]], 'finishReason' => 'STOP']],
            ]],
        ];
    }

    public function test_scoping_is_per_user_and_resets_previous_remote_configuration_for_local_users(): void
    {
        $cloud = User::factory()->make(['model_provider' => 'openai', 'model_name' => 'cloud-model', 'model_api_key' => 'cloud-secret']);
        $local = User::factory()->make(['model_provider' => 'local']);
        $chat = ['choices' => [['message' => ['content' => '{"tool":"finish"}'], 'finish_reason' => 'stop']]];
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response($chat),
            'http://127.0.0.1:11434/v1/chat/completions' => Http::response($chat),
        ]);
        $root = new ModelClient;
        $remote = $root->forUser($cloud);

        $remote->decide('Check.', []);
        $remote->forUser($local)->decide('Check.', []);
        $root->decide('Check.', []);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer cloud-secret') && $request['model'] === 'cloud-model');
        $localRequests = Http::recorded(fn (Request $request) => $request->url() === 'http://127.0.0.1:11434/v1/chat/completions');
        $this->assertCount(2, $localRequests);
        foreach ($localRequests as [$request]) {
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer server-private-key'));
            $this->assertSame('server-model', $request['model']);
            $this->assertSame('none', $request['reasoning_effort']);
        }
    }

    #[DataProvider('blockedResponses')]
    public function test_rejects_truncated_refused_or_credential_echoing_outputs(string $provider, string $url, array $response): void
    {
        $user = User::factory()->make(['model_provider' => $provider, 'model_name' => 'test-model', 'model_api_key' => 'private-api-key']);
        Http::fake([$url => Http::response($response)]);

        try {
            (new ModelClient)->forUser($user)->decide('Check.', []);
            $this->fail('Expected incomplete output to be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('private-api-key', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        Http::assertSentCount(1);
    }

    public static function blockedResponses(): array
    {
        return [
            'Claude token limit' => ['anthropic', 'https://api.anthropic.com/v1/messages', ['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => '{"tool":"click"}']]]],
            'Claude refusal' => ['anthropic', 'https://api.anthropic.com/v1/messages', ['stop_reason' => 'refusal', 'content' => [['type' => 'text', 'text' => '{"tool":"click"}']]]],
            'Gemini token limit' => ['gemini', 'https://generativelanguage.googleapis.com/v1beta/models/test-model:generateContent', ['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => '{"tool":"click"}']]]]]]],
            'OpenAI filtered output' => ['openai', 'https://api.openai.com/v1/chat/completions', ['choices' => [['finish_reason' => 'content_filter', 'message' => ['content' => '{"tool":"click"}']]]]],
            'provider echoes key' => ['openai', 'https://api.openai.com/v1/chat/completions', ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"summary":"private-api-key"}']]]]],
        ];
    }

    public function test_cloud_errors_and_redirects_never_expose_provider_response_or_previous_exception(): void
    {
        $user = User::factory()->make(['model_provider' => 'openai', 'model_name' => 'test-model', 'model_api_key' => 'private-api-key']);
        $url = 'https://api.openai.com/v1/chat/completions';
        Http::fake([$url => Http::response(['error' => 'private-api-key was invalid'], 302, ['Location' => 'https://attacker.example/'])]);

        try {
            (new ModelClient)->forUser($user)->decide('Check.', []);
            $this->fail('Expected redirect to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Model request failed', $exception->getMessage());
            $this->assertStringNotContainsString('private-api-key', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        Http::assertSentCount(1);
    }

    public function test_custom_private_dns_rebinding_is_refused_before_sending_credentials(): void
    {
        $user = User::factory()->make([
            'model_provider' => 'custom', 'model_name' => 'test-model', 'model_api_key' => 'private-api-key',
            'model_base_url' => 'https://api.example.com/v1',
        ]);
        $policy = Mockery::mock(ModelEndpointPolicy::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $policy->shouldReceive('resolveHost')->with('api.example.com')->once()->andReturn(['127.0.0.1']);
        $this->app->instance(ModelEndpointPolicy::class, $policy);
        Http::fake(['https://api.example.com/v1/chat/completions' => Http::response([])]);

        try {
            (new ModelClient)->forUser($user)->decide('Check.', []);
            $this->fail('Expected private DNS to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('model-settings.connection_failed'), $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_cloud_connection_failure_is_sanitized_without_logging_the_provider_exception(): void
    {
        $user = User::factory()->make(['model_provider' => 'openai', 'model_name' => 'test-model', 'model_api_key' => 'private-api-key']);
        Http::fake(['https://api.openai.com/v1/chat/completions' => Http::failedConnection('private-api-key')]);

        try {
            (new ModelClient)->forUser($user)->decide('Check.', []);
            $this->fail('Expected a connection error.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('model-settings.connection_failed'), $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        Http::assertSentCount(1);
    }

    public function test_missing_personal_key_does_not_fall_back_to_the_operator_key(): void
    {
        $user = User::factory()->make(['model_provider' => 'openai', 'model_name' => 'test-model', 'model_api_key' => null]);
        Http::fake(['https://api.openai.com/v1/chat/completions' => Http::response([])]);
        $client = (new ModelClient)->forUser($user);

        $this->assertFalse($client->ready());
        $this->assertFalse($client->health()['ready']);
        try {
            $client->decide('Check.', []);
            $this->fail('Expected missing key to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('model-settings.configuration_needed'), $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    #[DataProvider('healthEndpoints')]
    public function test_provider_health_uses_native_authentication_without_exposing_the_key(string $provider, string $url, string $header, string $value): void
    {
        $user = User::factory()->make(['model_provider' => $provider, 'model_name' => 'test-model', 'model_api_key' => 'private-api-key']);
        Http::fake([$url => Http::response(['data' => []])]);

        $health = (new ModelClient)->forUser($user)->health();

        $this->assertTrue($health['ready']);
        $this->assertStringNotContainsString('private-api-key', json_encode($health));
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === $url && $request->hasHeader($header, $value));
        Http::assertSentCount(1);
    }

    public static function healthEndpoints(): array
    {
        return [
            'OpenAI' => ['openai', 'https://api.openai.com/v1/models', 'Authorization', 'Bearer private-api-key'],
            'Claude' => ['anthropic', 'https://api.anthropic.com/v1/models', 'x-api-key', 'private-api-key'],
            'Gemini' => ['gemini', 'https://generativelanguage.googleapis.com/v1beta/models', 'x-goog-api-key', 'private-api-key'],
            'DeepSeek' => ['deepseek', 'https://api.deepseek.com/models', 'Authorization', 'Bearer private-api-key'],
        ];
    }
}
