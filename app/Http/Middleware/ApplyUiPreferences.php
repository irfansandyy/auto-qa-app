<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ApplyUiPreferences
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale ?? $request->cookie('autoqa_locale', 'en');
        $theme = $request->user()?->theme ?? $request->cookie('autoqa_theme', 'light');

        app()->setLocale(in_array($locale, ['en', 'id'], true) ? $locale : 'en');
        View::share('theme', in_array($theme, ['light', 'dark'], true) ? $theme : 'light');

        return $next($request);
    }
}
