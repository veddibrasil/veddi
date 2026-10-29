<?php

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Livewire\Chat\OrderChat;
use App\Models\Order;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\DeviceProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;

require_once __DIR__.'/../Audit/Flows/flows_helpers.php';

uses(RefreshDatabase::class);

test('falha na persistência opcional do aparelho não impede confirmação acesso ou retry do pedido', function (bool $remember) {
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
    Queue::fake();
    $ctx = flowAuditContext();
    $chat = new OrderChat;
    $chat->companyId = $ctx['company']->id;
    $chat->selectedBranchId = $ctx['branch']->id;
    $chat->phone = $ctx['customer']->phone;
    $chat->cart = [$ctx['product']->id => ['qty' => 1, 'price' => 20, 'name' => 'Item']];
    $chat->continueAsGuest();
    $chat->name = 'Visitante de teste';
    $chat->email = 'privado@example.com';
    $chat->submitName();
    $chat->orderType = 'pickup';
    $chat->paymentMethod = 'CASH';
    $chat->rememberDeviceData = $remember;
    $logs = new TestHandler;
    Log::channel('chat')->getLogger()->pushHandler($logs);

    $this->mock(DeviceProfile::class)->shouldReceive($remember ? 'save' : 'forget')->once()
        ->andThrow(new RuntimeException('Falha simulada com dados sensíveis: '.$chat->phone.' '.$chat->email));

    $chat->confirmOrder();
    $order = Order::withoutGlobalScopes()->where('company_id', $ctx['company']->id)->sole();
    // Retry must recover this order, without attempting persistence or dispatching twice.
    $chat->confirmOrder();

    expect($chat->step)->toBe('ORDER_CONFIRMED')
        ->and(app(ChatAccess::class)->canAccessOrder($order, true))->toBeTrue()
        ->and(Order::withoutGlobalScopes()->where('company_id', $ctx['company']->id)->count())->toBe(1)
        ->and(Cookie::queued('chat_device_profile_'.$ctx['company']->id)->getExpiresTime())->toBeLessThan(now()->timestamp);
    Event::assertDispatchedTimes(NewOrderPlaced::class, 1);

    $warnings = collect($logs->getRecords())->filter(fn ($record) => $record->message === 'Falha ao persistir dados do aparelho após criar pedido');
    expect($warnings)->toHaveCount(1)
        ->and($warnings->sole()->context)->toBe([
            'company_id' => $ctx['company']->id,
            'order_id' => $order->id,
            'exception_type' => RuntimeException::class,
        ])
        ->and(json_encode($logs->getRecords()))->not->toContain($chat->phone, $chat->email, 'Falha simulada com dados sensíveis');
})->with([true, false]);
