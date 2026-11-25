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

    /**
     * @return array{id: string, status: string, deleted: bool, value: float, nextDueDate: ?string, billingType: ?string}
     */
    public function getSubscription(string $subscriptionId): array;

    public function getPendingSubscriptionPayments(string $subscriptionId): array;

    public function getSubscriptionPayments(string $subscriptionId): array;

    /**
     * Cobranças vencidas (OVERDUE) do cliente, de qualquer assinatura ou avulsas. Lança em falha:
     * quem chama decide se a empresa sai do atraso a partir disso.
     *
     * @return array<int, array{id: string, status: string, value: float, dueDate: string, subscription: ?string}>
     */
    public function getCustomerOverduePayments(string $customerId): array;

    /**
     * Troca o cartão da assinatura sem cobrar nada. As faturas pendentes passam para o novo
     * cartão; as vencidas não (essas são pagas com payWithCreditCard).
     *
     * @throws \App\Exceptions\CardPaymentDeclinedException cartão recusado pelo Asaas (4xx)
     */
    public function updateSubscriptionCreditCard(
        string $subscriptionId,
        CreditCardDTO $creditCard,
        CreditCardHolderDTO $holderInfo,
        string $remoteIp,
    ): array;

    /**
     * Paga com cartão uma cobrança que já existe (ex.: fatura vencida da assinatura).
     * Não é idempotente: depois de timeout, consultar a cobrança antes de tentar de novo.
     *
     * @throws \App\Exceptions\CardPaymentDeclinedException cartão recusado pelo Asaas (4xx)
     */
    public function payWithCreditCard(string $paymentId, CreditCardDTO $creditCard, CreditCardHolderDTO $holderInfo): array;

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
