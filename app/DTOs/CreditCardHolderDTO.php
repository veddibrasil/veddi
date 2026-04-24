<?php

namespace App\DTOs;

readonly class CreditCardHolderDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $cpfCnpj,
        public string $postalCode,
        public string $addressNumber,
        public ?string $phone = null,
        public ?string $mobilePhone = null,
    ) {}
}
