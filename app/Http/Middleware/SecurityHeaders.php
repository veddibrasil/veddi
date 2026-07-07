<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->remove('X-Powered-By');
        header_remove('X-Powered-By');

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $devSources = app()->environment('local') ? ' http://localhost:* ws://localhost:*' : '';

        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; ".
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'{$devSources}; ".
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net{$devSources}; ".
            "img-src 'self' data: https:; ".
            "font-src 'self' data: https://fonts.bunny.net; ".
            "connect-src 'self' ws: wss:{$devSources}; ".
            "object-src 'none'; ".
            "base-uri 'self'; ".
            "form-action 'self'; ".
            "frame-ancestors 'self';"
        );

        return $response;
    }
}
