<?php

namespace App\Services\Finance;

use App\Models\Company;
use App\Models\CompanyTransaction;
use App\Models\CompanyWalletEntry;
use App\Models\PaymentSettings;
use App\Services\Payment\VindiService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AnticipationService
{
    public function __construct(private VindiService $vindi) {}

    /**
     * Retorna todas as transações elegíveis para antecipação com os dados de taxa calculados.
     * Não persiste nada — use para montar a lista no modal.
     *
     * @return Collection<int, array{
     *   id: int,
     *   type: string,
     *   description: string,
     *   net_value: float,
     *   release_date: string,
     *   days_remaining: int,
     *   rate: float,
     *   rate_pct: float,
     *   fee: float,
     *   net_after_fee: float,
     * }>
     */
    /**
     * Consulta o saldo disponível para antecipação diretamente na Yapay.
     * Retorna null se a empresa não tem affiliate_token ou o endpoint falhar.
     *
     * Campos esperados na resposta (a confirmar com a Yapay):
     *   amount_to_anticipate, fee, net_amount, fee_percentage
     */
    public function getGatewayAnticipationInfo(Company $company): ?array
    {
        $token = $company->vindi_affiliate_token;

        if (empty($token)) {
            return null;
        }

        return $this->vindi->getAnticipationBalance($token);
    }

    /**
     * Retorna as faixas de taxa de antecipação configuradas para a empresa.
     * Útil para exibir a tabela de taxas no modal antes de selecionar transações.
     *
     * @return array{d2: float, d7: float, d15: float, d30: float}
     */
    public function getRates(Company $company): array
    {
        $settings = $company->loadMissing('paymentSettings')->paymentSettings ?? null;

        return [
            'd2' => round($this->anticipationRate(2, $settings) * 100, 2),
            'd7' => round($this->anticipationRate(7, $settings) * 100, 2),
            'd15' => round($this->anticipationRate(15, $settings) * 100, 2),
            'd30' => round($this->anticipationRate(30, $settings) * 100, 2),
        ];
    }

    public function getEligibleTransactions(Company $company): Collection
    {
        $today = now()->toDateString();
        $settings = $company->loadMissing('paymentSettings')->paymentSettings ?? null;

        return CompanyTransaction::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'confirmed')
            ->where('withdrawn', false)
            ->where('release_date', '>', $today)
            ->orderBy('release_date')
            ->get()
            ->map(function ($tx) use ($settings) {
                $netValue = (float) $tx->net_value;
                $daysRemaining = (int) Carbon::today()->diffInDays(Carbon::parse($tx->release_date), false);
                $rate = $this->anticipationRate($daysRemaining, $settings);
                $fee = round($netValue * $rate, 2);

                return [
                    'id' => $tx->id,
                    'type' => $tx->type,
                    'description' => $tx->description ?? '',
                    'net_value' => $netValue,
                    'release_date' => Carbon::parse($tx->release_date)->toDateString(),
                    'days_remaining' => $daysRemaining,
                    'rate' => $rate,
                    'rate_pct' => round($rate * 100, 2),
                    'fee' => $fee,
                    'net_after_fee' => round($netValue - $fee, 2),
                ];
            });
    }

    /**
     * Calcula o resumo de antecipação para um subconjunto de transações (por ID),
     * a partir dos dados já calculados por getEligibleTransactions().
     *
     * @param  Collection  $eligibleTransactions  Retorno de getEligibleTransactions()
     * @param  array<int>  $selectedIds
     * @return array{transactions_count: int, gross_amount: float, fee_amount: float, net_amount: float, has_eligible: bool}
     */
    public function calculateSummary(Collection $eligibleTransactions, array $selectedIds): array
    {
        $selected = $eligibleTransactions->whereIn('id', $selectedIds)->values();

        $grossAmount = round($selected->sum('net_value'), 2);
        $feeAmount = round($selected->sum('fee'), 2);
        $netAmount = round($grossAmount - $feeAmount, 2);

        return [
            'transactions_count' => $selected->count(),
            'gross_amount' => $grossAmount,
            'fee_amount' => $feeAmount,
            'net_amount' => $netAmount,
            'has_eligible' => $selected->isNotEmpty(),
        ];
    }

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
        $settings = $company->loadMissing('paymentSettings')->paymentSettings ?? null;

        // Calcula montante bruto para solicitar à Yapay antes de atualizar registros locais.
        // Sem affiliate_token (empresas sem integração Yapay ou ambiente de teste), pula chamada.
        if (! empty($company->vindi_affiliate_token)) {
            $grossAmount = CompanyTransaction::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereIn('id', $transactionIds)
                ->where('status', 'confirmed')
                ->where('withdrawn', false)
                ->where('release_date', '>', $today)
                ->sum('net_value');

            if ($grossAmount > 0) {
                $this->vindi->requestAnticipation($company->vindi_affiliate_token, (float) $grossAmount);
            }
        }

        return DB::transaction(function () use ($company, $transactionIds, $today, $settings) {
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
                $rate = $this->anticipationRate($daysRemaining, $settings);
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
     * Mesma lógica do PaymentCalculatorService.
     */
    private function anticipationRate(int $days, ?PaymentSettings $settings): float
    {
        return match (true) {
            $days <= 2 => (float) ($settings?->anticipation_rate_d2 ?? config('payments.credit_card.anticipation_d2')),
            $days <= 7 => (float) ($settings?->anticipation_rate_d7 ?? config('payments.credit_card.anticipation_d7')),
            $days <= 15 => (float) ($settings?->anticipation_rate_d15 ?? config('payments.credit_card.anticipation_d15')),
            default => (float) ($settings?->anticipation_rate_d30 ?? config('payments.credit_card.anticipation_d30')),
        };
    }
}
