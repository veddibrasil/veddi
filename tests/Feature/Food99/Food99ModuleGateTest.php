<?php

use App\Livewire\Admin\Settings\Food99IntegrationSettings;
use App\Models\Company;
use App\Models\Food99WebhookEvent;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('bloqueia a tela e os pedidos da 99Food pra empresa no plano grátis sem módulos', function () {
    $ctx = food99Context();
    Company::whereKey($ctx['company']->id)->update(['plan' => 'free', 'pdv_module_enabled' => false, 'fiscal_notes_enabled' => false, 'waiter_module_enabled' => false]);
    $company = $ctx['company']->fresh();
    app()->instance('current.company', $company);
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    Livewire::actingAs($admin)->test(Food99IntegrationSettings::class)->assertForbidden();

    Http::fake();
    postFood99Webhook($this, food99Callback($ctx['integration'], 'orderNew', ['order_id' => 1, 'order_info' => food99OrderInfo($ctx)]))
        ->assertOk()->assertJson(['errno' => 0]);

    expect(Food99WebhookEvent::count())->toBe(0)
        ->and(Order::withoutGlobalScopes()->count())->toBe(0);
});

it('libera a 99Food com qualquer módulo contratado no plano grátis', function () {
    $ctx = food99Context();
    Company::whereKey($ctx['company']->id)->update(['plan' => 'free', 'pdv_module_enabled' => true]);

    expect($ctx['company']->fresh()->canUseMarketplaceIntegrations())->toBeTrue()
        ->and($ctx['integration']->fresh()->isOperational())->toBeTrue();
});
