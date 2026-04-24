<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckCompanyRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $company = app('current.company');

        if (! $user) {
            abort(401);
        }

        // Super admins bypass all role checks
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $userRole = $user->roleForCompany($company);

        if (! $userRole || ! in_array($userRole, $roles)) {
            // Aceitar roles customizados da empresa em rotas que permitem branch_manager
            if ($userRole && in_array('branch_manager', $roles)) {
                $isCustomRole = \App\Models\Role::where('slug', $userRole)
                    ->where('company_id', $company->id)
                    ->exists();
                if (! $isCustomRole) {
                    abort(403, 'Acesso não autorizado.');
                }
            } else {
                abort(403, 'Acesso não autorizado.');
            }
        }

        // Branch manager: bind their branch to the container
        if ($userRole === 'branch_manager') {
            $branchId = $user->branchIdForCompany($company);
            if ($branchId) {
                app()->instance('current.branch', Branch::find($branchId));
            }
        }

        return $next($request);
    }
}
