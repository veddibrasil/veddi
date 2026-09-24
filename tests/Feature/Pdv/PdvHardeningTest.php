<?php

use App\Events\NewOrderPlaced;
use App\Livewire\Admin\Pdv\TabTerminal;
use App\Livewire\Admin\Pdv\Terminal;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliverySetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PdvAuditLog;
use App\Models\PdvCashSession;
use App\Models\User;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Regressões da auditoria do PDV (dinheiro recebido, estado forjável, caixa, taxa de entrega,
// efeitos pós-commit). Helpers pdvContext()/openTable()/makeWaiter() ficam em tests/Pest.php.

// ─── Dinheiro recebido ───────────────────────────────────────────────────────

test('dinheiro recebido menor que o total é recusado e não cria pedido', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();
    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '1.00')
        ->call('processOrder')
        ->assertHasErrors('order')
        ->assertSet('step', 'payment');

    expect($component->errors()->first('order'))->toContain('menor que o total');
    expect(Order::withoutGlobalScopes()->count())->toBe(0);
    expect(Payment::count())->toBe(0);
    expect($component->get('cart'))->not->toBeEmpty();
});

test('dinheiro recebido em branco vale o total exato e sem troco', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();
    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertHasNoErrors()
        ->assertSet('changeAmount', 0.0);

    $order = Order::withoutGlobalScopes()->first();
    expect((float) $order->cash_received)->toBe(8.0);
    expect((float) $order->cash_change)->toBe(0.0);
});

test('dinheiro recebido aceita vírgula decimal e separador de milhar', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();
    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '1.000,50')
        ->call('processOrder')
        ->assertHasNoErrors()
        ->assertSet('changeAmount', 992.5);

    expect((float) Order::withoutGlobalScopes()->first()->cash_received)->toBe(1000.5);
});

test('dinheiro recebido não numérico é recusado', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();
    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', 'abc')
        ->call('processOrder')
        ->assertHasErrors('order');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

test('confirmar envia o valor que está no campo, sem esperar o debounce sincronizar', function () {
    // O campo sincroniza com wire:model.live.debounce; clique/F10 logo após digitar chegava ao
    // servidor com o valor antigo (vazio = "exato"). O botão agora manda o valor do DOM junto.
    ['admin' => $admin, 'product' => $product] = pdvContext();
    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder', '20.00')
        ->assertHasNoErrors()
        ->assertSet('changeAmount', 12.0);

    expect((float) Order::withoutGlobalScopes()->first()->cash_received)->toBe(20.0);
});

test('valor do campo enviado no clique é ignorado quando o pagamento não é em dinheiro', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();
    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'credit_card')
        ->call('processOrder', '1.00')
        ->assertHasNoErrors();

    expect(Order::withoutGlobalScopes()->count())->toBe(1);
});

test('fechar comanda com dinheiro menor que o total é recusado e a comanda segue aberta', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);
    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id);

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $component
        ->call('proceedToCloseTab', $orderId)
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '5.00')
        ->call('confirmCloseTab')
        ->assertHasErrors('order')
        ->assertSet('step', 'payment');

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->is_open_tab)->toBeTrue();
    expect($order->status)->not->toBe('paid');
    expect(Payment::where('order_id', $orderId)->count())->toBe(0);
});

test('fechar comanda usa o valor recebido enviado no clique', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);
    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id);

    $orderId = Order::withoutGlobalScopes()->first()->id;

    $component
        ->call('proceedToCloseTab', $orderId)
        ->set('paymentMethod', 'cash')
        ->call('confirmCloseTab', '20.00')
        ->assertHasNoErrors()
        ->assertSet('changeAmount', 12.0);
});

test('fechar a mesa toda com dinheiro menor que o total combinado é recusado', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();
    $table = openTable($company, $branch);
    $this->actingAs($admin);

    $component = Livewire::test(TabTerminal::class)
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->call('deselectOpenTab')
        ->set('selectedTableId', $table->id)
        ->call('addProduct', $product->id)
        ->call('addProduct', $product->id);

    $component
        ->call('proceedToCloseTableTabs', $table->id)
        ->set('isSplitPayment', false)
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->call('confirmCloseTableTabs')
        ->assertHasErrors('order');

    expect(Order::withoutGlobalScopes()->where('is_open_tab', true)->count())->toBe(2);
    expect(Payment::count())->toBe(0);
});

// ─── Estado público não pode ser forjado ─────────────────────────────────────

test('propriedades sensíveis do terminal não aceitam alteração vinda do cliente', function (string $property, mixed $value) {
    ['admin' => $admin] = pdvContext();
    $this->actingAs($admin);

    expect(fn () => Livewire::test(Terminal::class)->set($property, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'desconto manual' => ['manualDiscountAmount', 8.0],
    'desconto liberado' => ['manualDiscountAllowed', true],
    'taxa de entrega' => ['deliveryFeeAmount', 0.0],
    'sessão de caixa' => ['cashSessionId', 999],
    'flag de garçom' => ['isWaiter', true],
    'permissão de operar' => ['canOperate', false],
    'flag de caixa' => ['isCaixa', true],
]);

test('garçom não consegue se passar por operador forjando isWaiter na comanda', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $waiter = makeWaiter($company, $branch);

    expect(fn () => Livewire::actingAs($waiter)->test(TabTerminal::class)->set('isWaiter', false))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('caixa preso a uma filial não troca para outra filial da mesma empresa', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();

    $otherBranch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Outra filial',
        'address' => 'Rua B, 2',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $caixa = User::factory()->create();
    $caixa->companies()->attach($company->id, ['role' => 'caixa', 'branch_id' => $branch->id]);

    Livewire::actingAs($caixa)
        ->test(Terminal::class)
        ->assertSet('selectedBranchId', $branch->id)
        ->set('selectedBranchId', $otherBranch->id)
        ->assertForbidden();
});

// ─── Desconto manual ─────────────────────────────────────────────────────────

test('desconto manual aplicado não vale se a empresa desligou o recurso com a tela aberta', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = pdvContext();
    $company->update(['pdv_manual_discount_enabled' => true]);
    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('manualDiscountInput', '2')
        ->assertSet('manualDiscountAmount', 2.0);

    $company->update(['pdv_manual_discount_enabled' => false]);

    $component->call('processOrder')->assertHasErrors('order');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

test('desconto inválido zera o desconto válido anterior', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = pdvContext();
    $company->update(['pdv_manual_discount_enabled' => true]);
    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('manualDiscountInput', '2')
        ->assertSet('manualDiscountAmount', 2.0)
        ->set('manualDiscountInput', '500')
        ->assertHasErrors('manual_discount')
        ->assertSet('manualDiscountAmount', 0.0);
});

test('desconto manual é auditado no pedido, não a cada digitação', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = pdvContext();
    $company->update(['pdv_manual_discount_enabled' => true]);
    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('manualDiscountInput', '1')
        ->set('manualDiscountInput', '12')
        ->set('manualDiscountInput', '2')
        ->assertSet('manualDiscountAmount', 2.0)
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertHasNoErrors();

    expect(PdvAuditLog::withoutGlobalScopes()->where('action', 'discount_applied')->count())->toBe(0);

    $log = PdvAuditLog::withoutGlobalScopes()->where('action', 'order_created')->first();
    expect((float) $log->metadata['manual_discount'])->toBe(2.0);
    expect((float) Order::withoutGlobalScopes()->first()->total)->toBe(6.0);
});

// ─── Taxa de entrega ─────────────────────────────────────────────────────────

test('taxa de entrega calculada some ao voltar o pedido para balcão', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();

    DeliverySetting::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'fee_type' => 'flat',
        'flat_fee' => 5.00,
        'active' => true,
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Entrega',
        'phone' => '11999990000',
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('customerId', $customer->id)
        ->set('deliveryType', 'entrega')
        ->set('deliveryAddress', 'Rua das Flores')
        ->set('deliveryNumber', '123')
        ->set('deliveryNeighborhood', 'Centro')
        ->set('deliveryCity', 'São Paulo')
        ->set('deliveryCep', '01000-000')
        ->call('calculateDeliveryFee')
        ->assertSet('deliveryFeeAmount', 5.0);

    expect($component->instance()->cartTotalAfterDiscount)->toBe(13.0);

    $component
        ->set('deliveryType', 'balcao')
        ->assertSet('deliveryFeeAmount', 0.0)
        ->assertSet('deliveryFeeError', null);

    expect($component->instance()->cartTotalAfterDiscount)->toBe(8.0);

    $component->set('paymentMethod', 'cash')->call('processOrder')->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->first();
    expect((float) $order->delivery_fee)->toBe(0.0);
    expect((float) $order->total)->toBe(8.0);
});

// ─── Carrinho ────────────────────────────────────────────────────────────────

test('alterar quantidade de linha que não existe no carrinho não cria item fantasma', function () {
    ['admin' => $admin] = pdvContext();
    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('updateCartQty', '999', 5)
        ->assertSet('cart', []);
});

test('alterar quantidade respeita o estoque disponível', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch] = pdvContext();

    DB::table('branch_product')
        ->where('branch_id', $branch->id)
        ->where('product_id', $product->id)
        ->update(['track_stock' => 1, 'quantity' => 3]);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('updateCartQty', (string) $product->id, 10)
        ->assertHasErrors('stock');

    expect($component->get('cart')[(string) $product->id]['qty'])->toBe(3);
});

test('alterar quantidade tem teto por linha', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();
    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('updateCartQty', (string) $product->id, 99999999);

    expect($component->get('cart')[(string) $product->id]['qty'])->toBe(999);
});

// ─── Caixa ───────────────────────────────────────────────────────────────────

test('abrir o caixa duas vezes não cria uma segunda sessão aberta', function () {
    ['admin' => $admin, 'company' => $company] = pdvContext();
    PdvCashSession::withoutGlobalScopes()->where('company_id', $company->id)->delete();
    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->assertSet('step', 'open_cash')
        ->set('openingAmountInput', '50')
        ->call('openCashSession')
        ->call('openCashSession')
        ->assertSet('step', 'catalog');

    $open = PdvCashSession::withoutGlobalScopes()->where('company_id', $company->id)->whereNull('closed_at')->get();
    expect($open)->toHaveCount(1);
    expect((float) $open->first()->opening_amount)->toBe(50.0);
});

test('abertura de caixa com valor negativo ou inválido é recusada', function (string $input) {
    ['admin' => $admin, 'company' => $company] = pdvContext();
    PdvCashSession::withoutGlobalScopes()->where('company_id', $company->id)->delete();
    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->set('openingAmountInput', $input)
        ->call('openCashSession')
        ->assertHasErrors('openingAmountInput')
        ->assertSet('step', 'open_cash');

    expect(PdvCashSession::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(0);
})->with(['negativo' => '-50', 'texto' => 'abc']);

test('abertura de caixa em branco começa sem troco', function () {
    ['admin' => $admin, 'company' => $company] = pdvContext();
    PdvCashSession::withoutGlobalScopes()->where('company_id', $company->id)->delete();
    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('openCashSession')
        ->assertHasNoErrors()
        ->assertSet('step', 'catalog');

    expect((float) PdvCashSession::withoutGlobalScopes()->where('company_id', $company->id)->first()->opening_amount)->toBe(0.0);
});

test('sangria maior que o dinheiro disponível no caixa é recusada', function () {
    ['admin' => $admin] = pdvContext();
    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('toggleCashMovementForm', 'supply')
        ->set('cashMovementAmountInput', '50')
        ->set('cashMovementReason', 'Troco')
        ->call('registerCashMovement')
        ->call('toggleCashMovementForm', 'withdrawal')
        ->set('cashMovementAmountInput', '80')
        ->set('cashMovementReason', 'Cofre')
        ->call('registerCashMovement')
        ->assertHasErrors('cash_movement_amount');

    expect(PdvAuditLog::withoutGlobalScopes()->where('action', 'cash_withdrawal')->count())->toBe(0);
    expect(PdvAuditLog::withoutGlobalScopes()->where('action', 'cash_supply')->count())->toBe(1);
});

test('fechar o caixa com valor contado negativo ou inválido é recusado', function (string $input) {
    ['admin' => $admin] = pdvContext();
    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->set('closingAmountInput', $input)
        ->call('closeCashSession')
        ->assertHasErrors('closingAmountInput');

    expect(PdvCashSession::withoutGlobalScopes()->whereNull('closed_at')->count())->toBe(1);
})->with(['negativo' => '-5', 'texto' => 'abc']);

test('fechar o caixa só encerra a sessão do próprio operador', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch] = pdvContext();

    $other = User::factory()->create();
    $otherSession = PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $other->id,
        'opening_amount' => 0,
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->set('closingAmountInput', '0')
        ->call('closeCashSession')
        ->assertHasNoErrors();

    expect($otherSession->fresh()->closed_at)->toBeNull();
    expect(PdvCashSession::withoutGlobalScopes()->where('user_id', $admin->id)->whereNull('closed_at')->count())->toBe(0);
});

// ─── Falhas no pagamento ─────────────────────────────────────────────────────

test('falha em efeito pós-commit não vira erro na tela nem convida a refazer o pedido', function () {
    // Reverb/broadcast fora do ar depois do commit: o pedido já está gravado e pago. Antes o catch
    // genérico mostrava "erro", o carrinho ficava e o operador refazia a venda (pedido duplicado).
    ['admin' => $admin, 'product' => $product] = pdvContext();
    $this->actingAs($admin);

    Event::listen(NewOrderPlaced::class, fn () => throw new RuntimeException('reverb fora do ar'));

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertHasNoErrors()
        ->assertSet('step', 'catalog')
        ->assertSet('cart', []);

    expect(Order::withoutGlobalScopes()->count())->toBe(1);
    expect(Order::withoutGlobalScopes()->first()->status)->toBe('paid');
});

test('exceção inesperada não vaza a mensagem crua para o operador', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();
    $this->actingAs($admin);

    $this->mock(OrderService::class)
        ->shouldReceive('createOrder')
        ->andThrow(new LogicException('conexão com db-interno-01 recusada, senha=abc123'));

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertHasErrors('order');

    expect($component->errors()->first('order'))
        ->toBe('Não foi possível processar o pedido agora. Tente novamente.')
        ->not->toContain('abc123');
});

test('mensagem de regra de negócio dos services continua chegando ao operador', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();
    $this->actingAs($admin);

    $this->mock(OrderService::class)
        ->shouldReceive('createOrder')
        ->andThrow(new RuntimeException('Produto #1 não está disponível nesta filial.'));

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertHasErrors('order');

    expect($component->errors()->first('order'))->toBe('Produto #1 não está disponível nesta filial.');
});
