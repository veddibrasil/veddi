<?php

/*
 * Avisos de vendas no sino (SalesNotifier + sales:notify): só company_admin vê, alerta urgente não
 * se repete por 7 dias, resumo semanal uma vez por semana, isolamento por empresa.
 */

use App\Livewire\Admin\NotificationBell;
use App\Livewire\Admin\Reports\SoldItems;
use App\Models\Company;
use App\Models\CompanyNotification;
use App\Models\Product;
use App\Services\Sales\SalesNotifier;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/SoldItemsHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    // Quarta-feira: a janela dos alertas diários vai de 30/09 a 27/10 (28 dias até ontem).
    Carbon::setTestNow('2026-10-28 08:30:00');
    Cache::flush();
});

afterEach(fn () => Carbon::setTestNow());

/** Produto que vende 1 un./dia de 14/10 em diante e tem só 2 em estoque: "Estoque acabando" (Agir agora). */
function snStockAlert(array $ctx, string $name = 'Coxinha Estoque', string $until = '2026-11-10'): Product
{
    $product = sirProduct($ctx['company'], $ctx['category'], $name, 10.0);
    sirStock($ctx['branch'], $product, 2, trackStock: true);

    for ($day = CarbonImmutable::parse('2026-10-14 11:00:00'); $day->lessThanOrEqualTo(CarbonImmutable::parse($until)); $day = $day->addDay()) {
        sirOrder($ctx, $day->format('Y-m-d H:i:s'), [[$product, 1]]);
    }

    return $product;
}

function snSalesNotifications(Company $company): \Illuminate\Support\Collection
{
    return CompanyNotification::where('company_id', $company->id)->whereIn('type', ['sales_alert', 'sales_summary'])->get();
}

// ─── Alertas diários ────────────────────────────────────────────────────────

test('alerta urgente vai para o sino só do admin, com link para o produto na tela', function () {
    $ctx = sirContext();
    sirBase($ctx);
    $product = snStockAlert($ctx);

    $created = app(SalesNotifier::class)->daily($ctx['company']);

    $notification = snSalesNotifications($ctx['company'])->sole();
    expect($created)->toBe(1)
        ->and($notification->type)->toBe('sales_alert')
        ->and($notification->is_admin_only)->toBeTrue()
        ->and($notification->title)->toBe('Estoque acabando: Coxinha Estoque')
        ->and($notification->subtitle)->toContain('Coxinha Estoque')
        ->and($notification->link)->toBe(route('admin.reports.sold-items', ['produto' => 'p:'.$product->id], absolute: false));
});

test('o mesmo alerta não se repete por 7 dias, mesmo se o admin limpar o sino', function () {
    $ctx = sirContext();
    sirBase($ctx);
    snStockAlert($ctx);
    $notifier = app(SalesNotifier::class);

    expect($notifier->daily($ctx['company']))->toBe(1);
    CompanyNotification::query()->delete();

    $this->travelTo(CarbonImmutable::parse('2026-11-03 08:30:00'));
    expect($notifier->daily($ctx['company']->fresh()))->toBe(0);

    // Problema continua: lembra de novo no 7º dia, no mesmo horário do agendador.
    $this->travelTo(CarbonImmutable::parse('2026-11-04 08:30:05'));
    expect($notifier->daily($ctx['company']->fresh()))->toBe(1);
});

test('problema resolvido sai do registro e, se voltar, avisa na hora', function () {
    $ctx = sirContext();
    sirBase($ctx);
    $product = snStockAlert($ctx);
    $notifier = app(SalesNotifier::class);
    $setStock = fn (int $quantity) => DB::table('branch_product')->where('product_id', $product->id)->update(['quantity' => $quantity]);

    expect($notifier->daily($ctx['company']))->toBe(1);

    $setStock(50);
    $this->travelTo(CarbonImmutable::parse('2026-10-29 08:30:00'));
    expect($notifier->daily($ctx['company']->fresh()))->toBe(0)
        ->and($ctx['company']->fresh()->sales_notifications_state['alerts'])->toBe([]);

    $setStock(2);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 08:30:00'));
    expect($notifier->daily($ctx['company']->fresh()))->toBe(1);
});

test('alerta urgente não some pelo corte do ranking (mesmo produto em 3 filiais)', function () {
    $ctx = sirContext();
    sirBase($ctx);
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha Rede', 10.0);
    foreach (['Centro', 'Norte', 'Sul'] as $name) {
        $branch = $name === 'Centro' ? $ctx['branch'] : sirBranch($ctx['company'], "Filial {$name}");
        sirStock($branch, $product, 2, trackStock: true);
        for ($day = CarbonImmutable::parse('2026-10-14 11:00:00'); $day->lessThanOrEqualTo(CarbonImmutable::parse('2026-10-27')); $day = $day->addDay()) {
            sirOrder($ctx, $day->format('Y-m-d H:i:s'), [[$product, 1]], ['branch_id' => $branch->id]);
        }
    }

    expect(app(SalesNotifier::class)->daily($ctx['company']))->toBe(3);
});

test('só "Agir agora" vira alerta e no máximo 5 por dia', function () {
    $ctx = sirContext();
    sirBase($ctx);
    foreach (range(1, 7) as $n) {
        snStockAlert($ctx, "Produto {$n}");
    }

    expect(app(SalesNotifier::class)->daily($ctx['company']))->toBe(SalesNotifier::MAX_DAILY);
    expect(snSalesNotifications($ctx['company'])->pluck('type')->unique()->all())->toBe(['sales_alert']);

    // Os que ficaram de fora entram no dia seguinte.
    $this->travelTo(CarbonImmutable::parse('2026-10-29 08:30:00'));
    expect(app(SalesNotifier::class)->daily($ctx['company']))->toBe(2);
});

test('loja com poucos pedidos não recebe alerta', function () {
    $ctx = sirContext();
    snStockAlert($ctx, until: '2026-10-27');

    expect(app(SalesNotifier::class)->daily($ctx['company']))->toBe(0)
        ->and(snSalesNotifications($ctx['company']))->toBeEmpty();
});

test('alerta de uma empresa nunca cai no sino de outra', function () {
    $a = sirContext('A');
    sirBase($a);
    snStockAlert($a);
    $b = sirContext('B');
    sirBase($b);

    $this->artisan('sales:notify')->assertSuccessful();

    expect(snSalesNotifications($a['company']))->toHaveCount(1)
        ->and(snSalesNotifications($b['company']))->toBeEmpty();
});

// ─── Resumo semanal ─────────────────────────────────────────────────────────

test('resumo semanal da semana anterior com faturamento, variação e campeões, uma vez por semana', function () {
    // Segunda, 26/10: semana anterior 19/10 a 25/10; a de antes, 12/10 a 18/10.
    Carbon::setTestNow('2026-10-26 08:30:00');
    $ctx = sirContext();
    $coxinha = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0);
    $kibe = sirProduct($ctx['company'], $ctx['category'], 'Kibe', 5.0);
    sirOrders($ctx, 10, fn ($i) => sirSpreadAt($i, '2026-10-12', 7), [[$coxinha, 1]]);
    sirOrders($ctx, 12, fn ($i) => sirSpreadAt($i, '2026-10-19', 7), [[$coxinha, 1], [$kibe, 2]]);

    expect(app(SalesNotifier::class)->weekly($ctx['company']))->toBe(1)
        ->and(app(SalesNotifier::class)->weekly($ctx['company']))->toBe(0);

    $summary = snSalesNotifications($ctx['company'])->sole();
    expect($summary->type)->toBe('sales_summary')
        ->and($summary->is_admin_only)->toBeTrue()
        ->and($summary->title)->toBe('Resumo da semana: R$ 240,00 em itens (+140% vs semana anterior)')
        ->and($summary->subtitle)->toStartWith('12 pedidos · Mais vendidos: Coxinha, Kibe')
        ->and($summary->link)->toBe(route('admin.reports.sold-items', ['periodo' => 'personalizado', 'de' => '2026-10-19', 'ate' => '2026-10-25'], absolute: false));
});

test('resumo cita campeões pelo mesmo critério da tela e variação pequena vira 0%', function () {
    Carbon::setTestNow('2026-10-26 08:30:00');
    $ctx = sirContext();
    $star = sirProduct($ctx['company'], $ctx['category'], 'Estrela', 100.0);
    $others = collect(range(1, 9))->map(fn ($n) => sirProduct($ctx['company'], $ctx['category'], "Outro {$n}", 1.0));
    // Semana anterior com o mesmo faturamento (R$ 909) e a semana resumida com R$ 909,00.
    foreach (['2026-10-12', '2026-10-19'] as $start) {
        foreach ($others as $i => $other) {
            sirOrder($ctx, sirSpreadAt($i, $start, 7), [[$star, 1], [$other, 1]]);
        }
    }

    app(SalesNotifier::class)->weekly($ctx['company']);

    $summary = snSalesNotifications($ctx['company'])->sole();
    expect($summary->title)->toBe('Resumo da semana: R$ 909,00 em itens (0% vs semana anterior)')
        ->and($summary->subtitle)->toStartWith('9 pedidos · Campeões: Estrela');
});

test('comando sai com falha quando uma empresa falha, sem parar as outras', function () {
    $a = sirContext('A');
    sirBase($a);
    $b = sirContext('B');
    sirBase($b);
    snStockAlert($b);

    $real = app(SalesNotifier::class);
    $this->mock(SalesNotifier::class, function ($mock) use ($real, $a) {
        $mock->shouldReceive('companies')->andReturnUsing(fn (...$args) => $real->companies(...$args));
        $mock->shouldReceive('daily')->andReturnUsing(function (Company $company) use ($real, $a) {
            throw_if($company->id === $a['company']->id, new RuntimeException('boom'));

            return $real->daily($company);
        });
    });

    $this->artisan('sales:notify')->assertFailed();

    expect(snSalesNotifications($b['company']))->toHaveCount(1);
});

test('semana sem venda não gera resumo', function () {
    Carbon::setTestNow('2026-10-26 08:30:00');
    $ctx = sirContext();
    $coxinha = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0);
    sirOrders($ctx, 5, fn ($i) => sirSpreadAt($i, '2026-10-12', 7), [[$coxinha, 1]]);

    $this->artisan('sales:notify', ['--weekly' => true])->assertSuccessful();

    expect(snSalesNotifications($ctx['company']))->toBeEmpty();
});

test('--company limita a uma empresa', function () {
    $a = sirContext('A');
    sirBase($a);
    snStockAlert($a);
    $b = sirContext('B');
    sirBase($b);
    snStockAlert($b);

    $this->artisan('sales:notify', ['--company' => $b['company']->id])->assertSuccessful();

    expect(snSalesNotifications($a['company']))->toBeEmpty()
        ->and(snSalesNotifications($b['company']))->toHaveCount(1);
});

test('agendamento: alertas todo dia 08:30 e resumo às segundas 08:30', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'sales:notify'))
        ->mapWithKeys(fn ($event) => [str_contains($event->command, '--weekly') ? 'weekly' : 'daily' => $event->expression]);

    expect($events->all())->toBe(['daily' => '30 8 * * *', 'weekly' => '30 8 * * 1']);
});

// ─── Sino: só o administrador ───────────────────────────────────────────────

function snBellContext(): array
{
    $ctx = sirContext();
    $alert = CompanyNotification::create([
        'company_id' => $ctx['company']->id, 'type' => 'sales_alert', 'is_admin_only' => true,
        'title' => 'Estoque acabando: Segredo de vendas', 'subtitle' => 'restam 2 un.', 'link' => '/admin/reports/sold-items',
    ]);
    $order = CompanyNotification::create([
        'company_id' => $ctx['company']->id, 'type' => 'order',
        'title' => 'Novo pedido #10', 'subtitle' => 'R$ 20,00', 'link' => '/admin/orders',
    ]);

    return $ctx + compact('alert', 'order');
}

test('admin vê o aviso de vendas no sino; gerente não', function () {
    $ctx = snBellContext();

    Livewire::actingAs(sirUser($ctx['company']))->test(NotificationBell::class)
        ->assertSee('Estoque acabando: Segredo de vendas')
        ->assertSee('Novo pedido #10');

    Livewire::actingAs(sirUser($ctx['company'], 'branch_manager', $ctx['branch']))->test(NotificationBell::class)
        ->assertDontSee('Segredo de vendas')
        ->assertSee('Novo pedido #10');
});

test('gerente não lê, não apaga e não limpa os avisos só do admin', function () {
    $ctx = snBellContext();

    Livewire::actingAs(sirUser($ctx['company'], 'branch_manager', $ctx['branch']))->test(NotificationBell::class)
        ->call('markRead', $ctx['alert']->id)
        ->call('markAllRead')
        ->call('delete', $ctx['alert']->id)
        ->call('clearAll');

    $alert = $ctx['alert']->fresh();
    expect($alert)->not->toBeNull()
        ->and($alert->read_at)->toBeNull()
        ->and($ctx['order']->fresh())->toBeNull();
});

test('admin rebaixado com o sino aberto deixa de ver os avisos de vendas', function () {
    $ctx = snBellContext();
    $admin = sirUser($ctx['company']);

    $bell = Livewire::actingAs($admin)->test(NotificationBell::class)->assertSee('Segredo de vendas');

    $admin->companies()->updateExistingPivot($ctx['company']->id, ['role' => 'branch_manager', 'branch_id' => $ctx['branch']->id]);

    $bell->call('$refresh')->assertDontSee('Segredo de vendas');
});

// ─── Link do aviso abre o produto ───────────────────────────────────────────

test('?produto= do aviso abre o detalhe do produto; chave inválida é ignorada', function () {
    $ctx = sirContext();
    $product = sirProduct($ctx['company'], $ctx['category'], 'Coxinha', 10.0);
    sirOrder($ctx, '2026-10-20 12:00:00', [[$product, 2]]);
    $admin = sirUser($ctx['company']);

    Livewire::withQueryParams(['produto' => 'p:'.$product->id])->actingAs($admin)->test(SoldItems::class)
        ->assertSet('showDetail', true)
        ->assertSet('detailKey', 'p:'.$product->id)
        ->assertSee('Unidades por dia')
        ->set('showDetail', false)
        ->assertSet('detailKey', null);

    Livewire::withQueryParams(['produto' => 'p:999999'])->actingAs($admin)->test(SoldItems::class)
        ->assertSet('showDetail', false)
        ->assertSet('detailKey', null);
});
