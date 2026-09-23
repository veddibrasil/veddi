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
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');

        $devSources = app()->environment('local') ? ' http://localhost:* ws://localhost:*' : '';

        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; ".
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' blob: https://unpkg.com https://api.mapbox.com https://www.googletagmanager.com https://connect.facebook.net{$devSources}; ".
            "worker-src 'self' blob:; ".
            // Embedded Signup do WhatsApp: o SDK da Meta abre iframes/popup em facebook.com.
            "child-src 'self' blob: https://www.facebook.com https://web.facebook.com; ".
            "frame-src 'self' https://www.facebook.com https://web.facebook.com https://staticxx.facebook.com; ".
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://unpkg.com https://api.mapbox.com{$devSources}; ".
            "img-src 'self' data: blob: https:; ".
            "font-src 'self' data: https://fonts.bunny.net; ".
            "connect-src 'self' ws: wss: https://nominatim.openstreetmap.org https://viacep.com.br https://api.mapbox.com https://events.mapbox.com https://www.googletagmanager.com https://www.google-analytics.com https://analytics.google.com https://www.facebook.com https://web.facebook.com https://graph.facebook.com{$devSources}; ".
            "object-src 'none'; ".
            "base-uri 'self'; ".
            "form-action 'self'; ".
            "frame-ancestors 'self';"
        );

        return $response;
    }
}
