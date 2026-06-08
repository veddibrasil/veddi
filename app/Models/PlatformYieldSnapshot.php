<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PlatformYieldSnapshot extends Model
{
    protected $fillable = [
        'snapshot_date',
        'vindi_balance',
        'asaas_balance',
        'total_float',
        'cdi_rate_annual',
        'cdi_rate_daily',
        'yield_amount',
        'cumulative_yield_month',
        'cumulative_yield_total',
        'metadata',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'vindi_balance' => 'decimal:2',
        'asaas_balance' => 'decimal:2',
        'total_float' => 'decimal:2',
        'cdi_rate_annual' => 'decimal:4',
        'cdi_rate_daily' => 'decimal:8',
        'yield_amount' => 'decimal:2',
        'cumulative_yield_month' => 'decimal:2',
        'cumulative_yield_total' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function scopeForMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->whereYear('snapshot_date', $year)->whereMonth('snapshot_date', $month);
    }
}
