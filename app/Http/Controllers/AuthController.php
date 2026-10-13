<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (! User::query()->exists()) {
            return redirect()->route('register');
        }

        return view('auth.login');
    }

    public function showRegister(): View|RedirectResponse
    {
        if (User::query()->exists()) {
            return redirect()->route('login');
        }

        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        abort_if(User::query()->exists(), 403, 'The operator account has already been created.');

        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        try {
            $user = Cache::lock('autoqa-first-operator', 10)->block(3, function () use ($validated) {
                return DB::transaction(function () use ($validated) {
                    abort_if(User::query()->exists(), 403, 'The operator account has already been created.');

                    return User::query()->create([
                        'name' => $validated['name'],
                        'email' => $validated['email'],
                        'password' => Hash::make($validated['password']),
                    ]);
                });
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'email' => 'Account setup is already in progress. Please try signing in shortly.',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);
        $key = 'login:'.hash('sha256', $credentials['email'].'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many sign-in attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'The email or password did not match.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
