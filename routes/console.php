<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Bloqueia empresas inadimplentes após 3 dias úteis do vencimento
Schedule::command('companies:block-overdue')->dailyAt('08:00');

Schedule::job(new \App\Jobs\ReleaseCompanyTransactionsJob)
    ->name('release-company-transactions')
    ->everyMinute()
    ->withoutOverlapping(expiresAt: 5)
    ->onOneServer();

// Atualiza snapshots de saldo de todas as empresas (após liberação das transações)
Schedule::job(new \App\Jobs\UpdateCompanyBalancesJob)
    ->name('update-company-balances')
    ->everyMinute()
    ->withoutOverlapping(expiresAt: 5)
    ->onOneServer();

Schedule::job(new \App\Jobs\TransferAsaasBalanceToStark)
    ->name('transfer-asaas-to-stark')
    ->dailyAt('6:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new \App\Jobs\RecordPlatformYieldSnapshot)
    ->name('record-platform-yield')
    ->dailyAt('6:00')
    ->withoutOverlapping()
    ->onOneServer();

// Probe de recovery automático do Asaas — executa apenas se o circuit não estiver fechado
Schedule::call(function () {
    $cb = app(\App\Services\Payment\AsaasCircuitBreaker::class);

    if ($cb->getState() === 'closed') {
        return;
    }

    try {
        app(\App\Services\Payment\AsaasService::class)->probeHealth();
    } catch (\Throwable) {
        // recordFailure() já foi chamado dentro de AsaasService::request()
    }
})
    ->name('asaas-health-probe')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
