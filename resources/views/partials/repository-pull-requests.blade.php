<div class="repo-columns repo-pr-columns">
    <section class="repo-card" aria-labelledby="repository-pr-list-title">
        <div class="repo-card-header repo-pr-list-header">
            <h2 id="repository-pr-list-title">{{ __('repository-workspace.pull-requests') }}</h2>
            <div class="repo-picker">
                <label for="repository-pr-state" class="sr-only">{{ __('repository-workspace.pr_filter') }}</label>
                <select id="repository-pr-state" wire:change="setRepositoryPullRequestState($event.target.value)" wire:loading.attr="disabled">
                    @foreach (['open', 'closed', 'all'] as $state)
                        <option value="{{ $state }}" @selected($repositoryPullRequestState === $state)>{{ __('repository-workspace.pr_'.$state) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <ul class="repo-list">
            @forelse ($repositoryPullRequests as $pull)
                @php
                    $listStatus = ($pull['merged'] ?? false) ? 'merged' : (($pull['draft'] ?? false) && $pull['state'] === 'open' ? 'draft' : $pull['state']);
                @endphp
                <li wire:key="repo-pr-{{ $pull['number'] }}">
                    <button class="repo-list-button repo-pr-list-button" type="button" wire:click="openRepositoryPullRequest({{ $pull['number'] }})" wire:loading.attr="disabled" @if (($repositoryPullRequest['number'] ?? null) === $pull['number']) aria-current="true" @endif>
                        <x-icon name="merge" />
                        <span>
                            <strong>{{ $pull['title'] }}</strong>
                            <small>#{{ $pull['number'] }} <span aria-hidden="true">·</span> {{ __('repository-workspace.pr_'.$listStatus) }}</small>
                        </span>
                    </button>
                </li>
            @empty
                <li class="repo-list-note">{{ __('repository-workspace.pr_empty') }}</li>
            @endforelse
        </ul>

        @if ($repositoryHasMorePullRequests)
            <div class="repo-card-footer">
                <button class="text-link" type="button" wire:click="loadMoreRepositoryPullRequests" wire:loading.attr="disabled">{{ __('repository-workspace.more_prs') }}</button>
            </div>
        @endif
    </section>

    <section class="repo-card repo-pr-detail" aria-label="{{ __('repository-workspace.pull-requests') }}">
        @if ($repositoryPullRequest)
            @php
                $pull = $repositoryPullRequest;
                $detailStatus = $pull['merged'] ? 'merged' : ($pull['draft'] && $pull['state'] === 'open' ? 'draft' : $pull['state']);
                $pullFiles = $pull['files'] ?? [];
                $truncated = ($pull['files_truncated'] ?? false) || ($pull['changed_files'] ?? count($pullFiles)) > count($pullFiles);
            @endphp

            <div class="repo-pr-detail-header">
                <div class="repo-pr-meta">
                    <span>#{{ $pull['number'] }}</span>
                    <span class="repo-pr-status" data-status="{{ $detailStatus }}">{{ __('repository-workspace.pr_'.$detailStatus) }}</span>
                </div>
                <h2>{{ $pull['title'] }}</h2>
                <p class="repo-pr-author">{{ $pull['author'] ?: __('repository-workspace.pr_unknown_author') }}</p>
                <dl class="repo-pr-refs">
                    <div>
                        <dt>{{ __('repository-workspace.pr_from') }}</dt>
                        <dd><code class="repo-ref">{{ $pull['head_ref'] }}</code></dd>
                    </div>
                    <div>
                        <dt>{{ __('repository-workspace.pr_into') }}</dt>
                        <dd><code class="repo-ref">{{ $pull['base_ref'] }}</code></dd>
                    </div>
                    <div>
                        <dt>{{ __('repository-workspace.pr_head') }}</dt>
                        <dd><code class="repo-ref" title="{{ $pull['head_sha'] }}">{{ substr($pull['head_sha'], 0, 7) }}</code></dd>
                    </div>
                </dl>
                @if (filled($pull['body']))
                    <p class="repo-pr-body">{{ $pull['body'] }}</p>
                @endif
            </div>

            @include('partials.repository-review-status')

            @if ($pullFiles)
                <div class="repo-change-list">
                    <p class="repo-change-count">{{ trans_choice('repository-workspace.files_changed', $pull['changed_files'] ?? count($pullFiles)) }}</p>
                    @foreach ($pullFiles as $changedFile)
                        @include('partials.repository-diff', [
                            'change' => $changedFile,
                            'expanded' => $loop->first || ($repositoryCommentAnchor['path'] ?? null) === $changedFile['path'],
                            'diffIdentity' => 'pull-'.$pull['number'].'-'.$pull['head_sha'],
                            'commenting' => $repositoryCanComment,
                            'fileComments' => array_values(array_filter($repositoryComments, fn ($comment) => $comment['path'] === $changedFile['path'])),
                        ])
                    @endforeach
                </div>
            @endif

            @php
                $previousComments = array_filter($repositoryComments, fn ($comment) => ! in_array($comment['path'], array_column($pullFiles, 'path'), true));
            @endphp
            @if ($previousComments)
                <section class="repo-review-section" aria-label="{{ __('repository-review.previous_comments') }}">
                    <h3>{{ __('repository-review.previous_comments') }}</h3>
                    @include('partials.repository-review-comments', ['fileComments' => $previousComments, 'showCommentPath' => true])
                </section>
            @endif

            @if ($truncated)
                <p class="repo-list-note">{{ __('repository-workspace.diff_truncated') }}</p>
            @endif

            @if ($pull['state'] === 'open' && ! $pull['merged'])
                <div class="repo-pr-actions">
                    @if ($pull['draft'] && $repositoryCanMarkReady)
                        <p>{{ __('repository-workspace.pr_ready_hint') }}</p>
                        <button class="button button-primary" type="button" wire:click="markRepositoryPullRequestReady" wire:loading.attr="disabled">{{ __('repository-workspace.pr_ready') }}</button>
                    @elseif ($pull['draft'])
                        <p>{{ __('repository-workspace.'.(($repositoryPermissions['can_write_pull_requests'] ?? false) ? 'pr_ready_grant' : 'pr_ready_permissions')) }}</p>
                        <a class="text-link" href="{{ route('docs.github-app').'#permissions' }}">{{ __('repository-workspace.permissions_guide') }}</a>
                    @elseif (! $pull['can_merge'])
                        <p>{{ __('repository-workspace.pr_merge_unavailable') }}</p>
                        <a class="text-link" href="{{ route('docs.github-app').'#permissions' }}">{{ __('repository-workspace.permissions_guide') }}</a>
                    @elseif (! $repositoryCanMerge && ! $truncated)
                        <p>{{ __('repository-workspace.pr_merge_grant') }}</p>
                        <a class="text-link" href="{{ route('docs.github-app').'#permissions' }}">{{ __('repository-workspace.permissions_guide') }}</a>
                    @elseif ($pull['mergeable'] === false)
                        <p>{{ __('repository-workspace.pr_conflicts') }}</p>
                    @elseif ($pull['mergeable'] === null)
                        <p>{{ __('repository-workspace.pr_merge_pending') }}</p>
                    @elseif ($repositoryMergeBlockedByQuality)
                        <p>{{ __('repository-review.merge_blocked') }}</p>
                    @elseif ($repositoryCanMerge && ! $truncated)
                        <button class="button button-primary" type="button" wire:click="mergeRepositoryPullRequest" wire:confirm="{{ __('repository-workspace.pr_merge_confirm', ['number' => $pull['number'], 'sha' => substr($pull['head_sha'], 0, 7), 'base' => $pull['base_ref']]) }}" wire:loading.attr="disabled"><x-icon name="merge" /> {{ __('repository-workspace.pr_merge') }}</button>
                    @endif
                </div>
            @endif
        @else
            <div class="repo-empty">
                <x-icon name="merge" />
                <p>{{ __('repository-workspace.pr_choose') }}</p>
            </div>
        @endif
    </section>
</div>
