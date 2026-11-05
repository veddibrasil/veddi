<?php

namespace App\Enums;

/**
 * Motivos (RequestCancelled.code, v1.0.7) aceitos pela Keeta pra loja pedir o cancelamento.
 * Os de entrega só valem na entrega própria; OUTDATED_MENU desativa na Keeta os itens
 * informados em invalidItems. O texto (reason) é sempre obrigatório na API: sem texto da loja,
 * vai o rótulo do motivo.
 */
enum KeetaCancelReason: string
{
    case UnavailableItem = 'UNAVAILABLE_ITEM';
    case OutdatedMenu = 'OUTDATED_MENU';
    case OutsideDeliveryHours = 'OUTSIDE_DELIVERY_HOURS';
    case InternalDifficulties = 'INTERNAL_DIFFICULTIES_OF_THE_RESTAURANT';
    case SystemicIssues = 'SYSTEMIC_ISSUES';
    case DuplicateApplication = 'DUPLICATE_APPLICATION';
    case BlockedCustomer = 'BLOCKED_CUSTOMER';
    case WithoutDeliveryPerson = 'RESTAURANT_WITHOUT_DELIVERY_PERSON';
    case OutsideDeliveryArea = 'ORDER_OUTSIDE_THE_DELIVERY_AREA';
    case RiskArea = 'RISK_AREA';
    case DeliveryProblem = 'DELIVERY_PROBLEM';

    public function label(): string
    {
        return match ($this) {
            self::UnavailableItem => 'Item indisponível',
            self::OutdatedMenu => 'Cardápio desatualizado',
            self::OutsideDeliveryHours => 'Fora do horário de funcionamento',
            self::InternalDifficulties => 'Dificuldade interna da loja',
            self::SystemicIssues => 'Problema no sistema',
            self::DuplicateApplication => 'Pedido duplicado',
            self::BlockedCustomer => 'Cliente bloqueado',
            self::WithoutDeliveryPerson => 'Sem entregador disponível',
            self::OutsideDeliveryArea => 'Endereço fora da área de entrega',
            self::RiskArea => 'Área de risco',
            self::DeliveryProblem => 'Problema na entrega',
        };
    }

    /** [VALIDAR Keeta] a doc não diz quais códigos valem na entrega Keeta; os de entrega ficam só na própria. */
    public function selfDeliveryOnly(): bool
    {
        return in_array($this, [self::WithoutDeliveryPerson, self::OutsideDeliveryArea, self::RiskArea, self::DeliveryProblem], true);
    }

    /** Motivo que precisa da explicação da loja (vai no reason). */
    public function requiresText(): bool
    {
        return in_array($this, [self::InternalDifficulties, self::SystemicIssues, self::DeliveryProblem], true);
    }

    /** Motivos que levam a lista de itens (outOfStockItems / invalidItems). */
    public function takesItems(): bool
    {
        return in_array($this, [self::UnavailableItem, self::OutdatedMenu], true);
    }

    /** @return array<int, self> */
    public static function forDelivery(bool $selfDelivery): array
    {
        return array_values(array_filter(self::cases(), fn (self $reason) => $selfDelivery || ! $reason->selfDeliveryOnly()));
    }
}
