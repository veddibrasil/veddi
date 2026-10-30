<?php

namespace App\Services\Food99;

use App\Contracts\Food99GatewayContract;
use App\Exceptions\Food99ApiException;
use App\Exceptions\Food99TokenExpiredException;
use App\Models\Food99Integration;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints do 99Food Protocol usados pelo VEDDI. Os com token passam por call(), que injeta
 * o auth_token da loja e, se a 99 responder token expirado (10102), renova e repete UMA vez.
 * Os de app (lojas autorizadas, vínculo) vão assinados por Food99Client::signed().
 */
class Food99GatewayService implements Food99GatewayContract
{
    public function __construct(
        private readonly Food99Client $client,
        private readonly Food99AuthService $auth,
    ) {}

    public function getAuthorizedShops(int $page = 1, int $pageSize = 50): array
    {
        return (array) ($this->client->signed('/v3/auth/authorization/getAuthorizedShops', ['page_no' => $page, 'page_size' => $pageSize], 'authorization.getAuthorizedShops')['data'] ?? []);
    }

    public function listBoundShops(int $page = 1, int $pageSize = 50): array
    {
        return (array) ($this->client->signed('/v1/shop/shop/list', ['page_no' => $page, 'page_size' => $pageSize], 'shop.list')['data'] ?? []);
    }

    public function bindShops(array $shopInfos): array
    {
        return (array) ($this->client->signed('/v3/auth/authorization/shopBind', ['shop_infos' => $shopInfos], 'authorization.shopBind')['data'] ?? []);
    }

    public function unbindShop(Food99Integration $integration): void
    {
        $this->call($integration, 'POST', '/v1/shop/shop/unbind', [], 'shop.unbind');
    }

    public function getShopDetail(Food99Integration $integration): array
    {
        return (array) $this->call($integration, 'GET', '/v1/shop/shop/detail', [], 'shop.detail');
    }

    public function updateShop(Food99Integration $integration, array $fields): array
    {
        return (array) $this->call($integration, 'POST', '/v1/shop/shop/update', $fields, 'shop.update');
    }

    public function setConfirmMethod(Food99Integration $integration, int $method): void
    {
        $this->call($integration, 'POST', '/v1/shop/shop/setconfirmmethod', ['order_confirm_method' => $method], 'shop.setconfirmmethod');
    }

    public function setStoreStatus(Food99Integration $integration, array $params): void
    {
        $this->call($integration, 'POST', '/v1/shop/shop/setStatus', $params, 'shop.setStatus');
    }

    public function setApplyConfig(Food99Integration $integration, bool $receiveCancelApply, bool $receiveRefundApply): void
    {
        // [VALIDAR 99Food] 1 = receber, 2 = não receber (mesma convenção 1/2 dos demais flags).
        $this->call($integration, 'POST', '/v1/shop/apply/set', [
            'receive_cancel_apply' => $receiveCancelApply ? 1 : 2,
            'receive_refund_apply' => $receiveRefundApply ? 1 : 2,
        ], 'shop.apply.set');
    }

    public function getMenu(Food99Integration $integration): array
    {
        return (array) $this->call($integration, 'GET', '/v3/item/item/list', [], 'item.list');
    }

    public function uploadMenu(Food99Integration $integration, array $menu): string
    {
        $data = $this->call($integration, 'POST', '/v3/item/item/upload', $menu, 'item.upload');
        $taskId = is_array($data) ? ($data['taskID'] ?? $data['task_id'] ?? $data['taskId'] ?? null) : $data;

        if ($taskId === null || $taskId === '') {
            throw new Food99ApiException('99Food: upload de cardápio sem taskID na resposta', 0, operation: 'item.upload');
        }

        return (string) $taskId;
    }

    public function getMenuTaskInfo(Food99Integration $integration, string $taskId): array
    {
        // [VALIDAR 99Food] nome do parâmetro (taskID, como no callback uploadMenuTaskStatus).
        return (array) $this->call($integration, 'GET', '/v1/item/item/getMenuTaskInfo', ['taskID' => $taskId], 'item.getMenuTaskInfo');
    }

    public function updateItem(Food99Integration $integration, array $item): void
    {
        $this->call($integration, 'POST', '/v3/item/item/updateItem', $item, 'item.updateItem');
    }

    public function updateItemStatus(Food99Integration $integration, array $appItemIds, int $status): void
    {
        $this->call($integration, 'POST', '/v3/item/item/updateItemStatus', [
            'app_item_ids' => array_values(array_map('strval', $appItemIds)),
            'status' => $status,
        ], 'item.updateItemStatus');
    }

    public function updateModifierGroup(Food99Integration $integration, array $group): void
    {
        $this->call($integration, 'POST', '/v3/item/item/updateModifierGroup', $group, 'item.updateModifierGroup');
    }

    public function getOrderDetails(Food99Integration $integration, string $orderId): array
    {
        $data = $this->call($integration, 'GET', '/v1/order/order/detail', ['order_id' => $orderId], 'order.detail');

        // A resposta pode vir com o pedido direto em data ou embrulhado em order_info.
        return is_array($data['order_info'] ?? null) ? $data['order_info'] : (array) $data;
    }

    public function confirmOrder(Food99Integration $integration, string $orderId): void
    {
        $this->call($integration, 'POST', '/v1/order/order/confirm', ['order_id' => self::id($orderId)], 'order.confirm');
    }

    public function markOrderReady(Food99Integration $integration, string $orderId): void
    {
        $this->call($integration, 'POST', '/v1/order/order/ready', ['order_id' => self::id($orderId)], 'order.ready');
    }

    public function cancelOrder(Food99Integration $integration, string $orderId, int $reasonId, ?string $reason = null): void
    {
        $this->call($integration, 'POST', '/v1/order/order/cancel', array_filter([
            'order_id' => self::id($orderId),
            'reason_id' => $reasonId,
            'reason' => $reason,
        ], fn ($value) => $value !== null && $value !== ''), 'order.cancel');
    }

    public function respondCancelApply(Food99Integration $integration, string $applyId, bool $agree, ?string $reason = null): void
    {
        // [VALIDAR 99Food] formato de "agree" (booleano JSON).
        $this->call($integration, 'POST', '/v1/order/apply/cancel', array_filter([
            'apply_id' => self::id($applyId),
            'agree' => $agree,
            'reason' => $reason,
        ], fn ($value) => $value !== null && $value !== ''), 'order.apply.cancel');
    }

    public function respondRefundApply(Food99Integration $integration, string $applyId, bool $agree, ?int $baseReasonId = null, ?string $baseReason = null, ?string $customReason = null): void
    {
        $this->call($integration, 'POST', '/v1/order/apply/refund', array_filter([
            'apply_id' => self::id($applyId),
            'agree' => $agree,
            'base_reason_id' => $baseReasonId,
            'base_reason' => $baseReason,
            'custom_reason' => $customReason,
        ], fn ($value) => $value !== null && $value !== ''), 'order.apply.refund');
    }

    public function confirmCashPayment(Food99Integration $integration, string $orderId): void
    {
        $this->call($integration, 'POST', '/v1/order/order/payConfirm', ['order_id' => self::id($orderId)], 'order.payConfirm');
    }

    public function finishOrder(Food99Integration $integration, string $orderId): void
    {
        $this->call($integration, 'POST', '/v1/order/order/finish', ['order_id' => self::id($orderId)], 'order.finish');
    }

    public function partialCancel(Food99Integration $integration, string $orderId, array $items, array $soldOutAppItemIds = [], ?int $soldOutType = null): void
    {
        $params = [
            'order_id' => self::id($orderId),
            // A 99 espera "items" como string JSON, não como array.
            'items' => json_encode(array_values($items), JSON_UNESCAPED_UNICODE),
        ];

        if ($soldOutAppItemIds !== [] && $soldOutType !== null) {
            $params['app_item_ids'] = array_values(array_map('strval', $soldOutAppItemIds));
            $params['is_sold_out'] = $soldOutType;
        }

        $this->call($integration, 'POST', '/v3/order/operate/partialCancel', $params, 'order.partialCancel');
    }

    public function dispatchSelfDelivery(Food99Integration $integration, string $orderId, array $courierInfo, array $limitTime, ?array $vehicle = null): void
    {
        $this->call($integration, 'POST', '/v1/order/selfdelivery/dispatch', array_filter([
            'order_id' => self::id($orderId),
            'courier_info' => $courierInfo,
            'limit_time' => $limitTime,
            'vehicle' => $vehicle,
        ], fn ($value) => $value !== null && $value !== []), 'order.selfdelivery.dispatch');
    }

    public function markSelfDelivered(Food99Integration $integration, string $orderId): void
    {
        try {
            $this->call($integration, 'POST', '/v1/order/selfdelivery/delivered', ['order_id' => self::id($orderId)], 'order.selfdelivery.delivered');
        } catch (Food99ApiException $e) {
            // [VALIDAR 99Food] a doc também cita /v1/order/order/delivered: se a rota de entrega
            // própria não existir (resposta sem envelope), tenta a outra.
            if ($e->errno !== Food99ApiException::TRANSPORT_ERRNO) {
                throw $e;
            }

            Log::channel('food99')->warning('99Food: selfdelivery/delivered sem envelope, tentando order/delivered', [
                'food99_integration_id' => $integration->id,
            ]);
            $this->call($integration, 'POST', '/v1/order/order/delivered', ['order_id' => self::id($orderId)], 'order.delivered');
        }
    }

    public function verifyDeliveryCode(Food99Integration $integration, string $orderId, string $deliveryCode): void
    {
        $this->call($integration, 'POST', '/v1/order/selfdelivery/verifyDeliveryCode', [
            'order_id' => self::id($orderId),
            'delivery_code' => $deliveryCode,
        ], 'order.selfdelivery.verifyDeliveryCode');
    }

    /**
     * Chamada com o auth_token da loja. Token expirado no meio (10102: a expiração da 99 é
     * aleatória) → renova e repete uma vez, sem passar de novo pelo limitador local.
     */
    private function call(Food99Integration $integration, string $method, string $path, array $params, string $operation): mixed
    {
        $token = $this->auth->getToken($integration);

        try {
            return $this->client->request($method, $path, ['auth_token' => $token] + $params, $operation, $integration)['data'];
        } catch (Food99TokenExpiredException) {
            $token = $this->auth->renewExpiredToken($integration, $token);

            return $this->client->request($method, $path, ['auth_token' => $token] + $params, $operation, $integration, throttle: false)['data'];
        }
    }

    /**
     * Ids da 99 vão como número no JSON (int64 cabe no int do PHP 64-bit e json_encode não usa
     * notação científica pra int). Fora disso, segue como string.
     */
    public static function id(string $id): int|string
    {
        $fitsInt = ctype_digit($id)
            && (strlen($id) < 19 || (strlen($id) === 19 && strcmp($id, (string) PHP_INT_MAX) <= 0));

        return $fitsInt ? (int) $id : $id;
    }
}
