<?php

namespace App\Services\Finance;

use App\Models\PlatformYieldSnapshot;

class YieldTrackingService
{
    public function __construct(private BacenService $bacen) {}

    public function recordSnapshot(float $vindiBalance, float $asaasBalance): PlatformYieldSnapshot
    {
        $today = now()->toDateString();

        $existing = PlatformYieldSnapshot::whereDate('snapshot_date', $today)->first();
        if ($existing) {
            return $existing;
        }

        $dailyRate = $this->bacen->getDailyCdiRate();
        $annualRate = (pow(1 + $dailyRate, 252) - 1) * 100;
        $totalFloat = round($vindiBalance + $asaasBalance, 2);
        $yieldAmount = round($totalFloat * $dailyRate, 2);

        $year = now()->year;
        $month = now()->month;

        $monthlyAccumulated = PlatformYieldSnapshot::forMonth($year, $month)->sum('yield_amount');
        $totalAccumulated = PlatformYieldSnapshot::sum('yield_amount');

        return PlatformYieldSnapshot::create([
            'snapshot_date' => $today,
            'vindi_balance' => round($vindiBalance, 2),
            'asaas_balance' => round($asaasBalance, 2),
            'total_float' => $totalFloat,
            'cdi_rate_annual' => round($annualRate, 4),
            'cdi_rate_daily' => $dailyRate,
            'yield_amount' => $yieldAmount,
            'cumulative_yield_month' => round($monthlyAccumulated + $yieldAmount, 2),
            'cumulative_yield_total' => round($totalAccumulated + $yieldAmount, 2),
        ]);
    }

    public function monthlyYield(int $year, int $month): float
    {
        return (float) PlatformYieldSnapshot::forMonth($year, $month)->sum('yield_amount');
    }
}
