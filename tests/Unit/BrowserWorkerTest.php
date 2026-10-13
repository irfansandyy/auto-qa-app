<?php

namespace Tests\Unit;

use App\Services\BrowserWorker;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class BrowserWorkerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('autoqa.browser_worker_url', 'http://127.0.0.1:3001');
        config()->set('autoqa.browser_worker_token', str_repeat('a', 32));
        Http::preventStrayRequests();
    }

    public function test_starts_an_authenticated_session_in_the_selected_mode(): void
    {
        Http::fake(['http://127.0.0.1:3001/sessions' => Http::response(['session_id' => 'session-1', 'snapshot' => ['url' => 'http://localhost:8080/']], 201)]);

        $result = (new BrowserWorker)->start('http://localhost:8080/', 'local');

        $this->assertSame('session-1', $result['session_id']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer '.str_repeat('a', 32))
            && $request['url'] === 'http://localhost:8080/'
            && $request['mode'] === 'local');
    }

    public function test_configuration_readiness_does_not_send_health_requests(): void
    {
        Http::fake();

        $this->assertTrue((new BrowserWorker)->ready());
        Http::assertNothingSent();
    }

    public function test_health_reports_an_unavailable_worker(): void
    {
        Http::fake(['http://127.0.0.1:3001/health' => Http::failedConnection()]);

        $this->assertFalse((new BrowserWorker)->health()['ready']);
    }

    public function test_missing_token_does_not_send_a_request(): void
    {
        config()->set('autoqa.browser_worker_token', '');
        Http::fake();

        $this->assertFalse((new BrowserWorker)->ready());
        Http::assertNothingSent();
    }

    public function test_worker_policy_failure_is_preserved_for_the_user(): void
    {
        Http::fake(['http://127.0.0.1:3001/sessions' => Http::response(['error' => 'This local origin is not allowed by AUTOQA_LOCAL_TARGETS.'], 422)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This local origin is not allowed by AUTOQA_LOCAL_TARGETS.');

        (new BrowserWorker)->start('http://localhost:9000/', 'local');
    }

    public function test_snapshot_unwraps_the_current_browser_state(): void
    {
        Http::fake(['http://127.0.0.1:3001/sessions/session-1' => Http::response(['snapshot' => ['title' => 'Registration', 'elements' => []]])]);

        $this->assertSame('Registration', (new BrowserWorker)->snapshot('session-1')['title']);
    }

    public function test_an_action_is_sent_once_without_retries(): void
    {
        Http::fake(['http://127.0.0.1:3001/sessions/session-1/action' => Http::response(['snapshot' => ['title' => 'Done'], 'assertion' => ['passed' => true]])]);

        $result = (new BrowserWorker)->act('session-1', ['tool' => 'assert_text', 'text' => 'Welcome']);

        $this->assertTrue($result['assertion']['passed']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:3001/sessions/session-1/action' && $request['tool'] === 'assert_text');
    }

    public function test_rejects_unsafe_session_references_before_sending_a_request(): void
    {
        Http::fake();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid browser session reference.');

        (new BrowserWorker)->snapshot('../health');
    }

    public function test_rejects_evidence_path_traversal_returned_by_worker(): void
    {
        Http::fake(['http://127.0.0.1:3001/sessions/session-1/action' => Http::response(['artifact' => '../../secret.env', 'snapshot' => []])]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsafe evidence filename');

        (new BrowserWorker)->screenshot('session-1');
    }
}
