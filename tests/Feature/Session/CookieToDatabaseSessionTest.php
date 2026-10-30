<?php

use App\Models\User;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'session.driver' => 'database-from-cookie',
        'session.encrypt' => true,
        'session.cookie' => 'veddi-session',
        'session.lifetime' => 120,
    ]);
    freshSessionStore();

    Route::middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class])->group(function () {
        Route::get('/__sessao/ler', fn () => ['valor' => session('valor'), 'token' => csrf_token(), 'usuario' => auth()->id()]);
        Route::get('/__sessao/gravar', function () {
            session(['valor' => str_repeat('sessao-grande ', 500)]);

            return 'ok';
        });
        Route::get('/__sessao/sair', function () {
            session()->invalidate();

            return 'ok';
        });
    });
});

// Cada request real começa do zero: sem isto a sessão ficaria em memória entre os requests do teste.
function freshSessionStore(): void
{
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

function legacySession(array $data, ?int $expires = null): array
{
    $id = Str::random(40);

    return [$id, json_encode(['data' => serialize($data), 'expires' => $expires ?? time() + 3600])];
}

test('sessão que só existe no cookie continua válida e passa para a tabela', function (bool $encrypt) {
    config(['session.encrypt' => $encrypt]);
    freshSessionStore();
    [$id, $cookie] = legacySession(['_token' => 'token-original', 'valor' => 'do-cookie']);

    $response = $this->withCookies(['veddi-session' => $id, $id => $cookie])->get('/__sessao/ler');

    $response->assertOk()->assertJson(['valor' => 'do-cookie', 'token' => 'token-original']);
    $response->assertCookieExpired($id);
    expect(DB::table('sessions')->where('id', $id)->exists())->toBeTrue();

    $this->defaultCookies = [];
    freshSessionStore();

    $this->withCookie('veddi-session', $id)->get('/__sessao/ler')
        ->assertJson(['valor' => 'do-cookie', 'token' => 'token-original']);
})->with(['com criptografia' => true, 'sem criptografia' => false]);

test('quem está logado continua logado depois da troca de driver', function () {
    $user = User::factory()->create();
    [$id, $cookie] = legacySession(['_token' => 'token', auth()->guard('web')->getName() => $user->id]);

    $this->withCookies(['veddi-session' => $id, $id => $cookie])->get('/__sessao/ler')
        ->assertJson(['usuario' => $user->id]);

    expect(DB::table('sessions')->where('id', $id)->value('user_id'))->toBe($user->id);
});

test('sessão acima de 4 KB persiste sem depender de cookie e fica cifrada na tabela', function () {
    $response = $this->get('/__sessao/gravar');
    $id = $response->getCookie('veddi-session', decrypt: true)->getValue();
    $id = \Illuminate\Support\Str::afterLast($id, '|');

    expect(collect($response->headers->getCookies())->map->getName()->all())->not->toContain($id);

    $payload = base64_decode(DB::table('sessions')->where('id', $id)->value('payload'));
    expect($payload)->not->toContain('sessao-grande');

    $this->defaultCookies = [];
    freshSessionStore();

    expect($this->withCookie('veddi-session', $id)->get('/__sessao/ler')->json('valor'))
        ->toContain('sessao-grande');
});

test('cookie vencido não reabre sessão', function () {
    [$id, $cookie] = legacySession(['_token' => 'token', 'valor' => 'vencido'], time() - 60);

    $this->withCookies(['veddi-session' => $id, $id => $cookie])->get('/__sessao/ler')
        ->assertJson(['valor' => null]);
});

test('logout apaga a sessão da tabela', function () {
    [$id, $cookie] = legacySession(['_token' => 'token', 'valor' => 'x']);
    $this->withCookies(['veddi-session' => $id, $id => $cookie])->get('/__sessao/ler');

    $this->defaultCookies = [];
    freshSessionStore();
    $this->withCookie('veddi-session', $id)->get('/__sessao/sair')->assertOk();

    expect(DB::table('sessions')->where('id', $id)->exists())->toBeFalse();
});

test('deploy:check recusa configuração insegura em produção', function (string $driver, bool $encrypt, int $exitCode) {
    config(['session.driver' => $driver, 'session.encrypt' => $encrypt]);
    $this->app['env'] = 'production';

    $this->artisan('deploy:check')->assertExitCode($exitCode);
})->with([
    'cookie' => ['cookie', true, 1],
    'database sem criptografia' => ['database', false, 1],
    'database' => ['database', true, 0],
    'transição' => ['database-from-cookie', true, 0],
]);

test('deploy:check não interfere fora de produção', function () {
    config(['session.driver' => 'cookie', 'session.encrypt' => false]);

    $this->artisan('deploy:check')->assertExitCode(0);
});
