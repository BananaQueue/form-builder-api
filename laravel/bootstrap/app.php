<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TRUSTED_PROXIES is NOT read here: this closure runs via
        // afterResolving(Kernel::class), which fires the moment
        // bootstrap/app.php resolves the kernel - BEFORE Laravel's own
        // LoadEnvironmentVariables bootstrapper has run. env() here always
        // returns null regardless of what .env sets (confirmed by
        // reproduction), so this used to silently fall back to the
        // hardcoded default forever. The real, env()-aware configuration
        // now lives in AppServiceProvider::boot(), which runs after the
        // environment is actually loaded.
        // api/login is deliberately NOT exempt: without CSRF protection here,
        // a cross-site form could force a victim's browser to authenticate
        // as the ATTACKER's account (login CSRF) - the frontend fetches a
        // guest CSRF token from /api/session before rendering the login form.
        $middleware->validateCsrfTokens(except: ['test_reset_database.php', 'api/public/forms/*/responses']);

        $middleware->alias([
            'legacy.auth' => \App\Http\Middleware\RequireLegacyAuth::class,
            'legacy.superadmin' => \App\Http\Middleware\RequireLegacySuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

