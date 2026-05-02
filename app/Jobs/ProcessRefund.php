<?php

namespace App\Jobs;

use App\Exceptions\AsaasCircuitOpenException;
use App\Models\PaymentRefund;
use App\Services\Refund\RefundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessRefund implements ShouldQueue
{
    use Queueable;

    public array $backoff = [60, 300, 900];

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function __construct(public PaymentRefund $refund)
    {
        $this->onQueue('critical');
    }

    public function handle(RefundService $refundService): void
    {
        $this->refund->refresh();

        if (in_array($this->refund->status, ['in_progress', 'succeeded', 'canceled'])) {
            Log::channel('payments')->info('ProcessRefund ignorado: status já avançou', [
                'refund_id' => $this->refund->id,
                'status' => $this->refund->status,
            ]);

            return;
        }

        $this->refund->loadMissing(['payment', 'order']);

        $payment = $this->refund->payment;

        if (! $payment || $payment->status !== 'paid') {
            Log::channel('payments')->info('ProcessRefund ignorado: pagamento não está pago', [
                'refund_id' => $this->refund->id,
                'payment_status' => $payment?->status,
            ]);

            return;
        }

        // Simulated payments (dev environment)
        if (str_starts_with((string) ($payment->asaas_payment_id ?? ''), 'sim_') ||
            str_starts_with((string) ($payment->stark_payment_id ?? ''), 'sim_')) {
            $refundService->markSucceeded($this->refund, [
                'external_refund_id' => 'sim_refund_'.$this->refund->id,
                'external_status' => 'REFUNDED',
                'raw' => ['simulated' => true],
            ]);

            return;
        }

        try {
            $this->refund->update(['status' => 'in_progress']);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            Log::channel('payments')->warning('ProcessRefund: outro estorno já em progresso para este pagamento', [
                'refund_id' => $this->refund->id,
                'payment_id' => $this->refund->payment_id,
            ]);

            return;
        }

        try {
            $gateway = $refundService->getGatewayDriver($this->refund->gateway);
            $result = $gateway->requestRefund($payment, (float) $this->refund->amount, $this->refund->reason);

            $this->refund->update([
                'raw_request' => $result['raw'] ?? null,
                'external_refund_id' => $result['external_refund_id'] ?? null,
            ]);

            if ($result['status'] === 'in_progress') {
                // Gateway accepted — will confirm via webhook (Asaas) or manual (Stark)
                Log::channel('payments')->info('Estorno aceito pelo gateway, aguardando confirmação', [
                    'refund_id' => $this->refund->id,
                    'gateway' => $this->refund->gateway,
                    'external_refund_id' => $result['external_refund_id'] ?? null,
                ]);
            } else {
                $refundService->markFailed(
                    $this->refund,
                    'GATEWAY_REJECTED',
                    'Gateway rejeitou o estorno: '.json_encode($result['raw'] ?? [])
                );
            }
        } catch (AsaasCircuitOpenException $e) {
            Log::channel('payments')->warning('Circuit aberto — ProcessRefund adiado 15 min', [
                'refund_id' => $this->refund->id,
            ]);
            $this->refund->update(['status' => 'requested']);
            $this->release(900);
        } catch (\Throwable $e) {
            $refundService->markFailed($this->refund, 'EXCEPTION', $e->getMessage());
            throw $e;
        }
    }
}
