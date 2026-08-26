<?php

namespace App\Providers;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
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
        // Deliberately not set in bootstrap/app.php's withMiddleware closure:
        // that runs before .env is loaded, so env() there always returns the
        // hardcoded default. boot() runs after the full bootstrap sequence,
        // so this is the first point TRUSTED_PROXIES can actually be read.
        $trustedProxies = array_values(array_filter(array_map('trim', explode(',', env('TRUSTED_PROXIES', '127.0.0.1,::1')))));

        TrustProxies::at($trustedProxies);
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );
    }
}
