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
        'status',
        'paid_at',
        'webhook_payload',
        'expires_at',
        'payment_token',
    ];

    protected $casts = [
        'paid_at'         => 'datetime',
        'expires_at'      => 'datetime',
        'webhook_payload' => 'array',
        'amount'          => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
