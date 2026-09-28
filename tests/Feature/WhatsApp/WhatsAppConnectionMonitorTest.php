<?php

use App\Livewire\Admin\NotificationBell;
use App\Mail\WhatsAppConnectionAlert;
use App\Models\Company;
use App\Models\CompanyNotification;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\Messaging\WhatsAppConnectionMonitor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

/**
 * Empresa com um administrador e uma conexão. Por padrão saudável (ativa, número novo, qualidade verde).
 *
 * @param  array<string, mixed>  $attributes
 * @return array{company: Company, connection: WhatsAppConnection, admin: User}
 */
function whatsappMonitorSetup(array $attributes = [], string $status = WhatsAppConnection::STATUS_ACTIVE, string $name = 'Restaurante Monitor'): array
{
    $company = Company::create([
        'name' => $name,
        'slug' => 'monitor-'.uniqid(),
        'order_prefix' => 'MON',
        'active' => true,
        'status' => 'ACTIVE',
    ]);

    $admin = User::factory()->create(['name' => 'Admin '.$name]);
    $admin->companies()->attach($company->id, ['role' => 'company_admin']);

    $connection = WhatsAppConnection::factory()->forCompany($company)->status($status)->create($attributes);

    return compact('company', 'connection', 'admin');
}

function whatsappMonitor(): WhatsAppConnectionMonitor
{
    return app(WhatsAppConnectionMonitor::class);
}

function whatsappCompanyNotifications(Company $company): \Illuminate\Database\Eloquent\Collection
{
    return CompanyNotification::where('company_id', $company->id)->get();
}

// ── Condições que geram alerta ────────────────────────────────────────────────

test('conexão saudável não gera alerta nenhum', function () {
    ['company' => $company, 'connection' => $connection] = whatsappMonitorSetup();

    $summary = whatsappMonitor()->run();

    expect($summary)->toBe(['checked' => 1, 'alerted' => 0, 'alerts' => 0])
        ->and(whatsappCompanyNotifications($company))->toHaveCount(0)
        ->and($connection->fresh()->alerts_sent)->toBeNull();
    Mail::assertNothingQueued();
});

test('coexistência sem uso do app há 12 dias ou mais alerta; com 11 dias não', function (int $days, bool $alerts) {
    ['company' => $company] = whatsappMonitorSetup([
        'onboarding_type' => WhatsAppConnection::TYPE_COEXISTENCE,
        'last_app_activity_at' => now()->subDays($days)->subMinutes(5),
        'connected_at' => now()->subDays(60),
    ]);

    whatsappMonitor()->run();

    expect(whatsappCompanyNotifications($company))->toHaveCount($alerts ? 1 : 0);
})->with([
    '11 dias' => [11, false],
    '12 dias' => [12, true],
    '14 dias' => [14, true],
    '30 dias' => [30, true],
]);

test('a mensagem de inatividade informa quantos dias o app está parado', function () {
    ['company' => $company] = whatsappMonitorSetup([
        'onboarding_type' => WhatsAppConnection::TYPE_COEXISTENCE,
        'last_app_activity_at' => now()->subDays(13)->subHours(2),
    ]);

    whatsappMonitor()->run();

    $notification = whatsappCompanyNotifications($company)->first();

    expect($notification->title)->toBe('Abra o app WhatsApp Business para manter a conexão')
        ->and($notification->subtitle)->toContain('sem uso há 13 dias')
        ->and($notification->subtitle)->toContain('14 dias');
});

test('coexistência sem nenhum eco do app conta a inatividade a partir da conexão', function (int $connectedDaysAgo, bool $alerts) {
    ['company' => $company] = whatsappMonitorSetup([
        'onboarding_type' => WhatsAppConnection::TYPE_COEXISTENCE,
        'last_app_activity_at' => null,
        'connected_at' => now()->subDays($connectedDaysAgo),
    ]);

    whatsappMonitor()->run();

    expect(whatsappCompanyNotifications($company))->toHaveCount($alerts ? 1 : 0);
})->with([
    'conectada há 3 dias' => [3, false],
    'conectada há 20 dias' => [20, true],
]);

test('número novo (cloud_api) nunca alerta inatividade do app', function () {
    ['company' => $company] = whatsappMonitorSetup([
        'onboarding_type' => WhatsAppConnection::TYPE_CLOUD_API,
        'last_app_activity_at' => now()->subDays(90),
    ]);

    whatsappMonitor()->run();

    expect(whatsappCompanyNotifications($company))->toHaveCount(0);
});

test('conexão que funcionava e entrou em erro alerta com o motivo', function () {
    ['company' => $company] = whatsappMonitorSetup(
        ['last_error' => 'A autorização do WhatsApp foi recusada pela Meta. Refaça a conexão.', 'connected_at' => now()->subDays(10)],
        WhatsAppConnection::STATUS_ERROR,
    );

    whatsappMonitor()->run();

    $notification = whatsappCompanyNotifications($company)->first();

    expect($notification->title)->toBe('O WhatsApp da sua loja parou de funcionar')
        ->and($notification->subtitle)->toContain('A autorização do WhatsApp foi recusada pela Meta')
        ->and($notification->subtitle)->toContain('Reconecte o número');
});

test('erro durante o onboarding (nunca chegou a conectar) não alerta: o restaurante viu na tela', function () {
    ['company' => $company] = whatsappMonitorSetup(
        ['last_error' => 'Não encontramos uma conta do WhatsApp Business na autorização.', 'connected_at' => null],
        WhatsAppConnection::STATUS_ERROR,
    );

    whatsappMonitor()->run();

    expect(whatsappCompanyNotifications($company))->toHaveCount(0);
    Mail::assertNothingQueued();
});

test('qualidade vermelha alerta; verde, amarela e desconhecida não', function (?string $rating, bool $alerts) {
    ['company' => $company] = whatsappMonitorSetup(['quality_rating' => $rating]);

    whatsappMonitor()->run();

    expect(whatsappCompanyNotifications($company))->toHaveCount($alerts ? 1 : 0);
})->with([
    'RED' => ['RED', true],
    'YELLOW' => ['YELLOW', false],
    'GREEN' => ['GREEN', false],
    'UNKNOWN' => ['UNKNOWN', false],
    'nulo' => [null, false],
]);

test('conexão em erro com qualidade vermelha só alerta o erro', function () {
    ['company' => $company] = whatsappMonitorSetup(
        ['quality_rating' => 'RED', 'last_error' => 'Conta desativada.', 'connected_at' => now()->subDay()],
        WhatsAppConnection::STATUS_ERROR,
    );

    whatsappMonitor()->run();

    $titles = whatsappCompanyNotifications($company)->pluck('title')->all();

    expect($titles)->toBe(['O WhatsApp da sua loja parou de funcionar']);
});

test('conexões desconectadas ou ainda em montagem não são verificadas', function (string $status) {
    ['company' => $company] = whatsappMonitorSetup([
        'onboarding_type' => WhatsAppConnection::TYPE_COEXISTENCE,
        'last_app_activity_at' => now()->subDays(40),
        'quality_rating' => 'RED',
        'last_error' => 'qualquer erro',
    ], $status);

    $summary = whatsappMonitor()->run();

    expect($summary['checked'])->toBe(0)
        ->and(whatsappCompanyNotifications($company))->toHaveCount(0);
})->with([
    WhatsAppConnection::STATUS_DISCONNECTED,
    WhatsAppConnection::STATUS_PENDING,
    WhatsAppConnection::STATUS_PROVISIONING,
    WhatsAppConnection::STATUS_TEMPLATES_PENDING,
]);

// ── Sino do painel e e-mail ───────────────────────────────────────────────────

test('o alerta vira notificação no sino do painel da empresa, com link para a tela do WhatsApp', function () {
    ['company' => $company] = whatsappMonitorSetup(['quality_rating' => 'RED']);

    whatsappMonitor()->run();

    $notification = whatsappCompanyNotifications($company)->sole();

    expect($notification->type)->toBe('whatsapp_alert')
        ->and($notification->title)->toBe('A qualidade do seu número de WhatsApp está baixa')
        ->and($notification->link)->toBe(route('admin.settings.whatsapp'))
        ->and($notification->read_at)->toBeNull();
});

test('o sino do painel mostra o alerta do WhatsApp', function () {
    ['company' => $company, 'admin' => $admin] = whatsappMonitorSetup(['quality_rating' => 'RED']);
    app()->instance('current.company', $company);

    whatsappMonitor()->run();

    Livewire::actingAs($admin)
        ->test(NotificationBell::class)
        ->assertSee('A qualidade do seu número de WhatsApp está baixa');
});

test('o e-mail vai para todos os company_admin da empresa e para mais ninguém', function () {
    ['company' => $company, 'admin' => $admin] = whatsappMonitorSetup(['quality_rating' => 'RED']);

    $segundoAdmin = User::factory()->create();
    $segundoAdmin->companies()->attach($company->id, ['role' => 'company_admin']);

    $gerente = User::factory()->create();
    $gerente->companies()->attach($company->id, ['role' => 'branch_manager']);

    $outraEmpresa = User::factory()->create();

    whatsappMonitor()->run();

    Mail::assertQueued(WhatsAppConnectionAlert::class, 2);
    Mail::assertQueued(WhatsAppConnectionAlert::class, fn ($mail) => $mail->hasTo($admin->email));
    Mail::assertQueued(WhatsAppConnectionAlert::class, fn ($mail) => $mail->hasTo($segundoAdmin->email));
    Mail::assertNotQueued(WhatsAppConnectionAlert::class, fn ($mail) => $mail->hasTo($gerente->email) || $mail->hasTo($outraEmpresa->email));
});

test('vários problemas da mesma conexão vão em um único e-mail por administrador', function () {
    ['company' => $company, 'admin' => $admin] = whatsappMonitorSetup([
        'onboarding_type' => WhatsAppConnection::TYPE_COEXISTENCE,
        'last_app_activity_at' => now()->subDays(13),
        'quality_rating' => 'RED',
    ]);

    $summary = whatsappMonitor()->run();

    expect($summary)->toBe(['checked' => 1, 'alerted' => 1, 'alerts' => 2])
        ->and(whatsappCompanyNotifications($company))->toHaveCount(2);

    Mail::assertQueued(WhatsAppConnectionAlert::class, 1);
    Mail::assertQueued(WhatsAppConnectionAlert::class, fn ($mail) => count($mail->alerts) === 2 && $mail->hasTo($admin->email));
});

test('o e-mail traz o nome da loja, os problemas e o link para a tela do WhatsApp', function () {
    ['company' => $company, 'admin' => $admin] = whatsappMonitorSetup(['quality_rating' => 'RED'], name: 'Coxinhas do Zé');

    $mail = new WhatsAppConnectionAlert($admin, $company, [
        ['title' => 'A qualidade do seu número de WhatsApp está baixa', 'message' => 'Confira se os clientes aceitam os avisos.'],
    ]);

    $mail->assertHasSubject('Atenção com o WhatsApp de Coxinhas do Zé');
    $mail->assertSeeInHtml('Coxinhas do Zé');
    $mail->assertSeeInHtml('A qualidade do seu número de WhatsApp está baixa');
    $mail->assertSeeInHtml('Confira se os clientes aceitam os avisos.');
    $mail->assertSeeInHtml(url('/admin/settings/whatsapp'));
    $mail->assertSeeInHtml($admin->name);
});

test('empresa sem administrador ainda recebe a notificação no painel, sem quebrar', function () {
    $company = Company::create(['name' => 'Sem Admin', 'slug' => 'sem-admin-'.uniqid(), 'order_prefix' => 'SAD', 'active' => true]);
    WhatsAppConnection::factory()->forCompany($company)->create(['quality_rating' => 'RED']);

    $summary = whatsappMonitor()->run();

    expect($summary['alerts'])->toBe(1)
        ->and(whatsappCompanyNotifications($company))->toHaveCount(1);
    Mail::assertNothingQueued();
});

test('falha ao enfileirar o e-mail não impede a notificação nem o registro do alerta', function () {
    ['company' => $company, 'connection' => $connection] = whatsappMonitorSetup(['quality_rating' => 'RED']);

    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp fora do ar'));

    $summary = whatsappMonitor()->run();

    expect($summary['alerts'])->toBe(1)
        ->and(whatsappCompanyNotifications($company))->toHaveCount(1)
        ->and($connection->fresh()->alerts_sent)->toHaveKey(WhatsAppConnectionMonitor::ALERT_QUALITY_RED);
});

test('cada empresa é avisada só do próprio problema', function () {
    ['company' => $doente, 'admin' => $adminDoente] = whatsappMonitorSetup(['quality_rating' => 'RED'], name: 'Loja Doente');
    ['company' => $saudavel, 'admin' => $adminSaudavel] = whatsappMonitorSetup([], name: 'Loja Saudável');

    whatsappMonitor()->run();

    expect(whatsappCompanyNotifications($doente))->toHaveCount(1)
        ->and(whatsappCompanyNotifications($saudavel))->toHaveCount(0);

    Mail::assertQueued(WhatsAppConnectionAlert::class, fn ($mail) => $mail->hasTo($adminDoente->email) && $mail->company->is($doente));
    Mail::assertNotQueued(WhatsAppConnectionAlert::class, fn ($mail) => $mail->hasTo($adminSaudavel->email));
});

// ── Repetição ─────────────────────────────────────────────────────────────────

test('o mesmo alerta não é reenviado no dia seguinte', function () {
    ['company' => $company, 'connection' => $connection] = whatsappMonitorSetup(['quality_rating' => 'RED']);

    whatsappMonitor()->run();

    expect($connection->fresh()->alerts_sent)->toHaveKey('quality_red');

    $this->travel(1)->days();
    $segunda = whatsappMonitor()->run();

    expect($segunda['alerts'])->toBe(0)
        ->and(whatsappCompanyNotifications($company))->toHaveCount(1);
    Mail::assertQueued(WhatsAppConnectionAlert::class, 1);
});

test('enquanto o problema durar, o alerta volta como lembrete depois de 7 dias', function () {
    ['company' => $company] = whatsappMonitorSetup(['quality_rating' => 'RED']);

    whatsappMonitor()->run();

    $this->travel(6)->days();
    whatsappMonitor()->run();
    expect(whatsappCompanyNotifications($company))->toHaveCount(1);

    $this->travel(1)->days();
    whatsappMonitor()->run();
    expect(whatsappCompanyNotifications($company))->toHaveCount(2);

    Mail::assertQueued(WhatsAppConnectionAlert::class, 2);
});

test('problema resolvido limpa o registro e, se voltar, avisa de novo na hora', function () {
    ['company' => $company, 'connection' => $connection] = whatsappMonitorSetup(['quality_rating' => 'RED']);

    whatsappMonitor()->run();
    expect($connection->fresh()->alerts_sent)->not->toBeNull();

    $connection->update(['quality_rating' => 'GREEN']);
    whatsappMonitor()->run();
    expect($connection->fresh()->alerts_sent)->toBeNull();

    $this->travel(1)->days();
    $connection->update(['quality_rating' => 'RED']);
    whatsappMonitor()->run();

    expect(whatsappCompanyNotifications($company))->toHaveCount(2);
});

test('resolver um problema não apaga o registro de outro que continua', function () {
    ['connection' => $connection] = whatsappMonitorSetup([
        'onboarding_type' => WhatsAppConnection::TYPE_COEXISTENCE,
        'last_app_activity_at' => now()->subDays(13),
        'quality_rating' => 'RED',
    ]);

    whatsappMonitor()->run();
    expect(array_keys($connection->fresh()->alerts_sent))->toEqualCanonicalizing(['app_inactive', 'quality_red']);

    $connection->update(['quality_rating' => 'GREEN']);
    whatsappMonitor()->run();

    expect(array_keys($connection->fresh()->alerts_sent))->toBe(['app_inactive']);
});

test('novo alerta de outro tipo avisa mesmo com um alerta recente já registrado', function () {
    ['company' => $company, 'connection' => $connection] = whatsappMonitorSetup(['quality_rating' => 'RED']);

    whatsappMonitor()->run();

    $connection->update(['onboarding_type' => WhatsAppConnection::TYPE_COEXISTENCE, 'last_app_activity_at' => now()->subDays(13)]);
    whatsappMonitor()->run();

    expect(whatsappCompanyNotifications($company)->pluck('title')->all())->toBe([
        'A qualidade do seu número de WhatsApp está baixa',
        'Abra o app WhatsApp Business para manter a conexão',
    ]);
});

// ── Comando e agendamento ─────────────────────────────────────────────────────

test('o comando roda o monitoramento e resume o resultado', function () {
    whatsappMonitorSetup(['quality_rating' => 'RED']);
    whatsappMonitorSetup([], name: 'Loja Saudável');

    $this->artisan('whatsapp:check-connections')
        ->expectsOutput('2 conexão(ões) verificada(s), 1 restaurante(s) avisado(s), 1 alerta(s) enviado(s).')
        ->assertSuccessful();
});

test('o comando está agendado uma vez por dia às 09:00, sem sobreposição', function () {
    Artisan::call('list');

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'whatsapp:check-connections'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 9 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
