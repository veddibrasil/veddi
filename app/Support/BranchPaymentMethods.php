<?php

namespace App\Support;

class BranchPaymentMethods
{
    public const ORDER_TYPES = [
        'chat' => ['delivery' => 'Entrega', 'pickup' => 'Retirada'],
        'pdv' => ['entrega' => 'Entrega', 'retirar' => 'Retirada', 'balcao' => 'Balcão', 'mesa' => 'Mesas e comandas'],
    ];

    public const LABELS = [
        'chat' => [
            'cash' => 'Dinheiro ao receber',
            'card_on_receipt' => 'Cartão na maquininha ao receber',
            'pix_on_receipt' => 'PIX direto para a loja ao receber',
            'pix_online' => 'PIX online pelo chat',
            'card_online' => 'Cartão de crédito online pelo chat',
        ],
        'pdv' => ['cash' => 'Dinheiro', 'pix' => 'PIX', 'credit_card' => 'Cartão'],
    ];

    public static function chatChoice(string $method, bool $onReceipt): ?string
    {
        return match (strtoupper($method)) {
            'CASH' => 'cash',
            'PIX' => $onReceipt ? 'pix_on_receipt' : 'pix_online',
            'CARD' => $onReceipt ? 'card_on_receipt' : 'card_online',
            default => null,
        };
    }
}
