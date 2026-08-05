<?php

namespace App\Http\Controllers\Admin\Pdv;

use App\Contracts\PrinterServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\BranchPrinter;
use App\Models\Order;

class PrintPayloadController extends Controller
{
    public function receipt(Order $order, ?string $station = null)
    {
        $this->authorizeAccess();

        $station = $station ?? 'geral';

        abort_unless(in_array($station, BranchPrinter::STATIONS, true), 404);

        $order->load(['items.product.category', 'customer', 'branch', 'payment', 'coupon']);

        $printer = $order->branch->printerForStation($station);

        abort_unless($printer && $printer->active, 404);

        $company = app()->bound('current.company') ? app('current.company') : null;

        $payload = app(PrinterServiceInterface::class)->buildOrderReceipt($order, $station, $company);

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
