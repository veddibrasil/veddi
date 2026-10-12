<?php

use App\Jobs\ProvisionWhatsAppTemplates;
use App\Livewire\SuperAdmin\WhatsApp\Connections;
use App\Models\Company;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function whatsappSuperAdmin(): User
{
    return User::factory()->create(['is_super_admin' => true]);
}

function whatsappConnectionFor(string $companyName, string $status = WhatsAppConnection::STATUS_ACTIVE, array $attributes = []): WhatsAppConnection
{
    $company = Company::create([
        'name' => $companyName,
        'slug' => 'sa-wpp-'.uniqid(),
        'order_prefix' => 'SAW',
        'active' => true,
        'status' => 'ACTIVE',
    ]);

    return WhatsAppConnection::factory()->forCompany($company)->status($status)->create($attributes);
}

test('super admin vê as conexões de todas as empresas com número, status, qualidade, tipo e último erro', function () {
    whatsappConnectionFor('Coxinhas do Zé', WhatsAppConnection::STATUS_ACTIVE, [
        'display_phone_number' => '+55 44 99999-1111', 'verified_name' => 'Coxinhas do Zé LTDA', 'quality_rating' => 'GREEN',
    ]);
    whatsappConnectionFor('Pastelaria da Ana', WhatsAppConnection::STATUS_ERROR, [
        'display_phone_number' => '+55 11 98888-2222', 'quality_rating' => 'RED', 'last_error' => 'A autorização do WhatsApp foi recusada pela Meta. Refaça a conexão.',
    ]);
    $coex = whatsappConnectionFor('Lanches do Beto', WhatsAppConnection::STATUS_TEMPLATES_PENDING);
    $coex->update(['onboarding_type' => WhatsAppConnection::TYPE_COEXISTENCE]);

    $this->actingAs(whatsappSuperAdmin())
        ->get(route('superadmin.whatsapp.index'))
        ->assertOk()
        ->assertSee('Coxinhas do Zé')
        ->assertSee('+55 44 99999-1111')
        ->assertSee('Coxinhas do Zé LTDA')
        ->assertSee('Ativa')
        ->assertSee('Pastelaria da Ana')
        ->assertSee('Erro')
        ->assertSee('A autorização do WhatsApp foi recusada pela Meta')
        ->assertSee('RED')
        ->assertSee('Lanches do Beto')
        ->assertSee('Aguardando templates')
        ->assertSee('Coexistência')
        ->assertSee('Número novo');
});

test('a lista conta os templates aprovados de cada conexão', function () {
    $connection = whatsappConnectionFor('Empresa Templates');
    WhatsAppTemplate::factory()->forEvent('new_order')->create(['whatsapp_connection_id' => $connection->id]);
    WhatsAppTemplate::factory()->forEvent('paid')->create(['whatsapp_connection_id' => $connection->id]);
    WhatsAppTemplate::factory()->forEvent('scheduled')->status(WhatsAppTemplate::STATUS_PENDING)->create(['whatsapp_connection_id' => $connection->id]);

    $total = count(config('whatsapp_templates.templates'));

    Livewire::actingAs(whatsappSuperAdmin())
        ->test(Connections::class)
        ->assertSeeText("2/{$total}");
});

test('a lista filtra por empresa, número e status', function () {
    whatsappConnectionFor('Coxinhas do Zé', WhatsAppConnection::STATUS_ACTIVE, ['display_phone_number' => '+55 44 99999-1111']);
    whatsappConnectionFor('Pastelaria da Ana', WhatsAppConnection::STATUS_ERROR, ['display_phone_number' => '+55 11 98888-2222']);

    Livewire::actingAs(whatsappSuperAdmin())
        ->test(Connections::class)
        ->set('search', 'Pastelaria')
        ->assertSee('Pastelaria da Ana')
        ->assertDontSee('Coxinhas do Zé')
        ->set('search', '99999-1111')
        ->assertSee('Coxinhas do Zé')
        ->assertDontSee('Pastelaria da Ana')
        ->set('search', '')
        ->set('status', WhatsAppConnection::STATUS_ERROR)
        ->assertSee('Pastelaria da Ana')
        ->assertDontSee('Coxinhas do Zé');
});

test('a lista nunca expõe token nem PIN das conexões', function () {
    whatsappConnectionFor('Empresa Segredo', WhatsAppConnection::STATUS_ACTIVE, [
        'access_token' => 'EAAB-token-ultra-secreto', 'registration_pin' => '918273',
    ]);

    $component = Livewire::actingAs(whatsappSuperAdmin())->test(Connections::class);

    $component->assertDontSeeHtml('EAAB-token-ultra-secreto')->assertDontSeeHtml('918273');

    expect(json_encode($component->snapshot))->not->toContain('EAAB-token-ultra-secreto');
});

test('sincronizar templates enfileira o provisionamento da conexão', function (string $status) {
    Queue::fake();
    $connection = whatsappConnectionFor('Empresa Sync', $status);

    Livewire::actingAs(whatsappSuperAdmin())
        ->test(Connections::class)
        ->call('syncTemplates', $connection->id)
        ->assertSet('notice', 'Sincronização de templates enfileirada. O status é atualizado em instantes.');

    Queue::assertPushed(ProvisionWhatsAppTemplates::class, fn ($job) => $job->connectionId === $connection->id);
})->with([
    'ativa' => WhatsAppConnection::STATUS_ACTIVE,
    'provisionando' => WhatsAppConnection::STATUS_PROVISIONING,
    'aguardando templates' => WhatsAppConnection::STATUS_TEMPLATES_PENDING,
]);

test('sincronizar templates não enfileira conexão sem autorização da Meta', function (string $status, ?string $token) {
    Queue::fake();
    $connection = whatsappConnectionFor('Empresa Sem Sync', $status);
    // access_token é sobrescrito no update para não passar pelo default do factory.
    $connection->forceFill(['access_token' => $token])->save();

    Livewire::actingAs(whatsappSuperAdmin())
        ->test(Connections::class)
        ->call('syncTemplates', $connection->id)
        ->assertSet('notice', 'Esta conexão não pode ser sincronizada agora (sem autorização ativa da Meta).');

    Queue::assertNothingPushed();
})->with([
    'desconectada' => [WhatsAppConnection::STATUS_DISCONNECTED, null],
    'com erro' => [WhatsAppConnection::STATUS_ERROR, null],
    'ativa mas sem token' => [WhatsAppConnection::STATUS_ACTIVE, null],
]);

test('só o botão de sincronizar das conexões elegíveis aparece', function () {
    whatsappConnectionFor('Empresa Ativa', WhatsAppConnection::STATUS_ACTIVE);

    Livewire::actingAs(whatsappSuperAdmin())
        ->test(Connections::class)
        ->assertSee('Sincronizar templates');

    WhatsAppConnection::query()->withoutGlobalScopes()->update(['status' => WhatsAppConnection::STATUS_DISCONNECTED]);

    Livewire::actingAs(whatsappSuperAdmin())
        ->test(Connections::class)
        ->assertDontSee('Sincronizar templates');
});

test('admin de empresa e visitante não acessam a lista de conexões', function () {
    $company = Company::create(['name' => 'Empresa X', 'slug' => 'x-'.uniqid(), 'order_prefix' => 'XXX', 'active' => true, 'status' => 'ACTIVE']);
    app()->instance('current.company', $company);
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $this->actingAs($admin)->get(route('superadmin.whatsapp.index'))->assertForbidden();

    auth()->logout();

    $this->get(route('superadmin.whatsapp.index'))->assertRedirect(route('login'));
});
