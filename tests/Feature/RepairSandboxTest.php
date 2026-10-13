<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\QaRun;
use App\Services\BrowserWorker;
use App\Services\GitHub\GitHubAppClient;
use App\Services\RepairSandbox;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RepairSandboxTest extends TestCase
{
    private const BASE = '1111111111111111111111111111111111111111';

    private const IMAGE = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('autoqa.runner_image', 'autoqa-target:demo');
        config()->set('autoqa.preview_origin', 'http://127.0.0.1:8081');
        config()->set('autoqa.runner_timeout', 240);
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
    }

    public function test_readiness_checks_configuration_without_launching_a_process(): void
    {
        Process::fake();
        $sandbox = new RepairSandbox(Mockery::mock(GitHubAppClient::class), Mockery::mock(BrowserWorker::class));

        $this->assertTrue($sandbox->ready());
        config()->set('autoqa.runner_image', '');
        $this->assertFalse($sandbox->ready());
        Process::assertNothingRan();
    }

    public function test_missing_independent_control_stops_before_source_download_or_execution(): void
    {
        Process::fake();
        $run = $this->runModel();
        $run->report = [];
        $github = Mockery::mock(GitHubAppClient::class);
        $github->shouldNotReceive('archiveSnapshot');
        $browser = Mockery::mock(BrowserWorker::class);
        $browser->shouldNotReceive('start');

        $result = (new RepairSandbox($github, $browser))->verify($run, $this->patchFiles(), $this->regressionPath(), $this->original());

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('independent control', $result['message']);
        Process::assertNothingRan();
    }

    public function test_a_replay_without_a_success_assertion_is_not_verified(): void
    {
        Process::fake();
        $sandbox = new RepairSandbox(Mockery::mock(GitHubAppClient::class), Mockery::mock(BrowserWorker::class));

        $result = $sandbox->verify($this->runModel(), $this->patchFiles(), $this->regressionPath(), [$this->original()[0]]);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('explicit success assertion', $result['message']);
        Process::assertNothingRan();
    }

    public function test_an_unavailable_docker_image_fails_without_reaching_github(): void
    {
        Process::fake(['*docker*image*inspect*' => Process::result(errorOutput: 'Cannot connect to Docker', exitCode: 1)]);
        $github = Mockery::mock(GitHubAppClient::class);
        $github->shouldNotReceive('archiveSnapshot');

        $result = (new RepairSandbox($github, Mockery::mock(BrowserWorker::class)))->verify($this->runModel(), $this->patchFiles(), $this->regressionPath(), $this->original());

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('unavailable', $result['message']);
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['docker', 'image', 'inspect', '--format', '{{.Id}}', 'autoqa-target:demo']);
    }

    public function test_passes_only_after_all_six_gates_and_uses_fresh_controls_and_containers(): void
    {
        $this->fakeProcesses();
        $this->fakePreviewHealth();
        $paths = [];
        $ids = [];
        $github = $this->archiveClient($paths);
        $browser = $this->browserClient($ids);

        $result = (new RepairSandbox($github, $browser))->verify($this->runModel(), $this->patchFiles(), $this->regressionPath(), $this->original());

        $this->assertTrue($result['passed'], $result['message']);
        foreach (['baseline_suite', 'baseline_regression_assertion', 'patched_regression', 'patched_suite', 'original_replay', 'control_replay'] as $gate) {
            $this->assertTrue($result[$gate]);
        }
        $this->assertSame(self::BASE, $result['source_sha']);
        $this->assertSame(hash('sha256', json_encode($this->patchFiles())), $result['patch_sha256']);
        $this->assertSame(['fresh-original-register', 'fresh-control-signin'], $ids);
        $this->assertFileDoesNotExist($paths[0]);
        $this->assertDirectoryDoesNotExist(dirname($paths[0]));
        Process::assertRanTimes(fn (PendingProcess $process) => is_array($process->command)
            && array_slice($process->command, 0, 2) === ['docker', 'run'], 6);
        Process::assertRanTimes(fn (PendingProcess $process) => is_array($process->command)
            && array_slice($process->command, 0, 3) === ['docker', 'rm', '--force'], 6);
        Process::assertRan(function (PendingProcess $process): bool {
            $command = $process->command;
            if (! is_array($command) || array_slice($command, 0, 2) !== ['docker', 'run']) {
                return false;
            }
            $value = fn (string $flag) => $command[array_search($flag, $command, true) + 1];

            return in_array('--read-only', $command, true) && $value('--network') === 'none'
                && $value('--memory') === '2g' && $value('--cpus') === '1'
                && $value('--pids-limit') === '128' && $value('--cap-drop') === 'ALL'
                && $value('--security-opt') === 'no-new-privileges' && $value('--user') === '1000:1000'
                && in_array(self::IMAGE, $command, true)
                && ! in_array('--privileged', $command, true) && ! in_array('--publish', $command, true)
                && count(array_filter($command, fn ($argument) => is_string($argument) && str_contains($argument, 'target=/input/') && str_ends_with($argument, ',readonly'))) === 2;
        });
    }

    public function test_regression_runtime_errors_do_not_count_as_expected_baseline_assertion_failure(): void
    {
        $this->fakeProcesses(['baseline_regression_assertion' => ['status' => 'assertion_failed', 'tests' => 1, 'failures' => 1, 'errors' => 1]]);
        $paths = [];
        $browser = Mockery::mock(BrowserWorker::class);
        $browser->shouldNotReceive('start');

        $result = (new RepairSandbox($this->archiveClient($paths), $browser))->verify($this->runModel(), $this->patchFiles(), $this->regressionPath(), $this->original());

        $this->assertTrue($result['baseline_suite']);
        $this->assertFalse($result['baseline_regression_assertion']);
        $this->assertFalse($result['passed']);
        $this->assertFileDoesNotExist($paths[0]);
        Process::assertNotRan(fn (PendingProcess $process) => is_array($process->command) && in_array('preview', $process->command, true));
    }

    public function test_existing_baseline_failures_stop_patch_testing_and_cleanup_the_container(): void
    {
        $this->fakeProcesses(['baseline_suite' => ['status' => 'failed', 'tests' => 1, 'failures' => 1, 'errors' => 0]]);
        $paths = [];

        $result = (new RepairSandbox($this->archiveClient($paths), Mockery::mock(BrowserWorker::class)))->verify($this->runModel(), $this->patchFiles(), $this->regressionPath(), $this->original());

        $this->assertFalse($result['baseline_suite']);
        $this->assertFalse($result['patched_suite']);
        $this->assertFileDoesNotExist($paths[0]);
        Process::assertRan(fn (PendingProcess $process) => is_array($process->command) && array_slice($process->command, 0, 3) === ['docker', 'rm', '--force']);
        Process::assertNotRan(fn (PendingProcess $process) => is_array($process->command) && in_array('patched_regression', $process->command, true));
    }

    public function test_runner_results_for_another_source_commit_are_rejected(): void
    {
        $this->fakeProcesses(['baseline_suite' => ['source_sha' => str_repeat('9', 40)]]);
        $paths = [];

        $result = (new RepairSandbox($this->archiveClient($paths), Mockery::mock(BrowserWorker::class)))->verify($this->runModel(), $this->patchFiles(), $this->regressionPath(), $this->original());

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('did not match', $result['message']);
        $this->assertFileDoesNotExist($paths[0]);
    }

    public function test_ambiguous_fresh_controls_fail_closed_and_close_the_browser(): void
    {
        $this->fakeProcesses();
        $this->fakePreviewHealth();
        $paths = [];
        $browser = Mockery::mock(BrowserWorker::class);
        $duplicate = ['tag' => 'button', 'role' => '', 'label' => 'Register', 'text' => 'Register', 'type' => '', 'disabled' => false];
        $browser->shouldReceive('start')->once()->andReturn(['session_id' => 'original', 'snapshot' => ['elements' => [
            ['id' => 'fresh-1', ...$duplicate], ['id' => 'fresh-2', ...$duplicate],
        ]]]);
        $browser->shouldNotReceive('act');
        $browser->shouldReceive('close')->with('original')->once()->andReturn([]);

        $result = (new RepairSandbox($this->archiveClient($paths), $browser))->verify($this->runModel(), $this->patchFiles(), $this->regressionPath(), $this->original());

        $this->assertFalse($result['original_replay']);
        $this->assertFalse($result['control_replay']);
        $this->assertStringContainsString('exactly one', $result['message']);
        Process::assertRanTimes(fn (PendingProcess $process) => is_array($process->command) && in_array('preview', $process->command, true), 1);
    }

    public function test_a_failed_normal_control_prevents_verification_after_original_replay_passes(): void
    {
        $this->fakeProcesses();
        $this->fakePreviewHealth();
        $paths = [];
        $ids = [];

        $result = (new RepairSandbox($this->archiveClient($paths), $this->browserClient($ids, false)))->verify($this->runModel(), $this->patchFiles(), $this->regressionPath(), $this->original());

        $this->assertTrue($result['original_replay']);
        $this->assertFalse($result['control_replay']);
        $this->assertFalse($result['passed']);
        Process::assertRanTimes(fn (PendingProcess $process) => is_array($process->command) && in_array('preview', $process->command, true), 2);
    }

    public function test_unexpected_server_error_in_the_patched_preview_prevents_replay(): void
    {
        $this->fakeProcesses();
        $this->fakePreviewHealth();
        $paths = [];
        $browser = Mockery::mock(BrowserWorker::class);
        $browser->shouldReceive('start')->once()->andReturn(['session_id' => 'original', 'snapshot' => ['elements' => []], 'network_errors' => [['status' => 500]]]);
        $browser->shouldNotReceive('act');
        $browser->shouldReceive('close')->with('original')->once()->andReturn([]);

        $result = (new RepairSandbox($this->archiveClient($paths), $browser))->verify($this->runModel(), $this->patchFiles(), $this->regressionPath(), $this->original());

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('server failure', $result['message']);
        $this->assertFileDoesNotExist($paths[0]);
    }

    public function test_replay_urls_cannot_switch_to_a_different_website(): void
    {
        Process::fake();
        $actions = $this->original();
        array_unshift($actions, ['tool' => 'navigate', 'url' => 'https://other.example/private']);

        $result = (new RepairSandbox(Mockery::mock(GitHubAppClient::class), Mockery::mock(BrowserWorker::class)))->verify($this->runModel(), $this->patchFiles(), $this->regressionPath(), $actions);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('different website origin', $result['message']);
        Process::assertNothingRan();
    }

    public function test_patch_path_traversal_is_refused_before_any_execution(): void
    {
        Process::fake();
        $patch = $this->patchFiles();
        $patch['app/../../.env.php'] = '<?php';

        $result = (new RepairSandbox(Mockery::mock(GitHubAppClient::class), Mockery::mock(BrowserWorker::class)))->verify($this->runModel(), $patch, $this->regressionPath(), $this->original());

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('repair scope', $result['message']);
        Process::assertNothingRan();
    }

    public function test_snapshot_download_failure_does_not_launch_a_container(): void
    {
        $this->fakeProcesses();
        $github = Mockery::mock(GitHubAppClient::class);
        $github->shouldReceive('archiveSnapshot')->once()->andThrow(new RuntimeException('Repository access is missing.'));

        $result = (new RepairSandbox($github, Mockery::mock(BrowserWorker::class)))->verify($this->runModel(), $this->patchFiles(), $this->regressionPath(), $this->original());

        $this->assertFalse($result['passed']);
        $this->assertSame('Repository access is missing.', $result['message']);
        Process::assertNotRan(fn (PendingProcess $process) => is_array($process->command) && array_slice($process->command, 0, 2) === ['docker', 'run']);
    }

    public function test_an_unmatched_preview_identity_never_reaches_the_browser_and_is_cleaned_up(): void
    {
        $this->fakeProcesses();
        Sleep::fake();
        Http::fake(['http://127.0.0.1:8081/__autoqa_health' => Http::response(['source_sha' => str_repeat('9', 40), 'patch_sha256' => 'wrong'])]);
        $paths = [];
        $browser = Mockery::mock(BrowserWorker::class);
        $browser->shouldNotReceive('start');

        $result = (new RepairSandbox($this->archiveClient($paths), $browser))->verify($this->runModel(), $this->patchFiles(), $this->regressionPath(), $this->original());

        $this->assertFalse($result['passed']);
        $this->assertFalse($result['original_replay']);
        $this->assertStringContainsString('expected source and patch identity', $result['message']);
        $this->assertFileDoesNotExist($paths[0]);
        Process::assertRanTimes(fn (PendingProcess $process) => is_array($process->command) && array_slice($process->command, 0, 3) === ['docker', 'rm', '--force'], 5);
    }

    public function test_skipped_only_tests_cannot_establish_a_passing_baseline(): void
    {
        $this->fakeProcesses(['baseline_suite' => ['status' => 'passed', 'tests' => 1, 'failures' => 0, 'errors' => 0, 'skipped' => 1]]);
        $paths = [];

        $result = (new RepairSandbox($this->archiveClient($paths), Mockery::mock(BrowserWorker::class)))->verify($this->runModel(), $this->patchFiles(), $this->regressionPath(), $this->original());

        $this->assertFalse($result['baseline_suite']);
        $this->assertFalse($result['passed']);
        Process::assertNotRan(fn (PendingProcess $process) => is_array($process->command) && in_array('baseline_regression_assertion', $process->command, true));
    }

    private function runModel(): QaRun
    {
        $run = new QaRun(['id' => 12, 'base_sha' => self::BASE, 'report' => ['control_actions' => $this->control()]]);
        $run->setRelation('project', new Project(['website_url' => 'https://example.com/register', 'repository_url' => 'https://github.com/student/demo']));

        return $run;
    }

    private function regressionPath(): string
    {
        return 'tests/Feature/OptionalPhoneTest.php';
    }

    private function patchFiles(): array
    {
        return ['app/Registration.php' => '<?php // repaired application', $this->regressionPath() => '<?php // new regression test'];
    }

    private function original(): array
    {
        return [['tool' => 'click', 'id' => 'stale-original-id', 'target' => ['tag' => 'button', 'role' => '', 'label' => 'Register', 'text' => 'Register', 'type' => '']],
            ['tool' => 'assert_text', 'text' => 'Welcome']];
    }

    private function control(): array
    {
        return [['tool' => 'click', 'target' => ['tag' => 'button', 'role' => '', 'label' => 'Sign in', 'text' => 'Sign in', 'type' => '']],
            ['tool' => 'assert_text', 'text' => 'Dashboard']];
    }

    private function archiveClient(array &$paths): GitHubAppClient
    {
        $client = Mockery::mock(GitHubAppClient::class);
        $client->shouldReceive('archiveSnapshot')->with('https://github.com/student/demo', self::BASE, Mockery::type('string'))->once()
            ->andReturnUsing(function ($url, $sha, $destination) use (&$paths): void {
                $paths[] = $destination;
                file_put_contents($destination, gzencode('fake isolated source archive'));
            });

        return $client;
    }

    private function browserClient(array &$ids, bool $controlPasses = true): BrowserWorker
    {
        $client = Mockery::mock(BrowserWorker::class);
        $snapshot = function (string $session): array {
            return ['url' => 'http://127.0.0.1:8081/register', 'elements' => [
                ['id' => 'fresh-'.$session.'-register', 'tag' => 'button', 'role' => '', 'label' => 'Register', 'text' => 'Register', 'type' => '', 'disabled' => false],
                ['id' => 'fresh-'.$session.'-signin', 'tag' => 'button', 'role' => '', 'label' => 'Sign in', 'text' => 'Sign in', 'type' => '', 'disabled' => false],
            ]];
        };
        $client->shouldReceive('start')->with('http://127.0.0.1:8081/register', 'local')->twice()->andReturn(
            ['session_id' => 'original', 'snapshot' => $snapshot('original')], ['session_id' => 'control', 'snapshot' => $snapshot('control')]);
        $client->shouldReceive('act')->times(4)->andReturnUsing(function (string $session, array $action) use (&$ids, $snapshot, $controlPasses): array {
            if ($action['tool'] === 'click') {
                $ids[] = $action['id'];
            }

            return ['snapshot' => $snapshot($session), 'network_errors' => [],
                ...($action['tool'] === 'assert_text' ? ['assertion' => ['passed' => $session === 'original' || $controlPasses]] : [])];
        });
        $client->shouldReceive('close')->with('original')->once()->andReturn([]);
        $client->shouldReceive('close')->with('control')->once()->andReturn([]);

        return $client;
    }

    private function fakePreviewHealth(): void
    {
        Http::fake(['http://127.0.0.1:8081/__autoqa_health' => Http::response([
            'source_sha' => self::BASE, 'patch_sha256' => hash('sha256', json_encode($this->patchFiles())),
        ])]);
    }

    private function fakeProcesses(array $overrides = []): void
    {
        Process::fake(function (PendingProcess $process) use ($overrides) {
            $command = $process->command;
            if (is_array($command) && array_slice($command, 0, 3) === ['docker', 'image', 'inspect']) {
                return Process::result(output: self::IMAGE);
            }
            if (is_array($command) && array_slice($command, 0, 2) === ['docker', 'run']) {
                $phase = end($command);
                if ($phase === 'preview') {
                    return Process::result(output: str_repeat('b', 64));
                }
                $result = ['phase' => $phase, 'source_sha' => self::BASE,
                    'patch_sha256' => hash('sha256', json_encode($this->patchFiles())),
                    'status' => $phase === 'baseline_regression_assertion' ? 'assertion_failed' : 'passed',
                    'tests' => 1, 'failures' => $phase === 'baseline_regression_assertion' ? 1 : 0, 'errors' => 0, 'skipped' => 0];

                return Process::result(output: 'AUTOQA_RESULT:'.json_encode(array_replace($result, $overrides[$phase] ?? [])));
            }
            if (is_array($command) && (array_slice($command, 0, 3) === ['docker', 'rm', '--force'] || ($command[0] === PHP_BINARY && $command[1] === '-S'))) {
                return Process::result();
            }

            throw new RuntimeException('Unexpected process in sandbox test.');
        });
    }
}
