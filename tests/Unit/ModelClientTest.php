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
