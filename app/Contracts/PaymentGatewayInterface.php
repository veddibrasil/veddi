<?php

namespace App\Contracts;

use App\Models\Customer;

interface PaymentGatewayInterface
{
    /**
     * Cria uma cobrança PIX e retorna os dados para exibição ao cliente.
     *
     * @return array{id: string, brcode: string, qr_code_url: string|null, amount: float}
     */
    public function createPixCharge(
        float $amount,
        string $description,
        string $externalRef,
        Customer $customer
    ): array;

    /**
     * Consulta o status de uma cobrança PIX pelo ID externo.
     */
    public function getPixStatus(string $externalId): string;

    /**
     * Cria uma transferência (saque) PIX ou TED.
     *
     * @return array{id: string, status: string}
     */
    public function createTransfer(array $data): array;

    /**
     * Retorna o saldo disponível na conta do gateway em reais.
     */
    public function getBalance(): float;
}
