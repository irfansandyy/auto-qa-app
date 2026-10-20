@php
    $modelUser = auth()->user();
    $selectedProvider = old('model_provider', $modelUser->model_provider ?: 'local');
    $hasModelKey = filled($modelUser->getRawOriginal('model_api_key'));
@endphp

<section class="card preferences-card" aria-labelledby="model-settings-heading" wire:ignore>
    <div class="section-heading">
        <div>
            <h2 id="model-settings-heading">{{ __('model-settings.heading') }}</h2>
            <p>{{ __('model-settings.description') }}</p>
        </div>
    </div>

    @if (session('model_settings_saved'))
        <div class="notice notice-success" role="status">{{ __('model-settings.saved') }}</div>
    @endif

    <form method="POST" action="{{ route('settings.model') }}" class="form-stack" x-data="{ provider: @js($selectedProvider) }">
        @csrf
        <div class="field">
            <label for="model_provider">{{ __('model-settings.provider') }}</label>
            <select id="model_provider" name="model_provider" x-model="provider" @error('model_provider') aria-invalid="true" aria-describedby="model-provider-error" @enderror>
                @foreach (\App\Services\ModelClient::PROVIDERS as $providerOption)
                    <option value="{{ $providerOption }}" @selected($selectedProvider === $providerOption)>{{ __('model-settings.'.$providerOption) }}</option>
                @endforeach
            </select>
            @error('model_provider')<span class="field-error" id="model-provider-error">{{ $message }}</span>@enderror
        </div>

        <p class="field-hint" x-show="provider === 'local'">{{ __('model-settings.local_help') }}</p>

        <div class="form-stack" x-show="provider !== 'local'" @if ($selectedProvider === 'local') x-cloak @endif>
            <p class="field-hint">{{ __('model-settings.cloud_help') }}</p>
            <div class="field">
                <label for="model_name">{{ __('model-settings.model') }}</label>
                <input id="model_name" name="model_name" value="{{ old('model_name', $modelUser->model_name) }}" maxlength="150" autocomplete="off" :disabled="provider === 'local'" :required="provider !== 'local'" aria-describedby="model-name-help @error('model_name') model-name-error @enderror" @error('model_name') aria-invalid="true" @enderror>
                <span class="field-hint" id="model-name-help">{{ __('model-settings.model_help') }}</span>
                @error('model_name')<span class="field-error" id="model-name-error">{{ $message }}</span>@enderror
            </div>

            <div class="field" x-show="provider === 'custom'" @if ($selectedProvider !== 'custom') x-cloak @endif>
                <label for="model_base_url">{{ __('model-settings.endpoint') }}</label>
                <input id="model_base_url" name="model_base_url" type="url" value="{{ old('model_base_url', $modelUser->model_base_url) }}" placeholder="https://api.example.com/v1" maxlength="2048" autocomplete="off" :disabled="provider !== 'custom'" :required="provider === 'custom'" aria-describedby="model-endpoint-help @error('model_base_url') model-endpoint-error @enderror" @error('model_base_url') aria-invalid="true" @enderror>
                <span class="field-hint" id="model-endpoint-help">{{ __('model-settings.endpoint_help') }}</span>
                @error('model_base_url')<span class="field-error" id="model-endpoint-error">{{ $message }}</span>@enderror
            </div>

            <div class="field">
                <label for="model_api_key">{{ __('model-settings.api_key') }}</label>
                <input id="model_api_key" name="model_api_key" type="password" maxlength="4096" autocomplete="new-password" :disabled="provider === 'local'" aria-describedby="model-key-status model-key-help @error('model_api_key') model-key-error @enderror" @error('model_api_key') aria-invalid="true" @enderror>
                <span class="field-hint" id="model-key-status">{{ $hasModelKey ? __('model-settings.key_saved') : __('model-settings.key_empty') }}</span>
                <span class="field-hint" id="model-key-help">{{ __('model-settings.key_help') }}</span>
                @error('model_api_key')<span class="field-error" id="model-key-error">{{ $message }}</span>@enderror
            </div>

            @if ($hasModelKey)
                <label class="check-label">
                    <input name="forget_api_key" type="checkbox" value="1" :disabled="provider === 'local'">
                    <span>{{ __('model-settings.remove_key') }}</span>
                </label>
            @endif
        </div>

        <div class="action-row">
            <button type="submit" class="button button-primary">{{ __('model-settings.save') }}</button>
        </div>
    </form>
</section>
