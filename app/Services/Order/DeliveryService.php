<?php

namespace App\Services\Order;

use App\Exceptions\DeliveryException;
use App\Models\DeliverySetting;
use Illuminate\Support\Facades\Cache;

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

        $distanceKm = $this->resolveDistance($settings, $customerLat, $customerLng);

        $this->validateServiceRadius($settings, $distanceKm);

        $fee = $this->resolveFee($settings, $neighborhood, $distanceKm, $customerLat, $customerLng);

        $free = false;
        if ($settings->free_delivery_above !== null && $subtotal >= $settings->free_delivery_above) {
            $fee = 0.0;
            $free = true;
        }

        return ['fee' => $fee, 'free' => $free];
    }

    private function resolveDistance(DeliverySetting $settings, ?float $customerLat, ?float $customerLng): ?float
    {
        if (
            $settings->branch_latitude === null
            || $settings->branch_longitude === null
            || $customerLat === null
            || $customerLng === null
        ) {
            return null;
        }

        return $this->haversineDistance(
            $settings->branch_latitude,
            $settings->branch_longitude,
            $customerLat,
            $customerLng
        );
    }

    private function validateServiceRadius(DeliverySetting $settings, ?float $distanceKm): void
    {
        if ($settings->service_radius_km === null || $settings->service_radius_km <= 0) {
            return;
        }

        if ($distanceKm === null) {
            return;
        }

        if ($distanceKm <= $settings->service_radius_km) {
            return;
        }

        $km = number_format($distanceKm, 1, ',', '.');
        $max = number_format($settings->service_radius_km, 1, ',', '.');
        throw new DeliveryException("Seu endereço está fora da área de entrega ({$km} km). Raio máximo: {$max} km.");
    }

    private function resolveFee(
        DeliverySetting $settings,
        string $neighborhood,
        ?float $distanceKm,
        ?float $customerLat,
        ?float $customerLng
    ): float {
        return match ($settings->fee_type) {
            'flat' => (float) $settings->flat_fee,
            'neighborhood' => $this->feeByNeighborhood($settings, $neighborhood),
            'distance' => $this->feeByDistance($settings, $distanceKm),
            'zone' => $this->feeByZone($settings, $customerLat, $customerLng),
            default => 0.0,
        };
    }

    private function feeByNeighborhood(DeliverySetting $settings, string $neighborhood): float
    {
        $neighborhoods = Cache::remember(
            "delivery:neighborhoods:settings:{$settings->id}",
            now()->addMinutes(10),
            fn () => $settings->neighborhoods()->where('active', true)->get()
        );

        $match = $neighborhoods->first(
            fn ($n) => mb_strtolower(trim($n->neighborhood)) === mb_strtolower(trim($neighborhood))
        );

        if (! $match) {
            throw new DeliveryException('Seu bairro não está na área de cobertura desta filial.');
        }

        return (float) $match->fee;
    }

    private function feeByDistance(DeliverySetting $settings, ?float $distanceKm): float
    {
        if ($settings->branch_latitude === null || $settings->branch_longitude === null) {
            throw new DeliveryException('Entrega por distância não configurada para esta filial. Entre em contato com a loja.');
        }

        if ($distanceKm === null) {
            throw new DeliveryException('Não foi possível calcular a distância de entrega. Entre em contato com a loja.');
        }

        $tiers = Cache::remember(
            "delivery:distance_tiers:settings:{$settings->id}",
            now()->addMinutes(10),
            fn () => $settings->distanceTiers()->get()
        );

        $tier = $tiers->first(function ($t) use ($distanceKm) {
            $aboveMin = $distanceKm >= $t->min_km;
            $belowMax = $t->max_km === null || $distanceKm <= $t->max_km;

            return $aboveMin && $belowMax;
        });

        if (! $tier) {
            $km = number_format($distanceKm, 1, ',', '.');
            throw new DeliveryException("Seu endereço está fora da área de entrega ({$km} km).");
        }

        return (float) $tier->fee;
    }

    private function feeByZone(DeliverySetting $settings, ?float $lat, ?float $lng): float
    {
        if ($lat === null || $lng === null) {
            throw new DeliveryException('Não foi possível calcular a taxa de entrega para sua localização. Entre em contato com a loja.');
        }

        foreach (($settings->zones ?? []) as $zone) {
            if (! ($zone['active'] ?? true)) {
                continue;
            }

            if ($this->pointInPolygon($lat, $lng, $zone['polygon'] ?? [])) {
                return (float) $zone['fee'];
            }
        }

        throw new DeliveryException('Seu endereço está fora da área de entrega desta filial.');
    }

    /**
     * Ray-casting (PNPOLY) — testa se o ponto está dentro do polígono (array de pares [lat, lng]).
     */
    private function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $count = count($polygon);
        if ($count < 3) {
            return false;
        }

        $inside = false;
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$latI, $lngI] = $polygon[$i];
            [$latJ, $lngJ] = $polygon[$j];

            $intersects = (($lngI > $lng) !== ($lngJ > $lng))
                && ($lat < ($latJ - $latI) * ($lng - $lngI) / ($lngJ - $lngI) + $latI);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
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
