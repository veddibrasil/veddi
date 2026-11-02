<?php

require_once __DIR__.'/../Audit/Flows/flows_helpers.php';

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Livewire\Admin\NotificationBell;
use App\Livewire\Admin\Notifications;
use App\Livewire\Admin\OrderAcceptanceAlarm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
});

test('PDV fica sem notificações de pedido para qualquer operador', function (string $role, string $route) {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => $role, 'branch_id' => $branch->id]);
    $permission = \App\Models\Permission::firstOrCreate(['name' => 'pdv.operate'], ['group' => 'pdv', 'label' => 'Operar terminal PDV']);
    \App\Models\UserPermission::create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'permission_id' => $permission->id,
        'granted' => true,
    ]);
    \App\Models\PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $user->id,
        'opening_amount' => 0,
    ]);

    $this->actingAs($user)->get(route($route))->assertOk()
        ->assertDontSeeLivewire(Notifications::class)
        ->assertDontSeeLivewire(NotificationBell::class)
        ->assertDontSeeLivewire(OrderAcceptanceAlarm::class)
        ->assertDontSee('orderAcceptanceAlarm()', false);
})->with(['company_admin', 'branch_manager', 'caixa'])
    ->with(['admin.pdv', 'admin.pdv.checkout', 'admin.pdv.tabs']);

test('relatório do PDV também fica sem notificações', function () {
    ['admin' => $admin] = pdvContext();

    $this->actingAs($admin)->get(route('admin.pdv.report'))->assertOk()
        ->assertDontSeeLivewire(Notifications::class)
        ->assertDontSeeLivewire(NotificationBell::class)
        ->assertDontSeeLivewire(OrderAcceptanceAlarm::class);
});

test('caixa não recebe notificações fora do PDV mesmo com pedido aguardando aceite', function (string $route) {
    $ctx = flowAuditContext();
    flowAuditChatOrder($ctx, status: 'paid');
    $cashier = User::factory()->create();
    $cashier->companies()->attach($ctx['company']->id, ['role' => 'caixa', 'branch_id' => $ctx['branch']->id]);

    $this->actingAs($cashier)->get(route($route))->assertOk()
        ->assertDontSeeLivewire(Notifications::class)
        ->assertDontSeeLivewire(NotificationBell::class)
        ->assertDontSeeLivewire(OrderAcceptanceAlarm::class)
        ->assertDontSee('orderAcceptanceAlarm()', false);
})->with(['admin.orders.index', 'admin.dashboard']);

test('alarme desabilitado para caixa não consulta fila, escuta eventos ou permite ação direta', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $cashier = User::factory()->create();
    $cashier->companies()->attach($ctx['company']->id, ['role' => 'caixa']);

    $panel = Livewire::actingAs($cashier)->test(OrderAcceptanceAlarm::class)
        ->assertSet('canAccept', false)
        ->assertDontSee('wire:poll', false)
        ->assertDontSee('orderAcceptanceAlarm()', false);
    expect($panel->instance()->getListeners())->toBe([])
        ->and($panel->instance()->awaitingOrders())->toBeEmpty();
    $panel->call('acceptOrder', $order->id)->assertForbidden();
    expect($order->fresh()->status)->toBe('paid');
});

test('administrador continua recebendo notificações na tela de pedidos', function () {
    $ctx = flowAuditContext();
    $order = flowAuditChatOrder($ctx, status: 'paid');
    $admin = User::factory()->create();
    $admin->companies()->attach($ctx['company']->id, ['role' => 'company_admin']);

    $this->actingAs($admin)->get(route('admin.orders.index'))->assertOk()
        ->assertSeeLivewire(Notifications::class)
        ->assertSeeLivewire(NotificationBell::class)
        ->assertSeeLivewire(OrderAcceptanceAlarm::class)
        ->assertSee($order->order_number);
});
