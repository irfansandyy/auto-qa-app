<div class="repo-columns repo-git-columns">
    <section class="repo-card" aria-labelledby="repository-branches-title">
        <div class="repo-card-header">
            <h2 id="repository-branches-title">{{ __('repository-workspace.branches') }}</h2>
        </div>

        <ul class="repo-list">
            @forelse ($repositoryBranches as $branch)
                <li wire:key="git-branch-{{ $branch['name'] }}">
                    <button class="repo-list-button" type="button" wire:click="selectRepositoryBranch(@js($branch['name']))" wire:loading.attr="disabled" @if ($repositoryBranch === $branch['name']) aria-current="true" @endif>
                        <x-icon name="commit" />
                        <span>{{ $branch['name'] }}</span>
                        @if ($branch['protected'])
                            <small>{{ __('repository-workspace.protected') }}</small>
                        @elseif ($repositoryBranch === $branch['name'])
                            <small>{{ __('repository-workspace.selected_branch') }}</small>
                        @endif
                    </button>
                </li>
            @empty
                <li class="repo-list-note">{{ __('repository-workspace.no_branches') }}</li>
            @endforelse
        </ul>

        @if ($repositoryHasMoreBranches)
            <div class="repo-card-footer">
                <button class="text-link" type="button" wire:click="loadMoreRepositoryBranches" wire:loading.attr="disabled">{{ __('repository-workspace.more_branches') }}</button>
            </div>
        @endif

        @if ($repositoryCanCreateBranch)
            <form class="repo-branch-form" wire:submit="createRepositoryBranch">
                <div class="field">
                    <label for="repository-new-branch">{{ __('repository-workspace.branch_name') }}</label>
                    <input id="repository-new-branch" wire:model="repositoryNewBranch" type="text" maxlength="200" required autocapitalize="off" autocomplete="off" spellcheck="false" placeholder="{{ __('repository-workspace.branch_placeholder') }}" aria-describedby="repository-branch-base @error('repositoryNewBranch') repository-new-branch-error @enderror" @error('repositoryNewBranch') aria-invalid="true" @enderror>
                    <small id="repository-branch-base" class="field-hint">{{ __('repository-workspace.branch_base', ['branch' => $repositoryBranch]) }}</small>
                    @error('repositoryNewBranch')
                        <span id="repository-new-branch-error" class="field-error">{{ $message }}</span>
                    @enderror
                </div>
                <button type="submit" class="button button-secondary" wire:loading.attr="disabled"><x-icon name="add" /> {{ __('repository-workspace.create_branch') }}</button>
            </form>
        @endif

        @if ($repositoryCanOpenPull)
            <form class="repo-branch-form" wire:submit="createRepositoryPullRequest">
                <h3>{{ __('repository-workspace.open_draft') }}</h3>
                <div class="field">
                    <label for="repository-pull-title">{{ __('repository-workspace.pull_title') }}</label>
                    <input id="repository-pull-title" wire:model="repositoryNewPullTitle" type="text" maxlength="200" required @error('repositoryNewPullTitle') aria-invalid="true" aria-describedby="repository-pull-title-error" @enderror>
                    @error('repositoryNewPullTitle')
                        <span id="repository-pull-title-error" class="field-error">{{ $message }}</span>
                    @enderror
                </div>
                <div class="field">
                    <label for="repository-pull-base">{{ __('repository-workspace.pr_into') }}</label>
                    <select id="repository-pull-base" wire:model="repositoryNewPullBase" required @error('repositoryNewPullBase') aria-invalid="true" aria-describedby="repository-pull-base-error" @enderror>
                        <option value="" disabled>{{ __('repository-workspace.choose_base') }}</option>
                        @if ($repositoryDefaultBranch && ! in_array($repositoryDefaultBranch, array_column($repositoryBranches, 'name'), true))
                            <option value="{{ $repositoryDefaultBranch }}">{{ $repositoryDefaultBranch }}</option>
                        @endif
                        @foreach ($repositoryBranches as $branch)
                            @if ($branch['name'] !== $repositoryBranch)
                                <option value="{{ $branch['name'] }}">{{ $branch['name'] }}</option>
                            @endif
                        @endforeach
                    </select>
                    @error('repositoryNewPullBase')
                        <span id="repository-pull-base-error" class="field-error">{{ $message }}</span>
                    @enderror
                </div>
                <button type="submit" class="button button-secondary" wire:loading.attr="disabled"><x-icon name="merge" /> {{ __('repository-workspace.open_draft') }}</button>
            </form>
        @endif
    </section>

    <section class="repo-card" aria-labelledby="repository-commits-title">
        <div class="repo-card-header">
            <h2 id="repository-commits-title">{{ __('repository-workspace.commit_history') }}</h2>
            @if ($repositoryBranch)
                <span class="repo-ref">{{ $repositoryBranch }}</span>
            @endif
        </div>

        @if ($repositoryCommits)
            <ol class="repo-commit-list">
                @foreach ($repositoryCommits as $commit)
                    <li wire:key="git-commit-{{ $commit['sha'] }}" class="repo-commit">
                        <div class="repo-commit-copy">
                            <strong>{{ \Illuminate\Support\Str::before($commit['message'], "\n") }}</strong>
                            <div class="repo-commit-meta">
                                @if (filled($commit['author']))<span>{{ $commit['author'] }}</span>@endif
                                @if (filled($commit['authored_at']))
                                    <time datetime="{{ $commit['authored_at'] }}" lang="{{ app()->getLocale() }}" x-data x-init="const date = new Date($el.dateTime); if (!Number.isNaN(date.getTime())) $el.textContent = new Intl.DateTimeFormat($el.lang, {dateStyle: 'medium', timeStyle: 'short'}).format(date)">{{ substr($commit['authored_at'], 0, 10) }}</time>
                                @endif
                            </div>
                        </div>
                        <code class="repo-ref" title="{{ $commit['sha'] }}">{{ substr($commit['sha'], 0, 7) }}</code>
                    </li>
                @endforeach
            </ol>
        @else
            <div class="repo-empty"><x-icon name="commit" /><p>{{ __('repository-workspace.no_commits') }}</p></div>
        @endif

        @if ($repositoryHasMoreCommits)
            <div class="repo-card-footer">
                <button class="text-link" type="button" wire:click="loadMoreRepositoryCommits" wire:loading.attr="disabled">{{ __('repository-workspace.more_commits') }}</button>
            </div>
        @endif
    </section>
</div>

@if ($repositoryAuditEvents->isNotEmpty())
    <section class="repo-card repo-audit" aria-labelledby="repository-audit-heading">
        <div class="repo-card-header">
            <h2 id="repository-audit-heading">{{ __('repository-review.audit_heading') }}</h2>
        </div>
        <ul class="repo-audit-list">
            @foreach ($repositoryAuditEvents as $event)
                <li wire:key="repository-audit-{{ $event->id }}">
                    <span><strong>{{ $event->actor_name }}</strong> {{ __('repository-review.actions.'.$event->action) }}</span>
                    <span class="repo-audit-details">
                        @if (isset($event->details['pull_number']))<code>#{{ $event->details['pull_number'] }}</code>@endif
                        @if (isset($event->details['branch']))<code>{{ $event->details['branch'] }}</code>@endif
                        @if (isset($event->details['path']))<code>{{ $event->details['path'] }}</code>@endif
                        @if (isset($event->details['commit_sha']))<code>{{ substr($event->details['commit_sha'], 0, 7) }}</code>@endif
                    </span>
                    <span class="repo-audit-meta">
                        <span class="badge badge-neutral">{{ __('repository-review.audit_status.'.$event->status) }}</span>
                        <x-local-time :date="$event->created_at" :identity="'repository-audit-'.$event->id" />
                    </span>
                </li>
            @endforeach
        </ul>
    </section>
@endif
