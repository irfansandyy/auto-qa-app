<?php

namespace App\Http\Controllers;

use App\Services\GitHub\GitHubAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class GitHubConnectionController extends Controller
{
    public function connect(Request $request, GitHubAccount $github): RedirectResponse
    {
        if (! config('github.client_id') || ! config('github.client_secret')) {
            return $this->settings()->withErrors(['github' => __('repository-access.connection_unconfigured')]);
        }

        $state = Str::random(64);
        $verifier = Str::random(64);
        $request->session()->put('github_link', ['state' => $state, 'verifier' => $verifier,
            'user_id' => $request->user()->getKey(), 'expires_at' => now()->addMinutes(10)->getTimestamp(),
        ]);

        return redirect()->away($github->authorizeUrl($state, $verifier, $this->callbackUrl()));
    }

    public function callback(Request $request, GitHubAccount $github): RedirectResponse
    {
        $link = $request->session()->pull('github_link');
        $state = $request->query('state');
        $code = $request->query('code');
        if (! is_array($link) || $link['user_id'] !== $request->user()->getKey() || $link['expires_at'] < now()->getTimestamp()
            || ! is_string($state) || ! hash_equals($link['state'], $state)
            || ! is_string($code) || $code === '' || strlen($code) > 255
            || ! config('github.client_id') || ! config('github.client_secret')) {
            return $this->settings()->withErrors(['github' => __('repository-access.connection_failed')]);
        }

        try {
            $github->connect($request->user(), $code, $link['verifier'], $this->callbackUrl());
        } catch (ConnectionException|RuntimeException) {
            return $this->settings()->withErrors(['github' => __('repository-access.connection_failed')]);
        }

        return $this->settings()->with('githubStatus', __('repository-access.connected'));
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $request->session()->forget('github_link');
        $request->user()->forceFill(['github_id' => null, 'github_login' => null, 'github_credentials' => null])->save();

        return $this->settings()->with('githubStatus', __('repository-access.disconnected'));
    }

    private function settings(): RedirectResponse
    {
        return redirect()->route('dashboard', ['panel' => 'settings'])->header('Referrer-Policy', 'no-referrer');
    }

    private function callbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/').route('settings.github.callback', absolute: false);
    }
}
