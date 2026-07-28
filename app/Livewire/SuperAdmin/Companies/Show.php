<?php

namespace App\Livewire\SuperAdmin\Companies;

use App\Models\Company;
use App\Models\Order;
use App\Services\Finance\BalanceService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Show extends Component
{
    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public function render()
    {
        $company = $this->company;

        $ordersQuery = Order::query()->where('company_id', $company->id);
        $paidStatuses = ['paid', 'preparing', 'ready', 'out_for_delivery', 'delivered'];

        $totalOrders = (clone $ordersQuery)->count();

        $ordersByStatus = (clone $ordersQuery)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $paidOrdersQuery = (clone $ordersQuery)->whereIn('status', $paidStatuses);
        $paidOrdersCount = (clone $paidOrdersQuery)->count();
        $totalRevenue = (float) (clone $paidOrdersQuery)->sum('total');
        $totalNetValue = (float) (clone $paidOrdersQuery)->sum('net_value');
        $totalPlanFee = (float) (clone $paidOrdersQuery)->sum('fee');
        $avgTicket = $paidOrdersCount > 0 ? $totalRevenue / $paidOrdersCount : 0.0;

        // Margem da plataforma sobre PIX via Vindi (VINDI_PIX_PLATFORM_RATE), somada além
        // da taxa do plano — mesma regra da geração da cobrança (PaymentOrchestrator::processPix)
        // e da liquidação real (TransactionService::createForPayment).
        $vindiPixPlatformRate = (float) config('payments.vindi_pix_platform_rate', 0.0014);
        $vindiPixAmount = (float) DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.company_id', $company->id)
            ->whereIn('orders.status', $paidStatuses)
            ->where('payments.status', 'paid')
            ->where('payments.payment_gateway', 'vindi')
            ->whereNull('payments.original_amount')
            ->sum('payments.amount');
        $totalPixPlatformFee = round($vindiPixAmount * $vindiPixPlatformRate, 2);
        $totalPlatformFee = $totalPlanFee + $totalPixPlatformFee;

        $ordersThisMonth = (clone $ordersQuery)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $revenueThisMonth = (float) (clone $ordersQuery)
            ->whereIn('status', $paidStatuses)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('total');

        $monthlyHistory = (clone $ordersQuery)
            ->whereIn('status', $paidStatuses)
            ->where('created_at', '>=', now()->subMonths(5)->startOfMonth())
            ->get(['created_at', 'total'])
            ->groupBy(fn (Order $order) => $order->created_at->format('Y-m'))
            ->map(fn ($group, $month) => (object) [
                'month' => $month,
                'orders_count' => $group->count(),
                'revenue' => $group->sum('total'),
            ])
            ->sortKeys()
            ->values();

        $branchesCount = $company->branches()->count();
        $productsCount = $company->products()->count();

        $users = $company->users()->orderBy('name')->get();

        $balance = app(BalanceService::class)->calculateBalance($company);

        $subscription = $company->subscriptions()->latest('id')->first();

        return view('livewire.super-admin.companies.show', [
            'company' => $company,
            'totalOrders' => $totalOrders,
            'ordersByStatus' => $ordersByStatus,
            'paidOrdersCount' => $paidOrdersCount,
            'totalRevenue' => $totalRevenue,
            'totalNetValue' => $totalNetValue,
            'totalPlatformFee' => $totalPlatformFee,
            'totalPlanFee' => $totalPlanFee,
            'totalPixPlatformFee' => $totalPixPlatformFee,
            'avgTicket' => $avgTicket,
            'ordersThisMonth' => $ordersThisMonth,
            'revenueThisMonth' => $revenueThisMonth,
            'monthlyHistory' => $monthlyHistory,
            'branchesCount' => $branchesCount,
            'productsCount' => $productsCount,
            'users' => $users,
            'balance' => $balance,
            'subscription' => $subscription,
        ])->layout('layouts.app', ['title' => 'Super Admin — '.$company->name]);
    }
}
