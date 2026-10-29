<?php

use App\DTOs\SalesInsight;
use App\Livewire\Admin\Reports\SoldItems;
use App\Livewire\Admin\SalesSuggestions;
use App\Services\Sales\SalesInsightsService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

require_once __DIR__.'/SoldItemsHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-10-28 12:00:00');
    Cache::flush();
});

afterEach(fn () => Carbon::setTestNow());

test('sugestão de cadastro identifica apenas os campos ausentes de produtos com procura', function (array $attributes, int $quantity, ?string $missing) {
    $ctx = sirContext();
    sirBase($ctx);
    $product = sirProduct($ctx['company'], $ctx['category'], 'Produto com procura', attributes: $attributes);
    sirOrder($ctx, '2026-10-20 13:00:00', [[$product, $quantity]]);

    $insight = sirFirst(sirGenerate($ctx['company']), 'catalog_presentation', $product);
    if ($missing === null) {
        expect($insight)->toBeNull();
    } else {
        expect($insight)->not->toBeNull()
            ->and($insight->priority)->toBe(SalesInsight::PRIORITY_IDEA)
            ->and($insight->evidence)->toContain('Falta '.$missing)
            ->and($insight->actions[0]['params'])->toBe(['product' => $product->id]);
    }
})->with([
    'foto e descrição' => [[], 10, 'foto e descrição'],
    'somente foto' => [['description' => 'Ingredientes da casa'], 10, 'foto'],
    'somente descrição' => [['image_path' => 'produtos/foto.jpg', 'description' => '  '], 10, 'descrição'],
    'cadastro completo' => [['image_path' => 'produtos/foto.jpg', 'description' => 'Ingredientes'], 10, null],
    'volume insuficiente' => [[], 9, null],
    'produto inativo' => [['active' => false], 10, null],
]);

test('contexto do produto é aplicado antes do limite geral e não altera o cache da loja', function () {
    $ctx = sirContext();
    $a = sirProduct($ctx['company'], $ctx['category'], 'Produto principal', 50);
    $b = sirProduct($ctx['company'], $ctx['category'], 'Produto secundário', 10);
    sirStock($ctx['branch'], $a, 1);
    sirStock($ctx['branch'], $b, 1);
    sirOrders($ctx, 25, fn ($i) => sirSpreadAt($i), [[$a, 1], [$b, 1]]);
    config(['sales_insights.max_total' => 1]);
    $service = app(SalesInsightsService::class);
    $filters = sirFilters($ctx['company']);
    $global = $service->generate($ctx['company'], $filters);
    expect($global['insights'][0]->productId)->toBe($a->id);

    $specific = $service->generate($ctx['company'], $filters, 'products', [$b->id]);
    expect($specific['insights'])->toHaveCount(1)
        ->and($specific['insights'][0]->productId)->toBe($b->id)
        ->and($service->generate($ctx['company'], $filters)['insights'])->toEqual($global['insights']);

    $component = Livewire::withoutLazyLoading()->actingAs(sirUser($ctx['company']))
        ->test(SalesSuggestions::class, ['context' => 'products', 'productId' => $b->id])->assertOk();
    expect($component->get('suggestions')['insights'][0]->productId)->toBe($b->id);

    $report = Livewire::test(SoldItems::class)->call('openDetail', sirKey($b));
    expect($report->get('detail')['insights'][0]->productId)->toBe($b->id);
});

test('estoque e cupons recebem apenas recomendações pertinentes mesmo com limite geral', function (string $context) {
    $ctx = sirContext();
    $sold = sirProduct($ctx['company'], $ctx['category'], 'Em estoque baixo');
    $declining = sirProduct($ctx['company'], $ctx['category'], 'Queda de vendas');
    sirStock($ctx['branch'], $sold, 1);
    sirOrders($ctx, 25, fn ($i) => sirSpreadAt($i), [[$sold, 1]]);
    sirOrder($ctx, '2026-09-20 12:00:00', [[$declining, 20]]);
    config(['sales_insights.max_total' => 1]);

    $result = app(SalesInsightsService::class)->generate($ctx['company'], sirFilters($ctx['company']), $context);
    expect($result['insights'])->toHaveCount(1);
    foreach ($result['insights'] as $insight) {
        if ($context === 'stock') {
            expect($insight->type)->toBe('stock_running_out');
        } else {
            expect(collect($insight->actions)->pluck('route'))->toContain('admin.coupons.index');
        }
    }
})->with(['stock', 'coupons']);

test('painel informa volume insuficiente e atualiza vendas e sugestões ao solicitar', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Estoque de teste');
    sirStock($ctx['branch'], $product, 1);
    $component = Livewire::withoutLazyLoading()->actingAs(sirUser($ctx['company']))
        ->test(SalesSuggestions::class, ['context' => 'stock'])
        ->assertSee('Faltam 20 pedidos');
    sirOrders($ctx, 25, fn ($i) => sirSpreadAt($i), [[$product, 1]]);

    $component->call('refresh')->assertSee('Estoque acabando');
    expect($component->get('suggestions')['orders'])->toBe(25);
});

test('produto e pedido de outra empresa não abrem sugestões', function (string $target) {
    $own = sirContext('own');
    $foreign = sirContext('foreign');
    $product = sirProduct($foreign['company'], $foreign['category'], 'Produto confidencial');
    $orderId = sirOrder($foreign, '2026-10-20 12:00:00', [[$product, 1]]);
    app()->instance('current.company', $own['company']);

    Livewire::withoutLazyLoading()->actingAs(sirUser($own['company']))
        ->test(SalesSuggestions::class, [$target => $target === 'productId' ? $product->id : $orderId])
        ->assertNotFound();
})->with(['productId', 'orderId']);

test('papéis operacionais não acessam o componente de sugestões', function (string $role) {
    $ctx = sirContext();
    Livewire::withoutLazyLoading()->actingAs(sirUser($ctx['company'], $role, $ctx['branch']))
        ->test(SalesSuggestions::class)->assertForbidden();
})->with(['branch_manager', 'cozinha', 'caixa', 'entrega']);

test('permissão de sugestões é revalidada em atualizações após rebaixamento', function () {
    $ctx = sirContext();
    $user = sirUser($ctx['company']);
    $component = Livewire::withoutLazyLoading()->actingAs($user)->test(SalesSuggestions::class)->assertOk();
    $user->companies()->updateExistingPivot($ctx['company']->id, ['role' => 'branch_manager']);

    $component->call('refresh')->assertForbidden();
});

test('pedido recebe sugestões somente dos seus produtos e da sua filial', function () {
    $ctx = sirContext();
    $a = sirProduct($ctx['company'], $ctx['category'], 'Produto do pedido');
    $b = sirProduct($ctx['company'], $ctx['category'], 'Produto de outro pedido');
    sirStock($ctx['branch'], $a, 1);
    sirStock($ctx['branch'], $b, 1);
    sirOrders($ctx, 25, fn ($i) => sirSpreadAt($i), [[$a, 1], [$b, 1]]);
    $orderId = sirOrder($ctx, '2026-10-28 10:00:00', [[$a, 1]]);

    $component = Livewire::withoutLazyLoading()->actingAs(sirUser($ctx['company']))
        ->test(SalesSuggestions::class, ['context' => 'orders', 'orderId' => $orderId])->assertOk();
    $result = $component->get('suggestions');
    expect($result['window']->branchId)->toBe($ctx['branch']->id)
        ->and($result['insights'])->not->toBeEmpty();
    foreach ($result['insights'] as $insight) {
        expect($insight->concerns(sirKey($a)))->toBeTrue();
    }
});

test('filtros de sugestões não alteram os totais do relatório e podem ser limpos', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Produto das sugestões');
    sirStock($ctx['branch'], $product, 1);
    sirOrders($ctx, 25, fn ($i) => sirSpreadAt($i), [[$product, 1]]);
    $component = Livewire::actingAs(sirUser($ctx['company']))->test(SoldItems::class);
    $kpis = $component->get('report')['kpis'];

    $component->set('insightPriority', '3')->set('insightType', 'catalog_presentation');
    expect($component->get('filteredInsights'))->not->toBeEmpty();
    foreach ($component->get('filteredInsights') as $insight) {
        expect($insight->type)->toBe('catalog_presentation')->and($insight->priority)->toBe(3);
    }
    $component->set('insightPriority', '1')->assertSee('Nenhuma sugestão corresponde aos filtros selecionados.');
    expect($component->get('report')['kpis'])->toEqual($kpis);
    $component->call('clearInsightFilters')->assertSet('insightPriority', 'all')->assertSet('insightType', 'all');
});

test('telas de gestão incluem o painel para o administrador da empresa', function (string $route) {
    $this->seed(PermissionSeeder::class);
    $ctx = sirContext();
    $this->actingAs(sirUser($ctx['company']))->get(route($route))->assertOk()->assertSeeLivewire(SalesSuggestions::class);
})->with(['admin.dashboard', 'admin.products.index', 'admin.orders.index', 'admin.stock.index', 'admin.coupons.index']);

test('ranking encontra o nome atual do produto mesmo com relatório em cache', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Nome antigo');
    sirOrder($ctx, '2026-10-20 12:00:00', [[$product, 1]]);
    $component = Livewire::actingAs(sirUser($ctx['company']))->test(SoldItems::class);
    $product->update(['name' => 'Açaí especial']);

    $component->set('search', 'acai especial');
    expect($component->get('rows'))->toHaveCount(1)
        ->and($component->get('rows')[0]['name'])->toBe('Açaí especial');
});

test('pedido apenas com itens avulsos não recebe sugestões de outros produtos', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Produto fora do pedido');
    sirStock($ctx['branch'], $product, 1);
    sirOrders($ctx, 25, fn ($i) => sirSpreadAt($i), [[$product, 1]]);
    $orderId = sirOrder($ctx, '2026-10-20 12:00:00', [['Item avulso iFood', 1]], ['channel' => 'ifood']);

    $component = Livewire::withoutLazyLoading()->actingAs(sirUser($ctx['company']))
        ->test(SalesSuggestions::class, ['context' => 'orders', 'orderId' => $orderId])->assertOk();
    expect($component->get('suggestions')['insights'])->toBeEmpty();
});
