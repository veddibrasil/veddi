<?php

/*
 * AUDITORIA (qualidade) — regressões encontradas na revisão do diff das correções e corrigidas.
 */

require_once __DIR__.'/../Flows/flows_helpers.php';

use App\Events\OrderItemsUpdated;
use App\Events\OrderStatusUpdated;
use App\Livewire\Admin\Orders\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([OrderItemsUpdated::class, OrderStatusUpdated::class]);
});

test('troca parcial (1 de 2) ajusta estoque só da quantidade trocada', function () {
    $ctx = flowAuditContext(stock: 10, price: 20.0);
    $kibe = flowAuditProduct($ctx['company'], $ctx['branch'], $ctx['category'], 'Kibe', 20.0, 10);
    $admin = User::factory()->create(['is_super_admin' => true]);

    $order = flowAuditChatOrder($ctx, qty: 2, status: 'paid');

    Livewire::actingAs($admin)
        ->test(Show::class, ['order' => $order])
        ->call('startEditItems')
        ->call('openSwapItem', 0)
        ->call('selectSwapProduct', $kibe->id)
        ->set('swapQuantity', 1)
        ->call('applySwapItem')
        ->call('saveItems')
        ->assertHasNoErrors();

    expect(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(9);
    expect(flowAuditStock($ctx['branch'], $kibe))->toBe(9);
});

test('entrega continua podendo corrigir o endereço do pedido', function () {
    $ctx = flowAuditContext(stock: 10, price: 20.0);
    (new \Database\Seeders\PermissionSeeder)->run();
    $order = flowAuditChatOrder($ctx, qty: 1, status: 'paid');
    $order->update(['order_type' => 'delivery']);

    $driver = User::factory()->create();
    $driver->companies()->attach($ctx['company']->id, ['role' => 'entrega']);
    app()->instance('current.company', $ctx['company']);

    $c = Livewire::actingAs($driver)->test(Show::class, ['order' => $order->fresh()]);
    $c->assertSee('Editar endereço');
    $c->call('startEditAddress')->assertOk()->assertSet('editingAddress', true);
});

test('opção importada e desvinculada antes de salvar mantém a foto do produto da empresa', function () {
    $ctx = flowAuditContext(stock: null, price: 20.0);
    $ctx['product']->update(['image_path' => 'products/coxinha.jpg']);
    $super = User::factory()->create(['is_super_admin' => true]);

    $c = Livewire::actingAs($super)->test(\App\Livewire\Admin\Products\Form::class)
        ->set('company_id', $ctx['company']->id)
        ->set('name', 'Combo')
        ->set('price', '10')
        ->set('product_category_id', $ctx['category']->id)
        ->set('selectedBranches', [$ctx['branch']->id])
        ->call('addOptionGroup')
        ->set('optionGroups.0.name', 'Salgado')
        ->call('openProductPicker', 0)
        ->call('importProductAsOption', $ctx['product']->id);
    $c->call('unlinkSourceProduct', 0, 0)->call('save')->assertHasNoErrors();

    $opt = \App\Models\ProductOption::query()->latest('id')->first();
    expect($opt?->image_path)->toBe('products/coxinha.jpg');
});

test('chat salvo como UNAVAILABLE volta ao início quando a empresa volta a ACTIVE', function () {
    $company = \App\Models\Company::create([
        'name' => 'Empresa Volta', 'slug' => 'empresa-volta-'.uniqid(), 'order_prefix' => 'EVT',
        'active' => true, 'status' => 'ACTIVE', 'email' => 'contato@volta.test',
    ]);
    \App\Models\Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id, 'name' => 'Filial', 'address' => 'Rua', 'city' => 'SP',
        'active' => true, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59',
    ]);

    // Estado que orderPlacementAllowed() grava via transitionTo('UNAVAILABLE') quando a empresa
    // está bloqueada na hora do pedido.
    $response = $this->withSession(['chat_state' => ['step' => 'UNAVAILABLE', 'companyId' => $company->id, 'messages' => []]])
        ->get('/'.$company->slug);

    $step = \Livewire\Drawer\Utils::extractAttributeDataFromHtml($response->getContent(), 'wire:snapshot')['data']['step'] ?? null;
    expect($step)->not->toBe('UNAVAILABLE');
});
