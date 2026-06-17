<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Bloqueia empresas inadimplentes após 3 dias úteis do vencimento
Schedule::command('companies:block-overdue')->dailyAt('08:00');

// Schedule::job(new \App\Jobs\ReleaseCompanyTransactionsJob)
//     ->name('release-company-transactions')
//     ->everyMinute()
//     ->withoutOverlapping(expiresAt: 5)
//     ->onOneServer();

// Atualiza snapshots de saldo de todas as empresas (após liberação das transações)
// Comentado: saldo exibido agora é calculado on-demand via BalanceService::calculateBalance().
// Reabilitar se snapshots periódicos voltarem a ser necessários.
// Schedule::job(new \App\Jobs\UpdateCompanyBalancesJob)
//     ->name('update-company-balances')
//     ->everyMinute()
//     ->withoutOverlapping(expiresAt: 5)
//     ->onOneServer();

// Comentado: snapshot de rendimento depende de saldo Vindi/Asaas — endpoints não confirmados.
// Schedule::job(new \App\Jobs\RecordPlatformYieldSnapshot)
//     ->name('record-platform-yield')
//     ->dailyAt('6:00')
//     ->withoutOverlapping()
//     ->onOneServer();

// Resolve pagamentos Vindi presos em pending após expiração (webhook perdido)
Schedule::job(new \App\Jobs\ResolveExpiredVindiPaymentsJob)
    ->name('resolve-expired-vindi-payments')
    ->everyFifteenMinutes()
    ->withoutOverlapping(expiresAt: 10)
    ->onOneServer();

// Comentado: reconciliação depende de endpoints Yapay de saldo/listagem — não confirmados.
// Schedule::job(new \App\Jobs\VindiReconciliationJob)
//     ->name('vindi-reconciliation')
//     ->weeklyOn(1, '03:00')
//     ->withoutOverlapping()
//     ->onOneServer();

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
