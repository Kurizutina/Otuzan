<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// An account created with a temporary password (riders onboarded by an admin)
// may only read its own profile, change that password, or log out until the
// password is changed - the frontend redirect alone was UX, not enforcement.
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = ($request->isMethod('GET') && $request->is('api/auth/me'))
            || $request->is('api/auth/change-password', 'api/auth/logout');
        if ($request->user()?->MustChangePassword && !$allowed) {
            return response()->json(['error' => 'Please change your temporary password first.'], 403);
        }
        return $next($request);
    }
}
