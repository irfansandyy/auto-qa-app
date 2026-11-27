<ul class="repo-inline-comments" aria-label="{{ __('repository-review.comments') }}">
    @foreach ($fileComments as $comment)
        <li wire:key="native-review-comment-{{ $comment['id'] }}">
            <div class="repo-inline-comment-meta">
                <strong>{{ $comment['author'] }}</strong>
                @if ($showCommentPath ?? false)<code>{{ $comment['path'] }}</code>@endif
                @if ($comment['line'] ?? $comment['original_line'] ?? null)
                    <span>{{ __('repository-review.line', ['line' => $comment['line'] ?? $comment['original_line']]) }}</span>
                @endif
                @if ($comment['outdated'] ?? false)<span>{{ __('repository-review.outdated') }}</span>@endif
            </div>
            <p>{{ $comment['body'] }}</p>
        </li>
    @endforeach
</ul>
