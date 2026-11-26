<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\ModelSettingsController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PreferencesController;
use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::get('/forgot-password', [PasswordResetController::class, 'showRequest'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:6,1,recovery-email:')->block(5, 5)->name('password.email');
    Route::get('/reset-password', [PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1,recovery-code:')->block(5, 5)->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('/settings/model', [ModelSettingsController::class, 'update'])->name('settings.model');
    Route::post('/settings/preferences', PreferencesController::class)->name('settings.preferences');
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/runs/{run}/evidence/{filename}', EvidenceController::class)
        ->where('filename', 'browser-[a-zA-Z0-9-]+\.(png|zip)')
        ->name('runs.evidence');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
