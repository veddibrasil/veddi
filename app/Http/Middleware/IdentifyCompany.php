<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
                $company = Cache::get("company:subdomain:{$subdomain}");
                if (! $company) {
                    $company = Company::where('subdomain', $subdomain)->where('active', true)->first();
                    if ($company) {
                        Cache::put("company:subdomain:{$subdomain}", $company, now()->addMinutes(5));
                    }
                }
            }
        }

        // 2. Try slug from route parameter
        if (! $company) {
            $slug = $request->route('company');
            if ($slug) {
                $company = Cache::get("company:slug:{$slug}");
                if (! $company) {
                    $company = Company::where('slug', $slug)->where('active', true)->first();
                    if ($company) {
                        Cache::put("company:slug:{$slug}", $company, now()->addMinutes(5));
                    }
                }
            }
        }

        // 3. Authenticated user's company (multi-tenant: never trust query-string overrides)
        // Do not filter by active — EnsureCompanyIsActive handles inactive/pending redirects.
        if (! $company && auth()->check()) {
            $company = auth()->user()->companies()->orderBy('id')->first();
        }

        // 4. Fallback: first active company (single-tenant dev compatibility; debug only)
        if (! $company && config('app.debug')) {
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
