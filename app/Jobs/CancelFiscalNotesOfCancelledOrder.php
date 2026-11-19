<?php

namespace App\Jobs;

use App\Models\CompanyNotification;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Services\Fiscal\FiscalNoteService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pedido cancelado ou estornado com nota ativa: cancela a nota na SEFAZ (vale para nota
 * automática e manual). Nota ainda em processamento é consultada e o job volta mais tarde.
 * Se o cancelamento não sair (prazo da UF vencido, rejeição), avisa no sino e o pedido aparece
 * em "Pedido cancelado com nota" no Controle de notas, para a contabilidade decidir (devolução).
 */
class CancelFiscalNotesOfCancelledOrder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const JUSTIFICATION = 'Venda cancelada no sistema de pedidos do estabelecimento.';

    public int $tries = 6;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'order-'.$this->orderId;
    }

    public function handle(FiscalNoteService $service): void
    {
        $order = Order::withoutGlobalScopes()->with('company')->find($this->orderId);
        if (! $order || ! $order->isClosed()) {
            return;
        }

        $notes = FiscalNote::withoutGlobalScopes()
            ->where('company_id', $order->company_id)
            ->where('order_id', $order->id)
            ->whereIn('status', ['pending', 'authorized'])
            ->get();

        foreach ($notes as $note) {
            if ($note->status === 'pending') {
                try {
                    $note = $service->reconcile($note);
                } catch (\Throwable $e) {
                    Log::channel('fiscal')->warning('Cancelamento automático: consulta da nota falhou', [
                        'fiscal_note_id' => $note->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($note->status === 'pending') {
                    if ($this->attempts() < $this->tries) {
                        $this->release(120);
                    } else {
                        $this->warn($order, $note, 'a nota ainda está em processamento na SEFAZ.');
                    }

                    return;
                }
            }

            if ($note->status !== 'authorized') {
                continue;
            }

            try {
                $service->cancel($note, self::JUSTIFICATION);
            } catch (\Throwable $e) {
                Log::channel('fiscal')->error('Cancelamento automático: erro ao cancelar a nota', [
                    'fiscal_note_id' => $note->id,
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn($order, $note, $e->getMessage());

                continue;
            }

            $note->refresh();
            if ($note->status === 'cancelled') {
                Log::channel('fiscal')->info('Cancelamento automático: nota cancelada com o pedido', [
                    'fiscal_note_id' => $note->id,
                    'order_id' => $order->id,
                ]);

                continue;
            }

            $error = $note->events()->where('type', 'cancellation')->latest('id')->first()?->data['error'] ?? null;
            $this->warn($order, $note, $error ?: 'o provedor não confirmou o cancelamento.');
        }
    }

    private function warn(Order $order, FiscalNote $note, string $reason): void
    {
        Log::channel('fiscal')->warning('Pedido cancelado com nota fiscal ativa', [
            'order_id' => $order->id,
            'fiscal_note_id' => $note->id,
            'status' => $note->status,
        ]);

        CompanyNotification::create([
            'company_id' => $order->company_id,
            'type' => 'fiscal_note_failed',
            'title' => 'Pedido '.$order->order_number.' cancelado com nota fiscal ativa',
            'subtitle' => Str::limit('A nota não foi cancelada: '.$reason.' Cancele em Notas fiscais ou combine a devolução com a contabilidade.', 250),
            // O Controle abre no dia de hoje: leva a data e o número do pedido.
            'link' => route('admin.fiscal.control', [
                'situationFilter' => 'cancelled_order',
                'dateFrom' => $order->created_at?->toDateString(),
                'dateTo' => now()->toDateString(),
                'search' => $order->order_number,
            ]),
        ]);
    }
}
