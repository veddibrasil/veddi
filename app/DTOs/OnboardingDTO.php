<?php

namespace App\DTOs;

readonly class OnboardingDTO
{
    public function __construct(
        public string $companyName,
        public string $slug,
        public string $plan,
        public string $asaasCpfCnpj,
        public string $branchName,
        public string $branchPhone,
        public string $userName,
        public string $userEmail,
        public string $userPassword,
        public string $paymentMethod = 'PIX',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            companyName:  $data['company_name'],
            slug:         $data['slug'],
            plan:         $data['plan'],
            asaasCpfCnpj: $data['asaas_cpf_cnpj'],
            branchName:   $data['branch_name'],
            branchPhone:  $data['branch_phone'],
            userName:     $data['user_name'],
            userEmail:    $data['user_email'],
            userPassword:  $data['user_password'],
            paymentMethod: $data['payment_method'] ?? 'PIX',
        );
    }
}
