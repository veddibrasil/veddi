<?php

namespace App\Contracts;

use App\Models\Order;
use App\Models\Payment;

interface WalletServiceInterface
{
    public function creditForOrder(Order $order, Payment $payment): void;

    public function debitForRefund(Order $order, Payment $payment): void;
}
