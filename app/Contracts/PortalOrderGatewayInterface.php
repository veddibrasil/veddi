<?php

namespace App\Contracts;

use App\DTOs\PortalOrderDTO;

interface PortalOrderGatewayInterface
{
    /**
     * Busca os dados completos de um pedido no portal pelo ID externo.
     */
    public function fetchOrder(string $externalOrderId): PortalOrderDTO;

    /**
     * Confirma o recebimento do pedido junto ao portal.
     */
    public function confirmOrder(string $externalOrderId): void;

    /**
     * Recusa o pedido junto ao portal, informando o motivo.
     */
    public function rejectOrder(string $externalOrderId, string $reasonCode): void;

    /**
     * Solicita o cancelamento de um pedido já confirmado.
     */
    public function requestCancellation(string $externalOrderId, string $reasonCode): void;

    /**
     * Atualiza o status do pedido no portal (ex: pronto para retirada, despachado).
     */
    public function updateOrderStatus(string $externalOrderId, string $status): void;
}
