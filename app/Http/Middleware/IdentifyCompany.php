<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = null;

        // 1. Try subdomain resolution
        $host = $request->getHost();
        $appHost = config('app.host', parse_url(config('app.url'), PHP_URL_HOST));

        if ($appHost && str_ends_with($host, '.'.$appHost)) {
            $subdomain = str_replace('.'.$appHost, '', $host);
            if ($subdomain && $subdomain !== $appHost) {
                $company = Company::where('subdomain', $subdomain)->where('active', true)->first();
            }
        }

        // 2. Try slug from route parameter or query string
        if (! $company) {
            $slug = $request->route('company') ?? $request->query('company');
            $company = $slug
                ? Company::where('slug', $slug)->where('active', true)->first()
                : null;
        }

        // 3. Authenticated user's company (multi-tenant: resolve to the user's own company)
        if (! $company && auth()->check()) {
            $company = auth()->user()->companies()->where('active', true)->orderBy('id')->first();
        }

        // 4. Fallback: first active company (single-tenant dev compatibility)
        if (! $company) {
            $company = Company::where('active', true)->orderBy('id')->first();
        }

        if (! $company) {
            // No company found — allow the request to continue without scoping
            // (handles fresh installs, migrations, and auth routes before seeding)
            return $next($request);
        }

        app()->instance('current.company', $company);
        view()->share('currentCompany', $company);

        return $next($request);
    }
}
