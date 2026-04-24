<?php

namespace App\Services;

use App\Jobs\ProcessWithdrawal;
use App\Models\Company;
use App\Models\CompanyTransaction;
use App\Models\CompanyWithdrawal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WithdrawalService
{
    public function __construct(private readonly BalanceService $balanceService) {}

    private function pixWithdrawalFee(): float
    {
        return (float) config('payments.pix_withdrawal_fee', 0.50);
    }

    /**
     * Valida se a empresa tem saldo disponível suficiente para o saque.
     *
     * @throws RuntimeException
     */
    public function validateWithdrawal(Company $company, float $amount, string $payoutType = 'PIX'): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('O valor do saque deve ser maior que zero.');
        }

        if ($payoutType === 'PIX') {
            $fee = $this->pixWithdrawalFee();
            if ($amount <= $fee) {
                $feeFormatted = number_format($fee, 2, ',', '.');
                throw new RuntimeException("O valor do saque via PIX deve ser maior que R\$ {$feeFormatted} (taxa de processamento).");
            }
        }

        $balance = $this->balanceService->calculateBalance($company);

        if ($amount > $balance['available_balance']) {
            $available = number_format($balance['available_balance'], 2, ',', '.');
            $requested = number_format($amount, 2, ',', '.');
            throw new RuntimeException(
                "Saldo disponível insuficiente. Disponível: R\$ {$available}, Solicitado: R\$ {$requested}."
            );
        }
    }

    /**
     * Cria um saque pendente, vincula transações liberadas (FIFO) e despacha o job de processamento.
     *
     * $payoutData aceita: payout_type (PIX|TED), pix_key, pix_key_type,
     *   bank_code, bank_agency, bank_account, bank_account_digit,
     *   bank_account_type, bank_owner_cpf_cnpj, bank_owner_name
     *
     * @throws RuntimeException se o saldo for insuficiente
     */
    public function requestWithdrawal(Company $company, float $amount, array $payoutData): CompanyWithdrawal
    {
        $payoutType = $payoutData['payout_type'] ?? 'PIX';
        $this->validateWithdrawal($company, $amount, $payoutType);

        return DB::transaction(function () use ($company, $amount, $payoutData, $payoutType) {
            $pixFee = $payoutType === 'PIX' ? $this->pixWithdrawalFee() : 0.0;

            $withdrawal = CompanyWithdrawal::create(array_merge(
                [
                    'company_id' => $company->id,
                    'amount' => $amount,
                    'pix_fee' => $pixFee,
                    'status' => 'pending',
                ],
                $payoutData
            ));

            // Reserva transações elegíveis com lock pessimista para impedir
            // que dois saques concorrentes consumam o mesmo saldo.
            $transactions = CompanyTransaction::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('status', 'released')
                ->where('withdrawn', false)
                ->whereNull('withdrawal_id')
                ->orderBy('release_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $remaining = $amount;
            $reservedIds = [];

            foreach ($transactions as $tx) {
                if ($remaining <= 0) {
                    break;
                }

                $reservedIds[] = $tx->id;
                $remaining -= (float) $tx->net_value;
            }

            if ($remaining > 0) {
                throw new RuntimeException('Saldo disponível insuficiente no momento. Tente novamente.');
            }

            CompanyTransaction::withoutGlobalScopes()
                ->whereIn('id', $reservedIds)
                ->update([
                    'withdrawal_id' => $withdrawal->id,
                    'updated_at' => now(),
                ]);

            Log::channel('payments')->info('Saque solicitado e transações vinculadas', [
                'company_id' => $company->id,
                'withdrawal_id' => $withdrawal->id,
                'amount' => $amount,
                'reserved_transactions' => count($reservedIds),
            ]);

            Log::channel('audit')->info('withdrawal.requested', [
                'company_id' => $company->id,
                'withdrawal_id' => $withdrawal->id,
                'amount' => $amount,
                'payout_type' => $payoutType,
                'pix_fee' => $pixFee,
            ]);

            ProcessWithdrawal::dispatch($withdrawal->id);

            return $withdrawal;
        });
    }

    /**
     * Solicita antecipação de transações confirmadas específicas.
     *
     * Taxa por transação: value × 2% × (dias_restantes / 30)
     * A taxa é descontada automaticamente do net_value.
     * A transação é liberada imediatamente (release_date = hoje, status = released).
     *
     * @param  int[]  $transactionIds
     * @return CompanyTransaction[]
     *
     * @throws RuntimeException
     */
    public function requestAnticipation(Company $company, array $transactionIds): array
    {
        if (empty($transactionIds)) {
            throw new RuntimeException('Nenhuma transação selecionada para antecipação.');
        }

        return DB::transaction(function () use ($company, $transactionIds) {
            $transactions = CompanyTransaction::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereIn('id', $transactionIds)
                ->where('status', 'confirmed')
                ->where('withdrawn', false)
                ->get();

            if ($transactions->isEmpty()) {
                throw new RuntimeException('Nenhuma transação elegível para antecipação encontrada.');
            }

            $today = now()->startOfDay();

            foreach ($transactions as $tx) {
                $releaseDate = Carbon::parse($tx->release_date)->startOfDay();
                $daysRemaining = (int) max(0, $today->diffInDays($releaseDate, false));
                $fee = round((float) $tx->value * 0.02 * ($daysRemaining / 30), 2);
                $newNetValue = max(0, round((float) $tx->net_value - $fee, 2));

                $tx->update([
                    'is_anticipated' => true,
                    'anticipation_fee' => $fee,
                    'net_value' => $newNetValue,
                    'release_date' => now()->toDateString(),
                    'status' => 'released',
                ]);
            }

            Log::channel('payments')->info('Antecipação realizada', [
                'company_id' => $company->id,
                'transaction_ids' => $transactionIds,
                'count' => $transactions->count(),
            ]);

            Log::channel('audit')->info('anticipation.requested', [
                'company_id' => $company->id,
                'transaction_ids' => $transactionIds,
                'count' => $transactions->count(),
            ]);

            return $transactions->fresh()->all();
        });
    }
}
