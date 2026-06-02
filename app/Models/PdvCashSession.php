<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdvCashSession extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'branch_id',
        'user_id',
        'terminal_name',
        'opening_amount',
        'closing_amount',
        'expected_amount',
        'reconciliation_notes',
        'closed_at',
    ];

    protected $casts = [
        'opening_amount' => 'float',
        'closing_amount' => 'float',
        'expected_amount' => 'float',
        'closed_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
