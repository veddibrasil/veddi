<?php

use App\Livewire\Admin\Branches\Pauses;
use App\Models\BranchPause;
use App\Models\User;
use App\Services\Food99\Food99StoreStatusSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function food99StoreCalls(): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/v1/shop/shop/setStatus'))
        ->map(fn ($pair) => array_intersect_key($pair[0]->data(), array_flip(['store_status', 'pause_time', 'pause_reason_code'])))
        ->values()->all();
}

it('pausa a loja na 99 junto com pausa curta da filial e reabre ao excluir', function () {
    $this->travelTo(now()->setTime(12, 0));
    $ctx = food99Context();
    Http::fake(['*' => Http::response(food99Ok(), 200)]);
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    $component = Livewire::test(Pauses::class, ['branch' => $ctx['branch']])
        ->assertSee('Fechar também a loja na 99Food')
        ->set('starts_date', now()->format('Y-m-d'))->set('starts_time', now()->format('H:i'))
        ->set('ends_date', now()->format('Y-m-d'))->set('ends_time', now()->addMinutes(20)->format('H:i'))
        ->set('close_in_99food', true)
        ->call('create')
        ->assertHasNoErrors();

    $pause = BranchPause::withoutGlobalScopes()->sole();
    expect($pause->food99_sync)->toBeTrue()
        ->and($pause->food99_sync_state)->toBe('paused')
        ->and(food99StoreCalls())->toBe([['store_status' => 3, 'pause_time' => 3, 'pause_reason_code' => 1006]]);

    $this->travel(61)->seconds();
    $component->call('confirmDelete', $pause->id)->call('delete');

    expect(food99StoreCalls()[1])->toBe(['store_status' => 1]);
});

it('fecha a loja no início de pausa longa futura e revoga o fechamento no fim', function () {
    $ctx = food99Context();
    Http::fake([
        // Dois dias depois o token da loja já venceu e é renovado no caminho.
        '*/v1/auth/authtoken/get*' => fn () => Http::response(food99Ok(['auth_token' => 'tok-novo', 'token_expiration_time' => now()->addHours(4)->getTimestamp()]), 200),
        '*' => Http::response(food99Ok(), 200),
    ]);
    $pause = BranchPause::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'reason' => 'Reforma',
        'recurring_annual' => false, 'starts_at' => now()->addHour(), 'ends_at' => now()->addDays(2),
    ]);
    $sync = app(Food99StoreStatusSync::class);

    $sync->mirror($pause, $ctx['integration']);
    expect($pause->fresh()->food99_sync_state)->toBe('scheduled')
        ->and(food99StoreCalls())->toBe([]);

    $this->travel(61)->minutes();
    $sync->applyDuePauses();
    expect($pause->fresh()->food99_sync_state)->toBe('closed')
        ->and(food99StoreCalls())->toBe([['store_status' => 2]]);

    $this->travel(2)->days();
    $sync->applyDuePauses();
    expect($pause->fresh()->food99_sync_state)->toBe('done')
        ->and(food99StoreCalls()[1])->toBe(['store_status' => 4]);
});

it('acompanha o modo ocupado ligado e desligado fora do painel', function () {
    $ctx = food99Context();

    postFood99Webhook($this, food99Callback($ctx['integration'], 'shopBusyMode', [
        'source' => 300, 'busy_state' => 1, 'busy_duration_minutes' => 30, 'busy_cook_delay_minutes' => 10,
    ]))->assertOk();

    expect($ctx['integration']->fresh()->isBusy())->toBeTrue()
        ->and($ctx['integration']->fresh()->busy_mode['until'])->toBeGreaterThan(now()->getTimestamp());

    postFood99Webhook($this, food99Callback($ctx['integration'], 'shopBusyMode', [
        'source' => 300, 'busy_state' => 0, 'busy_duration_minutes' => 0, 'busy_cook_delay_minutes' => 0,
    ], ['timestamp' => now()->getTimestamp() + 60]))->assertOk();

    expect($ctx['integration']->fresh()->isBusy())->toBeFalse();
});

it('mantém o fim do modo ocupado quando o evento não traz duração', function () {
    $until = now()->addMinutes(20)->getTimestamp();
    $ctx = food99Context(integration: ['busy_mode' => ['busy_state' => 1, 'busy_duration_minutes' => 20, 'until' => $until]]);

    postFood99Webhook($this, food99Callback($ctx['integration'], 'shopBusyMode', ['source' => 300, 'busy_state' => 1]))->assertOk();

    expect($ctx['integration']->fresh()->busy_mode['until'])->toBe($until);
});

it('não desliga o modo ocupado por evento sem busy_state', function () {
    $until = now()->addMinutes(20)->getTimestamp();
    $ctx = food99Context(integration: ['busy_mode' => ['busy_state' => 1, 'busy_duration_minutes' => 20, 'until' => $until]]);

    postFood99Webhook($this, food99Callback($ctx['integration'], 'shopBusyMode', ['source' => 300]))->assertOk();

    expect($ctx['integration']->fresh()->isBusy())->toBeTrue();
});

it('recusa pausa recorrente na 99Food', function () {
    $ctx = food99Context();
    $pause = new BranchPause(['recurring_annual' => true, 'starts_at' => now(), 'ends_at' => now()->addDay()]);

    expect(app(Food99StoreStatusSync::class)->rejectionReason($pause))->toContain('todo ano');
});

it('não pausa loja que está fechada na 99', function () {
    $ctx = food99Context(integration: ['sub_biz_status' => 3]);
    Http::fake();

    expect(fn () => app(Food99StoreStatusSync::class)->pause($ctx['integration'], 1))->toThrow(RuntimeException::class, 'reabra');
    Http::assertNothingSent();
});

it('não oferece a 99Food nas pausas com a integração desligada na plataforma', function () {
    $ctx = food99Context();
    config(['food99.enabled' => false]);
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    Livewire::test(Pauses::class, ['branch' => $ctx['branch']])
        ->assertDontSee('Fechar também a loja na 99Food');
});

it('não reabre a loja por pausa agendada que terminou sem ter sido aplicada', function () {
    $ctx = food99Context();
    Http::fake(['*' => Http::response(food99Ok(), 200)]);
    $pause = BranchPause::withoutGlobalScopes()->create([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'recurring_annual' => false,
        'starts_at' => now()->subHour(), 'ends_at' => now()->subMinute(), 'food99_sync' => true, 'food99_sync_state' => 'scheduled',
    ]);

    app(Food99StoreStatusSync::class)->applyDuePauses();

    expect($pause->fresh()->food99_sync_state)->toBe('skipped')
        ->and(food99StoreCalls())->toBe([]);
});
