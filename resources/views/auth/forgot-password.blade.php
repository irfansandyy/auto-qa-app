@extends('auth.shell', ['headingId' => 'forgot-password-heading'])

@section('auth-card')
    <h2 id="forgot-password-heading">{{ __('auth-ui.forgot_heading') }}</h2>
    <p class="muted">{{ __('auth-ui.forgot_description') }}</p>
    @include('auth.feedback')
    <form method="POST" action="{{ route('password.email') }}" class="form-stack">
        @csrf
        <div class="field">
            <label for="email">{{ __('auth-ui.email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email', $email ?? '') }}" autocomplete="username" required maxlength="255" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email')<span id="email-error" class="field-error">{{ $message }}</span>@enderror
        </div>
        <button class="button button-primary button-full" type="submit">{{ __('auth-ui.send_reset_code') }}</button>
    </form>
    <p class="auth-note"><a href="{{ route('login') }}">{{ __('auth-ui.back_to_signin') }}</a></p>
@endsection
