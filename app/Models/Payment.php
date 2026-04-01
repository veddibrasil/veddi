<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'asaas_payment_id',
        'payment_gateway',
        'pix_qr_code',
        'pix_copy_paste',
        'amount',
        'pix_fee',
        'original_amount',
        'card_fee',
        'card_fee_rate',
        'installments',
        'anticipation_days',
        'status',
        'paid_at',
        'webhook_payload',
        'expires_at',
        'payment_token',
    ];

    protected $casts = [
        'paid_at'           => 'datetime',
        'expires_at'        => 'datetime',
        'webhook_payload'   => 'array',
        'amount'            => 'decimal:2',
        'pix_fee'           => 'decimal:2',
        'original_amount'   => 'decimal:2',
        'card_fee'          => 'decimal:2',
        'card_fee_rate'     => 'float',
        'installments'      => 'integer',
        'anticipation_days' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
