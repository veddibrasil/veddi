<?php

use App\Jobs\MonitorIfoodWebhookHealthJob;
use App\Jobs\PollIfoodEventsJob;
use App\Jobs\ReconcilePendingFiscalNotesJob;
use App\Jobs\ResolveExpiredVindiPaymentsJob;
use App\Jobs\SyncIfoodCatalogJob;
use App\Models\IfoodIntegration;
use App\Services\Payment\AsaasCircuitBreaker;
use App\Services\Payment\AsaasService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Bloqueia empresas inadimplentes após 3 dias úteis do vencimento
Schedule::command('companies:block-overdue')->dailyAt('08:00');

// Avisa restaurantes com WhatsApp inativo (coexistência sem uso do app), com erro ou com qualidade baixa
Schedule::command('whatsapp:check-connections')
    ->name('whatsapp-check-connections')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->onOneServer();

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
Schedule::job(new ResolveExpiredVindiPaymentsJob)
    ->name('resolve-expired-vindi-payments')
    ->everyFifteenMinutes()
    ->withoutOverlapping(expiresAt: 10)
    ->onOneServer();

// Resolve notas fiscais presas em pending quando o webhook da Focus NFe nunca chega
Schedule::job(new ReconcilePendingFiscalNotesJob)
    ->name('reconcile-pending-fiscal-notes')
    ->everyFifteenMinutes()
    ->withoutOverlapping(expiresAt: 10)
    ->onOneServer();

// Comentado: reconciliação depende de endpoints Yapay de saldo/listagem — não confirmados.
// Schedule::job(new \App\Jobs\VindiReconciliationJob)
//     ->name('vindi-reconciliation')
//     ->weeklyOn(1, '03:00')
//     ->withoutOverlapping()
//     ->onOneServer();

// Comentado: reconciliação depende do endpoint da Financial API do iFood — não confirmado em sandbox ainda.
// Schedule::job(new \App\Jobs\ReconcileIfoodSettlementsJob)
//     ->name('reconcile-ifood-settlements')
//     ->dailyAt('04:00')
//     ->withoutOverlapping()
//     ->onOneServer();

// Presença iFood: polling contínuo de todas as integrações distribuídas ativas.
Schedule::job(new PollIfoodEventsJob)
    ->name('poll-ifood-events')
    ->everyThirtySeconds()
    ->withoutOverlapping(expiresAt: 1)
    ->onOneServer();

// Detecta integrações iFood cujo webhook parou de chegar e aciona o fallback
// de polling acima (via webhook_status=degraded).
Schedule::job(new MonitorIfoodWebhookHealthJob)
    ->name('monitor-ifood-webhook-health')
    ->everyFiveMinutes()
    ->withoutOverlapping(expiresAt: 3)
    ->onOneServer();

// Sync completo de catálogo iFood (preço/cardápio) — segurança além do sync em
// tempo real de disponibilidade (ProductObserver -> SyncIfoodCatalogJob por item).
Schedule::call(function () {
    IfoodIntegration::withoutGlobalScopes()
        ->where('status', 'active')
        ->pluck('branch_id')
        ->each(fn ($branchId) => SyncIfoodCatalogJob::dispatch($branchId));
})
    ->name('sync-ifood-catalog-full')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->onOneServer();

// Probe de recovery automático do Asaas — executa apenas se o circuit não estiver fechado
Schedule::call(function () {
    $cb = app(AsaasCircuitBreaker::class);

    if ($cb->getState() === 'closed') {
        return;
    }

    try {
        app(AsaasService::class)->probeHealth();
    } catch (Throwable) {
        // recordFailure() já foi chamado dentro de AsaasService::request()
    }
})
    ->name('asaas-health-probe')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
