<?php

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Livewire\Chat\OrderChat;
use App\Models\ChatDeviceProfile;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\DeviceProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Audit/Flows/flows_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
    Queue::fake();
});

function deviceProfileCookie(int $companyId): string
{
    $name = "chat_device_profile_{$companyId}";
    $cookie = Cookie::queued($name);
    expect($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
    $token = $cookie->getValue();
    Cookie::unqueue($name);
    request()->cookies->set($name, $token);

    return $token;
}

function deviceProfileGuest(array $ctx): OrderChat
{
    $chat = new OrderChat;
    $chat->companyId = $ctx['company']->id;
    $chat->selectedBranchId = $ctx['branch']->id;
    $chat->cart = [$ctx['product']->id => ['qty' => 1, 'price' => 20, 'name' => 'Coxinha']];
    $chat->phone = $ctx['customer']->phone;
    $chat->submitPhone();
    $chat->continueAsGuest();
    $chat->name = 'Visitante do aparelho';
    $chat->submitName();
    $chat->orderType = 'pickup';
    $chat->paymentMethod = 'CASH';
    $chat->taxId = '52998224725';
    $chat->address = 'Rua digitada neste aparelho';

    return $chat;
}

test('pedido visitante lembra somente seus dados criptografados e preserva Customer', function () {
    $ctx = flowAuditContext();
    $original = $ctx['customer']->fresh()->getAttributes();
    $chat = deviceProfileGuest($ctx);
    $chat->confirmOrder();
    $token = deviceProfileCookie($ctx['company']->id);
    $profile = ChatDeviceProfile::sole();
    expect(strlen($token))->toBe(64)
        ->and($profile->token_hash)->toBe(hash('sha256', $token))
        ->and($profile->expires_at->timestamp)->toBe(now()->addDays(30)->timestamp)
        ->and($profile->data['name'])->toBe($chat->name)
        ->and($profile->data['taxId'])->toBe($chat->taxId)
        ->and($profile->data['address'])->toBe($chat->address)
        ->and($profile->data)->not->toHaveKeys(['phone', 'phone_verified', 'whatsapp_opt_in'])
        ->and(DB::table('chat_device_profiles')->value('data'))->not->toContain($chat->name, $chat->taxId, $chat->address)
        ->and(Order::withoutGlobalScopes()->findOrFail($chat->orderId)->customer_id)->toBeNull()
        ->and($ctx['customer']->fresh()->getAttributes())->toBe($original);
});

test('desmarcar não grava perfil e revoga o perfil anterior', function (bool $existing) {
    $ctx = flowAuditContext();
    if ($existing) {
        app(DeviceProfile::class)->save($ctx['company']->id, $ctx['customer']->phone, ['name' => 'Anterior']);
        deviceProfileCookie($ctx['company']->id);
    }
    $chat = deviceProfileGuest($ctx);
    $chat->rememberDeviceData = false;
    $chat->confirmOrder();
    expect(ChatDeviceProfile::count())->toBe($existing ? 1 : 0)
        ->and(ChatDeviceProfile::whereNull('revoked_at')->count())->toBe(0)
        ->and(Cookie::queued('chat_device_profile_'.$ctx['company']->id)->getExpiresTime())->toBeLessThan(now()->timestamp);
})->with([false, true]);

test('perfil exige telefone cookie empresa e validade corretos', function (string $scenario) {
    $ctx = flowAuditContext();
    $id = $ctx['company']->id;
    $phone = $ctx['customer']->phone;
    $service = app(DeviceProfile::class);
    $service->save($id, $phone, ['name' => 'Privado']);
    $token = deviceProfileCookie($id);
    if ($scenario === 'phone') {
        $phone = '11988887777';
    } elseif ($scenario === 'company') {
        $id = flowAuditContext()['company']->id;
        request()->cookies->set("chat_device_profile_{$id}", $token);
    } elseif ($scenario === 'expired') {
        $this->travel(30)->days();
    } elseif ($scenario === 'revoked') {
        ChatDeviceProfile::query()->update(['revoked_at' => now()]);
    } else {
        request()->cookies->remove("chat_device_profile_{$id}");
    }
    expect($service->find($id, $phone))->toBeNull();
})->with(['phone', 'company', 'expired', 'revoked', 'missing']);

test('leitura rotaciona token sem estender validade e rejeita replay', function () {
    $ctx = flowAuditContext();
    $id = $ctx['company']->id;
    $phone = $ctx['customer']->phone;
    $service = app(DeviceProfile::class);
    $service->save($id, $phone, ['name' => 'Privado']);
    $old = deviceProfileCookie($id);
    $expiry = ChatDeviceProfile::sole()->expires_at->timestamp;
    $this->travel(1)->day();
    expect($service->find($id, $phone))->toBe(['name' => 'Privado']);
    $new = deviceProfileCookie($id);
    expect($new)->not->toBe($old)
        ->and(ChatDeviceProfile::sole()->expires_at->timestamp)->toBe($expiry);
    request()->cookies->set("chat_device_profile_{$id}", $old);
    expect($service->find($id, $phone))->toBeNull();
    request()->cookies->set("chat_device_profile_{$id}", $new);
    expect($service->find($id, $phone))->toBe(['name' => 'Privado']);
});

test('salvar novamente atualiza apenas o próprio registro e renova validade', function () {
    $ctx = flowAuditContext();
    $id = $ctx['company']->id;
    $phone = $ctx['customer']->phone;
    $service = app(DeviceProfile::class);
    $service->save($id, $phone, ['name' => 'Anterior']);
    deviceProfileCookie($id);
    $this->travel(1)->day();
    $service->save($id, $phone, ['name' => 'Atualizado']);
    expect(ChatDeviceProfile::count())->toBe(1)
        ->and(ChatDeviceProfile::sole()->data)->toBe(['name' => 'Atualizado'])
        ->and(ChatDeviceProfile::sole()->expires_at->timestamp)->toBe(now()->addDays(30)->timestamp);
});

test('reutilizar dados segue checkout visitante sem carregar ou alterar Customer', function (bool $cart) {
    $ctx = flowAuditContext();
    $original = $ctx['customer']->fresh()->getAttributes();
    $id = $ctx['company']->id;
    app(DeviceProfile::class)->save($id, $ctx['customer']->phone, ['name' => 'Nome do aparelho', 'taxId' => '52998224725']);
    deviceProfileCookie($id);
    $chat = new OrderChat;
    $chat->companyId = $id;
    $chat->phone = $ctx['customer']->phone;
    $chat->cart = $cart ? [$ctx['product']->id => ['qty' => 1, 'price' => 20, 'name' => 'Item']] : [];
    $chat->submitPhone();
    expect($chat->step)->toBe('CUSTOMER_OPTIONS')->and($chat->name)->toBe('');
    deviceProfileCookie($id);
    $chat->useDeviceData();
    expect($chat->name)->toBe('Nome do aparelho')
        ->and($chat->customerId)->toBeNull()
        ->and($chat->useSavedProfile)->toBeFalse()
        ->and($chat->checkoutReady)->toBeTrue()
        ->and($chat->step)->toBe($cart ? 'CHECKOUT_COUPON' : 'MENU_BROWSE')
        ->and(app(ChatAccess::class)->customer($id))->toBeNull()
        ->and($ctx['customer']->fresh()->getAttributes())->toBe($original)
        ->and(Customer::withoutGlobalScopes()->count())->toBe(1);
})->with([false, true]);

test('trocar telefone antes de reutilizar não retorna dados e esquecer revoga ambos mecanismos', function () {
    $ctx = flowAuditContext();
    $id = $ctx['company']->id;
    app(DeviceProfile::class)->save($id, $ctx['customer']->phone, ['name' => 'Privado']);
    deviceProfileCookie($id);
    app(ChatAccess::class)->verify($id, $ctx['customer']->phone);
    app(\App\Services\Chat\RememberedDevice::class)->remember($ctx['customer']);
    $chat = new OrderChat;
    $chat->companyId = $id;
    $chat->phone = '11988887777';
    $chat->useDeviceData();
    expect($chat->name)->toBe('')->and($chat->step)->toBe('REGISTER_NAME');
    $chat->forgetDevice();
    expect(ChatDeviceProfile::sole()->revoked_at)->not->toBeNull()
        ->and(DB::table('chat_remembered_devices')->value('revoked_at'))->not->toBeNull()
        ->and(app(ChatAccess::class)->customer($id))->toBeNull()
        ->and($chat->hasDeviceData)->toBeFalse();
});

test('preferência é persistida no estado e pedido identificado não grava perfil visitante', function () {
    $ctx = flowAuditContext();
    $chat = deviceProfileGuest($ctx);
    $chat->rememberDeviceData = false;
    $chat->submitName();
    expect(session('chat_state.rememberDeviceData'))->toBeFalse();
    app(ChatAccess::class)->verify($ctx['company']->id, $ctx['customer']->phone);
    $chat->useSavedData();
    $chat->confirmOrder();
    expect(ChatDeviceProfile::count())->toBe(0)
        ->and(Order::withoutGlobalScopes()->findOrFail($chat->orderId)->customer_id)->toBe($ctx['customer']->id);
});

test('novo navegador não atualiza registro de outro aparelho', function () {
    $ctx = flowAuditContext();
    $id = $ctx['company']->id;
    $phone = $ctx['customer']->phone;
    $service = app(DeviceProfile::class);
    $service->save($id, $phone, ['name' => 'Primeiro aparelho']);
    $firstToken = deviceProfileCookie($id);
    request()->cookies->remove("chat_device_profile_{$id}");
    $service->save($id, $phone, ['name' => 'Segundo aparelho']);
    deviceProfileCookie($id);
    expect(ChatDeviceProfile::count())->toBe(2);
    request()->cookies->set("chat_device_profile_{$id}", $firstToken);
    expect($service->find($id, $phone))->toBe(['name' => 'Primeiro aparelho']);
});

test('pedido rejeitado não lembra dados do visitante', function () {
    $ctx = flowAuditContext();
    $chat = deviceProfileGuest($ctx);
    DB::table('branch_product')->where('branch_id', $ctx['branch']->id)->update(['quantity' => 0]);
    $chat->confirmOrder();
    expect($chat->orderId)->toBeNull()
        ->and(ChatDeviceProfile::count())->toBe(0)
        ->and(Cookie::queued('chat_device_profile_'.$ctx['company']->id))->toBeNull();
});
