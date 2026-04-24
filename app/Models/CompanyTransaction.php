<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyTransaction extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'order_id',
        'payment_id',
        'type',
        'status',
        'value',
        'net_value',
        'payment_date',
        'release_date',
        'withdrawn',
        'withdrawn_at',
        'withdrawal_id',
        'is_anticipated',
        'anticipation_fee',
        'description',
        'metadata',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'net_value' => 'decimal:2',
        'anticipation_fee' => 'decimal:2',
        'payment_date' => 'date',
        'release_date' => 'date',
        'withdrawn' => 'boolean',
        'withdrawn_at' => 'datetime',
        'is_anticipated' => 'boolean',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(CompanyWithdrawal::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isReleased(): bool
    {
        return $this->status === 'released';
    }

    public function isWithdrawn(): bool
    {
        return $this->status === 'withdrawn';
    }

    public function isRefunded(): bool
    {
        return $this->status === 'refunded';
    }

    public function isChargeback(): bool
    {
        return $this->status === 'chargeback';
    }

    public function scopeReleased($query)
    {
        return $query->where('status', 'released')->where('withdrawn', false);
    }

    public function scopeAvailable($query)
    {
        return $query->released()->where('release_date', '<=', now()->toDateString());
    }

    /**
     * Soma do net_value de transações confirmadas ou liberadas (ainda não sacadas).
     * Usa withoutGlobalScopes() para funcionar em jobs sem contexto de empresa.
     */
    public static function totalConfirmedFor(int $companyId): float
    {
        return (float) self::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', ['confirmed', 'released'])
            ->where('withdrawn', false)
            ->sum('net_value');
    }

    /**
     * Soma do net_value de transações liberadas (não sacadas).
     */
    public static function totalReleasedFor(int $companyId): float
    {
        return (float) self::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'released')
            ->where('withdrawn', false)
            ->sum('net_value');
    }
}
