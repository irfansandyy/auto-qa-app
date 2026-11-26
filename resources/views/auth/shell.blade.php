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
    <section class="auth-form-wrap" aria-labelledby="{{ $headingId }}">
        <div class="auth-card">
            @yield('auth-card')
        </div>
    </section>
</main>
@endsection
