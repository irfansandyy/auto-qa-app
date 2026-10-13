<?php

namespace App\Livewire;

use App\Jobs\ExecuteQaRun;
use App\Models\Project;
use App\Models\QaRun;
use App\Services\BrowserWorker;
use App\Services\GitHub\GitHubAppClient;
use App\Services\ModelClient;
use App\Services\TargetPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Dashboard extends Component
{
    public const TERMINAL_STATUSES = [
        'completed', 'failed', 'cancelled', 'blocked', 'needs_configuration',
        'verification_failed', 'suggestion_ready', 'ready_for_review',
    ];

    #[Locked]
    public ?int $selectedProjectId = null;

    #[Locked]
    public ?int $selectedRunId = null;

    #[Locked]
    public ?int $editingProjectId = null;

    public string $panel = 'workspace';

    public bool $showingProjectForm = false;

    public string $name = '';

    public string $repositoryUrl = '';

    public string $websiteUrl = '';

    public string $targetMode = 'public';

    public string $goal = '';

    public string $expectedBehavior = '';

    public string $baseBranch = '';

    public string $deployedSha = '';

    public string $repairPolicy = 'verified_pr';

    public ?string $notice = null;

    public ?array $connection = null;

    public function mount(): void
    {
        $first = $this->projects()->first();
        if ($first) {
            $this->selectProject($first->id);
        } else {
            $this->showingProjectForm = true;
        }
    }

    public function setPanel(string $panel): void
    {
        abort_unless(in_array($panel, ['workspace', 'settings'], true), 404);
        $this->panel = $panel;
    }

    public function newProject(): void
    {
        $this->reset(['name', 'repositoryUrl', 'websiteUrl', 'targetMode', 'goal', 'expectedBehavior', 'baseBranch', 'deployedSha', 'repairPolicy', 'notice', 'connection']);
        $this->editingProjectId = null;
        $this->resetValidation();
        $this->panel = 'workspace';
        $this->showingProjectForm = true;
    }

    public function closeProjectForm(): void
    {
        $this->showingProjectForm = false;
        $this->editingProjectId = null;
        $this->resetValidation();
    }

    public function editProject(int $id): void
    {
        $project = $this->projects()->findOrFail($id);
        if ($project->runs()->whereNotIn('status', self::TERMINAL_STATUSES)->exists()) {
            $this->addError('run', 'Wait for the active run to finish or cancel it before editing this project.');

            return;
        }
        $this->editingProjectId = $project->id;
        $this->name = $project->name;
        $this->repositoryUrl = $project->repository_url;
        $this->websiteUrl = $project->website_url;
        $this->targetMode = $project->target_mode;
        $this->goal = $project->goal;
        $this->expectedBehavior = $project->expected_behavior;
        $this->baseBranch = $project->base_branch ?? '';
        $this->deployedSha = $project->deployed_sha ?? '';
        $this->repairPolicy = $project->repair_policy;
        $this->showingProjectForm = true;
        $this->panel = 'workspace';
        $this->notice = null;
        $this->resetValidation();
    }

    public function saveProject(TargetPolicy $targets): void
    {
        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'repositoryUrl' => ['required', 'url:https', 'max:500', 'regex:~^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:\.git)?/?$~'],
            'websiteUrl' => ['required', 'url:http,https', 'max:2000'],
            'targetMode' => ['required', Rule::in(['public', 'local'])],
            'goal' => ['required', 'string', 'min:5', 'max:4000'],
            'expectedBehavior' => ['required', 'string', 'min:5', 'max:6000'],
            'baseBranch' => ['nullable', 'string', 'max:200', 'regex:~^[A-Za-z0-9][A-Za-z0-9._/-]*$~'],
            'deployedSha' => ['nullable', 'regex:/^[a-fA-F0-9]{40}$/'],
            'repairPolicy' => ['required', Rule::in(['suggest_only', 'verified_pr'])],
        ], [
            'repositoryUrl.regex' => 'Enter a GitHub repository URL, such as https://github.com/owner/repository.',
            'deployedSha.regex' => 'Enter the full 40-character GitHub commit SHA deployed on your website.',
        ], [
            'repositoryUrl' => 'GitHub repository', 'websiteUrl' => 'website URL',
            'expectedBehavior' => 'expected behavior', 'goal' => 'testing goal',
        ]);

        try {
            $targets->assertAllowed($this->websiteUrl, $this->targetMode);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['websiteUrl' => $exception->getMessage()]);
        }

        $attributes = [
            'user_id' => auth()->id(),
            'name' => trim($this->name),
            'repository_url' => rtrim($this->repositoryUrl, '/'),
            'website_url' => $this->websiteUrl,
            'target_mode' => $this->targetMode,
            'goal' => trim($this->goal),
            'expected_behavior' => trim($this->expectedBehavior),
            'base_branch' => $this->baseBranch ?: null,
            'deployed_sha' => $this->deployedSha ? strtolower($this->deployedSha) : null,
            'repair_policy' => $this->repairPolicy,
        ];

        if ($this->editingProjectId) {
            $project = DB::transaction(function () use ($attributes): Project {
                $project = $this->projects()->whereKey($this->editingProjectId)->lockForUpdate()->firstOrFail();
                if ($project->runs()->whereNotIn('status', self::TERMINAL_STATUSES)->exists()) {
                    throw ValidationException::withMessages([
                        'name' => 'Wait for the active run to finish or cancel it before changing project settings.',
                    ]);
                }
                $project->update($attributes);

                return $project;
            });
        } else {
            $project = Project::query()->create($attributes);
        }

        $this->selectProject($project->id);
        $this->notice = 'Project saved. Check repository access before starting your first run.';
    }

    public function selectProject(int $id): void
    {
        $project = $this->projects()->findOrFail($id);
        $this->selectedProjectId = $project->id;
        $this->selectedRunId = $project->runs()->latest('id')->value('id');
        $this->showingProjectForm = false;
        $this->editingProjectId = null;
        $this->connection = null;
        $this->notice = null;
        $this->panel = 'workspace';
        $this->resetValidation();
    }

    public function selectRun(int $id): void
    {
        $run = $this->selectedProject()->runs()->findOrFail($id);
        $this->selectedRunId = $run->id;
    }

    public function checkConnection(GitHubAppClient $github, TargetPolicy $targets): void
    {
        $project = $this->selectedProject();
        $this->connection = null;

        try {
            $targets->assertAllowed($project->website_url, $project->target_mode);
            $repository = $github->inspectRepository($project->repository_url);
            $this->connection = [
                'ok' => true,
                'repository' => $repository['full_name'],
                'can_push' => (bool) $repository['can_push'],
                'message' => $repository['can_push']
                    ? 'Repository is accessible with commit permission. Website address is allowed; browser reachability is checked during the run.'
                    : 'Repository is accessible, but commit permission is missing. Check your GitHub App installation permissions.',
            ];
            if (! $project->base_branch) {
                $project->update(['base_branch' => $repository['default_branch']]);
            }
        } catch (\Throwable $exception) {
            report($exception);
            $this->connection = [
                'ok' => false,
                'can_push' => false,
                'message' => 'Connection check failed. Confirm the GitHub App is installed for this repository and the website address is allowed. The server log has details.',
            ];
        }
    }

    public function startRun(): void
    {
        $project = $this->selectedProject();

        if (! app(ModelClient::class)->ready() || ! app(BrowserWorker::class)->ready()) {
            $this->addError('run', 'Configure the browser worker and model service in Connections before starting a run.');

            return;
        }

        $run = DB::transaction(function () use ($project) {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($project->runs()->whereNotIn('status', self::TERMINAL_STATUSES)->exists()) {
                throw ValidationException::withMessages(['run' => 'This project already has an active run. Wait for it to finish or cancel it first.']);
            }

            return $project->runs()->create([
                'status' => 'queued',
                'goal' => $project->goal,
                'expected_behavior' => $project->expected_behavior,
                'base_sha' => $project->deployed_sha,
            ]);
        });

        $this->selectedRunId = $run->id;
        ExecuteQaRun::dispatch($run->id);
        $this->notice = 'Run queued. The worker will record its progress here.';
        $this->resetValidation('run');
    }

    public function cancelRun(int $id): void
    {
        $run = $this->selectedProject()->runs()->findOrFail($id);
        DB::transaction(function () use ($run) {
            $run = QaRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! in_array($run->status, self::TERMINAL_STATUSES, true)) {
                $run->update(['status' => 'cancelled', 'finished_at' => now()]);
            }
        });
        $this->notice = 'Cancellation requested. The worker stops at its next checkpoint.';
    }

    private function projects(): Builder
    {
        return Project::query()->where('user_id', auth()->id())->latest('id');
    }

    private function selectedProject(): Project
    {
        return $this->projects()->findOrFail($this->selectedProjectId);
    }

    public function render(): View
    {
        $projects = $this->projects()->get();
        $project = $this->selectedProjectId ? $this->selectedProject() : null;
        $runs = $project?->runs()->latest('id')->limit(20)->get() ?? collect();
        $run = $this->selectedRunId && $project
            ? $project->runs()->with(['steps', 'findings'])->findOrFail($this->selectedRunId)
            : null;

        $githubReady = (bool) config('github.app_id')
            && (bool) config('github.private_key_path')
            && is_file(config('github.private_key_path'));

        return view('livewire.dashboard', [
            'projects' => $projects,
            'project' => $project,
            'runs' => $runs,
            'run' => $run,
            'githubReady' => $githubReady,
            'modelReady' => app(ModelClient::class)->ready(),
            'browserReady' => app(BrowserWorker::class)->ready(),
            'terminalStatuses' => self::TERMINAL_STATUSES,
            'installationUrl' => config('github.app_slug')
                ? 'https://github.com/apps/'.rawurlencode(config('github.app_slug')).'/installations/new'
                : null,
        ])->layout('layouts.app');
    }
}
