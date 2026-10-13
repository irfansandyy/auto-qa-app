<div class="workspace" wire:poll.5s>
    <aside class="sidebar" aria-label="Workspace navigation">
        <a href="{{ route('dashboard') }}" class="brand">Auto QA</a>

        <nav class="main-nav" aria-label="Main navigation">
            <button type="button" wire:click="setPanel('workspace')" class="nav-item {{ $panel === 'workspace' ? 'is-active' : '' }}" @if ($panel === 'workspace') aria-current="page" @endif>
                <x-icon name="dashboard" />
                Projects
            </button>
            <button type="button" wire:click="setPanel('settings')" class="nav-item {{ $panel === 'settings' ? 'is-active' : '' }}" @if ($panel === 'settings') aria-current="page" @endif>
                <x-icon name="settings" />
                Connections
            </button>
        </nav>

        <div class="sidebar-heading">Projects <span>{{ $projects->count() }}</span></div>
        <div class="project-nav">
            @forelse ($projects as $item)
                <button type="button" wire:key="project-nav-{{ $item->id }}" wire:click="selectProject({{ $item->id }})" class="project-nav-item {{ $selectedProjectId === $item->id && $panel === 'workspace' ? 'is-selected' : '' }}">
                    {{ $item->name }}
                </button>
            @empty
                <p class="sidebar-empty">No projects yet.</p>
            @endforelse
            <button class="project-nav-item add-project" type="button" wire:click="newProject">
                <x-icon name="add" />
                Add project
            </button>
        </div>

        <div class="sidebar-bottom">
            <div class="operator">
                <div>
                    <strong>{{ auth()->user()->name }}</strong>
                    <span>Workspace operator</span>
                </div>
                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button class="logout-button" aria-label="Sign out" title="Sign out">
                        <x-icon name="logout" />
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <main id="main" class="main-content">
        <header class="topbar">
            <div class="breadcrumb">
                Workspace <span aria-hidden="true">/</span>
                <strong>{{ $panel === 'settings' ? 'Connections' : 'Projects' }}</strong>
            </div>
            @if ($projects->isNotEmpty())
                <div class="mobile-project-picker">
                    <label for="mobile-project">Project</label>
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
                        <h1>Connections</h1>
                        <p>Configure repository access and testing services.</p>
                    </div>
                </div>

                <div class="connection-grid">
                    <section class="card connection-card">
                        <div class="connection-card-top">
                            <x-icon name="github" />
                            <span class="badge {{ $githubReady ? 'badge-success' : 'badge-warning' }}">{{ $githubReady ? 'Credentials configured' : 'Setup needed' }}</span>
                        </div>
                        <h2>GitHub App</h2>
                        <p>The app provides an identity for repair commits. A separate bot account is not required.</p>
                        <div class="connection-actions">
                            @if ($installationUrl)
                                <a class="button button-secondary" href="{{ $installationUrl }}" target="_blank" rel="noopener noreferrer">Install on GitHub</a>
                            @else
                                <span class="field-hint">Set your GitHub App name to enable installation.</span>
                            @endif
                        </div>
                        <p class="fine-print">Use Check connection on a project to confirm repository access.</p>
                    </section>

                    <section class="card connection-card">
                        <div class="connection-card-top">
                            <x-icon name="browser" />
                            <span class="badge {{ $browserReady ? 'badge-success' : 'badge-warning' }}">{{ $browserReady ? 'Worker configured' : 'Setup needed' }}</span>
                        </div>
                        <h2>Browser worker</h2>
                        <p>Opens the target website, performs the test journey, and records evidence.</p>
                        <p class="fine-print">Reachability is checked when a run starts. Localhost refers to the worker’s network.</p>
                    </section>

                    <section class="card connection-card">
                        <div class="connection-card-top">
                            <x-icon name="memory" />
                            <span class="badge {{ $modelReady ? 'badge-success' : 'badge-warning' }}">{{ $modelReady ? 'Address configured' : 'Setup needed' }}</span>
                        </div>
                        <h2>Agent model</h2>
                        <p>Guides browser checks and proposes repairs. Run one model and one browser session within the 8 GB limit.</p>
                        <p class="fine-print">{{ config('autoqa.model_name') ?: 'Choose the model in server configuration.' }}. Availability is checked during the run.</p>
                    </section>
                </div>

                <section class="card setup-guide">
                    <div class="section-heading">
                        <h2>Set up GitHub access</h2>
                        <a class="text-link" href="https://github.com/settings/apps/new" target="_blank" rel="noopener noreferrer">Create a GitHub App</a>
                    </div>
                    <ol class="setup-steps">
                        <li>
                            <div>
                                <strong>Create an app in your GitHub account</strong>
                                <p>Choose an app name, use your workspace URL, and disable webhooks for this version.</p>
                            </div>
                        </li>
                        <li>
                            <div>
                                <strong>Grant repository permissions</strong>
                                <p>Set Contents and Pull requests to Read and write. Metadata is Read-only. Install the app only on repositories you want the agent to repair.</p>
                            </div>
                        </li>
                        <li>
                            <div>
                                <strong>Configure the server</strong>
                                <p>Save the App ID and app name. Generate a private key, keep it outside the repository, and configure its file location on the server.</p>
                            </div>
                        </li>
                        <li>
                            <div>
                                <strong>Install and check access</strong>
                                <p>Install the app on your selected repository, add your project here, then use Check connection. Short-lived installation credentials are created as needed.</p>
                            </div>
                        </li>
                    </ol>
                    <p class="guide-callout">
                        The <strong>Verified draft PR</strong> policy authorizes repair commits and a draft pull request after verification. A person reviews and merges it.
                    </p>
                </section>
            @else
                <div class="page-heading">
                    <div>
                        <h1>{{ $showingProjectForm ? 'Project details' : 'Projects' }}</h1>
                        <p>{{ $showingProjectForm ? 'Configure the repository, website, and testing goal.' : 'Select a project to test its website and review results.' }}</p>
                    </div>
                    @if (! $showingProjectForm)
                        <div class="action-row">
                            @if ($project)
                                <button class="button button-secondary" type="button" wire:click="editProject({{ $project->id }})">Edit project</button>
                            @endif
                            <button class="button button-secondary" type="button" wire:click="newProject">
                                <x-icon name="add" />
                                New project
                            </button>
                        </div>
                    @endif
                </div>

                @if ($showingProjectForm)
                    <div class="setup-layout">
                        <section class="card project-form-card" aria-labelledby="project-heading">
                            <div class="section-heading">
                                <div>
                                    <h2 id="project-heading">{{ $editingProjectId ? 'Edit project' : 'New project' }}</h2>
                                    <p>Use a website and repository you have permission to test.</p>
                                </div>
                            </div>

                            @if ($errors->any())
                                <div class="notice notice-error" role="alert" tabindex="-1" x-data x-init="$el.focus()">
                                    <strong>Check your project details.</strong>
                                    <ul>
                                        @foreach ($errors->getMessages() as $field => $messages)
                                            <li><a href="#{{ $field }}">{{ $messages[0] }}</a></li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <form wire:submit="saveProject" class="form-stack">
                                <div class="field">
                                    <label for="name">Project name</label>
                                    <input id="name" wire:model="name" placeholder="Student portal" required maxlength="100" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                                    @error('name')
                                        <span id="name-error" class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="repositoryUrl">GitHub repository</label>
                                    <input id="repositoryUrl" wire:model="repositoryUrl" type="url" placeholder="https://github.com/your-team/student-portal" required aria-describedby="repository-hint @error('repositoryUrl') repository-error @enderror" @error('repositoryUrl') aria-invalid="true" @enderror>
                                    <span class="field-hint" id="repository-hint">The GitHub App must be installed for this repository.</span>
                                    @error('repositoryUrl')
                                        <span id="repository-error" class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="websiteUrl">Website URL</label>
                                    <input id="websiteUrl" wire:model="websiteUrl" type="url" placeholder="https://staging.example.com" required aria-describedby="website-hint @error('websiteUrl') website-error @enderror" @error('websiteUrl') aria-invalid="true" @enderror>
                                    <span class="field-hint" id="website-hint">A staging website or local application reachable by the browser worker.</span>
                                    @error('websiteUrl')
                                        <span id="website-error" class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="targetMode">Where is your website?</label>
                                    <select id="targetMode" wire:model.live="targetMode">
                                        <option value="public">Public / staging website</option>
                                        <option value="local">Local website / localhost</option>
                                    </select>
                                    @error('targetMode')
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                @if ($targetMode === 'local')
                                    <p class="guide-callout">
                                        <strong>The worker must be able to reach localhost.</strong>
                                        If the app runs on another machine or inside Docker, use a reachable host address or public tunnel. Your administrator must allow the exact local origin, including its port.
                                    </p>
                                @endif

                                <div class="field">
                                    <label for="goal">What should the agent test?</label>
                                    <textarea id="goal" wire:model="goal" rows="3" placeholder="Test registration without a phone number, then sign in." required maxlength="4000" @error('goal') aria-invalid="true" aria-describedby="goal-error" @enderror></textarea>
                                    @error('goal')
                                        <span id="goal-error" class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="expectedBehavior">What should happen?</label>
                                    <textarea id="expectedBehavior" wire:model="expectedBehavior" rows="3" placeholder="Phone is optional. A visitor can register without it and sign in successfully." required maxlength="6000" @error('expectedBehavior') aria-invalid="true" aria-describedby="expected-error" @enderror></textarea>
                                    @error('expectedBehavior')
                                        <span id="expected-error" class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <fieldset class="policy-field">
                                    <legend>Repair policy</legend>
                                    <label class="policy-option">
                                        <input type="radio" wire:model="repairPolicy" value="verified_pr">
                                        <span>
                                            <strong>Verified draft PR</strong>
                                            <span>Authorize a repair branch, commits, and a draft PR after tests and browser replay pass.</span>
                                        </span>
                                    </label>
                                    <label class="policy-option">
                                        <input type="radio" wire:model="repairPolicy" value="suggest_only">
                                        <span>
                                            <strong>Suggest only</strong>
                                            <span>Collect findings and propose a patch for your review.</span>
                                        </span>
                                    </label>
                                    @error('repairPolicy')
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </fieldset>

                                <details class="advanced-fields" @if ($errors->has('baseBranch') || $errors->has('deployedSha')) open @endif>
                                    <summary>Source version and branch</summary>
                                    <div class="form-stack">
                                        <div class="field">
                                            <label for="baseBranch">Base branch <span class="optional">Optional</span></label>
                                            <input id="baseBranch" wire:model="baseBranch" placeholder="Repository default branch">
                                            @error('baseBranch')
                                                <span class="field-error">{{ $message }}</span>
                                            @enderror
                                        </div>
                                        <div class="field">
                                            <label for="deployedSha">Deployed commit SHA <span class="optional">Needed for repair</span></label>
                                            <input id="deployedSha" wire:model="deployedSha" placeholder="Full commit SHA running on the website" maxlength="40" aria-describedby="sha-hint">
                                            <span id="sha-hint" class="field-hint">QA can start without this. Automated repair needs the exact live source version.</span>
                                            @error('deployedSha')
                                                <span class="field-error">{{ $message }}</span>
                                            @enderror
                                        </div>
                                    </div>
                                </details>

                                <div class="form-actions">
                                    <button class="button button-primary" type="submit" wire:loading.attr="disabled" wire:target="saveProject">
                                        <span wire:loading.remove wire:target="saveProject">{{ $editingProjectId ? 'Save changes' : 'Save project' }}</span>
                                        <span wire:loading wire:target="saveProject">Saving project…</span>
                                    </button>
                                    @if ($project)
                                        <button type="button" class="button button-quiet" wire:click="closeProjectForm">Cancel</button>
                                    @endif
                                </div>
                            </form>
                        </section>

                        <aside class="setup-help">
                            <h2>Before starting</h2>
                            <p>Use test accounts and disposable data on the target website.</p>
                            <h3>Repository access</h3>
                            <p>Install your GitHub App on the repository with Contents and Pull requests write permission.</p>
                            <h3>Local websites</h3>
                            <p>Localhost refers to the browser worker. Allow the website’s exact origin on that server.</p>
                            <button class="text-link" type="button" wire:click="setPanel('settings')">
                                <x-icon name="settings" />
                                Manage connections
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
                            <span class="project-target">{{ $project->target_mode === 'local' ? 'Local target' : 'Public target' }}</span>
                        </div>
                        <div class="project-goal">
                            <h3>Testing goal</h3>
                            <p>{{ $project->goal }}</p>
                            <span class="field-hint"><strong>Expected:</strong> {{ $project->expected_behavior }}</span>
                        </div>
                        <div class="project-overview-footer">
                            <span class="policy-label">Repair policy: {{ $project->repair_policy === 'verified_pr' ? 'Verified draft PR' : 'Suggest only' }}</span>
                            <div class="action-row">
                                <button class="button button-secondary" type="button" wire:click="checkConnection" wire:loading.attr="disabled" wire:target="checkConnection">
                                    <span wire:loading.remove wire:target="checkConnection">Check connection</span>
                                    <span wire:loading wire:target="checkConnection">Checking access…</span>
                                </button>
                                <button class="button button-primary" type="button" wire:click="startRun" wire:loading.attr="disabled" wire:target="startRun" @disabled(! $modelReady || ! $browserReady)>
                                    <x-icon name="play" />
                                    <span wire:loading.remove wire:target="startRun">Start QA run</span>
                                    <span wire:loading wire:target="startRun">Queueing…</span>
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
                            Configure the browser worker and model service before starting a run.
                            <button class="inline-link" type="button" wire:click="setPanel('settings')">Open Connections</button>
                        </div>
                    @endif
                    @if (! $project->deployed_sha)
                        <p class="source-note">The deployed commit is not recorded. QA can collect findings; automated repair requires the exact source version.</p>
                    @endif

                    <div class="run-layout {{ $runs->isEmpty() ? 'is-empty' : '' }}">
                        <section class="card run-main">
                            @if (! $run)
                                <div class="empty-run">
                                    <h2>No runs yet</h2>
                                    <p>Start a run to test this project.</p>
                                </div>
                            @else
                                <div class="section-heading">
                                    <h2>Run #{{ $run->id }}</h2>
                                    <span class="badge {{ $run->status === 'completed' ? 'badge-success' : (in_array($run->status, ['failed', 'blocked', 'needs_configuration', 'verification_failed']) ? 'badge-warning' : 'badge-neutral') }}">
                                        {{ ucfirst(str_replace('_', ' ', $run->status)) }}
                                    </span>
                                </div>
                                <div class="run-meta">
                                    <span>Created {{ $run->created_at->timezone(config('app.timezone'))->format('d M, H:i') }}</span>
                                    @if ($run->finished_at)
                                        <span>Finished {{ $run->finished_at->timezone(config('app.timezone'))->format('H:i') }}</span>
                                    @endif
                                    @if (! in_array($run->status, $terminalStatuses))
                                        <button class="text-link danger-link" type="button" wire:click="cancelRun({{ $run->id }})" wire:loading.attr="disabled" wire:target="cancelRun">Cancel run</button>
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
                                                <span class="timeline-kind">{{ ucfirst(str_replace('_', ' ', $step->kind)) }}</span>
                                                <p>{{ $step->summary }}</p>
                                                @if (! empty($step->data))
                                                    <details class="evidence-details">
                                                        <summary>Recorded details</summary>
                                                        <pre>{{ json_encode($step->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                    </details>
                                                @endif
                                            </div>
                                        </li>
                                    @empty
                                        <li class="timeline-empty">
                                            <p>{{ $run->status === 'queued' ? 'Waiting for the queue worker. Progress will appear when the run starts.' : 'No steps have been recorded for this run.' }}</p>
                                        </li>
                                    @endforelse
                                </ol>

                                @if ($run->findings->isNotEmpty())
                                    <div class="finding-section">
                                        <h3>Findings ({{ $run->findings->count() }})</h3>
                                        @foreach ($run->findings as $finding)
                                            <article class="finding" wire:key="finding-{{ $finding->id }}">
                                                <div class="finding-heading">
                                                    <h4>{{ $finding->title }}</h4>
                                                    <span class="badge badge-warning">{{ ucfirst($finding->severity) }}</span>
                                                </div>
                                                <dl>
                                                    <dt>Expected</dt>
                                                    <dd>{{ $finding->expected }}</dd>
                                                    <dt>Observed</dt>
                                                    <dd>{{ $finding->actual }}</dd>
                                                </dl>
                                                @if (is_string($finding->evidence['artifact'] ?? null) && preg_match('/\Abrowser-[a-zA-Z0-9-]+\.png\z/', $finding->evidence['artifact']))
                                                    <a class="text-link" href="{{ route('runs.evidence', [$run, $finding->evidence['artifact']]) }}" target="_blank" rel="noopener noreferrer">
                                                        <x-icon name="image" />
                                                        View screenshot
                                                    </a>
                                                @endif
                                                @if ($finding->evidence)
                                                    <details class="evidence-details">
                                                        <summary>View evidence</summary>
                                                        <pre>{{ json_encode($finding->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                    </details>
                                                @endif
                                            </article>
                                        @endforeach
                                    </div>
                                @endif

                                @if ($run->patch_files)
                                    <details class="result-details">
                                        <summary>Proposed source changes</summary>
                                        <pre>{{ json_encode($run->patch_files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endif
                                @if ($run->verification)
                                    <details class="result-details">
                                        <summary>Verification results</summary>
                                        <pre>{{ json_encode($run->verification, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endif
                                @if ($run->report)
                                    <details class="result-details">
                                        <summary>Run report</summary>
                                        <pre>{{ json_encode($run->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endif

                                @if (is_array($run->report['artifacts'] ?? null) && count($run->report['artifacts']))
                                    <section class="finding-section" aria-labelledby="browser-evidence-heading">
                                        <h3 id="browser-evidence-heading">Browser evidence</h3>
                                        <ul class="artifact-list">
                                            @foreach ($run->report['artifacts'] as $artifact)
                                                @if (is_array($artifact) && is_string($artifact['filename'] ?? null) && preg_match('/\Abrowser-[a-zA-Z0-9-]+\.(png|zip)\z/', $artifact['filename']))
                                                    <li>
                                                        <a class="text-link" href="{{ route('runs.evidence', [$run, $artifact['filename']]) }}" @if (str_ends_with($artifact['filename'], '.png')) target="_blank" rel="noopener noreferrer" @endif>
                                                            <x-icon :name="str_ends_with($artifact['filename'], '.png') ? 'image' : 'download'" />
                                                            {{ str_ends_with($artifact['filename'], '.png') ? 'View screenshot' : 'Download trace' }}
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
                                            <strong>Draft pull request ready</strong>
                                            <p>Review the repair and evidence on GitHub.</p>
                                        </div>
                                        <a class="button button-primary" href="{{ $run->pr_url }}" target="_blank" rel="noopener noreferrer">
                                            <x-icon name="github" />
                                            Review draft PR
                                        </a>
                                    </div>
                                @endif
                            @endif
                        </section>

                        @if ($runs->isNotEmpty())
                            <aside class="run-aside">
                                <section class="card history-card">
                                    <div class="section-heading">
                                        <h2>Recent runs</h2>
                                    </div>
                                    @foreach ($runs as $previous)
                                        <button class="history-item {{ $selectedRunId === $previous->id ? 'is-selected' : '' }}" type="button" wire:click="selectRun({{ $previous->id }})" wire:key="history-{{ $previous->id }}">
                                            <span>
                                                <strong>Run #{{ $previous->id }}</strong>
                                                <span>{{ $previous->created_at->timezone(config('app.timezone'))->format('d M, H:i') }}</span>
                                            </span>
                                            <span class="history-status">{{ ucfirst(str_replace('_', ' ', $previous->status)) }}</span>
                                        </button>
                                    @endforeach
                                </section>
                            </aside>
                        @endif
                    </div>
                @else
                    <section class="card empty-run">
                        <h2>No projects yet</h2>
                        <p>Add a GitHub repository and a website to begin.</p>
                        <button class="button button-primary" wire:click="newProject" type="button">
                            <x-icon name="add" />
                            Add project
                        </button>
                    </section>
                @endif
            @endif
        </div>
    </main>
</div>
