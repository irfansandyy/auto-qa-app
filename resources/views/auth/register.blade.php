@extends('layouts.app')

@section('content')
<main id="main" class="auth-shell">
    <div class="auth-story">
        <a class="brand" href="{{ url('/') }}">Auto QA</a>
        <div>
            <h1>{{ __('auth-ui.product_heading') }}<br>{{ __('auth-ui.product_heading_second') }}</h1>
            <p>{{ __('auth-ui.product_description') }}</p>
        </div>
    </div>
    <section class="auth-form-wrap" aria-labelledby="signup-heading">
        <div class="auth-card">
            <h2 id="signup-heading">{{ __('auth-ui.signup_heading') }}</h2>
            <p class="muted">{{ __('auth-ui.signup_description') }}</p>
            @if ($errors->any())
                <div class="notice notice-error" role="alert" tabindex="-1" x-data x-init="$el.focus()">
                    <strong>{{ __('auth-ui.check_details') }}</strong>
                    <ul>@foreach ($errors->getMessages() as $field => $messages)<li><a href="#{{ $field }}">{{ $messages[0] }}</a></li>@endforeach</ul>
                </div>
            @endif
            <form method="POST" action="{{ route('register') }}" class="form-stack">
                @csrf
                <div class="field">
                    <label for="name">{{ __('auth-ui.name') }}</label>
                    <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required maxlength="100" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                    @error('name')<span id="name-error" class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="email">{{ __('auth-ui.email') }}</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')<span id="email-error" class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="password">{{ __('auth-ui.password') }}</label>
                    <input id="password" type="password" name="password" autocomplete="new-password" required minlength="12" aria-describedby="password-hint @error('password') password-error @enderror" @error('password') aria-invalid="true" @enderror>
                    <span id="password-hint" class="field-hint">{{ __('auth-ui.password_hint') }}</span>
                    @error('password')<span id="password-error" class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="password_confirmation">{{ __('auth-ui.confirm_password') }}</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required minlength="12">
                </div>
                <button class="button button-primary button-full" type="submit">{{ __('auth-ui.create_account') }}</button>
            </form>
            <p class="auth-note">{{ __('auth-ui.have_account') }} <a href="{{ route('login') }}">{{ __('auth-ui.signin') }}</a></p>
        </div>
    </section>
</main>
@endsection
