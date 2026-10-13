<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $title ?? 'Auto QA' }}</title>
    @vite('resources/css/app.css')
    @livewireStyles
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>
    @hasSection('content')
        @yield('content')
    @else
        {{ $slot }}
    @endif
    @livewireScripts
</body>
</html>
