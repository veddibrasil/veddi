<?php

namespace App\DTOs;

readonly class AsaasCustomerDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $cpfCnpj,
        public ?string $phone = null,
    ) {}
}
