<?php

namespace App\Services\Fiscal;

use App\Jobs\AutoIssueFiscalNote;
use App\Jobs\CancelFiscalNotesOfCancelledOrder;
use App\Models\FiscalNote;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ponte entre as mudanças do pedido/pagamento (observers) e os jobs fiscais. Roda dentro do
 * save: nunca pode derrubar a gravação do pedido, e só enfileira depois do commit.
 */
class FiscalAutoIssueDispatcher
{
    public function __construct(private readonly FiscalAutoIssuePolicy $policy) {}

    /** Pedido que pode ter ficado pronto para a NFC-e automática (aceite, pagamento, etapa). */
    public function consider(Order $order): void
    {
        $this->safely($order, function () use ($order) {
            // Do mais barato ao mais caro: campos do pedido, filial ligada, pagamento recebido.
            if (! FiscalAutoIssuePolicy::candidate($order) || ! $this->policy->activeConfigFor($order) || $order->needsPdvPaymentConfirmation()) {
                return;
            }

            $orderId = $order->id;
            $delay = max(0, (int) config('fiscal.auto_issue.delay_seconds', 15));
            DB::afterCommit(fn () => $this->safely($order, fn () => AutoIssueFiscalNote::dispatch($orderId)->delay(now()->addSeconds($delay))));
        });
    }

    /** Pedido cancelado/estornado: nota ativa precisa ser cancelada junto. */
    public function orderClosed(Order $order): void
    {
        $this->safely($order, function () use ($order) {
            $hasActiveNote = FiscalNote::withoutGlobalScopes()
                ->where('company_id', $order->company_id)
                ->where('order_id', $order->id)
                ->whereIn('status', ['pending', 'authorized'])
                ->exists();

            if ($hasActiveNote) {
                $orderId = $order->id;
                DB::afterCommit(fn () => $this->safely($order, fn () => CancelFiscalNotesOfCancelledOrder::dispatch($orderId)));
            }
        });
    }

    private function safely(Order $order, \Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            // A varredura fiscal:auto-issue pega o pedido depois; o cancelamento fica no Controle.
            Log::channel('fiscal')->error('Emissão automática: falha ao enfileirar', [
                'order_id' => $order->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
