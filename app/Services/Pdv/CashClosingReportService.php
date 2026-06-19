<?php

namespace App\Services\Pdv;

use App\Models\PdvCashSession;
use Illuminate\Support\Facades\DB;

class CashClosingReportService
{
    public function build(PdvCashSession $session): array
    {
        $session->loadMissing(['branch', 'user']);

        $breakdown = $this->breakdown($session);
        $orderStats = $this->orderStats($session->id);
        $payments = $this->paymentTotals($session->id);

        return [
            'session' => $session,
            'opening' => $breakdown['opening'],
            'closing' => (float) $session->closing_amount,
            'expected' => $breakdown['expected'],
            'difference' => round((float) $session->closing_amount - $breakdown['expected'], 2),
            'cash_sales' => $breakdown['cash_sales'],
            'supplies' => $breakdown['supplies'],
            'withdrawals' => $breakdown['withdrawals'],
            'movements' => $this->movements($session->id),
            'payments' => $payments,
            'revenue' => $orderStats['revenue'],
            'orders_count' => $orderStats['orders'],
            'cancelled_count' => $orderStats['cancelled'],
            'discounts' => $orderStats['discounts'],
            'top_products' => $this->topProducts($session->id),
        ];
    }

    private function breakdown(PdvCashSession $session): array
    {
        $cashSales = (float) DB::table('orders')
            ->join('payments', 'payments.order_id', '=', 'orders.id')
            ->where('orders.pdv_cash_session_id', $session->id)
            ->where('orders.payment_method', 'cash')
            ->where('payments.status', 'paid')
            ->sum('payments.amount');

        $movements = DB::table('pdv_audit_logs')
            ->where('pdv_cash_session_id', $session->id)
            ->whereIn('action', ['cash_supply', 'cash_withdrawal'])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN action = 'cash_supply' THEN amount ELSE 0 END), 0) as supplies,
                COALESCE(SUM(CASE WHEN action = 'cash_withdrawal' THEN amount ELSE 0 END), 0) as withdrawals
            ")
            ->first();

        $supplies = (float) ($movements->supplies ?? 0.0);
        $withdrawals = (float) ($movements->withdrawals ?? 0.0);

        return [
            'opening' => (float) $session->opening_amount,
            'cash_sales' => $cashSales,
            'supplies' => $supplies,
            'withdrawals' => $withdrawals,
            'expected' => round((float) $session->opening_amount + $cashSales + $supplies - $withdrawals, 2),
        ];
    }

    private function movements(int $sessionId): \Illuminate\Support\Collection
    {
        return DB::table('pdv_audit_logs')
            ->where('pdv_cash_session_id', $sessionId)
            ->whereIn('action', ['cash_supply', 'cash_withdrawal'])
            ->orderBy('created_at')
            ->get(['action', 'amount', 'reason', 'created_at']);
    }

    private function paymentTotals(int $sessionId): array
    {
        $rows = DB::table('orders')
            ->where('pdv_cash_session_id', $sessionId)
            ->where('is_open_tab', false)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->selectRaw('payment_method, COUNT(*) as orders_count, COALESCE(SUM(total), 0) as total')
            ->groupBy('payment_method')
            ->get();

        $totals = ['cash' => 0.0, 'pix' => 0.0, 'credit_card' => 0.0];

        foreach ($rows as $row) {
            $totals[$row->payment_method] = (float) $row->total;
        }

        return $totals;
    }

    private function orderStats(int $sessionId): array
    {
        $stats = DB::table('orders')
            ->where('pdv_cash_session_id', $sessionId)
            ->where('is_open_tab', false)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->selectRaw('
                COUNT(*) as total_orders,
                COALESCE(SUM(total), 0) as total_revenue,
                COALESCE(SUM(discount + manual_discount), 0) as total_discounts
            ')
            ->first();

        $cancelled = DB::table('orders')
            ->where('pdv_cash_session_id', $sessionId)
            ->where('is_open_tab', false)
            ->whereIn('status', ['cancelled', 'refunded'])
            ->count();

        return [
            'orders' => (int) ($stats->total_orders ?? 0),
            'revenue' => (float) ($stats->total_revenue ?? 0.0),
            'discounts' => (float) ($stats->total_discounts ?? 0.0),
            'cancelled' => $cancelled,
        ];
    }

    private function topProducts(int $sessionId, int $limit = 5): \Illuminate\Support\Collection
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.pdv_cash_session_id', $sessionId)
            ->where('orders.is_open_tab', false)
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->selectRaw('order_items.product_name, SUM(order_items.quantity) as total_qty')
            ->groupBy('order_items.product_name')
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get();
    }
}
