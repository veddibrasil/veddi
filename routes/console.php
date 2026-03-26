<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cobra taxas de plataforma acumuladas no 1º dia de cada mês às 08h
Schedule::command('fees:bill')->monthlyOn(1, '08:00');
