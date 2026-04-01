<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BalanceService;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CompanyBalanceController extends Controller
{
    public function __construct(
        private readonly BalanceService $balanceService,
        private readonly WithdrawalService $withdrawalService,
    ) {}

    /**
     * GET /api/company/balance
     * Retorna o saldo atual da empresa autenticada.
     */
    public function balance(): JsonResponse
    {
        $company = app('current.company');
        $data    = $this->balanceService->calculateBalance($company);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/company/balance/forecast
     * Retorna a agenda financeira dos próximos 30 dias.
     */
    public function forecast(): JsonResponse
    {
        $company  = app('current.company');
        $forecast = $this->balanceService->getFinancialForecast($company, 30);

        return response()->json(['data' => $forecast]);
    }

    /**
     * POST /api/company/withdraw
     * Solicita saque do saldo disponível.
     *
     * Body: {
     *   amount: float,
     *   payout_type: PIX|TED,
     *   pix_key?: string,
     *   pix_key_type?: string,
     *   bank_code?: string,
     *   bank_agency?: string,
     *   bank_account?: string,
     *   bank_account_digit?: string,
     *   bank_account_type?: string,
     *   bank_owner_cpf_cnpj?: string,
     *   bank_owner_name?: string
     * }
     */
    public function withdraw(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount'              => ['required', 'numeric', 'min:1'],
            'payout_type'         => ['required', 'in:PIX,TED'],
            'pix_key'             => ['required_if:payout_type,PIX', 'nullable', 'string', 'max:255'],
            'pix_key_type'        => ['required_if:payout_type,PIX', 'nullable', 'string', 'max:50'],
            'bank_code'           => ['required_if:payout_type,TED', 'nullable', 'string', 'max:10'],
            'bank_agency'         => ['required_if:payout_type,TED', 'nullable', 'string', 'max:20'],
            'bank_account'        => ['required_if:payout_type,TED', 'nullable', 'string', 'max:20'],
            'bank_account_digit'  => ['nullable', 'string', 'max:5'],
            'bank_account_type'   => ['required_if:payout_type,TED', 'nullable', 'string', 'max:20'],
            'bank_owner_cpf_cnpj' => ['required_if:payout_type,TED', 'nullable', 'string', 'max:20'],
            'bank_owner_name'     => ['required_if:payout_type,TED', 'nullable', 'string', 'max:100'],
        ]);

        $company = app('current.company');

        try {
            $withdrawal = $this->withdrawalService->requestWithdrawal(
                $company,
                (float) $validated['amount'],
                collect($validated)->except('amount')->toArray()
            );

            return response()->json([
                'message' => 'Saque solicitado com sucesso.',
                'data'    => [
                    'withdrawal_id' => $withdrawal->id,
                    'amount'        => $withdrawal->amount,
                    'status'        => $withdrawal->status,
                ],
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/company/anticipate
     * Solicita antecipação de transações confirmadas (antes da release_date).
     *
     * Body: { transaction_ids: int[] }
     */
    public function anticipate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transaction_ids'   => ['required', 'array', 'min:1'],
            'transaction_ids.*' => ['integer', 'min:1'],
        ]);

        $company = app('current.company');

        try {
            $transactions = $this->withdrawalService->requestAnticipation(
                $company,
                $validated['transaction_ids']
            );

            return response()->json([
                'message' => 'Antecipação realizada com sucesso.',
                'data'    => collect($transactions)->map(fn ($tx) => [
                    'id'               => $tx->id,
                    'value'            => $tx->value,
                    'net_value'        => $tx->net_value,
                    'anticipation_fee' => $tx->anticipation_fee,
                    'release_date'     => $tx->release_date,
                    'status'           => $tx->status,
                ]),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
