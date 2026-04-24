<?php

namespace App\Jobs;

use App\Models\CompanyTransaction;
use App\Models\CompanyWalletEntry;
use App\Models\CompanyWithdrawal;
use App\Services\StarkService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWithdrawal implements ShouldQueue
{
    use Queueable;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function __construct(
        public int $withdrawalId,
    ) {
        $this->onQueue('default');
    }

    public function handle(StarkService $stark): void
    {
        $withdrawal = CompanyWithdrawal::findOrFail($this->withdrawalId);

        Log::channel('payments')->info('Processando saque via Stark Bank', [
            'withdrawal_id' => $withdrawal->id,
            'company_id' => $withdrawal->company_id,
            'amount' => $withdrawal->amount,
            'payout_type' => $withdrawal->payout_type,
        ]);

        // Verificar saldo disponível
        $balance = CompanyWalletEntry::balanceFor($withdrawal->company_id);

        if ($balance < (float) $withdrawal->amount) {
            $withdrawal->update(['status' => 'failed']);

            Log::channel('payments')->warning('Saldo insuficiente para saque', [
                'withdrawal_id' => $withdrawal->id,
                'company_id' => $withdrawal->company_id,
                'balance' => $balance,
                'requested' => $withdrawal->amount,
            ]);

            return;
        }

        $withdrawal->update(['status' => 'processing']);

        try {
            $transferData = $this->buildStarkTransferData($withdrawal);
            $transfer = $stark->createTransfer($transferData);

            DB::transaction(function () use ($withdrawal, $transfer) {
                CompanyTransaction::withoutGlobalScopes()
                    ->where('withdrawal_id', $withdrawal->id)
                    ->lockForUpdate()
                    ->update([
                        'withdrawn' => true,
                        'withdrawn_at' => now(),
                        'status' => 'withdrawn',
                        'updated_at' => now(),
                    ]);

                $withdrawal->update([
                    'status' => 'done',
                    'stark_transfer_id' => $transfer['id'] ?? null,
                    'stark_response' => $transfer,
                    'processed_at' => now(),
                ]);

                // O lançamento definitivo só acontece depois da confirmação do repasse.
                CompanyWalletEntry::create([
                    'company_id' => $withdrawal->company_id,
                    'order_id' => null,
                    'type' => 'withdrawal',
                    'amount' => $withdrawal->amount,
                    'description' => 'Saque #'.$withdrawal->id,
                    'reference' => $transfer['id'] ?? (string) $withdrawal->id,
                ]);
            });

            Log::channel('payments')->info('Saque processado com sucesso via Stark Bank', [
                'withdrawal_id' => $withdrawal->id,
                'stark_transfer_id' => $transfer['id'] ?? null,
                'amount' => $withdrawal->amount,
            ]);
        } catch (\Throwable $e) {
            DB::transaction(function () use ($withdrawal) {
                CompanyTransaction::withoutGlobalScopes()
                    ->where('withdrawal_id', $withdrawal->id)
                    ->lockForUpdate()
                    ->update([
                        'withdrawal_id' => null,
                        'updated_at' => now(),
                    ]);

                $withdrawal->update(['status' => 'failed']);
            });

            Log::channel('discord')->error('Falha ao processar saque via Stark Bank', [
                'type' => 'payments',
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function buildStarkTransferData(CompanyWithdrawal $withdrawal): array
    {
        $base = [
            'amount' => (float) $withdrawal->amount,
            'owner_name' => $withdrawal->bank_owner_name ?? '',
            'owner_cpf_cnpj' => $withdrawal->bank_owner_cpf_cnpj ?? '',
            'external_id' => 'withdrawal-'.$withdrawal->id,
        ];

        if ($withdrawal->payout_type === 'PIX') {
            return array_merge($base, [
                'pix_key' => $withdrawal->pix_key,
                'pix_key_type' => $withdrawal->pix_key_type,
            ]);
        }

        // TED / transferência bancária
        return array_merge($base, [
            'bank_code' => $withdrawal->bank_code,
            'bank_agency' => $withdrawal->bank_agency,
            'bank_account' => $withdrawal->bank_account,
            'account_type' => $withdrawal->bank_account_type ?? 'checking',
            'account_digit' => $withdrawal->bank_account_digit,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('discord')->error('Job de saque falhou definitivamente', [
            'type' => 'payments',
            'withdrawal_id' => $this->withdrawalId,
            'error' => $exception->getMessage(),
        ]);

        CompanyWithdrawal::where('id', $this->withdrawalId)
            ->where('status', 'processing')
            ->update(['status' => 'failed']);

        CompanyTransaction::withoutGlobalScopes()
            ->where('withdrawal_id', $this->withdrawalId)
            ->where('withdrawn', false)
            ->update([
                'withdrawal_id' => null,
                'updated_at' => now(),
            ]);
    }
}
