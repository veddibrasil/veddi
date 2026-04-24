<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSettings extends Model
{
    protected $fillable = [
        'company_id',
        'card_rate_1x',
        'anticipation_rate_d2',
        'anticipation_rate_d7',
        'anticipation_rate_d15',
        'anticipation_rate_d30',
        'system_fee_rate',
        'default_anticipation_days',
    ];

    protected $casts = [
        'card_rate_1x' => 'float',
        'anticipation_rate_d2' => 'float',
        'anticipation_rate_d7' => 'float',
        'anticipation_rate_d15' => 'float',
        'anticipation_rate_d30' => 'float',
        'system_fee_rate' => 'float',
        'default_anticipation_days' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
