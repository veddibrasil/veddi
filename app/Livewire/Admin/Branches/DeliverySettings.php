<?php

namespace App\Livewire\Admin\Branches;

use App\Models\Branch;
use App\Models\DeliverySetting;
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

    public string $branch_latitude = '';

    public string $branch_longitude = '';

    public string $service_radius_km = '';

    public bool $active = true;

    public array $neighborhoods = [];

    public array $distanceTiers = [];

    protected function rules(): array
    {
        return [
            'fee_type' => ['required', 'in:flat,neighborhood,distance'],
            'flat_fee' => ['required_if:fee_type,flat', 'numeric', 'min:0'],
            'minimum_order_value' => ['numeric', 'min:0'],
            'free_delivery_above' => ['nullable', 'numeric', 'min:0'],
            'branch_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'branch_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'service_radius_km' => ['nullable', 'numeric', 'min:0'],
            'active' => ['boolean'],
            'neighborhoods.*.neighborhood' => ['required_if:fee_type,neighborhood', 'string', 'max:100'],
            'neighborhoods.*.fee' => ['required_if:fee_type,neighborhood', 'numeric', 'min:0'],
            'distanceTiers.*.min_km' => ['required_if:fee_type,distance', 'numeric', 'min:0'],
            'distanceTiers.*.max_km' => ['nullable', 'numeric', 'min:0'],
            'distanceTiers.*.fee' => ['required_if:fee_type,distance', 'numeric', 'min:0'],
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

        if (! $settings) {
            return;
        }

        $this->fee_type = $settings->fee_type;
        $this->flat_fee = (string) $settings->flat_fee;
        $this->minimum_order_value = (string) $settings->minimum_order_value;
        $this->free_delivery_above = $settings->free_delivery_above !== null ? (string) $settings->free_delivery_above : '';
        $this->branch_latitude = $settings->branch_latitude !== null ? (string) $settings->branch_latitude : '';
        $this->branch_longitude = $settings->branch_longitude !== null ? (string) $settings->branch_longitude : '';
        $this->service_radius_km = $settings->service_radius_km !== null ? (string) $settings->service_radius_km : '';
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

    public function save(): void
    {
        abort_unless($this->canSave, 403);

        $this->validate($this->rules(), $this->messages());

        $companyId = $this->branch->company_id;

        DB::transaction(function () use ($companyId) {
            $settings = DeliverySetting::updateOrCreate(
                ['branch_id' => $this->branch->id],
                [
                    'company_id' => $companyId,
                    'fee_type' => $this->fee_type,
                    'flat_fee' => (float) $this->flat_fee,
                    'minimum_order_value' => (float) $this->minimum_order_value,
                    'free_delivery_above' => $this->free_delivery_above !== '' ? (float) $this->free_delivery_above : null,
                    'branch_latitude' => $this->branch_latitude !== '' ? (float) $this->branch_latitude : null,
                    'branch_longitude' => $this->branch_longitude !== '' ? (float) $this->branch_longitude : null,
                    'service_radius_km' => $this->service_radius_km !== '' ? (float) $this->service_radius_km : null,
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

        session()->flash('status', 'Configurações de entrega salvas.');
        $this->redirect(route('admin.branches.delivery', $this->branch));
    }

    public function render()
    {
        return view('livewire.admin.branches.delivery-settings')
            ->layout('layouts.app', ['title' => 'Configurar Entrega — '.$this->branch->name]);
    }
}
