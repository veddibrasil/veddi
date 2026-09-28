<?php

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Jobs\SendWhatsAppOrderNotificationJob;
use App\Listeners\SendWhatsAppOrderNotification;
use App\Listeners\SendWhatsAppStatusNotification;
use App\Models\Order;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    config(['services.whatsapp.fallback_to_platform' => false]);
});

function notifyStatus(Order $order): void
{
    app(SendWhatsAppStatusNotification::class)->handle(new OrderStatusUpdated($order->fresh()));
}

function notifyNewOrder(Order $order): void
{
    app(SendWhatsAppOrderNotification::class)->handle(new NewOrderPlaced($order->fresh()->load('customer')));
}

function assertWhatsAppDispatched(Order $order, string $event): void
{
    Queue::assertPushed(
        SendWhatsAppOrderNotificationJob::class,
        fn (SendWhatsAppOrderNotificationJob $job) => $job->orderId === $order->id && $job->event === $event,
    );
}

// ── Caso feliz ────────────────────────────────────────────────────────────────

test('mudança de status despacha o job com o evento do status', function (string $status) {
    ['order' => $order] = whatsappOrderContext(['status' => $status, 'scheduled_at' => $status === 'scheduled' ? now()->addDay() : null]);

    notifyStatus($order);

    Queue::assertPushed(SendWhatsAppOrderNotificationJob::class, 1);
    assertWhatsAppDispatched($order, $status);
})->with(['paid', 'scheduled', 'preparing', 'ready', 'out_for_delivery', 'delivered', 'cancelled', 'refunded']);

test('novo pedido despacha new_order', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'pending']);

    notifyNewOrder($order);

    Queue::assertPushed(SendWhatsAppOrderNotificationJob::class, 1);
    assertWhatsAppDispatched($order, 'new_order');
});

test('o job é enfileirado na fila whatsapp', function () {
    ['order' => $order] = whatsappOrderContext();

    notifyStatus($order);

    Queue::assertPushedOn('whatsapp', SendWhatsAppOrderNotificationJob::class);
});

test('os eventos estão ligados no AppServiceProvider', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'preparing']);

    OrderStatusUpdated::dispatch($order->fresh());

    assertWhatsAppDispatched($order, 'preparing');
});

// ── Lacunas corrigidas ────────────────────────────────────────────────────────

test('out_for_delivery agora notifica quando o toggle está ligado', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'out_for_delivery']);

    notifyStatus($order);

    assertWhatsAppDispatched($order, 'out_for_delivery');
});

test('out_for_delivery não notifica com o toggle desligado', function () {
    ['order' => $order, 'settings' => $settings] = whatsappOrderContext(['status' => 'out_for_delivery']);
    $settings->update(['notify_on_out_for_delivery' => false]);

    notifyStatus($order);

    Queue::assertNothingPushed();
});

test('scheduled agora notifica quando o toggle está ligado', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'scheduled', 'scheduled_at' => now()->addDay()]);

    notifyStatus($order);

    assertWhatsAppDispatched($order, 'scheduled');
});

test('scheduled não notifica com o toggle desligado', function () {
    ['order' => $order, 'settings' => $settings] = whatsappOrderContext(['status' => 'scheduled', 'scheduled_at' => now()->addDay()]);
    $settings->update(['notify_on_scheduled' => false]);

    notifyStatus($order);

    Queue::assertNothingPushed();
});

test('pedido em dinheiro agendado recebe o template agendado no lugar de new_order', function () {
    ['order' => $order] = whatsappOrderContext([
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay(),
        'payment_method' => 'CASH',
    ]);

    notifyNewOrder($order);

    Queue::assertPushed(SendWhatsAppOrderNotificationJob::class, 1);
    assertWhatsAppDispatched($order, 'scheduled');
});

test('o aviso tardio do NotifyScheduledOrderJob não duplica o template agendado', function () {
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext([
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay(),
        'payment_method' => 'CASH',
    ]);

    WhatsAppMessage::factory()->sent()->create([
        'company_id' => $order->company_id,
        'whatsapp_connection_id' => $connection->id,
        'order_id' => $order->id,
        'event' => 'scheduled',
    ]);

    notifyStatus($order);

    Queue::assertNothingPushed();
});

test('pagamento em dinheiro na entrega não dispara "pagamento confirmado"', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'paid', 'payment_method' => 'CASH']);

    notifyStatus($order);

    Queue::assertNothingPushed();
});

test('pagamento online confirmado dispara "pagamento confirmado"', function (string $method) {
    ['order' => $order] = whatsappOrderContext(['status' => 'paid', 'payment_method' => $method]);

    notifyStatus($order);

    assertWhatsAppDispatched($order, 'paid');
})->with(['PIX', 'pix', 'CARD']);

test('reembolso usa o toggle de cancelamento', function () {
    ['order' => $order, 'settings' => $settings] = whatsappOrderContext(['status' => 'refunded']);
    $settings->update(['notify_on_cancelled' => false]);

    notifyStatus($order);

    Queue::assertNothingPushed();
});

// ── Condições que impedem o envio ─────────────────────────────────────────────

test('não despacha sem opt-in do cliente', function () {
    ['order' => $order] = whatsappOrderContext(optedIn: false);

    notifyStatus($order);
    notifyNewOrder($order);

    Queue::assertNothingPushed();
});

test('não despacha com opt-out do cliente', function () {
    ['order' => $order, 'customer' => $customer] = whatsappOrderContext();
    $customer->update(['whatsapp_opt_out_at' => now()]);

    notifyStatus($order);

    Queue::assertNothingPushed();
});

test('não despacha com o WhatsApp desligado na empresa', function () {
    ['order' => $order, 'settings' => $settings] = whatsappOrderContext();
    $settings->update(['enabled' => false]);

    notifyStatus($order);

    Queue::assertNothingPushed();
});

test('não despacha com o toggle do evento desligado', function (string $status, string $field) {
    ['order' => $order, 'settings' => $settings] = whatsappOrderContext(['status' => $status]);
    $settings->update([$field => false]);

    notifyStatus($order);

    Queue::assertNothingPushed();
})->with([
    ['paid', 'notify_on_paid'],
    ['preparing', 'notify_on_preparing'],
    ['ready', 'notify_on_ready'],
    ['delivered', 'notify_on_delivered'],
    ['cancelled', 'notify_on_cancelled'],
]);

test('não despacha new_order com o toggle desligado', function () {
    ['order' => $order, 'settings' => $settings] = whatsappOrderContext(['status' => 'pending']);
    $settings->update(['notify_on_new_order' => false]);

    notifyNewOrder($order);

    Queue::assertNothingPushed();
});

test('não despacha sem configuração de WhatsApp da empresa', function () {
    ['order' => $order, 'settings' => $settings] = whatsappOrderContext();
    $settings->delete();

    notifyStatus($order);

    Queue::assertNothingPushed();
});

test('não despacha sem conexão nem fallback', function () {
    ['order' => $order] = whatsappOrderContext(withConnection: false);

    notifyStatus($order);

    Queue::assertNothingPushed();
});

test('não despacha com conexão que não está ativa', function (string $status) {
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext();
    $connection->update(['status' => $status]);

    notifyStatus($order);

    Queue::assertNothingPushed();
})->with([
    WhatsAppConnection::STATUS_PENDING,
    WhatsAppConnection::STATUS_PROVISIONING,
    WhatsAppConnection::STATUS_TEMPLATES_PENDING,
    WhatsAppConnection::STATUS_DISCONNECTED,
    WhatsAppConnection::STATUS_ERROR,
]);

test('não despacha sem template aprovado para o evento', function (string $status) {
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext(['status' => 'preparing']);
    $connection->templates()->where('event', 'preparing')->update(['status' => $status]);

    notifyStatus($order);

    Queue::assertNothingPushed();
})->with([
    WhatsAppTemplate::STATUS_PENDING,
    WhatsAppTemplate::STATUS_REJECTED,
    WhatsAppTemplate::STATUS_PAUSED,
    WhatsAppTemplate::STATUS_DISABLED,
]);

test('não despacha quando o template do evento nem foi criado na conexão', function () {
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext(['status' => 'preparing']);
    $connection->templates()->where('event', 'preparing')->delete();

    notifyStatus($order);

    Queue::assertNothingPushed();
});

test('ready de pedido de entrega exige o template de entrega aprovado', function () {
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext(['status' => 'ready', 'order_type' => 'delivery']);
    $connection->templates()->where('event', 'ready_delivery')->update(['status' => 'PENDING']);

    notifyStatus($order);

    Queue::assertNothingPushed();
});

test('ready de retirada usa o template de retirada, mesmo com o de entrega pendente', function () {
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext(['status' => 'ready', 'order_type' => 'pickup']);
    $connection->templates()->where('event', 'ready_delivery')->update(['status' => 'PENDING']);

    notifyStatus($order);

    assertWhatsAppDispatched($order, 'ready');
});

test('não despacha para telefone fixo', function () {
    ['order' => $order] = whatsappOrderContext(phone: '1133334444');

    notifyStatus($order);

    Queue::assertNothingPushed();
});

test('scheduled sem data agendada não notifica (template ficaria com variável vazia)', function () {
    ['order' => $order] = whatsappOrderContext(['status' => 'scheduled', 'scheduled_at' => null]);

    notifyStatus($order);

    Queue::assertNothingPushed();
});

test('não notifica status sem evento de WhatsApp', function (string $status) {
    ['order' => $order] = whatsappOrderContext(['status' => $status]);

    notifyStatus($order);

    Queue::assertNothingPushed();
})->with(['pending', 'awaiting_payment']);

test('awaiting_payment continua sem template mesmo com o toggle ligado', function () {
    ['order' => $order, 'settings' => $settings] = whatsappOrderContext(['status' => 'awaiting_payment']);
    $settings->update(['notify_on_awaiting_payment' => true]);

    notifyStatus($order);

    Queue::assertNothingPushed();
});

test('não despacha de novo quando o evento já foi notificado', function () {
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext(['status' => 'preparing']);

    WhatsAppMessage::factory()->sent()->create([
        'company_id' => $order->company_id,
        'whatsapp_connection_id' => $connection->id,
        'order_id' => $order->id,
        'event' => 'preparing',
    ]);

    notifyStatus($order);

    Queue::assertNothingPushed();
});

test('outro evento do mesmo pedido continua sendo notificado', function () {
    ['order' => $order, 'connection' => $connection] = whatsappOrderContext(['status' => 'ready']);

    WhatsAppMessage::factory()->sent()->create([
        'company_id' => $order->company_id,
        'whatsapp_connection_id' => $connection->id,
        'order_id' => $order->id,
        'event' => 'preparing',
    ]);

    notifyStatus($order);

    assertWhatsAppDispatched($order, 'ready');
});

// ── Escopo de canal ───────────────────────────────────────────────────────────

test('pedido do PDV não é notificado mesmo com cliente com opt-in', function () {
    ['order' => $order] = whatsappOrderContext(['order_type' => 'pdv', 'status' => 'paid', 'payment_method' => 'pix']);

    notifyStatus($order);
    notifyNewOrder($order);

    Queue::assertNothingPushed();
});

test('pedido do iFood não é notificado mesmo com cliente com opt-in', function () {
    ['order' => $order] = whatsappOrderContext(['channel' => 'ifood', 'status' => 'preparing']);

    notifyStatus($order);
    notifyNewOrder($order);

    Queue::assertNothingPushed();
});

// ── Fallback para a plataforma ────────────────────────────────────────────────

test('com fallback ligado e número da plataforma configurado, empresa sem conexão é notificada', function () {
    config([
        'services.whatsapp.fallback_to_platform' => true,
        'services.whatsapp.platform.phone_number_id' => '777000111',
        'services.whatsapp.platform.token' => 'token-plataforma',
    ]);

    ['order' => $order] = whatsappOrderContext(withConnection: false);

    notifyStatus($order);

    assertWhatsAppDispatched($order, 'preparing');
});

test('com fallback ligado mas número da plataforma incompleto, não despacha', function () {
    config([
        'services.whatsapp.fallback_to_platform' => true,
        'services.whatsapp.platform.phone_number_id' => '777000111',
        'services.whatsapp.platform.token' => null,
    ]);

    ['order' => $order] = whatsappOrderContext(withConnection: false);

    notifyStatus($order);

    Queue::assertNothingPushed();
});

// ── Isolamento entre empresas ─────────────────────────────────────────────────

test('empresa sem conexão não é notificada só porque outra empresa tem conexão ativa', function () {
    ['order' => $orderSemConexao] = whatsappOrderContext(withConnection: false);
    whatsappOrderContext(); // empresa B: conexão ativa e templates aprovados

    notifyStatus($orderSemConexao);

    Queue::assertNothingPushed();
});

test('templates aprovados de outra empresa não liberam o envio', function () {
    ['order' => $orderA, 'connection' => $connectionA] = whatsappOrderContext(['status' => 'preparing']);
    whatsappOrderContext(); // empresa B com todos os templates aprovados

    $connectionA->templates()->where('event', 'preparing')->update(['status' => 'PENDING']);

    notifyStatus($orderA);

    Queue::assertNothingPushed();
});

test('toggles são por empresa: desligar na A não afeta a B', function () {
    ['order' => $orderA, 'settings' => $settingsA] = whatsappOrderContext();
    ['order' => $orderB] = whatsappOrderContext();

    $settingsA->update(['notify_on_preparing' => false]);
    expect(WhatsAppSetting::count())->toBe(2);

    notifyStatus($orderA);
    notifyStatus($orderB);

    Queue::assertPushed(SendWhatsAppOrderNotificationJob::class, 1);
    assertWhatsAppDispatched($orderB, 'preparing');
});

test('opt-in é por cliente de cada empresa', function () {
    ['order' => $orderA] = whatsappOrderContext(optedIn: false);
    ['order' => $orderB] = whatsappOrderContext(optedIn: true);

    notifyStatus($orderA);
    notifyStatus($orderB);

    Queue::assertPushed(SendWhatsAppOrderNotificationJob::class, 1);
    assertWhatsAppDispatched($orderB, 'preparing');
});
