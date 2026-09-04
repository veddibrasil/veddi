<?php

namespace App\Enums;

enum OrderChannel: string
{
    case Chat = 'chat';
    case Ifood = 'ifood';

    public function label(): string
    {
        return match ($this) {
            self::Chat => 'Chat',
            self::Ifood => 'iFood',
        };
    }
}
