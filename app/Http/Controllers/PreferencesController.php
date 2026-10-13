<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreferencesController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $preferences = $request->validate([
            'locale' => ['required', Rule::in(['en', 'id'])],
            'theme' => ['required', Rule::in(['light', 'dark'])],
        ], [], [
            'locale' => __('preferences.language'),
            'theme' => __('preferences.appearance'),
        ]);

        $request->user()->update($preferences);

        return redirect()->route('dashboard', ['panel' => 'settings'])
            ->with('preferences_saved', true)
            ->withCookies([
                cookie('autoqa_locale', $preferences['locale'], 525600, '/', null, $request->isSecure(), true, false, 'lax'),
                cookie('autoqa_theme', $preferences['theme'], 525600, '/', null, $request->isSecure(), true, false, 'lax'),
            ]);
    }
}
