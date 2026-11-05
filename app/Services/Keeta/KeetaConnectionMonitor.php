<?php

namespace App\Services\Keeta;

use App\Exceptions\KeetaApiException;
use App\Mail\KeetaConnectionAlert;
use App\Models\CompanyNotification;
use App\Models\KeetaIntegration;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisa o restaurante (sino e, quando ele precisa agir, e-mail aos administradores) de problemas
 * na Keeta que impedem os pedidos de chegarem ao painel, e mantém a loja fechada na Keeta
 * enquanto a empresa não pode operar (bloqueada ou sem o módulo).
 *
 * Mesmo controle de repetição dos monitores iFood/99Food: alerts_sent guarda quando cada alerta
 * saiu, o lembrete volta depois de REMINDER_HOURS e o registro some quando o problema acaba.
 */
class KeetaConnectionMonitor
{
    public const ALERT_CONNECTION_LOST = 'connection_lost';

    public const ALERT_REVOKED = 'revoked';

    public const ALERT_CATALOG_FAILED = 'catalog_failed';

    /** Marca interna (não é aviso): loja fechada na Keeta porque a empresa não pode operar. */
    public const PRESENCE_CLOSED = 'presence_closed';

    /** Sem nenhum evento nesse tempo (só informativo na tela: a Keeta só manda evento com pedido). */
    public const WEBHOOK_SILENT_HOURS = 24;

    public const REMINDER_HOURS = 24;

    public function __construct(private readonly KeetaStoreStatusSync $store) {}

    /** @return array{checked: int, alerted: int, alerts: int} */
    public function run(): array
    {
        $summary = ['checked' => 0, 'alerted' => 0, 'alerts' => 0];

        KeetaIntegration::withoutGlobalScopes()
            ->with(['company', 'branch'])
            ->whereNotNull('keeta_merchant_id')
            ->whereIn('status', [KeetaIntegration::STATUS_ACTIVE, KeetaIntegration::STATUS_PAUSED, KeetaIntegration::STATUS_ERROR, KeetaIntegration::STATUS_REVOKED])
            ->lazyById()
            ->each(function (KeetaIntegration $integration) use (&$summary) {
                $summary['checked']++;
                $this->syncPresence($integration);
                $sent = $this->process($integration);

                if ($sent > 0) {
                    $summary['alerted']++;
                    $summary['alerts'] += $sent;
                }
            });

        return $summary;
    }

    /** Avalia uma integração e envia os alertas devidos. Devolve quantos foram enviados. */
    public function process(KeetaIntegration $integration): int
    {
        if (! $integration->company) {
            return 0;
        }

        $conditions = $this->conditionsFor($integration);
        $previous = (array) ($integration->alerts_sent ?? []);
        $marker = array_intersect_key($previous, [self::PRESENCE_CLOSED => true]);
        $alerts = array_diff_key($previous, $marker);

        $due = array_filter($conditions, fn (array $alert, string $key) => $this->isDue($alerts[$key] ?? null), ARRAY_FILTER_USE_BOTH);
        $registered = array_intersect_key($alerts, $conditions);
        foreach (array_keys($due) as $key) {
            $registered[$key] = Date::now()->toIso8601String();
        }

        if ($due !== []) {
            $this->notify($integration, array_values($due));
        }

        $this->saveAlerts($integration, $registered + $marker);

        return count($due);
    }

    /** @return array<string, array{title: string, message: string, email: bool}> */
    public function conditionsFor(KeetaIntegration $integration): array
    {
        $branch = $integration->branch?->name;
        $store = $branch ? "da filial {$branch}" : 'da sua loja';
        $company = $integration->company;

        if ($company === null || ! ($company->isActive() || $company->isOverdue()) || ! $company->canUseMarketplaceIntegrations()) {
            return [];
        }

        $conditions = [];

        if ($integration->status === KeetaIntegration::STATUS_ERROR) {
            $conditions[self::ALERT_CONNECTION_LOST] = [
                'title' => "A conexão com a Keeta {$store} caiu",
                'message' => 'A Keeta não confirmou o vínculo da loja com o VEDDI. Até ativar de novo em Integrações > Keeta, os pedidos novos podem não chegar ao painel.',
                'email' => true,
            ];
        }

        if ($integration->status === KeetaIntegration::STATUS_REVOKED) {
            $conditions[self::ALERT_REVOKED] = [
                'title' => "A Keeta {$store} foi desautorizada",
                'message' => 'A autorização do app VEDDI foi revogada no portal da Keeta. Autorize de novo em Integrações > Keeta para voltar a receber pedidos.',
                'email' => true,
            ];
        }

        if ($integration->status === KeetaIntegration::STATUS_ACTIVE && $integration->hasPublishedCatalog() && $integration->catalog_sync_status === 'failed') {
            $conditions[self::ALERT_CATALOG_FAILED] = [
                'title' => "O cardápio da Keeta {$store} não foi atualizado",
                'message' => 'A Keeta recusou o último aviso de cardápio. Abra Integrações > Keeta > Cardápio e envie de novo.',
                'email' => false,
            ];
        }

        return $conditions;
    }

    /**
     * Empresa bloqueada ou sem o módulo: fecha a loja na Keeta (ninguém veria o pedido). Ao
     * voltar a operar, reabre. Suspensa pelo restaurante já foi fechada pela tela.
     */
    private function syncPresence(KeetaIntegration $integration): void
    {
        $company = $integration->company;
        if (! $company || $integration->status !== KeetaIntegration::STATUS_ACTIVE) {
            return;
        }

        $operates = ($company->isActive() || $company->isOverdue()) && $company->canUseMarketplaceIntegrations();
        $alerts = (array) ($integration->alerts_sent ?? []);
        $closedByUs = isset($alerts[self::PRESENCE_CLOSED]);

        try {
            if (! $operates && ! $closedByUs) {
                $this->store->setOpen($integration, false);
                $alerts[self::PRESENCE_CLOSED] = Date::now()->toIso8601String();
                $this->saveAlerts($integration, $alerts);
            } elseif ($operates && $closedByUs) {
                $this->store->setOpen($integration, true);
                unset($alerts[self::PRESENCE_CLOSED]);
                $this->saveAlerts($integration, $alerts);
            }
        } catch (Throwable $e) {
            Log::channel('keeta')->warning('Monitor Keeta: não foi possível ajustar a loja à situação da empresa', [
                'keeta_integration_id' => $integration->id,
                'company_id' => $company->id,
                'error' => KeetaApiException::forLog($e),
            ]);
        }
    }

    private function saveAlerts(KeetaIntegration $integration, array $alerts): void
    {
        if ($alerts === (array) ($integration->alerts_sent ?? [])) {
            return;
        }

        KeetaIntegration::withoutGlobalScopes()->whereKey($integration->id)
            ->update(['alerts_sent' => $alerts === [] ? null : json_encode($alerts)]);
        $integration->alerts_sent = $alerts === [] ? null : $alerts;
    }

    private function isDue(?string $lastSentAt): bool
    {
        return $lastSentAt === null || Date::parse($lastSentAt)->lte(Date::now()->subHours(self::REMINDER_HOURS));
    }

    /** @param  array<int, array{title: string, message: string, email: bool}>  $alerts */
    private function notify(KeetaIntegration $integration, array $alerts): void
    {
        $company = $integration->company;

        foreach ($alerts as $alert) {
            CompanyNotification::create([
                'company_id' => $company->id,
                'type' => 'keeta_alert',
                'title' => mb_substr($alert['title'], 0, 255),
                'subtitle' => mb_substr($alert['message'], 0, 255),
                'link' => route('admin.settings.keeta'),
            ]);
        }

        $emailAlerts = array_values(array_filter($alerts, fn (array $alert) => $alert['email']));
        $admins = $emailAlerts === [] ? collect() : $company->users()->wherePivot('role', 'company_admin')->get();

        foreach ($admins as $admin) {
            try {
                Mail::to($admin->email)->queue(new KeetaConnectionAlert($admin, $company, $emailAlerts));
            } catch (Throwable $e) {
                Log::channel('keeta')->warning('Monitor Keeta: falha ao enfileirar o e-mail de alerta', [
                    'company_id' => $company->id,
                    'keeta_integration_id' => $integration->id,
                    'user_id' => $admin->id,
                    'error' => KeetaApiException::forLog($e),
                ]);
            }
        }

        Log::channel('keeta')->warning('Monitor Keeta: restaurante avisado', [
            'company_id' => $company->id,
            'keeta_integration_id' => $integration->id,
            'alerts' => count($alerts),
            'emails' => $admins->count(),
        ]);
    }
}
