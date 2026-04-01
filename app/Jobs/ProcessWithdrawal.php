<?php

namespace App\Jobs;

use App\Models\CompanyWalletEntry;
use App\Models\CompanyWithdrawal;
use App\Services\AsaasService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProcessWithdrawal implements ShouldQueue
{
    use Queueable;

    public int $tries  = 3;
    public int $backoff = 30;

    public function __construct(
        public int $withdrawalId,
    ) {
        $this->onQueue('default');
    }

    public function handle(AsaasService $asaas): void
    {
        $withdrawal = CompanyWithdrawal::findOrFail($this->withdrawalId);

        Log::channel('payments')->info('Processando saque', [
            'withdrawal_id' => $withdrawal->id,
            'company_id'    => $withdrawal->company_id,
            'amount'        => $withdrawal->amount,
            'payout_type'   => $withdrawal->payout_type,
        ]);

        // Verify available balance
        $balance = CompanyWalletEntry::balanceFor($withdrawal->company_id);

        if ($balance < (float) $withdrawal->amount) {
            $withdrawal->update(['status' => 'failed']);

            Log::channel('payments')->warning('Saldo insuficiente para saque', [
                'withdrawal_id' => $withdrawal->id,
                'company_id'    => $withdrawal->company_id,
                'balance'       => $balance,
                'requested'     => $withdrawal->amount,
            ]);

            return;
        }

        $withdrawal->update(['status' => 'processing']);

        try {
            $transferData = $this->buildTransferData($withdrawal);
            $transfer     = $asaas->createTransfer($transferData);

            $withdrawal->update([
                'status'           => 'done',
                'asaas_transfer_id' => $transfer['id'] ?? null,
                'asaas_response'   => $transfer,
                'processed_at'     => now(),
            ]);

            // Debit the withdrawal from the ledger
            CompanyWalletEntry::create([
                'company_id'  => $withdrawal->company_id,
                'order_id'    => null,
                'type'        => 'withdrawal',
                'amount'      => $withdrawal->amount,
                'description' => 'Saque #' . $withdrawal->id,
                'reference'   => $transfer['id'] ?? (string) $withdrawal->id,
            ]);

            Log::channel('payments')->info('Saque processado com sucesso', [
                'withdrawal_id'    => $withdrawal->id,
                'asaas_transfer_id' => $transfer['id'] ?? null,
                'amount'           => $withdrawal->amount,
            ]);
        } catch (\Throwable $e) {
            $withdrawal->update(['status' => 'failed']);

            Log::channel('discord')->error('Falha ao processar saque', [
                'type'       => 'payments',
                'withdrawal_id' => $withdrawal->id,
                'error'         => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function buildTransferData(CompanyWithdrawal $withdrawal): array
    {
        $base = [
            'value'         => (float) $withdrawal->amount,
            'operationType' => $withdrawal->payout_type,
            'description'   => 'Saque #' . $withdrawal->id,
        ];

        if ($withdrawal->payout_type === 'PIX') {
            return array_merge($base, [
                'pixAddressKey'     => $withdrawal->pix_key,
                'pixAddressKeyType' => $withdrawal->pix_key_type,
            ]);
        }

        // TED
        return array_merge($base, [
            'bankAccount' => [
                'bank'            => ['code' => $withdrawal->bank_code],
                'accountName'     => $withdrawal->bank_owner_name,
                'ownerName'       => $withdrawal->bank_owner_name,
                'cpfCnpj'         => preg_replace('/\D/', '', $withdrawal->bank_owner_cpf_cnpj ?? ''),
                'agency'          => $withdrawal->bank_agency,
                'account'         => $withdrawal->bank_account,
                'accountDigit'    => $withdrawal->bank_account_digit,
                'bankAccountType' => $withdrawal->bank_account_type,
            ],
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('discord')->error('Job de saque falhou definitivamente', [
            'type'       => 'payments',
            'withdrawal_id' => $this->withdrawalId,
            'error'         => $exception->getMessage(),
            
        ]);

        CompanyWithdrawal::where('id', $this->withdrawalId)
            ->where('status', 'processing')
            ->update(['status' => 'failed']);
    }
}
