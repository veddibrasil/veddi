<?php

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;

class ReceiptPdfController extends Controller
{
    public function __invoke(Order $order)
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

        $order->load(['items', 'customer', 'branch', 'payment', 'coupon']);

        $company = app()->bound('current.company') ? app('current.company') : null;

        $pdf = Pdf::loadView('livewire.admin.orders.receipt', compact('order', 'company'))
            ->setPaper('a6', 'portrait');

        return $pdf->stream('cupom-'.$order->order_number.'.pdf');
    }
}
