<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPortalMapping extends Model
{
    protected $fillable = [
        'product_id',
        'portal_id',
        'external_item_id',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function portal(): BelongsTo
    {
        return $this->belongsTo(Portal::class);
    }
}
