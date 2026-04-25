<?php

namespace App\Livewire\Admin\Orders;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Scopes\CompanyScope;
use Livewire\Component;
use Livewire\WithPagination;

class Report extends Component
{
    use WithPagination;

    public string $dateStart = '';

    public string $dateEnd = '';

    public string $statusFilter = '';

    public string $branchFilter = '';

    public string $paymentMethod = '';

    public string $orderType = '';

    public bool $isSuperAdmin = false;

    public bool $canView = false;

    public function mount(): void
    {
        $user = auth()->user();
        $this->isSuperAdmin = $user->isSuperAdmin();

        if ($this->isSuperAdmin) {
            $this->canView = true;
        } elseif (app()->bound('current.company')) {
            $company = app('current.company');
            $this->canView = $user->hasPermission('orders.view', $company);
        }

        abort_unless($this->canView, 403);

        $this->dateStart = now()->startOfMonth()->format('Y-m-d');
        $this->dateEnd = now()->endOfMonth()->format('Y-m-d');
    }

    public function updatingDateStart(): void
    {
        $this->resetPage();
    }

    public function updatingDateEnd(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingBranchFilter(): void
    {
        $this->resetPage();
    }

    public function updatingPaymentMethod(): void
    {
        $this->resetPage();
    }

    public function updatingOrderType(): void
    {
        $this->resetPage();
    }

    private function buildQuery()
    {
        $query = $this->isSuperAdmin
            ? Order::withoutGlobalScope(CompanyScope::class)->with(['customer', 'branch'])
            : Order::with(['customer', 'branch']);

        return $query
            ->when($this->dateStart, fn ($q) => $q->whereDate('created_at', '>=', $this->dateStart))
            ->when($this->dateEnd, fn ($q) => $q->whereDate('created_at', '<=', $this->dateEnd))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->branchFilter, fn ($q) => $q->where('branch_id', $this->branchFilter))
            ->when($this->paymentMethod, fn ($q) => $q->where('payment_method', $this->paymentMethod))
            ->when($this->orderType, fn ($q) => $q->where('order_type', $this->orderType));
    }

    public function exportCsv()
    {
        abort_unless($this->canView, 403);

        $orders = $this->buildQuery()->latest()->get();
        $filename = 'relatorio-pedidos-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($orders) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

            fputcsv($handle, [
                'Número', 'Cliente', 'Filial', 'Data',
                'Forma Pagamento', 'Tipo', 'Status', 'Total (R$)',
            ], ';');

            foreach ($orders as $order) {
                fputcsv($handle, [
                    $order->order_number,
                    $order->customer->name ?? '—',
                    $order->branch->name ?? '—',
                    $order->created_at->format('d/m/Y H:i'),
                    $this->paymentMethodLabel($order->payment_method),
                    $order->order_type === 'delivery' ? 'Entrega' : 'Retirada',
                    $order->status_label,
                    number_format($order->total, 2, ',', '.'),
                ], ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function paymentMethodLabel(string $method): string
    {
        return match (strtolower($method)) {
            'pix' => 'PIX',
            'card' => 'Cartão',
            'cash' => 'Dinheiro',
            default => $method,
        };
    }

    public function render()
    {
        $baseQuery = $this->buildQuery();

        $allOrders = (clone $baseQuery)->get(['total', 'payment_method', 'status']);

        $totalCount = $allOrders->count();
        $totalRevenue = $allOrders->whereNotIn('status', ['cancelled', 'refunded'])->sum('total');
        $averageTicket = $totalCount > 0 ? $totalRevenue / $totalCount : 0;
        $topPayment = $allOrders->isNotEmpty()
            ? $allOrders->groupBy('payment_method')->map->count()->sortDesc()->keys()->first()
            : null;
        $topPaymentLabel = $topPayment ? $this->paymentMethodLabel($topPayment) : '—';

        $orders = (clone $baseQuery)->latest()->paginate(25);

        $branches = $this->isSuperAdmin
            ? Branch::withoutGlobalScope(CompanyScope::class)->orderBy('name')->get(['id', 'name'])
            : Branch::orderBy('name')->get(['id', 'name']);

        return view('livewire.admin.orders.report', compact(
            'orders', 'branches',
            'totalCount', 'totalRevenue', 'averageTicket', 'topPaymentLabel'
        ))->layout('layouts.app', ['title' => 'Relatório de Pedidos']);
    }
}
