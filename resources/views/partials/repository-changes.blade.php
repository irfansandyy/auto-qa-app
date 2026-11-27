<section class="repo-card" aria-label="{{ __('repository-workspace.changes') }}">
    @if ($repositoryRepairRuns->isNotEmpty())
        <div class="repo-card-header repo-changes-toolbar">
            <div class="repo-picker">
                <label for="repository-repair-run">{{ __('repository-workspace.repair_run') }}</label>
                <select id="repository-repair-run" wire:change="selectRepositoryChangeRun($event.target.value)" wire:loading.attr="disabled">
                    <option value="" @selected(! $repositoryChangeRunId) disabled>{{ __('repository-workspace.choose_repair_run') }}</option>
                    @foreach ($repositoryRepairRuns as $repairRun)
                        <option value="{{ $repairRun->id }}" @selected($repositoryChangeRunId === $repairRun->id)>{{ __('repository-workspace.repair_run_label', ['id' => $repairRun->id]) }}</option>
                    @endforeach
                </select>
            </div>
            @if ($repositoryChangePullNumber)
                <a class="button button-secondary" href="{{ route('dashboard', ['panel' => 'pull-requests', 'project' => $project->id, 'pull' => $repositoryChangePullNumber]) }}"><x-icon name="merge" /> {{ __('repository-workspace.pr_count', ['number' => $repositoryChangePullNumber]) }}</a>
            @endif
        </div>
    @endif

    @if ($repositoryChangesTruncated)
        <p class="repo-list-note">{{ __('repository-workspace.diff_truncated') }}</p>
    @endif

    @if ($repositoryChanges)
        <div class="repo-change-list">
            <p class="repo-change-count">{{ trans_choice('repository-workspace.files_changed', count($repositoryChanges)) }}</p>
            @foreach ($repositoryChanges as $changedFile)
                @if ($repositoryChangePullNumber || $repositoryChangePath === $changedFile['path'])
                    @include('partials.repository-diff', ['change' => $changedFile, 'expanded' => $repositoryChangePath === $changedFile['path'] || $loop->first, 'diffIdentity' => 'run-'.$repositoryChangeRunId])
                @else
                    <button class="repo-change-trigger" type="button" wire:key="proposal-{{ $changedFile['path'] }}" wire:click="openRepositoryChange(@js($changedFile['path']))" wire:loading.attr="disabled">
                        <x-icon name="description" />
                        <strong>{{ $changedFile['path'] }}</strong>
                        <span>{{ __('repository-workspace.open_change') }}</span>
                    </button>
                @endif
            @endforeach
        </div>
    @else
        <div class="repo-empty">
            <x-icon name="code" />
            <p>{{ __('repository-workspace.no_changes') }}</p>
        </div>
    @endif
</section>
