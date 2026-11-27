@php
    $githubLogin = auth()->user()->github_login;
    $githubAccountLinkingReady = config('github.client_id') && config('github.client_secret');
@endphp

<section class="card preferences-card github-settings" id="github-connection" aria-labelledby="github-settings-heading">
    <div class="section-heading">
        <div>
            <h2 id="github-settings-heading">{{ __('dashboard-panels.github.heading') }}</h2>
            <p>{{ __('dashboard-panels.github.description') }}</p>
        </div>
        <span class="badge {{ $githubReady ? 'badge-success' : 'badge-warning' }}">{{ $githubReady ? __('ui.credentials_configured') : __('ui.setup_needed') }}</span>
    </div>

    @if (session('githubStatus'))
        <div class="notice notice-success" role="status">{{ session('githubStatus') }}</div>
    @endif
    @error('github')
        <div class="notice notice-error" role="alert">{{ $message }}</div>
    @enderror

    <div class="preference-row">
        <div>
            <h3>{{ __('dashboard-panels.github.account_heading') }}</h3>
            @if ($githubLogin)
                <p>{{ __('dashboard-panels.github.connected_as', ['login' => $githubLogin]) }}</p>
            @elseif ($githubAccountLinkingReady)
                <p>{{ __('dashboard-panels.github.account_help') }}</p>
            @else
                <p>{{ __('dashboard-panels.github.manual_access') }}</p>
            @endif
        </div>
        <div class="github-settings-actions">
            @if ($githubAccountLinkingReady)
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
    </div>

    <div class="preference-row">
        <div>
            <h3>{{ __('ui.github_app') }}</h3>
            <p>{{ __('dashboard-panels.github.installation_help') }}</p>
        </div>
        <div class="github-settings-actions">
            @if ($installationUrl)
                <a class="button button-secondary" href="{{ $installationUrl }}" target="_blank" rel="noopener noreferrer">{{ __('ui.install_on_github') }}</a>
            @endif
            <a class="text-link" href="{{ route('docs.github-app') }}">{{ __('ui.github_app_guide') }}</a>
        </div>
    </div>
</section>
