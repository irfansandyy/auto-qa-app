<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\QaRun;
use App\Services\BrowserWorker;
use App\Services\GitHub\GitHubAppClient;
use App\Services\GitHub\RepositoryUrl;
use App\Services\QaAgent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Mockery;
use PharData;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

#[Group('local-pipeline')]
class LocalQaPipelineTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const BASELINE = 'http://127.0.0.1:8082';

    private const MODEL = 'http://127.0.0.1:11434/v1';

    private const GATES = ['baseline_suite', 'baseline_regression_assertion', 'patched_regression', 'patched_suite', 'original_replay', 'control_replay'];

    private array $fixture;

    private array $modelResponses = [];

    private ?array $proposedRepair = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('AUTOQA_PIPELINE_TEST') !== '1') {
            $this->markTestSkipped('Enable AUTOQA_PIPELINE_TEST=1 with the owned fixture, model, Docker engine and private Chromium worker.');
        }
        $metadata = getenv('AUTOQA_PIPELINE_FIXTURE');
        $this->assertIsString($metadata, 'AUTOQA_PIPELINE_FIXTURE must name the prepared fixture JSON.');
        $this->assertFileExists($metadata);
        $this->fixture = json_decode(file_get_contents($metadata), true, flags: JSON_THROW_ON_ERROR);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{40}\z/', $this->fixture['source_sha']);
        $this->assertFileExists($this->fixture['archive']);
        config([
            'autoqa.runner_image' => $this->fixture['image'],
            'autoqa.preview_origin' => 'http://127.0.0.1:8081',
            'autoqa.model_url' => self::MODEL,
            'autoqa.model_name' => 'qwen3:1.7b',
        ]);
        $this->assertSame('http://127.0.0.1:3001', config('autoqa.browser_worker_url'));
        $this->assertContains(self::BASELINE, config('autoqa.local_targets'));
        $this->assertContains('http://127.0.0.1:8081', config('autoqa.local_targets'));
        Http::preventStrayRequests()->allowStrayRequests([
            self::MODEL.'/chat/completions', self::MODEL.'/models',
            'http://127.0.0.1:3001/*', 'http://127.0.0.1:8081/*', self::BASELINE.'/*',
            ...(getenv('AUTOQA_PIPELINE_REAL_GITHUB') === '1' ? $this->githubReadUrls() : []),
        ]);
        Http::record();
        if (getenv('AUTOQA_PIPELINE_REAL_GITHUB') === '1') {
            $this->guardGitHubReads();
        }
        $modelStarted = null;
        Event::listen(RequestSending::class, function (RequestSending $event) use (&$modelStarted): void {
            if ($event->request->url() === self::MODEL.'/chat/completions') {
                $modelStarted = microtime(true);
            }
        });
        Event::listen(ResponseReceived::class, function (ResponseReceived $event) use (&$modelStarted): void {
            if ($event->request->url() === self::MODEL.'/chat/completions') {
                $content = $event->response->json('choices.0.message.content', '');
                $content = is_string($content) ? preg_replace('/<think>.*?<\/think>/s', '', $content) : '';
                $decision = json_decode(preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/', '', trim($content)), true);
                $files = $decision['patch_files'] ?? null;
                if (is_array($files) && count($files) <= 5 && is_string($decision['diagnosis'] ?? null)
                    && strlen($decision['diagnosis']) <= 3000 && ! array_filter($files, fn ($value): bool => ! is_string($value))
                    && array_sum(array_map('strlen', $files)) <= 65536) {
                    $this->proposedRepair = ['diagnosis' => $decision['diagnosis'], 'patch_files' => $files,
                        'regression_test' => is_string($decision['regression_test'] ?? null) ? mb_substr($decision['regression_test'], 0, 255) : null];
                }
                $context = json_decode($event->request['messages'][1]['content'] ?? '{}', true);
                $metadata = [
                    'http_status' => $event->response->status(), 'elapsed_seconds' => $modelStarted === null ? null : round(microtime(true) - $modelStarted, 2),
                    'finish_reason' => $event->response->json('choices.0.finish_reason'),
                    'completion_tokens' => $event->response->json('usage.completion_tokens'), 'prompt_tokens' => $event->response->json('usage.prompt_tokens'),
                    'current_ids' => array_slice(array_column($context['page']['elements'] ?? [], 'id'), 0, 80),
                    'tool' => is_string($decision['tool'] ?? null) ? mb_substr($decision['tool'], 0, 30) : null,
                    'id' => is_string($decision['id'] ?? null) ? mb_substr($decision['id'], 0, 80) : null,
                    'source_selection' => is_array($decision['files'] ?? null) ? array_map(fn (string $path): string => mb_substr($path, 0, 180), array_slice(array_filter($decision['files'], 'is_string'), 0, 4)) : null,
                    'patch_paths' => is_array($decision['patch_files'] ?? null) ? array_map(fn (string|int $path): string => mb_substr((string) $path, 0, 180), array_slice(array_keys($decision['patch_files']), 0, 5)) : null,
                ];
                $this->modelResponses[] = $metadata;
                fwrite(STDERR, 'LOCAL_QA_MODEL:'.json_encode($metadata, JSON_THROW_ON_ERROR)."\n");
            }
        });
        $this->assertTrue(app(BrowserWorker::class)->health()['ready'] ?? false);
        foreach ([8081, 8082] as $port) {
            $socket = @stream_socket_server('tcp://127.0.0.1:'.$port, $errorNumber, $errorMessage);
            $this->assertNotFalse($socket, 'The isolated preview port '.$port.' must be free before this test.');
            fclose($socket);
        }
    }

    public function test_unmodified_baseline_reproduces_optional_phone_http_500_in_real_chromium(): void
    {
        $this->withBaseline(function (): void {
            $browser = app(BrowserWorker::class);
            $state = $browser->start(self::BASELINE.'/register', 'local');
            $session = $state['session_id'];
            try {
                $this->assertSame($this->fixture['source_sha'], $state['snapshot']['source_sha']);
                $this->assertSame([], $state['network_errors']);
                foreach (['Name' => 'Baseline Student', 'Email' => 'baseline@example.test', 'Password' => 'Baseline-password-2026', 'Confirm password' => 'Baseline-password-2026'] as $label => $value) {
                    $element = collect($state['snapshot']['elements'])->firstWhere('label', $label);
                    $this->assertNotNull($element, 'The fixture must expose the labelled '.$label.' field.');
                    $state = $browser->act($session, ['tool' => 'fill', 'id' => $element['id'], 'value' => $value]);
                }
                $button = collect($state['snapshot']['elements'])->firstWhere('text', 'Register');
                $this->assertNotNull($button);
                $state = $browser->act($session, ['tool' => 'click', 'id' => $button['id']]);

                $this->assertSame($this->fixture['source_sha'], $state['snapshot']['source_sha']);
                $this->assertContains(500, array_column($state['network_errors'], 'status'));
                $this->assertStringNotContainsString('Account dashboard', $state['snapshot']['text']);
            } finally {
                $browser->close($session);
            }
        });
        $this->assertSame(0, app(BrowserWorker::class)->health()['active_sessions']);
    }

    public function test_real_agent_generates_and_verifies_a_repair_before_intercepted_draft_publication(): void
    {
        $repository = getenv('AUTOQA_PIPELINE_REPOSITORY') ?: 'https://github.com/example/disposable-repair-demo';
        $goal = 'Register a disposable account with valid Name, Email, Password and Confirm password, leaving the optional Phone field blank. Submit Register and check that the Account dashboard appears.';
        $expected = 'Blank optional Phone must allow registration and show Account dashboard at /dashboard with You are signed in. Normal registration with a filled phone must also succeed. Passwords must contain at least 12 characters and match Confirm password.';
        $project = Project::factory()->local()->publishing()->create([
            'repository_url' => $repository, 'website_url' => self::BASELINE.'/register',
            'deployed_sha' => $this->fixture['source_sha'], 'base_branch' => 'main',
            'goal' => $goal, 'expected_behavior' => $expected,
        ]);
        $run = QaRun::factory()->for($project)->create(['goal' => $goal, 'expected_behavior' => $expected]);
        $published = [];
        $this->bindReadClientWithInterceptedPublication($run, $repository, $published);
        $started = microtime(true);
        $artifact = null;
        $proposalArtifact = null;

        try {
            $this->withBaseline(fn () => app(QaAgent::class)->run($run->id));
            $run->refresh();

            $this->assertSame('ready_for_review', $run->status, $this->runDiagnostics($run));
            $this->assertNull($run->error);
            $this->assertNotNull($run->started_at);
            $this->assertNotNull($run->finished_at);
            $this->assertSame($this->fixture['source_sha'], $run->base_sha);
            $this->assertSame(1, $run->findings()->count());
            $this->assertContains(500, array_column($run->report['failure']['network_errors'], 'status'));
            $original = collect($run->report['actions']);
            foreach (['Name', 'Email', 'Password', 'Confirm password'] as $label) {
                $this->assertTrue($original->contains(fn (array $action): bool => $action['tool'] === 'fill' && ($action['target']['label'] ?? '') === $label));
            }
            $this->assertTrue($original->contains(fn (array $action): bool => $action['tool'] === 'click' && ($action['target']['text'] ?? '') === 'Register'));
            $this->assertSame('', $this->phoneAtRegistration($run->report['actions']));
            $controlPhone = $this->phoneAtRegistration($run->report['control_actions']);
            $this->assertNotNull($controlPhone, 'The control must actually submit the Register form.');
            $this->assertNotSame('', trim($controlPhone), 'The normal control must retain a nonblank Phone when submitting Register.');
            $this->assertMatchesRegularExpression('/\Abrowser-[a-zA-Z0-9-]+\.png\z/', $run->report['failure']['artifact']);
            $this->assertFileExists(storage_path('app/private/evidence/'.$run->report['failure']['artifact']));
            $this->assertTrue($run->verification['passed']);
            foreach (self::GATES as $gate) {
                $this->assertTrue($run->verification[$gate], $gate);
            }
            $this->assertSame($this->fixture['source_sha'], $run->verification['source_sha']);
            $this->assertSame(hash('sha256', json_encode($run->patch_files)), $run->verification['patch_sha256']);
            $baseline = $run->verification['checks']['baseline_regression_assertion'];
            $this->assertSame('assertion_failed', $baseline['status']);
            $this->assertSame(0, $baseline['errors']);
            $this->assertGreaterThan(0, $baseline['failures']);
            foreach (['original_replay', 'control_replay'] as $gate) {
                $this->assertGreaterThan(0, $run->verification['checks'][$gate]['assertions']);
            }
            $this->assertCount(1, array_filter(array_keys($run->patch_files), fn (string $path): bool => str_starts_with($path, 'tests/Feature/')));
            $this->assertSame($run->patch_files, $published['files']);
            $this->assertSame($published['branch'], $run->report['branch']);
            $this->assertSame(str_repeat('b', 40), $run->report['commit_sha']);
            $this->assertSame('https://github.com/example/disposable-repair-demo/pull/1', $run->pr_url);
            $this->assertStringContainsString('Verification: regression failed on base', $published['body']);
            $this->assertSame(0, app(BrowserWorker::class)->health()['active_sessions']);
            $this->assertSame([], glob(storage_path('app/qa/run-'.$run->id.'-*')) ?: []);
            $artifact = $this->writePrivateArtifact('qa-pipeline', [
                'publication_intercepted' => true, 'real_github_reads' => getenv('AUTOQA_PIPELINE_REAL_GITHUB') === '1',
                'repository_url' => $repository, 'base_branch' => 'main', 'source_sha' => $run->base_sha,
                'goal' => $goal, 'expected_behavior' => $expected, 'patch_files' => $run->patch_files,
                'verification' => $run->verification, 'report' => $run->report,
                'model_responses' => $this->modelDiagnostics(),
            ]);
        } finally {
            if ($artifact === null && $this->proposedRepair !== null) {
                $proposalArtifact = $this->writePrivateArtifact('qa-pipeline-unverified-proposal', [
                    'verified' => false, 'publication_intercepted' => true, 'repository_url' => $repository,
                    'source_sha' => $this->fixture['source_sha'], 'proposal' => $this->proposedRepair,
                    'run' => json_decode($this->runDiagnostics($run->fresh()), true), 'model_responses' => $this->modelDiagnostics(),
                ]);
            }
            fwrite(STDERR, 'LOCAL_QA_PIPELINE:'.json_encode([
                'elapsed_seconds' => round(microtime(true) - $started, 2),
                'run' => json_decode($this->runDiagnostics($run->fresh()), true),
                'model_responses' => $this->modelDiagnostics(),
                'private_result_artifact' => $artifact,
                'private_unverified_proposal' => $proposalArtifact,
            ], JSON_THROW_ON_ERROR)."\n");
        }
    }

    private function withBaseline(callable $callback): void
    {
        $directory = storage_path('app/qa/baseline-'.Str::uuid());
        $this->assertTrue(mkdir($directory, 0700, true));
        $payloadPath = $directory.'/payload.json';
        $archivePath = $directory.'/source.tar.gz';
        $name = 'autoqa-'.Str::uuid();
        $proxy = null;
        try {
            $this->assertTrue(File::copy($this->fixture['archive'], $archivePath));
            chmod($archivePath, 0644);
            $this->assertNotFalse(file_put_contents($payloadPath, json_encode([
                'source_sha' => $this->fixture['source_sha'], 'patch_sha256' => hash('sha256', '[]'),
                'files' => [], 'test' => '', 'preview_origin' => self::BASELINE, 'test_timeout' => 30,
            ], JSON_THROW_ON_ERROR)));
            chmod($payloadPath, 0644);
            $image = Process::timeout(10)->run(['docker', 'image', 'inspect', '--format', '{{.Id}}', $this->fixture['image']]);
            $this->assertTrue($image->successful());
            $imageId = trim($image->output());
            $this->assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/', $imageId);
            $container = Process::timeout(15)->run([
                'docker', 'run', '--detach', '--log-driver', 'none', '--rm', '--pull', 'never', '--name', $name,
                '--read-only', '--network', 'none', '--memory', '2g', '--memory-swap', '2g',
                '--cpus', '1', '--pids-limit', '128', '--cap-drop', 'ALL',
                '--security-opt', 'no-new-privileges', '--user', '1000:1000',
                '--tmpfs', '/workspace:rw,noexec,nosuid,nodev,size=512m,uid=1000,gid=1000',
                '--tmpfs', '/tmp:rw,noexec,nosuid,nodev,size=64m,uid=1000,gid=1000',
                '--mount', 'type=bind,source='.$archivePath.',target=/input/source.tar.gz,readonly',
                '--mount', 'type=bind,source='.$payloadPath.',target=/input/payload.json,readonly',
                '--entrypoint', 'php', $imageId, '/opt/autoqa/runner.php', 'baseline_preview',
            ]);
            $this->assertTrue($container->successful(), 'The immutable baseline container must start.');
            $proxy = Process::env(['AUTOQA_PREVIEW_CONTAINER' => $name, 'AUTOQA_PREVIEW_HOST' => '127.0.0.1:8082'])
                ->forever()->start([PHP_BINARY, '-S', '127.0.0.1:8082', base_path('sandbox/preview-proxy.php')]);
            $ready = false;
            $deadline = microtime(true) + 45;
            do {
                try {
                    $health = Http::timeout(3)->get(self::BASELINE.'/__autoqa_health');
                    $ready = $health->successful() && $health->json('source_sha') === $this->fixture['source_sha']
                        && $health->json('patch_sha256') === hash('sha256', '[]');
                } catch (\Throwable) {
                }
                if (! $ready) {
                    usleep(100000);
                }
            } while (! $ready && microtime(true) < $deadline);
            $this->assertTrue($ready, 'The unmodified isolated baseline must expose matching source and empty-patch identities.');
            $callback();
        } finally {
            $proxy?->stop(1);
            Process::timeout(10)->run(['docker', 'rm', '--force', $name]);
            foreach ([$payloadPath, $archivePath] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
        $remaining = Process::timeout(10)->run(['docker', 'ps', '--all', '--filter', 'name='.$name, '--format', '{{.Names}}']);
        $this->assertSame('', trim($remaining->output()));
    }

    private function bindReadClientWithInterceptedPublication(QaRun $run, string $repository, array &$published): void
    {
        $remote = getenv('AUTOQA_PIPELINE_REAL_GITHUB') === '1';
        if ($remote) {
            $this->assertNotFalse(getenv('AUTOQA_PIPELINE_REPOSITORY'), 'Real GitHub reads require an explicitly selected repository.');
        }
        $client = Mockery::mock(GitHubAppClient::class);
        if ($remote) {
            $client->makePartial();
        } else {
            $source = $this->archiveSource();
            $client->shouldReceive('inspectRepository')->with($repository)->andReturn([
                'owner' => 'example', 'name' => 'disposable-repair-demo', 'full_name' => 'example/disposable-repair-demo',
                'default_branch' => 'main', 'head_sha' => $this->fixture['source_sha'], 'installation_id' => 1, 'can_push' => true,
            ]);
            $client->shouldReceive('assertPublicationAllowed')->with($repository, 'main', $this->fixture['source_sha'])->andReturnNull();
            $client->shouldReceive('repositoryTree')->with($repository, $this->fixture['source_sha'])
                ->andReturn(array_map(fn (string $path): array => ['path' => $path, 'size' => strlen($source[$path])], array_keys($source)));
            $client->shouldReceive('readFiles')->with($repository, $this->fixture['source_sha'], Mockery::type('array'))
                ->andReturnUsing(function (string $url, string $sha, array $paths) use ($source): array {
                    $this->assertGreaterThanOrEqual(1, count($paths));
                    $this->assertLessThanOrEqual(4, count($paths));

                    return array_intersect_key($source, array_flip($paths));
                });
            $client->shouldReceive('archiveSnapshot')->with($repository, $this->fixture['source_sha'], Mockery::type('string'))
                ->andReturnUsing(function (string $url, string $sha, string $destination): void {
                    if (! File::copy($this->fixture['archive'], $destination)) {
                        throw new RuntimeException('The exact prepared source archive could not be copied.');
                    }
                });
        }
        $client->shouldReceive('commitRepair')->atMost()->once()->andReturnUsing(function (string $url, string $sha, string $branch, array $files, string $message) use ($run, $repository, &$published): array {
            $current = $run->fresh();
            $this->assertSame($repository, $url);
            $this->assertSame($this->fixture['source_sha'], $sha);
            $this->assertSame($sha, $current->verification['source_sha']);
            $this->assertSame(hash('sha256', json_encode($files)), $current->verification['patch_sha256']);
            $this->assertTrue($current->verification['passed']);
            foreach (self::GATES as $gate) {
                $this->assertTrue($current->verification[$gate], 'Publication must follow real '.$gate.'.');
            }
            $published = ['files' => $files, 'branch' => $branch];

            return ['branch' => $branch, 'sha' => str_repeat('b', 40)];
        });
        $client->shouldReceive('createDraftPullRequest')->atMost()->once()->andReturnUsing(function (string $url, string $branch, string $base, string $title, string $body) use ($repository, &$published): array {
            $this->assertSame($repository, $url);
            $this->assertSame($published['branch'] ?? null, $branch, 'The intercepted commit must precede the intercepted draft PR.');
            $this->assertSame('main', $base);
            $published['body'] = $body;

            return ['number' => 1, 'html_url' => 'https://github.com/example/disposable-repair-demo/pull/1'];
        });
        $this->app->instance(GitHubAppClient::class, $client);
    }

    /** @return array<string, string> */
    private function archiveSource(): array
    {
        $archive = new PharData($this->fixture['archive']);
        $source = [];
        foreach (new \RecursiveIteratorIterator($archive) as $entry) {
            $relative = explode('/', substr($entry->getPathname(), strlen('phar://'.$this->fixture['archive'].'/')), 2)[1] ?? '';
            if ($entry->isFile() && (preg_match('~^(app|resources|routes|tests|config)/~', $relative)
                || in_array($relative, ['composer.json', 'composer.lock', 'package.json', 'phpunit.xml', 'phpunit.xml.dist'], true))) {
                $source[$relative] = $entry->getContent();
            }
        }
        ksort($source);
        $this->assertArrayHasKey('app/Http/Controllers/RegistrationController.php', $source);

        return $source;
    }

    private function runDiagnostics(QaRun $run): string
    {
        return json_encode([
            'status' => $run->status, 'error' => mb_substr($run->error ?? '', 0, 500),
            'summary' => mb_substr($run->report['summary'] ?? '', 0, 500),
            'patch_paths' => array_keys($run->patch_files ?? []), 'verification' => $run->verification,
            'action_tools' => array_column($run->report['actions'] ?? [], 'tool'), 'artifacts' => $run->report['artifacts'] ?? [],
            'executed_controls' => $run->steps()->where('kind', 'action')->get()->take(-10)->map(fn ($step): array => [
                'tool' => $step->data['action']['tool'] ?? null, 'id' => $step->data['action']['id'] ?? null,
                'label' => $step->data['action']['target']['label'] ?? null, 'text' => $step->data['action']['target']['text'] ?? null,
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR);
    }

    private function modelDiagnostics(): array
    {
        return $this->modelResponses;
    }

    /** @param list<array<string, mixed>> $actions */
    private function phoneAtRegistration(array $actions): ?string
    {
        $phone = '';
        $submittedPhone = null;
        foreach ($actions as $action) {
            if ($action['tool'] === 'fill' && ($action['target']['label'] ?? '') === 'Phone') {
                $phone = $action['value'];
            }
            if ($action['tool'] === 'click' && ($action['target']['text'] ?? '') === 'Register') {
                $submittedPhone = $phone;
            }
        }

        return $submittedPhone;
    }

    private function writePrivateArtifact(string $prefix, array $data): string
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        if (strlen($json) > 131072) {
            throw new RuntimeException('The private pipeline result exceeds its evidence bounds.');
        }
        $path = storage_path('app/private/'.$prefix.'-'.Str::uuid().'.json');
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('The private pipeline evidence file could not be created.');
        }
        try {
            if (! chmod($path, 0600) || fwrite($handle, $json) !== strlen($json)) {
                throw new RuntimeException('The private pipeline evidence file could not be written completely.');
            }
        } catch (\Throwable $exception) {
            unlink($path);
            throw $exception;
        } finally {
            fclose($handle);
        }

        return $path;
    }

    private function githubReadUrls(): array
    {
        $repository = RepositoryUrl::parse(getenv('AUTOQA_PIPELINE_REPOSITORY') ?: '');

        return ['https://api.github.com/repos/'.$repository['full_name'].'*',
            'https://api.github.com/app/installations/*/access_tokens',
            'https://codeload.github.com/'.$repository['full_name'].'/*'];
    }

    private function guardGitHubReads(): void
    {
        $repository = RepositoryUrl::parse(getenv('AUTOQA_PIPELINE_REPOSITORY') ?: '');
        $sha = $this->fixture['source_sha'];
        Http::globalRequestMiddleware(function (RequestInterface $request) use ($repository, $sha): RequestInterface {
            $uri = $request->getUri();
            if (! in_array($uri->getHost(), ['api.github.com', 'codeload.github.com'], true)) {
                return $request;
            }
            $path = $uri->getPath();
            $method = $request->getMethod();
            $prefix = '/repos/'.$repository['full_name'];
            $allowed = $uri->getScheme() === 'https' && in_array($uri->getPort(), [null, 443], true) && $uri->getUserInfo() === '';
            if ($uri->getHost() === 'api.github.com') {
                $read = in_array($path, [$prefix, $prefix.'/installation', $prefix.'/git/ref/heads/main', $prefix.'/tarball/'.$sha], true)
                    || preg_match('~\A'.preg_quote($prefix, '~').'/git/trees/[a-f0-9]{40}\z~', $path) === 1;
                if (str_starts_with($path, $prefix.'/contents/')) {
                    parse_str($uri->getQuery(), $query);
                    $read = ($query['ref'] ?? null) === $sha;
                }
                $token = preg_match('~\A/app/installations/[1-9][0-9]*/access_tokens\z~', $path) === 1;
                $allowed = $allowed && (($method === 'GET' && $read) || ($method === 'POST' && $token));
                if ($method === 'POST' && $token) {
                    $scope = json_decode((string) $request->getBody(), true);
                    $allowed = $allowed && ($scope['repositories'] ?? null) === [$repository['name']]
                        && array_diff(array_keys($scope['permissions'] ?? []), ['contents', 'pull_requests', 'metadata']) === [];
                }
            } else {
                $allowed = $allowed && $method === 'GET' && $path === '/'.$repository['full_name'].'/legacy.tar.gz/'.$sha
                    && ! $request->hasHeader('Authorization');
            }
            if (! $allowed) {
                throw new RuntimeException('The pipeline test blocked HTTP outside the selected repository reads and scoped installation-token request.');
            }

            return $request;
        });
    }
}
