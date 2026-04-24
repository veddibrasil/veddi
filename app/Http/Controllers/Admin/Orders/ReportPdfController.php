<?php

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Scopes\CompanyScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ReportPdfController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = auth()->user();

        if ($user->isSuperAdmin()) {
            $canView = true;
        } elseif (app()->bound('current.company')) {
            $canView = $user->hasPermission('orders.view', app('current.company'));
        } else {
            $canView = false;
        }

        abort_unless($canView, 403);

        $isSuperAdmin = $user->isSuperAdmin();

        $query = $isSuperAdmin
            ? Order::withoutGlobalScope(CompanyScope::class)->with(['customer', 'branch'])
            : Order::with(['customer', 'branch']);

        $orders = $query
            ->when($request->date_start, fn ($q) => $q->whereDate('created_at', '>=', $request->date_start))
            ->when($request->date_end, fn ($q) => $q->whereDate('created_at', '<=', $request->date_end))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->branch_id, fn ($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->payment_method, fn ($q) => $q->where('payment_method', $request->payment_method))
            ->when($request->order_type, fn ($q) => $q->where('order_type', $request->order_type))
            ->latest()
            ->get();

        $company = app()->bound('current.company') ? app('current.company') : null;

        $pdf = Pdf::loadView('livewire.admin.orders.report-pdf', compact('orders', 'company'))
            ->setPaper('a4', 'landscape');

        return $pdf->download('relatorio-pedidos-'.now()->format('Y-m-d').'.pdf');
    }
}
