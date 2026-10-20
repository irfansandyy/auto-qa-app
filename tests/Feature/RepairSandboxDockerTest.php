<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\QaRun;
use App\Services\BrowserWorker;
use App\Services\GitHub\GitHubAppClient;
use App\Services\RepairSandbox;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('docker')]
class RepairSandboxDockerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('AUTOQA_DOCKER_TEST') !== '1') {
            $this->markTestSkipped('Enable AUTOQA_DOCKER_TEST=1 with a prepared local image and private browser worker.');
        }
        $metadata = getenv('AUTOQA_DOCKER_FIXTURE');
        $this->assertIsString($metadata, 'AUTOQA_DOCKER_FIXTURE must name the prepared fixture JSON.');
        $this->assertFileExists($metadata);
        $this->fixture = json_decode(file_get_contents($metadata), true, flags: JSON_THROW_ON_ERROR);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{40}\z/', $this->fixture['source_sha']);
        $this->assertFileExists($this->fixture['archive']);
        config([
            'autoqa.runner_image' => $this->fixture['image'],
            'autoqa.preview_origin' => 'http://127.0.0.1:8081',
            'autoqa.runner_timeout' => 240,
        ]);
        Http::preventStrayRequests();
        Http::allowStrayRequests([
            'http://127.0.0.1:3001/*',
            'http://127.0.0.1:8081/*',
        ]);
        $this->assertTrue(app(BrowserWorker::class)->health()['ready'] ?? false, 'Start the authenticated local Chromium worker first.');
        $port = @stream_socket_server('tcp://127.0.0.1:8081', $errorNumber, $errorMessage);
        $this->assertNotFalse($port, 'The isolated preview port 8081 must be free before this test.');
        fclose($port);
    }

    public function test_real_containers_and_browser_pass_all_six_gates_for_optional_phone_repair(): void
    {
        $project = Project::factory()->local()->create([
            'website_url' => 'http://127.0.0.1:8082/register',
            'repository_url' => 'https://github.com/example/disposable-repair-demo',
        ]);
        $run = QaRun::factory()->for($project)->create([
            'status' => 'repairing',
            'base_sha' => $this->fixture['source_sha'],
            'report' => ['control_actions' => $this->journey(true)],
        ]);
        $files = $this->patchFiles();
        $directoriesBefore = glob(storage_path('app/qa/run-'.$run->id.'-*')) ?: [];
        $containersBefore = $this->containers();

        $result = (new RepairSandbox($this->localArchiveClient(), app(BrowserWorker::class)))
            ->verify($run, $files, 'tests/Feature/OptionalPhoneRegressionTest.php', $this->journey(false));

        $this->assertTrue($result['passed'], $result['message']);
        foreach (['baseline_suite', 'baseline_regression_assertion', 'patched_regression', 'patched_suite', 'original_replay', 'control_replay'] as $gate) {
            $this->assertTrue($result[$gate], $gate.' did not pass.');
        }
        $this->assertSame($this->fixture['source_sha'], $result['source_sha']);
        $this->assertSame(hash('sha256', json_encode($files)), $result['patch_sha256']);
        $this->assertSame('assertion_failed', $result['checks']['baseline_regression_assertion']['status']);
        $this->assertSame(0, $result['checks']['baseline_regression_assertion']['errors']);
        $this->assertSame(2, $result['checks']['original_replay']['assertions']);
        $this->assertSame(2, $result['checks']['control_replay']['assertions']);
        $this->assertSame([], array_values(array_diff($this->containers(), $containersBefore)));
        $this->assertSame([], array_values(array_diff(glob(storage_path('app/qa/run-'.$run->id.'-*')) ?: [], $directoriesBefore)));
        $this->assertSame(0, app(BrowserWorker::class)->health()['active_sessions']);
        $socket = @stream_socket_client('tcp://127.0.0.1:8081', $errorNumber, $errorMessage, .2);
        $this->assertFalse($socket, 'The temporary preview proxy must be stopped after verification.');
    }

    public function test_real_runner_rejects_prepared_source_identity_mismatch_and_cleans_up(): void
    {
        $project = Project::factory()->local()->create([
            'website_url' => 'http://127.0.0.1:8082/register',
            'repository_url' => 'https://github.com/example/disposable-repair-demo',
        ]);
        $run = QaRun::factory()->for($project)->create([
            'status' => 'repairing',
            'base_sha' => str_repeat('f', 40),
            'report' => ['control_actions' => $this->journey(true)],
        ]);
        $containersBefore = $this->containers();

        $result = (new RepairSandbox($this->localArchiveClient($run->base_sha), app(BrowserWorker::class)))
            ->verify($run, $this->patchFiles(), 'tests/Feature/OptionalPhoneRegressionTest.php', $this->journey(false));

        $this->assertFalse($result['passed']);
        $this->assertFalse($result['baseline_suite']);
        $this->assertSame(0, $result['checks']['baseline_suite']['tests']);
        $this->assertSame(1, $result['checks']['baseline_suite']['errors']);
        $this->assertArrayNotHasKey('original_replay', $result['checks']);
        $this->assertSame([], array_values(array_diff($this->containers(), $containersBefore)));
        $this->assertSame(0, app(BrowserWorker::class)->health()['active_sessions']);
    }

    #[DataProvider('invalidBaselinePreviews')]
    public function test_real_baseline_preview_rejects_patch_regression_and_digest_inputs(array $overrides): void
    {
        $directory = storage_path('app/qa/baseline-rejection-'.Str::uuid());
        $this->assertTrue(mkdir($directory, 0700, true));
        $payloadPath = $directory.'/payload.json';
        $archivePath = $directory.'/source.tar.gz';
        $this->assertTrue(File::copy($this->fixture['archive'], $archivePath));
        chmod($archivePath, 0644);
        $payload = array_merge([
            'source_sha' => $this->fixture['source_sha'], 'patch_sha256' => hash('sha256', '[]'),
            'files' => [], 'test' => '', 'preview_origin' => 'http://127.0.0.1:8082', 'test_timeout' => 30,
        ], $overrides);
        file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR));
        chmod($payloadPath, 0644);
        $name = 'autoqa-'.Str::uuid();
        $containersBefore = $this->containers();

        try {
            $process = Process::timeout(90)->run([
                'docker', 'run', '--rm', '--pull', 'never', '--name', $name,
                '--read-only', '--network', 'none', '--memory', '2g', '--memory-swap', '2g',
                '--cpus', '1', '--pids-limit', '128', '--cap-drop', 'ALL',
                '--security-opt', 'no-new-privileges', '--user', '1000:1000',
                '--tmpfs', '/workspace:rw,noexec,nosuid,nodev,size=512m,uid=1000,gid=1000',
                '--tmpfs', '/tmp:rw,noexec,nosuid,nodev,size=64m,uid=1000,gid=1000',
                '--mount', 'type=bind,source='.$archivePath.',target=/input/source.tar.gz,readonly',
                '--mount', 'type=bind,source='.$payloadPath.',target=/input/payload.json,readonly',
                '--entrypoint', 'php', $this->fixture['image'], '/opt/autoqa/runner.php', 'baseline_preview',
            ]);

            $this->assertTrue($process->successful(), 'The trusted runner must return its bounded rejection report. '.mb_substr($process->errorOutput().$process->output(), 0, 1000));
            $this->assertSame(1, preg_match('/^AUTOQA_RESULT:(.+)$/m', $process->output(), $match));
            $report = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('baseline_preview', $report['phase']);
            $this->assertSame('failed', $report['status']);
            $this->assertSame(0, $report['tests']);
            $this->assertSame(1, $report['errors']);
        } finally {
            Process::timeout(10)->run(['docker', 'rm', '--force', $name]);
            unlink($payloadPath);
            unlink($archivePath);
            rmdir($directory);
        }

        $this->assertSame([], array_values(array_diff($this->containers(), $containersBefore)));
    }

    public static function invalidBaselinePreviews(): array
    {
        return [
            'application patch' => [['files' => ['app/Http/Controllers/RegistrationController.php' => '<?php // forbidden baseline change']]],
            'regression file name' => [['test' => 'tests/Feature/UnexpectedTest.php']],
            'different patch identity' => [['patch_sha256' => str_repeat('0', 64)]],
        ];
    }

    private function localArchiveClient(?string $requestedSha = null): GitHubAppClient
    {
        $client = Mockery::mock(GitHubAppClient::class);
        $client->shouldReceive('archiveSnapshot')
            ->once()
            ->with('https://github.com/example/disposable-repair-demo', $requestedSha ?? $this->fixture['source_sha'], Mockery::type('string'))
            ->andReturnUsing(function (string $url, string $sha, string $destination): void {
                if (! File::copy($this->fixture['archive'], $destination)) {
                    throw new RuntimeException('The prepared local source archive could not be copied.');
                }
            });

        return $client;
    }

    /** @return array<string, string> */
    private function patchFiles(): array
    {
        $source = file_get_contents(base_path('tests/Fixtures/repair-demo/app/Http/Controllers/RegistrationController.php'));
        $original = '$phone = $this->normalizePhone($validated[\'phone\'] ?? null);';
        $replacement = '$rawPhone = $validated[\'phone\'] ?? null;'."\n        ".'$phone = $rawPhone === null ? null : $this->normalizePhone($rawPhone);';
        $this->assertSame(1, substr_count($source, $original), 'The demonstration source must still contain the seeded optional-phone bug.');

        return [
            'app/Http/Controllers/RegistrationController.php' => str_replace($original, $replacement, $source),
            'tests/Feature/OptionalPhoneRegressionTest.php' => <<<'REGRESSION'
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptionalPhoneRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_without_phone_succeeds_and_can_sign_in(): void
    {
        $response = $this->post('/register', [
            'name' => 'Optional Phone Student',
            'email' => 'optional@example.test',
            'password' => 'Demo-password-2026',
            'password_confirmation' => 'Demo-password-2026',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertDatabaseHas('users', ['email' => 'optional@example.test', 'phone' => null]);
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login', [
            'email' => 'optional@example.test',
            'password' => 'Demo-password-2026',
        ])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertSee('Account dashboard');
    }
}
REGRESSION,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function journey(bool $withPhone): array
    {
        $actions = [
            $this->fill('Name', '', 'QA Student'),
            $this->fill('Email', 'email', 'qa-student@example.test'),
        ];
        if ($withPhone) {
            $actions[] = $this->fill('Phone', 'tel', '+62 812 3456');
        }

        return [
            ...$actions,
            $this->fill('Password', 'password', 'Demo-password-2026'),
            $this->fill('Confirm password', 'password', 'Demo-password-2026'),
            $this->click('Register'),
            ['tool' => 'assert_text', 'text' => 'Account dashboard'],
            $this->click('Sign out'),
            $this->fill('Email', 'email', 'qa-student@example.test'),
            $this->fill('Password', 'password', 'Demo-password-2026'),
            $this->click('Sign in'),
            ['tool' => 'assert_text', 'text' => 'You are signed in.'],
        ];
    }

    /** @return array{tool: string, target: array{tag: string, label: string, type: string}, value: string} */
    private function fill(string $label, string $type, string $value): array
    {
        return ['tool' => 'fill', 'target' => ['tag' => 'input', 'label' => $label, 'type' => $type], 'value' => $value];
    }

    /** @return array{tool: string, target: array{tag: string, text: string, type: string}} */
    private function click(string $text): array
    {
        return ['tool' => 'click', 'target' => ['tag' => 'button', 'text' => $text, 'type' => 'submit']];
    }

    /** @return list<string> */
    private function containers(): array
    {
        $result = Process::timeout(10)->run(['docker', 'ps', '--all', '--filter', 'name=autoqa-', '--format', '{{.Names}}']);
        $this->assertTrue($result->successful(), 'The Docker daemon must be accessible to the test process.');

        return array_values(array_filter(explode("\n", trim($result->output()))));
    }
}
