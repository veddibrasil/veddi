<?php

namespace App\Contracts;

use App\Models\KeetaIntegration;

/**
 * Chamadas à API Open Delivery da Keeta. O token é do app (vale pra todas as lojas); a
 * integração entra pra montar o merchantId e dar contexto ao log.
 */
interface KeetaGatewayContract
{
    // ── Autorização e vínculo ──────────────────────────────────────────────

    /** Link do portal da Keeta onde o lojista autoriza o app VEDDI. */
    public function authorizationUrl(?string $redirectUri = null): string;

    /**
     * Lojas daquela autorização (só as que o lojista escolheu, nunca a lista do app inteiro).
     *
     * @return array{brand_name: ?string, shops: array<int, array{id: string, name: string, address: ?string, latitude: ?float, longitude: ?float, timezone: ?string}>, total_pages: int}
     */
    public function authorizedShops(string $authId, int $page = 1, int $pageSize = 50): array;

    /**
     * Registra o mapeamento merchantId (nosso) ↔ loja na Keeta, o webhook de pedidos e o
     * endpoint de cardápio. Sem isso nenhum evento chega. Reenviar com o mesmo id substitui o
     * mapeamento anterior na Keeta.
     */
    public function onboardMerchant(KeetaIntegration $integration, string $ordersWebhookUrl, string $menuUrl, ?string $menuApiKey): array;

    // ── Loja e cardápio ───────────────────────────────────────────────────

    /** Status, horários e sourceAppId da loja como a Keeta vê. */
    public function merchantStatus(KeetaIntegration $integration): array;

    /**
     * merchantUpdate: corpo vazio = Keeta busca o cardápio inteiro de novo; só merchantStatus =
     * abre/fecha; entityType + updatedObjects = muda itens/opções. Nunca misturar.
     */
    public function merchantUpdate(KeetaIntegration $integration, array $body = []): void;

    // ── Eventos ───────────────────────────────────────────────────────────

    /**
     * Eventos pendentes das lojas informadas (até 100 merchantIds nossos por chamada; a Keeta
     * aceita 2 chamadas por minuto por token).
     *
     * @param  array<int, string>  $merchantRefs
     * @return array<int, array<string, mixed>>
     */
    public function pollEvents(array $merchantRefs): array;

    /** @param array<int, array{id: string, orderId: string, eventType: string}> $events */
    public function acknowledgeEvents(array $events): void;

    // ── Pedidos ───────────────────────────────────────────────────────────

    public function getOrder(?KeetaIntegration $integration, string $orderId): array;

    public function confirmOrder(KeetaIntegration $integration, string $orderId, string $orderExternalCode, ?int $preparationMinutes = null, ?string $reason = null): void;

    public function readyForPickup(KeetaIntegration $integration, string $orderId): void;

    /** Só entrega própria. */
    public function dispatchOrder(KeetaIntegration $integration, string $orderId, ?array $deliveryTrackingInfo = null): void;

    /** Só entrega própria. */
    public function markDelivered(KeetaIntegration $integration, string $orderId): void;

    /**
     * Pede o cancelamento (202). O pedido só está cancelado quando chegar o evento CANCELLED.
     *
     * @param  array<int, string>  $invalidItems  ids de item da Keeta (com OUTDATED_MENU a Keeta os desativa)
     */
    public function requestCancellation(KeetaIntegration $integration, string $orderId, string $code, string $reason, string $mode = 'MANUAL', array $outOfStockItems = [], array $invalidItems = []): void;

    public function acceptRefund(KeetaIntegration $integration, string $orderId): void;

    public function rejectRefund(KeetaIntegration $integration, string $orderId, string $code, string $reason): void;

    /**
     * Descriptografa dados ENC_ (telefone, endereço) de pedido de entrega própria. Até 50 por vez.
     *
     * @param  array<int, string>  $cipherTexts
     * @return array<string, string> cipherText => texto (só os que deram certo)
     */
    public function batchDecrypt(KeetaIntegration $integration, array $cipherTexts): array;
}
