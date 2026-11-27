<section class="repo-review-section" aria-labelledby="repository-review-status-heading"
    wire:key="quality-{{ $pull['number'] }}-{{ $pull['head_sha'] }}-{{ $pull['base_sha'] ?? '' }}-{{ (int) $pull['draft'] }}"
    @if ($repositoryQuality === null) wire:init="loadRepositoryReview" @endif>
    <div class="repo-review-heading">
        <h3 id="repository-review-status-heading">{{ __('repository-review.heading') }}</h3>
        <button class="text-link" type="button" wire:click="loadRepositoryReview" wire:loading.attr="disabled" wire:target="loadRepositoryReview">
            {{ __('repository-review.refresh') }}
        </button>
    </div>
    <p class="field-hint" role="status" wire:loading wire:target="loadRepositoryReview">{{ __('repository-review.loading') }}</p>
    @if ($repositoryQuality !== null)
        @if (! empty($repositoryQuality['blockers']))
            <ul class="repo-review-blockers">
                @foreach ($repositoryQuality['blockers'] as $blocker)
                    <li>{{ __('repository-review.blockers.'.$blocker) }}</li>
                @endforeach
            </ul>
        @endif
        @if (! empty($repositoryQuality['unavailable']))
            <p class="repo-review-hint">{{ __('repository-review.unavailable') }}</p>
        @endif
        @if (! empty($repositoryQuality['truncated']))
            <p class="repo-review-hint">{{ __('repository-review.checks_truncated') }}</p>
        @endif
        <h4>{{ __('repository-review.checks') }}</h4>
        <ul class="repo-review-rows">
            @forelse ($repositoryQuality['checks'] ?? [] as $check)
                <li>
                    <span>{{ $check['name'] }} @if ($check['required'])<small>{{ __('repository-review.required') }}</small>@endif</span>
                    <span class="repo-review-state" data-state="{{ $check['state'] }}">{{ __('repository-review.states.'.$check['state']) }}</span>
                </li>
            @empty
                <li>{{ __('repository-review.no_checks') }}</li>
            @endforelse
        </ul>
        <h4>{{ __('repository-review.approvals') }}</h4>
        @if (! empty($repositoryQuality['review_decision']))
            <p class="repo-review-hint">{{ __('repository-review.reviews.'.$repositoryQuality['review_decision']) }}</p>
        @endif
        <ul class="repo-review-rows">
            @forelse ($repositoryQuality['reviews'] ?? [] as $review)
                <li>
                    <strong>{{ $review['author'] }}</strong>
                    <span class="repo-review-state" data-state="{{ $review['state'] }}">{{ __('repository-review.reviews.'.$review['state']) }}</span>
                </li>
            @empty
                <li>{{ __('repository-review.no_reviews') }}</li>
            @endforelse
        </ul>
        @if ($repositoryCanComment)
            <p class="repo-review-hint">{{ __('repository-review.comment_tip') }}</p>
        @endif
        @if (! $repositoryCommentsAvailable || $repositoryCommentsTruncated)
            <p class="repo-review-hint">{{ __('repository-review.comments_partial') }}</p>
        @endif
    @endif
</section>
