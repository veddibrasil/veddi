<?php

namespace App\Services\Finance;

use App\Models\Company;
use App\Models\CompanyTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReleaseService
{
    /**
     * Libera todas as transações confirmadas cuja release_date já passou — para TODAS as empresas.
     * Chamado pelo ReleaseCompanyTransactionsJob (agendado diariamente às 01h).
     *
     * @return int Número de transações liberadas.
     */
    public function releaseTransactions(): int
    {
        $today = now()->toDateString();

        $count = DB::transaction(function () use ($today) {
            return CompanyTransaction::withoutGlobalScopes()
                ->where('status', 'confirmed')
                ->where('release_date', '<=', $today)
                ->update(['status' => 'released', 'updated_at' => now()]);
        });

        Log::channel('payments')->info('Transações liberadas para saque', [
            'count' => $count,
            'date' => $today,
        ]);

        return $count;
    }

    /**
     * Libera transações confirmadas de uma empresa específica.
     * Útil para operações manuais ou testes.
     */
    public function releaseForCompany(Company $company): int
    {
        $today = now()->toDateString();

        $count = DB::transaction(function () use ($company, $today) {
            return CompanyTransaction::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('status', 'confirmed')
                ->where('release_date', '<=', $today)
                ->update(['status' => 'released', 'updated_at' => now()]);
        });

        Log::channel('payments')->info('Transações liberadas para empresa', [
            'company_id' => $company->id,
            'count' => $count,
            'date' => $today,
        ]);

        return $count;
    }
}
