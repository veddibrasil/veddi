<?php

namespace App\Http\Controllers\Admin\Pdv;

use App\Contracts\PrinterServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\BranchPrinter;
use App\Models\Order;
use Illuminate\Http\Request;

class PrintPayloadController extends Controller
{
    public function receipt(Request $request, Order $order, ?string $station = null)
    {
        $this->authorizeAccess();

        $station = $station ?? 'geral';

        abort_unless(in_array($station, BranchPrinter::STATIONS, true), 404);

        $order->load(['items.product.category', 'customer', 'branch', 'payment', 'coupon']);

        $printer = $order->branch->printerForStation($station);

        abort_unless($printer && $printer->active, 404);

        $company = app()->bound('current.company') ? app('current.company') : null;

        // ?full=1: via completa do "Finalizar Pedido" da mesa — ignora o filtro por
        // categoria da estação (cozinha/bar recebem o pedido inteiro, não só os itens
        // deles), porque a mesma via também serve de guia de entrega pro garçom.
        $payload = app(PrinterServiceInterface::class)->buildOrderReceipt($order, $station, $company, $request->boolean('full'));

        return response()->json([
            'printer' => $this->printerPayload($printer),
            'payload' => base64_encode($payload),
        ]);
    }

    /**
     * Cupom de item novo lançado na comanda (auto-print, sem "Finalizar Pedido"). Os
     * ids/quantidades vêm prontos do broadcast TabItemsReadyForProduction — já
     * decididos atomicamente no momento do lançamento (ver
     * HasOpenTabs::notifyPendingProductionItems), não recalculados aqui. Isso só
     * monta o cupom pros itens pedidos, validando contra os itens reais do pedido
     * (ids e quantidade máxima) pra não confiar cegamente no que vier na URL.
     */
    public function pendingItems(Request $request, Order $order, string $station)
    {
        $this->authorizeAccess();

        abort_unless(in_array($station, ['cozinha', 'bar'], true), 404);

        $order->load(['items.product.category', 'branch']);

        $printer = $order->branch->printerForStation($station);

        abort_unless($printer && $printer->active, 404);

        $requested = collect($request->query('items', []))
            ->mapWithKeys(fn ($qty, $id) => [(int) $id => (int) $qty])
            ->filter(fn ($qty) => $qty > 0);

        abort_if($requested->isEmpty(), 404);

        $items = $order->items
            ->whereIn('id', $requested->keys())
            ->map(function ($item) use ($requested) {
                $copy = $item->replicate();
                $copy->quantity = min($requested->get($item->id), $item->quantity);

                return $copy;
            })
            ->filter(fn ($item) => $item->quantity > 0)
            ->values();

        abort_if($items->isEmpty(), 404);

        $company = app()->bound('current.company') ? app('current.company') : null;

        $payload = app(PrinterServiceInterface::class)->buildOrderReceipt($order, $station, $company, itemsOverride: $items);

        return response()->json([
            'printer' => $this->printerPayload($printer),
            'payload' => base64_encode($payload),
        ]);
    }

    public function fiscalNote(Order $order)
    {
        $this->authorizeAccess();

        $order->load('branch.printers', 'items', 'activeFiscalNote');

        $note = $order->activeFiscalNote;

        abort_unless($note && $note->status === 'authorized', 404);

        $printer = $order->branch->printers->firstWhere('print_fiscal_note', true);

        abort_unless($printer && $printer->active, 404);

        $company = app()->bound('current.company') ? app('current.company') : null;

        $payload = app(PrinterServiceInterface::class)->buildFiscalNoteReceipt($note, $order, $company);

        return response()->json([
            'printer' => $this->printerPayload($printer),
            'payload' => base64_encode($payload),
        ]);
    }

    /**
     * @return array{connection_type: string, ip: ?string, port: ?int, name: ?string}
     */
    private function printerPayload(BranchPrinter $printer): array
    {
        return [
            'connection_type' => $printer->connection_type,
            'ip' => $printer->ip_address,
            'port' => $printer->port,
            'name' => $printer->printer_name,
        ];
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            return;
        }

        abort_unless(app()->bound('current.company'), 403);

        $company = app('current.company');
        $canView = $user->hasPermission('orders.view', $company) || $user->hasPermission('pdv.operate', $company);

        abort_unless($canView, 403);
    }
}
