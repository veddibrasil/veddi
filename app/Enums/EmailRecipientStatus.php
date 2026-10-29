<?php

namespace App\Enums;

enum EmailRecipientStatus: string
{
    case Pending = 'pending';
    // Fora da amostra do teste A/B: espera a vencedora.
    case Held = 'held';
    case Queued = 'queued';
    // Envio em andamento (reservado pelo worker); impede dois workers no mesmo destinatário.
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando',
            self::Held => 'Aguardando A/B',
            self::Queued => 'Na fila',
            self::Sending => 'Enviando',
            self::Sent => 'Enviado',
            self::Delivered => 'Entregue',
            self::Bounced => 'Bounce',
            self::Complained => 'Spam',
            self::Failed => 'Falhou',
            self::Suppressed => 'Suprimido',
            self::Cancelled => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Held, self::Queued, self::Sending => 'zinc',
            self::Sent => 'sky',
            self::Delivered => 'green',
            self::Bounced, self::Complained, self::Failed => 'red',
            self::Suppressed, self::Cancelled => 'amber',
        };
    }

    /** Ainda vai (ou pode) sair: a campanha não termina enquanto houver alguém nestes status. */
    public static function open(): array
    {
        return [self::Pending->value, self::Held->value, self::Queued->value, self::Sending->value];
    }

    /** O provedor aceitou a mensagem (conta como enviado). */
    public static function accepted(): array
    {
        return [self::Sent->value, self::Delivered->value, self::Bounced->value, self::Complained->value];
    }
}
