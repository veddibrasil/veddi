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
}
