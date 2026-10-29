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

// Avisa restaurantes com integração iFood sem autorização ou sem consultar o iFood (pedidos não chegam)
Schedule::command('ifood:check-connections')
    ->name('ifood-check-connections')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Avisa restaurantes com WhatsApp inativo (coexistência sem uso do app), com erro ou com qualidade baixa
Schedule::command('whatsapp:check-connections')
    ->name('whatsapp-check-connections')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->onOneServer();

// Vendas no sino do admin: "Agir agora" todo dia (estoque acabando, campeão fora de venda, queda forte)
Schedule::command('sales:notify')
    ->name('sales-notify-daily')
    ->dailyAt('08:30')
    ->withoutOverlapping()
    ->onOneServer();

// Vendas no sino do admin: resumo da semana anterior, às segundas
Schedule::command('sales:notify --weekly')
    ->name('sales-notify-weekly')
    ->weeklyOn(1, '08:30')
    ->withoutOverlapping()
    ->onOneServer();

// Conexões presas aguardando templates: relê o status na Meta. Cobre webhook de aprovação perdido
// (ou chegado no meio do provisionamento) e templates que falharam ao ser criados.
Schedule::command('whatsapp:sync-templates --pending')
    ->name('whatsapp-sync-pending-templates')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Não é necessário para o saldo: BalanceService já trata como liberada a transação
// confirmed cuja release_date chegou.
// Schedule::job(new \App\Jobs\ReleaseCompanyTransactionsJob)
//     ->name('release-company-transactions')
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
// Só lojas operacionais e com o cardápio já publicado pelo restaurante (publicação é opt-in).
Schedule::call(function () {
    IfoodIntegration::operational()
        ->with('company')
        ->whereNotNull('catalog_published_at')
        ->get()
        ->filter(fn (IfoodIntegration $integration) => $integration->isOperational())
        ->each(fn (IfoodIntegration $integration) => SyncIfoodCatalogJob::dispatch($integration->branch_id));
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

// Temporary authentication/access records contain hashes only; remove after expiry.
Schedule::call(function () {
    foreach (['chat_phone_challenges', 'chat_remembered_devices', 'chat_order_access'] as $table) {
        \Illuminate\Support\Facades\DB::table($table)->where('expires_at', '<', now()->subDay())->delete();
    }
})->name('chat-security-prune')->daily()->withoutOverlapping()->onOneServer();

Schedule::job(new \App\Jobs\ArchiveFiscalDocuments)->hourly()->withoutOverlapping()->onOneServer();

// E-mail marketing: inicia campanhas agendadas, decide teste A/B e retoma lotes (inclusive após o
// limite diário de aquecimento). Os envios em si rodam na fila 'marketing'.
Schedule::command('marketing:dispatch-scheduled')
    ->name('marketing-dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping(expiresAt: 5)
    ->onOneServer();

// E-mail marketing: automações (cadastro abandonado, empresa sem pedidos).
Schedule::command('marketing:run-automations')
    ->name('marketing-run-automations')
    ->everyFifteenMinutes()
    ->withoutOverlapping(expiresAt: 30)
    ->onOneServer();

// E-mail marketing: eventos (aberturas, cliques, entregas) com mais de 12 meses.
Schedule::command('marketing:prune-events')
    ->name('marketing-prune-events')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();
