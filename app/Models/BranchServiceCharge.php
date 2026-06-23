<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchServiceCharge extends Model
{
    protected $fillable = [
        'branch_id',
        'company_id',
        'service_fee_enabled',
        'service_fee_type',
        'service_fee_value',
        'couvert_enabled',
        'couvert_type',
        'couvert_value',
    ];

    protected $casts = [
        'service_fee_enabled' => 'boolean',
        'service_fee_value' => 'float',
        'couvert_enabled' => 'boolean',
        'couvert_value' => 'float',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function calculateServiceFee(float $subtotal): float
    {
        if (! $this->service_fee_enabled) {
            return 0.0;
        }

        return $this->service_fee_type === 'percent'
            ? round($subtotal * $this->service_fee_value / 100, 2)
            : $this->service_fee_value;
    }

    public function calculateCouvert(float $subtotal): float
    {
        if (! $this->couvert_enabled) {
            return 0.0;
        }

        return $this->couvert_type === 'percent'
            ? round($subtotal * $this->couvert_value / 100, 2)
            : $this->couvert_value;
    }
}
