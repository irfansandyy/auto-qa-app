<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" data-theme="{{ $theme ?? 'dark' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="{{ $theme ?? 'dark' }}">
    <title>{{ $title ?? 'Auto QA' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <a class="skip-link" href="#main">{{ __('auth-ui.skip_content') }}</a>
    @hasSection('content')
        @yield('content')
    @else
        {{ $slot }}
    @endif
    @livewireScripts
</body>
</html>
