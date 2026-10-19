<?php

use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessOrder;
use App\Livewire\Chat\OrderChat;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Drawer\Utils;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;

uses(RefreshDatabase::class);

/**
 * Chat público: o visitante só identifica o cliente pelo telefone, então os ids que decidem
 * dono/empresa (customerId, orderId, companyId) não podem vir do client, e toda ação sobre o
 * pedido confere o dono no servidor (cancelar dispara estorno e aviso de WhatsApp ao cliente real).
 *
 * @return array{company: Company, branch: Branch, owner: Customer, intruder: Customer, order: Order}
 */
function chatOwnershipContext(string $status = 'pending', string $paymentMethod = 'pix'): array
{
    $company = Company::create([
        'name' => 'Empresa Dono',
        'slug' => 'empresa-dono-'.uniqid(),
        'order_prefix' => 'OWN',
        'active' => true,
        'email' => 'contato@empresa.com',
    ]);

    app()->instance('current.company', $company);

    $branch = Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Filial',
        'active' => true,
        'opens_at' => '00:00:00',
        'closes_at' => '23:59:59',
    ]);

    $owner = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Cliente Dono',
        'phone' => '11999990001',
    ]);

    $intruder = Customer::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => 'Visitante',
        'phone' => '11988880002',
    ]);

    $order = Order::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'customer_id' => $owner->id,
        'status' => $status,
        'subtotal' => 40,
        'total' => 40,
        'payment_method' => $paymentMethod,
        'order_type' => 'delivery',
    ]);

    return compact('company', 'branch', 'owner', 'intruder', 'order');
}

/** Componente como o visitante o teria: identificado como $customer, mas com o orderId de outro pedido. */
function chatAs(Company $company, Customer $customer, ?Order $order): OrderChat
{
    $chat = new OrderChat;
    $chat->companyId = $company->id;
    $chat->customerId = $customer->id;
    verifiedChatFixture($chat, $customer);
    $chat->orderId = $order?->id;

    return $chat;
}

function lastBotMessage(OrderChat $chat): ?string
{
    return collect($chat->messages)->where('from', 'bot')->last()['text'] ?? null;
}

/**
 * Reproduz o ataque de verdade: abre o chat pela rota pública e manda ao endpoint do Livewire um
 * update com a propriedade adulterada (o harness Livewire::test não monta o OrderChat, que lê o
 * slug da rota).
 */
function tamperChatProperty(\Tests\TestCase $test, Company $company, string $property, mixed $value): \Illuminate\Testing\TestResponse
{
    $html = $test->get('/'.$company->slug)->assertOk()->getContent();
    $snapshot = Utils::extractAttributeDataFromHtml($html, 'wire:snapshot');

    expect($snapshot['memo']['name'] ?? null)->toBe('chat.order-chat');

    return $test->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'calls' => [],
            'updates' => [$property => $value],
        ]],
    ]);
}

// ── #[Locked] ─────────────────────────────────────────────────────────────────

test('o client não consegue trocar customerId, orderId nem companyId do chat', function (string $property) {
    ['company' => $company, 'order' => $order] = chatOwnershipContext();

    $this->withoutExceptionHandling();

    expect(fn () => tamperChatProperty($this, $company, $property, $order->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['customerId', 'orderId', 'companyId']);

test('update de propriedade comum do chat continua aceito (controle do teste acima)', function () {
    ['company' => $company] = chatOwnershipContext();

    tamperChatProperty($this, $company, 'notes', 'sem cebola')->assertOk();
});

test('sessão antiga sem verificação não restaura identidade nem pedido', function () {
    ['company' => $company, 'owner' => $owner, 'order' => $order] = chatOwnershipContext('awaiting_payment');

    $html = $this->withSession(['chat_state' => [
        'step' => 'PAYMENT_PIX',
        'companyId' => $company->id,
        'customerId' => $owner->id,
        'orderId' => $order->id,
    ]])->get('/'.$company->slug)->assertOk()->getContent();

    $data = Utils::extractAttributeDataFromHtml($html, 'wire:snapshot')['data'];

    expect($data['companyId'])->toBe($company->id)
        ->and($data['customerId'])->toBeNull()
        ->and($data['orderId'])->toBeNull();
});

// ── Cancelamento ──────────────────────────────────────────────────────────────

test('visitante não cancela pedido de outro cliente da mesma empresa', function (string $status) {
    Event::fake([OrderStatusUpdated::class]);

    ['company' => $company, 'intruder' => $intruder, 'order' => $order] = chatOwnershipContext($status);

    $chat = chatAs($company, $intruder, $order);

    $chat->requestCancelOrder();
    expect($chat->showCancelConfirm)->toBeFalse()
        ->and(lastBotMessage($chat))->toBe('Não é possível cancelar o pedido no momento. Entre em contato com a loja.');

    $chat->confirmCancelOrder();
    expect(lastBotMessage($chat))->toBe('Não foi possível cancelar o pedido. Entre em contato com a loja.')
        ->and($chat->step)->not->toBe('ORDER_CANCELLED');

    expect($order->fresh()->status)->toBe($status);
    Event::assertNotDispatched(OrderStatusUpdated::class);
})->with(['pending', 'awaiting_payment', 'paid']);

test('cliente cancela o próprio pedido pelo chat', function () {
    Event::fake([OrderStatusUpdated::class]);

    ['company' => $company, 'owner' => $owner, 'order' => $order] = chatOwnershipContext('awaiting_payment');

    $chat = chatAs($company, $owner, $order);

    $chat->requestCancelOrder();
    expect($chat->showCancelConfirm)->toBeTrue();

    $chat->confirmCancelOrder();

    expect($order->fresh()->status)->toBe('cancelled')
        ->and($chat->step)->toBe('ORDER_CANCELLED');
    Event::assertDispatched(OrderStatusUpdated::class, fn (OrderStatusUpdated $e) => $e->order->id === $order->id);
});

test('pedido de outra empresa não é alcançado pelo chat', function () {
    ['company' => $company, 'owner' => $owner] = chatOwnershipContext();
    ['order' => $foreignOrder] = chatOwnershipContext('awaiting_payment');

    // Visitante no chat da primeira empresa apontando para o pedido da segunda.
    app()->instance('current.company', $company);
    $chat = chatAs($company, $owner, $foreignOrder);

    $chat->confirmCancelOrder();

    expect($foreignOrder->fresh()->status)->toBe('awaiting_payment');
});

test('OrderService::cancelOrder recusa cliente que não é o dono do pedido', function () {
    ['intruder' => $intruder, 'order' => $order] = chatOwnershipContext('paid');

    expect(fn () => app(OrderService::class)->cancelOrder($order, $intruder->id))
        ->toThrow(\RuntimeException::class, 'Pedido não pertence ao cliente.');

    expect($order->fresh()->status)->toBe('paid');
});

// ── Demais ações sobre o pedido ───────────────────────────────────────────────

test('visitante não lê o PIX de pedido de outro cliente pelo polling de status', function () {
    ['company' => $company, 'intruder' => $intruder, 'order' => $order] = chatOwnershipContext('awaiting_payment');

    Payment::create([
        'order_id' => $order->id,
        'payment_gateway' => 'vindi',
        'amount' => 40,
        'status' => 'pending',
        'pix_qr_code' => 'QR-DE-OUTRO-CLIENTE',
        'pix_copy_paste' => 'COPIA-COLA-DE-OUTRO-CLIENTE',
        'payment_token' => 'token-outro-cliente',
    ]);

    $chat = chatAs($company, $intruder, $order);
    $chat->step = 'PAYMENT_PIX';

    $chat->checkPaymentStatus();

    expect($chat->pixCopyPaste)->toBeNull()
        ->and($chat->pixQrCode)->toBeNull()
        ->and($chat->lastNotifiedStatus)->toBeNull();
});

test('visitante não renova o PIX nem cobra cartão em pedido de outro cliente', function () {
    Queue::fake();

    ['company' => $company, 'intruder' => $intruder, 'order' => $order] = chatOwnershipContext('awaiting_payment');

    $chat = chatAs($company, $intruder, $order);
    $chat->paymentMethod = 'PIX';
    $chat->handlePaymentExpired();

    Queue::assertNotPushed(ProcessOrder::class);

    $chat->paymentMethod = 'CARD';
    $chat->useNewCard = true;
    $chat->cardNumber = '4111111111111111';
    $chat->cardExpiry = '12/30';
    $chat->cardCvv = '123';
    $chat->cardHolderName = 'Visitante';
    $chat->submitCardPayment();

    expect($chat->cardError)->toBe('Pedido não encontrado. Tente novamente.')
        ->and(Payment::where('order_id', $order->id)->exists())->toBeFalse();
});

test('trocar forma de pagamento não cancela pedido de outro cliente', function () {
    Event::fake([OrderStatusUpdated::class]);

    ['company' => $company, 'intruder' => $intruder, 'order' => $order] = chatOwnershipContext('pending');

    $chat = chatAs($company, $intruder, $order);
    $chat->changePaymentMethod();

    expect($order->fresh()->status)->toBe('pending')
        ->and($chat->step)->not->toBe('CHECKOUT_PAYMENT_METHOD');
    Event::assertNotDispatched(OrderStatusUpdated::class);
});

// ── Trocar forma de pagamento ────────────────────────────────────────────────

test('trocar forma de pagamento cancela o pedido pendente e dispara OrderStatusUpdated', function () {
    Event::fake([OrderStatusUpdated::class]);

    ['company' => $company, 'owner' => $owner, 'order' => $order] = chatOwnershipContext('pending', 'credit_card');

    $chat = chatAs($company, $owner, $order);
    $chat->changePaymentMethod();

    expect($order->fresh()->status)->toBe('cancelled')
        ->and($chat->step)->toBe('CHECKOUT_PAYMENT_METHOD');
    Event::assertDispatched(
        OrderStatusUpdated::class,
        fn (OrderStatusUpdated $e) => $e->order->id === $order->id && $e->order->status === 'cancelled',
    );
});
