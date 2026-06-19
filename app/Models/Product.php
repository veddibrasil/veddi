<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id',
        'product_category_id',
        'name',
        'barcode',
        'description',
        'price',
        'promo_price_enabled',
        'promo_price_type',
        'promo_price_value',
        'image_path',
        'active',
        'available_in_pdv',
        'available_in_delivery',
        'is_variant',
        'sort_order',
        'fiscal_data',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'promo_price_enabled' => 'boolean',
        'promo_price_value' => 'decimal:2',
        'active' => 'boolean',
        'available_in_pdv' => 'boolean',
        'available_in_delivery' => 'boolean',
        'is_variant' => 'boolean',
        'fiscal_data' => 'array',
    ];

    public function getEffectivePriceAttribute(): float
    {
        if (! $this->promo_price_enabled || $this->promo_price_value === null) {
            return (float) $this->price;
        }

        $base = (float) $this->price;

        if ($this->promo_price_type === 'percentage') {
            $pct = (float) $this->promo_price_value;
            $pct = max(0.0, min(100.0, $pct));

            return round(max(0.0, $base * (1 - ($pct / 100))), 2);
        }

        return (float) $this->promo_price_value;
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }
        $base = rtrim(config('filesystems.disks.s3.url', ''), '/');

        return $base.'/'.ltrim($this->image_path, '/');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withPivot(['available', 'quantity', 'min_quantity', 'track_stock']);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function optionGroups(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionGroup::class, 'option_group_product')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }
}
