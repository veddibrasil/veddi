<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\CompanyTransaction;
use App\Models\Payment;
use App\Services\Finance\BalanceService;
use App\Services\Payment\VindiService;
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

    public function handle(VindiService $vindi, BalanceService $balanceService): void
    {
        Log::channel('payments')->info('Reconciliação Vindi iniciada');

        $startDate = now()->subDays(7)->toDateString();
        $endDate = now()->toDateString();

        $this->reconcileTransactions($vindi, $startDate, $endDate);
        $this->reconcileAffiliateBalances($vindi, $balanceService);

        Log::channel('payments')->info('Reconciliação Vindi concluída');
    }

    /**
     * Compara transações Yapay aprovadas no período com CompanyTransactions locais.
     * Detecta webhooks perdidos: transação paga na Yapay mas não processada localmente.
     */
    private function reconcileTransactions(VindiService $vindi, string $startDate, string $endDate): void
    {
        $page = 1;
        $totalMissing = 0;

        do {
            $vindiTransactions = $vindi->listTransactions($startDate, $endDate, $page);

            if (empty($vindiTransactions)) {
                break;
            }

            foreach ($vindiTransactions as $tx) {
                $token = $tx['token'] ?? $tx['transaction_token'] ?? null;
                $statusName = $tx['status_name'] ?? null;
                $amount = (float) ($tx['price_payment'] ?? $tx['amount'] ?? 0);
                $orderNumber = $tx['order_number'] ?? null;

                if (! $token || $statusName !== 'Aprovada') {
                    continue;
                }

                // Verifica se existe Payment local com este token
                $payment = Payment::where('vindi_transaction_token', $token)->first();

                if (! $payment) {
                    // Webhook perdido — transação aprovada na Yapay sem registro local
                    $totalMissing++;
                    Log::channel('discord')->critical('Reconciliação Vindi: transação aprovada sem Payment local — webhook provavelmente perdido', [
                        'type' => 'reconciliation',
                        'vindi_token' => $token,
                        'order_number' => $orderNumber,
                        'amount' => $amount,
                        'status' => $statusName,
                        'action_needed' => 'Verificar manualmente e reprocessar se necessário',
                    ]);

                    continue;
                }

                // Payment existe mas não está marcado como pago
                if ($payment->status !== 'paid') {
                    Log::channel('discord')->critical('Reconciliação Vindi: Payment local não está como paid mas Yapay registra Aprovada', [
                        'type' => 'reconciliation',
                        'vindi_token' => $token,
                        'payment_id' => $payment->id,
                        'local_status' => $payment->status,
                        'order_id' => $payment->order_id,
                        'amount' => $amount,
                    ]);
                }

                // CompanyTransaction existe para este payment?
                $hasTransaction = CompanyTransaction::withoutGlobalScopes()
                    ->where('payment_id', $payment->id)
                    ->exists();

                if (! $hasTransaction && $payment->status === 'paid') {
                    Log::channel('discord')->critical('Reconciliação Vindi: Payment pago sem CompanyTransaction — carteira pode estar incorreta', [
                        'type' => 'reconciliation',
                        'payment_id' => $payment->id,
                        'vindi_token' => $token,
                        'order_id' => $payment->order_id,
                        'amount' => $amount,
                    ]);
                }
            }

            $page++;

            // Evita loop infinito em APIs sem paginação clara
        } while (count($vindiTransactions) >= 50 && $page <= 20);

        Log::channel('payments')->info('Reconciliação de transações Vindi concluída', [
            'period' => "{$startDate} → {$endDate}",
            'missing_locally' => $totalMissing,
        ]);
    }

    /**
     * Para cada empresa com affiliate token, compara saldo interno vs saldo real na Yapay.
     * Divergência acima de R$1,00 gera alerta crítico.
     */
    private function reconcileAffiliateBalances(VindiService $vindi, BalanceService $balanceService): void
    {
        $threshold = 1.00;
        $divergences = 0;

        Company::where('active', true)
            ->whereNotNull('vindi_affiliate_token')
            ->each(function (Company $company) use ($vindi, $balanceService, $threshold, &$divergences) {
                $affiliateBalance = $vindi->getAffiliateBalance($company->vindi_affiliate_token);

                if ($affiliateBalance === null) {
                    // Falha na API — ignora esta empresa nesta rodada
                    return;
                }

                $localBalance = $balanceService->calculateBalance($company);
                $localAvailable = $localBalance['available_balance'];
                $diff = round(abs($affiliateBalance - $localAvailable), 2);

                if ($diff > $threshold) {
                    $divergences++;
                    Log::channel('discord')->critical('Reconciliação Vindi: divergência de saldo entre carteira interna e subconta Yapay', [
                        'type' => 'reconciliation',
                        'company_id' => $company->id,
                        'company_name' => $company->name,
                        'local_available_balance' => $localAvailable,
                        'vindi_affiliate_balance' => $affiliateBalance,
                        'diff' => $diff,
                        'action_needed' => 'Verificar transações sem split ou saques não confirmados',
                    ]);
                } else {
                    Log::channel('payments')->debug('Reconciliação saldo afiliado OK', [
                        'company_id' => $company->id,
                        'local' => $localAvailable,
                        'vindi' => $affiliateBalance,
                        'diff' => $diff,
                    ]);
                }
            });

        Log::channel('payments')->info('Reconciliação de saldos afiliados concluída', [
            'divergences' => $divergences,
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
