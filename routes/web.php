<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\PreferencesController;
use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/settings/preferences', PreferencesController::class)->name('settings.preferences');
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/runs/{run}/evidence/{filename}', EvidenceController::class)
        ->where('filename', 'browser-[a-zA-Z0-9-]+\.(png|zip)')
        ->name('runs.evidence');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
