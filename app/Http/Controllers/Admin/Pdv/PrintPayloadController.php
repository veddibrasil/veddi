<?php

namespace App\Http\Controllers\Admin\Pdv;

use App\Contracts\PrinterServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\BranchPrinter;
use App\Models\Company;
use App\Models\Order;
use App\Models\Scopes\CompanyScope;
use App\Services\Order\OrderClosingReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class PrintPayloadController extends Controller
{
    public function receipt(Request $request, Order $order, ?string $station = null)
    {
        $this->authorizeAccess();

        $station = $station ?? 'geral';

        abort_unless(in_array($station, BranchPrinter::STATIONS, true), 404);

        $order->load(['items.product.category', 'customer', 'branch', 'payment', 'coupon']);

        $printer = $order->branch->printerForStation($station);

        Log::channel('orders')->info('[auto-print] navegador pediu payload de impressão', [
            'order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'station' => $station,
            'full' => $request->boolean('full'),
            'printer_found' => (bool) $printer,
            'printer_active' => $printer?->active,
            'user_id' => auth()->id(),
        ]);

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
     * Fechamento do dia agrega pedidos da empresa inteira (todas as filiais),
     * então só há impressora inequívoca pra mandar sozinho quando a empresa
     * tem exatamente uma filial ativa — com mais de uma, cai no 404 e o
     * front abre a tela de impressão manual (fallback), igual ao cupom sem
     * impressora configurada.
     */
    public function closing(Request $request, OrderClosingReportService $service)
    {
        $this->authorizeAccess();

        $user = auth()->user();
        $isSuperAdmin = $user->isSuperAdmin();
        $companyId = $isSuperAdmin ? ($request->integer('company_id') ?: null) : null;

        if ($isSuperAdmin) {
            $company = $companyId ? Company::withoutGlobalScope(CompanyScope::class)->find($companyId) : null;
        } else {
            $company = app()->bound('current.company') ? app('current.company') : null;
        }

        abort_unless($company, 404);

        $branches = $company->branches()->where('active', true)->get();

        abort_unless($branches->count() === 1, 404);

        $printer = $branches->first()->printerForStation('geral');

        abort_unless($printer && $printer->active, 404);

        $date = $request->date ? Carbon::parse($request->date) : now();
        $report = $service->build($date, true, $company->id);

        $payload = app(PrinterServiceInterface::class)->buildClosingReceipt($report, $company);

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
