<?php

namespace App\Services\Order;

use App\Exceptions\DeliveryException;
use App\Models\DeliverySetting;

class DeliveryService
{
    /**
     * Valida a área de cobertura e calcula a taxa de entrega.
     *
     * @return array{fee: float, free: bool}
     *
     * @throws DeliveryException
     */
    public function validate(
        DeliverySetting $settings,
        string $neighborhood,
        float $subtotal,
        ?float $customerLat = null,
        ?float $customerLng = null
    ): array {
        if ($subtotal < $settings->minimum_order_value && $settings->minimum_order_value > 0) {
            $min = number_format($settings->minimum_order_value, 2, ',', '.');
            throw new DeliveryException("Pedido mínimo para entrega é R$ {$min}.");
        }

        $fee = $this->resolveFee($settings, $neighborhood, $customerLat, $customerLng);

        $free = false;
        if ($settings->free_delivery_above !== null && $subtotal >= $settings->free_delivery_above) {
            $fee = 0.0;
            $free = true;
        }

        return ['fee' => $fee, 'free' => $free];
    }

    private function resolveFee(
        DeliverySetting $settings,
        string $neighborhood,
        ?float $customerLat,
        ?float $customerLng
    ): float {
        return match ($settings->fee_type) {
            'flat' => (float) $settings->flat_fee,
            'neighborhood' => $this->feeByNeighborhood($settings, $neighborhood),
            'distance' => $this->feeByDistance($settings, $customerLat, $customerLng),
            default => 0.0,
        };
    }

    private function feeByNeighborhood(DeliverySetting $settings, string $neighborhood): float
    {
        $match = $settings->neighborhoods()
            ->where('active', true)
            ->get()
            ->first(fn ($n) => mb_strtolower(trim($n->neighborhood)) === mb_strtolower(trim($neighborhood)));

        if (! $match) {
            throw new DeliveryException('Seu bairro não está na área de cobertura desta filial.');
        }

        return (float) $match->fee;
    }

    private function feeByDistance(DeliverySetting $settings, ?float $customerLat, ?float $customerLng): float
    {
        if (! $settings->branch_latitude || ! $settings->branch_longitude) {
            throw new DeliveryException('Entrega por distância não configurada para esta filial. Entre em contato com a loja.');
        }

        if ($customerLat === null || $customerLng === null) {
            throw new DeliveryException('Não foi possível calcular a distância de entrega. Entre em contato com a loja.');
        }

        $distanceKm = $this->haversineDistance(
            $settings->branch_latitude,
            $settings->branch_longitude,
            $customerLat,
            $customerLng
        );

        $tier = $settings->distanceTiers()
            ->get()
            ->first(function ($t) use ($distanceKm) {
                $aboveMin = $distanceKm >= $t->min_km;
                $belowMax = $t->max_km === null || $distanceKm < $t->max_km;

                return $aboveMin && $belowMax;
            });

        if (! $tier) {
            $km = number_format($distanceKm, 1, ',', '.');
            throw new DeliveryException("Seu endereço está fora da área de entrega ({$km} km).");
        }

        return (float) $tier->fee;
    }

    /**
     * Fórmula Haversine — distância em km entre dois pontos lat/lng.
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * asin(sqrt($a));
    }
}
