<?php

use App\Jobs\ReconcileFood99OrdersJob;
use App\Jobs\RefreshFood99TokensJob;
use App\Mail\Food99ConnectionAlert;
use App\Models\Company;
use App\Models\CompanyNotification;
use App\Models\User;
use App\Services\Food99\Food99ConnectionMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function food99MonitorAdmin(Company $company): User
{
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    return $admin;
}

it('avisa por sino e e-mail quando a conexão caiu, sem repetir antes de 24 h', function () {
    Mail::fake();
    $ctx = food99Context(integration: ['status' => 'error']);
    food99MonitorAdmin($ctx['company']);
    $monitor = app(Food99ConnectionMonitor::class);

    expect($monitor->run()['alerts'])->toBe(1)
        ->and($monitor->run()['alerts'])->toBe(0);

    expect(CompanyNotification::withoutGlobalScopes()->where('type', 'food99_alert')->count())->toBe(1);
    Mail::assertQueued(Food99ConnectionAlert::class, 1);

    $this->travel(25)->hours();
    expect($monitor->run()['alerts'])->toBe(1);
});

it('avisa só no sino quando a loja aberta fica horas sem callback', function () {
    Mail::fake();
    $ctx = food99Context(integration: ['sub_biz_status' => 1, 'last_webhook_received_at' => now()->subHours(7)]);
    food99MonitorAdmin($ctx['company']);

    app(Food99ConnectionMonitor::class)->run();

    expect(CompanyNotification::withoutGlobalScopes()->where('type', 'food99_alert')->sole()->title)->toContain('podem não estar chegando');
    Mail::assertNothingQueued();
});

it('fecha a loja na 99 enquanto a empresa está bloqueada e reabre quando volta', function () {
    $ctx = food99Context();
    Http::fake(['*' => Http::response(food99Ok(), 200)]);
    $monitor = app(Food99ConnectionMonitor::class);

    Company::whereKey($ctx['company']->id)->update(['status' => 'BLOCKED']);
    $monitor->run();
    $monitor->run();

    Company::whereKey($ctx['company']->id)->update(['status' => 'ACTIVE']);
    $this->travel(61)->seconds();
    $monitor->run();

    $statuses = collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/setStatus'))->map(fn ($p) => $p[0]['store_status'])->values()->all();
    expect($statuses)->toBe([2, 4])
        ->and($ctx['integration']->fresh()->alerts_sent)->toBeNull();
});

it('renova token que vence em breve e marca erro em loja desvinculada', function () {
    $a = food99Context('A', ['token_expires_at' => now()->addMinutes(10)]);
    $b = food99Context('B', ['token_expires_at' => now()->addMinutes(10)]);
    Http::fake(function (Request $request) use ($b) {
        if ($request['app_shop_id'] === $b['integration']->app_shop_id) {
            return Http::response(food99Error(10101, 'shop not bind'), 200);
        }

        return str_contains($request->url(), 'authtoken/get')
            ? Http::response(food99Ok(['auth_token' => 'novo-A', 'token_expires_at' => null, 'token_expiration_time' => now()->addHours(5)->getTimestamp()]), 200)
            : Http::response(food99Ok(true), 200);
    });

    app(RefreshFood99TokensJob::class)->handle(app(\App\Services\Food99\Food99AuthService::class), app(Food99ConnectionMonitor::class));

    expect($a['integration']->fresh()->auth_token)->toBe('novo-A')
        ->and($b['integration']->fresh()->status)->toBe('error')
        ->and(CompanyNotification::withoutGlobalScopes()->where('company_id', $b['company']->id)->where('type', 'food99_alert')->count())->toBe(1);
});

it('reconcilia pedido parado com a etapa que está na 99', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    $order = receiveFood99Order($this, $ctx, $info);
    Http::fake(['*/v1/order/order/detail*' => Http::response(food99Ok(array_merge($info, ['status' => 600])), 200)]);

    app(ReconcileFood99OrdersJob::class)->handle(app(\App\Contracts\Food99GatewayContract::class), app(\App\Services\Food99\Food99OrderStatusSync::class));

    expect($order->fresh()->status)->toBe('delivered');
});

it('reconcilia pedido que a 99 cancelou por falta de aceite', function () {
    $ctx = food99Context();
    $info = food99OrderInfo($ctx);
    $order = receiveFood99Order($this, $ctx, $info);
    Http::fake(['*/v1/order/order/detail*' => Http::response(food99Ok(array_merge($info, ['status' => 922])), 200)]);

    app(ReconcileFood99OrdersJob::class)->handle(app(\App\Contracts\Food99GatewayContract::class), app(\App\Services\Food99\Food99OrderStatusSync::class));

    expect($order->fresh()->status)->toBe('cancelled')
        ->and($order->fresh()->cancellation_reason)->toContain('não aceitou a tempo');
});

it('não roda o monitor com a 99Food desligada', function () {
    config(['food99.enabled' => false]);

    $this->artisan('food99:check-connections')->expectsOutputToContain('desligada')->assertSuccessful();
});
