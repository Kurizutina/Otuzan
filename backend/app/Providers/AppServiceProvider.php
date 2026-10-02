<?php

namespace App\Providers;

use App\Mail\Transport\SendGridApiTransport;
use Illuminate\Support\Facades\Mail;
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
        Mail::extend('sendgrid', fn (array $config) => new SendGridApiTransport($config['api_key'] ?? ''));
    }
}
