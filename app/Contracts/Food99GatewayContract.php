<?php

namespace App\Contracts;

use App\Models\Food99Integration;

/**
 * 99Food Protocol (Recommended). Ids da 99 (shop_id, order_id, apply_id, taskID) são int64 e
 * circulam como string; valores monetários em centavos, como a API devolve. Toda recusa vira
 * Food99ApiException (errno != 0); limite de frequência vira Food99RateLimitedException.
 */
interface Food99GatewayContract
{
    // ── Loja ──────────────────────────────────────────────────────────────

    /** Lojas que o superadmin da loja na 99 autorizou para o app (paginado). */
    public function getAuthorizedShops(int $page = 1, int $pageSize = 50): array;

    /** Lojas já vinculadas ao app (fallback quando getAuthorizedShops não está liberado). */
    public function listBoundShops(int $page = 1, int $pageSize = 50): array;

    /** Vincula lojas ao app com o nosso app_shop_id (só produção, acesso restrito). */
    public function bindShops(array $shopInfos): array;

    public function unbindShop(Food99Integration $integration): void;

    public function getShopDetail(Food99Integration $integration): array;

    /**
     * Atualiza dados da loja (horários, entre outros). A resposta traz um booleano por campo:
     * errno 0 com false significa que aquele campo não foi aceito.
     */
    public function updateShop(Food99Integration $integration, array $fields): array;

    /** 1 = app 99Food Loja e API confirmam pedidos; 2 = só API. */
    public function setConfirmMethod(Food99Integration $integration, int $method): void;

    /** store_status, pause_time, pause_reason_code, auto_switch, busy_mode... (efeito assíncrono, confirmado no callback shopStatus). */
    public function setStoreStatus(Food99Integration $integration, array $params): void;

    /** Liga/desliga o recebimento de pedidos de cancelamento e de reembolso do cliente. */
    public function setApplyConfig(Food99Integration $integration, bool $receiveCancelApply, bool $receiveRefundApply): void;

    // ── Cardápio ──────────────────────────────────────────────────────────

    public function getMenu(Food99Integration $integration): array;

    /** Upload completo (sobrescreve o cardápio) e assíncrono: devolve o taskID. */
    public function uploadMenu(Food99Integration $integration, array $menu): string;

    public function getMenuTaskInfo(Food99Integration $integration, string $taskId): array;

    public function updateItem(Food99Integration $integration, array $item): void;

    /** @param array<int, string> $appItemIds  $status 1 = à venda, 2 = indisponível */
    public function updateItemStatus(Food99Integration $integration, array $appItemIds, int $status): void;

    public function updateModifierGroup(Food99Integration $integration, array $group): void;

    // ── Pedido ────────────────────────────────────────────────────────────

    public function getOrderDetails(Food99Integration $integration, string $orderId): array;

    public function confirmOrder(Food99Integration $integration, string $orderId): void;

    public function markOrderReady(Food99Integration $integration, string $orderId): void;

    /** Recusa (antes do aceite) ou cancela (depois) pelo lado da loja; 1080 exige $reason. */
    public function cancelOrder(Food99Integration $integration, string $orderId, int $reasonId, ?string $reason = null): void;

    public function respondCancelApply(Food99Integration $integration, string $applyId, bool $agree, ?string $reason = null): void;

    public function respondRefundApply(Food99Integration $integration, string $applyId, bool $agree, ?int $baseReasonId = null, ?string $baseReason = null, ?string $customReason = null): void;

    /** Loja confirma que recebeu do entregador 99 o dinheiro do pedido (entrega 99 + dinheiro). */
    public function confirmCashPayment(Food99Integration $integration, string $orderId): void;

    /** Cliente retirou o pedido (só fulfillment_mode = 1). */
    public function finishOrder(Food99Integration $integration, string $orderId): void;

    /**
     * @param  array<int, array{app_item_id: string, mdu_id: string, cancel_amount: int}>  $items
     * @param  array<int, string>  $soldOutAppItemIds  marcados como esgotados junto
     */
    public function partialCancel(Food99Integration $integration, string $orderId, array $items, array $soldOutAppItemIds = [], ?int $soldOutType = null): void;

    // ── Entrega própria ───────────────────────────────────────────────────

    public function dispatchSelfDelivery(Food99Integration $integration, string $orderId, array $courierInfo, array $limitTime, ?array $vehicle = null): void;

    public function markSelfDelivered(Food99Integration $integration, string $orderId): void;

    public function verifyDeliveryCode(Food99Integration $integration, string $orderId, string $deliveryCode): void;
}
