<?php

use App\Models\EmailAutomation;
use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function marketingRoutes(): array
{
    $campaign = EmailCampaign::factory()->create();
    $template = EmailTemplate::factory()->create();
    $automation = EmailAutomation::create(['name' => 'Recuperar', 'trigger' => 'abandoned_signup']);

    return [
        route('superadmin.marketing.campaigns.index'),
        route('superadmin.marketing.campaigns.create'),
        route('superadmin.marketing.campaigns.edit', $campaign),
        route('superadmin.marketing.campaigns.show', $campaign),
        route('superadmin.marketing.campaigns.export', $campaign),
        route('superadmin.marketing.templates.index'),
        route('superadmin.marketing.templates.create'),
        route('superadmin.marketing.templates.edit', $template),
        route('superadmin.marketing.segments.index'),
        route('superadmin.marketing.automations.index'),
        route('superadmin.marketing.automations.create'),
        route('superadmin.marketing.automations.edit', $automation),
        route('superadmin.marketing.suppressions.index'),
    ];
}

test('usuário sem is_super_admin recebe 403 em todas as rotas do módulo', function () {
    $user = User::factory()->create(['is_super_admin' => false]);

    foreach (marketingRoutes() as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }
});

test('company_admin de uma empresa não chega ao módulo', function () {
    $admin = marketingMember(role: 'company_admin');

    foreach (marketingRoutes() as $url) {
        $this->actingAs($admin)->get($url)->assertForbidden();
    }
});

test('visitante é mandado para o login', function () {
    $this->get(route('superadmin.marketing.campaigns.index'))->assertRedirect(route('login'));
});

test('super admin abre todas as telas do módulo', function () {
    marketingSetup();
    $superAdmin = marketingSuperAdmin();

    foreach (marketingRoutes() as $url) {
        $response = $this->actingAs($superAdmin)->get($url);

        expect($response->getStatusCode())->toBe(200, "Falhou em {$url}");
    }
});

test('menu do super admin mostra o item de e-mail marketing', function () {
    $this->actingAs(marketingSuperAdmin())
        ->get(route('superadmin.marketing.campaigns.index'))
        ->assertSee('E-mail marketing');
});

test('ação Livewire do módulo é barrada para quem perdeu o super admin', function () {
    marketingSetup();
    $superAdmin = marketingSuperAdmin();
    $campaign = EmailCampaign::factory()->create();

    $component = Livewire\Livewire::actingAs($superAdmin)->test(\App\Livewire\SuperAdmin\Marketing\Campaigns\Index::class);

    $superAdmin->update(['is_super_admin' => false]);

    $component->call('duplicate', $campaign->id)->assertForbidden();
    expect(EmailCampaign::count())->toBe(1);
});
