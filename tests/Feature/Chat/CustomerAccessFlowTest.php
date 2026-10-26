<?php

use App\Contracts\ChatCodeSender;
use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Mail\ChatVerificationCode;
use App\Models\Order;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\PhoneVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Drawer\Utils;

require_once __DIR__.'/../Audit/Flows/flows_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['chat_security.code_channel' => 'email']);
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
    Queue::fake();
    Mail::fake();
});

function customerAccessBrowser(Tests\TestCase $test, string $slug): object
{
    return new class($test, $slug)
    {
        public string $snapshot;

        public string $html;

        public array $data;

        public array $effects = [];

        public function __construct(private Tests\TestCase $test, private string $slug)
        {
            $this->visit();
        }

        public function visit(): void
        {
            $response = $this->test->get('/'.$this->slug)->assertOk();
            $this->html = $response->getContent();
            $this->snapshot = json_encode(Utils::extractAttributeDataFromHtml($this->html, 'wire:snapshot'));
            $this->data = json_decode($this->snapshot, true)['data'];
            $this->rememberCookies($response);
        }

        public function call(string $method, array $params = [], array $updates = []): void
        {
            $response = $this->test->withCredentials()->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
                'components' => [['snapshot' => $this->snapshot, 'calls' => [['path' => '', 'method' => $method, 'params' => $params]], 'updates' => $updates]],
            ])->assertOk();
            $this->snapshot = $response->json('components.0.snapshot');
            $this->data = json_decode($this->snapshot, true)['data'];
            $this->html = $response->json('components.0.effects.html') ?? $this->html;
            $this->effects = $response->json('components.0.effects') ?? [];
            $this->rememberCookies($response);
        }

        private function rememberCookies($response): void
        {
            foreach ($response->headers->getCookies() as $cookie) {
                $this->test->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
            }
            // Each HTTP response has its own CookieJar in a real server lifecycle.
            // Do not let queued tokens substitute for cookies missing from a later request.
            foreach (Cookie::getQueuedCookies() as $cookie) {
                Cookie::unqueue($cookie->getName(), $cookie->getPath());
            }
        }
    };
}

function customerAccessCart(object $browser, array $ctx): void
{
    $browser->call('confirmCart', updates: ['cart' => [$ctx['product']->id => ['qty' => 1, 'price' => 20, 'name' => 'Coxinha']]]);
    $browser->call('selectOrderType', ['pickup']);
}

function customerAccessFinish(object $browser): Order
{
    $browser->call('skipCoupon');
    $browser->call('selectScheduleOption', ['now']);
    $browser->call('proceedFromNotes');
    $browser->call('selectPaymentMethod', ['CASH']);
    $browser->call('confirmOrder');
    expect($browser->data['step'])->toBe('ORDER_CONFIRMED');

    return Order::withoutGlobalScopes()->where('company_id', $browser->data['companyId'])->findOrFail($browser->data['orderId']);
}

test('visitante novo escolhe preencher e conclui pedido sem perfil salvo', function () {
    $ctx = flowAuditContext();
    $browser = customerAccessBrowser($this, $ctx['company']->slug);
    customerAccessCart($browser, $ctx);
    $browser->call('submitPhone', updates: ['phone' => '11988887777']);
    expect($browser->data['step'])->toBe('CUSTOMER_OPTIONS')
        ->and($browser->html)->toContain('Já tenho cadastro: usar meus dados salvos', 'Preencher meus dados', 'Enviaremos um código para o e-mail cadastrado.')
        ->and($browser->html)->not->toContain('Continuar como');
    $browser->call('continueAsGuest');
    $browser->call('submitName', updates: ['name' => 'Visitante novo']);
    $order = customerAccessFinish($browser);
    expect($order->customer_id)->toBeNull()->and($order->buyer_data['name'])->toBe('Visitante novo');
});

test('visitante volta no mesmo navegador e reutiliza dados em novo pedido', function () {
    $ctx = flowAuditContext();
    $browser = customerAccessBrowser($this, $ctx['company']->slug);
    customerAccessCart($browser, $ctx);
    $browser->call('submitPhone', updates: ['phone' => $ctx['customer']->phone]);
    $browser->call('continueAsGuest');
    $browser->call('submitName', updates: ['name' => 'Nome digitado pelo visitante']);
    $first = customerAccessFinish($browser);
    session()->flush();
    session()->save();
    $browser->visit();
    customerAccessCart($browser, $ctx);
    $browser->call('submitPhone', updates: ['phone' => $ctx['customer']->phone]);
    expect($browser->data['step'])->toBe('CUSTOMER_OPTIONS')
        ->and($browser->data['name'])->toBe('')
        // Primeiro nome do que este navegador digitou; o perfil salvo fica como opção secundária.
        ->and($browser->html)->toContain('Continuar como Nome')
        ->and(strpos($browser->html, 'Continuar como Nome'))->toBeLessThan(strpos($browser->html, 'Preencher meus dados'))
        ->and(strpos($browser->html, 'Preencher meus dados'))->toBeLessThan(strpos($browser->html, 'Já tenho cadastro'));
    $browser->call('useDeviceData');
    expect($browser->data['name'])->toBe('Nome digitado pelo visitante')
        ->and($browser->data['customerId'])->toBeNull()
        ->and($browser->data['step'])->toBe('CHECKOUT_COUPON');
    $second = customerAccessFinish($browser);
    expect($second->id)->not->toBe($first->id)
        ->and($second->customer_id)->toBeNull()
        ->and($second->buyer_data['name'])->toBe('Nome digitado pelo visitante');
    Mail::assertNotQueued(ChatVerificationCode::class);
});

test('perfil confirmado por email lembra aparelho e próxima visita entra identificado', function () {
    $ctx = flowAuditContext();
    $browser = customerAccessBrowser($this, $ctx['company']->slug);
    customerAccessCart($browser, $ctx);
    $browser->call('submitPhone', updates: ['phone' => $ctx['customer']->phone]);
    $browser->call('useSavedData');
    expect($browser->data['step'])->toBe('VERIFY_PHONE')
        ->and($browser->data['rememberDevice'])->toBeTrue()
        ->and($browser->html)->toContain('Reenviar código', 'Continuar preenchendo meus dados');
    $browser->call('submitVerificationCode', updates: ['verificationCode' => Mail::queued(ChatVerificationCode::class)->sole()->code]);
    expect($browser->data['customerId'])->toBe($ctx['customer']->id)
        ->and(DB::table('chat_remembered_devices')->where('company_id', $ctx['company']->id)->count())->toBe(1);
    $order = customerAccessFinish($browser);
    expect($order->customer_id)->toBe($ctx['customer']->id);
    session()->flush();
    session()->save();
    $browser->visit();
    expect($browser->data['customerId'])->toBe($ctx['customer']->id)
        ->and($browser->data['name'])->toBe($ctx['customer']->name)
        ->and($browser->data['checkoutReady'])->toBeTrue()
        ->and($browser->data['useSavedProfile'])->toBeTrue()
        ->and(app(ChatAccess::class)->customer($ctx['company']->id, true))->toBeNull();
    customerAccessCart($browser, $ctx);
    expect($browser->data['step'])->not->toBe('IDENTIFY_PHONE');
    Mail::assertQueuedCount(1);
});

test('cliente restaurado na próxima visita recebe a etapa de cupom e o desconto entra no pedido', function () {
    $ctx = flowAuditContext();
    $coupon = \App\Models\Coupon::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'code' => 'VOLTA10', 'name' => '10% off',
        'type' => 'percentage', 'discount_value' => 10, 'scope' => 'order', 'active' => true,
    ]);
    $browser = customerAccessBrowser($this, $ctx['company']->slug);
    customerAccessCart($browser, $ctx);
    $browser->call('submitPhone', updates: ['phone' => $ctx['customer']->phone]);
    $browser->call('useSavedData');
    $browser->call('submitVerificationCode', updates: ['verificationCode' => Mail::queued(ChatVerificationCode::class)->sole()->code]);
    customerAccessFinish($browser);
    session()->flush();
    session()->save();
    $browser->visit();
    expect($browser->data['checkoutReady'])->toBeTrue();
    $browser->call('confirmCart', updates: ['cart' => [$ctx['product']->id => ['qty' => 1, 'price' => 20, 'name' => 'Coxinha']]]);
    expect($browser->data['step'])->toBe('CHECKOUT_COUPON');
    $browser->call('applyCoupon', updates: ['couponInput' => 'VOLTA10']);
    expect($browser->data['step'])->toBe('CHECKOUT_ORDER_TYPE')
        ->and($browser->data['couponDiscount'])->toEqual(2.0);
    $browser->call('selectOrderType', ['pickup']);
    $browser->call('selectScheduleOption', ['now']);
    $browser->call('proceedFromNotes');
    $browser->call('selectPaymentMethod', ['CASH']);
    $browser->call('confirmOrder');
    $order = Order::withoutGlobalScopes()->where('company_id', $ctx['company']->id)->findOrFail($browser->data['orderId']);
    expect($order->customer_id)->toBe($ctx['customer']->id)
        ->and($order->coupon_id)->toBe($coupon->id)
        ->and((float) $order->discount)->toBe(2.0);
});

test('voltar ao carrinho com dados preenchidos oferece o cupom de novo e permite trocar o tipo', function () {
    $ctx = flowAuditContext();
    $browser = customerAccessBrowser($this, $ctx['company']->slug);
    customerAccessCart($browser, $ctx);
    $browser->call('submitPhone', updates: ['phone' => '11988887777']);
    $browser->call('continueAsGuest');
    $browser->call('submitName', updates: ['name' => 'Visitante']);
    expect($browser->data['step'])->toBe('CHECKOUT_COUPON');
    $browser->call('skipCoupon');
    $browser->call('backToCartReview');
    $browser->call('confirmCart');
    expect($browser->data['step'])->toBe('CHECKOUT_COUPON')
        ->and($browser->data['orderTypeChosen'])->toBeFalse();
    $browser->call('skipCoupon');
    expect($browser->data['step'])->toBe('CHECKOUT_ORDER_TYPE');
});

test('canal indisponível sem dados do aparelho segue direto para preencher', function (string $channel) {
    config(['chat_security.code_channel' => $channel, 'chat_security.whatsapp_auth_enabled' => false]);
    $ctx = flowAuditContext();
    $browser = customerAccessBrowser($this, $ctx['company']->slug);
    customerAccessCart($browser, $ctx);
    $browser->call('submitPhone', updates: ['phone' => $ctx['customer']->phone]);
    expect($browser->data['step'])->toBe('REGISTER_NAME')
        ->and($browser->data['customerId'])->toBeNull()
        ->and($browser->data['name'])->toBe('');
    $browser->call('submitName', updates: ['name' => 'Visitante sem código']);
    expect(customerAccessFinish($browser)->customer_id)->toBeNull();
})->with(['none', 'invalid', 'whatsapp']);

test('dados deste aparelho continuam disponíveis com canal none', function () {
    config(['chat_security.code_channel' => 'none']);
    $ctx = flowAuditContext();
    $browser = customerAccessBrowser($this, $ctx['company']->slug);
    customerAccessCart($browser, $ctx);
    $browser->call('submitPhone', updates: ['phone' => $ctx['customer']->phone]);
    $browser->call('submitName', updates: ['name' => 'Visitante sem canal']);
    customerAccessFinish($browser);
    session()->flush();
    session()->save();
    $browser->visit();
    customerAccessCart($browser, $ctx);
    $browser->call('submitPhone', updates: ['phone' => $ctx['customer']->phone]);
    expect($browser->data['step'])->toBe('CUSTOMER_OPTIONS')
        ->and($browser->html)->toContain('Continuar como Visitante', 'Preencher meus dados')
        ->and($browser->html)->not->toContain('Já tenho cadastro');
});

test('desmarcar lembrar aparelho persiste preferência e não cria dispositivo autenticado', function () {
    $ctx = flowAuditContext();
    $browser = customerAccessBrowser($this, $ctx['company']->slug);
    customerAccessCart($browser, $ctx);
    $browser->call('submitPhone', updates: ['phone' => $ctx['customer']->phone]);
    $browser->call('useSavedData');
    $browser->call('submitVerificationCode', updates: [
        'rememberDevice' => false,
        'verificationCode' => Mail::queued(ChatVerificationCode::class)->sole()->code,
    ]);
    expect(session('chat_state.rememberDevice'))->toBeFalse()
        ->and(DB::table('chat_remembered_devices')->count())->toBe(0);
    $browser->call('startNewOrder');
    expect($browser->data['rememberDevice'])->toBeTrue();
});

test('limite da empresa aceita sessenta códigos por dez minutos e bloqueia o seguinte', function () {
    $ctx = flowAuditContext();
    $companyId = $ctx['company']->id;
    $key = "chat-otp:company:{$companyId}";
    app()->instance(ChatCodeSender::class, new class implements ChatCodeSender
    {
        public function send(int $companyId, string $phone, string $code): void {}
    });
    for ($i = 0; $i < 59; $i++) {
        RateLimiter::hit($key, 600);
    }
    $service = app(PhoneVerification::class);
    $service->send($companyId, $ctx['customer']->phone);
    expect(RateLimiter::attempts($key))->toBe(60);
    session()->forget('chat_challenge_nonce');
    expect(fn () => $service->send($companyId, $ctx['customer']->phone))->toThrow(RuntimeException::class, 'Aguarde antes de solicitar outro código.');
    expect(DB::table('chat_phone_challenges')->count())->toBe(1);
    $this->travel(10)->minutes();
    expect($service->send($companyId, $ctx['customer']->phone))->toBeString();
});

test('sessão autenticada não substitui preenchimento de visitante ao recarregar', function () {
    $ctx = flowAuditContext();
    $browser = customerAccessBrowser($this, $ctx['company']->slug);
    customerAccessCart($browser, $ctx);
    $browser->call('submitPhone', updates: ['phone' => $ctx['customer']->phone]);
    $browser->call('useSavedData');
    $browser->call('submitVerificationCode', updates: ['verificationCode' => Mail::queued(ChatVerificationCode::class)->sole()->code]);
    $browser->call('continueAsGuest');
    $browser->call('submitName', updates: ['name' => 'Outro comprador neste aparelho']);
    $browser->visit();
    expect($browser->data['name'])->toBe('Outro comprador neste aparelho')
        ->and($browser->data['customerId'])->toBeNull()
        ->and($browser->data['useSavedProfile'])->toBeFalse();
    // Reopening a previously closed store also reinitializes the chat.
    session(['chat_state.step' => 'CLOSED']);
    session()->save();
    $browser->visit();
    expect($browser->data['name'])->toBe('Outro comprador neste aparelho')
        ->and($browser->data['customerId'])->toBeNull()
        ->and($browser->data['useSavedProfile'])->toBeFalse();
});

function customerAccessPlaceCashOrder(object $browser): void
{
    $browser->call('selectScheduleOption', ['now']);
    $browser->call('proceedFromNotes');
    $browser->call('selectPaymentMethod', ['CASH']);
    $browser->call('confirmOrder');
}

test('visitante usa cupom com limite por cliente e o mesmo telefone não usa de novo', function () {
    $ctx = flowAuditContext();
    \App\Models\Coupon::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'code' => 'PRIMEIRA', 'name' => 'Primeira compra',
        'type' => 'percentage', 'discount_value' => 10, 'scope' => 'order', 'active' => true, 'max_uses_per_customer' => 1,
    ]);
    $browser = customerAccessBrowser($this, $ctx['company']->slug);
    customerAccessCart($browser, $ctx);
    $browser->call('submitPhone', updates: ['phone' => '11988887777']);
    $browser->call('continueAsGuest');
    $browser->call('submitName', updates: ['name' => 'Visitante com cupom']);
    $browser->call('applyCoupon', updates: ['couponInput' => 'primeira']);
    expect($browser->data['couponError'])->toBeNull()
        ->and($browser->data['couponDiscount'])->toEqual(2.0)
        // O tipo já escolhido segue sozinho: nem bolha repetida nem pedido para escolher de novo.
        ->and(substr_count($browser->html, 'Retirada no local'))->toBe(1)
        ->and($browser->html)->not->toContain('Escolha o tipo de entrega');
    customerAccessPlaceCashOrder($browser);
    $order = Order::withoutGlobalScopes()->findOrFail($browser->data['orderId']);
    expect($order->customer_id)->toBeNull()->and((float) $order->discount)->toBe(2.0);

    session()->flush();
    session()->save();
    $browser->visit();
    customerAccessCart($browser, $ctx);
    $browser->call('submitPhone', updates: ['phone' => '11988887777']);
    $browser->call('continueAsGuest');
    $browser->call('submitName', updates: ['name' => 'Visitante com cupom']);
    $browser->call('applyCoupon', updates: ['couponInput' => 'PRIMEIRA']);
    expect($browser->data['couponError'])->toBe('Você já utilizou este cupom o número máximo de vezes.')
        ->and($browser->data['appliedCoupon'])->toBeNull();
});

test('troco em dinheiro precisa cobrir o total e vai para as observações do pedido', function () {
    $ctx = flowAuditContext();
    $browser = customerAccessBrowser($this, $ctx['company']->slug);
    customerAccessCart($browser, $ctx);
    $browser->call('submitPhone', updates: ['phone' => '11988887777']);
    $browser->call('continueAsGuest');
    $browser->call('submitName', updates: ['name' => 'Visitante troco']);
    $browser->call('skipCoupon');
    $browser->call('selectScheduleOption', ['now']);
    $browser->call('proceedFromNotes', updates: ['notes' => 'Sem cebola']);
    $browser->call('selectPaymentMethod', ['CASH']);
    expect($browser->data['step'])->toBe('CHECKOUT_CONFIRM')
        ->and($browser->html)->toContain('Vai precisar de troco?', 'Visitante troco', '(11) 98888-7777');
    $browser->call('confirmOrder', updates: ['needsChange' => true, 'cashChange' => '10']);
    expect($browser->data['orderId'])->toBeNull()
        ->and($browser->html)->toContain('Informe um valor igual ou maior que o total (R$ 20,00).')
        ->and(Order::withoutGlobalScopes()->count())->toBe(0);
    $browser->call('confirmOrder', updates: ['needsChange' => true, 'cashChange' => '50,00']);
    $order = Order::withoutGlobalScopes()->findOrFail($browser->data['orderId']);
    expect($order->notes)->toBe("Sem cebola\nTroco para R$ 50,00");
});

test('sem troco as observações do pedido ficam como o cliente escreveu', function () {
    $ctx = flowAuditContext();
    $browser = customerAccessBrowser($this, $ctx['company']->slug);
    customerAccessCart($browser, $ctx);
    $browser->call('submitPhone', updates: ['phone' => '11988887777']);
    $browser->call('continueAsGuest');
    $browser->call('submitName', updates: ['name' => 'Visitante sem troco']);
    $browser->call('skipCoupon');
    $browser->call('selectScheduleOption', ['now']);
    $browser->call('proceedFromNotes', updates: ['notes' => 'Bem passado']);
    $browser->call('selectPaymentMethod', ['CASH']);
    $browser->call('confirmOrder', updates: ['needsChange' => false, 'cashChange' => '50']);
    expect(Order::withoutGlobalScopes()->findOrFail($browser->data['orderId'])->notes)->toBe('Bem passado');
});

test('endereço segue direto e só pede o pino quando o frete depende da localização', function (bool $asksPin) {
    \Illuminate\Support\Facades\Http::fake(['nominatim.openstreetmap.org/*' => \Illuminate\Support\Facades\Http::response([
        ['lat' => '-23.5503', 'lon' => '-46.6339', 'address' => ['postcode' => '01001-000']],
    ])]);
    $ctx = flowAuditContext();
    \App\Models\DeliverySetting::create([
        'branch_id' => $ctx['branch']->id, 'company_id' => $ctx['company']->id, 'fee_type' => 'flat',
        'flat_fee' => 7.0, 'minimum_order_value' => 0, 'active' => true,
        // Raio de atendimento exige coordenadas; taxa fixa sem raio não.
        ...($asksPin ? ['branch_latitude' => -23.55, 'branch_longitude' => -46.63, 'service_radius_km' => 10] : []),
    ]);
    \Illuminate\Support\Facades\Cache::flush();
    $browser = customerAccessBrowser($this, $ctx['company']->slug);
    $browser->call('confirmCart', updates: ['cart' => [$ctx['product']->id => ['qty' => 1, 'price' => 20, 'name' => 'Coxinha']]]);
    $browser->call('selectOrderType', ['delivery']);
    $browser->call('submitPhone', updates: ['phone' => '11988887777']);
    $browser->call('continueAsGuest');
    $browser->call('submitName', updates: ['name' => 'Visitante entrega']);
    $browser->call('skipCoupon');
    expect($browser->data['step'])->toBe('CHECKOUT_DELIVERY_ADDRESS');
    $browser->call('confirmDeliveryAddress', updates: [
        'cep' => '01001-000', 'address' => 'Praça da Sé', 'number' => '45', 'neighborhood' => 'Sé', 'city' => 'São Paulo',
    ]);
    $dispatched = collect($browser->effects['dispatches'] ?? [])->pluck('name');
    expect($dispatched->contains('chat-geocoded'))->toBe($asksPin)
        ->and($browser->data['number'])->toBe('45')
        ->and($browser->data['customer_latitude'])->not->toBe('');
    if ($asksPin) {
        expect($browser->data['step'])->toBe('CHECKOUT_DELIVERY_ADDRESS');
        // Confirming the pin (map-picker.js) calls confirmDeliveryAddress again with the coordinates set.
        $browser->call('confirmDeliveryAddress');
    }
    expect($browser->data['step'])->not->toBe('CHECKOUT_DELIVERY_ADDRESS')
        ->and($browser->html)->toContain('Praça da Sé, 45 — Sé, São Paulo');
})->with([
    'taxa fixa sem raio' => [false],
    'raio de atendimento' => [true],
]);

test('escolhas do cliente ficam no histórico do chat', function () {
    $ctx = flowAuditContext();
    $browser = customerAccessBrowser($this, $ctx['company']->slug);
    customerAccessCart($browser, $ctx);
    $browser->call('submitPhone', updates: ['phone' => '11988887777']);
    $browser->call('continueAsGuest');
    expect($browser->html)->toContain('Retirada no local', '(11) 98888-7777', 'Vou preencher meus dados')
        ->and(strpos($browser->html, 'Retirada no local'))->toBeLessThan(strpos($browser->html, '(11) 98888-7777'))
        ->and(strpos($browser->html, '(11) 98888-7777'))->toBeLessThan(strpos($browser->html, 'Vou preencher meus dados'));
});
