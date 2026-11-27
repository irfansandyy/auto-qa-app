@php
    $githubLogin = auth()->user()->github_login;
@endphp

<section class="card preferences-card github-settings" id="github-connection" aria-labelledby="github-settings-heading">
    <div class="section-heading">
        <div>
            <h2 id="github-settings-heading">{{ __('dashboard-panels.github.heading') }}</h2>
            <p>{{ __('dashboard-panels.github.description') }}</p>
        </div>
    </div>

    @if (session('githubStatus'))
        <div class="notice notice-success" role="status">{{ session('githubStatus') }}</div>
    @endif
    @error('github')
        <div class="notice notice-error" role="alert">{{ $message }}</div>
    @enderror

    <div class="preference-row">
        <div>
            <h3>{{ __('dashboard-panels.github.signer_heading') }}</h3>
            <p>{{ __($githubReady ? 'dashboard-panels.github.signer_help' : 'dashboard-panels.github.signer_check') }}</p>
        </div>
        <span class="badge justify-self-start {{ $githubReady ? 'badge-success' : 'badge-warning' }}">
            {{ __($githubReady ? 'dashboard-panels.github.signer_readable' : 'ui.setup_needed') }}
        </span>
    </div>

    <div class="preference-row">
        <div>
            <h3>{{ __('dashboard-panels.github.account_heading') }}</h3>
            @if ($githubLogin)
                <p>{{ __('dashboard-panels.github.connected_as', ['login' => $githubLogin]) }}</p>
            @elseif ($githubOAuthReady)
                <p>{{ __('dashboard-panels.github.account_help') }}</p>
            @endif
            @unless ($githubOAuthReady)
                <p>{{ __('dashboard-panels.github.oauth_setup') }}</p>
            @endunless
            @if (! $githubLogin && ! $githubOAuthReady)
                <p>{{ __('dashboard-panels.github.manual_access') }}</p>
            @endif
        </div>
        @if ($githubOAuthReady || $githubLogin)
            <div class="github-settings-actions">
                @if ($githubOAuthReady)
                    <form method="POST" action="{{ route('settings.github.connect') }}">
                        @csrf
                        <button class="button {{ $githubLogin ? 'button-secondary' : 'button-primary' }}" type="submit">
                            {{ __($githubLogin ? 'dashboard-panels.github.reconnect' : 'dashboard-panels.github.connect') }}
                        </button>
                    </form>
                @endif
                @if ($githubLogin)
                    <form method="POST" action="{{ route('settings.github.disconnect') }}">
                        @csrf
                        @method('DELETE')
                        <button class="button button-secondary" type="submit">{{ __('dashboard-panels.github.disconnect') }}</button>
                    </form>
                @endif
            </div>
        @endif
    </div>

    <div class="preference-row">
        <div>
            <h3>{{ __('dashboard-panels.github.installation_heading') }}</h3>
            <p>{{ __('dashboard-panels.github.installation_help') }}</p>
        </div>
        <div class="github-settings-actions">
            @if ($installationUrl)
                <a class="button button-secondary" href="{{ $installationUrl }}" target="_blank" rel="noopener noreferrer">{{ __('ui.install_on_github') }}</a>
            @endif
            <a class="text-link" href="{{ route('docs.github-app') }}">{{ __('ui.github_app_guide') }}</a>
        </div>
    </div>

    <div class="preference-row">
        <div class="field">
            @if ($githubCallbackUrl)
                <label for="github-callback-url">{{ __('dashboard-panels.github.callback_label') }}</label>
                <input id="github-callback-url" type="text" value="{{ $githubCallbackUrl }}" dir="ltr" readonly spellcheck="false" aria-describedby="github-callback-help" x-data x-on:focus="$el.select()">
                <span class="field-hint" id="github-callback-help">{{ __('dashboard-panels.github.callback_help') }}</span>
            @else
                <h3>{{ __('dashboard-panels.github.callback_label') }}</h3>
                <p>{{ __('dashboard-panels.github.callback_unavailable') }}</p>
            @endif
        </div>
        <div class="github-settings-actions">
            <a class="text-link" href="https://github.com/settings/apps" target="_blank" rel="noopener noreferrer">{{ __('dashboard-panels.github.registration_link') }}</a>
        </div>
    </div>
</section>
