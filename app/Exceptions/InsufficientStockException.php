<?php

namespace App\Exceptions;

use App\Models\Product;

class InsufficientStockException extends OrderPlacementException
{
    public function __construct(
        public readonly Product $product,
        public readonly int $available,
        public readonly int $requested,
    ) {
        parent::__construct(
            "Estoque insuficiente para '{$product->name}': disponível {$available}, solicitado {$requested}."
        );
    }
}
