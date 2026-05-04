<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'asaas_payment_id',
        'stark_payment_id',
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
        'idempotency_key',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
        'webhook_payload' => 'array',
        'amount' => 'decimal:2',
        'pix_fee' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'card_fee' => 'decimal:2',
        'card_fee_rate' => 'float',
        'installments' => 'integer',
        'anticipation_days' => 'integer',
    ];

    /**
     * Retorna o ID externo do gateway, independente de qual gateway processou.
     * Stark → stark_payment_id; Asaas → asaas_payment_id.
     */
    public function getExternalIdAttribute(): ?string
    {
        return $this->stark_payment_id ?? $this->asaas_payment_id;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }
}
