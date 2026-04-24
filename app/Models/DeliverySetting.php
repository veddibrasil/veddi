<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliverySetting extends Model
{
    protected $fillable = [
        'branch_id',
        'company_id',
        'fee_type',
        'flat_fee',
        'minimum_order_value',
        'free_delivery_above',
        'branch_latitude',
        'branch_longitude',
        'active',
    ];

    protected $casts = [
        'flat_fee' => 'float',
        'minimum_order_value' => 'float',
        'free_delivery_above' => 'float',
        'branch_latitude' => 'float',
        'branch_longitude' => 'float',
        'active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function neighborhoods(): HasMany
    {
        return $this->hasMany(DeliveryNeighborhood::class);
    }

    public function distanceTiers(): HasMany
    {
        return $this->hasMany(DeliveryDistanceTier::class)->orderBy('min_km');
    }
}
