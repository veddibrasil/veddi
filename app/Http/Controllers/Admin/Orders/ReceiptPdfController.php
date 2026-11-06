<?php

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Printer\EscPosPrinterService;
use App\Support\Printing\ThermalReceiptPaper;

class ReceiptPdfController extends Controller
{
    public function __invoke(Order $order, ?string $station = null)
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            $canView = true;
        } elseif (app()->bound('current.company')) {
            $company = app('current.company');
            $canView = ($user->hasPermission('orders.view', $company)
                || $user->hasPermission('pdv.operate', $company))
                && $user->canAccessBranch($company, $order->branch_id);
        } else {
            $canView = false;
        }

        abort_unless($canView, 403);

        if ($station !== null) {
            abort_unless(in_array($station, ['cozinha', 'bar', 'entrega']), 404);
        }

        $order->load(['items.product.category', 'customer', 'branch', 'payment', 'coupon', 'deliveryAddressRecord', 'activeFiscalNote']);

        $company = app()->bound('current.company') ? app('current.company') : null;

        $paperWidth = $order->branch->receiptPaperWidth($station ?? 'geral');

        if ($station !== null) {
            // Cupom entrega não filtra por categoria: o entregador precisa ver todos os itens do pedido.
            $items = $station === 'entrega'
                ? $order->items
                : $order->items->filter(fn ($item) => $item->matchesStation($station))->values();

            // Mesmo rodapé de entrega/retirada do cupom térmico.
            $handoffFooter = EscPosPrinterService::handoffFooterLines($order, $station);

            $pdf = ThermalReceiptPaper::pdf('livewire.admin.orders.receipt-station', compact('order', 'company', 'station', 'items', 'handoffFooter'), $paperWidth);

            return $pdf->stream('cupom-'.$station.'-'.$order->order_number.'.pdf');
        }

        $pdf = ThermalReceiptPaper::pdf('livewire.admin.orders.receipt', compact('order', 'company'), $paperWidth);

        return $pdf->stream('cupom-'.$order->order_number.'.pdf');
    }
}
