<?php

namespace App\Enums;

/**
 * Motivos pré-definidos pra seleção rápida no cancelamento de pedido (PDV).
 * `Other` exige descrição livre complementar — ver validação no componente.
 */
enum OrderCancellationReason: string
{
    case CustomerGaveUp = 'customer_gave_up';
    case EntryError = 'entry_error';
    case ItemUnavailable = 'item_unavailable';
    case DuplicateOrder = 'duplicate_order';
    case PaymentIssue = 'payment_issue';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CustomerGaveUp => 'Cliente desistiu',
            self::EntryError => 'Erro de lançamento',
            self::ItemUnavailable => 'Produto indisponível',
            self::DuplicateOrder => 'Pedido duplicado',
            self::PaymentIssue => 'Problema no pagamento',
            self::Other => 'Outro motivo',
        };
    }
}
