<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductOption extends Model
{
    protected $fillable = ['product_option_group_id', 'name', 'active', 'description', 'additional_price', 'default_qty', 'sort_order'];

    protected $casts = [
        'active' => 'boolean',
        'additional_price' => 'decimal:2',
        'default_qty' => 'integer',
        'sort_order' => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductOptionGroup::class, 'product_option_group_id');
    }
}
