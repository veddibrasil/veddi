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
        $onboardingService->handle(
            OnboardingDTO::fromArray($request->validated())
        );

        return redirect()->route('login')
            ->with('status', 'Sua conta foi criada. Faça login para continuar.');
    }
}
