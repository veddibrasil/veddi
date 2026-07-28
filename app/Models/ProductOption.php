<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductOption extends Model
{
    protected $fillable = ['product_option_group_id', 'name', 'image_path', 'active', 'description', 'additional_price', 'default_qty', 'max_qty', 'sort_order'];

    protected $casts = [
        'active' => 'boolean',
        'additional_price' => 'decimal:2',
        'default_qty' => 'integer',
        'max_qty' => 'integer',
        'sort_order' => 'integer',
    ];

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }
        $base = rtrim(config('filesystems.disks.s3.url', ''), '/');

        return $base.'/'.ltrim($this->image_path, '/');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductOptionGroup::class, 'product_option_group_id');
    }
}
