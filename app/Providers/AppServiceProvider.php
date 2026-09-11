<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\ClientIp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
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
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Markdown::withSecuredEncoding();

        Password::defaults(function (): Password {
            $rule = Password::min(10)->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // Each poster is a synchronous dompdf render (A4/A3 layout, a 1200 px
        // QR PNG, TTF metrics) on the four-worker php-fpm pool that also
        // serves every tenant's public pages and panels, and nothing caches
        // the result. Cap how often one account can ask for a download.
        RateLimiter::for('conference-assets', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('user:'.($request->user()?->getAuthIdentifier() ?? ClientIp::from($request))));
    }
}
