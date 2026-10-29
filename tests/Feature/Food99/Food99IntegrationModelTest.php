<?php

use App\Models\Company;
use App\Models\Food99Integration;
use App\Models\Food99Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('guarda o auth_token criptografado e fora da serialização', function () {
    $ctx = food99Context();

    $raw = DB::table('food99_integrations')->where('id', $ctx['integration']->id)->value('auth_token');

    expect($raw)->not->toBe('token-99-A')
        ->and($ctx['integration']->fresh()->auth_token)->toBe('token-99-A')
        ->and($ctx['integration']->toArray())->not->toHaveKey('auth_token');
});

it('gera app_shop_id estável por empresa e filial', function () {
    expect(Food99Integration::appShopIdFor(12, 34))->toBe('veddi-12-34');
});

it('só considera operacional integração ativa, com loja e de empresa liberada', function () {
    $ctx = food99Context();
    $integration = $ctx['integration'];

    expect(Food99Integration::operational()->pluck('id')->all())->toBe([$integration->id])
        ->and($integration->isOperational())->toBeTrue();

    $integration->update(['status' => 'paused']);
    expect(Food99Integration::operational()->count())->toBe(0)
        ->and(Food99Integration::operational(includePaused: true)->count())->toBe(1)
        ->and($integration->fresh()->isOperational(includePaused: true))->toBeTrue();

    $integration->update(['status' => 'active', 'shop_id' => null]);
    expect(Food99Integration::operational()->count())->toBe(0);

    $integration->update(['shop_id' => '5763000000000000001']);
    Company::whereKey($ctx['company']->id)->update(['status' => 'BLOCKED']);
    expect(Food99Integration::operational()->count())->toBe(0)
        ->and($integration->fresh()->isOperational())->toBeFalse();
});

it('pede renovação do token dentro da margem configurada', function () {
    $ctx = food99Context();
    $integration = $ctx['integration'];

    expect($integration->tokenNeedsRefresh())->toBeFalse();

    $integration->token_expires_at = now()->addMinutes(3);
    expect($integration->tokenNeedsRefresh())->toBeTrue();

    $integration->auth_token = null;
    $integration->token_expires_at = now()->addHours(2);
    expect($integration->tokenNeedsRefresh())->toBeTrue();
});

it('impede a mesma loja da 99 em duas integrações', function () {
    $a = food99Context('A');
    $b = food99Context('B');

    expect(fn () => $b['integration']->update(['shop_id' => $a['integration']->shop_id]))
        ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

it('explica o desfecho de solicitação sem resposta', function () {
    $cancel = new Food99Request(['kind' => 'cancel', 'status' => 'expired']);
    $refund = new Food99Request(['kind' => 'refund', 'status' => 'expired']);

    expect($cancel->timeoutOutcome())->toBe('rejected')
        ->and($refund->timeoutOutcome())->toBe('accepted')
        ->and($cancel->statusLabel())->toContain('recusada')
        ->and($refund->statusLabel())->toContain('aceita');
});
