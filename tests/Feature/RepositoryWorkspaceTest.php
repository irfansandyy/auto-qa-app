<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\RepositoryWorkspace;
use App\Models\Project;
use App\Models\QaRun;
use App\Models\User;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class RepositoryWorkspaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REPOSITORY = 'https://github.com/example/workspace';

    private const HEAD = '1111111111111111111111111111111111111111';

    private const BASE = '2222222222222222222222222222222222222222';

    private const BLOB = '3333333333333333333333333333333333333333';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    public function test_operator_can_browse_create_a_branch_and_commit_text_without_losing_whitespace(): void
    {
        $project = $this->projectWithGrant();
        $github = $this->mockFileWorkspace(existingReviewBranch: false);
        $github->shouldReceive('createWorkspaceBranch')->once()->with(self::REPOSITORY, 'main', 'review/form')->andReturn([
            'name' => 'review/form', 'sha' => self::HEAD, 'protected' => false,
        ]);
        $github->shouldReceive('workspaceTree')->once()->with(self::REPOSITORY, 'review/form', 'app')->andReturn([
            'entries' => [['name' => 'Form Sample.php', 'path' => 'app/Form Sample.php', 'type' => 'file', 'size' => 27]], 'truncated' => false,
        ]);
        $original = "  <?php\r\n    echo 'Old';\r\n\r\n";
        $edited = "  <?php\n    echo 'New';  \n\n";
        $committed = "  <?php\r\n    echo 'New';  \r\n\r\n";
        $this->expectFileRead($github, $original);
        $github->shouldReceive('commitWorkspaceFile')->once()->with(self::REPOSITORY, 'review/form', 'app/Form Sample.php', self::BLOB, $committed, 'Fix form')->andReturn([
            'branch' => 'review/form', 'sha' => self::HEAD, 'blob_sha' => self::BASE,
        ]);

        Livewire::actingAs($project->user)->test(RepositoryWorkspace::class, ['section' => 'files', 'projectId' => $project->id])
            ->assertSet('repositoryBranch', 'main')
            ->assertSee('app')
            ->set('repositoryNewBranch', 'review/form')
            ->call('createRepositoryBranch')
            ->assertSet('repositoryBranch', 'review/form')
            ->call('openRepositoryDirectory', 'app')
            ->assertSee('Form Sample.php')
            ->call('openRepositoryFile', 'app/Form Sample.php')
            ->call('editRepositoryFile')
            ->assertSet('repositoryEditorContent', $original)
            ->set('repositoryEditorContent', $edited)
            ->set('repositoryCommitMessage', 'Fix form')
            ->call('commitRepositoryFile')
            ->assertHasNoErrors()
            ->assertSet('repositoryError', null)
            ->assertSet('editingRepositoryFile', false)
            ->assertSet('repositoryFile.content', $committed)
            ->assertSet('repositoryFile.sha', self::BASE);

        $this->assertDatabaseHas('repository_audit_events', [
            'project_id' => $project->id, 'actor_id' => $project->user_id,
            'repository' => 'example/workspace', 'action' => 'commit', 'status' => 'succeeded',
        ]);
    }

    public function test_repository_read_grant_does_not_let_an_operator_open_an_editor_directly(): void
    {
        $project = $this->projectWithGrant(write: false);
        $github = $this->mockFileWorkspace();
        $this->expectFileRead($github, "<?php\n");
        $github->shouldNotReceive('commitWorkspaceFile');

        Livewire::actingAs($project->user)->test(RepositoryWorkspace::class, ['section' => 'files', 'projectId' => $project->id])
            ->call('selectRepositoryBranch', 'review/form')
            ->call('openRepositoryFile', 'app/Form Sample.php')
            ->assertDontSee(__('repository-workspace.edit_file'))
            ->call('editRepositoryFile')
            ->assertSet('repositoryError', __('repository-access.write_denied'))
            ->assertSet('editingRepositoryFile', false);
    }

    public function test_revoking_a_repository_grant_blocks_an_existing_workspace_session(): void
    {
        $project = $this->projectWithGrant();
        $github = $this->mockFileWorkspace();
        $github->shouldNotReceive('commitWorkspaceFile');
        $workspace = Livewire::actingAs($project->user)->test(RepositoryWorkspace::class, ['section' => 'files', 'projectId' => $project->id]);

        config(['repository-access.grants' => []]);

        $workspace->call('loadRepository')->assertForbidden();
    }

    public function test_changing_the_project_repository_blocks_a_commit_from_the_old_workspace(): void
    {
        $project = $this->projectWithGrant();
        $github = $this->mockFileWorkspace();
        $this->expectFileRead($github, "<?php\n");
        $github->shouldNotReceive('commitWorkspaceFile');
        $workspace = Livewire::actingAs($project->user)->test(RepositoryWorkspace::class, ['section' => 'files', 'projectId' => $project->id])
            ->call('selectRepositoryBranch', 'review/form')
            ->call('openRepositoryFile', 'app/Form Sample.php')
            ->call('editRepositoryFile')
            ->set('repositoryEditorContent', "<?php echo 'Changed';\n")
            ->set('repositoryCommitMessage', 'Fix form');

        config(['repository-access.grants' => [$project->user_id => ['example/workspace', 'example/new-workspace']]]);
        $project->update(['repository_url' => 'https://github.com/example/new-workspace']);

        $workspace->call('commitRepositoryFile')->assertStatus(409);
    }

    public function test_pull_request_review_merges_the_exact_reviewed_head_and_base(): void
    {
        $project = $this->projectWithGrant();
        $github = $this->mockPullRequestWorkspace(truncated: false);
        $github->shouldReceive('mergePullRequest')->once()->with(self::REPOSITORY, 42, self::HEAD, 'main', self::BASE)->andReturn([
            'sha' => self::BLOB, 'merged' => true,
        ]);

        Livewire::actingAs($project->user)->test(RepositoryWorkspace::class, ['section' => 'pull-requests', 'projectId' => $project->id, 'pullNumber' => 42])
            ->assertSet('repositoryPullRequest.number', 42)
            ->assertSee('Form review')
            ->assertSee('Updated line')
            ->assertSeeHtml('wire:click="mergeRepositoryPullRequest"')
            ->call('mergeRepositoryPullRequest')
            ->assertSet('repositoryPullRequest.merged', true)
            ->assertSet('repositoryPullRequest.state', 'closed')
            ->assertSet('repositoryError', null);
    }

    public function test_dashboard_url_opens_the_requested_project_and_native_pull_request(): void
    {
        $project = $this->projectWithGrant();
        $project->update(['name' => 'Workspace integration']);
        Project::factory()->for($project->user)->create(['name' => 'A different project', 'repository_url' => null]);
        $this->mockPullRequestWorkspace(truncated: false);

        $this->actingAs($project->user)->get(route('dashboard', ['panel' => 'pull-requests', 'project' => $project->id, 'pull' => 42]))
            ->assertOk()
            ->assertSee('Workspace integration')
            ->assertSee('Form review')
            ->assertSee('Updated line')
            ->assertDontSeeHtml('<iframe');
    }

    public function test_filename_search_uses_the_selected_branch_and_opens_authorized_results(): void
    {
        $project = $this->projectWithGrant();
        $github = $this->mockFileWorkspace();
        $github->shouldReceive('searchWorkspaceFiles')->once()->with(self::REPOSITORY, 'review/form', 'sample')->andReturn([
            'items' => [['name' => 'Form Sample.php', 'path' => 'app/Form Sample.php']], 'truncated' => false,
        ]);
        $this->expectFileRead($github, "<?php\n");

        Livewire::actingAs($project->user)->test(RepositoryWorkspace::class, ['section' => 'files', 'projectId' => $project->id])
            ->call('selectRepositoryBranch', 'review/form')
            ->set('repositorySearch', 'sample')
            ->call('searchRepositoryFiles')
            ->assertSee('app/Form Sample.php')
            ->call('openRepositoryFile', 'app/Form Sample.php')
            ->assertSet('repositoryFile.sha', self::BLOB);
    }

    public function test_required_check_blocks_merge_and_an_inline_comment_is_recorded_with_its_actor(): void
    {
        $project = $this->projectWithGrant();
        $github = $this->mockPullRequestWorkspace(truncated: false);
        $github->shouldNotReceive('mergePullRequest');
        $github->shouldReceive('pullRequestQuality')->once()->with(self::REPOSITORY, 42, self::HEAD, 'main')->andReturn([
            'checks' => [['name' => 'Backend tests', 'required' => true, 'state' => 'failed']], 'reviews' => [],
            'blockers' => [], 'unavailable' => [], 'truncated' => false,
        ]);
        $github->shouldReceive('listPullRequestComments')->once()->with(self::REPOSITORY, 42)->andReturn(['comments' => [], 'truncated' => false]);
        $github->shouldReceive('createPullRequestComment')->once()->with(self::REPOSITORY, 42, self::HEAD, 'app/Form.php', 1, 'RIGHT', 'Please add a regression test.')->andReturn([
            'id' => 23, 'path' => 'app/Form.php', 'line' => 1, 'author' => 'qa-app[bot]', 'body' => 'Please add a regression test.',
        ]);

        $workspace = Livewire::actingAs($project->user)->test(RepositoryWorkspace::class, ['section' => 'pull-requests', 'projectId' => $project->id, 'pullNumber' => 42])
            ->call('loadRepositoryReview')->assertSee('Backend tests')->assertSee('Failed')
            ->assertDontSeeHtml('wire:click="mergeRepositoryPullRequest"')
            ->call('beginRepositoryComment', 'app/Form.php', 1, 'RIGHT')
            ->set('repositoryCommentBody', 'Please add a regression test.')
            ->call('submitRepositoryComment')->assertSee('qa-app[bot]')->assertSee('Please add a regression test.');

        $this->assertDatabaseHas('repository_audit_events', [
            'project_id' => $project->id, 'actor_id' => $project->user_id,
            'repository' => 'example/workspace', 'action' => 'comment', 'status' => 'succeeded',
        ]);
        $workspace->call('mergeRepositoryPullRequest')->assertForbidden();
    }

    public function test_pull_request_with_an_incomplete_diff_cannot_be_merged_from_the_workspace(): void
    {
        $project = $this->projectWithGrant();
        $this->mockPullRequestWorkspace(truncated: true)->shouldNotReceive('mergePullRequest');

        Livewire::actingAs($project->user)->test(RepositoryWorkspace::class, ['section' => 'pull-requests', 'projectId' => $project->id, 'pullNumber' => 42])
            ->assertSet('repositoryChangesTruncated', true)
            ->assertSee(__('repository-workspace.diff_truncated'))
            ->assertDontSeeHtml('wire:click="mergeRepositoryPullRequest"')
            ->call('mergeRepositoryPullRequest')
            ->assertForbidden();
    }

    public function test_agent_proposal_shows_a_native_diff_against_the_run_baseline(): void
    {
        $project = $this->projectWithGrant();
        $run = QaRun::factory()->for($project)->create(['base_sha' => self::BASE, 'patch_files' => ['app/Form.php' => "Updated line\n"]]);
        $this->mock(GitHubAppClient::class)->shouldReceive('readWorkspaceFile')->once()->with(self::REPOSITORY, self::BASE, 'app/Form.php')->andReturn([
            'path' => 'app/Form.php', 'sha' => self::BLOB, 'content' => "Old line\n", 'editable' => true,
        ]);

        Livewire::actingAs($project->user)->test(RepositoryWorkspace::class, ['section' => 'changes', 'projectId' => $project->id, 'runId' => $run->id])
            ->assertSee('app/Form.php')
            ->call('openRepositoryChange', 'app/Form.php')
            ->assertSet('repositoryChanges.0.additions', 1)
            ->assertSet('repositoryChanges.0.deletions', 1)
            ->assertSee('Old line')
            ->assertSee('Updated line');
    }

    public function test_prompt_draft_follows_the_project_and_preserves_existing_guidance(): void
    {
        $project = $this->projectWithGrant();
        $siteOnly = Project::factory()->for($project->user)->create(['repository_url' => null]);

        Livewire::actingAs($project->user)->test(Dashboard::class)
            ->call('switchProject', $project->id)
            ->set('runSectors', ['api'])
            ->call('generateRunGuidance')
            ->assertSet('runGuidance', fn (string $guidance): bool => str_contains($guidance, __('workspace.guidance_code'))
                && str_contains($guidance, 'api.') && ! str_contains($guidance, 'security.') && mb_strlen($guidance) <= 1000)
            ->set('runGuidance', 'Check the optional phone number.')
            ->call('generateRunGuidance')
            ->assertSet('runGuidance', 'Check the optional phone number.')
            ->call('switchProject', $siteOnly->id)
            ->call('generateRunGuidance')
            ->assertSet('runGuidance', fn (string $guidance): bool => str_contains($guidance, __('workspace.guidance_site'))
                && ! str_contains($guidance, __('workspace.guidance_code')));
    }

    private function projectWithGrant(bool $write = true): Project
    {
        $operator = User::factory()->create();
        config([
            'repository-access.grants' => [$operator->id => ['example/workspace']],
            'repository-access.write_grants' => $write ? [$operator->id => ['example/workspace']] : [],
        ]);

        return Project::factory()->for($operator)->create(['repository_url' => self::REPOSITORY]);
    }

    private function mockWorkspace(): MockInterface
    {
        $github = $this->mock(GitHubAppClient::class);
        $github->shouldReceive('workspacePermissions')->once()->with(self::REPOSITORY)->andReturn([
            'default_branch' => 'main', 'can_write_contents' => true, 'can_write_pull_requests' => true,
        ]);

        return $github;
    }

    private function mockFileWorkspace(bool $existingReviewBranch = true): MockInterface
    {
        $github = $this->mockWorkspace();
        $branches = [['name' => 'main', 'sha' => self::BASE, 'protected' => true]];
        if ($existingReviewBranch) {
            $branches[] = ['name' => 'review/form', 'sha' => self::HEAD, 'protected' => false];
        }
        $github->shouldReceive('listBranches')->once()->with(self::REPOSITORY, 1)->andReturn([
            'items' => $branches, 'has_more' => false,
        ]);
        $github->shouldReceive('workspaceTree')->with(self::REPOSITORY, 'main', '')->andReturn([
            'entries' => [['name' => 'app', 'path' => 'app', 'type' => 'dir', 'size' => null]], 'truncated' => false,
        ]);
        $github->shouldReceive('workspaceTree')->with(self::REPOSITORY, 'review/form', '')->andReturn(['entries' => [], 'truncated' => false]);

        return $github;
    }

    private function expectFileRead(MockInterface $github, string $content): void
    {
        $github->shouldReceive('readWorkspaceFile')->once()->with(self::REPOSITORY, 'review/form', 'app/Form Sample.php')->andReturn([
            'path' => 'app/Form Sample.php', 'sha' => self::BLOB, 'content' => $content, 'editable' => true,
        ]);
    }

    private function mockPullRequestWorkspace(bool $truncated): MockInterface
    {
        $pull = [
            'number' => 42, 'title' => 'Form review', 'body' => '', 'state' => 'open', 'draft' => false, 'merged' => false, 'mergeable' => true,
            'head_ref' => 'review/form', 'head_sha' => self::HEAD, 'base_ref' => 'main', 'base_sha' => self::BASE,
            'author' => 'review-bot', 'updated_at' => '2026-11-27T08:00:00Z', 'changed_files' => 1, 'html_url' => self::REPOSITORY.'/pull/42', 'node_id' => 'PR_42', 'can_merge' => true,
        ];
        $github = $this->mockWorkspace();
        $github->shouldReceive('listPullRequests')->with(self::REPOSITORY, 'open', 1)->andReturn(['items' => [$pull], 'has_more' => false]);
        $github->shouldReceive('getPullRequest')->twice()->with(self::REPOSITORY, 42)->andReturn($pull);
        $github->shouldReceive('listPullRequestFiles')->once()->with(self::REPOSITORY, 42)->andReturn([
            'files' => [[
                'path' => 'app/Form.php', 'previous_path' => null, 'status' => 'modified', 'additions' => 1, 'deletions' => 1,
                'patch' => $truncated ? null : "@@ -1 +1 @@\n-Old line\n+Updated line",
            ]],
            'truncated' => $truncated,
        ]);

        return $github;
    }
}
