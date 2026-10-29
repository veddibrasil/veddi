<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Food99WebhookEvent extends Model
{
    // Sem BelongsToCompany: o callback chega antes de qualquer contexto de empresa (e
    // shopBindStatus nem traz a loja). O isolamento é feito via food99_integration_id.

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_IGNORED = 'ignored';

    protected $fillable = [
        'dedupe_key',
        'food99_integration_id',
        'event_type',
        'external_order_id',
        'apply_id',
        'order_id',
        'payload',
        'request_id',
        'status',
        'attempts',
        'error',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function food99Integration(): BelongsTo
    {
        return $this->belongsTo(Food99Integration::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Conteúdo de "data" do callback. */
    public function data(): array
    {
        return (array) ($this->payload['data'] ?? []);
    }
}
