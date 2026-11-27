<div class="repo-columns repo-file-columns">
    <section class="repo-card" aria-label="{{ __('repository-workspace.files') }}">
        <div class="repo-card-header">
            <h2>{{ __('repository-workspace.files') }}</h2>
        </div>

        @if ($repositoryBranch)
            <form class="repo-file-search" wire:submit="searchRepositoryFiles">
                <label for="repo-file-search" class="sr-only">{{ __('repository-review.search_label') }}</label>
                <input id="repo-file-search" type="search" wire:model="repositorySearch" placeholder="{{ __('repository-review.search_label') }}" minlength="2" maxlength="120" required @disabled($editingRepositoryFile)>
                <button class="button button-secondary" type="submit" wire:loading.attr="disabled" @disabled($editingRepositoryFile)>{{ __('repository-review.search') }}</button>
                @error('repositorySearch')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            </form>
        @endif

        @if ($repositorySearchResults !== null)
            <div class="repo-search-heading">
                <h3>{{ __('repository-review.search_results') }}</h3>
                <button class="text-link" type="button" wire:click="clearRepositorySearch">{{ __('repository-review.clear_search') }}</button>
            </div>
            @if ($repositorySearchResults['truncated'])
                <p class="repo-list-note">{{ __('repository-review.search_truncated') }}</p>
            @endif
            <ul class="repo-list">
                @forelse ($repositorySearchResults['items'] as $result)
                    <li wire:key="repo-search-{{ $result['path'] }}">
                        <button class="repo-list-button" type="button" wire:click="openRepositoryFile(@js($result['path']))" wire:loading.attr="disabled" @disabled($editingRepositoryFile) @if (($repositoryFile['path'] ?? null) === $result['path']) aria-current="true" @endif>
                            <x-icon name="description" />
                            <span>{{ $result['path'] }}</span>
                        </button>
                    </li>
                @empty
                    <li class="repo-list-note">{{ __('repository-review.search_empty') }}</li>
                @endforelse
            </ul>
        @else
        <nav class="repo-directory" aria-label="{{ __('repository-workspace.directory') }}">
            @if ($repositoryDirectory === '')
                <span aria-current="page">{{ __('repository-workspace.root') }}</span>
            @else
                <button type="button" wire:click="openRepositoryDirectory('')" wire:loading.attr="disabled" @disabled($editingRepositoryFile)>{{ __('repository-workspace.root') }}</button>
                @php
                    $directoryParts = explode('/', $repositoryDirectory);
                @endphp
                @foreach ($directoryParts as $part)
                    <span aria-hidden="true" class="repo-slash">/</span>
                    @if ($loop->last)
                        <span aria-current="page">{{ $part }}</span>
                    @else
                        <button type="button" wire:click="openRepositoryDirectory(@js(implode('/', array_slice($directoryParts, 0, $loop->iteration))))" wire:loading.attr="disabled" @disabled($editingRepositoryFile)>{{ $part }}</button>
                    @endif
                @endforeach
            @endif
        </nav>

        @if ($repositoryTreeTruncated)
            <p class="repo-list-note">{{ __('repository-workspace.directory_truncated') }}</p>
        @endif

        <ul class="repo-list" aria-label="{{ __('repository-workspace.files') }}">
            @forelse ($repositoryTree as $entry)
                @php
                    $isDirectory = $entry['type'] === 'dir';
                @endphp
                <li wire:key="repo-entry-{{ $entry['path'] }}">
                    <button
                        type="button" class="repo-list-button"
                        @if ($isDirectory)
                            wire:click="openRepositoryDirectory(@js($entry['path']))"
                        @else
                            wire:click="openRepositoryFile(@js($entry['path']))"
                            @if (($repositoryFile['path'] ?? null) === $entry['path']) aria-current="true" @endif
                        @endif
                        wire:loading.attr="disabled" @disabled($editingRepositoryFile)
                    >
                        <x-icon :name="$isDirectory ? 'folder' : 'description'" />
                        <span>{{ $entry['name'] }}</span>
                        @if (! $isDirectory && is_int($entry['size']))
                            <small>{{ __('repository-workspace.bytes', ['size' => number_format($entry['size'])]) }}</small>
                        @endif
                    </button>
                </li>
            @empty
                <li class="repo-list-note">{{ __('repository-workspace.directory_empty') }}</li>
            @endforelse
        </ul>
        @endif
    </section>

    <section class="repo-card repo-editor-card" aria-label="{{ __('repository-workspace.source_code') }}">
        @if ($repositoryFile)
            <div class="repo-card-header repo-editor-header">
                <div class="repo-file-heading">
                    <x-icon name="code" />
                    <h2>{{ $repositoryFile['path'] }}</h2>
                </div>
                @if ($repositoryCanEdit && ! $editingRepositoryFile)
                    <button class="button button-secondary" type="button" wire:click="editRepositoryFile" wire:loading.attr="disabled">
                        <x-icon name="edit" /> {{ __('repository-workspace.edit_file') }}
                    </button>
                @endif
            </div>

            @if ($editingRepositoryFile)
                <form wire:submit="commitRepositoryFile" class="repo-edit-form">
                    <label for="repository-editor" class="sr-only">{{ __('repository-workspace.source_code') }}</label>
                    <autoqa-code class="repo-edit-surface" data-path="{{ $repositoryFile['path'] }}" wire:key="workspace-editor-{{ $repositoryFile['sha'] }}" wire:ignore.self>
                        <pre aria-hidden="true" wire:ignore><code></code></pre>
                        <textarea id="repository-editor" wire:model="repositoryEditorContent" spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off" aria-describedby="repository-commit-note @error('repositoryEditorContent') repository-content-error @enderror" @error('repositoryEditorContent') aria-invalid="true" @enderror></textarea>
                    </autoqa-code>
                    <div class="repo-edit-controls">
                        @error('repositoryEditorContent')
                            <p class="field-error" id="repository-content-error" role="alert">{{ $message }}</p>
                        @enderror
                        <div class="field">
                            <label for="repository-commit-message">{{ __('repository-workspace.commit_message') }}</label>
                            <input id="repository-commit-message" wire:model="repositoryCommitMessage" maxlength="250" required @error('repositoryCommitMessage') aria-invalid="true" aria-describedby="repository-commit-error" @enderror>
                            @error('repositoryCommitMessage')
                                <span class="field-error" id="repository-commit-error">{{ $message }}</span>
                            @enderror
                        </div>
                        <p class="field-hint" id="repository-commit-note">{{ __('repository-workspace.commit_note', ['branch' => $repositoryBranch]) }}</p>
                        <div class="repo-inline-actions">
                            <button class="button button-primary" type="submit" wire:loading.attr="disabled">{{ __('repository-workspace.commit_file') }}</button>
                            <button class="button button-quiet" type="button" wire:click="cancelRepositoryEdit" wire:confirm="{{ __('repository-workspace.cancel_edit_confirm') }}" wire:loading.attr="disabled">{{ __('repository-workspace.cancel_edit') }}</button>
                        </div>
                    </div>
                </form>
            @else
                <autoqa-code data-path="{{ $repositoryFile['path'] }}" wire:key="workspace-source-{{ $repositoryFile['path'] }}-{{ $repositoryFile['sha'] }}" wire:ignore>
                    <pre class="repo-source" tabindex="0" aria-label="{{ __('repository-workspace.source_code') }}"><code>{{ $repositoryFile['content'] }}</code></pre>
                </autoqa-code>
                @if (! $repositoryCanEdit)
                    @php
                        $branchProtection = array_column($repositoryBranches, 'protected', 'name');
                        $branchReadOnly = $repositoryBranch === $repositoryDefaultBranch || ($branchProtection[$repositoryBranch] ?? true);
                        $readOnlyMessage = match (true) {
                            ! ($repositoryFile['editable'] ?? false) => 'file_read_only',
                            $branchReadOnly => 'branch_read_only',
                            default => 'file_edit_grant',
                        };
                    @endphp
                    <p class="repo-list-note">{{ __('repository-workspace.'.$readOnlyMessage) }}</p>
                @endif
            @endif
        @else
            <div class="repo-empty">
                <x-icon name="code" />
                <p>{{ __('repository-workspace.choose_file') }}</p>
            </div>
        @endif
    </section>
</div>
