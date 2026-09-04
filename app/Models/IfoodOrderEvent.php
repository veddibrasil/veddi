<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IfoodOrderEvent extends Model
{
    // Sem BelongsToCompany: o evento chega no webhook antes de qualquer contexto de
    // empresa estar resolvido (ver App\Http\Controllers\IfoodWebhookController). O
    // isolamento por empresa é feito via ifood_integration_id, não via CompanyScope.

    protected $fillable = [
        'event_id',
        'event_type',
        'source',
        'order_id',
        'ifood_integration_id',
        'payload',
        'status',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function ifoodIntegration(): BelongsTo
    {
        return $this->belongsTo(IfoodIntegration::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
