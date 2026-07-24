<?php

namespace App\Livewire\Admin\Pdv\Concerns;

use App\Exceptions\DeliveryException;
use App\Models\Branch;
use App\Services\Order\DeliveryService;
use App\Services\Order\GeocodingService;

trait HasDeliveryFee
{
    /** @return array<int, string> */
    private function deliveryAddressErrors(): array
    {
        $errors = [];
        if (mb_strlen(trim($this->deliveryAddress)) < 5) {
            $errors[] = 'Endereço é obrigatório.';
        }
        if (blank($this->deliveryNumber)) {
            $errors[] = 'Número é obrigatório.';
        }
        if (blank($this->deliveryNeighborhood)) {
            $errors[] = 'Bairro é obrigatório.';
        }
        if (blank($this->deliveryCity)) {
            $errors[] = 'Cidade é obrigatória.';
        }
        if (! preg_match('/^\d{5}-?\d{3}$/', $this->deliveryCep)) {
            $errors[] = 'CEP inválido.';
        }

        return $errors;
    }

    public function updatedDeliveryCep(): void
    {
        $this->maybeAutoCalculateDeliveryFee();
    }

    public function updatedDeliveryAddress(): void
    {
        $this->maybeAutoCalculateDeliveryFee();
    }

    public function updatedDeliveryNumber(): void
    {
        $this->maybeAutoCalculateDeliveryFee();
    }

    public function updatedDeliveryNeighborhood(): void
    {
        $this->maybeAutoCalculateDeliveryFee();
    }

    public function updatedDeliveryCity(): void
    {
        $this->maybeAutoCalculateDeliveryFee();
    }

    /** Recalcula a taxa em silêncio enquanto o operador ainda preenche o endereço — só mostra erro quando os campos já estão completos. */
    private function maybeAutoCalculateDeliveryFee(): void
    {
        if ($this->deliveryAddressErrors() !== []) {
            $this->deliveryFeeAmount = 0.0;
            $this->deliveryFeeError = null;

            return;
        }

        $this->calculateDeliveryFee();
    }

    public function calculateDeliveryFee(): void
    {
        $this->deliveryFeeError = null;
        $this->deliveryFeeAmount = 0.0;

        $errors = $this->deliveryAddressErrors();

        if ($errors !== []) {
            $this->deliveryFeeError = implode(' ', $errors);

            return;
        }

        $branch = Branch::find($this->selectedBranchId);
        $settings = $branch?->deliverySetting;

        if (! $settings) {
            $this->deliveryFeeError = 'Esta filial não possui configuração de entrega.';

            return;
        }

        $lat = null;
        $lng = null;

        if ($settings->fee_type === 'distance') {
            $coords = app(GeocodingService::class)->geocode([
                'address' => $this->deliveryAddress,
                'number' => $this->deliveryNumber,
                'neighborhood' => $this->deliveryNeighborhood,
                'city' => $this->deliveryCity,
                'cep' => $this->deliveryCep,
            ]);

            if (! $coords) {
                $this->deliveryFeeError = 'Não foi possível localizar o endereço para calcular a distância.';

                return;
            }

            $lat = $coords['latitude'];
            $lng = $coords['longitude'];
        }

        try {
            $result = app(DeliveryService::class)->validate($settings, $this->deliveryNeighborhood, $this->cartTotal, $lat, $lng);
            $this->deliveryFeeAmount = (float) $result['fee'];
        } catch (DeliveryException $e) {
            $this->deliveryFeeAmount = 0.0;
            $this->deliveryFeeError = $e->getMessage();
        }
    }

    private function resetDeliveryState(): void
    {
        $this->deliveryType = 'balcao';
        $this->deliveryAddress = '';
        $this->deliveryNumber = '';
        $this->deliveryComplement = '';
        $this->deliveryNeighborhood = '';
        $this->deliveryCity = '';
        $this->deliveryCep = '';
        $this->deliveryFeeAmount = 0.0;
        $this->deliveryFeeError = null;
        $this->deliveryPaymentStatus = 'paid';
    }
}
