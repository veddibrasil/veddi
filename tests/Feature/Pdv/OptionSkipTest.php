<?php

use App\Livewire\Admin\Pdv\TabTerminal;
use App\Livewire\Admin\Pdv\Terminal;
use App\Models\Order;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('PDV envia a configuração Não quero para o seletor de opções', function (string $terminal, bool $allowSkip) {
    ['admin' => $admin, 'company' => $company, 'product' => $product] = pdvContext();
    $group = ProductOptionGroup::create([
        'company_id' => $company->id, 'name' => 'Molhos',
        'total_qty' => 3, 'min_qty' => 2, 'fixed' => false, 'allow_skip' => $allowSkip,
    ]);
    $product->optionGroups()->attach($group->id);

    $component = Livewire::actingAs($admin)->test($terminal);
    $data = $component->instance()->buildProductDataForSidebar($product->fresh());

    expect($data['groups'][0]['allow_skip'])->toBe($allowSkip);
})->with(['caixa' => [Terminal::class], 'comanda' => [TabTerminal::class]])->with([true, false]);

test('PDV confirma pedido sem cobrar opções do grupo com Não quero', function (string $terminal) {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch, 'product' => $product] = pdvContext();
    $table = openTable($company, $branch);
    $group = ProductOptionGroup::create([
        'company_id' => $company->id, 'name' => 'Molhos',
        'total_qty' => 3, 'min_qty' => 2, 'fixed' => false, 'allow_skip' => true,
    ]);
    $product->optionGroups()->attach($group->id);
    ProductOption::create([
        'product_option_group_id' => $group->id, 'name' => 'Maionese', 'additional_price' => 3,
    ]);

    $component = Livewire::actingAs($admin)->test($terminal);
    if ($terminal === TabTerminal::class) {
        $component->set('selectedTableId', $table->id);
    }

    $component->call('addProductWithOptions', $product->id, [
        $group->id => ['group_name' => $group->name, 'total_qty' => 3, 'selections' => []],
    ])->assertHasNoErrors();

    if ($terminal === Terminal::class) {
        expect($component->get('cartTotal'))->toBe(8.0);
        $component->call('proceedToPayment')
            ->set('paymentMethod', 'cash')
            ->set('cashReceivedInput', '10.00')
            ->call('processOrder')
            ->assertHasNoErrors();
    }

    $order = Order::withoutGlobalScopes()->sole();
    expect((float) $order->total)->toBe(8.0);
    expect($order->items->sole()->options[$group->id]['selections'])->toBe([]);
})->with(['caixa' => [Terminal::class], 'comanda' => [TabTerminal::class]]);
