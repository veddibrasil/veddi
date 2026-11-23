<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\Fiscal\FiscalAutoIssueDispatcher;

/**
 * Emissão automática de NFC-e e cancelamento da nota de pedido cancelado. Fica no modelo porque
 * aceite, pagamento e cancelamento chegam por muitos caminhos (painel, PDV, chat, webhooks, jobs);
 * o que escapa (UPDATE direto no banco) a varredura fiscal:auto-issue pega.
 */
class OrderFiscalObserver
{
    public function __construct(private readonly FiscalAutoIssueDispatcher $dispatcher) {}

    public function saved(Order $order): void
    {
        if ($order->wasChanged('status') && $order->isClosed()) {
            $this->dispatcher->orderClosed($order);

            return;
        }

        if ($order->wasRecentlyCreated || $order->wasChanged(['status', 'chat_accepted_at', 'is_open_tab', 'fiscal_auto_skip'])) {
            $this->dispatcher->consider($order);
        }
    }
}
