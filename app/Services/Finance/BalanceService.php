<?php

namespace App\Services\Finance;

use App\Events\WalletBalanceUpdated;
use App\Models\Company;
use App\Models\CompanyBalance;
use App\Models\CompanyTransaction;
use App\Models\CompanyWithdrawal;

class BalanceService
{
    /**
     * Calcula o saldo completo da empresa sem persistir.
     * Seguro para chamadas frequentes (ex.: resposta de API em tempo real).
     *
     * Fórmula:
     *   total_balance     = SUM(net_value) WHERE status IN (confirmed, released) AND withdrawn=false
     *   blocked_balance   = SUM(net_value) WHERE status=confirmed AND withdrawn=false
     *   withdrawn_balance = SUM(net_value) WHERE withdrawn=true
     *   released_gross    = SUM(net_value) WHERE status=released AND withdrawn=false
     *   available_balance = released_gross
     *
     * O saldo disponível reflete apenas transações efetivamente sacadas (withdrawn=true).
     * Saques pendentes/em processamento não reduzem o saldo exibido — o débito visível
     * ocorre somente quando o job conclui e marca as transações como withdrawn.
     * A proteção contra sobre-saque é feita atomicamente em WithdrawalService via
     * lockForUpdate + whereNull('withdrawal_id').
     */
    public function calculateBalance(Company $company): array
    {
        $companyId = $company->id;

        $totalBalance = (float) CompanyTransaction::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', ['confirmed', 'released'])
            ->where('withdrawn', false)
            ->sum('net_value');

        $blockedBalance = (float) CompanyTransaction::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'confirmed')
            ->where('withdrawn', false)
            ->sum('net_value');

        $releasedGross = (float) CompanyTransaction::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'released')
            ->where('withdrawn', false)
            ->sum('net_value');

        $withdrawnBalance = (float) CompanyTransaction::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('withdrawn', true)
            ->sum('net_value');

        $reserveBalance = 0.0;
        $availableBalance = round(max(0, $releasedGross), 2);

        return [
            'total_balance' => round($totalBalance, 2),
            'blocked_balance' => round($blockedBalance, 2),
            'available_balance' => $availableBalance,
            'withdrawn_balance' => round($withdrawnBalance, 2),
            'reserve_balance' => $reserveBalance,
        ];
    }

    /**
     * Persiste (upsert) o snapshot de saldo para uma empresa.
     * Chamado pelo UpdateCompanyBalancesJob.
     */
    public function updateSnapshot(Company $company): CompanyBalance
    {
        $data = $this->calculateBalance($company);

        $balance = CompanyBalance::updateOrCreate(
            ['company_id' => $company->id],
            array_merge($data, ['last_calculated_at' => now()])
        );

        WalletBalanceUpdated::dispatch(
            $company->id,
            $data['available_balance'],
            $data['blocked_balance'],
        );

        return $balance;
    }

    /**
     * Recalcula e persiste snapshots para TODAS as empresas ativas.
     * Chamado pelo UpdateCompanyBalancesJob.
     */
    public function updateAllSnapshots(): void
    {
        Company::where('active', true)->chunkById(100, function ($companies) {
            foreach ($companies as $company) {
                $this->updateSnapshot($company);
            }
        });
    }

    /**
     * Retorna previsão dia-a-dia de valores a serem liberados nos próximos $days dias.
     *
     * Cada entrada: ['date' => 'Y-m-d', 'releasing' => float, 'cumulative_available' => float]
     *
     * cumulative_available representa o saldo acumulado estimado.
     */
    public function getFinancialForecast(Company $company, int $days = 30): array
    {
        $today = now()->toDateString();
        $until = now()->addDays($days)->toDateString();

        // Baseline: liberado mas ainda não sacado (descontando saques pendentes)
        $releasedGross = (float) CompanyTransaction::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'released')
            ->where('withdrawn', false)
            ->sum('net_value');

        $pendingWithdrawals = (float) CompanyWithdrawal::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        $currentAvailable = max(0.0, $releasedGross - $pendingWithdrawals);

        // Transações confirmadas agrupadas por release_date (futuras)
        $releasing = CompanyTransaction::withoutGlobalScopes()
            ->selectRaw('release_date, SUM(net_value) as daily_amount')
            ->where('company_id', $company->id)
            ->where('status', 'confirmed')
            ->where('withdrawn', false)
            ->whereBetween('release_date', [$today, $until])
            ->groupBy('release_date')
            ->orderBy('release_date')
            ->get()
            ->keyBy('release_date');

        $forecast = [];
        $cumulative = $currentAvailable;

        for ($i = 0; $i <= $days; $i++) {
            $date = now()->addDays($i)->toDateString();
            $daily = (float) ($releasing->get($date)?->daily_amount ?? 0.0);
            $cumulative += $daily;

            $forecast[] = [
                'date' => $date,
                'releasing' => round($daily, 2),
                'cumulative_available' => round(max(0, $cumulative), 2),
            ];
        }

        return $forecast;
    }
}
