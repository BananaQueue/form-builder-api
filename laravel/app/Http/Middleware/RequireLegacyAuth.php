<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for endpoints that require an authenticated session. Replaces the
 * per-method `logged_in !== true` checks that used to be copy-pasted into
 * every controller, so the auth requirement lives in one enforceable place
 * (the route definition) instead of relying on each method remembering it.
 */
class RequireLegacyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('logged_in') !== true) {
            return response()->json(['error' => 'Authentication required'], 401);
        }

        return $next($request);
    }
}
