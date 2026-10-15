<?php

use App\Livewire\Admin\Settings\WhatsAppSettings;
use App\Models\Company;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function whatsappSettingsCompany(string $role = 'company_admin'): array
{
    $company = Company::create([
        'name' => 'Empresa Settings WhatsApp',
        'slug' => 'settings-whatsapp-'.uniqid(),
        'order_prefix' => 'SWA',
        'active' => true,
        'status' => 'ACTIVE',
    ]);

    app()->instance('current.company', $company);

    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => $role]);

    return [$company, $user];
}

test('company_admin acessa a tela de notificações WhatsApp', function () {
    [, $admin] = whatsappSettingsCompany();

    $this->actingAs($admin)
        ->get(route('admin.settings.whatsapp'))
        ->assertOk()
        ->assertSee('Saiu para entrega')
        ->assertSee('Pedido agendado')
        ->assertDontSee('Nenhum provedor de WhatsApp está configurado');
});

test('branch_manager não acessa a tela de notificações WhatsApp', function () {
    [, $manager] = whatsappSettingsCompany('branch_manager');

    $this->actingAs($manager)
        ->get(route('admin.settings.whatsapp'))
        ->assertForbidden();
});

test('visitante é redirecionado para o login', function () {
    whatsappSettingsCompany();

    $this->get(route('admin.settings.whatsapp'))->assertRedirect(route('login'));
});

test('novos toggles começam ligados e os padrões antigos são mantidos', function () {
    [, $admin] = whatsappSettingsCompany();

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->assertSet('enabled', false)
        ->assertSet('notifyOnScheduled', true)
        ->assertSet('notifyOnOutForDelivery', true)
        ->assertSet('notifyOnReady', true)
        ->assertSet('notifyOnAdminMessage', false);
});

test('salvar persiste os toggles novos separando "Pronto" de "Saiu para entrega"', function () {
    [$company, $admin] = whatsappSettingsCompany();
    WhatsAppConnection::factory()->forCompany($company)->create();

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->set('enabled', true)
        ->set('notifyOnReady', true)
        ->set('notifyOnOutForDelivery', false)
        ->set('notifyOnScheduled', false)
        ->call('save')
        ->assertRedirect(route('admin.settings.whatsapp'));

    $settings = WhatsAppSetting::where('company_id', $company->id)->first();

    expect($settings->enabled)->toBeTrue()
        ->and($settings->notify_on_ready)->toBeTrue()
        ->and($settings->notify_on_out_for_delivery)->toBeFalse()
        ->and($settings->notify_on_scheduled)->toBeFalse();
});

test('a tela carrega as configurações já salvas da empresa', function () {
    [$company, $admin] = whatsappSettingsCompany();

    WhatsAppSetting::create([
        'company_id' => $company->id,
        'enabled' => true,
        'notify_on_out_for_delivery' => false,
        'notify_on_scheduled' => false,
    ]);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->assertSet('enabled', true)
        ->assertSet('notifyOnOutForDelivery', false)
        ->assertSet('notifyOnScheduled', false);
});

test('salvar só altera as configurações da própria empresa', function () {
    [$company, $admin] = whatsappSettingsCompany();
    WhatsAppConnection::factory()->forCompany($company)->create();

    $outra = Company::create([
        'name' => 'Outra', 'slug' => 'outra-'.uniqid(), 'order_prefix' => 'OUT', 'active' => true,
    ]);
    $settingsOutra = WhatsAppSetting::create(['company_id' => $outra->id, 'enabled' => false]);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->set('enabled', true)
        ->call('save');

    expect($settingsOutra->fresh()->enabled)->toBeFalse();
});

test('salvar é restrito a quem pode configurar a empresa', function () {
    [$company, $manager] = whatsappSettingsCompany('branch_manager');
    WhatsAppConnection::factory()->forCompany($company)->create();

    Livewire::actingAs($manager)
        ->test(WhatsAppSettings::class)
        ->set('enabled', true)
        ->call('save')
        ->assertForbidden();

    expect(WhatsAppSetting::where('company_id', $company->id)->exists())->toBeFalse();
});

test('salvar não grava nada sem conexão ativa nem fallback (mesmo critério dos toggles da tela)', function (?string $status) {
    [$company, $admin] = whatsappSettingsCompany();
    config(['services.whatsapp.fallback_to_platform' => false]);

    if ($status !== null) {
        WhatsAppConnection::factory()->forCompany($company)->status($status)->create();
    }

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->set('enabled', true)
        ->call('save')
        ->assertNoRedirect();

    expect(WhatsAppSetting::where('company_id', $company->id)->exists())->toBeFalse();
})->with([
    'sem conexão' => [null],
    'aguardando templates' => [WhatsAppConnection::STATUS_TEMPLATES_PENDING],
    'com erro' => [WhatsAppConnection::STATUS_ERROR],
    'desconectada' => [WhatsAppConnection::STATUS_DISCONNECTED],
]);

test('salvar funciona sem conexão quando o fallback da plataforma está ligado', function () {
    [$company, $admin] = whatsappSettingsCompany();
    config([
        'services.whatsapp.fallback_to_platform' => true,
        'services.whatsapp.platform.phone_number_id' => '999000111',
        'services.whatsapp.platform.token' => 'token-da-plataforma',
    ]);

    Livewire::actingAs($admin)
        ->test(WhatsAppSettings::class)
        ->set('enabled', true)
        ->call('save')
        ->assertRedirect(route('admin.settings.whatsapp'));

    expect(WhatsAppSetting::where('company_id', $company->id)->value('enabled'))->toBeTrue();
});
