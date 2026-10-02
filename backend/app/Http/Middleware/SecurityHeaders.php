<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // header_remove() operates on PHP's real sent-headers list, which only
        // exists for an actual HTTP response. Under `php artisan test` (CLI
        // SAPI, PHPUnit has already written to stdout) headers are always
        // "already sent", and calling it there throws instead of no-op-ing -
        // broke the entire test suite (84/90 failing) until guarded here.
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
