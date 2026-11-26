@extends('auth.shell', ['headingId' => 'reset-password-heading'])

@section('auth-card')
    <h2 id="reset-password-heading">{{ __('auth-ui.reset_heading') }}</h2>
    <p class="muted wrap-anywhere">{{ __('auth-ui.reset_code_notice', ['email' => $email]) }}</p>
    @include('auth.feedback')
    <form method="POST" action="{{ route('password.update') }}" class="form-stack">
        @csrf
        <div class="field">
            <label for="code">{{ __('auth-ui.reset_code') }}</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required aria-describedby="code-hint @error('code') code-error @enderror" @error('code') aria-invalid="true" @enderror>
            <span id="code-hint" class="field-hint">{{ __('auth-ui.reset_code_hint') }}</span>
            @error('code')<span id="code-error" class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="field">
            <label for="password">{{ __('auth-ui.new_password') }}</label>
            <input id="password" type="password" name="password" autocomplete="new-password" required minlength="8" aria-describedby="password-hint @error('password') password-error @enderror" @error('password') aria-invalid="true" @enderror>
            <span id="password-hint" class="field-hint">{{ __('auth-ui.password_hint') }}</span>
            @error('password')<span id="password-error" class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="field">
            <label for="password_confirmation">{{ __('auth-ui.confirm_password') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required minlength="8">
        </div>
        <button class="button button-primary button-full" type="submit">{{ __('auth-ui.save_new_password') }}</button>
    </form>
    <p class="auth-note"><a href="{{ route('password.request') }}">{{ __('auth-ui.request_new_code') }}</a></p>
@endsection
