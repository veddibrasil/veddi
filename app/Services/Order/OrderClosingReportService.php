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
        $orders = $query->get(['total', 'status', 'payment_method', 'discount']);

        $valid = $orders->whereNotIn('status', ['cancelled', 'refunded']);

        return [
            'count' => $orders->count(),
            'cancelled_count' => $orders->count() - $valid->count(),
            'revenue' => (float) $valid->sum('total'),
            'discounts' => (float) $valid->sum('discount'),
            'payments' => $this->paymentBreakdown($valid),
        ];
    }

    private function paymentBreakdown(Collection $orders): array
    {
        $totals = ['cash' => 0.0, 'pix' => 0.0, 'card' => 0.0];

        foreach ($orders as $order) {
            $method = $order->payment_method === 'credit_card' ? 'card' : $order->payment_method;

            if (array_key_exists($method, $totals)) {
                $totals[$method] += (float) $order->total;
            }
        }

        return $totals;
    }
}
