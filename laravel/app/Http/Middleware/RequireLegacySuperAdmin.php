<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for super-admin-only endpoints (user management, audit logs, banner,
 * cross-owner form administration, super-admin password reset). Mirrors the
 * previous per-method `requireSuperAdmin()` helpers: 401 when there is no
 * session, 403 when the session belongs to a non-super-admin.
 */
class RequireLegacySuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('logged_in') !== true) {
            return response()->json(['error' => 'Authentication required'], 401);
        }

        if ($request->session()->get('role') !== 'super_admin') {
            return response()->json(['error' => 'Super admin access required'], 403);
        }

        return $next($request);
    }
}
