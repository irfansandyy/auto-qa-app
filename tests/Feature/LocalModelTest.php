<?php

namespace Tests\Feature;

use App\Services\ModelClient;
use App\Services\QaAgent;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('local-model')]
class LocalModelTest extends TestCase
{
    private const BASE_URL = 'http://127.0.0.1:11434/v1';

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('AUTOQA_MODEL_TEST') !== '1') {
            $this->markTestSkipped('Set AUTOQA_MODEL_TEST=1 to exercise the installed localhost Qwen model.');
        }

        config()->set('autoqa.model_url', self::BASE_URL);
        config()->set('autoqa.model_name', 'qwen3:1.7b');
        Http::preventStrayRequests()->allowStrayRequests([
            self::BASE_URL.'/chat/completions',
            self::BASE_URL.'/models',
        ]);
        Http::record();
    }

    public function test_local_model_selects_a_current_form_reference_and_returns_an_executable_fill_action(): void
    {
        $snapshot = [
            'url' => 'http://127.0.0.1:8081/register',
            'title' => 'Synthetic registration form',
            'text' => 'Name Email address Create account',
            'elements' => [
                ['id' => 'e17-2', 'tag' => 'input', 'role' => '', 'label' => 'Name', 'text' => '', 'type' => 'text'],
                ['id' => 'e17-3', 'tag' => 'input', 'role' => '', 'label' => 'Email address', 'text' => '', 'type' => 'email'],
                ['id' => 'e17-4', 'tag' => 'button', 'role' => '', 'label' => '', 'text' => 'Create account', 'type' => 'submit'],
            ],
        ];

        $decision = $this->decide(
            'Choose exactly one next browser action to satisfy the goal on the supplied form. Return only {"tool":"fill","id":string,"value":string}. Use a current element ID from the supplied snapshot. Do not navigate, submit, execute code, or include additional fields.',
            ['goal' => 'Fill the Name field with Alice. Do not submit the form yet.', 'page' => $snapshot],
        );

        $keys = array_keys($decision);
        sort($keys);
        $this->assertSame(['id', 'tool', 'value'], $keys);
        $this->assertSame('fill', $decision['tool']);
        $this->assertSame('e17-2', $decision['id']);
        $this->assertSame('Alice', $decision['value']);
        $this->assertSame($decision, app(QaAgent::class)->validateAction($decision, $snapshot));
    }

    public function test_local_model_selects_relevant_existing_source_paths_from_a_small_php_context(): void
    {
        $registration = 'app/Http/Controllers/RegistrationController.php';
        $view = 'resources/views/registration.blade.php';
        $unrelated = 'app/Http/Controllers/StatusController.php';
        $source = [
            $registration => '<?php declare(strict_types=1); class RegistrationController { public function store($request) { $phone = trim($request->input("phone")); return ["phone" => $phone]; } }',
            $view => '<form><label>Phone (optional)<input name="phone"></label><button>Register</button></form>',
            $unrelated => '<?php class StatusController { public function index() { return ["status" => "ok"]; } }',
        ];

        $decision = $this->decide(
            'Choose one or two existing source files relevant to the observed registration failure. Return only {"files":[string,...]}. Select paths from the supplied repository tree. Include the implementation responsible for registration and exclude unrelated health/status code. Do not invent files, return a patch, or include extra fields.',
            [
                'goal' => 'Registration must succeed when the optional phone is blank.',
                'failure' => 'Submitting registration without a phone triggers a TypeError when null is passed to trim().',
                'tree' => array_map(fn ($path) => ['path' => $path, 'size' => strlen($source[$path])], array_keys($source)),
                'source' => $source,
            ],
        );

        $this->assertSame(['files'], array_keys($decision));
        $this->assertIsArray($decision['files']);
        $this->assertTrue(array_is_list($decision['files']));
        $this->assertGreaterThanOrEqual(1, count($decision['files']));
        $this->assertLessThanOrEqual(2, count($decision['files']));
        $this->assertContains($registration, $decision['files'], 'Selected source paths: '.json_encode($decision['files']));
        foreach ($decision['files'] as $path) {
            $this->assertIsString($path);
            $this->assertContains($path, [$registration, $view]);
        }
    }

    private function decide(string $instruction, array $context): array
    {
        $started = microtime(true);
        try {
            $decision = app(ModelClient::class)->decide($instruction, $context);
            fwrite(STDERR, 'LOCAL_MODEL_DECISION:'.json_encode($decision, JSON_THROW_ON_ERROR)."\n");

            return $decision;
        } catch (RuntimeException $exception) {
            $record = Http::recorded()->last();
            $response = $record[1] ?? null;
            if (! $response) {
                throw $exception;
            }
            $error = $response->json('error');
            if (is_array($error)) {
                $error = $error['message'] ?? null;
            }
            $error = is_string($error) ? $error : 'No structured server error was returned.';
            $key = (string) config('autoqa.model_key');
            if ($key !== '') {
                $error = str_replace($key, '[redacted]', $error);
            }
            $error = mb_substr($error, 0, 500);

            throw new RuntimeException($exception->getMessage().' Local model HTTP '.$response->status().': '.$error, previous: $exception);
        } finally {
            $response = Http::recorded()->last()[1] ?? null;
            fwrite(STDERR, 'LOCAL_MODEL_METADATA:'.json_encode([
                'elapsed_seconds' => round(microtime(true) - $started, 2),
                'http_status' => $response?->status(),
                'finish_reason' => $response?->json('choices.0.finish_reason'),
                'completion_tokens' => $response?->json('usage.completion_tokens'),
                'prompt_tokens' => $response?->json('usage.prompt_tokens'),
            ], JSON_THROW_ON_ERROR)."\n");
        }
    }
}
