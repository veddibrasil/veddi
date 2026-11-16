<?php

use App\Livewire\Admin\Branches\Form;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeOrderInBranch(Company $company, Branch $branch): Order
{
    $customer = Customer::withoutGlobalScopes()->firstOrCreate(['company_id' => $company->id, 'phone' => '11999990000'], ['name' => 'Cliente']);

    return Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'subtotal' => 10, 'total' => 10, 'delivery_fee' => 0, 'fee' => 0, 'net_value' => 10,
        'status' => 'paid', 'payment_method' => 'cash', 'order_type' => 'pickup',
    ]);
}

test('pedido usa o prefixo da filial quando ela tem um', function () {
    ['company' => $company, 'branch' => $matriz] = pdvContext();
    $arapongas = Branch::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Arapongas', 'order_prefix' => 'ARP', 'address' => 'Rua 1', 'city' => 'Arapongas', 'active' => true]);

    $order = makeOrderInBranch($company, $arapongas);

    expect($order->order_number)->toStartWith('ARP-'.now()->year.'-');
});

test('filial sem prefixo usa o prefixo da empresa e a sequência continua única', function () {
    ['company' => $company, 'branch' => $matriz] = pdvContext();
    $arapongas = Branch::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Arapongas', 'order_prefix' => 'ARP', 'address' => 'Rua 1', 'city' => 'Arapongas', 'active' => true]);

    $sem = makeOrderInBranch($company, $matriz);
    $com = makeOrderInBranch($company, $arapongas);

    expect($sem->order_number)->toStartWith('PDV-'.now()->year.'-')
        ->and($com->order_number)->toStartWith('ARP-'.now()->year.'-')
        ->and($sem->order_number)->not->toBe($com->order_number);
});

test('formulário grava o prefixo em maiúsculas e recusa repetido na mesma empresa', function () {
    ['company' => $company, 'branch' => $matriz] = pdvContext();
    Branch::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Arapongas', 'order_prefix' => 'ARP', 'address' => 'Rua 1', 'city' => 'Arapongas', 'active' => true]);

    $admin = \App\Models\User::factory()->create(['is_super_admin' => true]);
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $valid = ['name' => 'Maringá', 'address' => 'Av. Brasil', 'city' => 'Maringá', 'state' => 'PR', 'cep' => '87000-000', 'available_days' => [0, 1, 2, 3, 4, 5, 6]];

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('name', $valid['name'])
        ->set('order_prefix', 'mga')
        ->set('address', $valid['address'])
        ->set('city', $valid['city'])
        ->set('state', $valid['state'])
        ->set('cep', $valid['cep'])
        ->set('available_days', $valid['available_days'])
        ->call('save');

    expect(Branch::withoutGlobalScopes()->where('name', 'Maringá')->value('order_prefix'))->toBe('MGA');

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('name', 'Outra')
        ->set('order_prefix', 'ARP')
        ->set('address', 'Rua X')
        ->set('city', 'Cidade')
        ->set('state', 'PR')
        ->set('cep', '87000-000')
        ->set('available_days', [0, 1, 2, 3, 4, 5, 6])
        ->call('save')
        ->assertHasErrors(['order_prefix']);
});
