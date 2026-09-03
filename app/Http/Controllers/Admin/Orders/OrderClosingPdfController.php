<?php

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Scopes\CompanyScope;
use App\Services\Order\OrderClosingReportService;
use App\Support\Printing\ThermalReceiptPaper;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OrderClosingPdfController extends Controller
{
    public function __invoke(Request $request, OrderClosingReportService $service)
    {
        $user = auth()->user();
        $isSuperAdmin = $user->isSuperAdmin();

        if ($isSuperAdmin) {
            $canView = true;
        } elseif (app()->bound('current.company')) {
            $canView = $user->canManageClosing(app('current.company'));
        } else {
            $canView = false;
        }

        abort_unless($canView, 403);

        $date = $request->date ? Carbon::parse($request->date) : now();
        $companyId = $isSuperAdmin ? ($request->integer('company_id') ?: null) : null;

        if ($isSuperAdmin) {
            $company = $companyId ? Company::withoutGlobalScope(CompanyScope::class)->find($companyId) : null;
        } else {
            $company = app()->bound('current.company') ? app('current.company') : null;
        }

        $report = $service->build($date, $isSuperAdmin, $companyId);

        $pdf = Pdf::loadView('livewire.admin.orders.closing-receipt', [
            'report' => $report,
            'company' => $company,
        ])->setPaper(ThermalReceiptPaper::forWidthMm(80));

        return $pdf->stream('fechamento-pedidos-'.$date->format('Y-m-d').'.pdf');
    }
}
