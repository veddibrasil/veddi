<?php

namespace App\Enums;

enum EmailCampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Building = 'building';
    case Sending = 'sending';
    case Paused = 'paused';
    case Sent = 'sent';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Scheduled => 'Agendada',
            self::Building => 'Montando público',
            self::Sending => 'Enviando',
            self::Paused => 'Pausada',
            self::Sent => 'Enviada',
            self::Cancelled => 'Cancelada',
            self::Failed => 'Falhou',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Scheduled => 'sky',
            self::Building, self::Sending => 'amber',
            self::Paused => 'orange',
            self::Sent => 'green',
            self::Cancelled => 'zinc',
            self::Failed => 'red',
        };
    }

    /** Conteúdo, público e agendamento só mudam antes do disparo começar. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled], true);
    }

    public function canPause(): bool
    {
        return in_array($this, [self::Building, self::Sending], true);
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::Scheduled, self::Building, self::Sending, self::Paused], true);
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
