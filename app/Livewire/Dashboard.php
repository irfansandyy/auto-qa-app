<?php

namespace App\Livewire;

use App\Jobs\ExecuteQaRun;
use App\Models\Project;
use App\Models\QaRun;
use App\Services\BrowserWorker;
use App\Services\GitHub\GitHubAppClient;
use App\Services\GitHub\RepositoryUrl;
use App\Services\ModelClient;
use App\Services\QaSectors;
use App\Services\RepositoryAccess;
use App\Services\TargetPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Dashboard extends Component
{
    public const TERMINAL_STATUSES = [
        'completed', 'failed', 'cancelled', 'blocked', 'needs_configuration',
        'verification_failed', 'suggestion_ready', 'ready_for_review', 'report_ready',
    ];

    #[Locked]
    public ?int $selectedProjectId = null;

    #[Locked]
    public ?int $selectedRunId = null;

    #[Locked]
    public ?int $editingProjectId = null;

    public string $panel = 'workspace';

    #[Locked]
    public ?int $repositoryRunId = null;

    #[Locked]
    public ?int $repositoryPullNumber = null;

    public bool $showingProjectForm = false;

    public string $name = '';

    public string $repositoryUrl = '';

    public string $websiteUrl = '';

    public string $targetMode = 'public';

    public string $baseBranch = '';

    public string $deployedSha = '';

    public string $repairPolicy = 'verified_pr';

    public array $qaSectors = QaSectors::ALL;

    public string $runGuidance = '';

    public string $findingSector = 'all';

    public string $findingSeverity = 'all';

    public ?string $notice = null;

    public ?array $connection = null;

    public function mount(): void
    {
        $requested = request()->integer('project');
        $first = $requested > 0 ? $this->projects()->findOrFail($requested) : $this->projects()->first();
        if ($first) {
            $this->selectProject($first->id);
        } else {
            $this->showingProjectForm = true;
        }
        $panel = request()->query('panel');
        if (is_string($panel) && in_array($panel, ['settings', ...RepositoryWorkspace::SECTIONS], true)) {
            $this->setPanel($panel);
            $this->repositoryRunId = $panel === 'changes' ? (request()->integer('run') ?: null) : null;
            $this->repositoryPullNumber = $panel === 'pull-requests' ? (request()->integer('pull') ?: null) : null;
        }
    }

    public function hydrate(): void
    {
        $locale = auth()->user()?->locale;
        app()->setLocale(in_array($locale, ['en', 'id'], true) ? $locale : 'en');
    }

    public function setPanel(string $panel): void
    {
        abort_unless(in_array($panel, ['workspace', 'settings', ...RepositoryWorkspace::SECTIONS], true), 404);
        $this->panel = $panel;
        $this->reset(['repositoryRunId', 'repositoryPullNumber', 'notice']);
    }

    public function switchProject(int $id): void
    {
        $panel = $this->panel;
        $this->selectProject($id);
        if (in_array($panel, RepositoryWorkspace::SECTIONS, true)) {
            $this->setPanel($panel);
        }
    }

    public function newProject(): void
    {
        $this->reset(['name', 'repositoryUrl', 'websiteUrl', 'targetMode', 'baseBranch', 'deployedSha', 'repairPolicy', 'qaSectors', 'notice', 'connection']);
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
            $this->addError('run', __('messages.edit_active'));

            return;
        }
        $this->editingProjectId = $project->id;
        $this->name = $project->name;
        $this->repositoryUrl = $project->repository_url ?? '';
        $this->websiteUrl = $project->website_url;
        $this->targetMode = $project->target_mode;
        $this->baseBranch = $project->base_branch ?? '';
        $this->deployedSha = $project->deployed_sha ?? '';
        $this->repairPolicy = $project->repair_policy === 'report_only' ? 'verified_pr' : $project->repair_policy;
        $this->qaSectors = $project->qaSectors();
        $this->showingProjectForm = true;
        $this->panel = 'workspace';
        $this->notice = null;
        $this->resetValidation();
    }

    public function saveProject(TargetPolicy $targets): void
    {
        $this->repositoryUrl = trim($this->repositoryUrl);
        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'repositoryUrl' => ['nullable', 'url:https', 'max:500', 'regex:~^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:\.git)?/?$~'],
            'websiteUrl' => ['required', 'url:http,https', 'max:2000'],
            'targetMode' => ['required', Rule::in(['public', 'local'])],
            'baseBranch' => ['nullable', 'string', 'max:200', 'regex:~^[A-Za-z0-9][A-Za-z0-9._/-]*$~'],
            'deployedSha' => ['nullable', 'regex:/^[a-fA-F0-9]{40}$/'],
            'repairPolicy' => ['required', Rule::in(['suggest_only', 'verified_pr'])],
            'qaSectors' => ['present', 'array', 'max:'.count(QaSectors::ALL)],
            'qaSectors.*' => ['string', 'distinct', Rule::in(QaSectors::ALL)],
        ], [
            'repositoryUrl.regex' => __('messages.repository_url'),
            'deployedSha.regex' => __('messages.deployed_sha'),
        ], [
            'name' => __('messages.attributes.name'),
            'repositoryUrl' => __('messages.attributes.repositoryUrl'),
            'websiteUrl' => __('messages.attributes.websiteUrl'),
            'targetMode' => __('messages.attributes.targetMode'),
            'baseBranch' => __('messages.attributes.baseBranch'),
            'deployedSha' => __('messages.attributes.deployedSha'),
            'repairPolicy' => __('messages.attributes.repairPolicy'),
            'qaSectors' => __('qa-sectors.title'),
            'qaSectors.*' => __('qa-sectors.title'),
        ]);

        try {
            $targets->assertAllowed($this->websiteUrl, $this->targetMode);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['websiteUrl' => $exception->getMessage()]);
        }

        $attributes = [
            'user_id' => auth()->id(),
            'name' => trim($this->name),
            'repository_url' => $this->repositoryUrl !== '' ? rtrim($this->repositoryUrl, '/') : null,
            'website_url' => $this->websiteUrl,
            'target_mode' => $this->targetMode,
            'base_branch' => $this->repositoryUrl !== '' ? ($this->baseBranch ?: null) : null,
            'deployed_sha' => $this->repositoryUrl !== '' && $this->deployedSha ? strtolower($this->deployedSha) : null,
            'repair_policy' => $this->repositoryUrl !== '' ? $this->repairPolicy : 'report_only',
            'qa_sectors' => QaSectors::selected($this->qaSectors),
        ];

        if ($this->editingProjectId) {
            $project = DB::transaction(function () use ($attributes): Project {
                $project = $this->projects()->whereKey($this->editingProjectId)->lockForUpdate()->firstOrFail();
                if ($project->runs()->whereNotIn('status', self::TERMINAL_STATUSES)->exists()) {
                    throw ValidationException::withMessages([
                        'name' => __('messages.change_active'),
                    ]);
                }
                $project->update($attributes);

                return $project;
            });
        } else {
            $project = Project::query()->create([
                ...$attributes,
                'goal' => QaRun::AUTOMATIC_GOAL,
                'expected_behavior' => QaRun::AUTOMATIC_EXPECTED_BEHAVIOR,
            ]);
        }

        $this->selectProject($project->id);
        $this->notice = $project->repository_url ? __('messages.project_saved') : __('messages.website_project_saved');
    }

    public function selectProject(int $id): void
    {
        $project = $this->projects()->findOrFail($id);
        $this->selectedProjectId = $project->id;
        $this->selectedRunId = $project->runs()->latest('id')->value('id');
        $this->showingProjectForm = false;
        $this->editingProjectId = null;
        $this->connection = null;
        $this->runGuidance = '';
        $this->notice = null;
        $this->panel = 'workspace';
        $this->reset(['findingSector', 'findingSeverity']);
        $this->resetValidation();
    }

    public function selectRun(int $id): void
    {
        $run = $this->selectedProject()->runs()->findOrFail($id);
        $this->selectedRunId = $run->id;
        $this->reset(['findingSector', 'findingSeverity']);
    }

    public function checkConnection(GitHubAppClient $github, TargetPolicy $targets): void
    {
        $project = $this->selectedProject();
        $this->connection = null;

        try {
            $targets->assertAllowed($project->website_url, $project->target_mode);
            if (blank($project->repository_url)) {
                $this->connection = [
                    'ok' => true,
                    'can_push' => false,
                    'message' => __('messages.website_connection_ready'),
                ];

                return;
            }
            app(RepositoryAccess::class)->assertAllowed($project->user, $project->repository_url);
            $repository = $github->inspectRepository($project->repository_url);
            $this->connection = [
                'ok' => true,
                'repository' => $repository['full_name'],
                'can_push' => (bool) $repository['can_push'],
                'message' => $repository['can_push']
                    ? __('messages.connection_ready')
                    : __('messages.connection_readonly'),
            ];
            if (! $project->base_branch) {
                $project->update(['base_branch' => $repository['default_branch']]);
            }
        } catch (AuthorizationException $exception) {
            $this->connection = [
                'ok' => false,
                'can_push' => false,
                'message' => $exception->getMessage(),
            ];
        } catch (\Throwable $exception) {
            report($exception);
            $this->connection = [
                'ok' => false,
                'can_push' => false,
                'message' => __('messages.connection_failed'),
            ];
        }
    }

    public function startRun(): void
    {
        $validated = $this->validate(['runGuidance' => ['nullable', 'string', 'max:1000']], [], [
            'runGuidance' => __('ui.run_guidance'),
        ]);
        $guidance = trim((string) ($validated['runGuidance'] ?? ''));
        $project = $this->selectedProject();

        if ($issue = $this->runConfigurationIssue($project)) {
            $this->addError('run', $issue);

            return;
        }

        $run = DB::transaction(function () use ($project, $guidance): QaRun {
            $project = $this->projects()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($issue = $this->runConfigurationIssue($project)) {
                throw ValidationException::withMessages(['run' => $issue]);
            }
            if ($project->runs()->whereNotIn('status', self::TERMINAL_STATUSES)->exists()) {
                throw ValidationException::withMessages(['run' => __('messages.run_active')]);
            }

            return $project->runs()->create([
                'status' => 'queued',
                'automatic' => true,
                'advanced' => true,
                'guidance' => $guidance === '' ? null : $guidance,
                'goal' => QaRun::ADVANCED_GOAL,
                'expected_behavior' => QaRun::AUTOMATIC_EXPECTED_BEHAVIOR,
                'base_sha' => $project->deployed_sha,
                'qa_sectors' => $project->qaSectors(),
            ]);
        });

        $this->selectedRunId = $run->id;
        $this->runGuidance = '';
        $this->reset(['findingSector', 'findingSeverity']);
        ExecuteQaRun::dispatch($run->id);
        $this->notice = __('messages.run_queued');
        $this->resetValidation('run');
    }

    public function generateRunGuidance(): void
    {
        $project = $this->selectedProject();
        if (filled(trim($this->runGuidance))) {
            return;
        }

        $this->runGuidance = __('workspace.guidance_site');
        if ($project->repository_url) {
            $this->runGuidance .= "\n\n".__('workspace.guidance_code');
        }
        $this->resetValidation('runGuidance');
    }

    public function useFindingForGuidance(int $findingId): void
    {
        $run = $this->selectedProject()->runs()->findOrFail($this->selectedRunId);
        $finding = $run->findings()->findOrFail($findingId);
        $sector = $finding->evidence['sector'] ?? null;
        abort_unless(in_array($sector, QaSectors::ALL, true), 404);

        $line = __('qa-sectors.guidance_finding', [
            'sector' => __('qa-sectors.names.'.$sector),
            'title' => Str::limit($finding->title, 160),
        ]);
        $path = $finding->evidence['path'] ?? null;
        if (is_string($path) && preg_match('~\A(?:[a-zA-Z0-9_.-]+/)*[a-zA-Z0-9_.-]+\z~D', $path)) {
            $line .= ' '.__('qa-sectors.guidance_path', ['path' => Str::limit($path, 140)]);
        }
        $remediation = $finding->evidence['remediation'] ?? null;
        if (is_string($remediation)) {
            $line .= ' '.Str::limit(Str::squish($remediation), 260);
        }
        $existing = trim($this->runGuidance);
        if (str_contains($existing, $line)) {
            return;
        }

        $draft = $existing === '' ? $line : $existing."\n\n".$line;
        if (mb_strlen($draft) > 1000) {
            $this->addError('runGuidance', __('qa-sectors.guidance_full'));
        } else {
            $this->runGuidance = $draft;
            $this->resetValidation('runGuidance');
        }
        $this->dispatch('qa-guidance-filled');
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
        $this->notice = __('messages.cancelled');
    }

    private function projects(): Builder
    {
        return Project::query()->where('user_id', auth()->id())->latest('id');
    }

    private function selectedProject(): Project
    {
        return $this->projects()->findOrFail($this->selectedProjectId);
    }

    private function runConfigurationIssue(Project $project): ?string
    {
        $sectors = $project->qaSectors();

        return match (true) {
            $sectors === [] => __('qa-sectors.select_one'),
            ! QaSectors::needsBrowser($sectors) && ! $project->repository_url => __('qa-sectors.database_needs_repository'),
            QaSectors::needsBrowser($sectors) && ! app(BrowserWorker::class)->ready() => __('ui.configure_browser_hint'),
            default => null,
        };
    }

    private function runPullNumber(?QaRun $run, ?Project $project): ?int
    {
        if (! $run?->pr_url || ! $project?->repository_url) {
            return null;
        }

        try {
            $repository = RepositoryUrl::parse($project->repository_url)['full_name'];
        } catch (InvalidArgumentException) {
            return null;
        }

        return preg_match('~\Ahttps://github\.com/'.preg_quote($repository, '~').'/pull/([1-9][0-9]*)\z~i', $run->pr_url, $matches)
            ? (int) $matches[1]
            : null;
    }

    public function render(): View
    {
        $projects = $this->projects()->get();
        $project = $this->selectedProjectId ? $this->selectedProject() : null;
        $showRuns = $this->panel === 'workspace';
        $runs = $showRuns ? ($project?->runs()->latest('id')->limit(20)->get() ?? collect()) : collect();
        $run = $showRuns && $this->selectedRunId && $project
            ? $project->runs()->with(['steps', 'findings'])->findOrFail($this->selectedRunId)
            : null;

        $githubReady = (bool) config('github.app_id')
            && (bool) config('github.private_key_path')
            && is_file(config('github.private_key_path'));
        $runConfigurationIssue = $project ? $this->runConfigurationIssue($project) : null;

        return view('livewire.dashboard', [
            'projects' => $projects,
            'project' => $project,
            'runs' => $runs,
            'run' => $run,
            'runPullNumber' => $this->runPullNumber($run, $project),
            'repositorySections' => RepositoryWorkspace::SECTIONS,
            'githubReady' => $githubReady,
            'modelReady' => app(ModelClient::class)->forUser(auth()->user())->ready(),
            'browserReady' => app(BrowserWorker::class)->ready(),
            'canStartRun' => $runConfigurationIssue === null,
            'runConfigurationIssue' => $runConfigurationIssue,
            'availableQaSectors' => QaSectors::ALL,
            'terminalStatuses' => self::TERMINAL_STATUSES,
            'installationUrl' => GitHubAppClient::installationUrl(),
        ])->layout('layouts.app');
    }
}
