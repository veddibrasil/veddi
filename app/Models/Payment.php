<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'order_id', 'abacatepay_billing_id', 'abacatepay_url',
        'pix_qr_code', 'pix_copy_paste', 'amount', 'status', 'paid_at', 'webhook_payload',
    ];

    protected $casts = [
        'paid_at'         => 'datetime',
        'webhook_payload' => 'array',
        'amount'          => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
