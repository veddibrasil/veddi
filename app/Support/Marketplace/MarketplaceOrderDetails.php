<?php

namespace App\Support\Marketplace;

use Carbon\CarbonInterface;

/**
 * O que telas, comandas e notificações leem de um pedido de marketplace (iFood, 99Food)
 * sem depender da plataforma. Regras próprias de cada uma (etapas, códigos de evento,
 * negociações) ficam na implementação: IfoodOrderDetails, Food99OrderDetails.
 */
interface MarketplaceOrderDetails
{
    /** Nome da plataforma pra exibir ("iFood", "99Food"). */
    public function channelLabel(): string;

    /** Número curto que a plataforma mostra pra loja e pro cliente. */
    public function displayId(): string;

    public function awaitingConfirmation(): bool;

    /** Minutos que a loja tem pra aceitar antes do cancelamento automático. */
    public function confirmationMinutes(): int;

    public function confirmationDeadline(): ?CarbonInterface;

    /** Prazo de aceite já passou (com folga): o pedido foi cancelado pela plataforma. */
    public function confirmationExpired(): bool;

    public function isFinished(): bool;

    public function isScheduled(): bool;

    public function scheduleWindow(): ?string;

    public function orderTypeLabel(): string;

    /** Entrega feita pelo entregador da plataforma (não pela loja). */
    public function isDeliveredByPlatform(): bool;

    /** A loja pode despachar o pedido agora (entrega própria pronta). */
    public function canDispatch(): bool;

    /** O que falta acontecer do lado da plataforma depois da última ação da loja. */
    public function waitingMessage(): ?string;

    public function customerPhone(): ?string;

    public function phoneLocalizer(): ?string;

    /** Valor que a loja cobra do cliente na entrega/retirada. */
    public function pendingAmount(): float;

    /** @return array<int, array{label: string, value: float, prepaid: bool, change_for: ?float, change: ?float}> */
    public function paymentLines(): array;

    /** @return array<int, array{label: string, value: float, sponsors: string}> */
    public function benefitLines(): array;

    /** @return array<int, array{label: string, value: float}> */
    public function additionalFeeLines(): array;

    /** @return array{origin: ?string, code: ?string, reason: ?string}|null */
    public function cancellation(): ?array;

    public function isTest(): bool;

    /**
     * Bloco da plataforma nas comandas (PDF e impressora térmica), em ASCII.
     *
     * @return array<int, array{text: string, bold: bool}>
     */
    public function receiptLines(string $station): array;
}
