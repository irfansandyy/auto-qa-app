@php
    $changeStatus = in_array($change['status'], ['added', 'removed', 'modified', 'renamed', 'copied', 'changed', 'unchanged', 'proposed'], true) ? $change['status'] : 'changed';
    $additions = $change['additions'] ?? null;
    $deletions = $change['deletions'] ?? null;
    $oldLine = null;
    $newLine = null;
@endphp

<details class="repo-diff" wire:key="diff-{{ $diffIdentity }}-{{ $change['path'] }}" @if ($expanded ?? false) open @endif>
    <summary class="repo-diff-summary">
        <x-icon name="description" />
        <span class="repo-diff-file">
            <strong>{{ $change['path'] }}</strong>
            @if (! empty($change['previous_path']))<small>{{ $change['previous_path'] }}</small>@endif
        </span>
        <span class="repo-diff-counts">
            <span class="repo-change-status">{{ __('repository-workspace.change_status.'.$changeStatus) }}</span>
            @if ($additions !== null && $deletions !== null)
                <span class="repo-diff-added" title="{{ trans_choice('repository-workspace.additions', $additions) }}">+{{ $additions }}</span>
                <span class="repo-diff-removed" title="{{ trans_choice('repository-workspace.deletions', $deletions) }}">−{{ $deletions }}</span>
            @endif
        </span>
    </summary>

    @if (is_string($change['patch'] ?? null) && $change['patch'] !== '')
        <div class="repo-diff-body" tabindex="0" aria-label="{{ $change['path'] }}">
            <table>
                <thead class="sr-only"><tr><th>{{ __('repository-workspace.old_line') }}</th><th>{{ __('repository-workspace.new_line') }}</th><th>{{ __('repository-workspace.source_code') }}</th></tr></thead>
                <tbody>
                    @foreach (explode("\n", $change['patch']) as $line)
                        @if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $hunk))
                            @php
                                [$oldLine, $newLine] = [(int) $hunk[1], (int) $hunk[2]];
                            @endphp
                            <tr class="repo-diff-hunk"><td></td><td></td><td><code>{{ $line }}</code></td></tr>
                        @else
                            @php
                                $lineType = match ($line[0] ?? '') {
                                    '+' => 'added',
                                    '-' => 'removed',
                                    ' ' => 'context',
                                    default => 'metadata',
                                };
                                $inHunk = $oldLine !== null && $newLine !== null;
                                $previousLine = $inHunk && in_array($lineType, ['context', 'removed'], true) ? $oldLine++ : null;
                                $currentLine = $inHunk && in_array($lineType, ['context', 'added'], true) ? $newLine++ : null;
                            @endphp
                            <tr class="repo-diff-{{ $inHunk ? $lineType : 'metadata' }}">
                                <td class="repo-diff-line">
                                    @if (($commenting ?? false) && $lineType === 'removed' && $previousLine)
                                        <button class="repo-comment-line" type="button" wire:click="beginRepositoryComment(@js($change['path']), {{ $previousLine }}, 'LEFT')" wire:loading.attr="disabled" aria-label="{{ __('repository-review.comment_removed', ['line' => $previousLine]) }}">{{ $previousLine }}</button>
                                    @else
                                        {{ $previousLine }}
                                    @endif
                                </td>
                                <td class="repo-diff-line">
                                    @if (($commenting ?? false) && $lineType === 'added' && $currentLine)
                                        <button class="repo-comment-line" type="button" wire:click="beginRepositoryComment(@js($change['path']), {{ $currentLine }}, 'RIGHT')" wire:loading.attr="disabled" aria-label="{{ __('repository-review.comment_added', ['line' => $currentLine]) }}">{{ $currentLine }}</button>
                                    @else
                                        {{ $currentLine }}
                                    @endif
                                </td>
                                <td><code>{{ $line }}</code></td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @elseif (is_string($change['content'] ?? null))
        <p class="repo-list-note">{{ __('repository-workspace.proposed_source') }}</p>
        <autoqa-code data-path="{{ $change['path'] }}" wire:ignore>
            <pre class="repo-source" tabindex="0" aria-label="{{ __('repository-workspace.proposed_source') }}"><code>{{ $change['content'] }}</code></pre>
        </autoqa-code>
    @else
        <p class="repo-list-note">{{ __('repository-workspace.diff_unavailable') }}</p>
    @endif

    @if (filled($fileComments ?? []))
        @include('partials.repository-review-comments', ['showCommentPath' => false])
    @endif

    @if (($commenting ?? false) && ($repositoryCommentAnchor['path'] ?? null) === $change['path'])
        <form class="repo-inline-comment" wire:submit="submitRepositoryComment" wire:key="comment-editor-{{ $diffIdentity }}-{{ $change['path'] }}-{{ $repositoryCommentAnchor['side'] }}-{{ $repositoryCommentAnchor['line'] }}">
            <div class="field">
                <label for="repository-comment-body">{{ __('repository-review.'.($repositoryCommentAnchor['side'] === 'LEFT' ? 'comment_removed' : 'comment_added'), ['line' => $repositoryCommentAnchor['line']]) }}</label>
                <textarea id="repository-comment-body" wire:model="repositoryCommentBody" x-data x-init="$el.focus()" rows="3" maxlength="2000" required></textarea>
                <p class="field-hint">{{ __('repository-review.comment_identity') }}</p>
                @error('repositoryCommentBody')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="repo-inline-actions">
                <button class="button button-primary" type="submit" wire:loading.attr="disabled">{{ __('repository-review.post_comment') }}</button>
                <button class="button button-secondary" type="button" wire:click="cancelRepositoryComment">{{ __('repository-review.cancel') }}</button>
            </div>
        </form>
    @endif
</details>
