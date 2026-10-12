<?php

namespace App\Services\Finance;

use App\Events\WalletBalanceUpdated;
use App\Models\Company;
use App\Models\CompanyTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class BalanceService
{
    /**
     * Calcula o saldo completo da empresa sem persistir.
     * Seguro para chamadas frequentes (ex.: resposta de API em tempo real).
     *
     * Uma transação conta como liberada quando status=released OU quando está
     * confirmed e a release_date já chegou — assim o saldo não depende de um job
     * de liberação rodando (o ReleaseCompanyTransactionsJob está desligado desde
     * que saque/antecipação foram para o portal Vindi).
     *
     * Estornadas (refunded) e em chargeback ficam fora de todos os saldos.
     *
     *   blocked_balance   = confirmed com release_date no futuro ("a receber")
     *   available_balance = liberadas e não sacadas
     *   total_balance     = blocked + available
     *   withdrawn_balance = já sacadas
     */
    public function calculateBalance(Company $company): array
    {
        $today = now()->toDateString();

        $blockedBalance = (float) $this->unwithdrawn($company)
            ->where('status', 'confirmed')
            ->where('release_date', '>', $today)
            ->sum('net_value');

        $availableBalance = (float) $this->unwithdrawn($company)
            ->where(fn (Builder $query) => $this->releasedBy($query, $today))
            ->sum('net_value');

        $withdrawnBalance = (float) CompanyTransaction::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('withdrawn', true)
            ->sum('net_value');

        return [
            'total_balance' => round($blockedBalance + $availableBalance, 2),
            'blocked_balance' => round($blockedBalance, 2),
            'available_balance' => round(max(0, $availableBalance), 2),
            'withdrawn_balance' => round($withdrawnBalance, 2),
            'reserve_balance' => 0.0,
        ];
    }

    /**
     * Avisa a tela da carteira/dashboard (canal wallet.{companyId}) que o saldo mudou.
     * Só depois do commit, para a tela não recarregar um saldo que ainda pode ser revertido.
     */
    public function broadcastUpdate(int $companyId): void
    {
        DB::afterCommit(function () use ($companyId) {
            $company = Company::withoutGlobalScopes()->find($companyId);

            if (! $company) {
                return;
            }

            $balance = $this->calculateBalance($company);

            WalletBalanceUpdated::dispatch($companyId, $balance['available_balance'], $balance['blocked_balance']);
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

        // Baseline: o que já está liberado hoje.
        $currentAvailable = $this->calculateBalance($company)['available_balance'];

        // Transações confirmadas agrupadas por release_date (futuras)
        $releasing = $this->unwithdrawn($company)
            ->selectRaw('release_date, SUM(net_value) as daily_amount')
            ->where('status', 'confirmed')
            ->where('release_date', '>', $today)
            ->where('release_date', '<=', $until)
            ->groupBy('release_date')
            ->orderBy('release_date')
            ->get()
            ->keyBy(fn ($row) => $row->release_date->toDateString());

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

    private function unwithdrawn(Company $company): Builder
    {
        return CompanyTransaction::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('withdrawn', false);
    }

    private function releasedBy(Builder $query, string $date): void
    {
        $query->where('status', 'released')
            ->orWhere(fn (Builder $confirmed) => $confirmed
                ->where('status', 'confirmed')
                ->where('release_date', '<=', $date));
    }
}
