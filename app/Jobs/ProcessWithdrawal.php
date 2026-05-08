<?php

namespace App\Jobs;

use App\Models\CompanyTransaction;
use App\Models\CompanyWalletEntry;
use App\Models\CompanyWithdrawal;
use App\Services\Payment\StarkService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWithdrawal implements ShouldQueue
{
    use Queueable;

    public array $backoff = [60, 300, 900];

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
        // Idempotency guard: atomic check-and-set prevents concurrent or retried runs
        // from processing the same withdrawal twice (double debit).
        $canProcess = DB::transaction(function () {
            $withdrawal = CompanyWithdrawal::lockForUpdate()->findOrFail($this->withdrawalId);

            if ($withdrawal->status === 'done') {
                Log::channel('payments')->info('Saque já processado, ignorando execução duplicada', [
                    'withdrawal_id' => $withdrawal->id,
                ]);

                return false;
            }

            $withdrawal->update(['status' => 'processing']);

            return true;
        });

        if (! $canProcess) {
            return;
        }

        $withdrawal = CompanyWithdrawal::findOrFail($this->withdrawalId);

        Log::channel('payments')->info('Processando saque via Stark Bank', [
            'withdrawal_id' => $withdrawal->id,
            'company_id' => $withdrawal->company_id,
            'amount' => $withdrawal->amount,
            'payout_type' => $withdrawal->payout_type,
        ]);

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
                // Guardas de idempotência: nunca criar dois lançamentos para o mesmo saque.
                $withdrawalDescription = 'Saque #'.$withdrawal->id;
                $feeDescription = 'Taxa PIX - Saque #'.$withdrawal->id;

                $alreadyWithdrawalEntry = CompanyWalletEntry::where('company_id', $withdrawal->company_id)
                    ->where('type', 'withdrawal')
                    ->where('description', $withdrawalDescription)
                    ->lockForUpdate()
                    ->exists();

                $alreadyPixFeeEntry = CompanyWalletEntry::where('company_id', $withdrawal->company_id)
                    ->where('type', 'pix_fee')
                    ->where('description', $feeDescription)
                    ->lockForUpdate()
                    ->exists();

                $pixFee = $withdrawal->payout_type === 'PIX' ? (float) $withdrawal->pix_fee : 0.0;
                $transferAmount = (float) $withdrawal->amount;
                if ($withdrawal->payout_type === 'PIX') {
                    $transferAmount = max(0.0, round($transferAmount - $pixFee, 2));
                }

                $starkReference = $transfer['id'] ?? (string) $withdrawal->id;

                if (! $alreadyWithdrawalEntry) {
                    CompanyWalletEntry::create([
                        'company_id' => $withdrawal->company_id,
                        'order_id' => null,
                        'type' => 'withdrawal',
                        'amount' => $transferAmount,
                        'description' => $withdrawalDescription,
                        'reference' => $starkReference,
                    ]);
                } elseif ($transfer['id'] ?? null) {
                    CompanyWalletEntry::where('company_id', $withdrawal->company_id)
                        ->where('type', 'withdrawal')
                        ->where('description', $withdrawalDescription)
                        ->update(['reference' => $starkReference]);
                }

                if ($pixFee > 0 && ! $alreadyPixFeeEntry) {
                    CompanyWalletEntry::create([
                        'company_id' => $withdrawal->company_id,
                        'order_id' => null,
                        'type' => 'pix_fee',
                        'amount' => $pixFee,
                        'description' => $feeDescription,
                        'reference' => $starkReference,
                    ]);
                } elseif ($pixFee > 0 && ($transfer['id'] ?? null)) {
                    CompanyWalletEntry::where('company_id', $withdrawal->company_id)
                        ->where('type', 'pix_fee')
                        ->where('description', $feeDescription)
                        ->update(['reference' => $starkReference]);
                }
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
        $company = $withdrawal->company;

        $pixFee = $withdrawal->payout_type === 'PIX' ? (float) $withdrawal->pix_fee : 0.0;
        $transferAmount = (float) $withdrawal->amount;
        if ($withdrawal->payout_type === 'PIX') {
            $transferAmount = max(0.0, round($transferAmount - $pixFee, 2));
        }

        $base = [
            'amount' => $transferAmount,
            'owner_name' => $withdrawal->bank_owner_name ?? $company->default_bank_owner_name ?? $company->name,
            'owner_cpf_cnpj' => $withdrawal->bank_owner_cpf_cnpj ?? $company->default_bank_owner_cpf_cnpj ?? $company->owner_cpf_cnpj ?? '',
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
