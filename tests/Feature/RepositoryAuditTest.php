<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\RepositoryAuditEvent;
use App\Models\User;
use App\Services\RepositoryAudit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RepositoryAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REPOSITORY_URL = 'https://github.com/example/project';

    public function test_audited_action_records_the_actor_before_the_external_write_and_keeps_only_safe_details(): void
    {
        $project = Project::factory()->create(['repository_url' => self::REPOSITORY_URL]);
        $this->grant($project->user, ['example/project']);
        $sha = str_repeat('a', 40);
        $audit = app(RepositoryAudit::class);

        $result = $audit->run($project, $project->user, 'commit', function () use ($project, $sha): array {
            $this->assertDatabaseHas('repository_audit_events', [
                'project_id' => $project->id, 'actor_id' => $project->user_id, 'action' => 'commit', 'status' => 'requested',
            ]);

            return ['sha' => $sha, 'token' => 'not recorded'];
        }, ['branch' => 'fix/form', 'path' => 'app/Form.php', 'content' => 'not recorded']);

        $this->assertSame($sha, $result['sha']);
        $event = $this->actingAs($project->user)->app->make(RepositoryAudit::class)->recent($project)->sole();
        $this->assertSame($project->user->name, $event->actor_name);
        $this->assertSame('succeeded', $event->status);
        $this->assertSame(['branch' => 'fix/form', 'path' => 'app/Form.php', 'commit_sha' => $sha], $event->details);
    }

    public function test_an_uncertain_github_outcome_retains_the_request_without_recording_the_exception(): void
    {
        $project = Project::factory()->create(['repository_url' => self::REPOSITORY_URL]);
        $this->grant($project->user, ['example/project']);
        $audit = app(RepositoryAudit::class);

        try {
            $audit->run($project, $project->user, 'merge', fn () => throw new RuntimeException('External timeout'), ['pull_number' => 5]);
            $this->fail('The external error should reach the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('External timeout', $exception->getMessage());
        }

        $event = RepositoryAuditEvent::query()->sole();
        $this->assertSame('unconfirmed', $event->status);
        $this->assertSame(['pull_number' => 5], $event->details);
    }

    public function test_an_operator_cannot_see_another_projects_audit_history_even_with_the_same_repository_grant(): void
    {
        $project = Project::factory()->create(['repository_url' => self::REPOSITORY_URL]);
        RepositoryAuditEvent::factory()->for($project)->create();
        $differentOperator = User::factory()->create();
        $this->grant($differentOperator, ['example/project']);
        $this->actingAs($differentOperator);

        $this->expectException(AuthorizationException::class);

        app(RepositoryAudit::class)->recent($project);
    }

    public function test_an_owner_without_write_access_cannot_invoke_the_audited_write(): void
    {
        $project = Project::factory()->create(['repository_url' => self::REPOSITORY_URL]);
        config(['repository-access.grants' => [$project->user_id => ['example/project']]]);

        try {
            app(RepositoryAudit::class)->run($project, $project->user, 'branch_created', fn () => $this->fail('The write must be blocked.'));
            $this->fail('The write grant is required.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('repository_audit_events', 0);
        }
    }

    public function test_repointing_a_project_hides_audit_records_for_the_previous_repository(): void
    {
        $project = Project::factory()->create(['repository_url' => self::REPOSITORY_URL]);
        RepositoryAuditEvent::factory()->for($project)->create();
        $this->grant($project->user, ['example/project', 'example/replacement']);
        $this->actingAs($project->user);
        $project->update(['repository_url' => 'https://github.com/example/replacement']);

        $this->assertEmpty(app(RepositoryAudit::class)->recent($project));
    }

    private function grant(User $operator, array $repositories): void
    {
        config([
            'repository-access.grants' => [$operator->id => $repositories],
            'repository-access.write_grants' => [$operator->id => $repositories],
        ]);
    }
}
