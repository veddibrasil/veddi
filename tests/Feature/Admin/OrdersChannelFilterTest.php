<?php

require_once __DIR__.'/../Audit/Flows/flows_helpers.php';

use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Um pedido de cada canal na mesma empresa: chat do VEDDI, PDV e duas integrações. Os de
 * marketplace também são delivery/pickup, como chegam do iFood/99Food/Keeta.
 *
 * @return array{admin: User, chat: Order, pdv: Order, ifood: Order, keeta: Order}
 */
function channelFilterScenario(): array
{
    $ctx = flowAuditContext();
    $chat = flowAuditChatOrder($ctx, qty: 1, status: 'paid');

    $copy = function (array $attributes) use ($chat): Order {
        $order = $chat->replicate();
        $order->forceFill(['checkout_key' => null, 'checkout_session_hash' => null, ...$attributes])->save();

        return $order;
    };

    $admin = User::factory()->create();
    $admin->companies()->attach($ctx['company']->id, ['role' => 'company_admin']);

    return [
        'admin' => $admin,
        'chat' => $chat,
        'pdv' => $copy(['order_type' => 'pdv', 'channel' => 'chat']),
        'ifood' => $copy(['order_type' => 'delivery', 'channel' => 'ifood', 'external_order_id' => 'ifood-'.uniqid()]),
        'keeta' => $copy(['order_type' => 'pickup', 'channel' => 'keeta', 'external_order_id' => 'keeta-'.uniqid()]),
    ];
}

test('filtro Chat VEDDI não mistura pedidos das integrações', function () {
    $scenario = channelFilterScenario();

    $ids = fn (string $filter) => Livewire::actingAs($scenario['admin'])->test(OrdersIndex::class)
        ->set('channelFilter', $filter)
        ->viewData('orders')
        ->pluck('id')->sort()->values()->all();

    expect($ids('chat'))->toBe([$scenario['chat']->id])
        ->and($ids('pdv'))->toBe([$scenario['pdv']->id])
        ->and($ids('ifood'))->toBe([$scenario['ifood']->id])
        ->and($ids('keeta'))->toBe([$scenario['keeta']->id])
        ->and($ids(''))->toHaveCount(4);
});

test('kanban com filtro Chat VEDDI não mostra pedidos das integrações', function () {
    $scenario = channelFilterScenario();

    Livewire::actingAs($scenario['admin'])->test(OrdersIndex::class)
        ->set('viewMode', 'kanban')
        ->set('channelFilter', 'chat')
        ->assertViewHas('kanbanColumns', function ($columns) use ($scenario) {
            $ids = $columns->flatMap(fn ($column) => $column['orders']->pluck('id'))->all();

            return in_array($scenario['chat']->id, $ids, true)
                && ! in_array($scenario['ifood']->id, $ids, true)
                && ! in_array($scenario['keeta']->id, $ids, true);
        });
});

test('filtro de canal separa o VEDDI das integrações', function () {
    $scenario = channelFilterScenario();

    Livewire::actingAs($scenario['admin'])->test(OrdersIndex::class)
        ->assertSeeHtml('<optgroup label="VEDDI">')
        ->assertSeeHtml('<optgroup label="Integrações">')
        ->assertSee('Chat VEDDI');
});
