<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'code', 'name', 'description',
        'type', 'discount_value', 'free_product_id',
        'scope', 'scope_ids',
        'minimum_order_value', 'max_uses', 'max_uses_per_customer',
        'starts_at', 'expires_at', 'active',
    ];

    protected $casts = [
        'scope_ids'           => 'array',
        'starts_at'           => 'datetime',
        'expires_at'          => 'datetime',
        'active'              => 'boolean',
        'discount_value'      => 'float',
        'minimum_order_value' => 'float',
    ];

    public function freeProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'free_product_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    public function isExpired(): bool
    {
        if ($this->starts_at && now()->lt($this->starts_at)) {
            return true;
        }

        return $this->expires_at && now()->gt($this->expires_at);
    }

    public function hasReachedMaxUses(): bool
    {
        if ($this->max_uses === null) {
            return false;
        }

        return $this->usages()->count() >= $this->max_uses;
    }

    public function hasReachedMaxUsesForCustomer(int $customerId): bool
    {
        if ($this->max_uses_per_customer === null) {
            return false;
        }

        return $this->usages()->where('customer_id', $customerId)->count() >= $this->max_uses_per_customer;
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            'percentage'    => 'Percentual (%)',
            'fixed'         => 'Valor Fixo (R$)',
            'free_delivery' => 'Frete Grátis',
            'free_product'  => 'Produto Grátis',
            default         => $this->type,
        };
    }
}
