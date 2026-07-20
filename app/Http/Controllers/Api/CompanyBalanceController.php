<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Finance\BalanceService;
use Illuminate\Http\JsonResponse;

class CompanyBalanceController extends Controller
{
    public function __construct(
        private readonly BalanceService $balanceService,
    ) {}

    /**
     * GET /api/company/balance
     * Retorna o saldo atual da empresa autenticada.
     */
    public function balance(): JsonResponse
    {
        $company = app('current.company');
        $data = $this->balanceService->calculateBalance($company);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/company/balance/forecast
     * Retorna a agenda financeira dos próximos 30 dias.
     */
    public function forecast(): JsonResponse
    {
        $company = app('current.company');
        $forecast = $this->balanceService->getFinancialForecast($company, 30);

        return response()->json(['data' => $forecast]);
    }
}
