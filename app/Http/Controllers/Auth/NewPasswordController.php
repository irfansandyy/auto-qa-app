<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\PasswordResetCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $challenge = PasswordResetCodes::pendingChallenge($request->session());

        return $challenge
            ? view('auth.reset-password', ['email' => $challenge['email']])
            : $this->requestNewCode();
    }

    public function store(Request $request, PasswordResetCodes $codes): RedirectResponse
    {
        $challenge = PasswordResetCodes::pendingChallenge($request->session());
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

    private function requestNewCode(): RedirectResponse
    {
        return redirect()->route('password.request')->withErrors(['email' => __('auth-ui.reset_request_required')]);
    }
}
