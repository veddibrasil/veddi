<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cobra taxas de plataforma acumuladas no 1º dia de cada mês às 08h
Schedule::command('fees:bill')->monthlyOn(1, '08:00');

// Bloqueia empresas inadimplentes após 3 dias úteis do vencimento
Schedule::command('companies:block-overdue')->dailyAt('08:00');

Schedule::job(new \App\Jobs\ReleaseCompanyTransactionsJob)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Atualiza snapshots de saldo de todas as empresas (após liberação das transações)
Schedule::job(new \App\Jobs\UpdateCompanyBalancesJob)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
