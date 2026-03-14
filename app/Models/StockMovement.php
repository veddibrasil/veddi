<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use BelongsToCompany;

    const TYPE_ORDER_DEDUCTION = 'order_deduction';
    const TYPE_ORDER_RESTORE   = 'order_restore';
    const TYPE_MANUAL_ADD      = 'manual_add';
    const TYPE_MANUAL_REMOVE   = 'manual_remove';
    const TYPE_MANUAL_SET      = 'manual_set';
    const TYPE_INITIAL_STOCK   = 'initial_stock';

    protected $fillable = [
        'company_id',
        'branch_id',
        'product_id',
        'order_id',
        'user_id',
        'quantity',
        'quantity_before',
        'quantity_after',
        'type',
        'notes',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
