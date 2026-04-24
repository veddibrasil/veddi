<?php

namespace App\Services;

use App\Enums\Plan;
use App\Models\Company;

class FeeCalculator
{
    /**
     * Calculate the platform fee and net value for an order.
     * The fee is applied only on $productBase (subtotal - discount, excluding shipping).
     * The net_value is calculated from $orderTotal so the company receives the full order minus the fee.
     * The fee percentage is driven by the company's Plan configuration in config/plans.php.
     *
     * @return array{fee: float, net_value: float}
     */
    public function calculate(Company $company, float $productBase, float $orderTotal): array
    {
        $plan = $company->plan instanceof Plan ? $company->plan : Plan::Free;

        $feePercentage = $plan->feePercentage();

        if ($company->isFree() && ! $company->isWithinOrderLimit()) {
            $feePercentage = 0.03;
        }

        if ($feePercentage > 0) {
            $fee = round($productBase * $feePercentage, 2);
            $netValue = round($orderTotal - $fee, 2);
        } else {
            $fee = 0.0;
            $netValue = $orderTotal;
        }

        return ['fee' => $fee, 'net_value' => $netValue];
    }
}
