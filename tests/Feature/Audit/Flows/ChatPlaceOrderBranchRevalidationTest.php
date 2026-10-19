<?php

require_once __DIR__.'/flows_helpers.php';

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Livewire\Chat\OrderChat;
use App\Models\Branch;
use App\Models\BranchPause;
use App\Models\Company;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * AUDITORIA FLUXOS — a filial do pedido não é revalidada ao confirmar o pedido no chat.
 * Horário/pausa/empresa só são checados em selectBranch() (e no initialize da sessão), mas
 * `selectedBranchId` é propriedade pública sem #[Locked] e o estado do chat sobrevive na
 * sessão. placeOrder() → OrderService::createOrder() aceita a filial da empresa sem conferir
 * horário nem pausa: pedido imediato entra com a loja fechada/pausada (filial de outra
 * empresa é barrada pelo CompanyScope — ver teste de controle).
 */

beforeEach(function () {
    Event::fake([OrderStatusUpdated::class, NewOrderPlaced::class]);
    Queue::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

function flowAuditCashChat(Company $company, int $customerId, int $branchId, int $productId, int $qty = 1): OrderChat
{
    $chat = new OrderChat;
    $chat->companyId = $company->id;
    $chat->customerId = $customerId;
    $chat->selectedBranchId = $branchId;
    $chat->orderType = 'pickup';
    $chat->paymentMethod = 'CASH';
    $chat->cart = [(string) $productId => ['product_id' => $productId, 'qty' => $qty, 'name' => 'Item', 'price' => 20.0]];

    return $chat;
}

test('pedido imediato não é aceito com a filial fora do horário', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-15 15:00:00', config('app.timezone')));
    $ctx = flowAuditContext(stock: null);
    $ctx['branch']->update(['opens_at' => '18:00:00', 'closes_at' => '23:00:00']);
    expect($ctx['branch']->fresh()->isOpen())->toBeFalse();

    // Carrinho montado enquanto a loja estava aberta (estado salvo na sessão) e confirmado depois
    // do fechamento — ou selectedBranchId enviado direto pelo navegador: o servidor não confere.
    flowAuditCashChat($ctx['company'], $ctx['customer']->id, $ctx['branch']->id, $ctx['product']->id)->confirmOrder();

    expect(Order::withoutGlobalScopes()->where('company_id', $ctx['company']->id)->count())->toBe(0);
});

test('pedido imediato não é aceito com a filial em pausa programada', function () {
    $ctx = flowAuditContext(stock: null);
    BranchPause::create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'reason' => 'Feriado',
        'recurring_annual' => false,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(5),
    ]);
    expect($ctx['branch']->fresh()->isPaused())->toBeTrue();

    flowAuditCashChat($ctx['company'], $ctx['customer']->id, $ctx['branch']->id, $ctx['product']->id)->confirmOrder();

    expect(Order::withoutGlobalScopes()->where('company_id', $ctx['company']->id)->count())->toBe(0);
});

test('controle: chat da empresa A não cria pedido com filial e estoque da empresa B', function () {
    $ctxB = flowAuditContext(['name' => 'Empresa B', 'order_prefix' => 'EMB'], stock: 5);
    $ctxA = flowAuditContext(['name' => 'Empresa A', 'order_prefix' => 'EMA'], stock: null); // current.company = A

    // OK hoje: o whereHas('branches') de OrderService::resolveProducts herda o CompanyScope da
    // Branch e recusa ("Produto #N não está disponível nesta filial").
    flowAuditCashChat($ctxA['company'], $ctxA['customer']->id, $ctxB['branch']->id, $ctxB['product']->id, qty: 5)->confirmOrder();

    expect(Order::withoutGlobalScopes()->where('branch_id', $ctxB['branch']->id)->count())->toBe(0);
    expect(flowAuditStock($ctxB['branch'], $ctxB['product']))->toBe(5);
});
