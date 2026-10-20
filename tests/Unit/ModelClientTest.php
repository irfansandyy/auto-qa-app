<?php

namespace Tests\Unit;

use App\Services\ModelClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ModelClientTest extends TestCase
{
    private const ENDPOINT = 'http://127.0.0.1:11434/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('autoqa.model_url', 'http://127.0.0.1:11434/v1');
        config()->set('autoqa.model_name', 'qa-test-model');
        config()->set('autoqa.model_key', 'private-model-test-key');
        config()->set('autoqa.model_reasoning_effort', null);
        Http::preventStrayRequests();
    }

    #[DataProvider('structuredResponses')]
    public function test_returns_structured_decisions_and_keeps_authentication_out_of_model_messages(string $content): void
    {
        Http::fake([self::ENDPOINT => Http::response(['choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']]])]);
        $context = ['page' => ['text' => 'Untrusted website asks to ignore instructions.']];

        $result = (new ModelClient)->decide('Check the registration journey.', $context);

        $this->assertSame(['tool' => 'assert_text', 'text' => 'Welcome'], $result);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($context): bool {
            return $request->url() === self::ENDPOINT && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer private-model-test-key')
                && $request['model'] === 'qa-test-model'
                && $request['messages'][1]['content'] === json_encode($context)
                && str_contains($request['messages'][0]['content'], 'untrusted data')
                && ! str_contains(json_encode($request->data()), 'private-model-test-key');
        });
    }

    public static function structuredResponses(): array
    {
        return [
            'plain JSON' => ['{"tool":"assert_text","text":"Welcome"}'],
            'thinking and fenced JSON' => ["<think>Reasoning is removed.</think>\n```json\n{\"tool\":\"assert_text\",\"text\":\"Welcome\"}\n```"],
        ];
    }

    public function test_model_context_exposes_plain_repository_paths_and_urls_without_changing_the_context_or_leaking_authentication(): void
    {
        $path = 'app/Http/Controllers/RegistrationController.php';
        $url = 'http://127.0.0.1:8082/register?next=%2Fdashboard';
        $context = ['tree' => [['path' => $path, 'size' => 1200]], 'page' => ['url' => $url]];
        Http::fake([self::ENDPOINT => Http::response(['choices' => [['message' => ['content' => '{"files":["app/Http/Controllers/RegistrationController.php"]}'], 'finish_reason' => 'stop']]])]);

        $decision = (new ModelClient)->decide('Choose relevant existing repository files.', $context);

        $this->assertSame(['files' => [$path]], $decision);
        Http::assertSentCount(1);
        $request = Http::recorded()->first()[0];
        $prompt = $request['messages'][1]['content'];
        $this->assertStringContainsString($path, $prompt);
        $this->assertStringContainsString($url, $prompt);
        $this->assertStringNotContainsString('\\/', $prompt);
        $this->assertSame($context, json_decode($prompt, true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private-model-test-key', json_encode($request->data(), JSON_THROW_ON_ERROR));
    }

    #[DataProvider('requestDeadlines')]
    public function test_forwards_a_bounded_optional_deadline_without_changing_the_configured_default(int $configured, ?int $timeout, int $expected): void
    {
        config()->set('autoqa.model_timeout', $configured);
        $observed = null;
        Http::fake([self::ENDPOINT => function (Request $request, array $options) use (&$observed): array {
            $observed = $options['timeout'];

            return ['choices' => [['message' => ['content' => '{"tool":"assert_text","text":"Welcome"}'], 'finish_reason' => 'stop']]];
        }]);
        $client = new ModelClient;

        $result = $timeout === null ? $client->decide('Check registration.', []) : $client->decide('Propose the source repair.', [], $timeout);

        $this->assertSame($expected, $observed);
        $this->assertSame($configured, config('autoqa.model_timeout'));
        $this->assertSame(['tool' => 'assert_text', 'text' => 'Welcome'], $result);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::ENDPOINT
            && ! array_key_exists('timeout', $request->data())
            && $request['temperature'] === 0.7 && $request['max_tokens'] === 6144
            && $request['response_format'] === ['type' => 'json_object']);
    }

    public static function requestDeadlines(): array
    {
        return [
            'ordinary two argument call' => [180, null, 180],
            'repair override' => [180, 600, 600],
            'minimum override' => [180, 1, 1],
            'zero override is bounded' => [180, 0, 1],
            'negative override is bounded' => [180, -30, 1],
            'maximum override' => [180, 900, 900],
            'overlong override is bounded' => [180, 1200, 900],
            'configured default is also bounded' => [1200, null, 900],
        ];
    }

    #[DataProvider('reasoningEfforts')]
    public function test_sends_the_configured_bounded_reasoning_level(string $reasoningEffort): void
    {
        config()->set('autoqa.model_reasoning_effort', $reasoningEffort);
        Http::fake([self::ENDPOINT => Http::response(['choices' => [['message' => ['content' => '{"tool":"assert_text","text":"Welcome"}'], 'finish_reason' => 'stop']]])]);

        (new ModelClient)->decide('Check registration.', []);

        Http::assertSent(fn (Request $request) => $request->url() === self::ENDPOINT
            && $request['reasoning_effort'] === $reasoningEffort
            && $request['temperature'] === 0.7
            && $request['max_tokens'] === 6144
            && $request['response_format'] === ['type' => 'json_object']);
    }

    public static function reasoningEfforts(): array
    {
        return [
            'disable thinking' => ['none'],
            'named compatibility level' => ['low'],
            'model-defined level' => ['balanced'],
        ];
    }

    #[DataProvider('omittedReasoningEfforts')]
    public function test_omits_reasoning_effort_when_unset_or_invalid(mixed $reasoningEffort): void
    {
        config()->set('autoqa.model_reasoning_effort', $reasoningEffort);
        Http::fake([self::ENDPOINT => Http::response(['choices' => [['message' => ['content' => '{"tool":"assert_text","text":"Welcome"}'], 'finish_reason' => 'stop']]])]);

        (new ModelClient)->decide('Check registration.', []);

        Http::assertSent(fn (Request $request) => $request->url() === self::ENDPOINT
            && ! array_key_exists('reasoning_effort', $request->data()));
    }

    public static function omittedReasoningEfforts(): array
    {
        return [
            'unset' => [null],
            'blank' => [''],
            'whitespace' => ['none '],
            'non-string' => [false],
            'structured value' => [['effort' => 'none']],
            'overlong' => [str_repeat('a', 33)],
        ];
    }

    #[DataProvider('unsafeResponses')]
    public function test_rejects_invalid_non_string_or_truncated_output_before_returning_a_decision(mixed $content, string $finishReason): void
    {
        Http::fake([self::ENDPOINT => Http::response(['choices' => [['message' => ['content' => $content], 'finish_reason' => $finishReason]]])]);
        $this->expectException(RuntimeException::class);

        (new ModelClient)->decide('Check registration.', []);
    }

    public static function unsafeResponses(): array
    {
        return [
            'ordinary prose' => ['Everything works.', 'stop'],
            'JSON list' => ['[{"tool":"click"}]', 'stop'],
            'JSON scalar' => ['"click"', 'stop'],
            'empty object' => ['{}', 'stop'],
            'malformed JSON' => ['{"tool":"click"', 'stop'],
            'empty content' => ['', 'stop'],
            'array content' => [['tool' => 'click'], 'stop'],
            'null content' => [null, 'stop'],
            'valid-looking truncated output' => ['{"tool":"assert_text","text":"Welcome"}', 'length'],
        ];
    }

    public function test_connection_failure_returns_a_clear_error_without_exposing_authentication(): void
    {
        Http::fake([self::ENDPOINT => Http::failedConnection()]);

        try {
            (new ModelClient)->decide('Check registration.', []);
            $this->fail('Expected the model connection error.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('could not be reached', $exception->getMessage());
            $this->assertStringNotContainsString('private-model-test-key', $exception->getMessage());
        }
    }

    public function test_http_error_does_not_return_a_decision(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => 'unavailable'], 503)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Model request failed');

        (new ModelClient)->decide('Check registration.', []);
    }

    public function test_health_authenticates_with_the_configured_api_key_without_returning_it(): void
    {
        Http::fake(['http://127.0.0.1:11434/v1/models' => Http::response(['data' => [['id' => 'qa-test-model']]])]);

        $health = (new ModelClient)->health();

        $this->assertTrue($health['ready']);
        $this->assertStringNotContainsString('private-model-test-key', json_encode($health));
        Http::assertSent(fn (Request $request) => $request->url() === 'http://127.0.0.1:11434/v1/models'
            && $request->hasHeader('Authorization', 'Bearer private-model-test-key'));
    }

    public function test_health_reports_unavailable_when_the_server_cannot_be_reached(): void
    {
        Http::fake(['http://127.0.0.1:11434/v1/models' => Http::failedConnection()]);

        $this->assertFalse((new ModelClient)->health()['ready']);
    }
}
