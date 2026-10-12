<?php

use App\Contracts\IfoodGatewayContract;
use App\Exceptions\IfoodApiException;
use App\Jobs\SyncIfoodCatalogJob;
use App\Livewire\Admin\Orders\Show as OrdersShow;
use App\Models\IfoodCategory;
use App\Models\User;
use App\Services\Ifood\IfoodCatalogSyncService;
use App\Services\Ifood\IfoodGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('motivo da recusa sai do corpo do iFood sem os ids de recurso', function (mixed $body, ?string $reason) {
    expect(IfoodApiException::reasonFrom($body))->toBe($reason);
})->with([
    'details de validação' => [
        ['error' => ['code' => 'BadRequest', 'message' => 'PatchItemStatusDto is not valid', 'details' => [
            ['code' => 'NotAnUUID', 'field' => 'PatchItemStatusDto.itemId', 'message' => 'itemId must be a UUID'],
        ]]],
        'itemId must be a UUID',
    ],
    'conflito com ids' => [
        ['error' => ['code' => 'Conflict', 'message' => 'An item with the same category and product already exists (existing item id 0a59a1e4-94a9-4596-9917-d776b6bc985b).: [0a59a1e4-94a9-4596-9917-d776b6bc985b]']],
        'An item with the same category and product already exists',
    ],
    'message na raiz' => [['message' => 'token expired'], 'token expired'],
    'corpo vazio' => [[], null],
    'corpo que não é JSON' => [null, null],
]);

test('mensagem pra tela explica a recusa conforme o status', function (int $status, ?string $reason, string $expected) {
    expect((new IfoodApiException('técnica', $status, $reason))->userMessage())->toContain($expected);
})->with([
    'item repetido' => [409, 'An item with the product id \'x\' and external code \'veddi-p66\' already exist', 'já tem um item igual'],
    'acesso negado' => [403, null, 'Reconecte a integração'],
    'iFood fora do ar' => [503, 'Sorry we couldn\'t complete your request', 'instável'],
    'motivo genérico' => [400, 'price must be greater than zero', 'O iFood recusou: price must be greater than zero.'],
    'sem motivo' => [400, null, 'erro 400'],
]);

test('item recusado pelo iFood guarda o motivo no cadastro e na aba Cardápio', function () {
    $ctx = ifoodContext('motivo-item');
    IfoodCategory::create([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
        'product_category_id' => $ctx['category']->id, 'ifood_category_id' => 'categoria-ifood',
    ]);
    Http::fake([
        '*/items/*/flat' => Http::response(['error' => ['code' => 'NotFound']], 404),
        '*/items' => Http::response(['error' => [
            'code' => 'Conflict',
            'message' => 'An item with the same category and product already exists (existing item id 0a59a1e4-94a9-4596-9917-d776b6bc985b).: [0a59a1e4-94a9-4596-9917-d776b6bc985b]',
            'conflictingResources' => ['0a59a1e4-94a9-4596-9917-d776b6bc985b'],
        ]], 409),
    ]);

    // Nenhum item aceito: o job marca a falha na hora (sem relançar pra fila repetir).
    (new SyncIfoodCatalogJob($ctx['branch']->id))->handle(app(IfoodCatalogSyncService::class));

    expect($ctx['integration']->fresh()->catalog_sync_status)->toBe('failed');
    $error = DB::table('branch_product')->where('branch_id', $ctx['branch']->id)->where('product_id', $ctx['product']->id)->value('ifood_sync_error');
    expect($error)->toContain('O iFood já tem um item igual a este no cardápio')
        ->not->toContain('0a59a1e4')
        ->and($ctx['integration']->fresh()->catalog_sync_error)->toContain('O iFood já tem um item igual');
});

test('lote do catálogo com falha traz o motivo da linha recusada', function () {
    $ctx = ifoodContext('motivo-lote');
    Http::fake([
        '*/items/price' => Http::response(['batchId' => 'lote-1'], 202),
        '*/batch/lote-1' => Http::response(['batchStatus' => 'COMPLETED', 'results' => [
            ['resourceId' => 'i1', 'result' => 'FAILED', 'message' => 'Price must be greater than zero'],
        ]]),
    ]);

    try {
        app(IfoodGatewayService::class)->updateItemPrices($ctx['integration'], [['itemId' => 'i1', 'price' => 0]]);
        $this->fail('O lote deveria falhar.');
    } catch (IfoodApiException $e) {
        expect($e->getMessage())->toContain('lote concluído com falhas')
            ->and($e->userMessage())->toBe('O iFood recusou: Price must be greater than zero.');
    }
});

test('ação do pedido recusada pelo iFood mostra o motivo em vez do erro técnico', function () {
    $ctx = ifoodContext('motivo-pedido');
    $order = ifoodKanbanOrder($ctx, 'paid', 'ifood-motivo-1');

    $gateway = Mockery::mock(IfoodGatewayContract::class);
    $gateway->shouldReceive('confirmOrder')->once()->andThrow(
        new IfoodApiException('iFood: falha em confirmOrder (integration_id=1, status 400)', 400, 'Order already cancelled'),
    );
    app()->instance(IfoodGatewayContract::class, $gateway);
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    Livewire::test(OrdersShow::class, ['order' => $order])
        ->call('updateStatus', 'preparing')
        ->assertSee('O iFood recusou: Order already cancelled.')
        ->assertDontSee('integration_id');

    expect($order->fresh()->status)->toBe('paid');
});
