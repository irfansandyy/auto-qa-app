<div class="workspace" wire:poll.5s>
    <aside class="sidebar" aria-label="{{ __('ui.workspace_navigation') }}">
        <a href="{{ route('dashboard') }}" class="brand">{{ __('ui.app_name') }}</a>

        <nav class="main-nav" aria-label="{{ __('ui.main_navigation') }}">
            <button type="button" wire:click="setPanel('workspace')" class="nav-item {{ $panel === 'workspace' ? 'is-active' : '' }}" @if ($panel === 'workspace') aria-current="page" @endif>
                <x-icon name="dashboard" />
                {{ __('ui.projects') }}
            </button>
            <button type="button" wire:click="setPanel('settings')" class="nav-item {{ $panel === 'settings' ? 'is-active' : '' }}" @if ($panel === 'settings') aria-current="page" @endif>
                <x-icon name="settings" />
                {{ __('ui.settings') }}
            </button>
        </nav>

        <div class="sidebar-heading">{{ __('ui.projects') }} <span>{{ $projects->count() }}</span></div>
        <div class="project-nav">
            @forelse ($projects as $item)
                <button type="button" wire:key="project-nav-{{ $item->id }}" wire:click="selectProject({{ $item->id }})" class="project-nav-item {{ $selectedProjectId === $item->id && $panel === 'workspace' ? 'is-selected' : '' }}">
                    {{ $item->name }}
                </button>
            @empty
                <p class="sidebar-empty">{{ __('ui.no_projects_sidebar') }}</p>
            @endforelse
            <button class="project-nav-item add-project" type="button" wire:click="newProject">
                <x-icon name="add" />
                {{ __('ui.add_project') }}
            </button>
        </div>

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
                <strong>{{ $panel === 'settings' ? __('ui.settings') : __('ui.projects') }}</strong>
            </div>
            @if ($projects->isNotEmpty())
                <div class="mobile-project-picker">
                    <label for="mobile-project">{{ __('ui.project') }}</label>
                    <select id="mobile-project" wire:change="selectProject($event.target.value)">
                        @foreach ($projects as $item)
                            <option value="{{ $item->id }}" @selected($selectedProjectId === $item->id)>{{ $item->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
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
                                    <input type="radio" name="theme" value="light" @checked(old('theme', $theme ?? 'light') === 'light')>
                                    <span>{{ __('preferences.light') }}</span>
                                </label>
                                <label>
                                    <input type="radio" name="theme" value="dark" @checked(old('theme', $theme ?? 'light') === 'dark')>
                                    <span>{{ __('preferences.dark') }}</span>
                                </label>
                            </div>
                        </div>

                        <noscript><button type="submit" class="button button-primary">{{ __('preferences.save') }}</button></noscript>
                    </form>
                </section>

                <div class="section-heading">
                    <h2>{{ __('ui.connections') }}</h2>
                </div>

                <div class="connection-grid">
                    <section class="card connection-card">
                        <div class="connection-card-top">
                            <x-icon name="github" />
                            <span class="badge {{ $githubReady ? 'badge-success' : 'badge-warning' }}">{{ $githubReady ? __('ui.credentials_configured') : __('ui.setup_needed') }}</span>
                        </div>
                        <h2>{{ __('ui.github_app') }}</h2>
                        <p>{{ __('ui.github_identity_description') }}</p>
                        <div class="connection-actions">
                            @if ($installationUrl)
                                <a class="button button-secondary" href="{{ $installationUrl }}" target="_blank" rel="noopener noreferrer">{{ __('ui.install_on_github') }}</a>
                            @else
                                <span class="field-hint">{{ __('ui.github_name_hint') }}</span>
                            @endif
                        </div>
                        <p class="fine-print">{{ __('ui.repository_access_hint') }}</p>
                    </section>

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
                        <p class="fine-print">{{ config('autoqa.model_name') ? config('autoqa.model_name').'.' : __('ui.choose_model_hint') }} {{ __('ui.model_availability_hint') }}</p>
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
                                <p>{{ __('ui.configure_server_description') }}</p>
                            </div>
                        </li>
                        <li>
                            <div>
                                <strong>{{ __('ui.install_access_heading') }}</strong>
                                <p>{{ __('ui.install_access_description') }}</p>
                            </div>
                        </li>
                    </ol>
                    <p class="guide-callout">
                        {{ __('ui.policy_authorization_before') }} <strong>{{ __('ui.verified_draft_pr') }}</strong> {{ __('ui.policy_authorization_after') }}
                    </p>
                </section>
            @else
                <div class="page-heading">
                    <div>
                        <h1>{{ $showingProjectForm ? __('ui.project_details') : __('ui.projects') }}</h1>
                        <p>{{ $showingProjectForm ? __('ui.project_details_description') : __('ui.projects_description') }}</p>
                    </div>
                    @if (! $showingProjectForm)
                        <div class="action-row">
                            @if ($project)
                                <button class="button button-secondary" type="button" wire:click="editProject({{ $project->id }})">{{ __('ui.edit_project') }}</button>
                            @endif
                            <button class="button button-secondary" type="button" wire:click="newProject">
                                <x-icon name="add" />
                                {{ __('ui.new_project') }}
                            </button>
                        </div>
                    @endif
                </div>

                @if ($showingProjectForm)
                    <div class="setup-layout">
                        <section class="card project-form-card" aria-labelledby="project-heading">
                            <div class="section-heading">
                                <div>
                                    <h2 id="project-heading">{{ $editingProjectId ? __('ui.edit_project') : __('ui.new_project') }}</h2>
                                    <p>{{ __('ui.permission_hint') }}</p>
                                </div>
                            </div>

                            @if ($errors->any())
                                <div class="notice notice-error" role="alert" tabindex="-1" x-data x-init="$el.focus()">
                                    <strong>{{ __('ui.check_project_details') }}</strong>
                                    <ul>
                                        @foreach ($errors->getMessages() as $field => $messages)
                                            <li><a href="#{{ $field }}">{{ $messages[0] }}</a></li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <form wire:submit="saveProject" class="form-stack">
                                <div class="field">
                                    <label for="name">{{ __('ui.project_name') }}</label>
                                    <input id="name" wire:model="name" placeholder="{{ __('ui.project_name_placeholder') }}" required maxlength="100" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                                    @error('name')
                                        <span id="name-error" class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="repositoryUrl">{{ __('ui.github_repository') }}</label>
                                    <input id="repositoryUrl" wire:model="repositoryUrl" type="url" placeholder="{{ __('ui.repository_placeholder') }}" required aria-describedby="repository-hint @error('repositoryUrl') repository-error @enderror" @error('repositoryUrl') aria-invalid="true" @enderror>
                                    <span class="field-hint" id="repository-hint">{{ __('ui.repository_installation_hint') }}</span>
                                    @error('repositoryUrl')
                                        <span id="repository-error" class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="websiteUrl">{{ __('ui.website_url') }}</label>
                                    <input id="websiteUrl" wire:model="websiteUrl" type="url" placeholder="{{ __('ui.website_placeholder') }}" required aria-describedby="website-hint @error('websiteUrl') website-error @enderror" @error('websiteUrl') aria-invalid="true" @enderror>
                                    <span class="field-hint" id="website-hint">{{ __('ui.website_reachability_hint') }}</span>
                                    @error('websiteUrl')
                                        <span id="website-error" class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="targetMode">{{ __('ui.website_location') }}</label>
                                    <select id="targetMode" wire:model.live="targetMode">
                                        <option value="public">{{ __('ui.public_website') }}</option>
                                        <option value="local">{{ __('ui.local_website') }}</option>
                                    </select>
                                    @error('targetMode')
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                @if ($targetMode === 'local')
                                    <p class="guide-callout">
                                        <strong>{{ __('ui.localhost_heading') }}</strong>
                                        {{ __('ui.localhost_description') }}
                                    </p>
                                @endif

                                <div class="field">
                                    <label for="goal">{{ __('ui.goal_label') }}</label>
                                    <textarea id="goal" wire:model="goal" rows="3" placeholder="{{ __('ui.goal_placeholder') }}" required maxlength="4000" @error('goal') aria-invalid="true" aria-describedby="goal-error" @enderror></textarea>
                                    @error('goal')
                                        <span id="goal-error" class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="expectedBehavior">{{ __('ui.expected_behavior_label') }}</label>
                                    <textarea id="expectedBehavior" wire:model="expectedBehavior" rows="3" placeholder="{{ __('ui.expected_behavior_placeholder') }}" required maxlength="6000" @error('expectedBehavior') aria-invalid="true" aria-describedby="expected-error" @enderror></textarea>
                                    @error('expectedBehavior')
                                        <span id="expected-error" class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <fieldset class="policy-field">
                                    <legend>{{ __('ui.repair_policy') }}</legend>
                                    <label class="policy-option">
                                        <input type="radio" wire:model="repairPolicy" value="verified_pr">
                                        <span>
                                            <strong>{{ __('ui.verified_draft_pr') }}</strong>
                                            <span>{{ __('ui.verified_draft_pr_description') }}</span>
                                        </span>
                                    </label>
                                    <label class="policy-option">
                                        <input type="radio" wire:model="repairPolicy" value="suggest_only">
                                        <span>
                                            <strong>{{ __('ui.suggest_only') }}</strong>
                                            <span>{{ __('ui.suggest_only_description') }}</span>
                                        </span>
                                    </label>
                                    @error('repairPolicy')
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </fieldset>

                                <details class="advanced-fields" @if ($errors->has('baseBranch') || $errors->has('deployedSha')) open @endif>
                                    <summary>{{ __('ui.source_version_branch') }}</summary>
                                    <div class="form-stack">
                                        <div class="field">
                                            <label for="baseBranch">{{ __('ui.base_branch') }} <span class="optional">{{ __('ui.optional') }}</span></label>
                                            <input id="baseBranch" wire:model="baseBranch" placeholder="{{ __('ui.base_branch_placeholder') }}">
                                            @error('baseBranch')
                                                <span class="field-error">{{ $message }}</span>
                                            @enderror
                                        </div>
                                        <div class="field">
                                            <label for="deployedSha">{{ __('ui.deployed_commit_sha') }} <span class="optional">{{ __('ui.needed_for_repair') }}</span></label>
                                            <input id="deployedSha" wire:model="deployedSha" placeholder="{{ __('ui.deployed_sha_placeholder') }}" maxlength="40" aria-describedby="sha-hint">
                                            <span id="sha-hint" class="field-hint">{{ __('ui.source_version_hint') }}</span>
                                            @error('deployedSha')
                                                <span class="field-error">{{ $message }}</span>
                                            @enderror
                                        </div>
                                    </div>
                                </details>

                                <div class="form-actions">
                                    <button class="button button-primary" type="submit" wire:loading.attr="disabled" wire:target="saveProject">
                                        <span wire:loading.remove wire:target="saveProject">{{ $editingProjectId ? __('ui.save_changes') : __('ui.save_project') }}</span>
                                        <span wire:loading wire:target="saveProject">{{ __('ui.saving_project') }}</span>
                                    </button>
                                    @if ($project)
                                        <button type="button" class="button button-quiet" wire:click="closeProjectForm">{{ __('ui.cancel') }}</button>
                                    @endif
                                </div>
                            </form>
                        </section>

                        <aside class="setup-help">
                            <h2>{{ __('ui.before_starting') }}</h2>
                            <p>{{ __('ui.test_data_hint') }}</p>
                            <h3>{{ __('ui.repository_access') }}</h3>
                            <p>{{ __('ui.repository_permission_hint') }}</p>
                            <h3>{{ __('ui.local_websites') }}</h3>
                            <p>{{ __('ui.local_origin_hint') }}</p>
                            <button class="text-link" type="button" wire:click="setPanel('settings')">
                                <x-icon name="settings" />
                                {{ __('ui.manage_connections') }}
                            </button>
                        </aside>
                    </div>
                @elseif ($project)
                    <section class="card project-overview">
                        <div class="project-overview-heading">
                            <div class="project-summary">
                                <h2>{{ $project->name }}</h2>
                                <div class="project-links">
                                    <a href="{{ $project->repository_url }}" target="_blank" rel="noopener noreferrer">
                                        <x-icon name="github" />
                                        {{ preg_replace('~^https://github.com/~', '', $project->repository_url) }}
                                    </a>
                                    <a href="{{ $project->website_url }}" target="_blank" rel="noopener noreferrer">
                                        <x-icon name="browser" />
                                        {{ $project->website_url }}
                                    </a>
                                </div>
                            </div>
                            <span class="project-target">{{ $project->target_mode === 'local' ? __('ui.local_target') : __('ui.public_target') }}</span>
                        </div>
                        <div class="project-goal">
                            <h3>{{ __('ui.testing_goal') }}</h3>
                            <p>{{ $project->goal }}</p>
                            <span class="field-hint"><strong>{{ __('ui.expected_label') }}</strong> {{ $project->expected_behavior }}</span>
                        </div>
                        <div class="project-overview-footer">
                            <span class="policy-label">{{ __('ui.repair_policy_label') }} {{ $project->repair_policy === 'verified_pr' ? __('ui.verified_draft_pr') : __('ui.suggest_only') }}</span>
                            <div class="action-row">
                                <button class="button button-secondary" type="button" wire:click="checkConnection" wire:loading.attr="disabled" wire:target="checkConnection">
                                    <span wire:loading.remove wire:target="checkConnection">{{ __('ui.check_connection') }}</span>
                                    <span wire:loading wire:target="checkConnection">{{ __('ui.checking_access') }}</span>
                                </button>
                                <button class="button button-primary" type="button" wire:click="startRun" wire:loading.attr="disabled" wire:target="startRun" @disabled(! $modelReady || ! $browserReady)>
                                    <x-icon name="play" />
                                    <span wire:loading.remove wire:target="startRun">{{ __('ui.start_qa_run') }}</span>
                                    <span wire:loading wire:target="startRun">{{ __('ui.queueing') }}</span>
                                </button>
                            </div>
                        </div>
                    </section>

                    @if ($connection)
                        <div class="notice {{ $connection['ok'] && $connection['can_push'] ? 'notice-success' : 'notice-warning' }}" role="status">{{ $connection['message'] }}</div>
                    @endif
                    @error('run')
                        <div id="run" class="notice notice-error" role="alert">{{ $message }}</div>
                    @enderror
                    @if (! $modelReady || ! $browserReady)
                        <div class="notice notice-warning">
                            {{ __('ui.configure_services_hint') }}
                            <button class="inline-link" type="button" wire:click="setPanel('settings')">{{ __('ui.open_settings') }}</button>
                        </div>
                    @endif
                    @if (! $project->deployed_sha)
                        <p class="source-note">{{ __('ui.missing_source_note') }}</p>
                    @endif

                    <div class="run-layout {{ $runs->isEmpty() ? 'is-empty' : '' }}">
                        <section class="card run-main">
                            @if (! $run)
                                <div class="empty-run">
                                    <h2>{{ __('ui.no_runs') }}</h2>
                                    <p>{{ __('ui.start_project_hint') }}</p>
                                </div>
                            @else
                                <div class="section-heading">
                                    <h2>{{ __('ui.run_number', ['number' => $run->id]) }}</h2>
                                    <span class="badge {{ $run->status === 'completed' ? 'badge-success' : (in_array($run->status, ['failed', 'blocked', 'needs_configuration', 'verification_failed']) ? 'badge-warning' : 'badge-neutral') }}">
                                        {{ __('ui.status.'.$run->status) }}
                                    </span>
                                </div>
                                <div class="run-meta">
                                    <span>{{ __('ui.created_at', ['time' => $run->created_at->timezone(config('app.timezone'))->locale(app()->getLocale())->translatedFormat('d M, H:i')]) }}</span>
                                    @if ($run->finished_at)
                                        <span>{{ __('ui.finished_at', ['time' => $run->finished_at->timezone(config('app.timezone'))->locale(app()->getLocale())->translatedFormat('H:i')]) }}</span>
                                    @endif
                                    @if (! in_array($run->status, $terminalStatuses))
                                        <button class="text-link danger-link" type="button" wire:click="cancelRun({{ $run->id }})" wire:loading.attr="disabled" wire:target="cancelRun">{{ __('ui.cancel_run') }}</button>
                                    @endif
                                </div>

                                @if ($run->error)
                                    <div class="notice notice-error" role="alert">{{ $run->error }}</div>
                                @endif

                                <ol class="run-timeline">
                                    @forelse ($run->steps->sortBy('sequence') as $step)
                                        <li wire:key="run-step-{{ $step->id }}">
                                            <span class="step-index" aria-hidden="true">{{ $step->sequence }}</span>
                                            <div>
                                                <span class="timeline-kind">{{ __('ui.kind.'.$step->kind) }}</span>
                                                <p>{{ $step->summary }}</p>
                                                @if (! empty($step->data))
                                                    <details class="evidence-details">
                                                        <summary>{{ __('ui.recorded_details') }}</summary>
                                                        <pre>{{ json_encode($step->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
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

                                @if ($run->findings->isNotEmpty())
                                    <div class="finding-section">
                                        <h3>{{ __('ui.findings_count', ['count' => $run->findings->count()]) }}</h3>
                                        @foreach ($run->findings as $finding)
                                            <article class="finding" wire:key="finding-{{ $finding->id }}">
                                                <div class="finding-heading">
                                                    <h4>{{ $finding->title }}</h4>
                                                    <span class="badge badge-warning">{{ __('ui.severity.'.$finding->severity) }}</span>
                                                </div>
                                                <dl>
                                                    <dt>{{ __('ui.expected') }}</dt>
                                                    <dd>{{ $finding->expected }}</dd>
                                                    <dt>{{ __('ui.observed') }}</dt>
                                                    <dd>{{ $finding->actual }}</dd>
                                                </dl>
                                                @if (is_string($finding->evidence['artifact'] ?? null) && preg_match('/\Abrowser-[a-zA-Z0-9-]+\.png\z/', $finding->evidence['artifact']))
                                                    <a class="text-link" href="{{ route('runs.evidence', [$run, $finding->evidence['artifact']]) }}" target="_blank" rel="noopener noreferrer">
                                                        <x-icon name="image" />
                                                        {{ __('ui.view_screenshot') }}
                                                    </a>
                                                @endif
                                                @if ($finding->evidence)
                                                    <details class="evidence-details">
                                                        <summary>{{ __('ui.view_evidence') }}</summary>
                                                        <pre>{{ json_encode($finding->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                    </details>
                                                @endif
                                            </article>
                                        @endforeach
                                    </div>
                                @endif

                                @if ($run->patch_files)
                                    <details class="result-details">
                                        <summary>{{ __('ui.proposed_changes') }}</summary>
                                        <pre>{{ json_encode($run->patch_files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
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

                                @if ($run->pr_url && preg_match('~^https://github\.com/[^/]+/[^/]+/pull/[0-9]+$~', $run->pr_url))
                                    <div class="pr-result">
                                        <div>
                                            <strong>{{ __('ui.draft_pr_ready') }}</strong>
                                            <p>{{ __('ui.review_repair_hint') }}</p>
                                        </div>
                                        <a class="button button-primary" href="{{ $run->pr_url }}" target="_blank" rel="noopener noreferrer">
                                            <x-icon name="github" />
                                            {{ __('ui.review_draft_pr') }}
                                        </a>
                                    </div>
                                @endif
                            @endif
                        </section>

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
                                                <span>{{ $previous->created_at->timezone(config('app.timezone'))->locale(app()->getLocale())->translatedFormat('d M, H:i') }}</span>
                                            </span>
                                            <span class="history-status">{{ __('ui.status.'.$previous->status) }}</span>
                                        </button>
                                    @endforeach
                                </section>
                            </aside>
                        @endif
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
