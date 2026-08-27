<?php

use App\Jobs\IssueFiscalNote;
use App\Livewire\Admin\Pdv\Terminal;
use App\Models\Branch;
use App\Models\BranchServiceCharge;
use App\Models\Company;
use App\Models\CompanyFiscalConfig;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PdvAuditLog;
use App\Models\PdvCashSession;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Payment\PaymentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Helpers pdvContext(), makeWaiter() e openTable() ficam em tests/Pest.php —
// compartilhados com TabTerminalTest.php.

// ─── Acesso ───────────────────────────────────────────────────────────────────

test('visitante é redirecionado para login ao acessar PDV', function () {
    pdvContext();

    $this->get(route('admin.pdv'))
        ->assertRedirect(route('login'));
});

test('empresa com plano sem PDV recebe 403', function () {
    $company = Company::create([
        'name' => 'Sem PDV',
        'slug' => 'sem-pdv-'.uniqid(),
        'order_prefix' => 'SP',
        'active' => true,
        'plan' => 'pro',
    ]);

    app()->instance('current.company', $company);

    Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'address' => 'Rua A',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $admin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.pdv'))
        ->assertForbidden();
});

// ─── Terminal: carregamento ───────────────────────────────────────────────────

test('terminal PDV carrega com filial ativa', function () {
    ['admin' => $admin, 'branch' => $branch] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->assertSet('selectedBranchId', $branch->id)
        ->assertSet('step', 'catalog');
});

test('terminal exibe produtos disponíveis na filial', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->assertSee($product->name);
});

test('produtos no terminal seguem ordem da categoria e depois do produto', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch] = pdvContext();

    $bebidas = ProductCategory::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Bebidas',
        'active' => true,
        'sort_order' => 0,
    ]);

    $refrigerante = Product::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'product_category_id' => $bebidas->id,
        'name' => 'Refrigerante',
        'price' => 6.00,
        'active' => true,
        'sort_order' => 0,
    ]);

    DB::table('branch_product')->insert([
        'branch_id' => $branch->id,
        'product_id' => $refrigerante->id,
        'available' => 1,
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class);

    expect($component->get('products')->pluck('name')->all())
        ->toBe(['Refrigerante', 'Coxinha']);
});

// ─── Carrinho ─────────────────────────────────────────────────────────────────

test('adicionar produto ao carrinho', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->assertSet('cart.'.(string) $product->id.'.qty', 1)
        ->assertSet('cart.'.(string) $product->id.'.price', 8.0);
});

test('incrementar e decrementar quantidade no carrinho', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('updateCartQty', (string) $product->id, 2)
        ->assertSet('cart.'.(string) $product->id.'.qty', 2)
        ->call('updateCartQty', (string) $product->id, 1)
        ->assertSet('cart.'.(string) $product->id.'.qty', 1);
});

test('decrementar a 0 remove item do carrinho', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $key = (string) $product->id;

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('updateCartQty', $key, 0)
        ->assertSet('cart', []);
});

test('produto com available_in_pdv false não aparece nem pode ser adicionado no PDV', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $product->update(['available_in_pdv' => false]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->assertDontSee($product->name)
        ->call('addProduct', $product->id)
        ->assertSet('cart', []);
});

// ─── Pedido com dinheiro ──────────────────────────────────────────────────────

test('processOrder ignora chamada reentrante enquanto outra já está em andamento', function () {
    // Simula F10 + clique disparando processOrder() quase simultaneamente: a
    // segunda chamada precisa ser barrada pelo guard, sem criar um segundo
    // pedido nem mexer no carrinho ainda pendente da primeira.
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00');

    $component->set('processingOrder', true)
        ->call('processOrder');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
    expect($component->get('cart'))->not->toBeEmpty();
});

test('pedido PDV com pagamento em dinheiro cria order e payment', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->assertSet('step', 'payment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->call('processOrder')
        ->assertSet('step', 'catalog')
        ->assertSet('changeAmount', 2.0);

    expect(Order::withoutGlobalScopes()->count())->toBe(1);

    $order = Order::withoutGlobalScopes()->first();
    expect($order->order_type)->toBe('pdv');
    expect($order->status)->toBe('paid');
    expect((float) $order->total)->toBe(8.0);
    expect((float) $order->cash_received)->toBe(10.0);
    expect((float) $order->cash_change)->toBe(2.0);

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe('paid');
    expect($payment->payment_gateway)->toBe('cash');
});

// ─── Terminal: pagamento dividido (split) ─────────────────────────────────────

test('pedido PDV com pagamento dividido cria order split e um payment por parte', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->assertSet('step', 'payment')
        ->set('isSplitPayment', true)
        ->set('splitPayments.0.method', 'cash')
        ->set('splitPayments.0.amount', '3.00')
        ->set('splitPayments.0.cash_received', '3.00')
        ->set('splitPayments.1.method', 'credit_card')
        ->set('splitPayments.1.amount', '5.00')
        ->call('processOrder')
        ->assertHasNoErrors()
        ->assertSet('step', 'catalog');

    expect(Order::withoutGlobalScopes()->count())->toBe(1);

    $order = Order::withoutGlobalScopes()->first();
    expect($order->payment_method)->toBe('split');
    expect($order->status)->toBe('paid');
    expect((float) $order->total)->toBe(8.0);
    expect((float) $order->cash_received)->toBe(3.0);
    expect((float) $order->cash_change)->toBe(0.0);

    $payments = Payment::where('order_id', $order->id)->orderBy('id')->get();
    expect($payments)->toHaveCount(2);
    expect($payments[0]->payment_gateway)->toBe('cash');
    expect((float) $payments[0]->amount)->toBe(3.0);
    expect($payments[1]->payment_gateway)->toBe('card_machine');
    expect((float) $payments[1]->amount)->toBe(5.0);
});

test('pagamento dividido com soma diferente do total bloqueia e não cria pedido', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('isSplitPayment', true)
        ->set('splitPayments.0.method', 'cash')
        ->set('splitPayments.0.amount', '3.00')
        ->set('splitPayments.1.method', 'credit_card')
        ->set('splitPayments.1.amount', '4.00')
        ->call('processOrder')
        ->assertHasErrors('order');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

test('pagamento dividido com duas partes em dinheiro bloqueia e não cria pedido', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('isSplitPayment', true)
        ->set('splitPayments.0.method', 'cash')
        ->set('splitPayments.0.amount', '4.00')
        ->set('splitPayments.0.cash_received', '4.00')
        ->set('splitPayments.1.method', 'cash')
        ->set('splitPayments.1.amount', '4.00')
        ->set('splitPayments.1.cash_received', '4.00')
        ->call('processOrder')
        ->assertHasErrors('order');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

test('pedido PDV pago em dinheiro dispara emissão automática de nota fiscal', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company, 'branch' => $branch] = pdvContext();

    $company->update(['fiscal_notes_enabled' => true]);
    CompanyFiscalConfig::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'is_default' => true, 'enabled' => true]);

    Bus::fake();
    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->call('processOrder')
        ->assertSet('step', 'catalog');

    $order = Order::withoutGlobalScopes()->first();

    Bus::assertDispatched(IssueFiscalNote::class, fn ($job) => $job->orderId === $order->id);
});

test('checkbox de imprimir nota fiscal só aparece com o módulo fiscal habilitado na empresa', function () {
    ['admin' => $admin] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->assertSet('canUseFiscalNotes', false)
        ->assertSet('printFiscalNote', false);
});

test('pagamento com checkbox de nota fiscal marcado dispara order-paid com printFiscalNote, mesmo sem impressora de auto-print', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = pdvContext();

    $company->update(['fiscal_notes_enabled' => true]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->assertSet('canUseFiscalNotes', true)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->set('printFiscalNote', true)
        ->call('processOrder')
        ->assertSet('step', 'catalog')
        ->assertDispatched('order-paid', printFiscalNote: true);
});

test('pagamento sem marcar o checkbox e sem impressora de auto-print não dispara order-paid', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '10.00')
        ->call('processOrder')
        ->assertSet('step', 'catalog')
        ->assertNotDispatched('order-paid');
});

// ─── Pedido com PIX ───────────────────────────────────────────────────────────

test('pedido PDV com PIX é apenas informativo: marca como pago direto sem gerar cobrança', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'pix')
        ->call('processOrder')
        ->assertSet('step', 'catalog')
        ->assertHasNoErrors();

    expect(Order::withoutGlobalScopes()->count())->toBe(1);

    $order = Order::withoutGlobalScopes()->first();
    expect($order->order_type)->toBe('pdv');
    expect($order->payment_method)->toBe('pix');
    expect($order->status)->toBe('paid');

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe('paid');
    expect($payment->payment_gateway)->toBe('pix_manual');
});

// ─── Busca de cliente ─────────────────────────────────────────────────────────

test('busca de cliente lista resultados automaticamente conforme o operador digita', function () {
    ['admin' => $admin, 'company' => $company] = pdvContext();

    Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Maria Souza', 'phone' => '11911112222']);
    Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Marcos Lima', 'phone' => '11933334444']);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->set('customerQuery', 'Mar');

    expect($component->get('customerResults'))->toHaveCount(2);
    expect($component->get('customerFound'))->toBeFalse();
});

test('selecionar cliente com entrega preenche o endereço a partir do cadastro dele', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = pdvContext();

    $address = \App\Models\Address::create([
        'line1' => 'Av. Brasil',
        'number' => '500',
        'complement' => 'Fundos',
        'neighborhood' => 'Zona 7',
        'city' => 'Maringá',
        'state' => 'PR',
        'cep' => '87050-000',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Cadastrado',
        'phone' => '11955556666',
        'address_id' => $address->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('deliveryType', 'entrega')
        ->call('selectCustomer', $customer->id)
        ->assertSet('customerId', $customer->id)
        ->assertSet('deliveryAddress', 'Av. Brasil')
        ->assertSet('deliveryNumber', '500')
        ->assertSet('deliveryComplement', 'Fundos')
        ->assertSet('deliveryNeighborhood', 'Zona 7')
        ->assertSet('deliveryCity', 'Maringá')
        ->assertSet('deliveryCep', '87050-000');
});

test('selecionar cliente no balcão e depois trocar para entrega preenche o endereço a partir do cadastro dele', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = pdvContext();

    $address = \App\Models\Address::create([
        'line1' => 'Av. Brasil',
        'number' => '500',
        'complement' => 'Fundos',
        'neighborhood' => 'Zona 7',
        'city' => 'Maringá',
        'state' => 'PR',
        'cep' => '87050-000',
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Cadastrado',
        'phone' => '11955556666',
        'address_id' => $address->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->call('selectCustomer', $customer->id)
        ->assertSet('customerId', $customer->id)
        ->assertSet('deliveryAddress', '')
        ->set('deliveryType', 'entrega')
        ->assertSet('deliveryAddress', 'Av. Brasil')
        ->assertSet('deliveryNumber', '500')
        ->assertSet('deliveryComplement', 'Fundos')
        ->assertSet('deliveryNeighborhood', 'Zona 7')
        ->assertSet('deliveryCity', 'Maringá')
        ->assertSet('deliveryCep', '87050-000');
});

test('busca de cliente seleciona automaticamente quando só há uma correspondência', function () {
    ['admin' => $admin, 'company' => $company] = pdvContext();

    $customer = Customer::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Maria Souza', 'phone' => '11911112222']);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->set('customerQuery', 'Maria Souza')
        ->assertSet('customerId', $customer->id)
        ->assertSet('customerFound', true);
});

// ─── Cliente anônimo ──────────────────────────────────────────────────────────

test('pedido PDV sem cliente cria ou reusa cliente balcão', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder');

    $guest = Customer::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->where('phone', 'pdv-guest')
        ->first();

    expect($guest)->not->toBeNull();
    expect($guest->name)->toBe('Cliente Balcão');

    // Segundo pedido reutiliza mesmo cliente balcão
    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder');

    expect(Customer::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->where('phone', 'pdv-guest')
        ->count()
    )->toBe(1);
});

// ─── Troco ────────────────────────────────────────────────────────────────────

test('troco calculado corretamente', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    // R$ 8,00 produto, paga R$ 20,00 → troco R$ 12,00
    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->set('cashReceivedInput', '20.00')
        ->call('processOrder')
        ->assertSet('changeAmount', 12.0);
});

test('preview de troco aparece assim que o valor recebido é informado, antes de confirmar o pagamento', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->assertDontSee('Troco: R$')
        ->set('cashReceivedInput', '20.00')
        ->assertSee('Troco: R$ 12,00');
});

// ─── Isolamento multi-tenant ───────────────────────────────────────────────────

test('pedido PDV é associado à empresa correta', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = pdvContext();

    $otherCompany = Company::create([
        'name' => 'Outra Empresa',
        'slug' => 'outra-'.uniqid(),
        'order_prefix' => 'OUT',
        'active' => true,
        'plan' => 'pro',
        'pdv_module_enabled' => true,
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder');

    $order = Order::withoutGlobalScopes()->first();
    expect($order->branch->company_id)->toBe($company->id);
    expect($order->branch->company_id)->not->toBe($otherCompany->id);
});

// ─── Pedido com cartão ────────────────────────────────────────────────────────

test('pedido PDV com cartão registra como pago via card_machine', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'credit_card')
        ->call('processOrder')
        ->assertSet('step', 'catalog');

    $order = Order::withoutGlobalScopes()->first();
    expect($order->status)->toBe('paid');
    expect($order->payment_method)->toBe('credit_card');

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe('paid');
    expect($payment->payment_gateway)->toBe('card_machine');
    expect($payment->paid_at)->not->toBeNull();
});

// ─── Notas no pedido ─────────────────────────────────────────────────────────

test('pedido PDV salva observação do operador', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->set('notes', 'sem cebola')
        ->call('processOrder');

    $order = Order::withoutGlobalScopes()->first();
    expect($order->notes)->toBe('sem cebola');
});

// ─── Desconto manual ────────────────────────────────────────────────────────

test('desconto manual fica bloqueado quando empresa desabilita a opção', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = pdvContext();
    $company->update(['pdv_manual_discount_enabled' => false]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->assertSet('manualDiscountAllowed', false)
        ->set('manualDiscountInput', '2,00')
        ->call('applyManualDiscount')
        ->assertSet('manualDiscountAmount', 0.0)
        ->assertHasErrors('manual_discount');
});

// ─── Entrega ────────────────────────────────────────────────────────────────

test('pedido PDV de entrega salva endereço e taxa na order', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();

    \App\Models\DeliverySetting::create([
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

    Livewire::test(Terminal::class)
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
        ->assertSet('deliveryFeeError', null)
        ->assertSet('deliveryFeeAmount', 5.0)
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertSet('step', 'catalog');

    $order = Order::withoutGlobalScopes()->first();
    expect((float) $order->delivery_fee)->toBe(5.0);
    expect($order->delivery_address)->toBe('Rua das Flores');
    expect($order->delivery_neighborhood)->toBe('Centro');
});

test('taxa de entrega é calculada automaticamente conforme o endereço é preenchido, sem botão manual', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch, 'company' => $company] = pdvContext();

    \App\Models\DeliverySetting::create([
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

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('customerId', $customer->id)
        ->set('deliveryType', 'entrega')
        ->assertSet('deliveryFeeAmount', 0.0)
        ->set('deliveryAddress', 'Rua das Flores')
        ->assertSet('deliveryFeeAmount', 0.0)
        ->assertSet('deliveryFeeError', null)
        ->set('deliveryNumber', '123')
        ->set('deliveryNeighborhood', 'Centro')
        ->set('deliveryCity', 'São Paulo')
        ->set('deliveryCep', '01000-000')
        ->assertSet('deliveryFeeError', null)
        ->assertSet('deliveryFeeAmount', 5.0);
});

test('pedido PDV de entrega exige cliente selecionado', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('deliveryType', 'entrega')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertHasErrors('order');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

test('pedido PDV de entrega rejeita CEP inválido', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => Company::first()->id,
        'name' => 'Cliente Entrega',
        'phone' => '11999990000',
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('customerId', $customer->id)
        ->set('deliveryType', 'entrega')
        ->set('deliveryAddress', 'Rua das Flores')
        ->set('deliveryNumber', '123')
        ->set('deliveryNeighborhood', 'Centro')
        ->set('deliveryCity', 'São Paulo')
        ->set('deliveryCep', '123')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertHasErrors('order');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

// ─── Cancelamento no terminal ─────────────────────────────────────────────────

test('operador cancela pedido PDV dentro de 5 minutos', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertSet('step', 'catalog');

    $orderId = $component->get('lastOrderId');
    expect($orderId)->not->toBeNull();

    $order = Order::withoutGlobalScopes()->find($orderId);

    $component
        ->call('cancelLastOrder')
        ->assertSet('step', 'catalog')
        ->assertSet('lastOrderId', null)
        ->assertDispatched('pdv-toast', message: "Pedido {$order->order_number} cancelado.");

    expect($order->fresh()->status)->toBe('cancelled');
});

test('cancelar pedido do card de sucesso não apaga o carrinho já iniciado do próximo pedido', function () {
    // Fluxo não-bloqueante: catálogo libera na hora após pagar, então o operador
    // pode já estar montando o pedido seguinte quando cancela o anterior pelo card
    // flutuante — cancelar não pode arrastar junto o carrinho em andamento.
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertSet('step', 'catalog')
        ->assertSet('cart', []);

    $firstOrderId = $component->get('lastOrderId');

    $component->call('addProduct', $product->id);
    expect($component->get('cart'))->not->toBeEmpty();

    $component->call('cancelLastOrder')
        ->assertSet('lastOrderId', null);

    expect($component->get('cart'))->not->toBeEmpty();

    $order = Order::withoutGlobalScopes()->find($firstOrderId);
    expect($order->status)->toBe('cancelled');
});

test('operador cancela pedido PDV de sessão mesmo após 5 minutos', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertSet('step', 'catalog');

    $orderId = $component->get('lastOrderId');
    Order::withoutGlobalScopes()->where('id', $orderId)->update(['created_at' => now()->subMinutes(6)]);

    $component->call('cancelLastOrder');

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->status)->toBe('cancelled');
});

test('pedido em status terminal não pode ser cancelado no PDV', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertSet('step', 'catalog');

    $orderId = $component->get('lastOrderId');
    Order::withoutGlobalScopes()->where('id', $orderId)->update(['status' => 'delivered']);

    $component->call('cancelLastOrder');

    $component->assertHasErrors('cancel');
    $component->assertDispatched('pdv-toast');

    $order = Order::withoutGlobalScopes()->find($orderId);
    expect($order->status)->toBe('delivered');
});

// ─── processCash direto ───────────────────────────────────────────────────────

test('processCash cria payment com status paid sem gateway', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Ana',
        'phone' => '11999990099',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'customer_id' => $customer->id,
        'branch_id' => $branch->id,
        'subtotal' => 15.00,
        'total' => 15.00,
        'fee' => 0,
        'net_value' => 15.00,
        'status' => 'paid',
        'payment_method' => 'cash',
        'order_type' => 'pdv',
        'cash_received' => 20.00,
        'cash_change' => 5.00,
    ]);

    $result = app(PaymentOrchestrator::class)->processCash($order);

    expect($result['status'])->toBe('paid');
    expect($result['gateway'])->toBe('cash');
    expect($result['change'])->toBe(5.0);

    $payment = Payment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe('paid');
    expect($payment->payment_gateway)->toBe('cash');
    expect($payment->paid_at)->not->toBeNull();
});

// ─── Auditoria e caixa profissional ──────────────────────────────────────────

test('suprimento e sangria alteram valor esperado do caixa', function () {
    ['admin' => $admin] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('toggleCashMovementForm', 'supply')
        ->set('cashMovementAmountInput', '50.00')
        ->set('cashMovementReason', 'Troco adicional')
        ->call('registerCashMovement')
        ->call('toggleCashMovementForm', 'withdrawal')
        ->set('cashMovementAmountInput', '20.00')
        ->set('cashMovementReason', 'Retirada para cofre')
        ->call('registerCashMovement');

    $session = PdvCashSession::withoutGlobalScopes()->find($component->get('cashSessionId'));

    expect($component->instance()->cashSessionExpected($session))->toBe(30.0);
    expect(PdvAuditLog::withoutGlobalScopes()->where('action', 'cash_supply')->count())->toBe(1);
    expect(PdvAuditLog::withoutGlobalScopes()->where('action', 'cash_withdrawal')->count())->toBe(1);
});

test('PDV registra auditoria para venda cancelamento e fechamento', function () {
    ['admin' => $admin, 'product' => $product] = pdvContext();

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertSet('step', 'catalog');

    $orderId = $component->get('lastOrderId');

    expect(PdvAuditLog::withoutGlobalScopes()
        ->where('action', 'order_created')
        ->where('order_id', $orderId)
        ->exists()
    )->toBeTrue();

    $component->call('cancelLastOrder');

    expect(PdvAuditLog::withoutGlobalScopes()
        ->where('action', 'order_cancelled')
        ->where('order_id', $orderId)
        ->exists()
    )->toBeTrue();

    $session = PdvCashSession::withoutGlobalScopes()->whereNull('closed_at')->first();

    Livewire::test(Terminal::class)
        ->set('closingAmountInput', (string) $component->instance()->cashSessionExpected($session))
        ->call('closeCashSession');

    expect(PdvAuditLog::withoutGlobalScopes()
        ->where('action', 'cash_closed')
        ->exists()
    )->toBeTrue();
});

test('relatório de fechamento lista sessões encerradas e exibe detalhe', function () {
    ['admin' => $admin] = pdvContext();

    $this->actingAs($admin);

    $opening = Livewire::test(Terminal::class);
    $session = PdvCashSession::withoutGlobalScopes()->find($opening->get('cashSessionId'));

    $opening->set('closingAmountInput', (string) $opening->instance()->cashSessionExpected($session))
        ->call('closeCashSession')
        ->assertSet('showClosingReports', true)
        ->assertSet('viewingClosedSessionId', $session->id)
        ->assertSee('Esperado no caixa');

    $session->refresh();

    Livewire::test(Terminal::class)
        ->call('openClosingReports')
        ->assertSet('showClosingReports', true)
        ->assertSee($session->closed_at->format('d/m/Y'))
        ->call('viewClosedSession', $session->id)
        ->assertSet('viewingClosedSessionId', $session->id)
        ->assertSee('Esperado no caixa')
        ->call('backToClosingReportsList')
        ->assertSet('viewingClosedSessionId', null);
});

test('caixa não vê o botão de relatórios de fechamento e recebe 403 se chamar direto', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();

    $caixa = \App\Models\User::factory()->create();
    $caixa->companies()->attach($company->id, ['role' => 'caixa', 'branch_id' => $branch->id]);

    $this->actingAs($caixa);

    Livewire::test(Terminal::class)
        ->assertDontSee('Relatórios de fechamento')
        ->call('openClosingReports')
        ->assertForbidden();
});

test('caixa fecha o próprio caixa, vê só o próprio resumo e o "voltar" não revela os fechamentos dos outros', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();

    $caixa = \App\Models\User::factory()->create();
    $caixa->companies()->attach($company->id, ['role' => 'caixa', 'branch_id' => $branch->id]);

    \App\Models\PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $caixa->id,
        'opening_amount' => 0,
    ]);

    // Sessão fechada de outro operador na mesma filial — não pode aparecer pro caixa.
    $otherSession = \App\Models\PdvCashSession::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => \App\Models\User::factory()->create()->id,
        'opening_amount' => 40.00,
        'closing_amount' => 40.00,
        'expected_amount' => 40.00,
        'terminal_name' => 'Terminal do outro caixa',
        'closed_at' => now(),
    ]);

    $this->actingAs($caixa);

    $component = Livewire::test(Terminal::class);
    $session = \App\Models\PdvCashSession::withoutGlobalScopes()->find($component->get('cashSessionId'));

    $component->set('closingAmountInput', (string) $component->instance()->cashSessionExpected($session))
        ->call('closeCashSession')
        ->assertSet('showClosingReports', true)
        ->assertSet('viewingClosedSessionId', $session->id)
        ->assertDontSee('Terminal do outro caixa')
        ->call('backToClosingReportsList')
        ->assertSet('showClosingReports', false)
        ->assertDontSee('Terminal do outro caixa');

    // Furar direto pelo id de outra sessão também é bloqueado.
    Livewire::test(Terminal::class)
        ->call('viewClosedSession', $otherSession->id)
        ->assertForbidden();
});

// ─── Mesa/Comanda ─────────────────────────────────────────────────────────────

test('taxa de serviço e couvert da filial são aplicados automaticamente no pedido de balcão', function () {
    ['company' => $company, 'admin' => $admin, 'product' => $product, 'branch' => $branch] = pdvContext();

    $company->update(['plan' => 'free']);

    BranchServiceCharge::create([
        'branch_id' => $branch->id,
        'company_id' => $company->id,
        'service_fee_enabled' => true,
        'service_fee_type' => 'percent',
        'service_fee_value' => 10,
        'couvert_enabled' => true,
        'couvert_type' => 'fixed',
        'couvert_value' => 5,
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->first();

    expect((float) $order->subtotal)->toBe(8.0);
    expect((float) $order->service_fee)->toBe(0.8);
    expect((float) $order->couvert_fee)->toBe(5.0);
    expect((float) $order->total)->toBe(13.8);
    // comissão da plataforma incide só sobre o subtotal de produtos, não sobre taxa/couvert
    expect((float) $order->fee)->toBe(0.08);
    expect((float) $order->net_value)->toBe(13.72);
});

test('taxa de serviço e couvert não são cobrados em pedido de entrega', function () {
    ['company' => $company, 'admin' => $admin, 'product' => $product, 'branch' => $branch] = pdvContext();

    BranchServiceCharge::create([
        'branch_id' => $branch->id,
        'company_id' => $company->id,
        'service_fee_enabled' => true,
        'service_fee_type' => 'percent',
        'service_fee_value' => 10,
        'couvert_enabled' => true,
        'couvert_type' => 'fixed',
        'couvert_value' => 5,
    ]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Entrega',
        'phone' => '11999990000',
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->assertSet('rawServiceFeeAmount', 0.8)
        ->assertSet('rawCouvertFeeAmount', 5.0)
        ->set('customerId', $customer->id)
        ->set('deliveryType', 'entrega')
        ->assertSet('rawServiceFeeAmount', 0.0)
        ->assertSet('rawCouvertFeeAmount', 0.0)
        ->set('deliveryAddress', 'Rua das Flores')
        ->set('deliveryNumber', '123')
        ->set('deliveryNeighborhood', 'Centro')
        ->set('deliveryCity', 'São Paulo')
        ->set('deliveryCep', '01000-000')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->first();
    expect((float) $order->service_fee)->toBe(0.0);
    expect((float) $order->couvert_fee)->toBe(0.0);
});

test('desconto manual é aplicado e removido automaticamente conforme o operador digita', function () {
    ['admin' => $admin, 'product' => $product, 'company' => $company] = pdvContext();

    $company->update(['pdv_manual_discount_enabled' => true]);

    $this->actingAs($admin);

    $component = Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('manualDiscountInput', '2,00')
        ->assertSet('manualDiscountAmount', 2.0);

    $component
        ->set('manualDiscountInput', '')
        ->assertSet('manualDiscountAmount', 0.0);
});

test('operador remove taxa de serviço e couvert na hora de finalizar o pedido de balcão', function () {
    ['admin' => $admin, 'product' => $product, 'branch' => $branch] = pdvContext();

    BranchServiceCharge::create([
        'branch_id' => $branch->id,
        'company_id' => $branch->company_id,
        'service_fee_enabled' => true,
        'service_fee_type' => 'percent',
        'service_fee_value' => 10,
        'couvert_enabled' => true,
        'couvert_type' => 'fixed',
        'couvert_value' => 5,
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->assertSet('serviceFeeWaived', false)
        ->set('serviceFeeWaived', true)
        ->set('couvertFeeWaived', true)
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertHasNoErrors();

    $order = Order::withoutGlobalScopes()->first();
    expect((float) $order->service_fee)->toBe(0.0);
    expect((float) $order->couvert_fee)->toBe(0.0);
    expect((float) $order->total)->toBe(8.0);
});

test('usuário com papel sem permissão de PDV recebe 403 ao acessar o terminal', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();

    // Papel "cozinha" (seedado por migration) só tem orders.view/orders.update — sem pdv.operate
    // nem pdv.waiter_operate.
    $userSemPermissao = User::factory()->create();
    $userSemPermissao->companies()->attach($company->id, ['role' => 'cozinha', 'branch_id' => $branch->id]);

    Livewire::actingAs($userSemPermissao)
        ->test(Terminal::class)
        ->assertForbidden();
});

test('garçom (só pdv.waiter_operate) recebe 403 ao acessar Terminal — venda direta é exclusiva de quem tem pdv.operate', function () {
    ['company' => $company, 'branch' => $branch] = pdvContext();
    $waiter = makeWaiter($company, $branch);

    Livewire::actingAs($waiter)
        ->test(Terminal::class)
        ->assertForbidden();
});

// ─── Auto-impressão de pedido chegando de fora (chat/site) via broadcast ──────

test('pedido de fora com item de cozinha dispara order-paid quando a filial tem impressora auto_print ativa', function () {
    ['admin' => $admin, 'branch' => $branch, 'company' => $company] = pdvContext();

    \App\Models\BranchPrinter::create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'station' => 'cozinha',
        'ip_address' => '192.168.0.20', 'port' => 9100, 'paper_width' => 80,
        'auto_print' => true, 'active' => true,
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('onOrderBroadcastReceived', [
            'order_id' => 999,
            'branch_id' => $branch->id,
            'order_type' => 'delivery',
            'is_kitchen' => true,
            'is_bar' => false,
        ])
        ->assertDispatched('order-paid', orderId: 999, printFiscalNote: false);
});

test('pedido com order_type pdv não dispara order-paid pelo broadcast — já tem impressão local própria', function () {
    ['admin' => $admin, 'branch' => $branch, 'company' => $company] = pdvContext();

    \App\Models\BranchPrinter::create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'station' => 'cozinha',
        'ip_address' => '192.168.0.20', 'port' => 9100, 'paper_width' => 80,
        'auto_print' => true, 'active' => true,
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('onOrderBroadcastReceived', [
            'order_id' => 998,
            'branch_id' => $branch->id,
            'order_type' => 'pdv',
            'is_kitchen' => true,
            'is_bar' => false,
        ])
        ->assertNotDispatched('order-paid');
});

test('pedido de fora de outra filial não dispara order-paid nesta tela', function () {
    ['admin' => $admin, 'branch' => $branch, 'company' => $company] = pdvContext();

    \App\Models\BranchPrinter::create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'station' => 'cozinha',
        'ip_address' => '192.168.0.20', 'port' => 9100, 'paper_width' => 80,
        'auto_print' => true, 'active' => true,
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('onOrderBroadcastReceived', [
            'order_id' => 997,
            'branch_id' => $branch->id + 999,
            'order_type' => 'delivery',
            'is_kitchen' => true,
            'is_bar' => false,
        ])
        ->assertNotDispatched('order-paid');
});

test('pedido de fora sem impressora auto_print ativa na estação não dispara order-paid', function () {
    ['admin' => $admin, 'branch' => $branch, 'company' => $company] = pdvContext();

    \App\Models\BranchPrinter::create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'station' => 'bar',
        'ip_address' => '192.168.0.20', 'port' => 9100, 'paper_width' => 80,
        'auto_print' => true, 'active' => true,
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('onOrderBroadcastReceived', [
            'order_id' => 996,
            'branch_id' => $branch->id,
            'order_type' => 'delivery',
            'is_kitchen' => true,
            'is_bar' => false,
        ])
        ->assertNotDispatched('order-paid');
});

test('duas telas de PDV abertas na mesma filial tentam imprimir o mesmo pedido de fora cada uma — sem trava de "só a primeira"', function () {
    // Sem lock de propósito: se uma tela sem impressora pareada vencesse a corrida de
    // travar o pedido, ele nunca sairia impresso em lugar nenhum. Preferimos o risco
    // raro de via duplicada (duas telas realmente pareadas com a mesma impressora) a
    // silenciosamente não imprimir.
    ['admin' => $admin, 'branch' => $branch, 'company' => $company] = pdvContext();

    \App\Models\BranchPrinter::create([
        'company_id' => $company->id, 'branch_id' => $branch->id, 'station' => 'cozinha',
        'ip_address' => '192.168.0.20', 'port' => 9100, 'paper_width' => 80,
        'auto_print' => true, 'active' => true,
    ]);

    $this->actingAs($admin);

    $payload = [
        'order_id' => 995,
        'branch_id' => $branch->id,
        'order_type' => 'delivery',
        'is_kitchen' => true,
        'is_bar' => false,
    ];

    Livewire::test(Terminal::class)
        ->call('onOrderBroadcastReceived', $payload)
        ->assertDispatched('order-paid', orderId: 995);

    Livewire::test(Terminal::class)
        ->call('onOrderBroadcastReceived', $payload)
        ->assertDispatched('order-paid', orderId: 995);
});

test('sidebar do caixa continua mostrando dashboard e painel completo', function () {
    ['admin' => $admin] = pdvContext();

    $this->actingAs($admin)
        ->get(route('admin.pdv.checkout'))
        ->assertOk()
        ->assertSee('Terminal PDV')
        ->assertSee('Dashboard');
});

test('seletor de PDV mostra as duas opções pra quem tem pdv.operate', function () {
    ['admin' => $admin] = pdvContext();

    $this->actingAs($admin)
        ->get(route('admin.pdv'))
        ->assertOk()
        ->assertSee('Venda Direta')
        ->assertSee('Mesas / Comandas');
});

// ─── Agendamento ────────────────────────────────────────────────────────────

test('agendamento fica indisponível quando empresa não habilita', function () {
    ['admin' => $admin, 'company' => $company] = pdvContext();

    $company->update(['schedule_min_advance_minutes' => 0]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->assertSet('schedulingEnabled', false);
});

test('pedido PDV agendado grava scheduled_at e status scheduled', function () {
    ['admin' => $admin, 'company' => $company, 'product' => $product] = pdvContext();

    $company->update(['schedule_min_advance_minutes' => 30]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Agendado',
        'phone' => '11999990000',
    ]);

    $this->actingAs($admin);

    $scheduleDate = now()->addDay()->format('Y-m-d');

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('customerId', $customer->id)
        ->set('isScheduled', true)
        ->set('scheduleDate', $scheduleDate)
        ->set('scheduleTime', '12:00')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertHasNoErrors()
        ->assertSet('step', 'catalog');

    $order = Order::withoutGlobalScopes()->first();
    expect($order)->not->toBeNull();
    expect($order->status)->toBe('scheduled');
    expect($order->scheduled_at)->not->toBeNull();
    expect($order->scheduled_at->format('Y-m-d H:i'))->toBe($scheduleDate.' 12:00');
    expect($order->customer_id)->toBe($customer->id);
});

test('pedido agendado exige cliente selecionado', function () {
    ['admin' => $admin, 'company' => $company, 'product' => $product] = pdvContext();

    $company->update(['schedule_min_advance_minutes' => 30]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('isScheduled', true)
        ->set('scheduleDate', now()->addDay()->format('Y-m-d'))
        ->set('scheduleTime', '12:00')
        ->call('processOrder')
        ->assertHasErrors('order');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

test('agendamento rejeita horário fora dos horários de agendamento configurados na filial', function () {
    ['admin' => $admin, 'company' => $company, 'branch' => $branch, 'product' => $product] = pdvContext();

    $company->update(['schedule_min_advance_minutes' => 30]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Agendado',
        'phone' => '11999990002',
    ]);

    $scheduled = now()->addDay()->setTime(12, 0);
    $dayOfWeek = (int) $scheduled->format('w');

    $branch->update([
        'scheduling_slots' => [$dayOfWeek => [['opens_at' => '06:00', 'closes_at' => '07:00']]],
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('customerId', $customer->id)
        ->set('isScheduled', true)
        ->set('scheduleDate', $scheduled->format('Y-m-d'))
        ->set('scheduleTime', $scheduled->format('H:i'))
        ->call('processOrder')
        ->assertHasErrors('scheduledAt');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

test('agendamento rejeita horário no passado', function () {
    ['admin' => $admin, 'company' => $company, 'product' => $product] = pdvContext();

    $company->update(['schedule_min_advance_minutes' => 30]);

    $customer = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Agendado',
        'phone' => '11999990001',
    ]);

    $this->actingAs($admin);

    Livewire::test(Terminal::class)
        ->call('addProduct', $product->id)
        ->call('proceedToPayment')
        ->set('customerId', $customer->id)
        ->set('isScheduled', true)
        ->set('scheduleDate', now()->subDay()->format('Y-m-d'))
        ->set('scheduleTime', '12:00')
        ->call('processOrder')
        ->assertHasErrors('scheduledAt');

    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});
