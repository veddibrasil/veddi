<?php

use App\Exceptions\Food99RateLimitedException;
use App\Jobs\ReconcileFood99IntegrationJob;
use App\Jobs\ReconcileFood99OrdersJob;
use App\Livewire\Admin\Integrations\Index as IntegrationsIndex;
use App\Livewire\Admin\Settings\Food99IntegrationSettings;
use App\Livewire\SuperAdmin\Integrations\Connections;
use App\Models\Branch;
use App\Models\BranchPause;
use App\Models\Company;
use App\Models\Food99Integration;
use App\Models\IfoodIntegration;
use App\Models\User;
use App\Services\Food99\Food99AuthService;
use App\Services\Food99\Food99StoreHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const FOOD99_SHOP_A = '5763000000000077001';
const FOOD99_SHOP_B = '5763000000000077002';

function food99MultiAdmin(Company $company): User
{
    $admin = User::factory()->create();
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    return $admin;
}

function food99AuthorizedList(array $shops, int $totalPage = 1): array
{
    return food99Ok(['page_no' => 1, 'total_page' => $totalPage, 'total_cnt' => count($shops), 'shops' => $shops]);
}

function food99SecondBranch(Company $company, string $name = 'Filial 2'): Branch
{
    return Branch::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'name' => $name,
        'address' => 'Rua Y, 2',
        'city' => 'SP',
        'active' => true,
        'opens_at' => '10:00:00',
        'closes_at' => '22:00:00',
    ]);
}

function food99CallsTo(string $path): int
{
    return collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), $path))->count();
}

it('avisa que a plataforma não está configurada em vez de chamar a 99 sem credencial', function () {
    $ctx = food99Context();
    $ctx['integration']->delete();
    config(['food99.app_id' => null, 'food99.app_secret' => null]);
    Http::fake();
    $this->actingAs(food99MultiAdmin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)
        ->call('connect')
        ->assertSee('ainda não foi configurada')
        ->assertSet('connectionState', 'not_connected');

    Http::assertNothingSent();
});

it('reconecta a mesma loja depois de a 99 desfazer o vínculo', function () {
    $ctx = food99Context();
    $ctx['integration']->forceFill(['status' => 'error', 'auth_token' => null, 'token_expires_at' => null])->save();
    Http::fake([
        '*/v3/auth/authorization/getAuthorizedShops' => Http::response(food99AuthorizedList([
            ['shop_id' => $ctx['integration']->shop_id, 'shop_name' => 'Loja 99 A', 'app_shop_id' => 'uuid-novo', 'bound_flag' => 1],
        ]), 200),
        '*/v1/auth/authtoken/get*' => Http::response(food99Ok(['auth_token' => 'tok', 'token_expires_at' => 0, 'token_expiration_time' => now()->addHours(3)->getTimestamp()]), 200),
        '*' => Http::response(food99Ok(['sub_biz_status' => 1]), 200),
    ]);

    app(Food99AuthService::class)->selectShop($ctx['integration']->fresh(), $ctx['integration']->shop_id);

    $integration = $ctx['integration']->fresh();
    expect($integration->status)->toBe('active')
        ->and($integration->app_shop_id)->toBe('uuid-novo')
        ->and($integration->activated_at)->not->toBeNull();
});

it('para de paginar assim que acha a loja e reaproveita a página em cache', function () {
    $ctx = food99Context();
    $ctx['integration']->delete();
    Http::fake(['*/v3/auth/authorization/getAuthorizedShops' => Http::response(food99AuthorizedList([
        ['shop_id' => FOOD99_SHOP_A, 'shop_name' => 'Loja', 'app_shop_id' => 'u1', 'bound_flag' => 1],
    ], totalPage: 9), 200)]);
    $auth = app(Food99AuthService::class);

    expect($auth->findAuthorizedShop(FOOD99_SHOP_A)['shop_name'])->toBe('Loja')
        ->and($auth->findAuthorizedShop(FOOD99_SHOP_A)['shop_name'])->toBe('Loja')
        ->and(food99CallsTo('getAuthorizedShops'))->toBe(1);
});

it('relê a lista de lojas quando a 99 avisa que uma loja autorizou', function () {
    $ctx = food99Context();
    $ctx['integration']->delete();
    Http::fake(['*/v3/auth/authorization/getAuthorizedShops' => Http::sequence()
        ->push(food99AuthorizedList([]), 200)
        ->push(food99AuthorizedList([['shop_id' => FOOD99_SHOP_A, 'shop_name' => 'Nova', 'app_shop_id' => 'uuid-a', 'bound_flag' => 1]]), 200)]);
    $auth = app(Food99AuthService::class);

    expect($auth->findAuthorizedShop(FOOD99_SHOP_A))->toBeNull();

    postFood99Webhook($this, food99Callback(null, 'shopBindStatus', ['appShopIDList' => ['uuid-a'], 'bindStatus' => 'bind']))->assertOk();
    $this->travel(2)->seconds();

    expect($auth->findAuthorizedShop(FOOD99_SHOP_A)['shop_name'])->toBe('Nova');
});

it('retoma a busca em shop/list de onde o limite interrompeu', function () {
    $ctx = food99Context();
    $ctx['integration']->delete();
    Http::fake([
        '*/v3/auth/authorization/getAuthorizedShops' => Http::response(food99Error(10006, 'denied'), 200),
        '*/v1/shop/shop/list' => Http::sequence()
            ->push(food99AuthorizedList([['shop_id' => FOOD99_SHOP_A, 'shop_name' => 'Primeira', 'app_shop_id' => 'u1', 'bound_flag' => 1]], totalPage: 2), 200)
            ->push(food99AuthorizedList([['shop_id' => FOOD99_SHOP_B, 'shop_name' => 'Segunda', 'app_shop_id' => 'u2', 'bound_flag' => 1]], totalPage: 2), 200),
    ]);
    $auth = app(Food99AuthService::class);

    expect(fn () => $auth->findAuthorizedShop(FOOD99_SHOP_B))->toThrow(Food99RateLimitedException::class);

    $this->travel(21)->seconds();

    expect($auth->findAuthorizedShop(FOOD99_SHOP_B)['shop_name'])->toBe('Segunda')
        ->and(food99CallsTo('getAuthorizedShops'))->toBe(1)
        ->and(food99CallsTo('/v1/shop/shop/list'))->toBe(2);
});

it('recusa a loja que o shopBind devolve na failure_list', function () {
    $ctx = food99Context();
    $ctx['integration']->forceFill(['shop_id' => null, 'status' => 'pending_authorization'])->save();
    Http::fake([
        '*/v3/auth/authorization/getAuthorizedShops' => Http::response(food99AuthorizedList([
            ['shop_id' => FOOD99_SHOP_A, 'shop_name' => 'Loja', 'bound_flag' => 0],
        ]), 200),
        '*/v3/auth/authorization/shopBind' => Http::response(food99Ok(['failure_list' => [['shop_id' => FOOD99_SHOP_A, 'reason' => 'duplicate']], 'success_list' => []]), 200),
    ]);

    expect(fn () => app(Food99AuthService::class)->selectShop($ctx['integration']->fresh(), FOOD99_SHOP_A))
        ->toThrow(RuntimeException::class, 'não aceitou vincular');

    expect($ctx['integration']->fresh()->shop_id)->toBeNull();
});

it('trata app_shop_id com bound_flag 0 como loja ainda sem vínculo', function () {
    $ctx = food99Context();
    $ctx['integration']->forceFill(['shop_id' => null, 'status' => 'pending_authorization'])->save();
    Http::fake(['*/v3/auth/authorization/getAuthorizedShops' => Http::response(food99AuthorizedList([
        ['shop_id' => FOOD99_SHOP_A, 'shop_name' => 'Loja', 'app_shop_id' => '123', 'bound_flag' => 0],
    ]), 200)]);

    $shop = app(Food99AuthService::class)->connectableShop($ctx['integration']->fresh(), FOOD99_SHOP_A);

    expect($shop['bound'])->toBeFalse()->and($shop['app_shop_id'])->toBeNull();
});

it('limite de frequência na ativação não vira erro de conexão e deixa concluir depois', function () {
    $ctx = food99Context();
    $ctx['integration']->forceFill(['shop_id' => null, 'shop_name' => null, 'auth_token' => null, 'token_expires_at' => null, 'status' => 'pending_authorization', 'authorization_url' => 'https://99food.test/a', 'authorization_url_expires_at' => now()->addDay()])->save();
    Http::fake([
        '*/v3/auth/authorization/getAuthorizedShops' => Http::response(food99AuthorizedList([
            ['shop_id' => FOOD99_SHOP_A, 'shop_name' => 'Coxinha 99', 'app_shop_id' => 'uuid-x', 'bound_flag' => 1],
        ]), 200),
        '*/v1/auth/authtoken/get*' => Http::sequence()
            ->push(food99Error(10005, 'too many'), 200)
            ->push(food99Ok(['auth_token' => 'tok', 'token_expiration_time' => now()->addHours(3)->getTimestamp()]), 200),
        '*' => Http::response(food99Ok(['sub_biz_status' => 1]), 200),
    ]);
    $this->actingAs(food99MultiAdmin($ctx['company']));

    $component = Livewire::test(Food99IntegrationSettings::class)
        ->set('shopIdInput', FOOD99_SHOP_A)
        ->call('linkShop')
        ->call('confirmLinkShop')
        ->assertSet('connectionState', 'activating')
        ->assertSee('Concluir ativação');

    expect($ctx['integration']->fresh()->status)->toBe('pending_authorization');

    $this->travel(31)->seconds();

    $component->call('reapplySettings')->assertSet('connectionState', 'connected');
    expect($ctx['integration']->fresh()->status)->toBe('active');
});

it('liga em outra filial a loja cuja desconexão anterior não desfez o vínculo', function () {
    $ctx = food99Context();
    $other = food99SecondBranch($ctx['company']);
    $target = Food99Integration::create([
        'company_id' => $ctx['company']->id,
        'branch_id' => $other->id,
        'app_shop_id' => Food99Integration::appShopIdFor($ctx['company']->id, $other->id),
        'status' => 'pending_authorization',
    ]);
    $bound = $ctx['integration']->app_shop_id;
    $ctx['integration']->forceFill(['shop_id' => null, 'shop_name' => null, 'auth_token' => null, 'status' => 'disconnected'])->save();
    Http::fake([
        '*/v3/auth/authorization/getAuthorizedShops' => Http::response(food99AuthorizedList([
            ['shop_id' => FOOD99_SHOP_A, 'shop_name' => 'Loja', 'app_shop_id' => $bound, 'bound_flag' => 1],
        ]), 200),
        '*/v1/auth/authtoken/get*' => Http::response(food99Ok(['auth_token' => 'tok', 'token_expiration_time' => now()->addHours(3)->getTimestamp()]), 200),
        '*' => Http::response(food99Ok(['sub_biz_status' => 1]), 200),
    ]);

    app(Food99AuthService::class)->selectShop($target, FOOD99_SHOP_A);

    expect($target->fresh()->app_shop_id)->toBe($bound)
        ->and($target->fresh()->status)->toBe('active')
        ->and($ctx['integration']->fresh()->app_shop_id)->not->toBe($bound);
});

it('conecta filiais diferentes da mesma empresa a lojas diferentes e bloqueia repetir a loja', function () {
    $ctx = food99Context();
    $ctx['integration']->delete();
    $second = food99SecondBranch($ctx['company']);
    Http::fake([
        '*/v3/auth/authorization/getAuthorizedShops' => Http::response(food99AuthorizedList([
            ['shop_id' => FOOD99_SHOP_A, 'shop_name' => 'Loja Centro', 'app_shop_id' => 'uuid-a', 'bound_flag' => 1],
            ['shop_id' => FOOD99_SHOP_B, 'shop_name' => 'Loja Sul', 'app_shop_id' => 'uuid-b', 'bound_flag' => 1],
        ]), 200),
        '*/v1/auth/authtoken/get*' => Http::response(food99Ok(['auth_token' => 'tok', 'token_expiration_time' => now()->addHours(3)->getTimestamp()]), 200),
        '*' => Http::response(food99Ok(['sub_biz_status' => 1]), 200),
    ]);
    $this->actingAs(food99MultiAdmin($ctx['company']));

    $component = Livewire::test(Food99IntegrationSettings::class)
        ->set('branchId', $ctx['branch']->id)
        ->set('shopIdInput', FOOD99_SHOP_A)->call('linkShop')->call('confirmLinkShop')
        ->assertSet('connectionState', 'connected');

    $component->set('branchId', $second->id)
        ->assertSet('connectionState', 'not_connected')
        ->set('shopIdInput', FOOD99_SHOP_A)->call('linkShop')
        ->assertSet('pendingShop', null)
        ->set('shopIdInput', FOOD99_SHOP_B)->call('linkShop')->call('confirmLinkShop')
        ->assertSet('connectionState', 'connected');

    expect(Food99Integration::where('company_id', $ctx['company']->id)->pluck('shop_id', 'branch_id')->all())
        ->toBe([$ctx['branch']->id => FOOD99_SHOP_A, $second->id => FOOD99_SHOP_B]);
    expect(collect($component->get('branchOptions'))->pluck('state')->unique()->values()->all())->toBe(['conectada']);
});

it('impede duas empresas de ligarem a mesma loja', function () {
    $a = food99Context('A');
    $b = food99Context('B');
    $b['integration']->forceFill(['shop_id' => null, 'status' => 'pending_authorization'])->save();
    Http::fake(['*/v3/auth/authorization/getAuthorizedShops' => Http::response(food99AuthorizedList([
        ['shop_id' => $a['integration']->shop_id, 'shop_name' => 'Loja A', 'app_shop_id' => $a['integration']->app_shop_id, 'bound_flag' => 1],
    ]), 200)]);

    expect(fn () => app(Food99AuthService::class)->connectableShop($b['integration']->fresh(), $a['integration']->shop_id))
        ->toThrow(\App\Exceptions\Food99ShopAlreadyLinkedException::class);
});

it('não leva o ID nem a loja pendente para a filial seguinte', function () {
    $ctx = food99Context();
    $ctx['integration']->delete();
    $second = food99SecondBranch($ctx['company']);
    Http::fake(['*/v3/auth/authorization/getAuthorizedShops' => Http::response(food99AuthorizedList([
        ['shop_id' => FOOD99_SHOP_A, 'shop_name' => 'Loja Centro', 'app_shop_id' => 'uuid-a', 'bound_flag' => 1],
    ]), 200)]);
    $this->actingAs(food99MultiAdmin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)
        ->set('branchId', $ctx['branch']->id)
        ->set('shopIdInput', FOOD99_SHOP_A)->call('linkShop')
        ->assertSet('pendingShop.shop_id', FOOD99_SHOP_A)
        ->set('branchId', $second->id)
        ->assertSet('pendingShop', null)
        ->assertSet('shopIdInput', '');
});

it('não troca a loja de uma filial em operação sem desconectar', function () {
    $ctx = food99Context();
    Http::fake();
    $this->actingAs(food99MultiAdmin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)
        ->set('shopIdInput', FOOD99_SHOP_A)
        ->call('linkShop')
        ->assertSet('pendingShop', null);

    Http::assertNothingSent();
    expect($ctx['integration']->fresh()->shop_id)->not->toBe(FOOD99_SHOP_A);
});

it('não mostra erro de banco para o admin', function () {
    $ctx = food99Context();
    $ctx['integration']->forceFill(['shop_id' => null, 'status' => 'pending_authorization'])->save();
    $this->mock(Food99AuthService::class)->shouldReceive('connectableShop')->andThrow(new \Illuminate\Database\QueryException('sqlite', 'select segredo', [], new RuntimeException('falha')));
    $this->actingAs(food99MultiAdmin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)
        ->set('shopIdInput', FOOD99_SHOP_A)
        ->call('linkShop')
        ->assertSee('Não foi possível concluir agora')
        ->assertDontSee('select segredo');
});

it('cancela a conexão pendente', function () {
    $ctx = food99Context();
    $ctx['integration']->forceFill(['shop_id' => null, 'status' => 'pending_authorization', 'authorization_url' => 'https://99food.test/a', 'authorization_url_expires_at' => now()->addDay()])->save();
    $this->actingAs(food99MultiAdmin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)
        ->assertSet('connectionState', 'pending_authorization')
        ->call('cancelConnection')
        ->assertSet('connectionState', 'not_connected');

    expect($ctx['integration']->fresh()->status)->toBe('disconnected');
});

it('libera a 99Food só para as empresas de FOOD99_COMPANY_IDS', function () {
    $ctx = food99Context();
    config(['food99.company_ids' => [$ctx['company']->id + 1]]);
    $this->actingAs(food99MultiAdmin($ctx['company']));

    Livewire::test(IntegrationsIndex::class)->assertDontSee('99Food')->assertSee('iFood');
    $this->get(route('admin.settings.99food'))->assertForbidden();

    config(['food99.company_ids' => [$ctx['company']->id]]);
    Livewire::test(IntegrationsIndex::class)->assertSee('99Food');
    $this->get(route('admin.settings.99food'))->assertOk();
    expect($ctx['integration']->fresh()->load('company')->isOperational())->toBeTrue();
});

it('mantém operando a loja já ligada quando a empresa sai de FOOD99_COMPANY_IDS', function () {
    $ctx = food99Context();
    config(['food99.company_ids' => [$ctx['company']->id + 1]]);
    Company::query()->whereKey($ctx['company']->id)->update(['status' => 'ACTIVE']);

    expect($ctx['integration']->fresh()->load('company')->isOperational())->toBeTrue()
        ->and($ctx['company']->fresh()->canUseFood99Integration())->toBeFalse();
});

it('avisa atraso quando a loja aberta para de receber callbacks', function () {
    $ctx = food99Context(integration: ['sub_biz_status' => 1, 'activated_at' => now()->subDay()]);
    $this->actingAs(food99MultiAdmin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)->assertSee('Com atraso')->assertDontSee('Recebendo pedidos');

    $ctx['integration']->update(['last_webhook_received_at' => now()]);
    Livewire::test(Food99IntegrationSettings::class)->assertSee('Recebendo pedidos');
});

it('mostra quando a 99 bloqueia a loja', function () {
    $ctx = food99Context(integration: ['sub_biz_status' => 6, 'last_webhook_received_at' => now()]);
    $this->actingAs(food99MultiAdmin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)->assertSee('Bloqueada pela 99Food');
});

it('mantém o fim do modo ocupado ao reler a loja', function () {
    $until = now()->addMinutes(20)->getTimestamp();
    $ctx = food99Context(integration: ['busy_mode' => ['busy_state' => 1, 'until' => $until]]);
    Http::fake(['*/v1/shop/shop/detail*' => Http::response(food99Ok(['busy_mode_info' => ['busy_state' => 1, 'busy_duration_minutes' => 30]]), 200)]);

    app(Food99AuthService::class)->syncShopDetail($ctx['integration']);

    expect($ctx['integration']->fresh()->busy_mode['until'])->toBe($until);

    $this->travel(21)->minutes();
    expect($ctx['integration']->fresh()->isBusy())->toBeFalse();
});

it('guarda horários, modo ocupado e indisponibilidade da loja', function () {
    $ctx = food99Context();
    $until = now()->addHour()->getTimestamp();
    Http::fake(['*/v1/shop/shop/detail*' => Http::response(food99Ok([
        'name' => 'Loja Detalhe', 'biz_status' => 1, 'sub_biz_status' => 1, 'auto_switch' => 2, 'promise_produce_time' => 900,
        'biz_day_time' => [['biz_day' => [1, 2], 'biz_time' => [['begin' => '08:00', 'end' => '24:00']]]],
        'busy_mode_info' => ['busy_state' => 1, 'busy_duration_minutes' => 30, 'busy_cook_delay_minutes' => 10],
        'store_unavailable_reason' => 'Motivo especial', 'store_unavailable_end_time' => $until,
    ]), 200)]);

    app(Food99AuthService::class)->syncShopDetail($ctx['integration']);

    $integration = $ctx['integration']->fresh();
    expect($integration->auto_switch)->toBe(2)
        ->and($integration->promise_produce_time)->toBe(900)
        ->and($integration->busy_mode['busy_state'])->toBe(1)
        ->and($integration->store_unavailable_reason)->toBe('Motivo especial')
        ->and($integration->store_unavailable_until->getTimestamp())->toBe($until)
        ->and(app(Food99StoreHours::class)->shiftsFrom($integration->business_hours))->toBe([
            ['day' => 1, 'start' => '08:00', 'end' => '23:59'],
            ['day' => 2, 'start' => '08:00', 'end' => '23:59'],
        ]);
});

it('limpa a indisponibilidade quando o shopStatus avisa que a loja voltou', function () {
    $ctx = food99Context(integration: ['store_unavailable_reason' => 'Motivo', 'store_unavailable_until' => now()->addHour()]);

    postFood99Webhook($this, food99Callback($ctx['integration'], 'shopStatus', [
        'biz_status' => 1, 'sub_biz_status' => 1, 'store_availability_status' => 1,
        'store_unavailable_reason' => '', 'store_unavailable_end_time' => 0,
    ]))->assertOk();

    $integration = $ctx['integration']->fresh();
    expect($integration->store_unavailable_reason)->toBeNull()
        ->and($integration->store_unavailable_until)->toBeNull();
});

it('salva os horários no formato camelCase e confere o resultado por campo', function () {
    $ctx = food99Context();
    Http::fake(['*/v1/shop/shop/update' => Http::response(food99Ok(['biz_day_time' => true]), 200)]);

    app(Food99StoreHours::class)->save($ctx['integration'], [
        ['day' => 6, 'start' => '09:00', 'end' => '13:00'],
        ['day' => 1, 'start' => '08:00', 'end' => '12:00'],
        ['day' => 2, 'start' => '08:00', 'end' => '12:00'],
    ]);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/shop/shop/update') && $r['biz_day_time'] === [
        ['bizDay' => [1, 2], 'bizTime' => [['begin' => '08:00', 'end' => '12:00']]],
        ['bizDay' => [6], 'bizTime' => [['begin' => '09:00', 'end' => '13:00']]],
    ]);
    expect($ctx['integration']->fresh()->business_hours[0])->toBe(['biz_day' => [1, 2], 'biz_time' => [['begin' => '08:00', 'end' => '12:00']]]);
});

it('não grava horário que a 99 respondeu como não aceito', function () {
    $ctx = food99Context();
    Http::fake(['*/v1/shop/shop/update' => Http::response(food99Ok(['biz_day_time' => false]), 200)]);

    expect(fn () => app(Food99StoreHours::class)->save($ctx['integration'], [['day' => 1, 'start' => '08:00', 'end' => '12:00']]))
        ->toThrow(RuntimeException::class, 'não aceitou');

    expect($ctx['integration']->fresh()->business_hours)->toBeNull();
});

it('valida os turnos antes de chamar a 99', function (array $shifts, string $message) {
    $ctx = food99Context();
    Http::fake();

    expect(fn () => app(Food99StoreHours::class)->save($ctx['integration'], $shifts))
        ->toThrow(InvalidArgumentException::class, $message);

    Http::assertNothingSent();
})->with([
    'semana vazia' => [[], 'ao menos um turno'],
    'meia-noite' => [[['day' => 1, 'start' => '22:00', 'end' => '02:00']], 'meia-noite'],
    'sobreposição' => [[['day' => 1, 'start' => '08:00', 'end' => '12:00'], ['day' => 1, 'start' => '11:00', 'end' => '14:00']], 'sobrepostos'],
    'formato' => [[['day' => 1, 'start' => '8h', 'end' => '12:00']], 'HH:MM'],
    'dia inválido' => [[['day' => 9, 'start' => '08:00', 'end' => '12:00']], 'HH:MM'],
]);

it('usa os horários da filial dividindo o turno que passa da meia-noite', function () {
    $ctx = food99Context();
    $branch = food99SecondBranch($ctx['company']);
    $branch->update(['opens_at' => '18:00:00', 'closes_at' => '02:00:00', 'available_days' => [0]]);

    expect(app(Food99StoreHours::class)->shiftsFromBranch($branch->fresh()))->toBe([
        ['day' => 1, 'start' => '00:00', 'end' => '02:00'],
        ['day' => 7, 'start' => '18:00', 'end' => '23:59'],
    ]);
});

it('edita os horários pela aba e preenche com os da filial', function () {
    $ctx = food99Context();
    Http::fake(['*/v1/shop/shop/update' => Http::response(food99Ok(['biz_day_time' => true]), 200)]);
    $this->actingAs(food99MultiAdmin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)
        ->set('tab', 'horarios')
        ->assertSee('Horários de funcionamento na 99Food')
        ->call('addOpeningShift', 3)
        ->assertSet('openingShifts.0', ['day' => 3, 'start' => '09:00', 'end' => '18:00'])
        ->call('saveOpeningHours')
        ->assertSee('Horários salvos na 99Food');

    expect($ctx['integration']->fresh()->business_hours)->not->toBeNull();
});

it('lista a pausa da filial na aba e tira só da 99Food', function () {
    $ctx = food99Context(integration: ['sub_biz_status' => 3]);
    $pause = BranchPause::create([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'reason' => 'Reforma',
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(5),
        'food99_sync' => true, 'food99_sync_state' => 'closed',
    ]);
    Http::fake(['*/v1/shop/shop/setStatus' => Http::response(food99Ok(), 200)]);
    $this->actingAs(food99MultiAdmin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)
        ->set('tab', 'pausas')
        ->assertSee('Reforma')
        ->assertSee('Em andamento')
        ->call('confirmRemovePause', $pause->id)
        ->assertSet('showRemovePauseModal', true)
        ->call('removePauseFrom99')
        ->assertDontSee('Reforma');

    expect($pause->fresh()->food99_sync)->toBeFalse();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setStatus') && (int) $r['store_status'] === 4);
});

it('não remove pausa de outra empresa pela tela', function () {
    $a = food99Context('A');
    $b = food99Context('B');
    $foreign = BranchPause::create([
        'company_id' => $a['company']->id, 'branch_id' => $a['branch']->id, 'reason' => 'De outra empresa',
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(5),
        'food99_sync' => true, 'food99_sync_state' => 'closed',
    ]);
    app()->instance('current.company', $b['company']);
    $this->actingAs(food99MultiAdmin($b['company']));

    Livewire::test(Food99IntegrationSettings::class)
        ->call('confirmRemovePause', $foreign->id)
        ->assertNotFound();

    expect($foreign->fresh()->food99_sync)->toBeTrue();
});

it('usa os motivos de pausa oficiais da 99', function () {
    $ctx = food99Context();
    Http::fake(['*/v1/shop/shop/setStatus' => Http::response(food99Ok(), 200)]);
    $this->actingAs(food99MultiAdmin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)
        ->set('tab', 'pausas')
        ->assertSee('Ingredientes insuficientes')
        ->assertDontSee('Falta de entregador')
        ->set('pauseReason', 1003)
        ->call('storeAction', 'pause');

    Http::assertSent(fn (Request $r) => (int) $r['pause_reason_code'] === 1003 && (int) $r['store_status'] === 3);
});

it('encerra o modo ocupado', function () {
    $ctx = food99Context(integration: ['busy_mode' => ['busy_state' => 1]]);
    Http::fake(['*/v1/shop/shop/setStatus' => Http::response(food99Ok(), 200)]);
    $this->actingAs(food99MultiAdmin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)
        ->assertSee('Encerrar modo ocupado')
        ->call('storeAction', 'busy-off')
        ->assertSee('Ativar modo ocupado')
        ->assertDontSee('Encerrar modo ocupado');

    Http::assertSent(fn (Request $r) => $r['busy_mode'] === ['busy_state' => 0]);
});

it('mostra o modo ocupado na hora e encerra sozinho quando vence', function () {
    $ctx = food99Context();
    Http::fake(['*/v1/shop/shop/setStatus' => Http::response(food99Ok(), 200)]);
    $this->actingAs(food99MultiAdmin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)
        ->assertSee('Ativar modo ocupado')
        ->set('busyMinutes', 15)
        ->call('storeAction', 'busy')
        ->assertSee('Encerrar modo ocupado')
        ->assertSee('modo ocupado');

    expect($ctx['integration']->fresh()->isBusy())->toBeTrue();

    $this->travel(16)->minutes();

    expect($ctx['integration']->fresh()->isBusy())->toBeFalse();
    Livewire::test(Food99IntegrationSettings::class)->assertSee('Ativar modo ocupado');
});

it('não enfileira de novo com envio de cardápio em andamento', function () {
    Queue::fake();
    $ctx = food99Context(integration: ['catalog_sync_status' => 'running']);
    $this->actingAs(food99MultiAdmin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)->set('tab', 'cardapio')->call('syncCatalogNow');

    Queue::assertNothingPushed();
    expect($ctx['integration']->fresh()->catalog_sync_status)->toBe('running');
});

it('solta o envio de cardápio perdido na fila', function () {
    Queue::fake();
    $ctx = food99Context(integration: ['catalog_sync_status' => 'queued', 'catalog_queued_at' => now()->subMinutes(11)]);
    $this->actingAs(food99MultiAdmin($ctx['company']));

    Livewire::test(Food99IntegrationSettings::class)->set('tab', 'cardapio')->call('syncCatalogNow');

    Queue::assertPushed(\App\Jobs\SyncFood99CatalogJob::class, 1);
    expect($ctx['integration']->fresh()->catalog_queued_at->gt(now()->subMinute()))->toBeTrue();
});

it('não deixa a primeira publicação em fila quando o envio automático segurou o lock', function () {
    $ctx = food99Context(integration: ['catalog_published_at' => null, 'catalog_sync_status' => 'queued', 'catalog_queued_at' => now()]);

    (new \App\Jobs\SyncFood99CatalogJob($ctx['branch']->id))->handle(app(\App\Services\Food99\Food99CatalogSyncService::class));

    expect($ctx['integration']->fresh()->catalog_sync_status)->toBe('failed');
});

it('tira a pausa fechada da 99 mesmo com a loja já reaberta', function () {
    $ctx = food99Context();
    $pause = BranchPause::create([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'reason' => 'Reforma',
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(5),
        'food99_sync' => true, 'food99_sync_state' => 'closed',
    ]);
    Http::fake(['*/v1/shop/shop/setStatus' => Http::response(food99Error(56101, 'Incorrect store status'), 200)]);

    expect(app(\App\Services\Food99\Food99StoreStatusSync::class)->remove($pause))->toBeTrue()
        ->and($pause->fresh()->food99_sync_state)->toBe('done');
});

it('desconecta com o id próprio da filial preso em outra linha', function () {
    $ctx = food99Context();
    $other = food99SecondBranch($ctx['company']);
    $ctx['integration']->forceFill(['app_shop_id' => 'uuid-outro'])->save();
    Food99Integration::create([
        'company_id' => $ctx['company']->id, 'branch_id' => $other->id,
        'app_shop_id' => Food99Integration::appShopIdFor($ctx['company']->id, $ctx['branch']->id),
        'shop_id' => FOOD99_SHOP_B, 'status' => 'active',
    ]);
    Http::fake(['*' => Http::response(food99Ok(true), 200)]);

    app(Food99AuthService::class)->disconnect($ctx['integration']->fresh());

    expect($ctx['integration']->fresh()->status)->toBe('disconnected')
        ->and($ctx['integration']->fresh()->app_shop_id)->toStartWith(Food99Integration::appShopIdFor($ctx['company']->id, $ctx['branch']->id).'-');
});

it('esquece a lista de lojas ao desconectar', function () {
    $ctx = food99Context();
    $shopId = $ctx['integration']->shop_id;
    Http::fake([
        '*/v3/auth/authorization/getAuthorizedShops' => Http::sequence()
            ->push(food99AuthorizedList([['shop_id' => $shopId, 'shop_name' => 'Antes', 'app_shop_id' => 'u1', 'bound_flag' => 1]]), 200)
            ->push(food99AuthorizedList([['shop_id' => $shopId, 'shop_name' => 'Depois', 'app_shop_id' => 'u2', 'bound_flag' => 1]]), 200),
        '*' => Http::response(food99Ok(true), 200),
    ]);
    $auth = app(Food99AuthService::class);

    expect($auth->findAuthorizedShop($shopId)['shop_name'])->toBe('Antes');
    $auth->disconnect($ctx['integration']->fresh());
    $this->travel(2)->seconds();

    expect($auth->findAuthorizedShop($shopId)['shop_name'])->toBe('Depois');
});

it('não troca de lista quando uma página do meio falha', function () {
    $ctx = food99Context();
    $ctx['integration']->delete();
    Http::fake([
        '*/v3/auth/authorization/getAuthorizedShops' => Http::sequence()
            ->push(food99AuthorizedList([['shop_id' => FOOD99_SHOP_A, 'shop_name' => 'Primeira', 'app_shop_id' => 'u1', 'bound_flag' => 1]], totalPage: 2), 200)
            ->push(food99Error(10001, 'erro'), 200),
        '*/v1/shop/shop/list' => Http::response(food99AuthorizedList([]), 200),
    ]);

    expect(fn () => app(Food99AuthService::class)->findAuthorizedShop(FOOD99_SHOP_B))
        ->toThrow(\App\Exceptions\Food99ApiException::class);

    expect(food99CallsTo('/v1/shop/shop/list'))->toBe(0);
});

it('reconcilia cada loja em um job próprio', function () {
    Queue::fake();
    $a = food99Context('A');
    $b = food99Context('B');
    Company::query()->whereIn('id', [$a['company']->id, $b['company']->id])->update(['status' => 'ACTIVE']);

    (new ReconcileFood99OrdersJob)->handle();

    Queue::assertPushed(ReconcileFood99IntegrationJob::class, 2);
});

it('mostra as lojas de todas as empresas pro super admin', function () {
    $a = food99Context('A');
    $b = food99Context('B', ['status' => 'error']);
    IfoodIntegration::withoutGlobalScopes()->create([
        'company_id' => $a['company']->id, 'branch_id' => $a['branch']->id,
        'merchant_id' => 'merchant-1', 'merchant_name' => 'Merchant iFood', 'status' => 'active',
    ]);
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    $this->get(route('superadmin.integrations.index'))->assertOk();

    Livewire::test(Connections::class)
        ->assertSee('Empresa 99 A')->assertSee('Empresa 99 B')
        ->assertSee('Merchant iFood')->assertSee('Reconexão necessária')
        ->set('channel', 'ifood')->assertSee('Merchant iFood')->assertDontSee('Loja 99 A')
        ->set('channel', '')->set('search', 'Empresa 99 B')->assertSee('Loja 99 B')->assertDontSee('Loja 99 A')
        ->set('search', '')->set('state', 'error')->assertSee('Loja 99 B')->assertDontSee('Loja 99 A');
});

it('lista pro super admin as lojas autorizadas sem filial', function () {
    $ctx = food99Context();
    Http::fake(['*/v3/auth/authorization/getAuthorizedShops' => Http::response(food99AuthorizedList([
        ['shop_id' => $ctx['integration']->shop_id, 'shop_name' => 'Já ligada', 'app_shop_id' => 'u1', 'bound_flag' => 1],
        ['shop_id' => FOOD99_SHOP_B, 'shop_name' => 'Esperando filial', 'app_shop_id' => 'u2', 'bound_flag' => 1],
    ]), 200)]);
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    Livewire::test(Connections::class)
        ->call('loadUnlinkedShops')
        ->assertSee('Esperando filial')
        ->assertDontSee('Já ligada');
});

it('bloqueia a tela de integrações do super admin para quem não é', function () {
    $ctx = food99Context();

    $this->actingAs(food99MultiAdmin($ctx['company']))->get(route('superadmin.integrations.index'))->assertForbidden();
});
