<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'product_name', 'unit_price', 'quantity', 'subtotal', 'options',
        'kitchen_sent_quantity', 'bar_sent_quantity',
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

    /**
     * Quanto desta linha ainda não foi mandado pra produção na estação informada.
     * addOrIncrementItem() funde quantidade nova na mesma linha (mesmo id) quando o
     * produto+opções repete — sem isso não dá pra saber "já imprimi 2, chegou +1"
     * sem reimprimir os 2 de novo. Só cozinha/bar têm contador; 'geral'/'entrega'
     * sempre voltam 0 aqui (a via deles é o cupom de venda completo, não item a item).
     */
    public function pendingQuantityForStation(string $station): int
    {
        if (! $this->matchesStation($station)) {
            return 0;
        }

        $sentColumn = match ($station) {
            'cozinha' => 'kitchen_sent_quantity',
            'bar' => 'bar_sent_quantity',
            default => null,
        };

        if ($sentColumn === null) {
            return 0;
        }

        return max(0, $this->quantity - $this->{$sentColumn});
    }
}
