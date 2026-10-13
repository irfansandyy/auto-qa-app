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
    <section class="auth-form-wrap" aria-labelledby="setup-heading">
        <div class="auth-card">
            <span class="eyebrow">First-time setup</span>
            <h2 id="setup-heading">Create your operator account</h2>
            <p class="muted">This account controls the workspace. Account setup closes after you register.</p>
            @if ($errors->any())
                <div class="notice notice-error" role="alert" tabindex="-1" x-data x-init="$el.focus()">
                    <strong>Check these details.</strong>
                    <ul>@foreach ($errors->getMessages() as $field => $messages)<li><a href="#{{ $field }}">{{ $messages[0] }}</a></li>@endforeach</ul>
                </div>
            @endif
            <form method="POST" action="{{ route('register') }}" class="form-stack">
                @csrf
                <div class="field">
                    <label for="name">Your name</label>
                    <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required maxlength="100" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                    @error('name')<span id="name-error" class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')<span id="email-error" class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" autocomplete="new-password" required minlength="12" aria-describedby="password-hint @error('password') password-error @enderror" @error('password') aria-invalid="true" @enderror>
                    <span id="password-hint" class="field-hint">Use at least 12 characters.</span>
                    @error('password')<span id="password-error" class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirm password</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required minlength="12">
                </div>
                <button class="button button-primary button-full" type="submit">Create workspace account</button>
            </form>
            <p class="auth-note">You don’t need a separate GitHub bot account. Connect a GitHub App after setup.</p>
        </div>
    </section>
</main>
@endsection
