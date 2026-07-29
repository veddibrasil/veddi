<?php

use App\Livewire\Admin\Branches\DeliverySettings;
use App\Models\DeliverySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('raio de atuação é carregado e salvo na tela de configurações de entrega', function () {
    ['admin' => $admin, 'branch' => $branch, 'company' => $company] = pdvContext();

    DeliverySetting::create([
        'branch_id' => $branch->id,
        'company_id' => $company->id,
        'service_radius_km' => 8.5,
    ]);

    $this->actingAs($admin);

    Livewire::test(DeliverySettings::class, ['branch' => $branch])
        ->assertSet('service_radius_km', '8.5')
        ->set('service_radius_km', '12.3')
        ->call('save');

    expect((float) DeliverySetting::where('branch_id', $branch->id)->first()->service_radius_km)->toBe(12.3);
});

test('raio de atuação em branco salva null', function () {
    ['admin' => $admin, 'branch' => $branch] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(DeliverySettings::class, ['branch' => $branch])
        ->set('service_radius_km', '')
        ->call('save');

    expect(DeliverySetting::where('branch_id', $branch->id)->first()->service_radius_km)->toBeNull();
});
