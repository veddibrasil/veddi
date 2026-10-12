<?php

namespace App\Contracts;

use App\Models\IfoodIntegration;

interface IfoodGatewayContract
{
    /** Garante um access_token válido para a integração, renovando se necessário. */
    public function authenticate(IfoodIntegration $integration): string;

    /** Força renovação do token, ignorando cache/validade atual. */
    public function refreshToken(IfoodIntegration $integration): string;

    /** Busca eventos pendentes via polling (GET /events:polling). */
    public function pollEvents(IfoodIntegration $integration): array;

    /** Confirma recebimento de eventos já processados (ACK explícito do polling). */
    public function acknowledgeEvents(IfoodIntegration $integration, array $eventIds): void;

    /** Busca detalhes completos de um pedido pelo id do iFood. */
    public function getOrderDetails(IfoodIntegration $integration, string $ifoodOrderId): array;

    public function confirmOrder(IfoodIntegration $integration, string $ifoodOrderId): void;

    public function rejectOrder(IfoodIntegration $integration, string $ifoodOrderId, string $reasonCode): void;

    public function updateOrderStatus(IfoodIntegration $integration, string $ifoodOrderId, string $status): void;

    public function getCancellationReasons(IfoodIntegration $integration, string $ifoodOrderId): array;

    public function requestCancellation(IfoodIntegration $integration, string $ifoodOrderId, string $reasonCode): void;

    /** Plataforma de Negociação: aceita o pedido do cliente (cancelamento/reembolso). */
    public function acceptDispute(IfoodIntegration $integration, string $disputeId, ?string $reason = null): void;

    /** Plataforma de Negociação: recusa o pedido do cliente, com justificativa. */
    public function rejectDispute(IfoodIntegration $integration, string $disputeId, string $reason): void;

    /** Plataforma de Negociação: responde com uma das alternativas oferecidas na disputa. */
    public function proposeDisputeAlternative(IfoodIntegration $integration, string $disputeId, string $alternativeId, array $body): void;

    public function listMerchants(IfoodIntegration $integration): array;

    public function getMerchantDetails(IfoodIntegration $integration): array;

    public function getMerchantStatus(IfoodIntegration $integration): array;

    public function listInterruptions(IfoodIntegration $integration): array;

    public function createInterruption(IfoodIntegration $integration, array $data): array;

    public function deleteInterruption(IfoodIntegration $integration, string $id): void;

    public function getOpeningHours(IfoodIntegration $integration): array;

    public function setOpeningHours(IfoodIntegration $integration, array $shifts): void;

    public function getCatalogItem(IfoodIntegration $integration, string $id): ?array;

    public function uploadCatalogImage(IfoodIntegration $integration, string $image): string;

    public function updateItemPrices(IfoodIntegration $integration, array $prices): void;

    public function updateItemStatuses(IfoodIntegration $integration, array $items): void;

    public function updateOptionPrice(IfoodIntegration $integration, string $id, array $price): void;

    public function updateOptionStatus(IfoodIntegration $integration, string $id, string $status): void;

    /** Cria uma categoria no catálogo do merchant e retorna o categoryId gerado pelo iFood. */
    public function createCategory(IfoodIntegration $integration, string $name): string;

    /**
     * PUT /items da Catalog API — envia UM item (com seus products/optionGroups/
     * options aninhados) por chamada. Idempotente por item.id: chamar de novo com
     * o mesmo payload sobrescreve, não duplica.
     */
    public function syncCatalog(IfoodIntegration $integration, array $itemPayload): void;

    /**
     * Busca lançamentos de repasse (Financial API) num período. Formato de
     * endpoint/payload especulativo — validar contra sandbox real (Fase 8).
     */
    public function getSettlements(IfoodIntegration $integration, \Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to): array;
}
