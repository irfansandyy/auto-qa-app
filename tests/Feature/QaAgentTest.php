<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\QaRun;
use App\Services\BrowserWorker;
use App\Services\GitHub\GitHubAppClient;
use App\Services\ModelClient;
use App\Services\QaAgent;
use App\Services\RepairSandbox;
use App\Services\TargetPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QaAgentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const BASE_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SOURCE_PATH = 'app/Http/Controllers/RegistrationController.php';

    private const TEST_PATH = 'tests/Feature/OptionalPhoneRegressionTest.php';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('autoqa.max_steps', 6);
    }

    public function test_completes_a_journey_after_a_deterministic_assertion_passes(): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        $snapshot = $this->snapshot($project);
        [$browser, $model, $github] = $this->dependencies($run, $snapshot);
        $model->shouldReceive('decide')->twice()->andReturn(
            ['tool' => 'assert_text', 'text' => 'Account created'],
            ['tool' => 'finish', 'outcome' => 'passed', 'summary' => 'The expected account confirmation appeared.'],
        );
        $browser->shouldReceive('act')->once()->with('browser-session', ['tool' => 'assert_text', 'text' => 'Account created'])
            ->andReturn(['snapshot' => $snapshot, 'assertion' => ['type' => 'text', 'expected' => 'Account created', 'passed' => true], 'network_errors' => []]);
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(0, $run->findings()->count());
        $this->assertNotNull($run->finished_at);
        $this->assertDatabaseHas('run_steps', ['run_id' => $run->id, 'kind' => 'action']);
        $this->assertNull($run->pr_url);
    }

    public function test_blocks_a_model_completion_claim_without_an_observed_assertion(): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        [$browser, $model, $github] = $this->dependencies($run, $this->snapshot($project));
        $model->shouldReceive('decide')->once()->andReturn(['tool' => 'finish', 'outcome' => 'passed', 'summary' => 'Everything works.']);
        $browser->shouldNotReceive('act');
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertNull($run->fresh()->pr_url);
    }

    public function test_preserves_an_initial_server_error_without_requesting_model_or_repository_access(): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        $snapshot = $this->snapshot($project);
        [$browser, $model, $github] = $this->dependencies($run, $snapshot, [['url' => $snapshot['url'], 'status' => 500]]);
        $model->shouldNotReceive('decide');
        $github->shouldNotReceive('inspectRepository');
        $this->expectScreenshot($browser, $snapshot);
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('needs_configuration', $run->status);
        $this->assertSame(1, $run->findings()->count());
        $this->assertSame('high', $run->findings()->first()->severity);
        $this->assertSame(500, $run->report['failure']['network_errors'][0]['status']);
        $this->assertNull($run->pr_url);
    }

    public function test_preserves_a_failed_assertion_when_the_deployed_source_identity_is_missing(): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        $snapshot = $this->snapshot($project);
        [$browser, $model, $github] = $this->dependencies($run, $snapshot);
        $this->expectFailedAssertion($browser, $model, $snapshot);
        $github->shouldNotReceive('inspectRepository');
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('needs_configuration', $run->status);
        $this->assertSame(1, $run->findings()->count());
        $this->assertFalse($run->report['failure']['assertion']['passed']);
        $this->assertSame('browser-evidence-1.png', $run->report['failure']['artifact']);
        $this->assertNull($run->base_sha);
    }

    public function test_blocks_repair_when_the_website_header_conflicts_with_the_configured_commit(): void
    {
        $project = Project::factory()->local()->publishing()->create();
        $run = QaRun::factory()->for($project)->create();
        $snapshot = array_merge($this->snapshot($project), ['source_sha' => str_repeat('b', 40)]);
        [$browser, $model, $github] = $this->dependencies($run, $snapshot);
        $this->expectFailedAssertion($browser, $model, $snapshot);
        $github->shouldNotReceive('inspectRepository');
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertSame(1, $run->findings()->count());
        $this->assertNull($run->fresh()->base_sha);
    }

    public function test_password_values_are_hidden_in_saved_reports_and_steps(): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        $snapshot = $this->snapshot($project);
        [$browser, $model, $github] = $this->dependencies($run, $snapshot);
        $password = 'disposable-QA-password-7921';
        $model->shouldReceive('decide')->times(3)->andReturn(
            ['tool' => 'fill', 'id' => 'e1-1', 'value' => $password],
            ['tool' => 'assert_text', 'text' => 'Account created'],
            ['tool' => 'finish', 'outcome' => 'passed', 'summary' => 'Confirmation checked.'],
        );
        $browser->shouldReceive('act')->once()->with('browser-session', ['tool' => 'fill', 'id' => 'e1-1', 'value' => $password])
            ->andReturn(['snapshot' => $snapshot, 'network_errors' => []]);
        $browser->shouldReceive('act')->once()->with('browser-session', ['tool' => 'assert_text', 'text' => 'Account created'])
            ->andReturn(['snapshot' => $snapshot, 'assertion' => ['passed' => true], 'network_errors' => []]);
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertStringNotContainsString($password, json_encode($run->report));
        $this->assertStringNotContainsString($password, $run->steps()->get()->toJson());
        $this->assertSame('[QA password hidden]', $run->report['actions'][0]['value']);
    }

    #[DataProvider('incompleteVerification')]
    public function test_does_not_publish_when_any_required_verification_evidence_is_missing_or_wrong(array $overrides): void
    {
        $project = Project::factory()->local()->publishing()->create();
        $run = QaRun::factory()->for($project)->create();
        [$github, $files] = $this->prepareRepair($run, $overrides);
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('verification_failed', $run->status);
        $this->assertSame($files, $run->patch_files);
        $this->assertSame(1, $run->findings()->count());
        $this->assertNull($run->pr_url);
    }

    public static function incompleteVerification(): array
    {
        return [
            'verification failed' => [['passed' => false]],
            'baseline suite missing' => [['baseline_suite' => null]],
            'regression did not fail by assertion' => [['baseline_regression_assertion' => false]],
            'patched regression failed' => [['patched_regression' => false]],
            'patched suite failed' => [['patched_suite' => false]],
            'original journey failed' => [['original_replay' => false]],
            'normal control journey failed' => [['control_replay' => false]],
            'source commit mismatch' => [['source_sha' => str_repeat('b', 40)]],
            'patch digest mismatch' => [['patch_sha256' => str_repeat('0', 64)]],
            'truthy claim is not a boolean pass' => [['passed' => 'true']],
        ];
    }

    public function test_publishes_one_repair_branch_and_draft_pr_only_after_all_verification_gates_pass(): void
    {
        $project = Project::factory()->local()->publishing()->create();
        $run = QaRun::factory()->for($project)->create();
        [$github, $files] = $this->prepareRepair($run);
        $commit = str_repeat('c', 40);
        $publishedBranch = null;
        $github->shouldReceive('commitRepair')->once()->withArgs(function ($repository, $base, $branch, $patch, $message) use ($project, $run, $files, &$publishedBranch): bool {
            $publishedBranch = $branch;

            return $repository === $project->repository_url && $base === self::BASE_SHA
                && str_starts_with($branch, 'agent/fix-'.$run->id.'-') && $patch === $files
                && $message === 'Repair QA finding #'.$run->id;
        })->andReturnUsing(fn ($repository, $base, $branch) => ['branch' => $branch, 'sha' => $commit]);
        $github->shouldReceive('createDraftPullRequest')->once()->withArgs(function ($repository, $branch, $base, $title, $body) use ($project, $run, $commit, &$publishedBranch): bool {
            return $repository === $project->repository_url && $branch === $publishedBranch && $base === 'main'
                && $title === 'Repair QA finding #'.$run->id && str_contains($body, self::BASE_SHA) && str_contains($body, $commit);
        })
            ->andReturn(['number' => 7, 'html_url' => 'https://github.com/example/laravel-demo/pull/7', 'draft' => true]);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame('https://github.com/example/laravel-demo/pull/7', $run->pr_url);
        $this->assertSame($commit, $run->report['commit_sha']);
        $this->assertSame($publishedBranch, $run->report['branch']);
        $this->assertTrue($run->verification['control_replay']);
        $this->assertSame($files, $run->patch_files);
    }

    public function test_a_cancelled_run_does_not_start_any_external_operation(): void
    {
        $run = QaRun::factory()->create(['status' => 'cancelled']);
        $this->mock(BrowserWorker::class)->shouldNotReceive('start');
        $this->mock(ModelClient::class)->shouldNotReceive('decide');
        $this->mock(TargetPolicy::class)->shouldNotReceive('assertAllowed');
        $this->expectNoPublication($this->mock(GitHubAppClient::class));

        app(QaAgent::class)->run($run->id);

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(0, $run->steps()->count());
    }

    public function test_cancellation_during_target_validation_preserves_the_cancelled_result_before_browser_start(): void
    {
        $this->freezeTime();
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        $cancelledReport = ['summary' => 'Stopped by the user.', 'actions' => []];
        $cancelledAt = now()->subMinute()->startOfSecond();
        $this->mock(TargetPolicy::class)->shouldReceive('assertAllowed')->once()->with($project->website_url, $project->target_mode)
            ->andReturnUsing(function () use ($run, $cancelledReport, $cancelledAt): void {
                $run->update(['status' => 'cancelled', 'report' => $cancelledReport, 'finished_at' => $cancelledAt]);
            });
        $browser = $this->mock(BrowserWorker::class);
        foreach (['start', 'act', 'close', 'snapshot', 'screenshot'] as $method) {
            $browser->shouldNotReceive($method);
        }
        $this->mock(ModelClient::class)->shouldNotReceive('decide');
        $github = $this->mock(GitHubAppClient::class);
        foreach (['inspectRepository', 'repositoryTree', 'readFiles', 'archiveSnapshot', 'commitRepair', 'createDraftPullRequest'] as $method) {
            $github->shouldNotReceive($method);
        }

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('cancelled', $run->status);
        $this->assertSame($cancelledReport, $run->report);
        $this->assertTrue($run->finished_at->equalTo($cancelledAt));
        $this->assertNull($run->started_at);
        $this->assertSame(0, $run->steps()->count());
        $this->assertSame(0, $run->findings()->count());
        $this->assertNull($run->pr_url);
    }

    #[DataProvider('decisionsWhileCancelled')]
    public function test_cancellation_during_model_inference_prevents_actions_and_completion(array $decision): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        [$browser, $model, $github] = $this->dependencies($run, $this->snapshot($project));
        $model->shouldReceive('decide')->once()->andReturnUsing(function () use ($run, $decision): array {
            $run->update(['status' => 'cancelled']);

            return $decision;
        });
        $browser->shouldNotReceive('act');
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(0, $run->findings()->count());
        $this->assertSame(0, $run->steps()->where('kind', 'action')->count());
    }

    public static function decisionsWhileCancelled(): array
    {
        return [
            'pending assertion' => [['tool' => 'assert_text', 'text' => 'Account created']],
            'pending completion claim' => [['tool' => 'finish', 'outcome' => 'passed', 'summary' => 'Everything works.']],
        ];
    }

    #[DataProvider('unsupportedBrowserActions')]
    public function test_rejects_unsupported_model_actions_before_the_browser_runs_them(array $decision): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        [$browser, $model, $github] = $this->dependencies($run, $this->snapshot($project));
        $model->shouldReceive('decide')->once()->andReturn($decision);
        $browser->shouldNotReceive('act');
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(0, $run->findings()->count());
    }

    public static function unsupportedBrowserActions(): array
    {
        return [
            'stale element reference' => [['tool' => 'click', 'id' => 'e0-9']],
            'arbitrary script execution' => [['tool' => 'evaluate', 'code' => 'fetch("http://localhost")']],
        ];
    }

    /** @return array{0: MockInterface, 1: MockInterface, 2: MockInterface, 3: MockInterface} */
    private function dependencies(QaRun $run, array $snapshot, array $initialErrors = []): array
    {
        $project = $run->project;
        $this->mock(TargetPolicy::class)->shouldReceive('assertAllowed')->once()->with($project->website_url, $project->target_mode);
        $browser = $this->mock(BrowserWorker::class);
        $browser->shouldReceive('start')->once()->with($project->website_url, $project->target_mode)
            ->andReturn(['session_id' => 'browser-session', 'snapshot' => $snapshot, 'network_errors' => $initialErrors, 'console_errors' => []]);
        $browser->shouldReceive('close')->once()->with('browser-session')->andReturn(['closed' => true, 'artifacts' => []]);

        return [$browser, $this->mock(ModelClient::class), $this->mock(GitHubAppClient::class), $this->mock(RepairSandbox::class)];
    }

    private function snapshot(Project $project): array
    {
        return [
            'url' => $project->website_url.'/register', 'title' => 'Register', 'text' => 'Create an account',
            'elements' => [['id' => 'e1-1', 'tag' => 'input', 'role' => '', 'label' => 'Password', 'text' => '', 'type' => 'password']],
        ];
    }

    private function expectFailedAssertion(MockInterface $browser, MockInterface $model, array $snapshot): void
    {
        $model->shouldReceive('decide')->once()->andReturn(['tool' => 'assert_text', 'text' => 'Account created']);
        $browser->shouldReceive('act')->once()->with('browser-session', ['tool' => 'assert_text', 'text' => 'Account created'])
            ->andReturn(['snapshot' => $snapshot, 'assertion' => ['type' => 'text', 'expected' => 'Account created', 'passed' => false], 'network_errors' => []]);
        $this->expectScreenshot($browser, $snapshot);
    }

    private function expectScreenshot(MockInterface $browser, array $snapshot): void
    {
        $browser->shouldReceive('act')->once()->with('browser-session', ['tool' => 'screenshot'])
            ->andReturn(['snapshot' => $snapshot, 'artifact' => 'browser-evidence-1.png']);
    }

    private function expectNoPublication(MockInterface $github): void
    {
        $github->shouldNotReceive('commitRepair');
        $github->shouldNotReceive('createDraftPullRequest');
    }

    /** @return array{0: MockInterface, 1: array<string, string>} */
    private function prepareRepair(QaRun $run, array $verificationOverrides = []): array
    {
        $project = $run->project;
        $snapshot = array_merge($this->snapshot($project), ['source_sha' => self::BASE_SHA]);
        [$browser, $model, $github, $sandbox] = $this->dependencies($run, $snapshot);
        $files = [
            self::SOURCE_PATH => '<?php // registration accepts a blank optional phone',
            self::TEST_PATH => '<?php // regression for registration with an optional blank phone',
        ];
        $model->shouldReceive('decide')->times(4)->andReturn(
            ['tool' => 'assert_text', 'text' => 'Account created'],
            ['files' => [self::SOURCE_PATH]],
            ['diagnosis' => 'The optional phone was dereferenced without a null check.', 'patch_files' => $files, 'regression_test' => self::TEST_PATH],
            ['actions' => [
                ['tool' => 'fill', 'target' => ['tag' => 'input', 'role' => '', 'label' => 'Email', 'text' => '', 'type' => 'email'], 'value' => 'qa-control@example.com'],
                ['tool' => 'assert_text', 'text' => 'Account created'],
            ], 'original_assertion' => ['tool' => 'assert_text', 'text' => 'Account created']],
        );
        $browser->shouldReceive('act')->once()->with('browser-session', ['tool' => 'assert_text', 'text' => 'Account created'])
            ->andReturn(['snapshot' => $snapshot, 'assertion' => ['type' => 'text', 'expected' => 'Account created', 'passed' => false], 'network_errors' => []]);
        $this->expectScreenshot($browser, $snapshot);
        $github->shouldReceive('inspectRepository')->once()->with($project->repository_url)->andReturn(['default_branch' => 'main', 'head_sha' => self::BASE_SHA]);
        $github->shouldReceive('repositoryTree')->once()->with($project->repository_url, self::BASE_SHA)->andReturn([['path' => self::SOURCE_PATH, 'size' => 100]]);
        $github->shouldReceive('readFiles')->once()->with($project->repository_url, self::BASE_SHA, [self::SOURCE_PATH])->andReturn([self::SOURCE_PATH => '<?php // broken optional phone access']);
        $sandbox->shouldReceive('ready')->once()->andReturn(true);
        $verification = array_merge([
            'passed' => true, 'source_sha' => self::BASE_SHA, 'patch_sha256' => hash('sha256', json_encode($files)),
            'baseline_suite' => true, 'baseline_regression_assertion' => true, 'patched_regression' => true,
            'patched_suite' => true, 'original_replay' => true, 'control_replay' => true,
        ], $verificationOverrides);
        $sandbox->shouldReceive('verify')->once()->withArgs(fn ($actualRun, $actualFiles, $test, $actions) => $actualRun->id === $run->id
            && $actualRun->base_sha === self::BASE_SHA && $actualFiles === $files && $test === self::TEST_PATH
            && $actions[0]['tool'] === 'assert_text' && count($actualRun->report['control_actions']) === 2)
            ->andReturn($verification);

        return [$github, $files];
    }
}
