<?php

namespace App\Contracts;

use App\DTOs\AsaasCustomerDTO;
use App\DTOs\CreditCardDTO;
use App\DTOs\CreditCardHolderDTO;
use App\Enums\Plan;

interface AsaasServiceInterface
{
    public function probeHealth(): bool;

    public function createCustomer(AsaasCustomerDTO $customer): string;

    public function createSubscription(
        string $customerId,
        Plan $plan,
        string $billingType = 'PIX',
        ?CreditCardDTO $creditCard = null,
        ?CreditCardHolderDTO $holderInfo = null,
        ?string $nextDueDate = null,
        float $extraAmount = 0.0,
        string $extraDescription = '',
        ?string $creditCardToken = null,
    ): array;

    public function updateSubscriptionValue(string $subscriptionId, float $value, string $description): array;

    public function getSubscriptionPayments(string $subscriptionId): array;

    public function cancelSubscription(string $subscriptionId): void;

    public function createCharge(string $customerId, float $amount, string $description, string $billingType = 'PIX'): array;

    public function createCreditCardCharge(
        string $customerId,
        float $amount,
        string $description,
        string $externalReference,
        ?CreditCardDTO $creditCard = null,
        ?CreditCardHolderDTO $holderInfo = null,
        int $installments = 1,
        ?string $creditCardToken = null,
    ): array;

    public function findOrCreateCustomer(AsaasCustomerDTO $customer): string;

    public function getPaymentPixQrCode(string $paymentId): array;

    public function createTransfer(array $data): array;

    public function refundPayment(string $asaasPaymentId, ?float $value = null): array;

    public function validateWebhookToken(string $token): bool;

    public function getBalance(): array;

    public function simulateAnticipation(string $asaasPaymentId): array;

    public function createAnticipation(string $asaasPaymentId): array;
}
