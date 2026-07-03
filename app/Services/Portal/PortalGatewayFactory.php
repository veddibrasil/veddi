<?php

namespace App\Services\Portal;

use App\Contracts\PortalAvailabilityGatewayInterface;
use App\Contracts\PortalOrderGatewayInterface;
use App\Models\Portal;
use InvalidArgumentException;

class PortalGatewayFactory
{
    public function for(Portal $portal): PortalOrderGatewayInterface
    {
        return match ($portal->channel) {
            'ifood' => new IfoodService($portal),
            default => throw new InvalidArgumentException("Portal channel [{$portal->channel}] não suportado."),
        };
    }

    public function forAvailability(Portal $portal): PortalAvailabilityGatewayInterface
    {
        return match ($portal->channel) {
            'ifood' => new IfoodService($portal),
            default => throw new InvalidArgumentException("Portal channel [{$portal->channel}] não suporta controle de disponibilidade."),
        };
    }
}
