<?php

namespace App\Contracts;

use App\Models\CompanyTransaction;
use App\Models\Order;
use App\Models\Payment;

interface TransactionServiceInterface
{
    public function createForPayment(Order $order, Payment $payment): CompanyTransaction;
}
