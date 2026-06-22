<?php

namespace App\Services\Finance;

use App\Models\Company;
use App\Models\CompanyTransaction;
use App\Models\CompanyWalletEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AnticipationService
{
    public function __construct() {}

    /**
     * Executa a antecipação para os IDs de transações selecionados.
     * - Ajusta release_date para hoje, marca is_anticipated, deduz anticipation_fee do net_value
     * - Cria uma entrada de débito de taxa na CompanyWalletEntry
     *
     * @param  array<int>  $transactionIds
     * @return int Número de transações antecipadas
     */
    public function anticipateSelected(Company $company, array $transactionIds): int
    {
        if (empty($transactionIds)) {
            return 0;
        }

        $today = now()->toDateString();

        return DB::transaction(function () use ($company, $transactionIds, $today) {
            $transactions = CompanyTransaction::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereIn('id', $transactionIds)
                ->where('status', 'confirmed')
                ->where('withdrawn', false)
                ->where('release_date', '>', $today)
                ->get();

            if ($transactions->isEmpty()) {
                return 0;
            }

            $totalFee = 0.0;

            foreach ($transactions as $tx) {
                $netValue = (float) $tx->net_value;
                $daysRemaining = (int) Carbon::today()->diffInDays(Carbon::parse($tx->release_date), false);
                $rate = $this->anticipationRate($daysRemaining);
                $fee = round($netValue * $rate, 2);

                $tx->update([
                    'release_date' => $today,
                    'is_anticipated' => true,
                    'anticipation_fee' => $fee,
                    'net_value' => round($netValue - $fee, 2),
                    'status' => 'released',
                ]);

                $totalFee += $fee;
            }

            $totalFee = round($totalFee, 2);

            if ($totalFee > 0) {
                CompanyWalletEntry::create([
                    'company_id' => $company->id,
                    'type' => 'fee',
                    'amount' => $totalFee,
                    'description' => 'Taxa plataforma - Antecipação de recebíveis',
                ]);
            }

            Log::channel('payments')->info('Antecipação de recebíveis executada', [
                'company_id' => $company->id,
                'count' => $transactions->count(),
                'total_fee' => $totalFee,
            ]);

            return $transactions->count();
        });
    }

    /**
     * Taxa de antecipação com base nos dias restantes até a release_date.
     * WithdrawalService::requestAnticipation() usa fórmula distinta (2% flat prorated) — manter em sincronia.
     */
    private function anticipationRate(int $days): float
    {
        return match (true) {
            $days <= 2 => (float) config('payments.credit_card.anticipation_d2'),
            $days <= 7 => (float) config('payments.credit_card.anticipation_d7'),
            $days <= 15 => (float) config('payments.credit_card.anticipation_d15'),
            default => (float) config('payments.credit_card.anticipation_d30'),
        };
    }
}
