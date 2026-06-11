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
        public ?string $street = null,
        public ?string $complement = null,
        public ?string $neighborhood = null,
        public ?string $city = null,
        public ?string $state = null,
    ) {}
}
