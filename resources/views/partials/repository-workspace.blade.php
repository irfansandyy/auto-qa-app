<section class="repo-workspace" aria-labelledby="repo-workspace-title">
    <header class="repo-workspace-header">
        <div>
            <h1 id="repo-workspace-title">{{ __('repository-workspace.'.$section) }}</h1>
            @if ($project?->repository_url)
                <p class="repo-workspace-name">{{ preg_replace('~^https://github\.com/|(?:\.git|/)$~', '', $project->repository_url) }}</p>
            @endif
        </div>

        @if ($project?->repository_url)
            <div class="repo-workspace-actions">
                @if (in_array($section, ['files', 'git'], true) && $repositoryBranch)
                    <div class="repo-picker">
                        <label for="repository-branch"><x-icon name="commit" /> {{ __('repository-workspace.branch') }}</label>
                        <select id="repository-branch" wire:change="selectRepositoryBranch($event.target.value)" wire:loading.attr="disabled" @disabled($editingRepositoryFile)>
                            @if (! in_array($repositoryBranch, array_column($repositoryBranches, 'name'), true))
                                <option value="{{ $repositoryBranch }}">{{ $repositoryBranch }}</option>
                            @endif
                            @foreach ($repositoryBranches as $branch)
                                <option value="{{ $branch['name'] }}" @selected($repositoryBranch === $branch['name'])>{{ $branch['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <button class="button button-secondary" type="button" wire:click="loadRepository" wire:loading.attr="disabled" @disabled($editingRepositoryFile)>
                    {{ __('repository-workspace.'.($repositoryBranch || $section === 'changes' ? 'refresh' : 'load')) }}
                </button>
            </div>
        @endif
    </header>

    <p class="sr-only" role="status" wire:loading>{{ __('repository-workspace.loading') }}</p>

    @if ($repositoryError)
        <div class="notice notice-error" role="alert">{{ $repositoryError }}</div>
    @endif
    @if ($repositoryNotice)
        <div class="notice notice-success" role="status">{{ $repositoryNotice }}</div>
    @endif

    @if (! $project?->repository_url)
        <div class="repo-empty repo-card">
            <x-icon name="folder" />
            <p>{{ __('repository-workspace.'.($project ? 'connect_repository' : 'select_project')) }}</p>
            @if ($project)
                <a class="text-link" href="{{ route('docs.github-app') }}">{{ __('repository-workspace.connection_guide') }}</a>
            @endif
        </div>
    @elseif ($section === 'files')
        @include('partials.repository-files')
    @elseif ($section === 'changes')
        @include('partials.repository-changes')
    @elseif ($section === 'git')
        @include('partials.repository-history')
    @elseif ($section === 'pull-requests')
        @include('partials.repository-pull-requests')
    @endif

    @if ($project?->repository_url)
        <footer class="repo-workspace-footer">
            <a class="text-link" href="{{ route('docs.github-app').'#permissions' }}">{{ __('repository-workspace.permissions_guide') }}</a>
        </footer>
    @endif
</section>
