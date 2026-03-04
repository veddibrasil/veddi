<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    protected $fillable = [
        'product_category_id', 'name', 'description', 'price', 'image_path', 'active', 'sort_order',
    ];

    protected $casts = [
        'price'  => 'decimal:2',
        'active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withPivot('available');
    }
}
