<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class RegistrationController
{
    public function show(): View
    {
        return view('register');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        $phone = $this->normalizePhone($validated['phone'] ?? null);
        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $phone,
            'password' => Hash::make($validated['password']),
        ]);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    private function normalizePhone(string $phone): string
    {
        return str_replace(' ', '', trim($phone));
    }
}
