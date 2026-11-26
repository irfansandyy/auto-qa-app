@extends('auth.shell', ['headingId' => 'signin-heading'])

@section('auth-card')
    <h2 id="signin-heading">{{ __('auth-ui.signin_heading') }}</h2>
    <p class="muted">{{ __('auth-ui.signin_description') }}</p>
    @if (session('password_reset_success'))
        <p class="notice notice-success" role="status">{{ __('auth-ui.reset_success') }}</p>
    @endif
    @include('auth.feedback', ['errorHeading' => __('auth-ui.signin_error')])
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
        <div class="flex flex-wrap items-center justify-between gap-x-3">
            <label class="check-label"><input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}> {{ __('auth-ui.remember') }}</label>
            <a href="{{ route('password.request') }}" class="text-link">{{ __('auth-ui.forgot_password') }}</a>
        </div>
        <button class="button button-primary button-full" type="submit">{{ __('auth-ui.signin') }}</button>
    </form>
    @if (config('auth.registration_enabled'))
        <p class="auth-note">{{ __('auth-ui.need_account') }} <a href="{{ route('register') }}">{{ __('auth-ui.signup') }}</a></p>
    @endif
    <p class="auth-note">{{ __('auth-ui.repository_note') }}</p>
@endsection
