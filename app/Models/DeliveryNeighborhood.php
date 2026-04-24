<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryNeighborhood extends Model
{
    protected $fillable = [
        'delivery_setting_id',
        'neighborhood',
        'fee',
        'active',
    ];

    protected $casts = [
        'fee' => 'float',
        'active' => 'boolean',
    ];

    public function deliverySetting(): BelongsTo
    {
        return $this->belongsTo(DeliverySetting::class);
    }
}
