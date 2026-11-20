<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Troca a empresa ativa do usuário logado entre as empresas em que ele tem vínculo.
 * Só aceita empresas do próprio vínculo (company_user): não há como escolher empresa alheia.
 */
class CompanySwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'company_id' => ['required', 'integer'],
        ]);

        $company = $user->companies()
            ->where('companies.id', $data['company_id'])
            ->first();

        abort_if($company === null, 403, 'Acesso não autorizado.');

        $request->session()->put(User::ACTIVE_COMPANY_SESSION_KEY, $company->id);

        return redirect()->route(User::homeRouteForRole($user->roleForCompany($company)));
    }
}
