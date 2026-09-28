<?php

namespace App\Jobs;

use App\Exceptions\FiscalNoteAlreadyIssuedException;
use App\Models\CompanyNotification;
use App\Models\FiscalNote;
use App\Models\Order;
use App\Services\Fiscal\FiscalNoteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class IssueFiscalNote implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $orderId,
        public readonly ?string $customerDocument = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(FiscalNoteService $service): void
    {
        Log::channel('fiscal')->info('IssueFiscalNote: job iniciado', [
            'order_id' => $this->orderId,
            'attempt' => $this->attempts(),
        ]);

        $order = Order::withoutGlobalScopes()->with(['company', 'items.product', 'branch'])->find($this->orderId);

        if (! $order) {
            Log::channel('fiscal')->warning('IssueFiscalNote: pedido não encontrado', [
                'order_id' => $this->orderId,
            ]);

            return;
        }

        try {
            $service->issue($order, $this->customerDocument);
        } catch (FiscalNoteAlreadyIssuedException $e) {
            Log::channel('fiscal')->info('IssueFiscalNote: emissão ignorada, nota já ativa', [
                'order_id' => $this->orderId,
                'reason' => $e->getMessage(),
            ]);
        } catch (\RuntimeException $e) {
            // Falha de configuração/negócio (módulo não contratado, filial sem config,
            // config desabilitada, sem token) — não é transitória, então retentar só
            // desperdiça as 3 tentativas (~7min de backoff) até notificar o lojista.
            Log::channel('fiscal')->warning('IssueFiscalNote: falha de configuração, sem retry', [
                'order_id' => $this->orderId,
                'reason' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('fiscal')->error('IssueFiscalNote: falha definitiva', [
            'order_id' => $this->orderId,
            'error' => $exception->getMessage(),
        ]);

        FiscalNote::where('order_id', $this->orderId)
            ->where('status', 'pending')
            ->update(['status' => 'error', 'data->error_message' => $exception->getMessage()]);

        $companyId = Order::withoutGlobalScopes()->find($this->orderId)?->company_id;

        if ($companyId) {
            CompanyNotification::create([
                'company_id' => $companyId,
                'type' => 'fiscal_note_failed',
                'title' => 'Falha ao emitir nota fiscal',
                'subtitle' => $exception->getMessage(),
                'link' => route('admin.orders.show', $this->orderId),
            ]);
        }
    }
}
