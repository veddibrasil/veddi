<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'product_name', 'unit_price', 'quantity', 'subtotal', 'options',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'options' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Item pertence à estação (cozinha|bar) se a categoria do produto estiver marcada pra ela,
     * ou não tiver estação definida (categoria "ambos"). Usado pra filtrar visualização e cupom
     * de perfis restritos (cozinha/bar).
     */
    public function matchesStation(?string $station): bool
    {
        if ($station === null) {
            return true;
        }

        $itemStation = $this->product?->category?->station;

        return $itemStation === null || $itemStation === $station;
    }
}
