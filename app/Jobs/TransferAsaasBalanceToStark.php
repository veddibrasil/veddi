<?php

namespace App\Jobs;

use App\Contracts\AsaasServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class TransferAsaasBalanceToStark implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [300, 600, 1200];

    private const MIN_TRANSFER = 1.00;

    public function handle(AsaasServiceInterface $asaas): void
    {
        if (! app()->environment('production') && ! config('services.asaas.allow_transfers_in_non_production', false)) {
            Log::channel('payments')->info('TransferAsaasBalanceToStark: ignorado fora de production', [
                'env' => app()->environment(),
            ]);

            return;
        }

        if (config('services.asaas.sandbox')) {
            Log::channel('payments')->info('TransferAsaasBalanceToStark: ignorado em sandbox');

            return;
        }

        $pixKey = trim((string) config('services.asaas.platform_stark_pix_key', ''));
        $pixKeyType = strtoupper(trim((string) config('services.asaas.platform_stark_pix_key_type', 'CNPJ')));

        if ($pixKey === '') {
            Log::channel('payments')->warning('TransferAsaasBalanceToStark: PLATFORM_STARK_PIX_KEY não configurado');

            return;
        }

        $balance = $asaas->getBalance();
        $available = $balance['balance'];

        if ($available <= 0) {
            Log::channel('payments')->info('TransferAsaasBalanceToStark: saldo Asaas zerado, nada a transferir');

            return;
        }

        $reserveRatio = (float) config('services.asaas.reserve_ratio', 0.05);
        $reserve = round($available * $reserveRatio, 2);
        $transferAmount = round($available - $reserve, 2);

        if ($transferAmount < self::MIN_TRANSFER) {
            Log::channel('payments')->info('TransferAsaasBalanceToStark: valor abaixo do mínimo, ignorado', [
                'available' => $available,
                'reserve' => $reserve,
                'would_transfer' => $transferAmount,
            ]);

            return;
        }

        Log::channel('payments')->info('TransferAsaasBalanceToStark: iniciando transferência', [
            'pix_key_type' => $pixKeyType,
            'pix_key_length' => strlen($pixKey),
            'amount' => $transferAmount,
        ]);

        $transfer = $asaas->createTransfer([
            'value' => $transferAmount,
            'operationType' => 'PIX',
            'pixAddressKey' => $pixKey,
            'pixAddressKeyType' => $pixKeyType,
            'description' => 'Transferência automática Asaas→Stark '.now()->toDateString(),
        ]);

        Log::channel('payments')->info('TransferAsaasBalanceToStark: transferência criada', [
            'transfer_id' => $transfer['id'] ?? null,
            'available' => $available,
            'reserve' => $reserve,
            'transferred' => $transferAmount,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('discord')->error('Falha na transferência automática Asaas→Stark', [
            'type' => 'payments',
            'error' => $exception->getMessage(),
        ]);
    }
}
