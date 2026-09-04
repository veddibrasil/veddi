<?php

namespace App\Enums;

/**
 * Motivos de recusa/cancelamento aceitos pela API do iFood — lista fechada,
 * texto livre não é aceito. Códigos baseados na doc pública da Order API;
 * confirmar contra o sandbox real antes de produção (Fase 8 do plano).
 */
enum IfoodRejectReason: string
{
    case RestaurantOutOfOperation = 'RESTAURANT_OUT_OF_OPERATION';
    case OrderAboveCapacity = 'ORDER_QUANTITY_ABOVE_MERCHANT_CAPACITY';
    case ItemUnavailable = 'ITEM_UNAVAILABLE';
    case MerchantAddressNotFound = 'MERCHANT_ADDRESS_NOT_FOUND';
    case RestaurantClosed = 'RESTAURANT_CLOSED';
    case PriceDivergence = 'PRICE_DIVERGENCE';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::RestaurantOutOfOperation => 'Loja fora de operação',
            self::OrderAboveCapacity => 'Quantidade acima da capacidade da loja',
            self::ItemUnavailable => 'Item indisponível',
            self::MerchantAddressNotFound => 'Endereço da loja não encontrado',
            self::RestaurantClosed => 'Loja fechada',
            self::PriceDivergence => 'Divergência de preço',
            self::Other => 'Outro motivo',
        };
    }
}
