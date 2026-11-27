<?php

use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\ModelSettingsController;
use App\Http\Controllers\PreferencesController;
use App\Livewire\Dashboard;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));
Route::get('/docs/github-app', fn (): View => view('docs.github-app', [
    'installationUrl' => GitHubAppClient::installationUrl(),
]))->name('docs.github-app');

Route::middleware('auth')->group(function () {
    Route::post('/settings/model', [ModelSettingsController::class, 'update'])->name('settings.model');
    Route::post('/settings/preferences', PreferencesController::class)->name('settings.preferences');
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/runs/{run}/evidence/{filename}', EvidenceController::class)
        ->where('filename', 'browser-[a-zA-Z0-9-]+\.(png|zip)')
        ->name('runs.evidence');
});

require __DIR__.'/auth.php';
