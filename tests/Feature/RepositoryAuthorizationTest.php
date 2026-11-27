<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Project;
use App\Models\QaRun;
use App\Models\User;
use App\Services\BrowserWorker;
use App\Services\GitHub\GitHubAppClient;
use App\Services\ModelClient;
use App\Services\QaAgent;
use App\Services\RepairSandbox;
use App\Services\TargetPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class RepositoryAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REPOSITORY = 'https://github.com/example/private-repository';

    private const BASE_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('repository-access.grants', []);
        Http::preventStrayRequests();
    }

    public function test_second_user_cannot_check_another_users_repository_by_copying_its_url_into_an_owned_project(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Project::factory()->for($owner)->create(['repository_url' => self::REPOSITORY]);
        $copied = Project::factory()->for($other)->create(['repository_url' => self::REPOSITORY]);
        config()->set('repository-access.grants', [$owner->id => ['example/private-repository']]);
        $this->mock(TargetPolicy::class)->shouldReceive('assertAllowed')->once()->with($copied->website_url, $copied->target_mode);
        $this->mock(GitHubAppClient::class)->shouldNotReceive('inspectRepository');

        Livewire::actingAs($other)->test(Dashboard::class)
            ->call('checkConnection')
            ->assertSet('connection.ok', false)
            ->assertSet('connection.can_push', false)
            ->assertSet('connection.message', __('repository-access.denied'));

        Http::assertNothingSent();
    }

    public function test_explicitly_granted_operator_can_check_the_repository_connection(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner)->create(['repository_url' => self::REPOSITORY]);
        config(['repository-access.grants' => [$owner->id => ['example/private-repository']],
            'repository-access.write_grants' => [$owner->id => ['example/private-repository']]]);
        $this->mock(TargetPolicy::class)->shouldReceive('assertAllowed')->once()->with($project->website_url, $project->target_mode);
        $this->mock(GitHubAppClient::class)->shouldReceive('inspectRepository')->once()->with(self::REPOSITORY)->andReturn([
            'full_name' => 'example/private-repository', 'can_push' => true, 'default_branch' => 'main', 'head_sha' => self::BASE_SHA,
        ]);

        Livewire::actingAs($owner)->test(Dashboard::class)
            ->call('checkConnection')->assertSet('connection.ok', true)->assertSet('connection.can_push', true)
            ->assertSet('connection.head_sha', self::BASE_SHA);

        Http::assertNothingSent();
    }

    public function test_background_repair_preserves_browser_finding_but_denies_source_access_for_a_copied_repository_url(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        config()->set('repository-access.grants', [$owner->id => ['example/private-repository']]);
        $project = Project::factory()->for($other)->local()->publishing()->create(['repository_url' => self::REPOSITORY]);
        $run = QaRun::factory()->for($project)->create();
        [$model, $github, $sandbox] = $this->prepareInitialServerFailure($run);
        $model->shouldNotReceive('decide');
        foreach (['inspectRepository', 'repositoryTree', 'readFiles', 'archiveSnapshot', 'assertPublicationAllowed', 'commitRepair', 'createDraftPullRequest'] as $method) {
            $github->shouldNotReceive($method);
        }
        $sandbox->shouldNotReceive('ready');
        $sandbox->shouldNotReceive('verify');

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('blocked', $run->status);
        $this->assertSame(__('repository-access.denied'), $run->report['summary']);
        $this->assertSame(1, $run->findings()->count());
        $this->assertSame(500, $run->report['failure']['network_errors'][0]['status']);
        $this->assertSame('browser-denied-finding.png', $run->findings()->sole()->evidence['artifact']);
        $this->assertNull($run->patch_files);
        $this->assertNull($run->pr_url);
        Http::assertNothingSent();
    }

    public function test_revoking_repository_access_during_verification_prevents_commit_and_pull_request_publication(): void
    {
        $owner = User::factory()->create();
        config(['repository-access.grants' => [$owner->id => ['example/private-repository']],
            'repository-access.write_grants' => [$owner->id => ['example/private-repository']]]);
        $project = Project::factory()->for($owner)->local()->publishing()->create(['repository_url' => self::REPOSITORY]);
        $run = QaRun::factory()->for($project)->create();
        [$model, $github, $sandbox] = $this->prepareInitialServerFailure($run);
        $sourcePath = 'app/Registration.php';
        $testPath = 'tests/Feature/OptionalPhoneTest.php';
        $files = [
            $sourcePath => '<?php // registration accepts an optional phone',
            $testPath => '<?php // regression for omitted optional phone',
        ];
        $model->shouldReceive('decide')->times(3)->andReturn(
            ['files' => [$sourcePath]],
            ['diagnosis' => 'Optional phone was not handled.', 'patch_files' => $files, 'regression_test' => $testPath],
            ['actions' => [['tool' => 'assert_text', 'text' => 'Account created']], 'original_assertion' => ['tool' => 'assert_text', 'text' => 'Account created']],
        );
        $github->shouldReceive('inspectRepository')->once()->with(self::REPOSITORY)->andReturn(['default_branch' => 'main']);
        $github->shouldReceive('repositoryTree')->once()->with(self::REPOSITORY, self::BASE_SHA)->andReturn([['path' => $sourcePath, 'size' => 100]]);
        $github->shouldReceive('readFiles')->once()->with(self::REPOSITORY, self::BASE_SHA, [$sourcePath])->andReturn([$sourcePath => '<?php // broken optional phone handling']);
        foreach (['assertPublicationAllowed', 'commitRepair', 'createDraftPullRequest'] as $method) {
            $github->shouldNotReceive($method);
        }
        $sandbox->shouldReceive('ready')->once()->andReturn(true);
        $sandbox->shouldReceive('verify')->once()->andReturnUsing(function () use ($files): array {
            config()->set('repository-access.grants', []);

            return [
                'passed' => true, 'source_sha' => self::BASE_SHA, 'patch_sha256' => hash('sha256', json_encode($files)),
                'baseline_suite' => true, 'baseline_regression_assertion' => true, 'patched_regression' => true,
                'patched_suite' => true, 'original_replay' => true, 'control_replay' => true,
            ];
        });

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('blocked', $run->status);
        $this->assertSame(__('repository-access.write_denied'), $run->report['summary']);
        $this->assertSame($files, $run->patch_files);
        $this->assertTrue($run->verification['passed']);
        $this->assertNull($run->pr_url);
        $this->assertArrayNotHasKey('commit_sha', $run->report);
        Http::assertNothingSent();
    }

    /** @return array{0: MockInterface, 1: MockInterface, 2: MockInterface} */
    private function prepareInitialServerFailure(QaRun $run): array
    {
        $project = $run->project;
        $snapshot = ['url' => $project->website_url, 'source_sha' => self::BASE_SHA, 'title' => 'Server error', 'text' => 'Server error', 'elements' => []];
        $this->mock(TargetPolicy::class)->shouldReceive('assertAllowed')->once()->with($project->website_url, $project->target_mode);
        $browser = $this->mock(BrowserWorker::class);
        $browser->shouldReceive('start')->once()->with($project->website_url, $project->target_mode)->andReturn([
            'session_id' => 'browser-session', 'snapshot' => $snapshot,
            'network_errors' => [['url' => $project->website_url, 'status' => 500]],
        ]);
        $browser->shouldReceive('act')->once()->with('browser-session', ['tool' => 'screenshot'])
            ->andReturn(['snapshot' => $snapshot, 'artifact' => 'browser-denied-finding.png']);
        $browser->shouldReceive('close')->once()->with('browser-session')->andReturn(['closed' => true, 'artifacts' => []]);
        $model = $this->mock(ModelClient::class);
        $model->shouldReceive('forUser')->once()->withArgs(fn (User $user): bool => $user->is($project->user))->andReturnSelf();

        return [$model, $this->mock(GitHubAppClient::class), $this->mock(RepairSandbox::class)];
    }
}
