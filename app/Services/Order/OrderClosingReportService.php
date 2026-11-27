<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\Scopes\CompanyScope;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class OrderClosingReportService
{
    public function build(CarbonInterface $date, bool $withoutCompanyScope, ?int $companyId): array
    {
        $base = $withoutCompanyScope
            ? Order::withoutGlobalScope(CompanyScope::class)
            : Order::query();

        $base = $base
            ->whereDate('created_at', $date)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId));

        return [
            'date' => $date,
            'delivery' => $this->summarize((clone $base)->where('order_type', 'delivery')),
            'pdv' => $this->summarize((clone $base)->where('order_type', 'pdv')),
            'geral' => $this->summarize(clone $base),
        ];
    }

    private function summarize($query): array
    {
        // Consumo de funcionário não é venda: sai do faturamento e vira linha própria.
        $staff = (clone $query)->whereNotNull('staff_user_id')->whereNotIn('status', ['cancelled', 'refunded'])->get(['total']);
        $orders = $query->excludingStaffConsumption()->get(['total', 'status', 'payment_method', 'discount']);

        // Faturamento só de pedidos confirmados; pendente/aguardando pagamento fica numa linha própria.
        $valid = $orders->whereIn('status', Order::CONFIRMED_STATUSES);
        $cancelled = $orders->whereIn('status', ['cancelled', 'refunded']);

        return [
            'count' => $valid->count(),
            'cancelled_count' => $cancelled->count(),
            'pending_count' => $orders->count() - $valid->count() - $cancelled->count(),
            'revenue' => (float) $valid->sum('total'),
            'discounts' => (float) $valid->sum('discount'),
            'payments' => $this->paymentBreakdown($valid),
            'staff' => ['count' => $staff->count(), 'total' => round((float) $staff->sum('total'), 2)],
        ];
    }

    private function paymentBreakdown(Collection $orders): array
    {
        // 'split' (PDV dividido) e formas fora da lista (ex.: iFood) ficam em linhas próprias
        // para a soma das formas bater com o TOTAL.
        $totals = ['cash' => 0.0, 'pix' => 0.0, 'card' => 0.0, 'split' => 0.0, 'other' => 0.0];

        foreach ($orders as $order) {
            $method = strtolower((string) $order->payment_method);
            $method = $method === 'credit_card' ? 'card' : $method;

            $totals[array_key_exists($method, $totals) ? $method : 'other'] += (float) $order->total;
        }

        return $totals;
    }
}
