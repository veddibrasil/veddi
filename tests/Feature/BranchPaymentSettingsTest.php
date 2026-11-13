<?php

require_once __DIR__.'/Chat/endings_helpers.php';

use App\Livewire\Admin\Branches\PaymentSettings;
use App\Livewire\Admin\Pdv\TabTerminal;
use App\Livewire\Admin\Pdv\Terminal;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderService;
use App\Support\BranchPaymentMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

test('filial sem configuração mantém todas as formas atuais e modalidade inválida não permite pagamento', function () {
    $ctx = flowAuditContext();
    $branch = $ctx['branch']->fresh();
    foreach (BranchPaymentMethods::ORDER_TYPES as $channel => $types) {
        foreach ($types as $type => $label) {
            expect($branch->paymentMethodsFor($channel, $type))->toBe(array_keys(BranchPaymentMethods::LABELS[$channel]));
        }
    }
    expect($branch->paymentMethodsFor('pdv', 'invalid'))->toBe([]);
});

test('administrador configura pagamentos por canal e modalidade somente na filial escolhida', function () {
    $ctx = pdvContext();
    $other = Branch::create(['company_id' => $ctx['company']->id, 'name' => 'Outra filial']);
    $admin = User::factory()->create();
    $admin->companies()->attach($ctx['company'], ['role' => 'company_admin']);
    Cache::put("branches:company:{$ctx['company']->id}", 'cached');

    Livewire::actingAs($admin)->test(PaymentSettings::class, ['branch' => $ctx['branch']])
        ->assertSee('Chat / cardápio público')
        ->assertSee('Mesas e comandas')
        ->set('methods.chat.delivery', ['pix_online'])
        ->set('methods.chat.pickup', ['cash', 'card_on_receipt'])
        ->set('methods.pdv.entrega', ['pix'])
        ->set('methods.pdv.retirar', ['credit_card'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Formas de pagamento salvas.');

    $branch = $ctx['branch']->fresh();
    expect($branch->paymentMethodsFor('chat', 'delivery'))->toBe(['pix_online'])
        ->and($branch->paymentMethodsFor('chat', 'pickup'))->toBe(['cash', 'card_on_receipt'])
        ->and($branch->paymentMethodsFor('pdv', 'entrega'))->toBe(['pix'])
        ->and($branch->paymentMethodsFor('pdv', 'retirar'))->toBe(['credit_card'])
        ->and($other->fresh()->payment_methods)->toBeNull()
        ->and(Cache::has("branches:company:{$ctx['company']->id}"))->toBeFalse();

    Livewire::test(PaymentSettings::class, ['branch' => $branch])
        ->assertSet('methods.chat.delivery', ['pix_online']);
});

test('configuração recusa modalidade sem opção e método inválido', function (array $methods, string $field) {
    $ctx = pdvContext();
    Livewire::actingAs($ctx['admin'])->test(PaymentSettings::class, ['branch' => $ctx['branch']])
        ->set('methods.chat.delivery', $methods)
        ->call('save')
        ->assertHasErrors($field);
    expect($ctx['branch']->fresh()->payment_methods)->toBeNull();
})->with([
    'nenhuma opção' => [[], 'methods.chat.delivery'],
    'opção inválida' => [['invalid'], 'methods.chat.delivery.0'],
    'opção de outro canal' => [['credit_card'], 'methods.chat.delivery.0'],
]);

test('configuração protege empresa e filial e reconfere permissão ao salvar', function () {
    $ctx = pdvContext();
    $otherBranch = Branch::create(['company_id' => $ctx['company']->id, 'name' => 'Outra']);
    $manager = User::factory()->create();
    $manager->companies()->attach($ctx['company'], ['role' => 'branch_manager', 'branch_id' => $ctx['branch']->id]);
    Livewire::actingAs($manager)->test(PaymentSettings::class, ['branch' => $otherBranch])->assertForbidden();

    $component = Livewire::actingAs($manager)->test(PaymentSettings::class, ['branch' => $ctx['branch']]);
    $manager->companies()->updateExistingPivot($ctx['company']->id, ['branch_id' => $otherBranch->id]);
    $component->call('save')->assertForbidden();

    $foreign = flowAuditContext();
    app()->instance('current.company', $ctx['company']);
    $admin = User::factory()->create();
    $admin->companies()->attach($ctx['company'], ['role' => 'company_admin']);
    Livewire::actingAs($admin)->test(PaymentSettings::class, ['branch' => $foreign['branch']])->assertForbidden();
});

test('chat distingue forma online e ao receber e revalida configuração ao confirmar', function (string $type, string $method, bool $onReceipt, string $choice) {
    $ctx = flowAuditContext(stock: null);
    $chat = endingsChat($ctx);
    $chat->orderType = $type;
    $ctx['branch']->update(['payment_methods' => ['chat' => [$type => [$choice]]]]);

    $chat->selectPaymentMethod($method, $onReceipt);
    expect($chat->paymentMethod)->toBe($method)->and($chat->step)->toBe('CHECKOUT_CONFIRM');
    $ctx['branch']->update(['payment_methods' => ['chat' => [$type => [$choice === 'cash' ? 'pix_online' : 'cash']]]]);
    $chat->confirmOrder();
    expect($chat->orderId)->toBeNull()->and($chat->step)->toBe('CHECKOUT_PAYMENT_METHOD')
        ->and(Order::withoutGlobalScopes()->count())->toBe(0);
})->with([
    ['delivery', 'CASH', false, 'cash'],
    ['pickup', 'PIX', false, 'pix_online'],
    ['delivery', 'PIX', true, 'pix_on_receipt'],
    ['pickup', 'CARD', false, 'card_online'],
    ['delivery', 'CARD', true, 'card_on_receipt'],
]);

test('chat recusa seleção desativada e respeita diferença entre entrega e retirada', function () {
    $ctx = flowAuditContext();
    $ctx['branch']->update(['payment_methods' => ['chat' => ['pickup' => ['pix_online'], 'delivery' => ['cash']]]]);
    $chat = endingsChat($ctx);
    $chat->paymentMethod = '';
    $chat->selectPaymentMethod('CASH');
    expect($chat->paymentMethod)->toBe('');
    $chat->selectPaymentMethod('PIX', true);
    expect($chat->paymentMethod)->toBe('');
    $chat->orderType = 'delivery';
    $chat->selectPaymentMethod('CASH');
    expect($chat->paymentMethod)->toBe('CASH');
});

test('tela do chat mostra somente os pagamentos permitidos e identifica cupom incompatível', function () {
    $ctx = flowAuditContext();
    $ctx['branch']->update(['payment_methods' => ['chat' => ['pickup' => ['pix_online']]]]);
    $chat = endingsChat($ctx);
    $chat->backToPaymentMethod();

    $this->get('/'.$ctx['company']->slug)->assertOk()
        ->assertSeeHtml('wire:click="selectPaymentMethod(\'PIX\')"')
        ->assertDontSeeHtml('wire:click="selectPaymentMethod(\'PIX\', true)"')
        ->assertDontSeeHtml('wire:click="selectPaymentMethod(\'CASH\')"')
        ->assertDontSeeHtml('wire:click="selectPaymentMethod(\'CARD\')"')
        ->assertDontSeeHtml('wire:click="selectPaymentMethod(\'CARD\', true)"')
        ->assertSee('Pagar agora, pelo chat')
        ->assertDontSee('Pagar na retirada');

    $chat->appliedCoupon = ['id' => 1, 'code' => 'DINHEIRO', 'payment_method' => 'CASH'];
    $chat->backToPaymentMethod();
    $this->get('/'.$ctx['company']->slug)->assertOk()
        ->assertSee('O pagamento exigido pelo cupom não está disponível nesta modalidade.')
        ->assertSeeHtml('wire:click="removeCoupon"');
});

test('serviço recusa pagamento desativado antes de criar pedido ou consumir estoque', function () {
    $ctx = flowAuditContext();
    $ctx['branch']->update(['payment_methods' => ['chat' => ['pickup' => ['pix_online']]]]);
    expect(fn () => app(OrderService::class)->createOrder(
        customerId: $ctx['customer']->id,
        branchId: $ctx['branch']->id,
        cart: [$ctx['product']->id => ['qty' => 1]],
        notes: '', paymentMethod: 'CASH', orderType: 'pickup',
    ))->toThrow(RuntimeException::class, 'Esta forma de pagamento não está disponível');
    expect(Order::withoutGlobalScopes()->count())->toBe(0)
        ->and(flowAuditStock($ctx['branch'], $ctx['product']))->toBe(10);
});

test('PDV troca opções e seleção ao mudar modalidade e bloqueia método forjado', function () {
    $ctx = pdvContext();
    $ctx['branch']->update(['payment_methods' => ['pdv' => ['balcao' => ['pix'], 'entrega' => ['credit_card'], 'retirar' => ['cash']]]]);
    $component = Livewire::actingAs($ctx['admin'])->test(Terminal::class)
        ->call('addProduct', $ctx['product']->id)
        ->call('proceedToPayment')
        ->assertSet('paymentMethod', 'pix')
        ->assertSeeHtml('value="pix"')
        ->assertDontSeeHtml('value="credit_card"')
        ->set('deliveryType', 'entrega')
        ->assertSet('paymentMethod', 'credit_card')
        ->set('deliveryType', 'retirar')
        ->assertSet('paymentMethod', 'cash')
        ->set('deliveryType', 'balcao')
        ->set('paymentMethod', 'cash')
        ->call('processOrder')
        ->assertHasErrors('order');
    expect(Order::withoutGlobalScopes()->count())->toBe(0);
    $component->set('paymentMethod', 'pix')->call('processOrder')->assertHasNoErrors();
    expect(Order::withoutGlobalScopes()->sole()->payment_method)->toBe('pix');
});

test('PDV só oferece formas permitidas no split e revalida quando a configuração muda', function () {
    $ctx = pdvContext();
    $ctx['branch']->update(['payment_methods' => ['pdv' => ['balcao' => ['pix', 'credit_card']]]]);
    $component = Livewire::actingAs($ctx['admin'])->test(Terminal::class)
        ->call('addProduct', $ctx['product']->id)->call('proceedToPayment')
        ->set('isSplitPayment', true)
        ->assertSet('splitPayments.0.method', 'pix')
        ->assertSet('splitPayments.1.method', 'credit_card')
        ->set('splitPayments.0.amount', '4')->set('splitPayments.1.amount', '4');
    $ctx['branch']->update(['payment_methods' => ['pdv' => ['balcao' => ['pix']]]]);
    $component->call('processOrder')->assertHasErrors('order');
    expect(Order::withoutGlobalScopes()->count())->toBe(0);
});

test('comandas usam configuração de mesa no fechamento individual e em grupo', function (bool $group) {
    $ctx = pdvContext();
    $ctx['branch']->update(['payment_methods' => ['pdv' => ['mesa' => ['pix'], 'balcao' => ['cash']]]]);
    $table = openTable($ctx['company'], $ctx['branch']);
    $component = Livewire::actingAs($ctx['admin'])->test(TabTerminal::class)
        ->set('selectedTableId', $table->id)->call('addProduct', $ctx['product']->id);
    $order = Order::withoutGlobalScopes()->sole();
    if ($group) {
        $component->call('deselectOpenTab')->set('selectedTableId', $table->id)->call('addProduct', $ctx['product']->id)
            ->call('proceedToCloseTableTabs', $table->id)
            ->assertSet('splitPayments.0.method', 'pix')->assertSet('splitPayments.1.method', 'pix')
            ->set('isSplitPayment', false);
    } else {
        $component->call('proceedToCloseTab', $order->id);
    }
    $component->assertSet('paymentMethod', 'pix')->set('paymentMethod', 'cash')
        ->call($group ? 'confirmCloseTableTabs' : 'confirmCloseTab')->assertHasErrors('order');
    expect($order->fresh()->is_open_tab)->toBeTrue();
    $component->set('paymentMethod', 'pix')->call($group ? 'confirmCloseTableTabs' : 'confirmCloseTab')->assertHasNoErrors();
    expect($order->fresh()->is_open_tab)->toBeFalse()->and($order->fresh()->payment_method)->toBe('pix');
})->with([false, true]);
