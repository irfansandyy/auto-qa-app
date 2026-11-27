<div class="run-setup">
    <fieldset class="run-focus" aria-describedby="run-focus-help @error('runSectors') run-focus-error @enderror">
        <legend>{{ __('dashboard-panels.runs.focus') }}</legend>
        <div class="run-focus-intro">
            <p id="run-focus-help" class="field-hint">{{ __('dashboard-panels.runs.focus_help') }}</p>
            <button class="text-link" type="button" wire:click="setPanel('rules')">
                {{ __('dashboard-panels.runs.browse_rules') }}
            </button>
        </div>

        <div class="run-focus-groups">
            @foreach ($runFocusGroups as $group => $sectors)
                @if ($sectors !== [])
                    <div role="group" aria-labelledby="run-focus-{{ $group }}">
                        <p class="run-focus-group-label" id="run-focus-{{ $group }}">{{ __('dashboard-panels.runs.groups.'.$group) }}</p>
                        <div class="run-focus-options">
                            @foreach ($sectors as $sector)
                                <label class="run-focus-option" wire:key="run-focus-sector-{{ $sector }}">
                                    <input type="checkbox" wire:model.live="runSectors" value="{{ $sector }}">
                                    <span>{{ __('qa-rule-catalog.'.$sector) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
        @error('runSectors')
            <p id="run-focus-error" class="field-error" role="alert">{{ $message }}</p>
        @enderror
        @error('runSectors.*')
            <p class="field-error" role="alert">{{ $message }}</p>
        @enderror
    </fieldset>

    <div class="field run-guidance" x-data x-on:qa-guidance-filled.window="$nextTick(() => $el.querySelector('textarea').focus())">
        <div class="guidance-heading">
            <label for="run-guidance">{{ __('ui.run_guidance') }} <span class="optional">{{ __('ui.optional') }}</span></label>
            <button class="button button-secondary guidance-generate" type="button" wire:click="generateRunGuidance" wire:loading.attr="disabled" wire:target="generateRunGuidance" x-bind:disabled="$wire.runGuidance.trim().length > 0">
                <x-icon name="guidance-stars" />
                {{ __('workspace.generate_guidance') }}
            </button>
        </div>
        <textarea id="run-guidance" wire:model="runGuidance" rows="3" maxlength="1000" aria-describedby="run-guidance-hint @error('runGuidance') run-guidance-error @enderror" @error('runGuidance') aria-invalid="true" @enderror placeholder="{{ __('ui.run_guidance_placeholder') }}"></textarea>
        <p id="run-guidance-hint" class="field-hint">{{ __('dashboard-panels.runs.guidance_help') }}</p>
        @error('runGuidance') <p id="run-guidance-error" class="field-error" role="alert">{{ $message }}</p> @enderror
    </div>
</div>
