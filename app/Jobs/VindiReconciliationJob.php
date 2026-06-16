<?php

namespace App\Jobs;

use App\Models\CompanyTransaction;
use App\Models\CompanyWalletEntry;
use App\Models\CompanyWithdrawal;
use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reconcilia as transações locais contra o que a Yapay realmente registrou.
 *
 * O job roda semanalmente e detecta dois tipos de divergência:
 *   1. Transação paga na Yapay mas sem CompanyTransaction local (webhook perdido).
 *   2. Saldo disponível da subconta afiliada difere do saldo interno calculado.
 *
 * Divergências são apenas logadas/alertadas — nunca corrigidas automaticamente
 * para evitar double-credit. Cabe ao operador investigar e corrigir via superadmin.
 */
class VindiReconciliationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $startDate = now()->subDays(7)->toDateString();
        $endDate = now()->toDateString();
        $this->reconcileWalletEntries($startDate, $endDate);
    }

    /**
     * Detecta pagamentos Vindi pagos sem entrada de crédito na CompanyWalletEntry
     * e saques concluídos sem entrada de débito — sintomas do bug de external_id nulo.
     */
    private function reconcileWalletEntries(string $startDate, string $endDate): void
    {
        $missingCredits = 0;
        $missingWithdrawals = 0;

        // Pagamentos Vindi confirmados sem entrada de crédito na carteira
        Payment::whereNotNull('vindi_transaction_token')
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
            ->each(function (Payment $payment) use (&$missingCredits) {
                $reference = $payment->external_id ?? $payment->vindi_transaction_token;
                if (! $reference) {
                    return;
                }

                $hasCreditEntry = CompanyWalletEntry::where('reference', $reference)
                    ->where('type', 'credit')
                    ->exists();

                if (! $hasCreditEntry) {
                    $missingCredits++;
                    Log::channel('discord')->critical('Reconciliação carteira: Payment pago sem entrada de crédito na CompanyWalletEntry', [
                        'type' => 'reconciliation',
                        'payment_id' => $payment->id,
                        'order_id' => $payment->order_id,
                        'vindi_token' => $payment->vindi_transaction_token,
                        'external_id' => $payment->external_id,
                        'amount' => $payment->amount,
                        'action_needed' => 'Verificar se external_id estava nulo quando pagamento foi confirmado e recriar entrada manualmente',
                    ]);
                }
            });

        // Saques concluídos sem entrada de débito na carteira
        CompanyWithdrawal::withoutGlobalScopes()
            ->where('status', 'done')
            ->whereBetween('processed_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
            ->each(function (CompanyWithdrawal $withdrawal) use (&$missingWithdrawals) {
                $description = 'Saque #'.$withdrawal->id;

                $hasWithdrawalEntry = CompanyWalletEntry::where('company_id', $withdrawal->company_id)
                    ->where('type', 'withdrawal')
                    ->where('description', $description)
                    ->exists();

                if (! $hasWithdrawalEntry) {
                    $missingWithdrawals++;
                    Log::channel('discord')->critical('Reconciliação carteira: Saque concluído sem entrada de débito na CompanyWalletEntry', [
                        'type' => 'reconciliation',
                        'withdrawal_id' => $withdrawal->id,
                        'company_id' => $withdrawal->company_id,
                        'amount' => $withdrawal->amount,
                        'vindi_transfer_id' => $withdrawal->vindi_transfer_id,
                        'action_needed' => 'Verificar se job ProcessWithdrawal criou entradas corretamente',
                    ]);
                }
            });

        Log::channel('payments')->info('Reconciliação de entradas de carteira concluída', [
            'missing_credits' => $missingCredits,
            'missing_withdrawal_entries' => $missingWithdrawals,
            'period' => "{$startDate} → {$endDate}",
        ]);

        // Verifica CompanyTransactions sem wallet entry de crédito correspondente
        $missingTransactions = CompanyTransaction::withoutGlobalScopes()
            ->where('status', 'confirmed')
            ->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59'])
            ->whereDoesntHave('payment', fn ($q) => $q->whereNull('vindi_transaction_token'))
            ->count();

        Log::channel('payments')->info('Reconciliação Vindi concluída', [
            'period' => "{$startDate} → {$endDate}",
            'missing_credits' => $missingCredits,
            'missing_withdrawal_entries' => $missingWithdrawals,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('discord')->error('Job de reconciliação Vindi falhou', [
            'type' => 'reconciliation',
            'error' => $exception->getMessage(),
        ]);
    }
}
