<?php

namespace App\Enums;

enum OrderChannel: string
{
    case Chat = 'chat';
    case Ifood = 'ifood';
    case Food99 = '99food';

    public function label(): string
    {
        return match ($this) {
            self::Chat => 'Chat',
            self::Ifood => 'iFood',
            self::Food99 => '99Food',
        };
    }

    /**
     * Marketplace: o pedido nasce e é cobrado fora do VEDDI (iFood, 99Food). Itens, valores e
     * endereço vêm da plataforma, o pedido não é editável aqui e cada etapa passa pela API dela.
     */
    public function isMarketplace(): bool
    {
        return $this !== self::Chat;
    }

    /** Coluna de products que liga/desliga a venda do produto no marketplace. */
    public function availabilityColumn(): ?string
    {
        return match ($this) {
            self::Ifood => 'available_in_ifood',
            self::Food99 => 'available_in_99food',
            default => null,
        };
    }

    /** Canal de log do módulo da plataforma. */
    public function logChannel(): string
    {
        return match ($this) {
            self::Ifood => 'ifood',
            self::Food99 => 'food99',
            default => 'orders',
        };
    }

    public static function isMarketplaceValue(?string $channel): bool
    {
        return (bool) self::tryFrom((string) $channel)?->isMarketplace();
    }

    /** @return array<int, string> */
    public static function marketplaceValues(): array
    {
        return array_values(array_map(
            fn (self $channel) => $channel->value,
            array_filter(self::cases(), fn (self $channel) => $channel->isMarketplace()),
        ));
    }
}
