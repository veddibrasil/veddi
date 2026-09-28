<?php

namespace App\DTOs;

/**
 * Divisão de um pagamento entre gateway, plataforma e empresa.
 *
 * grossAmount + (acréscimo pago pelo cliente) = valor cobrado;
 * grossAmount - gatewayFee - platformFee = companyNet (o que a empresa recebe no split).
 * gatewayFee aqui é só a parte da taxa do gateway que sai do bolso da empresa.
 */
readonly class PaymentSplitDTO
{
    public function __construct(
        public float $grossAmount,
        public float $gatewayFee,
        public float $platformFee,
        public float $companyNet,
        public ?float $affiliatePercentual = null,
    ) {}
}
