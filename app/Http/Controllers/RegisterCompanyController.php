<?php

namespace App\Http\Controllers;

use App\DTOs\OnboardingDTO;
use App\Http\Requests\RegisterCompanyRequest;
use App\Services\Company\OnboardingService;
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

        // All plans start as PENDING_PAYMENT (setup fee required)
        session(['pending_company_id' => $company->id]);

        return redirect()->route('register.pending')
            ->with('info', 'Sua conta foi criada. Aguardando confirmação do pagamento da taxa de ativação.');
    }
}
