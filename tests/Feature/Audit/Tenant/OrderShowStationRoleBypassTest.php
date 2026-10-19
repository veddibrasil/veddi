<?php

/*
 * Auditoria 15/10/2026 — Admin/Orders/Show: papéis de estação (cozinha/bar/entrega)
 * têm orders.update, e as ações financeiras (confirmCancel -> reembolso automático,
 * manualRefund, confirmPayment) só checam $this->canUpdate. A restrição de estação
 * existe só na view (@if (! $userStation)) e em updateStatus(), que lê $userStation —
 * prop pública sem #[Locked]. Os testes afirmam o comportamento seguro e devem FALHAR hoje.
 *
 * (A falta de escopo de FILIAL em Orders/Show é o achado F-02 do lead; aqui o pedido é
 * da mesma filial da cozinha, para isolar o problema do papel.)
 */

use App\Livewire\Admin\Orders\Show;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/TenantAuditHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->company = atnCompany('Est');
    app()->instance('current.company', $this->company);
    $this->branch = atnBranch($this->company, 'Centro');
    $this->cook = atnUser($this->company, 'cozinha', $this->branch);
    $this->order = atnOrder($this->company, $this->branch, atnCustomer($this->company), [
        'status' => 'paid', 'payment_method' => 'CASH',
    ]);
});

test('cozinha não pode cancelar pedido pago (ação reservada ao admin, dispara reembolso)', function () {
    atnAttempt(fn () => Livewire::actingAs($this->cook)
        ->test(Show::class, ['order' => $this->order])
        ->call('openCancelModal')
        ->set('cancelReason', 'cozinha cancelou por conta')
        ->call('confirmCancel'));

    expect($this->order->fresh()->status)->toBe('paid');
});

test('cozinha não pode limpar userStation e marcar o pedido como entregue', function () {
    atnAttempt(fn () => Livewire::actingAs($this->cook)
        ->test(Show::class, ['order' => $this->order])
        ->set('userStation', null)
        ->call('updateStatus', 'delivered'));

    expect($this->order->fresh()->status)->toBe('paid');
});
