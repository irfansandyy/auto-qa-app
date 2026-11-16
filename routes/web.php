<?php

use App\Http\Controllers\ChatSessionPageController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\PreferencesController;
use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

require __DIR__.'/auth.php';

Route::middleware('auth')->group(function () {
    Route::post('/settings/preferences', PreferencesController::class)->name('settings.preferences');
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/runs/{run}/evidence/{filename}', EvidenceController::class)
        ->where('filename', 'browser-[a-zA-Z0-9-]+\.(png|zip)')
        ->name('runs.evidence');
    Route::get('/chat-sessions/{session}', ChatSessionPageController::class)->name('chat-sessions.show');
});
