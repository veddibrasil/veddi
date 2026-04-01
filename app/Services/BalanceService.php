<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyBalance;
use App\Models\CompanyTransaction;
use Illuminate\Support\Facades\Log;

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
     *   reserve_balance   = max(0, total_balance × 10%)
     *   available_balance = max(0, released_not_withdrawn − reserve_balance)
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

        $releasedBalance = (float) CompanyTransaction::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'released')
            ->where('withdrawn', false)
            ->sum('net_value');

        $withdrawnBalance = (float) CompanyTransaction::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('withdrawn', true)
            ->sum('net_value');

        $reserveBalance   = round(max(0, $totalBalance * 0.10), 2);
        $availableBalance = round(max(0, $releasedBalance - $reserveBalance), 2);

        return [
            'total_balance'     => round($totalBalance, 2),
            'blocked_balance'   => round($blockedBalance, 2),
            'available_balance' => $availableBalance,
            'withdrawn_balance' => round($withdrawnBalance, 2),
            'reserve_balance'   => $reserveBalance,
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

        // Log::channel('payments')->debug('Snapshot de saldo atualizado', [
        //     'company_id' => $company->id,
        //     'available'  => $data['available_balance'],
        //     'total'      => $data['total_balance'],
        // ]);

        return $balance;
    }

    /**
     * Recalcula e persiste snapshots para TODAS as empresas ativas.
     * Chamado pelo UpdateCompanyBalancesJob.
     */
    public function updateAllSnapshots(): void
    {
        Company::where('active', true)->each(function (Company $company) {
            $this->updateSnapshot($company);
        });
    }

    /**
     * Retorna previsão dia-a-dia de valores a serem liberados nos próximos $days dias.
     *
     * Cada entrada: ['date' => 'Y-m-d', 'releasing' => float, 'cumulative_available' => float]
     *
     * cumulative_available já aplica a reserva de 10% no acumulado.
     */
    public function getFinancialForecast(Company $company, int $days = 30): array
    {
        $today = now()->toDateString();
        $until = now()->addDays($days)->toDateString();

        // Baseline: liberado mas ainda não sacado
        $currentAvailable = (float) CompanyTransaction::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'released')
            ->where('withdrawn', false)
            ->sum('net_value');

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

        $forecast   = [];
        $cumulative = $currentAvailable;

        for ($i = 0; $i <= $days; $i++) {
            $date    = now()->addDays($i)->toDateString();
            $daily   = (float) ($releasing->get($date)?->daily_amount ?? 0.0);
            $cumulative += $daily;

            $forecast[] = [
                'date'                 => $date,
                'releasing'            => round($daily, 2),
                'cumulative_available' => round(max(0, $cumulative * 0.90), 2),
            ];
        }

        return $forecast;
    }
}
