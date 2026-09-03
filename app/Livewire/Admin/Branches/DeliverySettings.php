<?php

namespace App\Livewire\Admin\Branches;

use App\Models\Branch;
use App\Models\DeliverySetting;
use App\Services\Order\GeocodingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class DeliverySettings extends Component
{
    public Branch $branch;

    public bool $canSave = false;

    public string $fee_type = 'flat';

    public string $flat_fee = '0';

    public string $minimum_order_value = '0';

    public string $free_delivery_above = '';

    public string $service_radius_km = '';

    public string $branch_latitude = '';

    public string $branch_longitude = '';

    public bool $active = true;

    public array $neighborhoods = [];

    public array $distanceTiers = [];

    public array $zones = [];

    protected function rules(): array
    {
        return [
            'fee_type' => ['required', 'in:flat,neighborhood,distance,zone'],
            'flat_fee' => ['required_if:fee_type,flat', 'numeric', 'min:0'],
            'minimum_order_value' => ['numeric', 'min:0'],
            'free_delivery_above' => ['nullable', 'numeric', 'min:0'],
            'service_radius_km' => ['nullable', 'numeric', 'min:0'],
            'branch_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'branch_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'active' => ['boolean'],
            'neighborhoods.*.neighborhood' => ['required_if:fee_type,neighborhood', 'string', 'max:100'],
            'neighborhoods.*.fee' => ['required_if:fee_type,neighborhood', 'numeric', 'min:0'],
            'distanceTiers.*.min_km' => ['required_if:fee_type,distance', 'numeric', 'min:0'],
            'distanceTiers.*.max_km' => ['nullable', 'numeric', 'min:0'],
            'distanceTiers.*.fee' => ['required_if:fee_type,distance', 'numeric', 'min:0'],
            'zones.*.name' => ['required_if:fee_type,zone', 'string', 'max:100'],
            'zones.*.fee' => ['required_if:fee_type,zone', 'numeric', 'min:0'],
        ];
    }

    protected function messages(): array
    {
        return [
            'flat_fee.required_if' => 'Informe a taxa fixa.',
            'neighborhoods.*.neighborhood.required_if' => 'Informe o nome do bairro.',
            'neighborhoods.*.fee.required_if' => 'Informe a taxa do bairro.',
            'distanceTiers.*.min_km.required_if' => 'Informe a distância mínima.',
            'distanceTiers.*.fee.required_if' => 'Informe a taxa da faixa.',
            'zones.*.name.required_if' => 'Informe o nome da área.',
            'zones.*.fee.required_if' => 'Informe a taxa da área.',
        ];
    }

    public function mount(Branch $branch): void
    {
        $this->branch = $branch;

        $user = auth()->user();
        if ($user->isSuperAdmin()) {
            $this->canSave = true;
        } elseif (app()->bound('current.company')) {
            $this->canSave = $user->hasPermission('branches.update', app('current.company'));
        }

        $settings = $branch->deliverySetting;

        if ($settings) {
            $this->fee_type = $settings->fee_type;
            $this->flat_fee = (string) $settings->flat_fee;
            $this->minimum_order_value = (string) $settings->minimum_order_value;
            $this->free_delivery_above = $settings->free_delivery_above !== null ? (string) $settings->free_delivery_above : '';
            $this->service_radius_km = $settings->service_radius_km !== null ? (string) $settings->service_radius_km : '';
            $this->branch_latitude = $settings->branch_latitude !== null ? (string) $settings->branch_latitude : '';
            $this->branch_longitude = $settings->branch_longitude !== null ? (string) $settings->branch_longitude : '';
            $this->active = $settings->active;

            $this->neighborhoods = $settings->neighborhoods->map(fn ($n) => [
                'neighborhood' => $n->neighborhood,
                'fee' => (string) $n->fee,
                'active' => $n->active,
            ])->values()->toArray();

            $this->distanceTiers = $settings->distanceTiers->map(fn ($t) => [
                'min_km' => (string) $t->min_km,
                'max_km' => $t->max_km !== null ? (string) $t->max_km : '',
                'fee' => (string) $t->fee,
            ])->values()->toArray();

            $this->zones = $settings->zones ?? [];
        }

        if ($this->branch_latitude === '' && $this->branch_longitude === '') {
            $this->geocodeFromBranchAddress();
        }
    }

    /**
     * Preenche a coordenada do mapa a partir do endereço cadastrado da filial,
     * só quando a filial ainda não tem lat/lng salvos manualmente.
     */
    private function geocodeFromBranchAddress(): void
    {
        $address = $this->branch->addressRecord;

        if (! $address || ! $address->city) {
            return;
        }

        if ($address->latitude !== null && $address->longitude !== null) {
            $this->branch_latitude = (string) $address->latitude;
            $this->branch_longitude = (string) $address->longitude;

            return;
        }

        $result = app(GeocodingService::class)->geocode([
            'address' => $address->line1 ?? '',
            'number' => $address->number ?? '',
            'neighborhood' => $address->neighborhood ?? '',
            'city' => $address->city ?? '',
            'state' => $address->state ?? '',
            'cep' => $address->cep ?? '',
        ]);

        if ($result) {
            $this->branch_latitude = (string) $result['latitude'];
            $this->branch_longitude = (string) $result['longitude'];
        }
    }

    public function addNeighborhood(): void
    {
        $this->neighborhoods[] = ['neighborhood' => '', 'fee' => '', 'active' => true];
    }

    public function removeNeighborhood(int $index): void
    {
        array_splice($this->neighborhoods, $index, 1);
        $this->neighborhoods = array_values($this->neighborhoods);
    }

    public function addDistanceTier(): void
    {
        $this->distanceTiers[] = ['min_km' => '', 'max_km' => '', 'fee' => ''];
    }

    public function removeDistanceTier(int $index): void
    {
        array_splice($this->distanceTiers, $index, 1);
        $this->distanceTiers = array_values($this->distanceTiers);
    }

    public function addZoneFromDraw(string $name, string $fee, array $polygon): void
    {
        if (count($polygon) < 3) {
            return;
        }

        $this->zones[] = [
            'name' => $name,
            'fee' => $fee,
            'active' => true,
            'polygon' => $polygon,
        ];
    }

    public function removeZone(int $index): void
    {
        array_splice($this->zones, $index, 1);
        $this->zones = array_values($this->zones);
    }

    public function moveZoneUp(int $index): void
    {
        if ($index <= 0 || $index >= count($this->zones)) {
            return;
        }

        [$this->zones[$index - 1], $this->zones[$index]] = [$this->zones[$index], $this->zones[$index - 1]];
    }

    public function moveZoneDown(int $index): void
    {
        if ($index < 0 || $index >= count($this->zones) - 1) {
            return;
        }

        [$this->zones[$index + 1], $this->zones[$index]] = [$this->zones[$index], $this->zones[$index + 1]];
    }

    /**
     * Garante que as faixas de distância comecem em 0 km e não tenham lacunas entre si,
     * senão endereços muito próximos da filial (ex.: mesma rua) ficam sem faixa e são
     * recusados como "fora da área de entrega".
     */
    private function distanceTiersAreContiguous(): bool
    {
        $tiers = collect($this->distanceTiers)
            ->map(fn ($t) => ['min_km' => (float) $t['min_km'], 'max_km' => $t['max_km'] !== '' ? (float) $t['max_km'] : null])
            ->sortBy('min_km')
            ->values();

        if ($tiers->isEmpty()) {
            $this->addError('distanceTiers', 'Adicione ao menos uma faixa de distância.');

            return false;
        }

        if ($tiers->first()['min_km'] !== 0.0) {
            $this->addError('distanceTiers', 'A primeira faixa de distância precisa começar em 0 km, senão endereços muito próximos da filial ficam fora da área de entrega.');

            return false;
        }

        foreach ($tiers as $index => $tier) {
            $next = $tiers->get($index + 1);

            if ($next === null) {
                continue;
            }

            if ($tier['max_km'] === null) {
                $this->addError('distanceTiers', 'Só a última faixa de distância pode ficar sem km máximo.');

                return false;
            }

            if ($tier['max_km'] !== $next['min_km']) {
                $this->addError('distanceTiers', 'As faixas de distância precisam ser contínuas, sem lacunas entre elas.');

                return false;
            }
        }

        return true;
    }

    public function save(): void
    {
        abort_unless($this->canSave, 403);

        $this->validate($this->rules(), $this->messages());

        if ($this->fee_type === 'zone') {
            foreach ($this->zones as $zone) {
                if (count($zone['polygon'] ?? []) < 3) {
                    $this->addError('zones', 'Toda área precisa ter um polígono com pelo menos 3 pontos.');

                    return;
                }
            }
        }

        if ($this->fee_type === 'distance' && ! $this->distanceTiersAreContiguous()) {
            return;
        }

        $companyId = $this->branch->company_id;

        $settings = null;
        DB::transaction(function () use ($companyId, &$settings) {
            $settings = DeliverySetting::updateOrCreate(
                ['branch_id' => $this->branch->id],
                [
                    'company_id' => $companyId,
                    'fee_type' => $this->fee_type,
                    'flat_fee' => (float) $this->flat_fee,
                    'minimum_order_value' => (float) $this->minimum_order_value,
                    'free_delivery_above' => $this->free_delivery_above !== '' ? (float) $this->free_delivery_above : null,
                    'service_radius_km' => $this->service_radius_km !== '' ? (float) $this->service_radius_km : null,
                    'branch_latitude' => $this->branch_latitude !== '' ? (float) $this->branch_latitude : null,
                    'branch_longitude' => $this->branch_longitude !== '' ? (float) $this->branch_longitude : null,
                    'zones' => array_map(fn ($z) => [
                        'name' => $z['name'],
                        'fee' => (float) $z['fee'],
                        'active' => $z['active'] ?? true,
                        'polygon' => $z['polygon'],
                    ], $this->zones),
                    'active' => $this->active,
                ]
            );

            $settings->neighborhoods()->delete();
            foreach ($this->neighborhoods as $n) {
                $settings->neighborhoods()->create([
                    'neighborhood' => $n['neighborhood'],
                    'fee' => (float) $n['fee'],
                    'active' => $n['active'] ?? true,
                ]);
            }

            $settings->distanceTiers()->delete();
            foreach ($this->distanceTiers as $t) {
                $settings->distanceTiers()->create([
                    'min_km' => (float) $t['min_km'],
                    'max_km' => $t['max_km'] !== '' ? (float) $t['max_km'] : null,
                    'fee' => (float) $t['fee'],
                ]);
            }
        });

        Cache::forget("branches:company:{$this->branch->company_id}");
        if ($settings) {
            Cache::forget("delivery:neighborhoods:settings:{$settings->id}");
            Cache::forget("delivery:distance_tiers:settings:{$settings->id}");
        }

        session()->flash('status', 'Configurações de entrega salvas.');
        $this->redirect(route('admin.branches.delivery', $this->branch));
    }

    public function render()
    {
        return view('livewire.admin.branches.delivery-settings')
            ->layout('layouts.app', ['title' => 'Configurar Entrega — '.$this->branch->name]);
    }
}
