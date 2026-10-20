<?php

namespace App\Providers;

use App\Http\Middleware\ApplyUiPreferences;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::addPersistentMiddleware([ApplyUiPreferences::class]);
    }
}
