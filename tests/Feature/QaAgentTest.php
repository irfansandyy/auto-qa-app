<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\QaRun;
use App\Services\BrowserWorker;
use App\Services\GitHub\GitHubAppClient;
use App\Services\ModelClient;
use App\Services\QaAgent;
use App\Services\RepairSandbox;
use App\Services\RepositoryReview;
use App\Services\TargetPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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
        config()->set('autoqa.model_repair_timeout', 600);
    }

    public function test_automatic_check_follows_only_an_observed_same_origin_link_and_saves_checkpoints_during_the_run(): void
    {
        $project = Project::factory()->local()->create(['repository_url' => null, 'website_url' => 'http://127.0.0.1:8080']);
        $run = QaRun::factory()->for($project)->create(['automatic' => true]);
        $home = 'http://127.0.0.1:8080';
        $about = $home.'/about';
        $snapshot = $this->automaticPage($home, 200, [
            ['tag' => 'button', 'text' => 'Submit', 'read_only_url' => $home.'/submit'],
            ['tag' => 'a', 'text' => 'Different site', 'read_only_url' => 'https://example.org/about'],
            ['tag' => 'a', 'text' => 'Filtered query', 'read_only_url' => $home.'/settings?action=change'],
            ['tag' => 'a', 'text' => 'About', 'read_only_url' => $about],
        ]);
        [$browser, $model, $github, $sandbox] = $this->automaticDependencies($run, $snapshot);
        $model->shouldNotReceive('forUser');
        $model->shouldNotReceive('decide');
        $this->expectNoSourceRepair($github, $sandbox);
        $browser->shouldReceive('act')->once()->ordered()->with('browser-session', ['tool' => 'assert_page'])
            ->andReturnUsing(function () use ($run, $snapshot): array {
                $observation = $run->steps()->where('kind', 'observe')->sole();
                $this->assertSame('browser-example-1.png', $observation->data['preview_artifact']);
                $this->assertSame('opened', $observation->data['message']);

                return ['snapshot' => $snapshot, 'assertion' => ['type' => 'page', 'passed' => true],
                    'network_errors' => [['status' => 500, 'url' => 'https://example.org/asset', 'main_document' => false]]];
            });
        $browser->shouldReceive('act')->once()->ordered()->with('browser-session', ['tool' => 'navigate', 'url' => $about])
            ->andReturn(['snapshot' => $this->automaticPage($about, 200), 'preview_artifact' => 'browser-example-2.png']);
        $browser->shouldReceive('act')->once()->ordered()->with('browser-session', ['tool' => 'assert_page'])
            ->andReturnUsing(function () use ($run, $about): array {
                $navigation = $run->steps()->where('data->action->tool', 'navigate')->sole();
                $this->assertSame('browser-example-2.png', $navigation->data['preview_artifact']);
                $this->assertSame('link', $navigation->data['message']);
                $this->assertSame('About', $navigation->data['label']);

                return ['snapshot' => $this->automaticPage($about, 200), 'assertion' => ['type' => 'page', 'passed' => true]];
            });

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('completed', $run->status, (string) $run->error);
        $this->assertSame('completed', $run->report['message']);
        $this->assertSame('completed', $run->steps()->where('kind', 'result')->sole()->data['message']);
        $this->assertSame([$home, $about], $run->report['checked_pages']);
        $this->assertSame(2, $run->report['passed_assertions']);
        $this->assertSame(0, $run->findings()->count());
    }

    public function test_automatic_website_only_check_reports_a_missing_link_with_browser_evidence(): void
    {
        $project = Project::factory()->local()->create(['repository_url' => null, 'website_url' => 'http://127.0.0.1:8080']);
        $run = QaRun::factory()->for($project)->create(['automatic' => true]);
        $home = 'http://127.0.0.1:8080';
        $missing = $home.'/help';
        $snapshot = $this->automaticPage($home, 200, [['tag' => 'a', 'text' => 'Help', 'read_only_url' => $missing]]);
        [$browser, $model, $github, $sandbox] = $this->automaticDependencies($run, $snapshot);
        $model->shouldNotReceive('decide');
        $this->expectNoSourceRepair($github, $sandbox);
        $browser->shouldReceive('act')->once()->ordered()->with('browser-session', ['tool' => 'assert_page'])
            ->andReturn(['snapshot' => $snapshot, 'assertion' => ['type' => 'page', 'passed' => true]]);
        $browser->shouldReceive('act')->once()->ordered()->with('browser-session', ['tool' => 'navigate', 'url' => $missing])
            ->andReturn(['snapshot' => $this->automaticPage($missing, 404), 'preview_artifact' => 'browser-missing-2.png']);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('report_ready', $run->status, (string) $run->error);
        $this->assertSame('http_status', $run->report['message']);
        $this->assertSame(['message' => 'http_status', 'document_status' => 404], $run->steps()->where('kind', 'result')->sole()->data);
        $this->assertStringContainsString('HTTP 404', $run->report['summary']);
        $this->assertSame($missing, $run->report['failure']['url']);
        $this->assertSame('browser-missing-2.png', $run->findings()->sole()->evidence['artifact']);
        $this->assertSame('A link led to a missing page', $run->findings()->sole()->title);
    }

    public function test_automatic_check_marks_an_inconclusive_document_as_blocked_without_a_repair(): void
    {
        $project = Project::factory()->local()->publishing()->create(['website_url' => 'http://127.0.0.1:8080']);
        $run = QaRun::factory()->for($project)->create(['automatic' => true]);
        $snapshot = $this->automaticPage($project->website_url, 200);
        [$browser, $model, $github, $sandbox] = $this->automaticDependencies($run, $snapshot);
        $browser->shouldReceive('act')->once()->with('browser-session', ['tool' => 'assert_page'])
            ->andReturn(['snapshot' => $snapshot, 'assertion' => ['type' => 'page', 'passed' => false]]);
        $model->shouldNotReceive('decide');
        $this->expectNoSourceRepair($github, $sandbox);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('blocked', $run->status, (string) $run->error);
        $this->assertStringContainsString('could not verify', $run->report['summary']);
        $this->assertSame(0, $run->report['passed_assertions']);
        $this->assertSame(0, $run->findings()->count());
    }

    public function test_automatic_repair_reuses_the_observed_page_and_healthy_control_without_model_directed_browser_actions(): void
    {
        $project = Project::factory()->local()->publishing()->create(['website_url' => 'http://127.0.0.1:8080']);
        $run = QaRun::factory()->for($project)->create(['automatic' => true]);
        $home = 'http://127.0.0.1:8080';
        $errorPage = $home.'/register';
        $snapshot = $this->automaticPage($home, 200, [['tag' => 'a', 'text' => 'Registration', 'read_only_url' => $errorPage]]);
        [$browser, $model, $github, $sandbox] = $this->automaticDependencies($run, $snapshot);
        $files = $this->expectAutomaticRepairProposal($run, $model, $github, $errorPage);
        $browser->shouldReceive('act')->once()->ordered()->with('browser-session', ['tool' => 'assert_page'])
            ->andReturn(['snapshot' => $snapshot, 'assertion' => ['type' => 'page', 'passed' => true]]);
        $navigation = ['tool' => 'navigate', 'url' => $errorPage];
        $browser->shouldReceive('act')->once()->ordered()->with('browser-session', $navigation)
            ->andReturn(['snapshot' => $this->automaticPage($errorPage, 500), 'preview_artifact' => 'browser-error-2.png']);
        $sandbox->shouldReceive('ready')->once()->andReturnTrue();
        $sandbox->shouldReceive('verify')->once()
            ->withArgs(fn (QaRun $actualRun, array $changedFiles, string $test, array $plan): bool => $run->is($actualRun)
                && $changedFiles === $files && $test === self::TEST_PATH
                && $plan === [['tool' => 'assert_page', 'url' => $home], $navigation, ['tool' => 'assert_page', 'url' => $errorPage]]
                && $actualRun->report['control_actions'] === [['tool' => 'assert_page', 'url' => $home]])
            ->andReturn(['passed' => false]);
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('verification_failed', $run->status, (string) $run->error);
        $this->assertSame($files, $run->patch_files);
        $this->assertSame('GET', $run->report['failure']['method']);
        $this->assertSame('browser-error-2.png', $run->findings()->sole()->evidence['artifact']);
        $this->assertNull($run->pr_url);
    }

    public function test_automatic_repair_keeps_a_starting_page_patch_as_a_suggestion_without_a_healthy_control(): void
    {
        $project = Project::factory()->local()->publishing()->create(['website_url' => 'http://127.0.0.1:8080']);
        $run = QaRun::factory()->for($project)->create(['automatic' => true]);
        $home = 'http://127.0.0.1:8080';
        [$browser, $model, $github, $sandbox] = $this->automaticDependencies($run, $this->automaticPage($home, 500));
        $files = $this->expectAutomaticRepairProposal($run, $model, $github, $home);
        $browser->shouldNotReceive('act');
        $sandbox->shouldNotReceive('ready');
        $sandbox->shouldNotReceive('verify');
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('suggestion_ready', $run->status, (string) $run->error);
        $this->assertSame($files, $run->patch_files);
        $this->assertStringContainsString('separate healthy page', $run->report['summary']);
        $this->assertNull($run->pr_url);
    }

    public function test_missing_deployed_sha_uses_the_selected_branch_commit_for_audit_and_keeps_repairs_as_proposals(): void
    {
        $project = Project::factory()->local()->publishing()->create(['deployed_sha' => null, 'base_branch' => 'release/current']);
        $rule = ['id' => 'backend.deterministic-errors', 'title' => 'Report failures clearly', 'check' => 'Review recorded errors.'];
        $run = QaRun::factory()->for($project)->create(['automatic' => true, 'advanced' => true, 'qa_sectors' => ['backend'],
            'report' => ['rule_guide' => [$rule]],
        ]);
        $home = $project->website_url;
        $errorPage = $home.'/register';
        $snapshot = ['url' => $home, 'document_status' => 200, 'elements' => [['tag' => 'a', 'text' => 'Registration', 'read_only_url' => $errorPage]]];
        [$browser, $model, $github, $sandbox] = $this->automaticDependencies($run, $snapshot);
        $files = $this->expectAutomaticRepairProposal($run, $model, $github, $errorPage, true);
        $browser->shouldReceive('act')->once()->ordered()->with('browser-session', ['tool' => 'assert_page'])
            ->andReturn(['snapshot' => $snapshot, 'assertion' => ['passed' => true]]);
        $browser->shouldReceive('act')->once()->ordered()->with('browser-session', ['tool' => 'navigate', 'url' => $errorPage])
            ->andReturn(['snapshot' => ['url' => $errorPage, 'document_status' => 500, 'elements' => []]]);
        $github->shouldReceive('branchHead')->once()->with($project->repository_url, 'release/current')->andReturn(self::BASE_SHA);
        $this->mock(RepositoryReview::class)->shouldReceive('review')->once()->withArgs(fn ($actual, $sha, $guidance, $sectors): bool => $project->is($actual)
            && $sha === self::BASE_SHA && $sectors === ['backend'])
            ->andReturn(['coverage' => ['source_status' => 'reviewed', 'source_matches_deployment' => true], 'findings' => []]);
        $sandbox->shouldNotReceive('ready');
        $sandbox->shouldNotReceive('verify');
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('suggestion_ready', $run->status, (string) $run->error);
        $this->assertSame(self::BASE_SHA, $run->base_sha);
        $this->assertSame(['sha' => self::BASE_SHA, 'branch' => 'release/current', 'origin' => 'github_latest'], $run->report['source_reference']);
        $this->assertFalse($run->report['audit']['repository']['source_matches_deployment']);
        $this->assertSame([$rule], $run->report['rule_guide']);
        $this->assertSame($files, $run->patch_files);
        $this->assertNull($run->pr_url);
        $this->assertNull($project->fresh()->deployed_sha);
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

    #[DataProvider('missingRepositories')]
    public function test_website_only_failure_finishes_with_a_report_and_never_attempts_source_repair(?string $repository): void
    {
        $project = Project::factory()->local()->publishing()->create(['repository_url' => $repository]);
        $run = QaRun::factory()->for($project)->create();
        $snapshot = array_merge($this->snapshot($project), ['source_sha' => self::BASE_SHA]);
        $artifacts = ['browser-recording.webm', 'browser-evidence-1.png'];
        [$browser, $model, $github, $sandbox] = $this->dependencies($run, $snapshot, artifacts: $artifacts);
        $this->expectFailedAssertion($browser, $model, $snapshot);
        $this->expectNoSourceRepair($github, $sandbox);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('report_ready', $run->status);
        $this->assertStringContainsString('Expected behavior was not observed', $run->report['summary']);
        $this->assertFalse($run->report['failure']['assertion']['passed']);
        $this->assertSame('browser-evidence-1.png', $run->report['failure']['artifact']);
        $this->assertSame([['tool' => 'assert_text', 'text' => 'Account created']], $run->report['actions']);
        $this->assertSame($artifacts, $run->report['artifacts']);
        $this->assertSame(1, $run->findings()->count());
        $this->assertSame($run->report['failure'], $run->findings()->first()->evidence);
        $this->assertSame('observed', $run->findings()->first()->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->base_sha);
        $this->assertNull($run->patch_files);
        $this->assertNull($run->verification);
        $this->assertNull($run->pr_url);
    }

    public static function missingRepositories(): array
    {
        return [
            'no repository' => [null],
            'empty repository' => [''],
            'blank repository' => ['   '],
        ];
    }

    public function test_website_only_initial_server_error_saves_a_report_without_model_inference_or_source_binding(): void
    {
        $project = Project::factory()->local()->create(['repository_url' => null]);
        $run = QaRun::factory()->for($project)->create();
        $snapshot = $this->snapshot($project);
        [$browser, $model, $github, $sandbox] = $this->dependencies($run, $snapshot, [['url' => $snapshot['url'], 'status' => 503]]);
        $model->shouldNotReceive('decide');
        $this->expectScreenshot($browser, $snapshot);
        $this->expectNoSourceRepair($github, $sandbox);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('report_ready', $run->status);
        $this->assertStringContainsString('HTTP 503', $run->report['summary']);
        $this->assertSame([], $run->report['actions']);
        $this->assertSame(503, $run->report['failure']['network_errors'][0]['status']);
        $this->assertSame('browser-evidence-1.png', $run->findings()->sole()->evidence['artifact']);
        $this->assertSame('high', $run->findings()->sole()->severity);
        $this->assertNull($run->base_sha);
        $this->assertNull($run->error);
    }

    public function test_website_only_success_retains_its_completed_result_and_recorded_assertion(): void
    {
        $project = Project::factory()->local()->create(['repository_url' => null]);
        $run = QaRun::factory()->for($project)->create();
        $snapshot = $this->snapshot($project);
        [$browser, $model, $github, $sandbox] = $this->dependencies($run, $snapshot);
        $model->shouldReceive('decide')->twice()->andReturn(
            ['tool' => 'assert_text', 'text' => 'Account created'],
            ['tool' => 'finish', 'outcome' => 'passed', 'summary' => 'The account confirmation appeared.'],
        );
        $browser->shouldReceive('act')->once()->with('browser-session', ['tool' => 'assert_text', 'text' => 'Account created'])
            ->andReturn(['snapshot' => $snapshot, 'assertion' => ['passed' => true], 'network_errors' => []]);
        $this->expectNoSourceRepair($github, $sandbox);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('The account confirmation appeared.', $run->report['summary']);
        $this->assertSame(1, $run->report['passed_assertions']);
        $this->assertSame([['tool' => 'assert_text', 'text' => 'Account created']], $run->report['actions']);
        $this->assertSame(0, $run->findings()->count());
    }

    public function test_website_only_unverified_completion_stays_blocked(): void
    {
        $project = Project::factory()->local()->create(['repository_url' => null]);
        $run = QaRun::factory()->for($project)->create();
        [$browser, $model, $github, $sandbox] = $this->dependencies($run, $this->snapshot($project));
        $model->shouldReceive('decide')->once()->andReturn(['tool' => 'finish', 'outcome' => 'passed', 'summary' => 'Everything works.']);
        $browser->shouldNotReceive('act');
        $this->expectNoSourceRepair($github, $sandbox);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('blocked', $run->status);
        $this->assertSame(0, $run->report['passed_assertions']);
        $this->assertSame([], $run->report['actions']);
        $this->assertStringContainsString('unverified', $run->report['summary']);
        $this->assertSame(0, $run->findings()->count());
    }

    public function test_background_run_uses_the_project_owners_selected_model_client(): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        [$browser, $model, $github] = $this->dependencies($run, $this->snapshot($project));
        $selectedModel = \Mockery::mock(ModelClient::class);
        $model->shouldReceive('forUser')->once()->withArgs(fn ($user) => $user->is($project->user))->andReturn($selectedModel);
        $model->shouldNotReceive('decide');
        $selectedModel->shouldReceive('decide')->once()->andReturn(['tool' => 'finish', 'outcome' => 'blocked', 'summary' => 'The target requires access.']);
        $browser->shouldNotReceive('act');
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertSame('The target requires access.', $run->fresh()->report['summary']);
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

    public function test_disposable_identity_stays_consistent_and_password_confirmation_is_masked_in_audit(): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        $snapshot = $this->snapshot($project);
        $snapshot['elements'][] = ['id' => 'e1-2', 'tag' => 'input', 'role' => '', 'label' => 'Confirm password', 'text' => '', 'type' => 'password'];
        [$browser, $model, $github] = $this->dependencies($run, $snapshot);
        $identity = null;
        $call = 0;
        $model->shouldReceive('decide')->times(4)->andReturnUsing(function (string $instruction, array $context) use (&$identity, &$call): array {
            $identity ??= $context['qa_identity'];
            $this->assertTrue($identity === $context['qa_identity'], 'Every browser decision must use the same disposable identity.');
            $this->assertTrue($identity['password'] === $identity['password_confirmation'], 'Password confirmation must match the generated password.');
            $this->assertNotEmpty(trim($identity['name']));
            $this->assertNotFalse(filter_var($identity['email'], FILTER_VALIDATE_EMAIL));
            $this->assertStringEndsWith('@example.test', $identity['email']);
            $this->assertGreaterThanOrEqual(24, strlen($identity['password']));
            foreach (['/[a-z]/', '/[A-Z]/', '/[0-9]/', '/[^a-zA-Z0-9]/'] as $pattern) {
                $this->assertSame(1, preg_match($pattern, $identity['password']), 'The synthetic password must meet each character-class requirement.');
            }
            $this->assertStringNotContainsString('"value":"QA data"', $instruction);
            $this->assertStringNotContainsString($identity['password'], json_encode($context['previous_actions']));
            $decision = match ($call++) {
                0 => ['tool' => 'fill', 'id' => 'e1-1', 'value' => $identity['password']],
                1 => ['tool' => 'fill', 'id' => 'e1-2', 'value' => $identity['password_confirmation']],
                2 => ['tool' => 'assert_text', 'text' => 'Account created'],
                default => ['tool' => 'finish', 'outcome' => 'passed', 'summary' => 'Synthetic fixture '.$identity['password'].' confirmed.'],
            };

            return $decision;
        });
        $browserCall = 0;
        $browser->shouldReceive('act')->times(3)->andReturnUsing(function (string $session, array $action) use (&$browserCall, &$identity, $snapshot): array {
            $this->assertSame('browser-session', $session);
            if ($browserCall < 2) {
                $this->assertSame('fill', $action['tool']);
                $this->assertSame($browserCall === 0 ? 'e1-1' : 'e1-2', $action['id']);
                $this->assertTrue($identity['password'] === $action['value'], 'Both password controls must receive the same synthetic password.');
            } else {
                $this->assertSame(['tool' => 'assert_text', 'text' => 'Account created'], $action);
            }
            $browserCall++;

            return ['snapshot' => $snapshot, 'network_errors' => [], 'assertion' => $browserCall === 3
                ? ['passed' => true, 'diagnostic' => $identity['password']] : ['diagnostic' => $identity['password']]];
        });
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('[QA password hidden]', $run->report['actions'][0]['value']);
        $this->assertSame('[QA password hidden]', $run->report['actions'][1]['value']);
        $this->assertStringNotContainsString($identity['password'], json_encode($run->report));
        $this->assertStringNotContainsString($identity['password'], $run->steps()->get()->toJson());
        $this->assertArrayNotHasKey('qa_identity', $run->report);
    }

    public function test_disposable_email_is_valid_and_different_for_each_run(): void
    {
        $project = Project::factory()->local()->create();
        $emails = [];
        foreach ([QaRun::factory()->for($project)->create(), QaRun::factory()->for($project)->create()] as $run) {
            [$browser, $model, $github] = $this->dependencies($run, $this->snapshot($project));
            $model->shouldReceive('decide')->once()->andReturnUsing(function (string $instruction, array $context) use (&$emails): array {
                $emails[] = $context['qa_identity']['email'];
                $this->assertNotFalse(filter_var(end($emails), FILTER_VALIDATE_EMAIL));
                $this->assertStringEndsWith('@example.test', end($emails));

                return ['tool' => 'finish', 'outcome' => 'blocked', 'summary' => 'No interaction requested.'];
            });
            $browser->shouldNotReceive('act');
            $this->expectNoPublication($github);

            app(QaAgent::class)->run($run->id);

            $this->assertSame('blocked', $run->fresh()->status);
            $this->assertArrayNotHasKey('qa_identity', $run->fresh()->report);
        }

        $this->assertNotSame($emails[0], $emails[1]);
    }

    public function test_synthetic_password_is_masked_when_a_model_error_echoes_it(): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        [$browser, $model, $github] = $this->dependencies($run, $this->snapshot($project));
        $password = null;
        $model->shouldReceive('decide')->once()->andReturnUsing(function (string $instruction, array $context) use (&$password): never {
            $password = $context['qa_identity']['password'];

            throw new RuntimeException('Synthetic fixture rejected: '.$password);
        });
        $browser->shouldNotReceive('act');
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('Synthetic fixture rejected: [QA password hidden]', $run->error);
        $this->assertStringNotContainsString($password, $run->steps()->get()->toJson());
    }

    public function test_synthetic_password_is_masked_in_saved_failure_evidence(): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        $snapshot = $this->snapshot($project);
        [$browser, $model, $github] = $this->dependencies($run, $snapshot);
        $password = null;
        $model->shouldReceive('decide')->once()->andReturnUsing(function (string $instruction, array $context) use (&$password): array {
            $password = $context['qa_identity']['password'];

            return ['tool' => 'fill', 'id' => 'e1-1', 'value' => $password];
        });
        $browser->shouldReceive('act')->once()->withArgs(function (string $session, array $action) use (&$password): bool {
            return $session === 'browser-session' && $action === ['tool' => 'fill', 'id' => 'e1-1', 'value' => $password];
        })
            ->andReturnUsing(function () use (&$password, $snapshot): array {
                $url = $snapshot['url'].'?diagnostic='.$password;

                return ['snapshot' => [...$snapshot, 'url' => $url],
                    'network_errors' => [['url' => $url, 'status' => 500]],
                    'assertion' => ['passed' => false, 'diagnostic' => 'Rejected '.$password]];
            });
        $this->expectScreenshot($browser, $snapshot);
        $github->shouldNotReceive('inspectRepository');
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('needs_configuration', $run->status);
        $this->assertSame(1, $run->findings()->count());
        $finding = $run->findings()->first();
        $this->assertSame($snapshot['url'].'?diagnostic=[QA password hidden]', $finding->evidence['url']);
        $this->assertSame('Rejected [QA password hidden]', $finding->evidence['assertion']['diagnostic']);
        $this->assertStringNotContainsString($password, $finding->toJson());
        $this->assertStringNotContainsString($password, json_encode($run->report));
        $this->assertStringNotContainsString($password, $run->steps()->get()->toJson());
        $this->assertNull($run->pr_url);
    }

    public function test_corrects_one_stale_reference_without_executing_it_or_reusing_history_ids(): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        $snapshot = $this->snapshot($project);
        $snapshot['elements'][0] = ['id' => 'e1-1', 'tag' => 'input', 'role' => '', 'label' => 'Name', 'text' => '', 'type' => 'text'];
        $refreshed = $snapshot;
        $refreshed['elements'][0]['id'] = 'e2-2';
        [$browser, $model, $github] = $this->dependencies($run, $snapshot);
        $decisions = [
            ['tool' => 'fill', 'id' => 'e1-1', 'value' => 'Alice'],
            ['tool' => 'fill', 'id' => 'e1-1', 'value' => 'Bob'],
            ['tool' => 'fill', 'id' => 'e2-2', 'value' => 'Bob'],
            ['tool' => 'assert_text', 'text' => 'Account created'],
            ['tool' => 'finish', 'outcome' => 'passed', 'summary' => 'Account confirmation appeared.'],
        ];
        $call = 0;
        $model->shouldReceive('decide')->times(5)->andReturnUsing(function (string $instruction, array $context) use (&$call, $decisions, $refreshed): array {
            $this->assertStringContainsString('Element IDs refresh after every browser action.', $instruction);
            if (in_array($call, [1, 2], true)) {
                $this->assertSame($refreshed, $context['page']);
                $this->assertArrayNotHasKey('id', $context['previous_actions'][0]);
                $this->assertArrayNotHasKey('id', $context['previous_actions'][0]['target']);
                $this->assertSame('Name', $context['previous_actions'][0]['target']['label']);
            }
            if ($call === 2) {
                $this->assertStringContainsString('No browser action was executed for the rejected decision.', $instruction);
                $this->assertArrayHasKey('validation_error', $context);
                $this->assertSame(4, $context['steps_remaining']);
            }

            return $decisions[$call++];
        });
        $browser->shouldReceive('act')->once()->with('browser-session', ['tool' => 'fill', 'id' => 'e1-1', 'value' => 'Alice'])
            ->andReturn(['snapshot' => $refreshed, 'network_errors' => []]);
        $browser->shouldNotReceive('act')->with('browser-session', ['tool' => 'fill', 'id' => 'e1-1', 'value' => 'Bob']);
        $browser->shouldReceive('act')->once()->with('browser-session', ['tool' => 'fill', 'id' => 'e2-2', 'value' => 'Bob'])
            ->andReturn(['snapshot' => $refreshed, 'network_errors' => []]);
        $browser->shouldReceive('act')->once()->with('browser-session', ['tool' => 'assert_text', 'text' => 'Account created'])
            ->andReturn(['snapshot' => $refreshed, 'assertion' => ['passed' => true], 'network_errors' => []]);
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(3, count($run->report['actions']));
        $this->assertSame('e1-1', $run->report['actions'][0]['id']);
        $this->assertSame('e1-1', $run->report['actions'][0]['target']['id']);
        $this->assertSame('e2-2', $run->report['actions'][1]['id']);
        $this->assertSame(3, $run->steps()->where('kind', 'action')->count());
    }

    public function test_repeated_stale_choice_stops_after_one_correction_without_browser_execution(): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        [$browser, $model, $github] = $this->dependencies($run, $this->snapshot($project));
        $model->shouldReceive('decide')->twice()->andReturn(['tool' => 'click', 'id' => 'e0-9']);
        $browser->shouldNotReceive('act');
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('outside the current page snapshot', $run->error);
        $this->assertSame(0, $run->steps()->where('kind', 'action')->count());
    }

    public function test_a_later_stale_reference_cannot_start_a_second_correction(): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        $snapshot = $this->snapshot($project);
        $refreshed = $snapshot;
        $refreshed['elements'][0]['id'] = 'e2-2';
        [$browser, $model, $github] = $this->dependencies($run, $snapshot);
        $model->shouldReceive('decide')->times(3)->andReturn(
            ['tool' => 'fill', 'id' => 'e0-9', 'value' => 'Disposable QA password'],
            ['tool' => 'fill', 'id' => 'e1-1', 'value' => 'Disposable QA password'],
            ['tool' => 'fill', 'id' => 'e1-1', 'value' => 'Disposable QA password'],
        );
        $browser->shouldReceive('act')->once()->with('browser-session', ['tool' => 'fill', 'id' => 'e1-1', 'value' => 'Disposable QA password'])
            ->andReturn(['snapshot' => $refreshed, 'network_errors' => []]);
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(1, $run->steps()->where('kind', 'action')->count());
    }

    #[DataProvider('supportedStaleActions')]
    public function test_a_stale_correction_consumes_the_remaining_decision_budget(array $stale, array $element): void
    {
        config()->set('autoqa.max_steps', 2);
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        $snapshot = $this->snapshot($project);
        $snapshot['elements'] = [$element];
        [$browser, $model, $github] = $this->dependencies($run, $snapshot);
        $corrected = [...$stale, 'id' => $element['id']];
        $model->shouldReceive('decide')->twice()->andReturn(
            $stale,
            $corrected,
        );
        $browser->shouldReceive('act')->once()->with('browser-session', $corrected)
            ->andReturn(['snapshot' => $snapshot, 'network_errors' => []]);
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertSame(1, $run->steps()->where('kind', 'action')->count());
    }

    public static function supportedStaleActions(): array
    {
        return [
            'click' => [['tool' => 'click', 'id' => 'e0-9'], ['id' => 'e1-1', 'tag' => 'button', 'label' => '', 'text' => 'Register', 'type' => 'submit']],
            'fill' => [['tool' => 'fill', 'id' => 'e0-9', 'value' => 'QA Student'], ['id' => 'e1-1', 'tag' => 'input', 'label' => 'Name', 'text' => '', 'type' => 'text']],
            'select' => [['tool' => 'select', 'id' => 'e0-9', 'value' => 'ID'], ['id' => 'e1-1', 'tag' => 'select', 'label' => 'Country', 'text' => '', 'type' => 'select-one']],
        ];
    }

    public function test_a_stale_choice_cannot_exceed_an_exhausted_decision_budget(): void
    {
        config()->set('autoqa.max_steps', 1);
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        [$browser, $model, $github] = $this->dependencies($run, $this->snapshot($project));
        $model->shouldReceive('decide')->once()->andReturn(['tool' => 'click', 'id' => 'e0-9']);
        $browser->shouldNotReceive('act');
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertStringContainsString('action limit', $run->fresh()->error);
        $this->assertSame(0, $run->steps()->where('kind', 'action')->count());
    }

    public function test_cancellation_during_reference_correction_prevents_browser_execution(): void
    {
        $project = Project::factory()->local()->create();
        $run = QaRun::factory()->for($project)->create();
        [$browser, $model, $github] = $this->dependencies($run, $this->snapshot($project));
        $model->shouldReceive('decide')->once()->ordered()->andReturn(['tool' => 'click', 'id' => 'e0-9']);
        $model->shouldReceive('decide')->once()->ordered()->andReturnUsing(function () use ($run): array {
            $run->update(['status' => 'cancelled']);

            return ['tool' => 'fill', 'id' => 'e1-1', 'value' => 'Disposable QA password'];
        });
        $browser->shouldNotReceive('act');
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(0, $run->steps()->where('kind', 'action')->count());
    }

    public function test_control_planning_uses_the_form_before_server_error_and_preserves_failure_evidence(): void
    {
        $project = Project::factory()->local()->publishing()->create();
        $run = QaRun::factory()->for($project)->create();
        [$github] = $this->prepareRepair($run, ['passed' => false], true);
        $this->expectNoPublication($github);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('verification_failed', $run->status);
        $this->assertSame(500, $run->report['failure']['network_errors'][0]['status']);
        $this->assertSame($project->website_url.'/register', $run->report['failure']['url']);
        $this->assertSame('browser-evidence-1.png', $run->report['failure']['artifact']);
        $this->assertSame('e1-2', $run->report['actions'][0]['id']);
        $this->assertSame('e1-2', $run->report['actions'][0]['target']['id']);
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
        $github->shouldReceive('assertPublicationAllowed')->once()->with($project->repository_url, 'main', self::BASE_SHA)->ordered();
        $github->shouldReceive('commitRepair')->once()->ordered()->withArgs(function ($repository, $base, $branch, $patch, $message) use ($project, $run, $files, &$publishedBranch): bool {
            $publishedBranch = $branch;

            return $repository === $project->repository_url && $base === self::BASE_SHA
                && str_starts_with($branch, 'agent/fix-'.$run->id.'-') && $patch === $files
                && $message === 'Repair QA finding #'.$run->id;
        })->andReturnUsing(fn ($repository, $base, $branch) => ['branch' => $branch, 'sha' => $commit]);
        $github->shouldReceive('createDraftPullRequest')->once()->withArgs(function ($repository, $branch, $base, $title, $body) use ($project, $run, $commit, &$publishedBranch): bool {
            $saved = $run->fresh();

            return $repository === $project->repository_url && $branch === $publishedBranch && $base === 'main'
                && $saved->report['branch'] === $branch && $saved->report['commit_sha'] === $commit
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

    #[DataProvider('publicationRefusals')]
    public function test_preserves_the_verified_proposal_without_publishing_when_preflight_refuses_it(string $reason): void
    {
        $project = Project::factory()->local()->publishing()->create(['base_branch' => 'release/demo']);
        $run = QaRun::factory()->for($project)->create();
        [$github, $files] = $this->prepareRepair($run);
        $github->shouldReceive('assertPublicationAllowed')->once()->with($project->repository_url, 'release/demo', self::BASE_SHA)
            ->andThrow(new RuntimeException($reason));
        $github->shouldNotReceive('commitRepair');
        $github->shouldNotReceive('createDraftPullRequest');

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('blocked', $run->status);
        $this->assertSame($reason, $run->report['summary']);
        $this->assertSame($files, $run->patch_files);
        $this->assertTrue($run->verification['passed']);
        $this->assertSame(1, $run->findings()->count());
        $this->assertArrayNotHasKey('branch', $run->report);
        $this->assertNull($run->pr_url);
    }

    public static function publicationRefusals(): array
    {
        return [
            'missing pull request permission' => ['Grant the GitHub App Pull requests read/write permission, then approve the installation update.'],
            'missing base branch' => ['The selected pull request base branch does not exist. Correct the project base branch before publishing.'],
            'changed base branch' => ['The selected base branch no longer matches the verified deployed commit. Deploy the current source and refresh the deployed commit before publishing a repair.'],
        ];
    }

    public function test_preserves_the_created_branch_and_commit_when_draft_pull_request_creation_fails(): void
    {
        $project = Project::factory()->local()->publishing()->create();
        $run = QaRun::factory()->for($project)->create();
        [$github, $files] = $this->prepareRepair($run);
        $branch = 'agent/fix-'.$run->id.'-'.substr(hash('sha256', json_encode($files)), 0, 8);
        $commit = str_repeat('c', 40);
        $github->shouldReceive('assertPublicationAllowed')->once()->with($project->repository_url, 'main', self::BASE_SHA)->ordered();
        $github->shouldReceive('commitRepair')->once()->ordered()->andReturn(['branch' => $branch, 'sha' => $commit]);
        $github->shouldReceive('createDraftPullRequest')->once()->ordered()->andThrow(new RuntimeException('GitHub rejected the draft pull request.'));

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame($branch, $run->report['branch']);
        $this->assertSame($commit, $run->report['commit_sha']);
        $this->assertSame('main', $run->report['base_branch']);
        $this->assertSame($files, $run->patch_files);
        $this->assertTrue($run->verification['passed']);
        $this->assertSame(1, $run->findings()->count());
        $this->assertNull($run->pr_url);
        $this->assertSame('GitHub rejected the draft pull request.', $run->error);
    }

    public function test_preserves_committed_metadata_when_cancellation_arrives_during_commit(): void
    {
        $project = Project::factory()->local()->publishing()->create();
        $run = QaRun::factory()->for($project)->create();
        [$github, $files] = $this->prepareRepair($run);
        $branch = 'agent/fix-'.$run->id.'-'.substr(hash('sha256', json_encode($files)), 0, 8);
        $commit = str_repeat('c', 40);
        $github->shouldReceive('assertPublicationAllowed')->once()->with($project->repository_url, 'main', self::BASE_SHA);
        $github->shouldReceive('commitRepair')->once()->andReturnUsing(function () use ($run, $branch, $commit): array {
            $run->update(['status' => 'cancelled', 'finished_at' => now()]);

            return ['branch' => $branch, 'sha' => $commit];
        });
        $github->shouldNotReceive('createDraftPullRequest');

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('cancelled', $run->status);
        $this->assertSame($branch, $run->report['branch']);
        $this->assertSame($commit, $run->report['commit_sha']);
        $this->assertNull($run->pr_url);
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
            'pending stale reference' => [['tool' => 'click', 'id' => 'e0-9']],
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
            'malformed stale fill' => [['tool' => 'fill', 'id' => 'e0-9', 'value' => []]],
            'missing element reference' => [['tool' => 'click']],
            'arbitrary script execution' => [['tool' => 'evaluate', 'code' => 'fetch("http://localhost")']],
        ];
    }

    /** @return array{0: MockInterface, 1: MockInterface, 2: MockInterface, 3: MockInterface} */
    private function dependencies(QaRun $run, array $snapshot, array $initialErrors = [], array $artifacts = []): array
    {
        $project = $run->project;
        $this->mock(TargetPolicy::class)->shouldReceive('assertAllowed')->once()->with($project->website_url, $project->target_mode);
        $browser = $this->mock(BrowserWorker::class);
        $browser->shouldReceive('start')->once()->with($project->website_url, $project->target_mode)
            ->andReturn(['session_id' => 'browser-session', 'snapshot' => $snapshot, 'network_errors' => $initialErrors, 'console_errors' => []]);
        $browser->shouldReceive('close')->once()->with('browser-session')->andReturn(['closed' => true, 'artifacts' => $artifacts]);
        $model = $this->mock(ModelClient::class);
        $model->shouldReceive('forUser')->once()->withArgs(fn ($user) => $user->is($project->user))->andReturnSelf()->byDefault();

        return [$browser, $model, $this->mock(GitHubAppClient::class), $this->mock(RepairSandbox::class)];
    }

    /** @return array{MockInterface, MockInterface, MockInterface, MockInterface} */
    private function automaticDependencies(QaRun $run, array $snapshot): array
    {
        $project = $run->project;
        config()->set('autoqa.local_targets', ['http://127.0.0.1:8080']);
        $browser = $this->mock(BrowserWorker::class);
        $browser->shouldReceive('start')->once()->with($project->website_url, 'local', true)
            ->andReturn(['session_id' => 'browser-session', 'snapshot' => $snapshot, 'preview_artifact' => 'browser-example-1.png']);
        $browser->shouldReceive('close')->once()->with('browser-session')->andReturn(['closed' => true, 'artifacts' => []]);

        return [$browser, $this->mock(ModelClient::class), $this->mock(GitHubAppClient::class), $this->mock(RepairSandbox::class)];
    }

    /** @return array{url: string, document_status: int, source_sha: string, elements: array} */
    private function automaticPage(string $url, int $status, array $elements = []): array
    {
        return ['url' => $url, 'document_status' => $status, 'source_sha' => self::BASE_SHA, 'elements' => $elements];
    }

    /** @return array<string, string> */
    private function expectAutomaticRepairProposal(QaRun $run, MockInterface $model, MockInterface $github, string $failedPage, bool $usesLatest = false): array
    {
        $project = $run->project;
        config(['repository-access.grants' => [$project->user_id => ['example/laravel-demo']],
            'repository-access.write_grants' => [$project->user_id => ['example/laravel-demo']]]);
        $files = [self::SOURCE_PATH => '<?php // Repair observed page output', self::TEST_PATH => '<?php // Exercise the failing GET page'];
        $model->shouldReceive('forUser')->once()->withArgs(fn ($user): bool => $user->is($project->user))->andReturnSelf();
        $model->shouldReceive('ready')->once()->andReturnTrue();
        $model->shouldReceive('decide')->once()->ordered()->andReturn(['files' => [self::SOURCE_PATH]]);
        $model->shouldReceive('decide')->once()->ordered()->andReturnUsing(function (string $instruction, array $context) use ($files, $failedPage, $run): array {
            $this->assertSame('GET', $context['failure']['method']);
            $this->assertSame($failedPage, $context['failure']['url']);
            $this->assertArrayNotHasKey('qa_identity', $context);
            $this->assertArrayHasKey('observed_browser_actions', $context);
            if ($run->advanced) {
                $this->assertSame($run->report['rule_guide'], $context['qa_rule_reference']);
            }

            return ['diagnosis' => 'The page contained a missing dependency.', 'patch_files' => $files, 'regression_test' => self::TEST_PATH];
        });
        if ($usesLatest) {
            $github->shouldNotReceive('inspectRepository');
        } else {
            $github->shouldReceive('inspectRepository')->once()->with($project->repository_url)->andReturn(['default_branch' => 'main', 'head_sha' => self::BASE_SHA]);
        }
        $github->shouldReceive('repositoryTree')->once()->with($project->repository_url, self::BASE_SHA)->andReturn([['path' => self::SOURCE_PATH, 'size' => 100]]);
        $github->shouldReceive('readFiles')->once()->with($project->repository_url, self::BASE_SHA, [self::SOURCE_PATH])
            ->andReturn([self::SOURCE_PATH => '<?php // Existing implementation']);

        return $files;
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
        $github->shouldNotReceive('assertPublicationAllowed');
        $github->shouldNotReceive('commitRepair');
        $github->shouldNotReceive('createDraftPullRequest');
    }

    private function expectNoSourceRepair(MockInterface $github, MockInterface $sandbox): void
    {
        foreach (['inspectRepository', 'repositoryTree', 'readFiles', 'archiveSnapshot'] as $method) {
            $github->shouldNotReceive($method);
        }
        $this->expectNoPublication($github);
        $sandbox->shouldNotReceive('ready');
        $sandbox->shouldNotReceive('verify');
    }

    /** @return array{0: MockInterface, 1: array<string, string>} */
    private function prepareRepair(QaRun $run, array $verificationOverrides = [], bool $serverError = false): array
    {
        $project = $run->project;
        config(['repository-access.grants' => [$project->user_id => ['example/laravel-demo']],
            'repository-access.write_grants' => [$project->user_id => ['example/laravel-demo']]]);
        $snapshot = array_merge($this->snapshot($project), ['source_sha' => self::BASE_SHA]);
        if ($serverError) {
            $snapshot['elements'][] = ['id' => 'e1-2', 'tag' => 'button', 'role' => '', 'label' => '', 'text' => 'Register', 'type' => 'submit'];
        }
        [$browser, $model, $github, $sandbox] = $this->dependencies($run, $snapshot);
        $files = [
            self::SOURCE_PATH => '<?php // registration accepts a blank optional phone',
            self::TEST_PATH => '<?php // regression for registration with an optional blank phone',
        ];
        $firstAction = $serverError ? ['tool' => 'click', 'id' => 'e1-2'] : ['tool' => 'assert_text', 'text' => 'Account created'];
        $decisions = [
            $firstAction,
            ['files' => [self::SOURCE_PATH]],
            ['diagnosis' => 'The optional phone was dereferenced without a null check.', 'patch_files' => $files, 'regression_test' => self::TEST_PATH],
            ['actions' => [
                ['tool' => 'fill', 'target' => ['tag' => 'input', 'role' => '', 'label' => 'Email', 'text' => '', 'type' => 'email'], 'value' => 'qa-control@example.com'],
                ['tool' => 'assert_text', 'text' => 'Account created'],
            ], 'original_assertion' => ['tool' => 'assert_text', 'text' => 'Account created']],
        ];
        $call = 0;
        $identity = null;
        $model->shouldReceive('decide')->times(4)->andReturnUsing(function (string $instruction, array $context, ?int $timeout = null) use (&$call, &$identity, $decisions, $snapshot, $serverError): array {
            $this->assertSame($call === 2 ? 600 : null, $timeout, 'Only complete patch generation may use the repair deadline.');
            if ($call === 0) {
                $identity = $context['qa_identity'];
            } elseif (in_array($call, [2, 3], true)) {
                $this->assertTrue($identity === $context['qa_identity'], 'Repair generation and control planning must reuse the exploration identity.');
            }
            if ($call === 3 && $serverError) {
                $this->assertSame($snapshot, $context['page']);
                $this->assertArrayNotHasKey('id', $context['original_actions'][0]);
                $this->assertArrayNotHasKey('id', $context['original_actions'][0]['target']);
                $this->assertSame('Register', $context['original_actions'][0]['target']['text']);
            }

            return $decisions[$call++];
        });
        $failedSnapshot = $serverError ? ['url' => $project->website_url.'/register', 'title' => 'Server error', 'text' => 'HTTP 500', 'elements' => []] : $snapshot;
        $browser->shouldReceive('act')->once()->with('browser-session', $firstAction)
            ->andReturn(['snapshot' => $failedSnapshot, 'assertion' => ['type' => 'text', 'expected' => 'Account created', 'passed' => false],
                'network_errors' => $serverError ? [['url' => $failedSnapshot['url'], 'status' => 500]] : []]);
        $this->expectScreenshot($browser, $failedSnapshot);
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
            && $actions[0] === ($serverError ? [...$firstAction, 'target' => $snapshot['elements'][1]] : $firstAction)
            && count($actualRun->report['control_actions']) === 2)
            ->andReturn($verification);

        return [$github, $files];
    }
}
