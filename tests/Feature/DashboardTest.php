<?php

namespace Tests\Feature;

use App\Jobs\ExecuteQaRun;
use App\Livewire\Dashboard;
use App\Models\Project;
use App\Models\QaRun;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'autoqa.local_targets' => ['http://127.0.0.1:9000'],
            'autoqa.model_url' => 'http://127.0.0.1:8080/v1',
            'autoqa.browser_url' => 'http://127.0.0.1:3001',
            'autoqa.browser_worker_url' => 'http://127.0.0.1:3001',
            'autoqa.browser_worker_token' => str_repeat('t', 32),
        ]);
    }

    public function test_dashboard_redirects_unauthenticated_visitors(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect(route('login'));
    }

    public function test_dashboard_only_shows_operator_owned_projects(): void
    {
        $operator = User::factory()->create();
        Project::factory()->for($operator)->create(['name' => 'Visible project']);
        Project::factory()->create(['name' => 'Another operator private project']);

        $response = $this->actingAs($operator)->get('/dashboard');

        $response->assertOk()->assertSee('Visible project')->assertDontSee('Another operator private project');
    }

    public function test_saving_local_project_records_owner_goal_and_explicit_repair_policy(): void
    {
        $operator = User::factory()->create();

        Livewire::actingAs($operator)->test(Dashboard::class)
            ->set('name', 'Student portal')
            ->set('repositoryUrl', 'https://github.com/example/student-portal')
            ->set('websiteUrl', 'http://127.0.0.1:9000/register')
            ->set('targetMode', 'local')
            ->set('goal', 'Register without a phone number and then sign in.')
            ->set('expectedBehavior', 'Phone is optional and registration succeeds.')
            ->set('repairPolicy', 'verified_pr')
            ->call('saveProject')->assertHasNoErrors();

        $this->assertDatabaseHas('projects', [
            'user_id' => $operator->id, 'name' => 'Student portal',
            'repository_url' => 'https://github.com/example/student-portal',
            'website_url' => 'http://127.0.0.1:9000/register',
            'target_mode' => 'local', 'repair_policy' => 'verified_pr',
            'expected_behavior' => 'Phone is optional and registration succeeds.',
        ]);
    }

    public function test_project_requires_repository_website_goal_and_expected_behavior(): void
    {
        $operator = User::factory()->create();

        Livewire::actingAs($operator)->test(Dashboard::class)
            ->call('saveProject')->assertHasErrors(['name', 'repositoryUrl', 'websiteUrl', 'goal', 'expectedBehavior']);

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_editing_project_adds_deployed_version_without_creating_duplicate(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->for($operator)->local()->create([
            'website_url' => 'http://127.0.0.1:9000', 'deployed_sha' => null,
        ]);

        Livewire::actingAs($operator)->test(Dashboard::class)
            ->call('editProject', $project->id)
            ->set('deployedSha', str_repeat('b', 40))
            ->call('saveProject')->assertHasNoErrors();

        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id, 'user_id' => $operator->id, 'deployed_sha' => str_repeat('b', 40),
        ]);
    }

    public function test_editing_project_rejects_another_operator_project_with_404(): void
    {
        $operator = User::factory()->create();
        $foreign = Project::factory()->create();

        Livewire::actingAs($operator)->test(Dashboard::class)
            ->call('editProject', $foreign->id)->assertNotFound();

        $this->assertModelExists($foreign);
    }

    public function test_active_run_blocks_changes_to_project_configuration(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->for($operator)->create();
        QaRun::factory()->for($project)->create(['status' => 'testing']);

        Livewire::actingAs($operator)->test(Dashboard::class)
            ->call('editProject', $project->id)->assertHasErrors('run')
            ->assertSet('showingProjectForm', false);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'goal' => $project->goal]);
    }

    public function test_local_project_rejects_origin_not_allowed_by_administrator(): void
    {
        $operator = User::factory()->create();

        Livewire::actingAs($operator)->test(Dashboard::class)
            ->set('name', 'Portal')
            ->set('repositoryUrl', 'https://github.com/example/portal')
            ->set('websiteUrl', 'http://127.0.0.1:9999')
            ->set('targetMode', 'local')
            ->set('goal', 'Check registration.')
            ->set('expectedBehavior', 'Registration succeeds.')
            ->call('saveProject')->assertHasErrors('websiteUrl')
            ->assertSee('This local address is not configured on the browser server.');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_project_selection_rejects_another_operator_project_with_404(): void
    {
        $operator = User::factory()->create();
        $other = Project::factory()->create();

        Livewire::actingAs($operator)->test(Dashboard::class)
            ->call('selectProject', $other->id)->assertNotFound();
    }

    public function test_run_selection_rejects_another_operator_run_with_404(): void
    {
        $operator = User::factory()->create();
        Project::factory()->for($operator)->create();
        $foreign = QaRun::factory()->create();

        Livewire::actingAs($operator)->test(Dashboard::class)
            ->call('selectRun', $foreign->id)->assertNotFound();
    }

    public function test_starting_run_snapshots_goal_and_queues_work_for_owned_project(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->for($operator)->create(['deployed_sha' => str_repeat('a', 40)]);
        Queue::fake([ExecuteQaRun::class]);

        Livewire::actingAs($operator)->test(Dashboard::class)
            ->call('startRun')->assertHasNoErrors();

        $this->assertDatabaseHas('qa_runs', [
            'project_id' => $project->id, 'status' => 'queued',
            'goal' => $project->goal, 'expected_behavior' => $project->expected_behavior,
            'base_sha' => str_repeat('a', 40),
        ]);
        $run = QaRun::query()->sole();
        Queue::assertPushed(ExecuteQaRun::class, fn (ExecuteQaRun $job): bool => $job->runId === $run->id);
    }

    public function test_project_cannot_queue_a_second_active_run(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->for($operator)->create();
        QaRun::factory()->for($project)->create(['status' => 'testing']);
        Queue::fake();

        Livewire::actingAs($operator)->test(Dashboard::class)
            ->call('startRun')->assertHasErrors('run');

        $this->assertDatabaseCount('qa_runs', 1);
        Queue::assertNothingPushed();
    }

    #[TestWith(['needs_configuration'])]
    #[TestWith(['verification_failed'])]
    #[TestWith(['suggestion_ready'])]
    #[TestWith(['ready_for_review'])]
    public function test_finished_attempt_does_not_block_a_new_run(string $status): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->for($operator)->create();
        QaRun::factory()->for($project)->create(['status' => $status]);
        Queue::fake([ExecuteQaRun::class]);

        Livewire::actingAs($operator)->test(Dashboard::class)
            ->call('startRun')->assertHasNoErrors();

        $this->assertDatabaseCount('qa_runs', 2);
        Queue::assertPushed(ExecuteQaRun::class, 1);
    }

    public function test_missing_model_configuration_blocks_queueing_with_actionable_message(): void
    {
        $operator = User::factory()->create();
        Project::factory()->for($operator)->create();
        config(['autoqa.model_url' => '']);
        Queue::fake();

        Livewire::actingAs($operator)->test(Dashboard::class)
            ->call('startRun')->assertHasErrors('run')
            ->assertSee('Configure the browser worker and model service');

        $this->assertDatabaseCount('qa_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_cancel_records_operator_owned_run_as_cancelled(): void
    {
        $this->freezeTime();
        $operator = User::factory()->create();
        $project = Project::factory()->for($operator)->create();
        $run = QaRun::factory()->for($project)->create(['status' => 'testing']);

        Livewire::actingAs($operator)->test(Dashboard::class)
            ->call('cancelRun', $run->id)->assertHasNoErrors();

        $this->assertDatabaseHas('qa_runs', [
            'id' => $run->id, 'status' => 'cancelled', 'finished_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_cancel_rejects_another_operator_run_without_mutation(): void
    {
        $operator = User::factory()->create();
        Project::factory()->for($operator)->create();
        $foreign = QaRun::factory()->create(['status' => 'testing']);

        Livewire::actingAs($operator)->test(Dashboard::class)
            ->call('cancelRun', $foreign->id)->assertNotFound();

        $this->assertDatabaseHas('qa_runs', ['id' => $foreign->id, 'status' => 'testing', 'finished_at' => null]);
    }

    public function test_cancel_does_not_change_completed_run_result(): void
    {
        $operator = User::factory()->create();
        $project = Project::factory()->for($operator)->create();
        $run = QaRun::factory()->for($project)->create(['status' => 'completed']);

        Livewire::actingAs($operator)->test(Dashboard::class)
            ->call('cancelRun', $run->id)->assertHasNoErrors();

        $this->assertDatabaseHas('qa_runs', ['id' => $run->id, 'status' => 'completed']);
    }

    public function test_project_text_is_escaped_when_rendered(): void
    {
        $operator = User::factory()->create();
        $unsafe = '<script>alert("project")</script>';
        Project::factory()->for($operator)->create(['name' => $unsafe, 'goal' => $unsafe, 'expected_behavior' => $unsafe]);

        $response = $this->actingAs($operator)->get('/dashboard');

        $response->assertOk()->assertSee($unsafe)->assertDontSee($unsafe, false);
    }
}
