<?php

namespace App\Providers;

use App\Http\Middleware\ApplyUiPreferences;
use Closure;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
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
        Password::defaults(static fn (): Password => Password::min(8)->rules(static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && str_contains($value, "\0")) {
                $fail(__('auth-ui.password_unsupported_characters'));
            } elseif (is_string($value) && config('hashing.driver') === 'bcrypt' && strlen($value) > 72) {
                $fail(__('auth-ui.password_too_long'));
            }
        }));
        Livewire::addPersistentMiddleware([ApplyUiPreferences::class]);
    }
}
