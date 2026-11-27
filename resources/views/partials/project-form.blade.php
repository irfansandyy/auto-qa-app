<div class="setup-layout">
    <section class="card project-form-card" aria-labelledby="project-heading">
        <div class="section-heading">
            <div>
                <h2 id="project-heading">{{ $editingProjectId ? __('ui.edit_project') : __('ui.new_project') }}</h2>
                <p>{{ __('ui.permission_hint') }}</p>
            </div>
        </div>

        @if ($errors->any())
            <div class="notice notice-error" role="alert" tabindex="-1" x-data x-init="$el.focus()">
                <strong>{{ __('ui.check_project_details') }}</strong>
                <ul>
                    @foreach ($errors->getMessages() as $field => $messages)
                        <li><a href="#{{ $field }}">{{ $messages[0] }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form wire:submit="saveProject" class="form-stack">
            <div class="field">
                <label for="name">{{ __('ui.project_name') }}</label>
                <input id="name" wire:model="name" placeholder="{{ __('ui.project_name_placeholder') }}" required maxlength="100" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                @error('name')
                    <span id="name-error" class="field-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="websiteUrl">{{ __('ui.website_url') }}</label>
                <input id="websiteUrl" wire:model="websiteUrl" type="url" placeholder="{{ __('ui.website_placeholder') }}" required aria-describedby="website-hint @error('websiteUrl') website-error @enderror" @error('websiteUrl') aria-invalid="true" @enderror>
                <span class="field-hint" id="website-hint">{{ __('ui.website_reachability_hint') }}</span>
                @error('websiteUrl')
                    <span id="website-error" class="field-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="targetMode">{{ __('ui.website_location') }}</label>
                <select id="targetMode" wire:model.live="targetMode">
                    <option value="public">{{ __('ui.public_website') }}</option>
                    <option value="local">{{ __('ui.local_website') }}</option>
                </select>
                @error('targetMode')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </div>

            @if ($targetMode === 'local')
                <p class="guide-callout">
                    <strong>{{ __('ui.localhost_heading') }}</strong>
                    {{ __('ui.localhost_description') }}
                </p>
            @endif

            <div class="field">
                <label for="repositoryUrl">{{ __('ui.github_repository') }} <span class="optional">{{ __('ui.optional') }}</span></label>
                <input id="repositoryUrl" wire:model.live.debounce.400ms="repositoryUrl" type="url" placeholder="{{ __('ui.repository_placeholder') }}" aria-describedby="repository-hint @error('repositoryUrl') repository-error @enderror" @error('repositoryUrl') aria-invalid="true" @enderror>
                <span class="field-hint" id="repository-hint">{{ __('ui.repository_installation_hint') }}</span>
                @error('repositoryUrl')
                    <span id="repository-error" class="field-error">{{ $message }}</span>
                @enderror
            </div>

            @if (filled($repositoryUrl))
            <fieldset class="policy-field">
                <legend>{{ __('ui.repair_policy') }}</legend>
                <label class="policy-option">
                    <input type="radio" wire:model="repairPolicy" value="verified_pr">
                    <span>
                        <strong>{{ __('ui.verified_draft_pr') }}</strong>
                        <span>{{ __('ui.verified_draft_pr_description') }}</span>
                    </span>
                </label>
                <label class="policy-option">
                    <input type="radio" wire:model="repairPolicy" value="suggest_only">
                    <span>
                        <strong>{{ __('ui.suggest_only') }}</strong>
                        <span>{{ __('ui.suggest_only_description') }}</span>
                    </span>
                </label>
                @error('repairPolicy')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </fieldset>

            <details class="advanced-fields" wire:ignore.self x-data @if ($errors->has('baseBranch') || $errors->has('deployedSha')) open @endif>
                <summary>{{ __('ui.source_version_branch') }}</summary>
                <div class="form-stack">
                    <div class="field">
                        <label for="baseBranch">{{ __('ui.base_branch') }} <span class="optional">{{ __('ui.optional') }}</span></label>
                        <input id="baseBranch" wire:model="baseBranch" placeholder="{{ __('ui.base_branch_placeholder') }}">
                        @error('baseBranch')
                            <span class="field-error" x-init="$el.closest('details').open = true">{{ $message }}</span>
                        @enderror
                    </div>
                    <div class="field">
                        <label for="deployedSha">{{ __('ui.deployed_commit_sha') }} <span class="optional">{{ __('ui.optional') }}</span></label>
                        <input id="deployedSha" wire:model="deployedSha" placeholder="{{ __('ui.deployed_sha_placeholder') }}" maxlength="40" aria-describedby="sha-hint">
                        <span id="sha-hint" class="field-hint">{{ __('dashboard-panels.project.sha_help') }}</span>
                        @error('deployedSha')
                            <span class="field-error" x-init="$el.closest('details').open = true">{{ $message }}</span>
                        @enderror
                    </div>
                </div>
            </details>
            @else
                <p class="guide-callout">{{ __('ui.website_only_description') }}</p>
            @endif

            <div class="form-actions">
                <button class="button button-primary" type="submit" wire:loading.attr="disabled" wire:target="saveProject">
                    <span wire:loading.remove wire:target="saveProject">{{ $editingProjectId ? __('ui.save_changes') : __('ui.save_project') }}</span>
                    <span wire:loading wire:target="saveProject">{{ __('ui.saving_project') }}</span>
                </button>
                @if ($project)
                    <button type="button" class="button button-quiet" wire:click="closeProjectForm">{{ __('ui.cancel') }}</button>
                @endif
            </div>
        </form>
    </section>

    <aside class="setup-help">
        <h2>{{ __('ui.before_starting') }}</h2>
        <p>{{ __('dashboard-panels.project.run_focus_help') }}</p>
        <h3>{{ __('ui.repository_access') }}</h3>
        <p>{{ __('dashboard-panels.project.repository_help') }}</p>
        <a class="inline-link" href="{{ route('docs.github-app') }}#approve">{{ __('dashboard-panels.project.get_repository_access') }}</a>
        <h3>{{ __('ui.local_websites') }}</h3>
        <p>{{ __('ui.local_origin_hint') }}</p>
        <button class="text-link" type="button" wire:click="setPanel('settings')">
            <x-icon name="settings" />
            {{ __('ui.manage_connections') }}
        </button>
    </aside>
</div>
