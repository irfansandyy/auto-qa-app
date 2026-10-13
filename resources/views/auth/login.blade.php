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
    <section class="auth-form-wrap" aria-labelledby="signin-heading">
        <div class="auth-card">
            <h2 id="signin-heading">{{ __('auth-ui.signin_heading') }}</h2>
            <p class="muted">{{ __('auth-ui.signin_description') }}</p>
            @if ($errors->any())
                <div class="notice notice-error" role="alert" tabindex="-1" x-data x-init="$el.focus()">
                    <strong>{{ __('auth-ui.signin_error') }}</strong>
                    <ul>@foreach ($errors->getMessages() as $field => $messages)<li><a href="#{{ $field }}">{{ $messages[0] }}</a></li>@endforeach</ul>
                </div>
            @endif
            <form method="POST" action="{{ route('login') }}" class="form-stack">
                @csrf
                <div class="field">
                    <label for="email">{{ __('auth-ui.email') }}</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')<span id="email-error" class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="password">{{ __('auth-ui.password') }}</label>
                    <input id="password" type="password" name="password" autocomplete="current-password" required @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                    @error('password')<span id="password-error" class="field-error">{{ $message }}</span>@enderror
                </div>
                <label class="check-label"><input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}> {{ __('auth-ui.remember') }}</label>
                <button class="button button-primary button-full" type="submit">{{ __('auth-ui.signin') }}</button>
            </form>
            <p class="auth-note">{{ __('auth-ui.repository_note') }}</p>
        </div>
    </section>
</main>
@endsection
