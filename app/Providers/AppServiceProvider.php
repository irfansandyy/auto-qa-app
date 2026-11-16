<?php

namespace App\Providers;

use App\Http\Middleware\ApplyUiPreferences;
use App\Services\TranscriptRecorder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->useLangPath($this->app->resourcePath('lang'));
        $this->app->scoped(TranscriptRecorder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::addPersistentMiddleware([ApplyUiPreferences::class]);

        RateLimiter::for('chat', function (Request $request): Limit {
            $key = $request->user()?->getAuthIdentifier() ?: $request->ip();

            return Limit::perMinute(max(1, (int) config('autoqa.chat_api_throttle', 20)))->by((string) $key);
        });
    }
}
