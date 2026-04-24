<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot de saldo por empresa.
 *
 * Intencionalmente NÃO usa BelongsToCompany (constraint unique em company_id
 * tornaria o CompanyScope problemático no updateOrCreate).
 * Mesmo padrão do model Subscription.
 */
class CompanyBalance extends Model
{
    protected $fillable = [
        'company_id',
        'total_balance',
        'blocked_balance',
        'available_balance',
        'withdrawn_balance',
        'reserve_balance',
        'last_calculated_at',
    ];

    protected $casts = [
        'total_balance' => 'decimal:2',
        'blocked_balance' => 'decimal:2',
        'available_balance' => 'decimal:2',
        'withdrawn_balance' => 'decimal:2',
        'reserve_balance' => 'decimal:2',
        'last_calculated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
