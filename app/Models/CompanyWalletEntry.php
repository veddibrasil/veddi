<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyWalletEntry extends Model
{
    protected $fillable = [
        'company_id',
        'order_id',
        'type',
        'amount',
        'description',
        'reference',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Calculate the available balance for a company.
     * Balance = SUM(credit) - SUM(fee) - SUM(withdrawal)
     */
    public static function balanceFor(int $companyId): float
    {
        return (float) self::where('company_id', $companyId)
            ->selectRaw("SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END) as balance")
            ->value('balance') ?? 0.0;
    }
}
