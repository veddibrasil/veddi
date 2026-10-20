<?php

use App\Contracts\ChatCodeSender;
use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessOrder;
use App\Livewire\Chat\OrderChat;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\PhoneVerification;
use App\Services\Chat\RememberedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Audit/Flows/flows_helpers.php';

uses(RefreshDatabase::class);

function securityGuestChat(array $ctx): OrderChat
{
    $chat = new OrderChat;
    $chat->companyId = $ctx['company']->id;
    $chat->selectedBranchId = $ctx['branch']->id;
    $chat->cart = [$ctx['product']->id => ['qty' => 1, 'price' => 0.01, 'name' => 'Item']];
    $chat->phone = $ctx['customer']->phone;
    $chat->submitPhone();
    $chat->continueAsGuest();
    $chat->name = 'Comprador visitante';
    $chat->submitName();
    $chat->orderType = 'pickup';
    $chat->paymentMethod = 'CASH';

    return $chat;
}

beforeEach(function () {
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
    Queue::fake();
});

test('compra visitante com telefone existente conclui sem criar ou alterar perfil e só acessa o pedido criado', function () {
    $ctx = flowAuditContext();
    $original = $ctx['customer']->fresh()->getAttributes();
    $chat = securityGuestChat($ctx);
    $chat->confirmOrder();
    $order = Order::withoutGlobalScopes()->findOrFail($chat->orderId);

    expect($order->customer_id)->toBeNull()
        ->and($order->customer->name)->toBe('Comprador visitante')
        ->and($order->customer->phone)->toBe($ctx['customer']->phone)
        ->and((float) $order->total)->toBe(20.0)
        ->and($ctx['customer']->fresh()->getAttributes())->toBe($original)
        ->and(Customer::withoutGlobalScopes()->count())->toBe(1)
        ->and(app(ChatAccess::class)->canAccessOrder($order, true))->toBeTrue()
        ->and(app(ChatAccess::class)->customer($ctx['company']->id))->toBeNull();
    $chat->goToOrderHistory();
    $chat->openEditProfile();
    expect($chat->orderHistory)->toBe([])->and($chat->step)->toBe('ORDER_CONFIRMED');
    $chat->confirmOrder();
    expect(Order::withoutGlobalScopes()->count())->toBe(1);
});

test('pedido preserva contato e endereço próprios após edição do perfil', function () {
    $ctx = flowAuditContext();
    $chat = securityGuestChat($ctx);
    $chat->orderType = 'delivery';
    $chat->address = 'Rua do visitante';
    $chat->number = '50';
    $chat->neighborhood = 'Centro';
    $chat->city = 'São Paulo';
    $chat->cep = '01001000';
    $chat->confirmOrder();
    $ctx['customer']->update(['name' => 'Perfil alterado', 'address' => 'Outra rua']);
    $order = Order::withoutGlobalScopes()->with('customer')->findOrFail($chat->orderId);
    expect($order->delivery_address)->toBe('Rua do visitante')
        ->and($order->customer->address)->toBe('Rua do visitante')
        ->and($order->customer->name)->toBe('Comprador visitante');
});

test('job de PIX visitante sobrevive serialização sem persistir um Customer artificial', function () {
    $ctx = flowAuditContext();
    $chat = securityGuestChat($ctx);
    $chat->paymentMethod = 'PIX';
    $chat->taxId = '52998224725';
    $chat->confirmOrder();
    Queue::assertPushed(ProcessOrder::class, function ($job) {
        $restored = unserialize(serialize($job));
        expect($restored->customer)->toBeNull()
            ->and($restored->order->customer->name)->toBe('Comprador visitante');

        return true;
    });
});

test('link exclusivo só acompanha um pedido e não permite cancelar renovar PIX ou recuperar perfil', function () {
    $ctx = flowAuditContext();
    $chat = securityGuestChat($ctx);
    $chat->confirmOrder();
    $order = Order::withoutGlobalScopes()->findOrFail($chat->orderId);
    $token = explode('#pedido=', $chat->trackingLink)[1];
    app(ChatAccess::class)->redeemOrderLink($ctx['company']->id, $token);
    expect(app(ChatAccess::class)->canAccessOrder($order, true))->toBeTrue();
    session()->flush();
    expect(app(ChatAccess::class)->redeemOrderLink($ctx['company']->id + 1, $token))->toBeNull();
    $reader = new OrderChat;
    $reader->companyId = $ctx['company']->id;
    $reader->redeemTrackingLink($token);
    expect($reader->orderId)->toBe($order->id)
        ->and(app(ChatAccess::class)->canAccessOrder($order))->toBeTrue()
        ->and(app(ChatAccess::class)->canAccessOrder($order, true))->toBeFalse();
    $reader->confirmCancelOrder();
    $reader->handlePaymentExpired();
    $reader->goToOrderHistory();
    expect($order->fresh()->status)->toBe('paid')
        ->and($reader->orderHistory)->toBe([]);
    DB::table('chat_order_access')->update(['revoked_at' => now()]);
    expect(app(ChatAccess::class)->canAccessOrder($order))->toBeFalse();
    session()->flush();
    expect(app(ChatAccess::class)->redeemOrderLink($ctx['company']->id, $token))->toBeNull();
});

function captureSecurityCode(): object
{
    $sender = new class implements ChatCodeSender
    {
        public string $code = '';

        public function send(int $companyId, string $phone, string $code): void
        {
            $this->code = $code;
        }
    };
    app()->instance(ChatCodeSender::class, $sender);

    return $sender;
}

test('código está vinculado ao telefone empresa e sessão e é consumido apenas uma vez', function () {
    $ctx = flowAuditContext();
    $sender = captureSecurityCode();
    $service = app(PhoneVerification::class);
    $id = $service->send($ctx['company']->id, $ctx['customer']->phone);
    $nonce = session('chat_challenge_nonce');
    $code = $sender->code;
    $stored = DB::table('chat_phone_challenges')->find($id);
    expect($stored->code_hash)->not->toBe($code);
    expect($service->consume($id, $ctx['company']->id + 1, $ctx['customer']->phone, $code))->toBeFalse();
    expect($service->consume($id, $ctx['company']->id, '11988887777', $code))->toBeFalse();
    session()->forget('chat_challenge_nonce');
    expect($service->consume($id, $ctx['company']->id, $ctx['customer']->phone, $code))->toBeFalse();
    session(['chat_challenge_nonce' => $nonce]);
    expect($service->consume($id, $ctx['company']->id, $ctx['customer']->phone, $code))->toBeTrue()
        ->and($service->consume($id, $ctx['company']->id, $ctx['customer']->phone, $code))->toBeFalse();
});

test('código expirado substituído ou com cinco erros é recusado', function (string $scenario) {
    $ctx = flowAuditContext();
    $sender = captureSecurityCode();
    $service = app(PhoneVerification::class);
    $id = $service->send($ctx['company']->id, $ctx['customer']->phone);
    $code = $sender->code;
    if ($scenario === 'expired') {
        $this->travel(6)->minutes();
    } elseif ($scenario === 'replaced') {
        $this->travel(61)->seconds();
        $service->send($ctx['company']->id, $ctx['customer']->phone);
    } else {
        for ($i = 0; $i < 5; $i++) {
            expect($service->consume($id, $ctx['company']->id, $ctx['customer']->phone, 'invalid'))->toBeFalse();
        }
    }
    expect($service->consume($id, $ctx['company']->id, $ctx['customer']->phone, $code))->toBeFalse();
})->with(['expired', 'replaced', 'attempts']);

test('verificação libera dados somente da empresa e falha de envio preserva carrinho e caminho visitante', function () {
    $ctx = flowAuditContext();
    $sender = captureSecurityCode();
    $chat = securityGuestChat($ctx);
    $cart = $chat->cart;
    $chat->requestVerificationCode();
    $chat->verificationCode = $sender->code;
    $chat->submitVerificationCode();
    expect($chat->customerId)->toBe($ctx['customer']->id)
        ->and($chat->name)->toBe($ctx['customer']->name)
        ->and(app(ChatAccess::class)->customer($ctx['company']->id + 1))->toBeNull();
    app()->instance(ChatCodeSender::class, new \App\Services\Chat\UnavailableCodeSender);
    $this->travel(61)->seconds();
    $chat->requestVerificationCode();
    $chat->continueAsGuest();
    expect($chat->cart)->toBe($cart)->and($chat->customerId)->toBeNull()->and($chat->step)->toBe('REGISTER_NAME');
});

test('sessão expirada revogada ou telefone alterado não restaura identidade', function (string $scenario) {
    $ctx = flowAuditContext();
    app(ChatAccess::class)->verify($ctx['company']->id, $ctx['customer']->phone);
    if ($scenario === 'expired') {
        $this->travel(121)->minutes();
    } elseif ($scenario === 'phone') {
        $ctx['customer']->update(['phone' => '11988887777']);
    } else {
        app(RememberedDevice::class)->remember($ctx['customer']);
        DB::table('chat_remembered_devices')->update(['revoked_at' => now()]);
    }
    expect(app(ChatAccess::class)->customer($ctx['company']->id))->toBeNull();
})->with(['expired', 'phone', 'revoked']);

test('visitante não lista nem seleciona cartão salvo mesmo com id de cliente conhecido', function () {
    $ctx = flowAuditContext();
    $card = $ctx['customer']->saveVindiCardToken('saved-secret', '4111111111111111', 'visa');
    $chat = securityGuestChat($ctx);
    $chat->customerId = $ctx['customer']->id;
    $chat->selectSavedCard($card->id);
    expect($chat->selectedCardId)->toBeNull()->and($chat->customerCards)->toBe([]);
});

test('e-mails visitante serializam e usam o contato do pedido', function () {
    $ctx = flowAuditContext();
    $chat = securityGuestChat($ctx);
    $chat->email = 'visitante@example.com';
    $chat->confirmOrder();
    $order = Order::withoutGlobalScopes()->findOrFail($chat->orderId);
    foreach ([\App\Mail\OrderConfirmation::class, \App\Mail\OrderDelivered::class] as $mailClass) {
        $mail = unserialize(serialize(new $mailClass($order, $order->customer, $ctx['company'])));
        expect($mail->render())->toContain('Comprador visitante');
    }
});

test('página de acompanhamento não carrega pixels mapas ou fontes de terceiros', function () {
    $ctx = flowAuditContext();
    $this->get(route('chat.tracking', ['company' => $ctx['company']->slug]))
        ->assertOk()->assertSee('no-referrer')
        ->assertDontSee('unpkg.com')->assertDontSee('fonts.bunny.net')
        ->assertDontSee('connect.facebook.net');
});

test('compra visitante completa pelo endpoint Livewire sem código ou endereço para retirada', function () {
    $ctx = flowAuditContext();
    $html = $this->get('/'.$ctx['company']->slug)->assertOk()->getContent();
    $snapshot = json_encode(\Livewire\Drawer\Utils::extractAttributeDataFromHtml($html, 'wire:snapshot'));
    $call = function (string $method, array $params = [], array $updates = []) use (&$snapshot) {
        $response = $this->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'calls' => [['path' => '', 'method' => $method, 'params' => $params]], 'updates' => $updates]],
        ])->assertOk();
        $snapshot = $response->json('components.0.snapshot');

        return json_decode($snapshot, true)['data'];
    };
    $data = $call('confirmCart', updates: ['cart' => [$ctx['product']->id => ['qty' => 1, 'price' => 20, 'name' => 'Coxinha']]]);
    expect($data['step'])->toBe('CHECKOUT_ORDER_TYPE');
    $call('selectOrderType', ['pickup']);
    $data = $call('submitPhone', updates: ['phone' => $ctx['customer']->phone]);
    expect($data['step'])->toBe('CUSTOMER_OPTIONS')->and($data['name'])->toBe('');
    $call('continueAsGuest');
    $call('submitName', updates: ['name' => 'Pessoa visitante']);
    $data = $call('skipCoupon');
    expect($data['step'])->toBe('CHECKOUT_SCHEDULE');
    $data = $call('selectScheduleOption', ['now']);
    expect($data['step'])->toBe('CHECKOUT_NOTES');
    $call('proceedFromNotes');
    $call('selectPaymentMethod', ['CASH']);
    $data = $call('confirmOrder');
    expect($data['step'])->toBe('ORDER_CONFIRMED')
        ->and(Order::withoutGlobalScopes()->findOrFail($data['orderId'])->customer_id)->toBeNull();
});

test('dispositivo lembrado rotaciona token sem estender validade e não libera confirmação recente', function () {
    $ctx = flowAuditContext();
    app(ChatAccess::class)->verify($ctx['company']->id, $ctx['customer']->phone);
    app(RememberedDevice::class)->remember($ctx['customer']);
    $cookie = collect(\Illuminate\Support\Facades\Cookie::getQueuedCookies())->first(fn ($cookie) => $cookie->getName() === 'chat_device_'.$ctx['company']->id);
    $original = DB::table('chat_remembered_devices')->first();
    expect($cookie->isSecure())->toBeTrue()->and($cookie->isHttpOnly())->toBeTrue()->and($cookie->getSameSite())->toBe('lax');
    session()->flush();
    request()->cookies->set($cookie->getName(), $cookie->getValue());
    $this->travel(1)->day();
    app(RememberedDevice::class)->restore($ctx['company']->id);
    $rotated = DB::table('chat_remembered_devices')->first();
    expect($rotated->token_hash)->not->toBe($original->token_hash)
        ->and($rotated->expires_at)->toBe($original->expires_at)
        ->and(app(ChatAccess::class)->customer($ctx['company']->id)?->id)->toBe($ctx['customer']->id)
        ->and(app(ChatAccess::class)->customer($ctx['company']->id, true))->toBeNull();
    session()->flush();
    app(RememberedDevice::class)->restore($ctx['company']->id);
    expect(app(ChatAccess::class)->customer($ctx['company']->id))->toBeNull();
});

test('exceções de Livewire não registram snapshot código ou token no contexto', function () {
    $request = \Illuminate\Http\Request::create('/livewire-abcd1234/update', 'POST', [
        'components' => [['snapshot' => 'private-profile', 'updates' => ['verificationCode' => '762593'], 'calls' => [['params' => ['secret-order-token']]]]],
    ]);
    $request->headers->set('X-Livewire', 'true');
    app()->instance('request', $request);
    $handler = new class(app()) extends \App\Exceptions\Handler
    {
        public function exposedContext(): array
        {
            return $this->context();
        }
    };
    $context = $handler->exposedContext();
    expect($context['input'])->toBe(['livewire' => '[redacted]'])
        ->and(json_encode($context))->not->toContain('762593', 'secret-order-token', 'private-profile');
});
