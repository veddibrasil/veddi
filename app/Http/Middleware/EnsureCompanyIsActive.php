<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = app()->bound('current.company') ? app('current.company') : null;

        if (! $company || $company->isActive()) {
            return $next($request);
        }

        return redirect()->route('register.pending');
    }
}
