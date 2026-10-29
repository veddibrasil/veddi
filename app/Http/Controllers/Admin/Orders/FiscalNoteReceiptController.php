<?php

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Printing\ThermalReceiptPaper;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * DANFE da NFC-e em PDF de bobina (mesmo esquema do cupom) — é o que o botão "Imprimir"
 * manda pra impressão do navegador quando a filial não tem térmica pelo QZ Tray.
 */
class FiscalNoteReceiptController extends Controller
{
    public function __invoke(Order $order)
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

        $order->load(['items', 'branch.printers', 'activeFiscalNote']);

        $note = $order->activeFiscalNote;

        abort_unless($note && $note->status === 'authorized' && $note->model !== '55', 404);

        $company = app()->bound('current.company') ? app('current.company') : null;

        // QR oficial da SEFAZ (qrcode_url); sem ele, a chave de acesso.
        $qrData = $note->raw_response['qrcode_url'] ?? preg_replace('/\D/', '', (string) $note->access_key);
        $qrCode = $qrData
            ? 'data:image/svg+xml;base64,'.base64_encode((new Writer(new ImageRenderer(new RendererStyle(240, 0), new SvgImageBackEnd)))->writeString($qrData))
            : null;

        $printer = $order->branch->printers->firstWhere('print_fiscal_note', true) ?? $order->branch->printerForStation('geral');

        $pdf = ThermalReceiptPaper::pdf('livewire.admin.orders.receipt-fiscal-note', compact('order', 'note', 'company', 'qrCode'), $printer?->paper_width ?? 80);

        return $pdf->stream('nfce-'.$order->order_number.'.pdf');
    }
}
