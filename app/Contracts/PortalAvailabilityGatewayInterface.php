<?php

namespace App\Contracts;

interface PortalAvailabilityGatewayInterface
{
    /**
     * Pausa temporariamente o recebimento de pedidos pelo portal (ex: cozinha
     * sobrecarregada). Sem $minutes, usa a duração padrão do gateway.
     */
    public function pauseReceivingOrders(?int $minutes = null, ?string $reason = null): void;

    /**
     * Encerra a pausa ativa e volta a receber pedidos normalmente.
     */
    public function resumeReceivingOrders(): void;
}
