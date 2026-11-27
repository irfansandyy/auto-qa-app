<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\SendPasswordResetCode;
use App\Services\PasswordResetCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(Request $request): View
    {
        $challenge = PasswordResetCodes::pendingChallenge($request->session());

        return view('auth.forgot-password', ['email' => $challenge['email'] ?? '']);
    }

    public function store(Request $request): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);
        if (! SendPasswordResetCode::mailReady()) {
            throw ValidationException::withMessages(['email' => __('auth-ui.reset_unavailable')]);
        }

        $email = $validated['email'];
        $limiter = 'password-reset:'.hash('sha256', $email);
        $canSend = Cache::add($limiter.':minute', 1, 60) && ! RateLimiter::tooManyAttempts($limiter.':hour', 5);
        $pending = PasswordResetCodes::pendingChallenge($request->session());
        if ($canSend || ($pending['email'] ?? null) !== $email) {
            $pending = [
                'email' => $email,
                'id' => (string) Str::ulid(),
                'expires_at' => now()->addMinutes(PasswordResetCodes::EXPIRY_MINUTES + SendPasswordResetCode::MAX_QUEUE_MINUTES)->timestamp,
            ];
            $request->session()->put(PasswordResetCodes::SESSION_KEY, $pending);
        }
        if ($canSend) {
            RateLimiter::hit($limiter.':hour', 3600);
            SendPasswordResetCode::dispatch($email, $pending['id'], app()->getLocale(), now()->addMinutes(SendPasswordResetCode::MAX_QUEUE_MINUTES)->timestamp);
        }

        return redirect()->route('password.reset');
    }
}
