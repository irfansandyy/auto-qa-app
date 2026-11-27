<?php

namespace Tests\Feature;

use App\Jobs\ExecuteQaRun;
use App\Livewire\Dashboard;
use App\Models\Project;
use App\Models\QaRun;
use App\Models\User;
use App\Services\BrowserWorker;
use App\Services\GitHub\GitHubAppClient;
use App\Services\ModelClient;
use App\Services\QaAgent;
use App\Services\QaSectors;
use App\Services\RepositoryReview;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class QaSectorsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SITE = 'http://127.0.0.1:9000';

    private const REPOSITORY = 'https://github.com/example/laravel-demo';

    private const SOURCE_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SECTORS = ['security', 'ui', 'ux', 'performance', 'backend', 'database'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config([
            'autoqa.local_targets' => [self::SITE],
            'autoqa.browser_url' => 'http://127.0.0.1:3001',
            'autoqa.browser_worker_url' => 'http://127.0.0.1:3001',
            'autoqa.browser_worker_token' => str_repeat('t', 32),
        ]);
    }

    public function test_new_and_legacy_projects_start_with_all_six_sectors_and_legacy_runs_keep_that_default(): void
    {
        $operator = User::factory()->create();
        $component = Livewire::actingAs($operator)->test(Dashboard::class)
            ->assertSet('qaSectors', self::SECTORS)
            ->assertSee(__('qa-sectors.descriptions.database'));

        $project = Project::factory()->for($operator)->create(['qa_sectors' => null]);
        $run = QaRun::factory()->for($project)->create(['status' => 'completed', 'qa_sectors' => null]);

        $component->call('selectProject', $project->id)->call('editProject', $project->id)
            ->assertSet('qaSectors', self::SECTORS);
        $this->assertSame(self::SECTORS, $project->qaSectors());
        $this->assertSame(self::SECTORS, $run->qaSectors());
    }

    public function test_project_settings_can_change_without_modifying_the_queued_runs_sector_snapshot(): void
    {
        $operator = User::factory()->create();
        Queue::fake([ExecuteQaRun::class]);

        $component = Livewire::actingAs($operator)->test(Dashboard::class)
            ->set('name', 'Review portal')
            ->set('websiteUrl', self::SITE)
            ->set('repositoryUrl', self::REPOSITORY)
            ->set('targetMode', 'local')
            ->set('qaSectors', ['database', 'ui'])
            ->call('saveProject')->assertHasNoErrors()
            ->call('startRun')->assertHasNoErrors();

        $project = Project::query()->sole();
        $run = $project->runs()->sole();
        $this->assertSame(['ui', 'database'], $project->qa_sectors);
        $this->assertSame(['ui', 'database'], $run->qa_sectors);
        Queue::assertPushed(ExecuteQaRun::class, 1);

        $run->update(['status' => 'report_ready']);
        $component->call('editProject', $project->id)
            ->assertSet('qaSectors', ['ui', 'database'])
            ->set('qaSectors', ['performance'])
            ->call('saveProject')->assertHasNoErrors();

        $this->assertSame(['performance'], $project->fresh()->qa_sectors);
        $this->assertSame(['ui', 'database'], $run->fresh()->qa_sectors);
    }

    public function test_disabled_sectors_are_not_replaced_with_defaults_or_allowed_to_queue_an_empty_run(): void
    {
        $operator = User::factory()->create();
        Queue::fake([ExecuteQaRun::class]);

        Livewire::actingAs($operator)->test(Dashboard::class)
            ->set('name', 'Website')
            ->set('websiteUrl', self::SITE)
            ->set('targetMode', 'local')
            ->set('qaSectors', ['unsupported'])
            ->call('saveProject')->assertHasErrors(['qaSectors.0'])
            ->set('qaSectors', [])
            ->call('saveProject')->assertHasNoErrors()
            ->assertSee(__('qa-sectors.none_enabled'))
            ->call('startRun')->assertHasErrors(['run']);

        $this->assertSame([], Project::query()->sole()->qa_sectors);
        $this->assertDatabaseCount('qa_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_interface_only_run_requests_only_its_worker_signals_and_ignores_other_sections(): void
    {
        $project = Project::factory()->local()->create(['website_url' => self::SITE]);
        $run = QaRun::factory()->for($project)->create([
            'automatic' => true, 'advanced' => true, 'qa_sectors' => ['ui'],
        ]);
        $snapshot = ['url' => self::SITE, 'document_status' => 200, 'elements' => []];
        $browser = $this->mock(BrowserWorker::class);
        $browser->shouldReceive('start')->once()->with(self::SITE, 'local', true)
            ->andReturn(['session_id' => 'sector-browser', 'snapshot' => $snapshot]);
        $browser->shouldReceive('act')->once()->with('sector-browser', ['tool' => 'assert_page'])
            ->andReturn(['snapshot' => $snapshot, 'assertion' => ['passed' => true]]);
        $browser->shouldReceive('act')->once()->with('sector-browser', ['tool' => 'inspect_qa', 'sectors' => ['ui']])
            ->andReturn([
                'snapshot' => $snapshot,
                'frontend' => ['viewports' => ['mobile' => ['width' => 390]], 'findings' => [
                    ['code' => 'horizontal_overflow', 'count' => 40, 'viewport' => 'mobile'],
                    ['code' => 'missing_document_language', 'count' => 1, 'viewport' => 'mobile'],
                ]],
                'hardening' => ['nosniff' => false],
                'performance' => ['response_start_ms' => 2000],
            ]);
        $browser->shouldReceive('close')->once()->with('sector-browser')->andReturn(['artifacts' => []]);
        $this->mock(RepositoryReview::class)->shouldNotReceive('review');
        $this->mock(ModelClient::class)->shouldNotReceive('decide');

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('report_ready', $run->status, (string) $run->error);
        $this->assertSame('horizontal_overflow', $run->findings()->sole()->evidence['code']);
        $coverage = $run->report['audit']['sectors'];
        $this->assertSame('reviewed', $coverage['ui']['status']);
        $this->assertSame(1, $coverage['ui']['findings_count']);
        foreach (['security', 'ux', 'performance', 'backend', 'database'] as $sector) {
            $this->assertSame(['status' => 'disabled', 'checks' => [], 'findings_count' => 0], $coverage[$sector]);
        }
    }

    public function test_database_only_run_reviews_migration_source_without_browser_or_dependency_access(): void
    {
        $project = Project::factory()->create([
            'qa_sectors' => ['database'], 'repository_url' => self::REPOSITORY, 'deployed_sha' => self::SOURCE_SHA,
        ]);
        config(['repository-access.grants' => [$project->user_id => ['example/laravel-demo']]]);
        Queue::fake([ExecuteQaRun::class]);
        $browser = $this->mock(BrowserWorker::class);
        $browser->shouldReceive('ready')->andReturnFalse();
        $browser->shouldNotReceive('start');
        $browser->shouldNotReceive('act');
        $github = $this->mock(GitHubAppClient::class);
        $migration = 'database/migrations/2026_11_26_000000_retire_old_status.php';
        $code = 'app/Models/Order.php';
        $github->shouldReceive('repositoryTree')->once()->with(self::REPOSITORY, self::SOURCE_SHA, true)
            ->andReturn([['path' => $migration], ['path' => $code]]);
        $github->shouldReceive('readFiles')->once()->with(self::REPOSITORY, self::SOURCE_SHA, [$migration, $code])
            ->andReturn([
                $migration => '<?php return new class { public function up(): void { Schema::dropIfExists("retired_status"); } };',
                $code => '<?php final class Order {}',
            ]);
        $github->shouldNotReceive('readReviewLockfiles');
        $model = $this->mock(ModelClient::class);
        $model->shouldReceive('forUser')->withArgs(fn (User $user): bool => $user->is($project->user))->andReturnSelf();
        $model->shouldReceive('ready')->andReturnFalse();
        $model->shouldNotReceive('decide');

        Livewire::actingAs($project->user)->test(Dashboard::class)
            ->call('startRun')->assertHasNoErrors();
        $run = $project->runs()->sole();
        $this->assertSame(['database'], $run->qa_sectors);
        Queue::assertPushed(ExecuteQaRun::class, 1);

        app(QaAgent::class)->run($run->id);

        $run->refresh();
        $this->assertSame('report_ready', $run->status, (string) $run->error);
        $this->assertSame('disabled', $run->report['audit']['browser_status']);
        $this->assertSame('reviewed', $run->report['audit']['sectors']['database']['status']);
        $this->assertSame('destructive_forward_migration', $run->findings()->sole()->evidence['rule']);
    }

    public function test_run_findings_can_be_filtered_by_sector_and_severity_and_added_to_guidance(): void
    {
        $project = Project::factory()->create();
        $run = QaRun::factory()->for($project)->create([
            'status' => 'report_ready', 'automatic' => true, 'advanced' => true,
            'qa_sectors' => ['security', 'backend'],
        ]);
        $findings = $run->findings()->createMany([
            $this->finding('Unexpected browser policy', 'low', 'security'),
            $this->finding('Slow route response', 'low', 'backend'),
            $this->finding('Missing error handling', 'high', 'backend'),
        ]);
        $run->update(['report' => ['audit' => ['sectors' => QaSectors::coverage($run->qaSectors(), [], $findings)]]]);

        $component = Livewire::actingAs($project->user)->test(Dashboard::class)
            ->assertSee(__('qa-sectors.coverage'))
            ->assertSee('Unexpected browser policy')
            ->assertSee('Slow route response')
            ->assertSee('Missing error handling')
            ->set('findingSector', 'backend')
            ->assertDontSee('Unexpected browser policy')
            ->assertSee('Slow route response')
            ->set('findingSeverity', 'high')
            ->assertDontSee('Slow route response')
            ->assertSee('Missing error handling')
            ->call('useFindingForGuidance', $findings[2]->id);

        $this->assertStringContainsString('Missing error handling', $component->get('runGuidance'));
        $this->assertStringContainsString('app/Models/Order.php', $component->get('runGuidance'));
    }

    private function finding(string $title, string $severity, string $sector): array
    {
        return [
            'title' => $title, 'severity' => $severity, 'status' => 'observed', 'expected' => 'Expected behavior',
            'actual' => 'Observed result', 'evidence' => ['sector' => $sector, 'path' => 'app/Models/Order.php'],
        ];
    }
}
