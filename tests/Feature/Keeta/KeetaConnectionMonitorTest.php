<?php

use App\Mail\KeetaConnectionAlert;
use App\Models\CompanyNotification;
use App\Models\User;
use App\Services\Keeta\KeetaConnectionMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('avisa por sino e e-mail a integração revogada, uma vez por dia', function () {
    Mail::fake();
    $ctx = keetaContext(integration: ['status' => 'revoked']);
    $admin = User::factory()->create();
    $ctx['company']->users()->attach($admin->id, ['role' => 'company_admin']);

    app(KeetaConnectionMonitor::class)->run();
    app(KeetaConnectionMonitor::class)->run();

    expect(CompanyNotification::where('company_id', $ctx['company']->id)->where('type', 'keeta_alert')->count())->toBe(1);
    Mail::assertQueued(KeetaConnectionAlert::class, 1);
});

it('não avisa integração saudável', function () {
    Mail::fake();
    keetaContext();

    expect(app(KeetaConnectionMonitor::class)->run()['alerts'])->toBe(0);
});

it('comando sai sem fazer nada com a Keeta desligada', function () {
    config(['keeta.enabled' => false]);

    $this->artisan('keeta:check-connections')->expectsOutputToContain('Keeta desligada')->assertSuccessful();
});
