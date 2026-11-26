<?php

namespace App\Http\Controllers;

use App\Jobs\SendPasswordResetCode;
use App\Services\PasswordResetCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    private const SESSION_KEY = 'password_reset_challenge';

    public function showRequest(Request $request): View
    {
        return view('auth.forgot-password', ['email' => $this->challenge($request)['email'] ?? '']);
    }

    public function send(Request $request): RedirectResponse
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
        $pending = $this->challenge($request);
        if ($canSend || ($pending['email'] ?? null) !== $email) {
            $pending = [
                'email' => $email,
                'id' => (string) Str::ulid(),
                'expires_at' => now()->addMinutes(PasswordResetCodes::EXPIRY_MINUTES + SendPasswordResetCode::MAX_QUEUE_MINUTES)->timestamp,
            ];
            $request->session()->put(self::SESSION_KEY, $pending);
        }
        if ($canSend) {
            RateLimiter::hit($limiter.':hour', 3600);
            SendPasswordResetCode::dispatch($email, $pending['id'], app()->getLocale(), now()->addMinutes(SendPasswordResetCode::MAX_QUEUE_MINUTES)->timestamp);
        }

        return redirect()->route('password.reset');
    }

    public function showReset(Request $request): View|RedirectResponse
    {
        $challenge = $this->challenge($request);

        return $challenge
            ? view('auth.reset-password', ['email' => $challenge['email']])
            : $this->requestNewCode();
    }

    public function update(Request $request, PasswordResetCodes $codes): RedirectResponse
    {
        $challenge = $this->challenge($request);
        if ($challenge === null) {
            return $this->requestNewCode();
        }
        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], ['code.regex' => __('auth-ui.reset_code_invalid')]);

        if (! $codes->consume($challenge['email'], $challenge['id'], $validated['code'], $validated['password'])) {
            throw ValidationException::withMessages(['code' => __('auth-ui.reset_code_invalid')]);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('password_reset_success', true);
    }

    /** @return array{email: string, id: string, expires_at: int}|null */
    private function challenge(Request $request): ?array
    {
        $challenge = $request->session()->get(self::SESSION_KEY);

        return $challenge && $challenge['expires_at'] > now()->timestamp ? $challenge : null;
    }

    private function requestNewCode(): RedirectResponse
    {
        return redirect()->route('password.request')->withErrors(['email' => __('auth-ui.reset_request_required')]);
    }
}
