@extends('layouts.app')

@section('content')
<main id="main" class="auth-shell">
    <div class="auth-story">
        <a class="brand" href="{{ url('/') }}">Auto QA</a>
        <div>
            <h1>Website testing<br>and repair</h1>
            <p>Manage projects, review test results, and track proposed repairs.</p>
        </div>
    </div>
    <section class="auth-form-wrap" aria-labelledby="signin-heading">
        <div class="auth-card">
            <h2 id="signin-heading">Sign in to your workspace</h2>
            <p class="muted">Use the operator account created during setup.</p>
            @if ($errors->any())
                <div class="notice notice-error" role="alert" tabindex="-1" x-data x-init="$el.focus()">
                    <strong>We couldn’t sign you in.</strong>
                    <ul>@foreach ($errors->getMessages() as $field => $messages)<li><a href="#{{ $field }}">{{ $messages[0] }}</a></li>@endforeach</ul>
                </div>
            @endif
            <form method="POST" action="{{ route('login') }}" class="form-stack">
                @csrf
                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')<span id="email-error" class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" autocomplete="current-password" required @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                    @error('password')<span id="password-error" class="field-error">{{ $message }}</span>@enderror
                </div>
                <label class="check-label"><input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}> Keep me signed in</label>
                <button class="button button-primary button-full" type="submit">Sign in</button>
            </form>
            <p class="auth-note">Repository access is connected separately with a GitHub App.</p>
        </div>
    </section>
</main>
@endsection
