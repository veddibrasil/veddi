<?php

namespace App\Jobs;

use App\DTOs\FiscalAutoIssueVerdict;
use App\Exceptions\FiscalNoteAlreadyIssuedException;
use App\Models\Order;
use App\Services\Fiscal\FiscalAutoIssueMonitor;
use App\Services\Fiscal\FiscalAutoIssuePolicy;
use App\Services\Fiscal\FiscalNoteService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * NFC-e automática de um pedido, disparada pelo observer do pedido/pagamento
 * (QueueFiscalAutoIssue) e pela varredura fiscal:auto-issue. Fila 'low', como o lote do Controle.
 *
 * Uma tentativa por pedido: reavalia a FiscalAutoIssuePolicy, reserva o pedido com UPDATE
 * condicional em orders.fiscal_auto_attempted_at e só então emite. Falha não reemite sozinha:
 * vai para o resumo do sino e para o Controle de notas. Timeout/5xx da Focus deixa a nota
 * 'pending' e a ReconcilePendingFiscalNotesJob confere depois.
 */
class AutoIssueFiscalNote implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Segura disparos repetidos (observer + varredura) enquanto este job não terminar. */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue('low');
    }

    public function uniqueId(): string
    {
        return 'order-'.$this->orderId;
    }

    public function handle(FiscalAutoIssuePolicy $policy, FiscalNoteService $service, FiscalAutoIssueMonitor $monitor): void
    {
        $order = Order::withoutGlobalScopes()->with(['company', 'items.product', 'branch'])->find($this->orderId);
        if (! $order) {
            return;
        }

        $verdict = $policy->evaluate($order);
        if (! $verdict->settles()) {
            return;
        }

        $claimed = Order::withoutGlobalScopes()
            ->whereKey($order->id)
            ->where('company_id', $order->company_id)
            ->whereNull('fiscal_auto_attempted_at')
            ->update(['fiscal_auto_attempted_at' => now()]);
        if (! $claimed) {
            return;
        }

        if ($verdict->action === FiscalAutoIssueVerdict::SKIP) {
            Log::channel('fiscal')->info('Emissão automática: pedido fica no Controle de notas', [
                'order_id' => $order->id,
                'company_id' => $order->company_id,
                'reason' => $verdict->reason,
            ]);

            return;
        }

        Log::channel('fiscal')->info('Emissão automática: NFC-e enviada', [
            'order_id' => $order->id,
            'company_id' => $order->company_id,
        ]);

        try {
            // Modelo conferido = NFC-e: se a política mudar entre a avaliação e o envio, a emissão para.
            $service->issue($order, null, null, '65', automatic: true);
        } catch (FiscalNoteAlreadyIssuedException) {
            // PDV, botão do pedido ou Controle emitiram no meio do caminho.
        } catch (\RuntimeException $e) {
            if (Order::withoutGlobalScopes()->whereKey($order->id)->first()?->isClosed()) {
                Log::channel('fiscal')->info('Emissão automática: pedido cancelado antes do envio', ['order_id' => $order->id]);

                return;
            }
            // Pendência (FiscalPreflightException) ou configuração: fica no Controle de notas.
            $monitor->orderFailed($order, $e->getMessage());
        } catch (\Throwable $e) {
            // Timeout/5xx: FiscalNoteService deixou a nota 'pending' para a reconciliação.
            Log::channel('fiscal')->error('Emissão automática: erro ao enviar', [
                'order_id' => $order->id,
                'company_id' => $order->company_id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
