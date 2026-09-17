<?php

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Scopes\CompanyScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportPdfController extends Controller
{
    private const MAX_RANGE_DAYS = 92;

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

        // Sem intervalo obrigatório e limitado, um super admin sem filtro de
        // data carregava TODOS os pedidos de TODAS as empresas da plataforma
        // de uma vez pra montar o PDF.
        $validated = $request->validate([
            'date_start' => ['required', 'date'],
            'date_end' => ['required', 'date', 'after_or_equal:date_start'],
        ]);

        $start = Carbon::parse($validated['date_start']);
        $end = Carbon::parse($validated['date_end']);

        abort_if(
            $start->diffInDays($end) > self::MAX_RANGE_DAYS,
            422,
            'Intervalo máximo para exportação é de '.self::MAX_RANGE_DAYS.' dias.'
        );

        $isSuperAdmin = $user->isSuperAdmin();

        $query = $isSuperAdmin
            ? Order::withoutGlobalScope(CompanyScope::class)->with(['customer', 'branch'])
            : Order::with(['customer', 'branch']);

        $orders = $query
            ->whereDate('created_at', '>=', $validated['date_start'])
            ->whereDate('created_at', '<=', $validated['date_end'])
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
