<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->registerDevCommands();
    }

    /**
     * Add the WhatsApp gateway to `composer dev` once it has been configured.
     */
    protected function registerDevCommands(): void
    {
        if (file_exists(base_path('services/whatsapp-gateway/.env'))) {
            DevCommands::register('npm --prefix services/whatsapp-gateway run dev', 'gateway');
        }
    }

    /**
     * Configure application rate limiters.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('gateway-webhook', fn (Request $request): Limit => Limit::perMinute(1200)->by((string) $request->ip()));
        RateLimiter::for('send-message', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->user()?->getAuthIdentifier()));
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
}
