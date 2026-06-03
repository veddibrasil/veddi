<?php

namespace App\DTOs;

readonly class FiscalNoteDTO
{
    public function __construct(
        public string $emitenteCnpj,
        public string $emitenteNome,
        public string $emitenteLogradouro,
        public string $emitenteNumero,
        public string $emitenteBairro,
        public string $emitenteMunicipio,
        public string $emitenteUf,
        public string $emitenteCep,
        public int $emitenteCrt,
        public string $nfceSerie,
        public int $orderId,
        public float $total,
        public string $paymentMethod,
        /** @var array<int, array{ncm: string, cfop: string, icms_situation: string, cest: string|null, name: string, quantity: float, unit_price: float, subtotal: float}> */
        public array $items,
        public ?string $customerDocument = null,
        public string $environment = 'homologacao',
    ) {}
}
