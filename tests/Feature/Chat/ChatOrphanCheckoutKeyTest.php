<?php

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Jobs\ProcessOrder;
use App\Livewire\Chat\OrderChat;
use App\Models\Order;
use App\Services\Chat\ChatAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Drawer\Utils;
use Monolog\Handler\TestHandler;

require_once __DIR__.'/../Audit/Flows/flows_helpers.php';

uses(RefreshDatabase::class);

/**
 * Pedido gravado com a checkout_key da sessão, mas sem acesso dela (falha entre criar o pedido e o
 * grant, ou acesso perdido): confirmOrder() saía calado e o botão "Confirmar pedido" ficava sem
 * efeito para sempre, porque a chave nunca mudava.
 */
function orphanCheckoutOrder(array $ctx, string $checkoutKey): Order
{
    return Order::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'customer_id' => null,
        'status' => 'pending',
        'subtotal' => 20,
        'total' => 20,
        'payment_method' => 'pix',
        'order_type' => 'pickup',
        'checkout_key' => $checkoutKey,
    ]);
}

test('confirmar com checkout_key de pedido sem acesso cria o pedido com chave nova', function () {
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
    Queue::fake();
    $ctx = flowAuditContext();
    $chat = new OrderChat;
    $chat->companyId = $ctx['company']->id;
    $chat->selectedBranchId = $ctx['branch']->id;
    $chat->phone = '11966660003';
    $chat->cart = [$ctx['product']->id => ['qty' => 1, 'price' => 20, 'name' => 'Item']];
    $chat->continueAsGuest();
    $chat->name = 'Visitante de teste';
    $chat->submitName();
    $chat->orderType = 'pickup';
    $chat->paymentMethod = 'PIX';
    $chat->taxId = $ctx['customer']->tax_id;
    $chat->checkoutKey = $staleKey = Str::random(64);
    $orphan = orphanCheckoutOrder($ctx, $staleKey);
    $logs = new TestHandler;
    Log::channel('chat')->getLogger()->pushHandler($logs);

    $chat->confirmOrder();

    $order = Order::withoutGlobalScopes()->where('company_id', $ctx['company']->id)->whereKeyNot($orphan->id)->sole();
    expect($chat->step)->toBe('PAYMENT_PIX')
        ->and($chat->orderId)->toBe($order->id)
        ->and($order->checkout_key)->not->toBe($staleKey)
        ->and($chat->checkoutKey)->toBe($order->checkout_key)
        ->and(app(ChatAccess::class)->canAccessOrder($order, true))->toBeTrue()
        ->and(app(ChatAccess::class)->canAccessOrder($orphan->fresh()))->toBeFalse()
        ->and($orphan->fresh()->status)->toBe('pending');
    Queue::assertPushed(ProcessOrder::class, fn (ProcessOrder $job) => $job->order->is($order));
    Queue::assertPushed(ProcessOrder::class, 1);

    $warning = collect($logs->getRecords())->sole(fn ($record) => $record->message === 'confirmOrder: checkout_key de pedido sem acesso, gerando nova chave');
    expect($warning->context)->toBe(['company_id' => $ctx['company']->id, 'order_id' => $orphan->id]);
});

test('sessão restaurada com pedido sem acesso descarta o pedido e troca a checkout_key', function () {
    $ctx = flowAuditContext();
    $staleKey = Str::random(64);
    $orphan = orphanCheckoutOrder($ctx, $staleKey);

    $html = $this->withSession(['chat_state' => [
        'securityVersion' => 2,
        'step' => 'PAYMENT_PIX',
        'companyId' => $ctx['company']->id,
        'selectedBranchId' => $ctx['branch']->id,
        'orderId' => $orphan->id,
        'checkoutKey' => $staleKey,
    ]])->get('/'.$ctx['company']->slug)->assertOk()->getContent();

    $data = Utils::extractAttributeDataFromHtml($html, 'wire:snapshot')['data'];

    expect($data['orderId'])->toBeNull()
        ->and($data['checkoutKey'])->toBeString()->toHaveLength(64)->not->toBe($staleKey);
});
