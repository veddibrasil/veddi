<?php

namespace App\Livewire\SuperAdmin;

use App\Enums\Plan;
use App\Models\Company;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $data = Cache::remember('superadmin:dashboard', now()->addMinutes(5), function () {
            $totalCompanies = Company::count();
            $activeCompanies = Company::where('active', true)->count();
            $pendingPaymentCompanies = Company::where('status', 'PENDING_PAYMENT')->count();
            $blockedCompanies = Company::where('status', 'BLOCKED')->count();

            $newCompaniesThisMonth = Company::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();

            $companiesByPlan = Company::selectRaw('plan, count(*) as total')
                ->groupBy('plan')
                ->pluck('total', 'plan');

            $mrrPlans = 0.0;
            foreach (Plan::cases() as $plan) {
                if (! $plan->hasMonthlySubscription()) {
                    continue;
                }
                $activeOnPlan = Company::where('plan', $plan->value)->where('active', true)->count();
                $mrrPlans += $activeOnPlan * $plan->monthlyPrice();
            }

            $activeWithFiscal = Company::where('active', true)->where('fiscal_notes_enabled', true)->count();
            $mrrFiscalAddon = $activeWithFiscal * (float) config('fiscal.addon_monthly_price');

            $activeWithPdv = Company::where('active', true)->where('pdv_module_enabled', true)->count();
            $mrrPdvAddon = $activeWithPdv * (float) config('pdv.addon_monthly_price');

            $activeWithWaiter = Company::where('active', true)->where('waiter_module_enabled', true)->count();
            $mrrWaiterAddon = $activeWithWaiter * (float) config('waiter.addon_monthly_price');

            $mrr = $mrrPlans + $mrrFiscalAddon + $mrrPdvAddon + $mrrWaiterAddon;

            $paidStatuses = ['paid', 'preparing', 'ready', 'delivered'];

            $totalOrders = Order::count();
            $ordersToday = Order::whereDate('created_at', today())->count();
            $ordersThisMonth = Order::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();

            $totalRevenue = (float) Order::whereIn('status', $paidStatuses)->sum('total');
            $revenueThisMonth = (float) Order::whereIn('status', $paidStatuses)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('total');
            $planFeeThisMonth = (float) Order::whereIn('status', $paidStatuses)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('fee');
            $totalPlanFee = (float) Order::whereIn('status', $paidStatuses)->sum('fee');

            // Margem da plataforma sobre PIX via Vindi (VINDI_PIX_PLATFORM_RATE), somada além
            // da taxa do plano — mesma regra usada na geração da cobrança (PaymentOrchestrator::processPix)
            // e na liquidação real (TransactionService::createForPayment). Identifica-se um PIX real
            // via Vindi pelo gateway 'vindi' + original_amount nulo (cartão sempre grava original_amount).
            $vindiPixPlatformRate = (float) config('payments.vindi_pix_platform_rate', 0.0014);

            $vindiPixAmountBase = fn () => DB::table('payments')
                ->join('orders', 'orders.id', '=', 'payments.order_id')
                ->whereIn('orders.status', $paidStatuses)
                ->where('payments.status', 'paid')
                ->where('payments.payment_gateway', 'vindi')
                ->whereNull('payments.original_amount');

            $vindiPixAmountThisMonth = (float) $vindiPixAmountBase()
                ->whereMonth('orders.created_at', now()->month)
                ->whereYear('orders.created_at', now()->year)
                ->sum('payments.amount');
            $vindiPixAmountTotal = (float) $vindiPixAmountBase()->sum('payments.amount');

            $pixPlatformFeeThisMonth = round($vindiPixAmountThisMonth * $vindiPixPlatformRate, 2);
            $totalPixPlatformFee = round($vindiPixAmountTotal * $vindiPixPlatformRate, 2);

            $platformFeeThisMonth = $planFeeThisMonth + $pixPlatformFeeThisMonth;
            $totalPlatformFee = $totalPlanFee + $totalPixPlatformFee;

            $topCompanies = Company::query()
                ->withSum(['orders as revenue_this_month' => function ($query) use ($paidStatuses) {
                    $query->whereIn('status', $paidStatuses)
                        ->whereMonth('created_at', now()->month)
                        ->whereYear('created_at', now()->year);
                }], 'total')
                ->orderByDesc('revenue_this_month')
                ->take(5)
                ->get(['id', 'name', 'slug', 'plan'])
                ->filter(fn ($company) => $company->revenue_this_month > 0)
                ->values();

            return compact(
                'totalCompanies', 'activeCompanies', 'pendingPaymentCompanies', 'blockedCompanies',
                'newCompaniesThisMonth', 'companiesByPlan', 'mrr', 'mrrPlans', 'mrrFiscalAddon', 'mrrPdvAddon', 'mrrWaiterAddon',
                'totalOrders', 'ordersToday', 'ordersThisMonth',
                'totalRevenue', 'revenueThisMonth', 'platformFeeThisMonth', 'totalPlatformFee',
                'planFeeThisMonth', 'totalPlanFee', 'pixPlatformFeeThisMonth', 'totalPixPlatformFee',
                'topCompanies'
            );
        });

        return view('livewire.super-admin.dashboard', $data)
            ->layout('layouts.app', ['title' => 'Super Admin — Dashboard']);
    }
}
