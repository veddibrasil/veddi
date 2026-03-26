<?php

namespace App\Http\Controllers;

use App\DTOs\OnboardingDTO;
use App\Http\Requests\RegisterCompanyRequest;
use App\Services\OnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RegisterCompanyController extends Controller
{
    public function create(): View
    {
        return view('register.create');
    }

    public function store(
        RegisterCompanyRequest $request,
        OnboardingService $onboardingService,
    ): RedirectResponse {
        $company = $onboardingService->handle(
            OnboardingDTO::fromArray($request->validated())
        );

        if ($company->isPendingPayment()) {
            return redirect()->route('register.pending')
                ->with('info', 'Sua conta foi criada. Aguardando confirmação do pagamento via Asaas.');
        }

        // FREE plan: log the user in immediately
        $user = $company->users()->wherePivot('role', 'company_admin')->first();
        auth()->login($user);

        return redirect()->route('admin.dashboard')
            ->with('success', 'Bem-vindo(a)! Sua conta está ativa.');
    }
}
