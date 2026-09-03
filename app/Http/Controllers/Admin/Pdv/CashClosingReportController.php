<?php

namespace App\Http\Controllers\Admin\Pdv;

use App\Http\Controllers\Controller;
use App\Models\PdvCashSession;
use App\Services\Pdv\CashClosingReportService;
use App\Support\Printing\ThermalReceiptPaper;
use Barryvdh\DomPDF\Facade\Pdf;

class CashClosingReportController extends Controller
{
    public function __invoke(PdvCashSession $cashSession, CashClosingReportService $service)
    {
        $user = auth()->user();
        $company = app()->bound('current.company') ? app('current.company') : null;

        abort_unless($company, 403);
        abort_unless($company->pdv_module_enabled, 403, 'Módulo PDV não está habilitado para esta empresa.');
        abort_unless($user?->hasPermission('pdv.operate', $company), 403);
        abort_unless($user->roleForCompany($company) !== 'caixa', 403);
        abort_unless($cashSession->closed_at, 404);

        $report = $service->build($cashSession);

        $paperWidth = $cashSession->branch->printerForStation('geral')?->paper_width ?? 80;

        $pdf = Pdf::loadView('livewire.admin.pdv.cash-closing-receipt', [
            'report' => $report,
            'company' => $company,
        ])->setPaper(ThermalReceiptPaper::forWidthMm($paperWidth));

        return $pdf->stream('fechamento-caixa-'.$cashSession->id.'.pdf');
    }
}
