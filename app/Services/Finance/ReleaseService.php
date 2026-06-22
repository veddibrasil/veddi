<?php

namespace App\Services\Finance;

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
}
