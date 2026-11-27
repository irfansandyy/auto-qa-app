<div class="page-heading">
    <div>
        <h1>{{ __('dashboard-panels.nav.projects') }}</h1>
        <p>{{ __('dashboard-panels.project.description') }}</p>
    </div>
    @unless ($showingProjectForm)
        <button class="button button-primary" type="button" wire:click="newProject">
            <x-icon name="add" />
            {{ __('ui.new_project') }}
        </button>
    @endunless
</div>

@error('run')
    <div class="notice notice-error" role="alert">{{ $message }}</div>
@enderror

@if ($showingProjectForm)
    @include('partials.project-form')
@elseif ($projects->isEmpty())
    <section class="card empty-run" aria-labelledby="empty-projects-heading">
        <h2 id="empty-projects-heading">{{ __('ui.no_projects') }}</h2>
        <p>{{ __('ui.add_project_hint') }}</p>
    </section>
@else
    <ul class="card managed-projects" aria-label="{{ __('dashboard-panels.nav.projects') }}">
        @foreach ($projects as $item)
            <li class="managed-project" wire:key="managed-project-{{ $item->id }}">
                <div class="managed-project-info">
                    <div class="managed-project-heading">
                        <h2>{{ $item->name }}</h2>
                        @if ($selectedProjectId === $item->id)
                            <span class="badge badge-neutral">{{ __('dashboard-panels.project.selected') }}</span>
                        @endif
                    </div>
                    <div class="project-links">
                        <a href="{{ $item->website_url }}" target="_blank" rel="noopener noreferrer">
                            <x-icon name="browser" />
                            {{ $item->website_url }}
                        </a>
                        @if ($item->repository_url)
                            <a href="{{ $item->repository_url }}" target="_blank" rel="noopener noreferrer">
                                <x-icon name="github" />
                                {{ preg_replace('~^https://github.com/~', '', $item->repository_url) }}
                            </a>
                        @endif
                    </div>
                </div>
                <div class="managed-project-actions">
                    <button class="button button-secondary" type="button" wire:click="selectProject({{ $item->id }})" aria-label="{{ __('dashboard-panels.project.open_runs_for', ['project' => $item->name]) }}">
                        {{ __('dashboard-panels.project.open_runs') }}
                    </button>
                    <button class="button button-secondary" type="button" wire:click="editProject({{ $item->id }})" aria-label="{{ __('dashboard-panels.project.edit_named', ['project' => $item->name]) }}">
                        {{ __('ui.edit_project') }}
                    </button>
                </div>
            </li>
        @endforeach
    </ul>
@endif
