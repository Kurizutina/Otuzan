<?php

namespace App\Providers;

use App\Mail\Transport\BrevoTransport;
use App\Mail\Transport\SendGridApiTransport;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        Mail::extend('brevo', fn (array $config) => new BrevoTransport((string) ($config['api_key'] ?? '')));
        Mail::extend('sendgrid', fn (array $config) => new SendGridApiTransport($config['api_key'] ?? ''));

        // Per account as well as per IP: the IP alone can be rotated (or, behind
        // a proxy, spoofed through X-Forwarded-For), the targeted email cannot.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('email:'.strtolower(trim((string) $request->input('email')))),
        ]);
    }
}
