<div class="workspace">
    @if ($panel === 'workspace' && $runs->contains(fn ($item) => ! in_array($item->status, $terminalStatuses, true)))
        <span wire:key="active-run-poller" wire:poll.2s hidden></span>
    @endif

    <aside class="sidebar" aria-label="{{ __('ui.workspace_navigation') }}">
        <a href="{{ route('dashboard') }}" class="brand">{{ __('ui.app_name') }}</a>

        <details class="project-switcher" x-data x-on:click.outside="$el.removeAttribute('open')" x-on:keydown.escape="$el.removeAttribute('open'); $el.querySelector('summary').focus()">
            @php
                $creatingProject = $panel === 'projects' && $showingProjectForm && ! $editingProjectId;
            @endphp
            <summary aria-label="{{ __('workspace.switch_project') }}">
                <x-icon :name="$project && ! $creatingProject ? 'folder' : 'add'" />
                <span>{{ $project && ! $creatingProject ? $project->name : __('ui.new_project') }}</span>
            </summary>
            <div class="project-switcher-menu">
                @foreach ($projects as $item)
                    <button type="button" wire:key="switch-project-{{ $item->id }}" wire:click="switchProject({{ $item->id }})" x-on:click="$el.closest('details').removeAttribute('open')" @if ($selectedProjectId === $item->id && ! $creatingProject) aria-current="true" @endif>
                        <x-icon name="folder" />
                        <span>{{ $item->name }}</span>
                    </button>
                @endforeach
                <button type="button" wire:click="newProject" x-on:click="$el.closest('details').removeAttribute('open')">
                    <x-icon name="add" />
                    <span>{{ __('ui.new_project') }}</span>
                </button>
            </div>
        </details>

        <nav class="main-nav" aria-label="{{ __('ui.main_navigation') }}">
            @foreach (['projects' => 'folder', 'workspace' => 'dashboard', 'rules' => 'assignment', 'files' => 'description', 'changes' => 'code', 'git' => 'commit', 'pull-requests' => 'merge', 'settings' => 'settings'] as $destination => $icon)
                <button type="button" wire:key="workspace-nav-{{ $destination }}" wire:click="setPanel('{{ $destination }}')" class="nav-item {{ $panel === $destination ? 'is-active' : '' }}" @if ($panel === $destination) aria-current="page" @endif>
                    <x-icon :name="$icon" />
                    {{ in_array($destination, ['projects', 'rules'], true) ? __('dashboard-panels.nav.'.$destination) : __('workspace.nav.'.$destination) }}
                </button>
            @endforeach
            <a href="{{ route('docs.github-app') }}" class="nav-item">
                <x-icon name="assignment" />
                {{ __('ui.help') }}
            </a>
        </nav>

        <div class="sidebar-bottom">
            <div class="operator">
                <div>
                    <strong>{{ auth()->user()->name }}</strong>
                    <span>{{ __('ui.workspace_operator') }}</span>
                </div>
                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button class="logout-button" aria-label="{{ __('ui.sign_out') }}" title="{{ __('ui.sign_out') }}">
                        <x-icon name="logout" />
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <main id="main" class="main-content">
        <header class="topbar">
            <div class="breadcrumb">
                {{ __('ui.workspace') }} <span aria-hidden="true">/</span>
                <strong>{{ in_array($panel, ['projects', 'rules'], true) ? __('dashboard-panels.nav.'.$panel) : __('workspace.nav.'.$panel) }}</strong>
            </div>
        </header>

        <div class="page-body">
            @if ($notice)
                <div class="notice notice-success" role="status">{{ $notice }}</div>
            @endif

            @if ($panel === 'settings')
                <div class="page-heading">
                    <div>
                        <h1>{{ __('ui.settings') }}</h1>
                        <p>{{ __('ui.settings_description') }}</p>
                    </div>
                </div>

                @if (session('preferences_saved'))
                    <div class="notice notice-success" role="status">{{ __('preferences.saved') }}</div>
                @endif

                <section class="card preferences-card" aria-labelledby="preferences-heading">
                    <div class="section-heading">
                        <div>
                            <h2 id="preferences-heading">{{ __('preferences.heading') }}</h2>
                            <p>{{ __('preferences.description') }}</p>
                        </div>
                    </div>

                    @if ($errors->has('locale') || $errors->has('theme'))
                        <div class="notice notice-error" role="alert">
                            @error('locale')<p>{{ $message }}</p>@enderror
                            @error('theme')<p>{{ $message }}</p>@enderror
                        </div>
                    @endif

                    <form method="POST" action="{{ route('settings.preferences') }}" x-data @change="$el.requestSubmit()">
                        @csrf
                        <div class="preference-row">
                            <div>
                                <h3 id="language-label">{{ __('preferences.language') }}</h3>
                                <p id="language-help">{{ __('preferences.language_help') }}</p>
                            </div>
                            <div class="segmented-control" role="radiogroup" aria-labelledby="language-label" aria-describedby="language-help">
                                <label>
                                    <input type="radio" name="locale" value="en" @checked(old('locale', app()->getLocale()) === 'en')>
                                    <span lang="en">English</span>
                                </label>
                                <label>
                                    <input type="radio" name="locale" value="id" @checked(old('locale', app()->getLocale()) === 'id')>
                                    <span lang="id">Bahasa Indonesia</span>
                                </label>
                            </div>
                        </div>

                        <div class="preference-row">
                            <div>
                                <h3 id="appearance-label">{{ __('preferences.appearance') }}</h3>
                                <p id="appearance-help">{{ __('preferences.appearance_help') }}</p>
                            </div>
                            <div class="segmented-control" role="radiogroup" aria-labelledby="appearance-label" aria-describedby="appearance-help">
                                <label>
                                    <input type="radio" name="theme" value="light" @checked(old('theme', $theme ?? 'dark') === 'light')>
                                    <span>{{ __('preferences.light') }}</span>
                                </label>
                                <label>
                                    <input type="radio" name="theme" value="dark" @checked(old('theme', $theme ?? 'dark') === 'dark')>
                                    <span>{{ __('preferences.dark') }}</span>
                                </label>
                            </div>
                        </div>

                        <noscript><button type="submit" class="button button-primary">{{ __('preferences.save') }}</button></noscript>
                    </form>
                </section>

                @include('partials.github-settings')
                @include('partials.model-settings')

                <div class="section-heading">
                    <h2>{{ __('ui.connections') }}</h2>
                </div>

                <div class="connection-grid">
                    <section class="card connection-card">
                        <div class="connection-card-top">
                            <x-icon name="browser" />
                            <span class="badge {{ $browserReady ? 'badge-success' : 'badge-warning' }}">{{ $browserReady ? __('ui.worker_configured') : __('ui.setup_needed') }}</span>
                        </div>
                        <h2>{{ __('ui.browser_worker') }}</h2>
                        <p>{{ __('ui.browser_worker_description') }}</p>
                        <p class="fine-print">{{ __('ui.browser_worker_hint') }}</p>
                    </section>

                    <section class="card connection-card">
                        <div class="connection-card-top">
                            <x-icon name="memory" />
                            <span class="badge {{ $modelReady ? 'badge-success' : 'badge-warning' }}">{{ $modelReady ? __('ui.address_configured') : __('ui.setup_needed') }}</span>
                        </div>
                        <h2>{{ __('ui.agent_model') }}</h2>
                        <p>{{ __('ui.agent_model_description') }}</p>
                        <p class="fine-print">{{ __('ui.model_settings_hint') }}</p>
                    </section>
                </div>

                <section class="card setup-guide">
                    <div class="section-heading">
                        <h2>{{ __('ui.setup_github_access') }}</h2>
                        <a class="text-link" href="https://github.com/settings/apps/new" target="_blank" rel="noopener noreferrer">{{ __('ui.create_github_app') }}</a>
                    </div>
                    <ol class="setup-steps">
                        <li>
                            <div>
                                <strong>{{ __('ui.create_app_heading') }}</strong>
                                <p>{{ __('ui.create_app_description') }}</p>
                            </div>
                        </li>
                        <li>
                            <div>
                                <strong>{{ __('ui.repository_permissions_heading') }}</strong>
                                <p>{{ __('ui.repository_permissions_description') }}</p>
                            </div>
                        </li>
                        <li>
                            <div>
                                <strong>{{ __('ui.configure_server_heading') }}</strong>
                                <p>{{ __('dashboard-panels.github.configure_server') }}</p>
                                <a class="inline-link" href="{{ route('docs.github-app') }}#permissions">{{ __('ui.github_app_guide') }}</a>
                            </div>
                        </li>
                        <li>
                            <div>
                                <strong>{{ __('ui.install_access_heading') }}</strong>
                                <p>{{ __('dashboard-panels.github.install_and_check') }}</p>
                            </div>
                        </li>
                    </ol>
                    <p class="guide-callout">
                        {{ __('ui.policy_authorization_before') }} <strong>{{ __('ui.verified_draft_pr') }}</strong> {{ __('ui.policy_authorization_after') }}
                    </p>
                </section>
            @elseif (in_array($panel, $repositorySections, true))
                <livewire:repository-workspace
                    :section="$panel"
                    :project-id="$project?->id"
                    :run-id="$repositoryRunId"
                    :pull-number="$repositoryPullNumber"
                    :key="'repository-'.$panel.'-'.$selectedProjectId.'-'.$repositoryRunId.'-'.$repositoryPullNumber"
                />
            @elseif ($panel === 'projects')
                @include('partials.project-management')
            @elseif ($panel === 'rules')
                @include('partials.qa-rulebook')
            @else
                <div class="page-heading">
                    <div>
                        <h1>{{ __('workspace.nav.workspace') }}</h1>
                        <p>{{ __('workspace.runs_description') }}</p>
                    </div>
                    @if ($project)
                        <button class="button button-secondary" type="button" wire:click="editProject({{ $project->id }})">
                            {{ __('dashboard-panels.project.settings') }}
                        </button>
                    @endif
                </div>

                @if ($project)
                    <section class="card project-overview">
                        <div class="project-overview-heading">
                            <div class="project-summary">
                                <h2>{{ $project->name }}</h2>
                                <div class="project-links">
                                    @if ($project->repository_url)
                                    <a href="{{ $project->repository_url }}" target="_blank" rel="noopener noreferrer">
                                        <x-icon name="github" />
                                        {{ preg_replace('~^https://github.com/~', '', $project->repository_url) }}
                                    </a>
                                    @endif
                                    <a href="{{ $project->website_url }}" target="_blank" rel="noopener noreferrer">
                                        <x-icon name="browser" />
                                        {{ $project->website_url }}
                                    </a>
                                </div>
                            </div>
                            <span class="project-target">{{ $project->target_mode === 'local' ? __('ui.local_target') : __('ui.public_target') }}</span>
                        </div>
                        @include('partials.run-setup')
                        <div class="project-overview-footer">
                            <span class="policy-label">{{ $project->repository_url ? __('ui.repair_policy_label').' '.($project->repair_policy === 'verified_pr' ? __('ui.verified_draft_pr') : __('ui.suggest_only')) : __('ui.website_only_label') }}</span>
                            <div class="action-row">
                                <button class="button button-secondary" type="button" wire:click="checkConnection" wire:loading.attr="disabled" wire:target="checkConnection">
                                    <span wire:loading.remove wire:target="checkConnection">{{ __('ui.check_connection') }}</span>
                                    <span wire:loading wire:target="checkConnection">{{ __('ui.checking_access') }}</span>
                                </button>
                                <button class="button button-primary" type="button" wire:click="startRun" wire:loading.attr="disabled" wire:target="startRun" @disabled(! $canStartRun)>
                                    <x-icon name="play" />
                                    <span wire:loading.remove wire:target="startRun">{{ __($project->repository_url && $project->repair_policy === 'verified_pr' ? 'ui.start_test_and_repair' : 'ui.start_qa_run') }}</span>
                                    <span wire:loading wire:target="startRun">{{ __('ui.queueing') }}</span>
                                </button>
                            </div>
                        </div>
                    </section>

                    @if ($connection)
                        <div class="notice {{ $connection['ok'] && ($connection['can_push'] || ! $project->repository_url) ? 'notice-success' : 'notice-warning' }}" role="status">
                            <p>{{ $connection['message'] }}</p>
                            @if (! empty($connection['needs_repository_grant']))
                                <div class="managed-project-actions">
                                    @if (config('github.client_id') && config('github.client_secret'))
                                        <a class="inline-link" href="{{ route('dashboard', ['panel' => 'settings']) }}#github-connection">{{ __('dashboard-panels.github.account_settings') }}</a>
                                    @endif
                                    <a class="inline-link" href="{{ route('docs.github-app') }}#approve">{{ __('dashboard-panels.project.get_repository_access') }}</a>
                                </div>
                            @elseif (filled($connection['head_sha'] ?? null))
                                <dl class="connection-details">
                                    @if (filled($connection['branch'] ?? null))
                                        <div>
                                            <dt>{{ __('ui.base_branch') }}</dt>
                                            <dd><code>{{ $connection['branch'] }}</code></dd>
                                        </div>
                                    @endif
                                    <div>
                                        <dt>{{ __('dashboard-panels.runs.branch_head') }}</dt>
                                        <dd><code>{{ $connection['head_sha'] }}</code></dd>
                                    </div>
                                </dl>
                            @endif
                        </div>
                    @endif
                    @error('run')
                        <div id="run" class="notice notice-error" role="alert">{{ $message }}</div>
                    @enderror
                    @if (! $canStartRun)
                        <div class="notice notice-warning">
                            {{ $runConfigurationIssue }}
                            @if ($runConfigurationIssue === __('ui.configure_browser_hint'))
                                <button class="inline-link" type="button" wire:click="setPanel('settings')">{{ __('ui.open_settings') }}</button>
                            @elseif ($runConfigurationIssue === __('qa-sectors.database_needs_repository'))
                                <button class="inline-link" type="button" wire:click="editProject({{ $project->id }})">{{ __('dashboard-panels.project.settings') }}</button>
                            @endif
                        </div>
                    @elseif ($project->repository_url && ! $modelReady)
                        <p class="source-note">{{ __('ui.repair_model_hint') }}</p>
                    @endif
                    @if ($project->repository_url && ! $project->deployed_sha)
                        <p class="source-note">{{ __('dashboard-panels.runs.sha_fallback') }}</p>
                    @endif

                    <div class="run-layout {{ $runs->isEmpty() ? 'is-empty' : '' }}">
                        @if ($runs->isNotEmpty())
                            <aside class="run-aside">
                                <section class="card history-card">
                                    <div class="section-heading">
                                        <h2>{{ __('ui.recent_runs') }}</h2>
                                    </div>
                                    @foreach ($runs as $previous)
                                        <button class="history-item {{ $selectedRunId === $previous->id ? 'is-selected' : '' }}" type="button" wire:click="selectRun({{ $previous->id }})" wire:key="history-{{ $previous->id }}">
                                            <span>
                                                <strong>{{ __('ui.run_number', ['number' => $previous->id]) }}</strong>
                                                <x-local-time :date="$previous->created_at" :identity="'history-'.$previous->id" />
                                                @if ($previous->advanced)
                                                    <span>{{ __($previous->guidance ? 'ui.guided_run' : 'ui.automatic_run') }}</span>
                                                @endif
                                            </span>
                                            <span class="history-status">
                                                <span class="history-indicator history-indicator-{{ in_array($previous->status, ['failed', 'blocked', 'needs_configuration', 'verification_failed'], true) ? 'attention' : (in_array($previous->status, $terminalStatuses, true) ? 'finished' : 'active') }}" aria-hidden="true"></span>
                                                {{ __('ui.status.'.$previous->status) }}
                                            </span>
                                        </button>
                                    @endforeach
                                </section>
                            </aside>
                        @endif
                        <section class="card run-main">
                            @if (! $run)
                                <div class="empty-run">
                                    <h2>{{ __('ui.no_runs') }}</h2>
                                    <p>{{ __('ui.start_project_hint') }}</p>
                                </div>
                            @else
                                @php
                                    $steps = $run->steps->sortBy('sequence');
                                    $runFinished = in_array($run->status, $terminalStatuses, true);
                                    $previewFor = function ($step): ?string {
                                        $name = $step->data['preview_artifact'] ?? null;

                                        return is_string($name) && preg_match('/\Abrowser-[a-zA-Z0-9-]+\.png\z/', $name) ? $name : null;
                                    };
                                    $latestBrowserStep = $steps->last(fn ($step) => $previewFor($step) !== null);
                                    $activityMessages = $run->automatic ? __('ui.automatic_activity') : [];
                                    $activityMessage = fn ($key, string $fallback, array $replacements = []): string =>
                                        is_string($key) && isset($activityMessages[$key])
                                            ? __('ui.automatic_activity.'.$key, $replacements)
                                            : $fallback;
                                    $browserStage = match ($run->status) {
                                        'queued' => 'queued',
                                        'testing' => ($steps->last()?->data['message'] ?? null) === 'repository_scan' ? 'reviewing_source' : ($latestBrowserStep ? 'testing' : 'opening'),
                                        'diagnosing' => 'diagnosing',
                                        'repairing' => 'repairing',
                                        default => 'finished',
                                    };
                                @endphp
                                <div class="section-heading">
                                    <h2>{{ __('ui.run_number', ['number' => $run->id]) }}</h2>
                                    <span class="badge {{ $run->status === 'completed' ? 'badge-success' : (in_array($run->status, ['failed', 'blocked', 'needs_configuration', 'verification_failed']) ? 'badge-warning' : 'badge-neutral') }}">
                                        {{ __('ui.status.'.$run->status) }}
                                    </span>
                                </div>
                                <div class="run-meta">
                                    <x-local-time :date="$run->created_at" :identity="'created-'.$run->id" :label="__('ui.created_at', ['time' => ''])" />
                                    @if ($run->finished_at)
                                        <x-local-time :date="$run->finished_at" :identity="'finished-'.$run->id" :label="__('ui.finished_at', ['time' => ''])" :time-only="true" />
                                    @endif
                                    @if ($run->advanced)
                                        <span>{{ __($run->guidance ? 'ui.guided_run' : 'ui.automatic_run') }}</span>
                                    @endif
                                    @if (! $runFinished)
                                        <button class="text-link danger-link" type="button" wire:click="cancelRun({{ $run->id }})" wire:loading.attr="disabled" wire:target="cancelRun">{{ __('ui.cancel_run') }}</button>
                                    @endif
                                </div>

                                @if ($run->error)
                                    <div class="notice notice-error" role="alert">{{ $run->error }}</div>
                                @endif

                                @include('partials.run-source-reference')

                                @if ($run->advanced && filled($run->guidance))
                                    <section class="finding-section" aria-labelledby="run-guidance-heading">
                                        <h3 id="run-guidance-heading">{{ __('ui.run_guidance') }}</h3>
                                        <p class="review-guidance">{{ $run->guidance }}</p>
                                    </section>
                                @endif

                                @if (is_string($run->report['summary'] ?? null))
                                    <section class="finding-section" aria-labelledby="report-summary-heading">
                                        <h3 id="report-summary-heading">{{ __('ui.report_summary') }}</h3>
                                        <p>{{ $activityMessage($run->report['message'] ?? null, $run->report['summary'], ['status' => $run->report['failure']['document_status'] ?? '']) }}</p>
                                        @if (is_string($run->report['coverage'] ?? null))
                                            <p class="field-hint">{{ $run->advanced ? (isset($run->report['audit']['sectors']) ? __('qa-sectors.run_scope') : __('ui.advanced_coverage')) : ($run->automatic ? __('ui.automatic_coverage') : $run->report['coverage']) }}</p>
                                        @endif
                                    </section>
                                @endif

                                @if (is_array($run->report['audit']['sectors'] ?? null))
                                    @include('partials.qa-sector-report', ['audit' => $run->report['audit']])
                                @elseif (is_array($audit = $run->report['audit'] ?? null))
                                    @php
                                        $repositoryAudit = $audit['repository'] ?? [];
                                        $frontendAudit = $audit['frontend'] ?? [];
                                        $auditStatuses = __('ui.audit.status');
                                        $coverageStatus = fn (?string $status): string => $auditStatuses[$status ?? 'not_checked'] ?? $auditStatuses['unavailable'];
                                    @endphp
                                    <section class="finding-section audit-coverage" aria-labelledby="audit-coverage-heading">
                                        <h3 id="audit-coverage-heading">{{ __('ui.audit.title') }}</h3>
                                        <dl class="coverage-list">
                                            <div>
                                                <dt>{{ __('ui.audit.frontend') }}</dt>
                                                <dd>{{ $coverageStatus($frontendAudit['status'] ?? null) }}</dd>
                                                @if (($frontendAudit['pages_reviewed'] ?? 0) > 0)
                                                    <dd class="field-hint">{{ trans_choice('ui.audit.pages', $frontendAudit['pages_reviewed']) }}</dd>
                                                @endif
                                                @if (isset($frontendAudit['viewports']['desktop']['width'], $frontendAudit['viewports']['mobile']['width']))
                                                    <dd class="field-hint">{{ __('ui.audit.viewports', ['desktop' => $frontendAudit['viewports']['desktop']['width'], 'mobile' => $frontendAudit['viewports']['mobile']['width']]) }}</dd>
                                                @endif
                                            </div>
                                            <div>
                                                <dt>{{ __('ui.audit.hardening') }}</dt>
                                                <dd>{{ $coverageStatus($audit['hardening']['status'] ?? null) }}</dd>
                                            </div>
                                            <div>
                                                <dt>{{ __('ui.audit.source') }}</dt>
                                                <dd>{{ $coverageStatus($repositoryAudit['source_status'] ?? null) }}</dd>
                                                @if (isset($repositoryAudit['files_reviewed']))
                                                    <dd class="field-hint">{{ trans_choice('ui.audit.files', $repositoryAudit['files_reviewed']) }}</dd>
                                                @endif
                                                @if (($repositoryAudit['source_matches_deployment'] ?? null) === false)
                                                    <dd class="field-hint">{{ __('ui.audit.unbound') }}</dd>
                                                @endif
                                            </div>
                                            <div>
                                                <dt>{{ __('ui.audit.dependencies') }}</dt>
                                                <dd>{{ $coverageStatus($repositoryAudit['advisory_status'] ?? null) }}</dd>
                                                @if (isset($repositoryAudit['packages_reviewed']))
                                                    <dd class="field-hint">{{ trans_choice('ui.audit.packages', $repositoryAudit['packages_reviewed']) }}</dd>
                                                @endif
                                                @if (($repositoryAudit['packages_skipped'] ?? 0) > 0)
                                                    <dd class="field-hint">{{ trans_choice('ui.audit.packages_skipped', $repositoryAudit['packages_skipped']) }}</dd>
                                                @endif
                                            </div>
                                        </dl>
                                    </section>
                                @endif

                                @if ($latestBrowserStep || ! $runFinished || $run->automatic)
                                    <section class="browser-activity" wire:key="browser-activity-{{ $run->id }}" aria-labelledby="browser-activity-heading">
                                        <div class="browser-activity-heading">
                                            <div>
                                                <h3 id="browser-activity-heading">{{ __('ui.browser_activity') }}</h3>
                                                <p class="browser-stage" role="status" aria-live="polite" aria-atomic="true">{{ __('ui.browser_stage.'.$browserStage, ['number' => $latestBrowserStep?->sequence]) }}</p>
                                            </div>
                                            @if ($latestBrowserStep)
                                                <a class="text-link browser-frame-link" href="{{ route('runs.evidence', [$run, $previewFor($latestBrowserStep)]) }}" target="_blank" rel="noopener noreferrer">
                                                    {{ __('ui.open_latest_screenshot') }}
                                                </a>
                                            @endif
                                        </div>
                                        @if ($latestBrowserStep)
                                            <figure class="browser-frame" wire:key="latest-preview-{{ $latestBrowserStep->id }}">
                                                <img src="{{ route('runs.evidence', [$run, $previewFor($latestBrowserStep)]) }}" alt="{{ __('ui.step_screenshot_alt', ['number' => $latestBrowserStep->sequence]) }}" decoding="async">
                                                <figcaption>{{ __('ui.latest_screenshot_step', ['number' => $latestBrowserStep->sequence]) }}</figcaption>
                                            </figure>
                                        @else
                                            <div class="browser-frame browser-frame-empty">
                                                <x-icon name="browser" />
                                                <p>{{ __($runFinished ? 'ui.no_browser_screenshots' : 'ui.first_screenshot_pending') }}</p>
                                            </div>
                                        @endif
                                    </section>
                                @endif

                                <ol class="run-timeline">
                                    @forelse ($steps as $step)
                                        @php
                                            $stepPreview = $previewFor($step);
                                            $details = \Illuminate\Support\Arr::except($step->data ?? [], ['preview_artifact', 'message', 'label']);
                                        @endphp
                                        <li wire:key="run-step-{{ $step->id }}">
                                            <span class="step-index" aria-hidden="true">{{ $step->sequence }}</span>
                                            <div>
                                                <span class="timeline-kind">{{ __('ui.kind.'.$step->kind) }}</span>
                                                <p>{{ $activityMessage($step->data['message'] ?? null, $step->summary, ['label' => $step->data['label'] ?? '', 'status' => $step->data['document_status'] ?? '']) }}</p>
                                                @if ($stepPreview)
                                                    <details class="step-screenshot" wire:ignore.self x-data="{ opened: false }" @toggle="if ($el.open) opened = true">
                                                        <summary>
                                                            <x-icon name="image" />
                                                            {{ __('ui.step_screenshot', ['number' => $step->sequence]) }}
                                                        </summary>
                                                        <template x-if="opened">
                                                            <a class="step-screenshot-image" href="{{ route('runs.evidence', [$run, $stepPreview]) }}" target="_blank" rel="noopener noreferrer">
                                                                <img src="{{ route('runs.evidence', [$run, $stepPreview]) }}" alt="{{ __('ui.step_screenshot_alt', ['number' => $step->sequence]) }}" loading="lazy" decoding="async">
                                                            </a>
                                                        </template>
                                                        <noscript><a class="text-link" href="{{ route('runs.evidence', [$run, $stepPreview]) }}">{{ __('ui.view_screenshot') }}</a></noscript>
                                                    </details>
                                                @endif
                                                @if ($details)
                                                    <details class="evidence-details" wire:ignore.self>
                                                        <summary>{{ __('ui.recorded_details') }}</summary>
                                                        <pre>{{ json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                    </details>
                                                @endif
                                            </div>
                                        </li>
                                    @empty
                                        <li class="timeline-empty">
                                            <p>{{ $run->status === 'queued' ? __('ui.queue_wait_hint') : __('ui.no_steps_hint') }}</p>
                                        </li>
                                    @endforelse
                                </ol>

                                @if (! is_array($run->report['audit']['sectors'] ?? null) && $run->findings->isNotEmpty())
                                    <div class="finding-section">
                                        <h3>{{ __('ui.findings_count', ['count' => $run->findings->count()]) }}</h3>
                                        @foreach ($run->findings as $finding)
                                            @include('partials.qa-finding', ['finding' => $finding])
                                        @endforeach
                                    </div>
                                @endif

                                @if ($run->patch_files)
                                    <a class="button button-secondary run-changes-link" href="{{ route('dashboard', ['project' => $project->id, 'panel' => 'changes', 'run' => $run->id]) }}">
                                        <x-icon name="code" />
                                        {{ __('ui.proposed_changes') }}
                                    </a>
                                @endif
                                @if ($run->verification)
                                    <details class="result-details">
                                        <summary>{{ __('ui.verification_results') }}</summary>
                                        <pre>{{ json_encode($run->verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endif
                                @if ($run->report)
                                    <details class="result-details">
                                        <summary>{{ __('ui.run_report') }}</summary>
                                        <pre>{{ json_encode($run->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endif

                                @include('partials.repair-visual-comparison')

                                @if (is_array($run->report['artifacts'] ?? null) && count($run->report['artifacts']))
                                    <section class="finding-section" aria-labelledby="browser-evidence-heading">
                                        <h3 id="browser-evidence-heading">{{ __('ui.browser_evidence') }}</h3>
                                        <ul class="artifact-list">
                                            @foreach ($run->report['artifacts'] as $artifact)
                                                @if (is_array($artifact) && is_string($artifact['filename'] ?? null) && preg_match('/\Abrowser-[a-zA-Z0-9-]+\.(png|zip)\z/', $artifact['filename']))
                                                    <li>
                                                        <a class="text-link" href="{{ route('runs.evidence', [$run, $artifact['filename']]) }}" @if (str_ends_with($artifact['filename'], '.png')) target="_blank" rel="noopener noreferrer" @endif>
                                                            <x-icon :name="str_ends_with($artifact['filename'], '.png') ? 'image' : 'download'" />
                                                            {{ str_ends_with($artifact['filename'], '.png') ? __('ui.view_screenshot') : __('ui.download_trace') }}
                                                        </a>
                                                        <span class="field-hint">{{ $artifact['filename'] }}</span>
                                                    </li>
                                                @endif
                                            @endforeach
                                        </ul>
                                    </section>
                                @endif

                                @if ($runPullNumber)
                                    <div class="pr-result">
                                        <div>
                                            <strong>{{ __('ui.draft_pr_ready') }}</strong>
                                            <p>{{ __('ui.review_repair_hint') }}</p>
                                        </div>
                                        <a class="button button-primary" href="{{ route('dashboard', ['project' => $project->id, 'panel' => 'pull-requests', 'pull' => $runPullNumber]) }}">
                                            <x-icon name="github" />
                                            {{ __('ui.review_draft_pr') }}
                                        </a>
                                    </div>
                                @endif
                            @endif
                        </section>

                    </div>
                @else
                    <section class="card empty-run">
                        <h2>{{ __('ui.no_projects') }}</h2>
                        <p>{{ __('ui.add_project_hint') }}</p>
                        <button class="button button-primary" wire:click="newProject" type="button">
                            <x-icon name="add" />
                            {{ __('ui.add_project') }}
                        </button>
                    </section>
                @endif
            @endif
        </div>
    </main>
</div>
