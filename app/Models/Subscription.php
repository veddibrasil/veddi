<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Subscription model for Asaas SaaS billing records.
 *
 * NOTE: This model deliberately does NOT use the BelongsToCompany trait.
 * Subscription records must be queryable without tenant context (webhooks, jobs).
 */
class Subscription extends Model
{
    protected $fillable = [
        'company_id',
        'asaas_subscription_id',
        'plan',
        'status',
        'amount',
        'billing_cycle',
        'next_due_date',
        'last_payment_at',
    ];

    protected $casts = [
        'next_due_date' => 'date',
        'last_payment_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
