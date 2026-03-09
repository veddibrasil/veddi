<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryDistanceTier extends Model
{
    protected $fillable = [
        'delivery_setting_id',
        'min_km',
        'max_km',
        'fee',
    ];

    protected $casts = [
        'min_km' => 'float',
        'max_km' => 'float',
        'fee'    => 'float',
    ];

    public function deliverySetting(): BelongsTo
    {
        return $this->belongsTo(DeliverySetting::class);
    }
}
