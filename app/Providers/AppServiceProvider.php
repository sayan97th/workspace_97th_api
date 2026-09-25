<?php

namespace App\Providers;

use App\Services\Board\ColumnPermissionService;
use App\Services\Favorite\UserFavoriteService;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Memoizes column permission lookups for the lifetime of one request.
        $this->app->scoped(ColumnPermissionService::class);
        $this->app->scoped(UserFavoriteService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureQueueRateLimiting();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Configure the rate limiters used by queued jobs.
     */
    protected function configureQueueRateLimiting(): void
    {
        RateLimiter::for('emails', fn () => Limit::perMinute(
            (int) config('queue.email_rate_limit'),
        ));
    }
}
