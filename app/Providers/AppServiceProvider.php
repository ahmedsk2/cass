<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\RecordOutgoingEmail;
use App\Support\ClientIp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Event;
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

        // A signed URL is a bearer capability with a 30-minute life. If one
        // leaks, this is what keeps it from being used to stream a 10 MB PDF a
        // thousand times off a four-worker pool.
        RateLimiter::for('file-download', fn (Request $request): Limit => Limit::perMinute(60)
            ->by(ClientIp::from($request)));

        // Spec section 9: the author status page, 20/min/IP. The key is the
        // client address only - not the token - so that enumerating tokens
        // counts against one budget instead of getting a fresh one per guess.
        RateLimiter::for('submission-status', fn (Request $request): Limit => Limit::perMinute(
            (int) config('cass.status_page_rate_limit')
        )->by(ClientIp::from($request)));

        // Registered by hand rather than by Laravel 13's listener discovery:
        // discovery matches one class to one event by the type hint of a
        // `handle()` method, and this listener deliberately has two entry
        // points for two events so the pair cannot drift apart in two files.
        Event::listen(MessageSending::class, [RecordOutgoingEmail::class, 'sending']);
        Event::listen(MessageSent::class, [RecordOutgoingEmail::class, 'sent']);
    }
}
