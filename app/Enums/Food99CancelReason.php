<?php

namespace App\Enums;

/**
 * Motivos (reason_id) aceitos pela 99Food pra loja recusar ou cancelar um pedido. Os de
 * entrega (sem entregador, fora da área, área insegura) só valem na entrega própria; "outro"
 * exige o texto do motivo.
 */
enum Food99CancelReason: int
{
    case ItemSoldOut = 1010;
    case StoreClosed = 1020;
    case StoreBusy = 1030;
    case Accident = 1040;
    case CustomerProblem = 1050;
    case NoCourier = 1060;
    case MenuOutdated = 1070;
    case OutOfArea = 1071;
    case UnsafeArea = 1072;
    case SuspectedFraud = 1073;
    case FeesOrPromotions = 1074;
    case Other = 1080;

    public function label(): string
    {
        return match ($this) {
            self::ItemSoldOut => 'Item esgotado',
            self::StoreClosed => 'Loja fechada',
            self::StoreBusy => 'Loja muito ocupada',
            self::Accident => 'Acidente, falta de energia ou de água',
            self::CustomerProblem => 'Problema com o cliente',
            self::NoCourier => 'Sem entregador disponível',
            self::MenuOutdated => 'Cardápio precisa de atualização',
            self::OutOfArea => 'Endereço fora da área de entrega',
            self::UnsafeArea => 'Área de entrega insegura',
            self::SuspectedFraud => 'Suspeita de fraude ou trote',
            self::FeesOrPromotions => 'Dúvida sobre taxas ou promoções',
            self::Other => 'Outro motivo',
        };
    }

    public function selfDeliveryOnly(): bool
    {
        return in_array($this, [self::NoCourier, self::OutOfArea, self::UnsafeArea], true);
    }

    public function requiresText(): bool
    {
        return $this === self::Other;
    }

    /** @return array<int, self> */
    public static function forDelivery(bool $selfDelivery): array
    {
        return array_values(array_filter(self::cases(), fn (self $reason) => $selfDelivery || ! $reason->selfDeliveryOnly()));
    }
}
