<?php

namespace App\Enums;

enum EmailSuppressionReason: string
{
    case Unsubscribed = 'unsubscribed';
    case HardBounce = 'hard_bounce';
    case Complaint = 'complaint';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Unsubscribed => 'Descadastro',
            self::HardBounce => 'Bounce permanente',
            self::Complaint => 'Reclamação de spam',
            self::Manual => 'Manual',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unsubscribed => 'zinc',
            self::HardBounce => 'orange',
            self::Complaint => 'red',
            self::Manual => 'sky',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
