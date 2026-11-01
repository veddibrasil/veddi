<?php

namespace App\Exceptions;

class CheckoutPriceChanged extends \RuntimeException
{
    public function __construct(public array $cart, public float $deliveryFee, public float $discount)
    {
        parent::__construct('O valor do pedido mudou. Confira o carrinho atualizado e confirme novamente.');
    }
}
