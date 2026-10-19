<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;

class Coupon extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'code', 'name', 'description',
        'type', 'discount_value', 'free_product_id',
        'scope', 'scope_ids', 'payment_method',
        'minimum_order_value', 'max_uses', 'max_uses_per_customer',
        'starts_at', 'expires_at', 'active',
    ];

    protected $casts = [
        'scope_ids' => 'array',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'active' => 'boolean',
        'discount_value' => 'float',
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

    /**
     * Usos que contam para os limites (max_uses / max_uses_per_customer). Pedido cancelado ou
     * estornado devolve o uso: o registro fica para histórico, mas sem isto o cliente que troca a
     * forma de pagamento no chat (ou tem o pedido cancelado) perdia o cupom. Vale para todo
     * caminho de cancelamento (chat, admin, PDV, iFood, sistema) sem cada um ter de apagar o uso.
     */
    public function countedUsages(): HasMany
    {
        return $this->usages()->whereNotExists(fn (Builder $orders) => $orders
            ->selectRaw('1')
            ->from('orders')
            ->whereColumn('orders.id', 'coupon_usages.order_id')
            ->whereIn('orders.status', ['cancelled', 'refunded']));
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

        return $this->countedUsages()->count() >= $this->max_uses;
    }

    public function hasReachedMaxUsesForCustomer(int $customerId): bool
    {
        if ($this->max_uses_per_customer === null) {
            return false;
        }

        return $this->countedUsages()->where('customer_id', $customerId)->count() >= $this->max_uses_per_customer;
    }

    public function allowsPaymentMethod(string $paymentMethod): bool
    {
        return $this->payment_method === null || $this->payment_method === $paymentMethod;
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            'percentage' => 'Percentual (%)',
            'fixed' => 'Valor Fixo (R$)',
            'free_delivery' => 'Frete Grátis',
            'free_product' => 'Produto Grátis',
            default => $this->type,
        };
    }
}
