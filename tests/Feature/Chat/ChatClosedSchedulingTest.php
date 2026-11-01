<?php

require_once __DIR__.'/endings_helpers.php';

use App\Events\NewOrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Models\BranchPause;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Drawer\Utils;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-11-01 12:00:00', config('app.timezone')));
    Event::fake([NewOrderPlaced::class, OrderStatusUpdated::class]);
    Queue::fake();
});

test('loja fechada permite montar pedido apenas agendado, sem opção agora', function () {
    $ctx = flowAuditContext(['schedule_min_advance_minutes' => 60], stock: null);
    $ctx['branch']->update(['opens_at' => '18:00:00', 'closes_at' => '23:00:00']);
    $html = $this->get('/'.$ctx['company']->slug)->assertOk()->assertSee('pedido agendado')->assertSee('Somente agendamentos')->getContent();
    expect(Utils::extractAttributeDataFromHtml($html, 'wire:snapshot')['data']['step'])->toBe('MENU_BROWSE');
    $chat = endingsChat($ctx);
    $chat->selectBranch($ctx['branch']->id);
    $chat->step = 'CHECKOUT_SCHEDULE';
    $chat->selectScheduleOption('now');
    expect($chat->step)->toBe('CHECKOUT_SCHEDULE')->and($chat->getErrorBag()->has('scheduledAt'))->toBeTrue();
    $chat->scheduledAt = now()->setTime(19, 0)->toIso8601String();
    $chat->confirmOrder();
    expect($chat->orderId)->not->toBeNull()->and(Order::find($chat->orderId)->scheduled_at->format('H:i'))->toBe('19:00');
});

test('confirmação revalida agendamento após alteração de horário, pausa, antecedência ou configuração', function (string $cause) {
    $ctx = flowAuditContext(['schedule_min_advance_minutes' => 60], stock: null);
    $chat = endingsChat($ctx);
    $chat->scheduledAt = now()->setTime(19, 0)->toIso8601String();
    match ($cause) {
        'hours' => $ctx['branch']->update(['opens_at' => '20:00:00', 'closes_at' => '23:00:00']),
        'advance' => $ctx['company']->update(['schedule_min_advance_minutes' => 600]),
        'disabled' => $ctx['company']->update(['schedule_min_advance_minutes' => 0]),
        'pause' => BranchPause::create(['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'starts_at' => now()->setTime(18, 0), 'ends_at' => now()->setTime(20, 0), 'recurring_annual' => false]),
    };
    app()->instance('current.company', $ctx['company']->fresh());
    $chat->confirmOrder();
    expect($chat->orderId)->toBeNull()->and(Order::withoutGlobalScopes()->count())->toBe(0);
})->with(['hours', 'advance', 'disabled', 'pause']);

test('agendamento e seleção de filial mantêm isolamento por empresa', function () {
    $ctx = flowAuditContext(['schedule_min_advance_minutes' => 60], stock: null);
    $other = flowAuditContext(['schedule_min_advance_minutes' => 60], stock: null);
    app()->instance('current.company', $ctx['company']);
    $chat = endingsChat($ctx);
    $chat->selectedBranchId = $other['branch']->id;
    $chat->scheduledAt = now()->addHours(2)->toIso8601String();
    $chat->confirmOrder();
    expect($chat->orderId)->toBeNull()->and(Order::withoutGlobalScopes()->count())->toBe(0);
});
