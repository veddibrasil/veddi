<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRefund extends Model
{
    protected $fillable = [
        'company_id',
        'order_id',
        'payment_id',
        'gateway',
        'amount',
        'status',
        'reason',
        'requested_by_type',
        'requested_by_id',
        'external_refund_id',
        'external_status',
        'raw_request',
        'raw_response',
        'failure_code',
        'failure_message',
        'requested_at',
        'processed_at',
        'coverage_status',
        'coverage_meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'raw_request' => 'array',
        'raw_response' => 'array',
        'coverage_meta' => 'array',
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'requested' => 'Solicitado',
            'in_progress' => 'Em processamento',
            'succeeded' => 'Concluído',
            'failed' => 'Falhou',
            'canceled' => 'Cancelado',
            default => $this->status,
        };
    }
}
